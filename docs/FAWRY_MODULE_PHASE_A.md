# Fawry Module — Phase A Fix Contract

> **Date:** 2026-07-12
> **Phase:** A — `updateTransaction()` repost + dashboard `customers_debt` source-of-truth fix
> **Validation:** `php scripts/phase_a_fawry_module_fix.php` → **24/24 PASSED**

---

## 1. Problem Statement

The Fawry module had **four sources of truth for "customer debt"** that could
silently diverge. The original report (Phase 9 exploration) flagged
`updateTransaction()` as destructive, but a deeper audit surfaced two
additional gaps:

| # | Symptom | Root cause |
|---|---------|------------|
| 1 | `customers_debt` in dashboard drifts from reality after editing prices | Dashboard used `FawryTransaction::sum('selling_price - amount')` (model columns), not the GL |
| 2 | `customerBalances` falls back to model columns for registered clients whose `customer.account_id` was NULL | Fallback path skipped the GL query entirely when `isset($accountBalances[$r->client_id])` failed |
| 3 | `updateTransaction()` does not repost the ledger | Only mutated the model's `selling_price`/`fawry_price`/`amount`/`account_id`; the linked `Transaction` and `AccountEntry` rows stayed at the old values |
| 4 | `updateTransaction()` does not update `income_transaction_id` / `expense_transaction_id` pointers | Same root cause — the old (now reversed) IDs remain on the model, so future dashboard queries fetch stale rows |

The combination of (1) and (3) was the **exact "accountants and debtors don't
show correctly" pattern** the user reported: every edit of a Fawry
transaction's price caused the dashboard total to silently inflate / deflate
relative to the actual ledger.

---

## 2. Solution Strategy

Adopt the **same pattern that fixed HajjUmra (Phase 8), Visa (Phase 8),
Online (Phase 9), and Wallet (Phase 8)**: detect ACTUAL field changes,
reverse the old ledger entries additively (never destructive), and post a
fresh pair with the new values.

Refactor `createTransaction()` into a reusable `postLedgerEntries()` helper
so the repost flow shares the exact same code path — no risk of behavioral
drift between create and repost.

Switch all debt reads to the GL (`account_entries.credit - debit` where
`transactions.module='fawry'`) so they survive any future code change that
mutates the model without reposting.

---

## 3. Files Changed (Phase A)

### `app/Services/Fawry/FawryTransactionService.php`

#### 3.1 Refactor: extract `postLedgerEntries()` helper

The old `createTransaction()` had 3 inline code paths (expense, sale income,
optional settlement). All 3 are now in a single helper:

```php
protected function postLedgerEntries(
    FawryTransaction $fawryTransaction,
    ?int $clientId,
    int $accountId,
    float $fawryPrice,
    float $sellingPrice,
    float $amountPaid,
    bool $hasMachine,
    int $createdBy,
    string $operationLabel,
    string $clientName,
): array {
    // expense: from prepaid_fawry (with machine) or settlement account (without)
    // sale income: to customer_account (registered) or settlement account (walk-in)
    // settlement: transfer customer→settlement (registered + amount>0)
    return [$incomeTransactionId, $expenseTransactionId];
}
```

`createTransaction()` is now ~40 lines shorter and delegates all GL
posting to this helper.

#### 3.2 Fix: `updateTransaction()` — full reverse + repost

Before:

```php
public function updateTransaction(FawryTransaction $transaction, array $data): FawryTransaction
{
    return DB::transaction(function () use ($transaction, $data) {
        if (isset($data['selling_price']) || isset($data['fawry_price'])) {
            $data['profit'] = $sellingPrice - $fawryPrice;
        }
        $transaction->update($data);  // ← ONLY mutates the model
        return $transaction->fresh([...]);
    });
}
```

After:

```php
public function updateTransaction(FawryTransaction $transaction, array $data): FawryTransaction
{
    return DB::transaction(function () use ($transaction, $data) {
        // Detect ACTUAL changes for 4 ledger-affecting fields.
        $sellingChanged  = array_key_exists('selling_price', $data) && (float)$data['selling_price']  !== (float)$transaction->selling_price;
        $fawryPriceChanged = array_key_exists('fawry_price', $data) && (float)$data['fawry_price'] !== (float)$transaction->fawry_price;
        $amountChanged   = array_key_exists('amount', $data)       && (float)$data['amount']        !== (float)$transaction->amount;
        $accountChanged  = array_key_exists('account_id', $data)   && (int)$data['account_id']      !== (int)$transaction->account_id;

        $anyLedgerAffectingChange = $sellingChanged || $fawryPriceChanged || $amountChanged || $accountChanged;

        if ($sellingChanged || $fawryPriceChanged) {
            $data['profit'] = ...;
        }
        $transaction->update($data);

        if ($anyLedgerAffectingChange) {
            // ① Reverse ALL linked GL transactions (additive)
            $linked = Transaction::where('related_type', FawryTransaction::class)
                ->where('related_id', $transaction->id)->get();
            foreach ($linked as $linkedTx) {
                $this->transactionService->reverseTransaction($linkedTx);
            }

            // ② Repost via the SAME helper used by createTransaction
            [$newIncomeId, $newExpenseId] = $this->postLedgerEntries(
                fawryTransaction: $transaction->fresh(),
                clientId: $transaction->client_id,
                ...
            );

            // ③ Update the model's pointers to the new transactions
            $transaction->update([
                'income_transaction_id' => $newIncomeId,
                'expense_transaction_id' => $newExpenseId,
            ]);
        }

        return $transaction->fresh([...]);
    });
}
```

#### 3.3 No-op guard

