# Online Module — Deletion & Reversal Contract

> **Contract:** Unlike Flight/HajjUmra/Visa/Bus (which have full
> "admin delete with reversal"), Online transactions represent **real
> third-party transactions** (Fawry, Visa, etc.) and **can never be
> hard-deleted**. The only "delete" semantic in Online is **cancellation**:
> status swap to `Cancelled` + additive reversal of every related ledger
> entry. The original `transactions` and `account_entries` rows are
> preserved forever for audit.

---

## 1. Why Online is **different** from every other module

| Module | Entity nature | "Admin delete" use case? | Implementation |
|---|---|---|---|
| **Flight / HajjUmra / Visa / Bus** | Internal booking (can be wrong, can be re-entered) | ✅ Yes — `deleteBookingWithReversal()` removes from active lists | Uses `ModelDeletionGuard` (gate opens for canonical service) |
| **Online** | Real third-party transaction (Fawry/Visa/etc.) — happened in the real world | ❌ No — the transaction occurred; we can only record that we cancelled its accounting impact | Observer **always throws** on `$record->delete()` (no gate) |

This is a deliberate design difference. Online's `OnlineTransaction::deleting`
observer (lines 59–61) unconditionally throws, so:
- `ModelDeletionGuard` is **NOT** applied here — it would open an unnecessary
  attack surface for a use case that doesn't exist.
- The `SoftDeletes` trait is kept on the model as a **forward-compatibility**
  hedge (if we ever introduce an archival/purge flow later, the trait is
  already in place) — but currently the observer wins, so soft-delete is
  effectively unreachable. Documented to avoid confusion.

---

## 2. Three deletion / update paths

| Method | Status effect | Ledger reversal | Soft-delete | Idempotent | Use case |
|---|---|---|---|---|---|
| `OnlineTransactionService::delete()` | `Cancelled` (visible) | ✅ `reverseTransaction()` per related tx (additive) | ❌ no | partial — throws on already-cancelled | Operational cancellation of a Fawry/Visa/etc. transaction |
| `OnlineTransactionService::update()` (price changes) | unchanged | ✅ additive repost per changed field (`repostIncomeTransaction` / `repostExpenseTransaction` / `repostCashPaymentTransaction`) | ❌ no | ✅ no-op if amounts unchanged | Admin correcting a wrong selling/purchase/amount_paid |
| `OnlineTransactionService::update()` (non-price fields) | unchanged | ❌ no | ❌ no | ✅ idempotent | Editing customer name/phone/notes/etc. |

There is **no** `deleteBookingWithReversal()` equivalent for Online — and
there should not be one. Online transactions represent externally-committed
state; they can be cancelled (status swap) but not erased.

---

## 3. `delete()` — full implementation

```php
public function delete(OnlineTransaction $tx): bool
{
    return DB::transaction(function () use ($tx) {
        if ($tx->status === OnlineTransactionStatus::Cancelled) {
            throw new \RuntimeException('المعاملة ملغاة بالفعل.');
        }

        if ($tx->status === OnlineTransactionStatus::Completed) {
            // Reverse every related transaction (additive — never destructive).
            $relatedTransactions = Transaction::where('related_type', OnlineTransaction::class)
                ->where('related_id', $tx->id)
                ->get();
            foreach ($relatedTransactions as $rt) {
                $this->transactionService->reverseTransaction($rt);
            }
        }

        $tx->status = OnlineTransactionStatus::Cancelled;
        $tx->failure_reason = ($tx->failure_reason ? $tx->failure_reason."\n" : '')
            .'[تم الإلغاء بواسطة '.Auth::user()?->name.' في '.now()->format('Y-m-d H:i').']';
        $tx->save();

        return true;
    });
}
```

Key invariants:
- **Name is misleading**: `delete()` does NOT delete. It cancels.
- **Reversals are additive** — original transaction rows stay; inverse
  `account_entries` are added on the same `transaction_id`.
- **Only operates on `Completed` transactions** — Pending/Failed have no
  ledger entries to reverse.
- **Idempotency guard**: throws on double-cancel.
- **Audit trail preserved** in `failure_reason`.

---

## 4. `update()` — full implementation (Phase 9 fix)

The `update()` method detects ACTUAL changes in `selling_price`,
`purchase_price`, and `amount_paid`. For each changed field, it calls the
corresponding `repostXxxTransaction()` helper which:
1. Reverses the old transaction via `TransactionService::reverseTransaction()`
   (additive — adds inverse `account_entries` on the same `transaction_id`).
2. Posts a fresh transaction with the corrected amount.
3. Updates `$tx->income_transaction_id` / `$tx->expense_transaction_id` to
   point at the new transactions.

This pattern **mirrors `HajjUmraBookingService::repostExpenseTransaction` /
`repostIncomeTransaction`** introduced in Phase 8 for HajjUmra/Visa.

### `repostIncomeTransaction(OnlineTransaction $tx, float $newSelling): ?Transaction`

Reverses `$tx->income_transaction_id` (if any) and posts a new income.
- If `customer_id` is set → destination is the customer account (debt).
- If `customer_id` is null → destination is `$tx->account_id` (direct
  cashbox deposit for anonymous customers).

### `repostExpenseTransaction(OnlineTransaction $tx, float $newPurchase): ?Transaction`

