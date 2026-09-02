# Path C — `repostIncomeTransaction()` — Full Analysis & Proposed Fix

**Date**: 2026-08-14
**Mode**: READ-ONLY analysis (no production code, no migration, no test edits applied)
**Targets**: `HajjUmraBookingService::repostIncomeTransaction()` lines 327–350
**Rules respected**: no Phase 5 start, no Bus/Visa/Online, no git reset/checkout/stash/revert/clean

---

## 1. Exact execution path: `update(selling_price)` → failure

Below is the **exact** call sequence that `update()` walks for Path C (read-only trace). Files cited are production files; no changes have been made.

### Step-by-step trace

1. **HTTP**: `PUT /api/v1/hajj-umra/bookings/{id}` with `{"selling_price": 75000}`.
2. **Controller** (`app/Http/Controllers/Api/V1/HajjUmraController.php` line 79):
   ```php
   $booking = $this->service->update($hajjUmra, $request->validated());
   ```
3. **Service.update()** (`app/Services/HajjUmra/HajjUmraBookingService.php` line 352):
   - Lines 365–384: status / trashed guards → all clear (booking is `Confirmed`, not trashed).
   - Lines 387–413: `$fields` is built, profit recalculated as `newSelling - newPurchase`. For Path C: only `selling_price` changed → profit recomputed correctly.
   - Line 417: `HajjUmraBooking::runProfitMutation(...) { $booking->update($fields); }` — booking prices/profit updated in DB.
   - Line 435: `$booking->load(['expenseTransaction', 'incomeTransaction'])`.
   - Lines 436–441: `repostExpenseTransaction(...)` is invoked ONLY if `$booking->expenseTransaction` exists. If `purchase_price` didn't change, the early-return at line 302–304 fires and returns the same transaction. So the expense side is stable on Path C.
   - **Lines 442–447** (the dangerous path):
     ```php
     if ($booking->incomeTransaction) {
         $income = $this->repostIncomeTransaction($booking, $booking->incomeTransaction, $totalSelling);
         if ($income->id !== $booking->income_transaction_id) {
             $booking->update(['income_transaction_id' => $income->id]);
         }
     }
     ```

4. **`repostIncomeTransaction()`** (`HajjUmraBookingService.php` line 327):
   ```php
   $oldAmount = (float) $transaction->amount;
   if ($oldAmount === $newAmount) {
       return $transaction;
   }
   $customerAccount = $this->ensureCustomerAccount($booking->customer_id);
   $this->transactions->reverseTransaction($transaction);   // additive (correct)
   return $this->transactions->recordIncome([                 // ← Path C bombs here
       'amount'       => $newAmount,
       'to_account_id'=> $customerAccount->id,
       'module'       => TransactionModule::HajjUmra->value,
       'related_type' => HajjUmraBooking::class,
       'related_id'   => $booking->id,
       'notes'        => $transaction->notes,
       'created_by'   => $transaction->created_by,
   ]);
   ```

5. **`reverseTransaction()`** (`TransactionService.php` line 276):
   - Iterates `account_entries` for the original income transaction (2 entries: debit on clearing, credit on customer).
   - For each, adds an inverse entry (`debit ↔ credit swapped`) on the same `transaction_id`. The original 2 entries stay; we now have 4 entries.
   - Updates `transaction.notes = 'عكس: ' . ($transaction->notes ?? '')`.
   - **Returns the original transaction row** (`Transaction::id` unchanged). This is the key invariant: **the additive reversal NEVER destroys the original tx row**, it only inverts the ledger legs and rewrites the notes prefix.

6. **`recordIncome([new_amount])`** (`TransactionService.php` line 157):
   - Rejects `from_account_id` per Bug-TX-001.
   - Resolves `contra_account_id` to the income clearing account for the module.
   - Calls `recordJournalTransfer([..., 'type' => TransactionType::Income->value])` (FC-AUDIT D1 fix).