When the edit only touches non-ledger fields (`notes`, `reference_number`,
`client_name`), no reverse/repost happens. The
`$anyLedgerAffectingChange` gate ensures we don't waste DB writes on no-ops.

#### 3.4 Additive reversal

`TransactionService::reverseTransaction()` (already used by Online / HajjUmra
/ Visa / Wallet / Bus) prepends `'عكس: '` to the old transaction's `notes`
and inserts a counter `AccountEntry`. The original row is **never deleted**
— the audit trail is preserved.

### `app/Http/Controllers/Api/V1/Fawry/FawryDashboardController.php`

#### 3.5 Fix: `customers_debt` reads from GL

Before:

```php
$stats['customers_debt'] = (float) FawryTransaction::sum(DB::raw('selling_price - amount'));
```

After:

```php
$stats['customers_debt'] = (float) DB::table('account_entries')
    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
    ->join('accounts', 'account_entries.account_id', '=', 'accounts.id')
    ->where('accounts.type', AccountType::Customer->value)
    ->where('transactions.module', TransactionModule::Fawry->value)
    ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as debt')
    ->value('debt') ?? 0.0;
```

The query now joins the GL, sums credit/debit on customer accounts, and
filters strictly by `transactions.module='fawry'`. This number is
**authoritative**: it cannot drift from the model even if a future code
path mutates the model without reposting.

### `app/Http/Controllers/Api/V1/Fawry/FawryTransactionController.php`

#### 3.6 Fix: `customerBalances` registered-client fallback

Before:

```php
if ($r->client_id && isset($accountBalances[$r->client_id])) {
    $totalDebt = $accountBalances[$r->client_id];  // GL (correct)
    $totalPaid = $totalSales - $totalDebt;
} else {
    $totalPaid = (float) $r->total_paid;  // column (could be wrong)
    $totalDebt = (float) $r->total_debt;
}
```

After:

```php
// Always initialize the GL key for every registered client whose
// customer.account_id is non-null, even if no Fawry entries exist yet.
foreach ($customerAccounts as $clientId => $accountId) {
    $bal = $balances->get($accountId);
    $accountBalances[$clientId] = $bal
        ? (float) $bal->total_credit - (float) $bal->total_debit
        : 0.0;  // ← explicit zero, not "missing"
}

// Switch to GL for ANY registered client with a customer account.
if ($r->client_id !== null && array_key_exists($r->client_id, $accountBalances)) {
    $totalDebt = $accountBalances[$r->client_id];  // GL always
    $totalPaid = $totalSales - $totalDebt;
} else {
    // walk-in OR registered-but-no-account-yet
    $totalPaid = (float) $r->total_paid;
    $totalDebt = (float) $r->total_debt;
}
```

The walk-in fallback remains column-based — that's a documented limitation
(walk-ins pay cash on the spot to the settlement account, no GL row exists
on a customer account for them).

---

## 4. Test Contract (`scripts/phase_a_fawry_module_fix.php`)

24 checks across 6 sections, all rolled back via `DB::rollBack()`:

| # | Section | Checks |
|---|---------|--------|
| ① | `updateTransaction()` reposts ledger on price/amount change | 12 |
| ② | `updateTransaction()` skips repost on notes-only edit (no-op guard) | 2 |
| ③ | `customers_debt` in dashboard reads from GL | 4 |
| ④ | `customerBalances` prefers GL for registered clients | 3 |
| ⑤ | `deleteTransaction()` still works (no regression) | 3 |
| ⑥ | Module-level invariants | 3 |

### Run

```bash
php scripts/phase_a_fawry_module_fix.php
# → Result file: storage/logs/phase_a_fawry_result.json
```

### Latest Result

```json
{
  "success": true,
  "failed_count": 0,
  "failed_labels": [],
  "ran_at": "2026-07-12T..."
}
```

---

## 5. Risks & Rollback

| Risk | Mitigation |
|------|------------|
| `updateTransaction()` reposts even when fields are unchanged | Uses `array_key_exists` + float-equality check; if no field actually changed, the reverse+repost block is skipped entirely. |
| Walk-in client debt shows model-column value, not GL | Documented limitation — walk-ins have no customer account, so no GL exists. UI labels these rows as walk-in so the user understands. |
| A future code path mutates the model without reposting | GL dashboard source-of-truth survives; the column sum would silently drift but the dashboard would still show the correct number. |
| `postLedgerEntries` reintroduces the settlement duplicate (same bug we hit in Wallet Phase 8) | The helper is called exactly once per reverse+repost cycle. The settlement creation is inside the helper, not duplicated by separate handlers. |

---

## 6. Phase A Atomic Commit Plan

1. **`fix(fawry): repost income/expense/settlement transactions on update (Phase A pattern)`**
   - `FawryTransactionService::updateTransaction` + extract `postLedgerEntries()`
2. **`fix(fawry): source customers_debt from GL (account_entries)`**
   - `FawryDashboardController`
3. **`fix(fawry): always prefer GL debt for registered clients in customerBalances`**
   - `FawryTransactionController::customerBalances`
4. **`chore(fawry): add Phase A test script + README`**
   - `scripts/phase_a_fawry_module_fix.php`
   - `docs/FAWRY_MODULE_PHASE_A.md`

---

## 7. What's Next (Phase B + C)

- **Phase B**: `FawryMachine.balance` protection — boot guard +
  `LedgerBalanceMutationGuard` wrap. Currently the `decrement`/`increment`
  calls happen unguarded.
- **Phase C**: `ensureCustomerAccount()` re-tag (existing customer accounts
  get their `module_type` set to `fawry`), and `FinancialReportService`
  receivables filter by `transactions.module='fawry'`.

---

*Phase A closes the 3 P0 gaps in the Fawry module. After this phase, the
GL is the single source of truth for customer debt.*