Reverses `$tx->expense_transaction_id` (if any) and posts a new expense.
- Source is `provider->default_purchase_account_id` if set, otherwise
  `$tx->account_id`.

### `repostCashPaymentTransaction(OnlineTransaction $tx, float $newAmountPaid): void`

The cash payment is the OPTIONAL second income transaction created at
booking time when `customer_id` is set AND `amount_paid > 0`. Its
`transaction_id` is **not** stored on `$tx`, so we locate it via:

```php
Transaction::where('related_type', OnlineTransaction::class)
    ->where('related_id', $tx->id)
    ->where('type', TransactionType::Income->value)
    ->where('to_account_id', $tx->account_id)
    ->where('id', '!=', $tx->income_transaction_id)
    ->first();
```

Handles all 4 transitions (X→Y where X and Y can be 0):
- X>0, Y>0: reverse old + create new
- X>0, Y=0: reverse old only
- X=0, Y>0: create new only
- X=0, Y=0: no-op

### Guard: status check

Repost logic is wrapped in `if ($tx->status === OnlineTransactionStatus::Completed)`
because `postFinancialEntries` only posts entries when status is Completed.
Non-Completed transactions have no ledger entries to repost.

### Important limitation

If status changes from `Completed` to `Failed` or `Pending` via `update()`,
the existing income/expense/cash transactions are NOT reversed (this would
require a delete-like operation). Callers must use `delete()` (which sets
status=Cancelled and reverses all entries) for "un-completing".

---

## 5. Filament wiring — `account_id` filter (Phase 9 fix)

The `account_id` Select in `OnlineTransactionResource:175` is filtered
to `module_type = 'online'` to prevent cross-module financial pollution:

```php
->relationship('account', 'name', fn ($q) => $q
    ->where('is_active', true)
    ->where('module_type', 'online'))
```

Before this fix, users could pick any active account (bus/flights/visas/
hajj_umra/office/tourism/fawry vaults), causing Online transactions to
debit the wrong module's cashbox.

The 4 sibling Online resources (`OnlineTreasuryResource`,
`OnlineWalletResource`, `OnlineBankAccountResource`,
`OnlineGeneralTreasuryResource`) already correctly scope by `module_type`
in their `getEloquentQuery()` — this fix brings the **transaction form** in
line with the same invariant.

---

## 6. The "always-throws" `deleting` observer

```php
static::deleting(function (OnlineTransaction $transaction) {
    throw new \RuntimeException(
        'لا يمكن حذف معاملات الخدمات الإلكترونية برمجياً للحفاظ على السجلات المالية وتوازن الخزينة. '
        .'يرجى تعديل الحالة بدلاً من الحذف.'
    );
});
```

**Why no `app()->runningUnitTests()` bypass?**
Because the contract is intentional — even PHPUnit tests must use `delete()`
(which means "cancel") instead of `delete()`-as-erase. The test suite for
Online uses the service's `delete()` to validate cancellation behavior, not
direct `Model::delete()`. No bypass needed.

**Why is `SoftDeletes` trait still present?**
Forward-compatibility. If we ever add a long-term archival flow (e.g.,
"purge Online transactions older than 7 years, after legal retention"),
we'll need real `deleted_at` columns. Keeping the trait now is a no-op cost
(the observer wins) but saves a migration + model change later.

---

## 7. Ledger guard already in place (no work needed)

`LedgerBalanceMutationGuard::run()` is **implicitly** used everywhere via
`TransactionService::reverseTransaction()` and `TransactionService::recordExpense()`
/ `recordIncome()`. Direct `Account.balance` mutation is already blocked by
the `Account::updating` observer. No additional `LedgerBalanceMutationGuard`
wrapping is needed at the `delete()` or `update()` entry points.

---

## 8. Test coverage

`phase9_online_deletion_cycle.php` (project root) verifies:

| TEST | Asserts |
|---|---|
| **A** | `delete()` (cancel + reverse) preserves behavior — no regression vs Phase 9 spec |
| **B** | `update()` with selling_price + purchase_price changes correctly reposts the income and expense transactions (additive — old reversed, new posted with new amounts) |
| **C** | `update()` with amount_paid change correctly reposts the cash payment transaction |
| **D** | The `account_id` Select in `OnlineTransactionResource:175` filters to `module_type=online` only — verified via code inspection (no other-module accounts appear) |
| **E** | Direct `$tx->delete()` outside the canonical path throws RuntimeException (the always-throws observer is intact) |
| **CLEANUP** | DB returns to original state |

Run with:
```bash
php artisan tinker --execute='require "phase9_online_deletion_cycle.php";'
```

---

## 9. Origin

This contract mirrors the established pattern from:

- `HajjUmraBookingService::cancel()` + `deleteBookingWithReversal()` + `repostXxxTransaction()` (Phase 8)
- `VisaBookingService::cancel()` + `deleteBookingWithReversal()` + `repostXxxTransaction()` (Phase 8)
- `BusBookingService::cancelBooking()` + `deleteBookingWithReversal()` (Phase 8)

**But with a critical design difference**: Online transactions are NOT
internally-generated bookings. They are external third-party transactions
that must remain visible forever. The deletion contract is therefore **status
swap + additive reversal**, NOT row removal. This is the correct design for
the module's data semantics — and is documented here to prevent well-meaning
future maintainers from "unifying" Online with the other modules'
`ModelDeletionGuard` pattern.