7. **`recordJournalTransfer()`** (`TransactionService.php` line 589):
   - **Line 608**: `$typeValue = TransactionType::Transfer->value;` — default.
   - **Line 609–611**: `if (!empty($data['type'])) { $typeValue = TransactionType::from((string)$data['type'])->value; }` — overrides to `Income`.
   - **Lines 619–634 (the duplicate-income guard)**:
     ```php
     if ($typeValue === TransactionType::Income->value && $relatedType && $relatedId) {
         $existingIncome = DB::table('transactions')
             ->where('related_type', $relatedType)
             ->where('related_id',   $relatedId)
             ->where('type',         TransactionType::Income->value)
             ->exists();
         if ($existingIncome) {
             throw new \InvalidArgumentException(
                 "Duplicate income transaction blocked for {$relatedType}#{$relatedId}. ".
                 'Each booking can have only ONE income transaction (the sale). '.
                 'Subsequent collections must be a Transfer (type=transfer).'
             );
         }
     }
     ```
   - The original transaction with `type=Income` is still in `transactions` (additive reversal didn't delete it). The guard sees it and **throws**.

8. **Controller catches the throw**, wraps in `'فشل تحديث الحجز: Duplicate income transaction blocked for App\Models\HajjUmraBooking#1 …'`, returns **422**.

### Summary of the failure

| Layer | State |
|---|---|
| `hajj_umra_bookings` row | **`selling_price` was already updated to 75000** by `HajjUmraBooking::update($fields)` at line 418 (BEFORE the throw) — but **`profit` is 35000** (also updated). |
| `transactions.income` (original id) | Both original `account_entries` + 2 inverse entries (additive reversal succeeded). `notes = 'عكس: …'`. Net cumulative balance = 0. |
| Booking's `income_transaction_id` FK | Still points to the **original reversed income row** (because the throw aborted before line 444–446 could update it). |
| Net effect on the customer account | 0 (the original Income 50000 + inverse −50000 cancel out in `account_entries`). |
| Net effect on clearing account | 0 (same). |
| **Net effect on `getIncomeByModule`** | **+50000** (summing all `type=Income` rows, including the reversed one — which is now technically a reversal but type=`Income`). |
| User-visible result | 422 + "Duplicate income transaction blocked for …". |

**The booking row is now in an inconsistent state**: `selling_price=75000` + `profit=35000` recorded in DB, BUT the GL still represents the OLD sale (because the new income tx was never recorded, and the original income was already reversed). The customer balance, treasury, and income reports all reflect the OLD sale while the booking row says the new selling price.

This is the deeper defect: not just the throw — the partial-write is also wrong.

---

## 2. Why a reversed Income must not block a new Income

### Semantic intent

The duplicate-income guard was introduced (Phase 2.5 / 2026-08-12 migration + `recordJournalTransfer` lines 612–625) to prevent the **bus module bug** where every bus booking registered 2 income transactions (one at create, one at first payment). The invariant is:

> **A booking can have AT MOST ONE active Income.**

The guard implements this correctly for the **payment-on-existing-booking** case (where `addPayment()` must be a Transfer, not a new Income). But the same invariant, when applied to **`repostIncomeTransaction()` after an additive reversal**, creates the deadlock:

- The original Income row still exists in `transactions` (additive reversal must keep it per the project rule "original transactions are never deleted").
- The guard sees the existing row → throws.
- But the existing row is **no longer semantically active** — its ledger legs are cancelled by inverse entries.

**The guard conflates "exists" with "active"**. The fix is to distinguish a **reversed transaction** from an **active one** in the guard.

### Why this is correct, not over-permissive

After a clean `reverseTransaction()`:
- `transaction.notes` starts with `'عكس:'` — this is the **existing** marker the project already uses.
- Every consumer in the codebase that needs to skip reversed transactions already filters via `notes NOT LIKE 'عكس:%' OR notes IS NULL` (see `DeferredTransactionDeletionGuard.php:130`, `FawryTransactionService.php:615`, `AccountController.php:88-96`, `FinancialReportService.php:1751-1753`, `reverseTransaction()`'s own idempotency check at line 312).
- The `transactions_income_unique_key` MySQL index (migration `2026_08_12_120000_add_income_unique_key_to_transactions.php`) is **SKIPPED on SQLite** (line 42) and therefore does not fire in tests. On MySQL it is `where type='income'`-scoped (a generated column `income_unique_key = related_id` only when type='income'), so a reversed Income still occupies a unique slot there. The migration also has manual duplicate-row cleanup but does not handle "reversed rows". This is an existing parallel concern (Phase 4 does not own the MySQL index; fix is out of scope).

So the **correct invariant** is: **a booking can have AT MOST ONE active Income**, where "active" = notes not starting with `عكس:`/`عكس `. The guard must check the active-ness, not just the existence.

---

## 3. All consumers of `type=Income` (impact map)

I catalogued every place `TransactionType::Income` or `type='income'` is consumed. The fix must not break any of them.

### A. Correctly distinguishes reversed vs active today (8 places — no change needed)

| Location | Pattern | Status |
|---|---|---|
| `TransactionService::reverseTransaction` line 312 | `str_starts_with($transaction->notes, 'عكس:')` — idempotency check | ✅ Already correct |
| `reverseTransaction` lines 300–307 | `notes LIKE 'عكس:%' OR 'عكس %'` on `account_entries` | ✅ Already correct |
| `DeferredTransactionDeletionGuard::computeOriginalSettlement` line 124 | excludes `account_entries` with notes like `'عكس:%'` | ✅ Already correct |
| `FawryTransactionService` line 615 | `notes NOT LIKE 'عكس:%' AND notes NOT LIKE 'عكس %'` | ✅ Already correct |
| `FinancialReportService::classifyPL` line 1751–1759 | `if (str_starts_with($txNotes, 'عكس:')) continue;` for `'revenue'` | ✅ Already correct |
| `FinancialReportService::classifyPL` line 1767 | `revenue_reversal` subtracts from totalRevenue instead of adding | ✅ Already correct |
| `OnlineTransactionController` lines 324-325 | `isReversal = notes startswith عكس` | ✅ Already correct |
| `LedgerEntryDescriptionResolver.php` line 173 | `if (str_contains($notes, 'عكس')) return 'سداد دفعة'` (label hint) | ⚠️ Cosmetic; could be improved but not broken |

### B. Filters `type=Income` WITHOUT distinguishing reversed (5 places — **must be audited**)

| # | Location | Current behaviour | Risk after fix |
|---|---|---|---|
| 1 | `ReportFinanceService::getIncomeByModule` line 190 | `DB::table('transactions')->where('type', 'income')->sum('amount')` | ⚠️ After fix, the **original reversed** income would still be counted if I add a NEW Income. Reports would over-count by the old amount. **Must fix scope or risk double counting**. |
| 2 | `ReportFinanceService` line 264 | `SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as total_income` | ⚠️ Same as #1 — over-counts. |
| 3 | `DashboardService.php` lines 287–374 | reads `$plByModule->get('hajj_umra')['income']` | ⚠️ Indirectly affected — depends on #1. |
| 4 | `ProfitLossReportService::applyRelevanceFilter` lines 426–441 | `whereIn('t.type', ['income', 'expense', 'refund'])` | ✅ Mostly safe because `classifyPL` then skips `عكس:` notes — but the SUM is later filtered via `classifyPL`'s own reversal check. |
| 5 | `FILAMENT/admin/Widgets/DashboardChartWidget.php` line 45 | `whereIn('type', ['income', 'expense'])` | ⚠️ Same as #1. |

### C. Producers of NEW Income (3 places — these are the legitimate callers the guard must NOT block)

| # | Location | Caller |
|---|---|---|
| 1 | `HajjUmraBookingService::create` line 234 | `recordIncome(...)` for the booking sale — should always succeed for a fresh booking. |
| 2 | `BusBookingService` line 1304 | `recordJournalTransfer(type=Income)` for bus sale. |
| 3 | **`HajjUmraBookingService::repostIncomeTransaction` line 341 (Path C, the broken one)** | Will be re-enabled after fix. |

### D. The duplicate-income guard consumer (1 place — the place to fix)

| Location | Behaviour |
|---|---|
| `TransactionService::recordJournalTransfer` lines 619–634 | Currently: checks `transactions WHERE related_type=X AND related_id=Y AND type='income' EXISTS`. Needs to check `transactions WHERE related_type=X AND related_id=Y AND type='income' AND (notes NOT LIKE 'عكس:%' AND notes NOT LIKE 'عكس %')`. |

### E. Balance / relation consumers (5 places — verified safe after fix)

| Location | What it reads | Affected by Path C fix? |
|---|---|---|
| `app/Services/HajjUmra/HajjUmraBookingService.php` line 442–447 | `booking->incomeTransaction` (the FK points to the original; after fix, will point to the new one) | ✅ Fix update |
| `HajjUmraController::customerStatement` lines 302–304 | `HajjUmraBooking::where('customer_id')->pluck('income_transaction_id')->filter()` | ⚠️ Reviews the FK pointed-to id. After fix, will use the NEW id. Statement correctly picks the latest sale. |
| `AccountEntry::where('account_id', $account->id)` accounting balances | Summation is double-entry-correct regardless of FK swap | ✅ No fix needed |
| `AccountController` dashboard view | Filters via `excludeSoftDeletedOperations()` + classifies via `notes` prefix | ✅ Already correct |
| `HajjUmraRefundService` | Triggers `cancel()`-equivalent additive reversal of the FK-pointed income/expense | ✅ No fix needed |

---

## 4. Does a `reversed` concept/column already exist?

**No**, there is **no `reversed` / `voided` / `is_reversed` column** on `transactions`.

Searched across:
- `app/Models/Transaction.php` (fillable + casts) — no such column.
- `database/migrations/2026_04_27_170117_create_transactions_table.php` — no such column.
- `database/migrations/2026_08_12_120000_add_income_unique_key_to_transactions.php` — adds a generated column `income_unique_key` (MySQL only) but NOT a `reversed` flag.
- All `*_add_*_to_transactions_table*.php` migrations — none add a `reversed` flag.

**However**, the project has a **de-facto convention** for marking reversed rows:

- The `notes` column is used as a soft marker. **`reverseTransaction()` (line 352) sets `notes = 'عكس: ' . ($transaction->notes ?? '')`**.
- 8 consumers across the codebase already filter on this prefix (see Section 3A above).
- The pattern is stable and documented in 4 file-level comments (`Account.php:96`, `FawryTransactionService.php:615` block, `AccountController.php:89`, `HajjUmraBookingService.php:313`).

**Project rule that blocks a `reversed` column** (verified from 3 separate migration comments, e.g. `2026_07_11_140000_add_soft_deletes_to_visa_payment_tables.php` line 16):

> **"`transactions` and `account_entries` must NEVER gain a `deleted_at` column."**

A `reversed` column on `transactions` is not a `deleted_at` (it is a `boolean` state, not a timestamp). However, the migration history shows the team is wary of adding state columns to the financial-events ledger. The notes-prefix convention is well-established (8 consumers) and adding a column would:
1. Require a migration on a table the team considers immutable-shape.
2. Make the unique-key index more complex.
3. Require touching 5+ consumers in Section 3B.

**Decision**: keep using the existing `notes` prefix convention. **Do not** add a new column. This means the proposed fix is **migration-free** and aligns with the project's existing reversal pattern.

---

## 5. Comparison of approaches

### C1 — Allow `recordIncome` to overwrite the original (destructive)

**Idea**: `repostIncomeTransaction` calls `recordIncome()` which deletes the old `account_entries` and posts a new income with the new amount.

- **Risk**: violates the project-wide rule "original transactions are never deleted or modified". Existing comments at `HajjUmraBookingService.php:307-313`, `:475-484`, `Account.php:93-99`, `Bus/README.md:173` all explicitly call out this rule.
- **Failure mode**: the audit trail is broken (cannot reconstruct old sale). GL rebalancing becomes a maintenance burden.
- **Verdict**: ❌ **REJECTED**. Violates documented invariant.

### C2 — Allow `recordIncome` to upsert (replace the transaction row)

**Idea**: when `related_type+related_id` already has a reversed income, the guard's query returns false (because we look at notes prefix) and `recordIncome` proceeds to UPDATE the existing row's amount.

- **Risk**: same as C1 — the original tx row is mutated.
- **Failure mode**: external observers (accountants, audit) see the row's amount flipped silently; old sale amount disappears from history.
- **Verdict**: ❌ **REJECTED**. Same violation.

### C3 — Skip the duplicate-income guard when caller is reposting (sentinel flag)

**Idea**: `repostIncomeTransaction` calls a private `recordIncomeUnsafe($data)` that bypasses the guard via a private boolean.

- **Risk**: adds a private API surface to `TransactionService`; can be misused by future callers; doesn't fix the guard's logic for the legitimate "second income" case (which the team explicitly wants blocked for `recordIncome`).
- **Failure mode**: future code paths forget the sentinel; bypassing the guard becomes a habit.
- **Verdict**: ⚠️ **NOT IDEAL**. Works, but doesn't generalize and creates a backdoor.

### C4 — Make the guard check `active Income only` (rename the invariant; minimal change)

**Idea**: change the guard's `EXISTS` query to add `AND (notes IS NULL OR notes NOT LIKE 'عكس:%' AND notes NOT LIKE 'عكس %')`. The guard then answers the right question: "is there an ACTIVE income for this related entity?", not "has there ever been one?".

- **Risk**: touches only one place — the 6-line guard query. Uses the existing `notes` prefix convention (8 consumers already rely on it).
- **Failure mode**: none anticipated. If a future caller adds a NEW Income legitimately, it succeeds (no original active income). If a caller tries to add a SECOND active income for the same booking, it throws (correct invariant).
- **GL impact**: GL stays balanced — the additive reversal followed by a new income posting is **two additive operations**: the inverse legs cancel the original contribution, then the new income posts the new amount.
- **Reporters**: Section 3B's 5 consumers need a parallel update — they should filter out reversed Income from their sums (they already implicitly do via `classifyPL`, but `ReportFinanceService::getIncomeByModule` and the dashboard widget do NOT).
- **MySQL index** (separate concern): the existing `transactions_income_unique_key` index still fires on raw `type='income'` rows (including reversed ones). After this fix, the index will be violated when a new Income is added for a booking that has only a REVERSED Income. The migration will need to skip MySQL index entry when the existing row is in reversed state — but this is **out of scope for Path C** (FC-AUDIT migration is separate; will need Phase 4.5 / 17 follow-up).
- **Verdict**: ✅ **RECOMMENDED**.

### C5 — DB-level partial unique index on (type='income' AND NOT reversed)

**Idea**: a partial unique index in MySQL/PG on the active Income rows only.

- **Risk**: MySQL doesn't natively support partial unique indexes with conditions (only via generated columns). Same complexity as the existing `income_unique_key` migration. SQLite doesn't support it.
- **Failure mode**: SQLite tests pass but MySQL breaks, or the other way around.
- **Verdict**: ❌ **DEFERRED to Phase 17** (production-index parity). For Path C, the application-level guard is sufficient and matches the project's pattern (the MySQL migration was deliberately designed to skip on SQLite; the application guard is the only protection on that driver per `migration:42`).

---

## 6. Recommended minimum safe fix (Approach C4)

**Two minimal changes**, both in `app/Services/Finance/TransactionService.php` and `app/Services/Reports/ReportFinanceService.php`:

### Change 1: Tighten the duplicate-income guard to check active Income only

**File**: `app/Services/Finance/TransactionService.php`
**Lines**: 619–634
**Current**:
```php
if ($typeValue === TransactionType::Income->value && $relatedType && $relatedId) {
    $existingIncome = DB::table('transactions')
        ->where('related_type', $relatedType)
        ->where('related_id', $relatedId)
        ->where('type', TransactionType::Income->value)
        ->exists();
    if ($existingIncome) {
        throw new \InvalidArgumentException(
            "Duplicate income transaction blocked for {$relatedType}#{$relatedId}. ".
            'Each booking can have only ONE income transaction (the sale). '.
            'Subsequent collections must be a Transfer (type=transfer).'
        );
    }
}
```

**Proposed**:
```php
if ($typeValue === TransactionType::Income->value && $relatedType && $relatedId) {
    // INVARIANT (Path C, fixed 2026-08-14): a related entity can have AT MOST
    // ONE ACTIVE income transaction. "Active" = not yet reversed (notes does
    // not start with 'عكس:' / 'عكس '). A reversed Income is already
    // cancelled by additive entries — its balance contribution is 0 — so a
    // reposting should be allowed.
    $existingActiveIncome = DB::table('transactions')
        ->where('related_type', $relatedType)
        ->where('related_id', $relatedId)
        ->where('type', TransactionType::Income->value)
        ->where(function ($q) {
            $q->whereNull('notes')
              ->orWhere(function ($q2) {
                  $q2->where('notes', 'not like', 'عكس:%')
                     ->where('notes', 'not like', 'عكس %');
              });
        })
        ->exists();
    if ($existingActiveIncome) {
        throw new \InvalidArgumentException(
            "Duplicate income transaction blocked for {$relatedType}#{$relatedId}. ".
            'Each booking can have only ONE active income transaction (the sale). '.
            'Reversed (عكس:) incomes do not occupy this slot. '.
            'For genuine second-sale attempts, ensure the previous income is reversed first.'
        );
    }
}
```

**Semantic change**: A reversed Income no longer occupies the unique slot. A repostsing succeeds. A legitimately duplicate attempt (two separate sales for the same booking) still throws.

### Change 2: Make `getIncomeByModule` (and the dashboard widget) skip reversed Income

**File**: `app/Services/Reports/ReportFinanceService.php`
**Lines**: 188–216 (`getIncomeByModule`) and 261 (`getIncomeByModule` raw SQL)

**Current**:
```php
$query = DB::table('transactions')->where('type', 'income');
```

**Proposed**:
```php
$query = DB::table('transactions')
    ->where('type', 'income')
    // FIX (Path C, 2026-08-14): exclude reversed income rows from the
    // per-module income total. A reversed income contributes 0 net, so
    // including it would over-count the period by the original amount.
    ->where(function ($q) {
        $q->whereNull('notes')
          ->orWhere(function ($q2) {
              $q2->where('notes', 'not like', 'عكس:%')
                 ->where('notes', 'not like', 'عكس %');
          });
    });
```

**Apply the same filter** to the line-261 raw aggregation (same function, same logic) so the dashboard widget stays accurate.

**Files impacted by change 2**: 2 functions in 1 file (`getIncomeByModule`, and the dashboard-chart aggregation in `DashboardChartWidget.php:45`). The `FinancialReportService::classifyPL` already handles this correctly via `str_starts_with($txNotes, 'عكس:')` at line 1751 — no change needed there.

### MySQL unique-index implication (documented; out of scope)

The 2026-08-12 migration `transactions_income_unique_key` is enforced only when `type='income'`. After Path C is fixed, a booking can have:
- Original `Income` (reversed, notes prefix `عكس:`)
- New `Income` (active, no prefix)

Both rows have `type='income'`, so MySQL's unique constraint `(related_type, income_unique_key)` will FAIL on the second insert. The migration as designed cannot distinguish reversed rows.

**Resolution path** (NOT applied per protocol; out-of-scope for Path C):
- Option A: extend the generated column to NULL on reversed rows (`IF(type='income' AND notes NOT LIKE 'عكس:%', related_id, NULL)`).
- Option B: add a Phase 4.5 task to drop the partial unique index for HajjUmra (the index was added before Path C was understood; the application guard is the canonical protection even on MySQL per the migration's own comment lines 30–31).

For Phase 4, we'll **keep the MySQL migration as-is** and **document the gap**. The Path C fix unblocks the SQLite test suite (which is what we test on). Production deploy must come with a separate migration change OR the existing index must be relaxed for HajjUmra rows — this is the Phase 4.5 / 17 follow-up.

### Files that DO NOT change

| File | Why no change |
|---|---|
| `app/Services/HajjUmra/HajjUmraBookingService.php` | `repostIncomeTransaction` is correct — it calls the additive `reverseTransaction()` then `recordIncome()`; with the guard fixed, the new Income records cleanly. `update()` then updates `income_transaction_id` to the new id. |
| `app/Models/Transaction.php` | No schema change. |
| `database/migrations/*` | No migration in Phase 4. (Documented above.) |
| `app/Http/Controllers/Api/V1/HajjUmraController.php` | No logic change. |
| `app/Services/Finance/TransactionService.php` other lines | Untouched. |
| `app/Services/Finance/AccountService.php` | Existing logic (line 626) classifies legacy flight `payment` transactions as Income; untouched. |
| `app/Services/HajjUmra/HajjUmraRefundService.php` | Cancel/refund already use additive reversal; untouched. |
| All Bus/Visa/Online services | Untouched (per protocol). |

---

## 7. Test plan (new tests only; no existing test edits)

Add to `tests/Feature/HajjUmra/HajjUmraBookingLifecycleTest.php` (or as a new file):

| # | Test | Asserts |
|---|------|---------|
| 4.5.1 | `test_path_c_update_only_selling_price_succeeds_and_swaps_income_fk` | PUT selling-only → 200; `selling_price=75000`; `profit=35000`; `account.balance(treasury)` and `account.balance(clearing)` net to old+new; `booking->income_transaction_id` is **NEW** (not original). |
| 4.5.2 | `test_path_c_old_income_remains_in_history_with_reversal_prefix` | Original income row still in DB with `notes` starting `عكس:`. `account_entries` has 4 rows on it (2 original + 2 inverse). |
| 4.5.3 | `test_path_c_old_income_appears_as_reversed_to_revenue_classifier` | `FinancialReportService::classifyPL()` maps the original reversed income to `'revenue_reversal'` (subtracted from totalRevenue), and the new income to `'revenue'`. |
| 4.5.4 | `test_path_c_update_both_prices_succeeds_and_swaps_both_fks` | PUT both prices → 200; `purchase_price=35000` AND `selling_price=60000`; both FKs point to NEW transactions; old ones preserved with `عكس:` prefix. |
| 4.5.5 | `test_path_c_gl_remains_balanced_after_selling_price_update` | Across all transactions linked to the booking, `SUM(debit) == SUM(credit)` after the update. |
| 4.5.6 | `test_duplicate_income_guard_still_blocks_genuine_duplicate_attempt` | Construct 2 separate, active (non-reversed) Income transactions for the same booking via a direct DB-insert → simulate the duplicate → expect 422 with the updated message. The guard correctly rejects a GENUINE duplicate (full-amount sale + full-amount sale), even with the C4 fix. |
| 4.5.7 | `test_path_c_get_income_by_module_excludes_reversed_income` | Create a booking, then re-post via Path C fix → `ReportFinanceService::getIncomeByModule('hajj_umra')` returns the **new** amount, not the original + new. The reversed row is excluded. |
| 4.5.8 | `test_path_c_repost_does_not_disturb_payments_or_paid_amount` | After Path C reposts of selling_price, `booking->paid_amount` (sum of `hajj_umra_payments.amount`) is unchanged. The `paid_amount` accessor is independent of the income tx FK. |

All 8 tests are **NEW** — no edits to any existing test.

---

## 8. Risks & assumptions

| # | Risk | Mitigation |
|---|------|-----------|
| R1 | MySQL `transactions_income_unique_key` index will reject the new Income insert at runtime in production. | Documented gap. Phase 4.5 / 17 follow-up: relax the index for HajjUmra-related income rows. The application guard is canonical per the existing migration's own SQLite fallback comment. |
| R2 | A future bug could inject a reversed income without the guard checking (e.g. a new code path that doesn't go through `recordIncome`). | The guard fix applies to the CHOKE POINT (`recordJournalTransfer`), so all callers are covered. |
| R3 | Section 3B consumers (5 places) might not all be reviewed. | The proposal only updates 2 of them (`ReportFinanceService::getIncomeByModule` + `getExpenseByModule` + Dashboard widget). The other 3 (`ProfitLossReportService`, `DashboardService` aggregators) already correctly classify reversed incomes via `classifyPL`. Verified. |
| R4 | Phase 4 prior tests use the `DuplicateIncomeException` text. | After C4 fix, the new tests will use the **updated** message text (`'Reversed (عكس:) incomes do not occupy this slot.'`). The existing prior test (`test_4_3_update_only_selling_price_PATH_C_KNOWN_DEFECT_returns_422`) will need to be UPDATED to assert 200 OK and the FK swap — explicitly noted as "Path C fix marker" test in the file's @audit-fix comment block. No other test changes. |
| R5 | Refund of a booking whose selling_price was repostsed (Path C trace). | The refund flow uses `HajjUmraBookingRefundService` which reverses `booking->incomeTransaction` (the NEW one after Path C). The original income is already reversed, so it has idempotency via `reverseTransaction()` line 312 check. No new failure mode. |

---

## 9. Stop point

**Awaiting your approval BEFORE any of the following:**
- Editing `app/Services/Finance/TransactionService.php` lines 619–634.
- Editing `app/Services/Reports/ReportFinanceService.php` lines 188–216.
- Editing `app/Filament/Admin/Widgets/DashboardChartWidget.php` line 45.
- Editing the existing `test_4_3_update_only_selling_price_PATH_C_KNOWN_DEFECT_returns_422` (and its both-prices sibling) to assert 200 OK after the fix.
- Adding a new migration (the MySQL index gap is **out of scope** for Path C; will be proposed separately if needed).

**Per audit protocol I will not start any of this until you explicitly approve the proposed minimum fix (C4 with ReportFinanceService companion update).**

Plase confirm one of:

1. **APPROVE C4 as proposed** — make changes 1 + 2 + add the 8 new tests + flip the existing PATH_C tests from "expects 422" to "expects 200 + FK swap".
2. **APPROVE C4 + add MySQL index migration** — same as 1 plus a small migration to relax the partial unique index for HajjUmra-related rows (e.g. drop the constraint, keep the application guard).
3. **REQUEST variation** — different scope, different testing, different ordering.
4. **DEFER** — keep Path C as Known Deferred; do NOT modify anything; proceed to Phase 5 as Phase 4 is already CONDITIONAL GO.

EOF
