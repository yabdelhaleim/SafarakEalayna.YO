# WALLET_TRANSFER_FINANCIAL_INVARIANTS.md

**Audit:** Wallet & Transfers
**Date:** 2026-08-20

This file documents the financial invariants tested by the audit, the actual behavior observed, and the gaps confirmed.

---

## Documented invariants (from `app/Models/Account.php` docblock lines 27-61)

### Invariant #1 — `Account.balance = SUM(credit) − SUM(debit)` on `account_entries`

**Status:** ⚠️ BROKEN for accounts with non-zero opening balance (FIN-1)

**Expected behavior:** Every account's `balance` column equals the net of its own `account_entries` rows.

**Actual behavior:**
- For accounts created with `balance = 0` (e.g. the auto-created clearing accounts): invariant holds.
- For accounts created with non-zero `balance` (e.g. fixtures, manually-seeded vaults): invariant DOES NOT hold because no opening-balance entry is created.

**Test in audit:**

```
Phase15ReconciliationTest::test_reconciliation_detects_FIN_1_opening_balance_gap
  → stored=10000.00, derived=0.00, diff=10000.00 = opening balance
```

**Effect:**
- Every reconciliation run will report a phantom delta equal to the sum of all opening balances.
- The daily `ledger:reconcile` command (scheduled in `bootstrap/app.php:44`) will produce false-positive findings daily.

**Workaround documented in the audit:**

The correct reconciliation formula is:
```
expected_balance = opening_balance + SUM(credit) − SUM(debit)
```

This is verified by `Phase15ReconciliationTest::test_reconciliation_report_matches_opening_balance_sum`.

---

### Invariant #2 — `SUM(debit) == SUM(credit)` per `transaction_id` on `account_entries`

**Status:** ✅ HELD

**Expected behavior:** Every `Transaction` row produces balanced `AccountEntry` rows on both sides.

**Actual behavior:** Verified across all 7 phases. The double-entry balance holds for every transaction recorded by the wallet module.

**Test in audit:**

```
Phase12ConcurrencyTest::test_tight_loop_ledger_integrity
  → 20 sends × 2 ledger transactions each = 40 ledger transactions.
  → Every (transaction_id, debit, credit) tuple is balanced.
```

**Findings related:**

- **FIN-3 (HIGH)**: When the expense flows through a clearing account, the `transactions.type` column is `'transfer'` (not `'expense'`). The double-entry balance still holds, but the semantic is lost.

---

## Additional invariants verified by the audit

### Invariant #3 — Total system money is conserved

**Status:** ✅ HELD

**Expected behavior:** For any sequence of wallet operations, the sum of all `accounts.balance` is unchanged.

**Actual behavior:** Verified across:
- `Phase12ConcurrencyTest::test_total_system_money_conservation` (10 sends)
- `Phase15ReconciliationTest::test_total_system_money_remains_conserved` (10 sends)

**Test methodology:**

```php
$sumAllAccounts = function (): float {
    $total = 0.0;
    foreach (DB::table('accounts')->get(['balance']) as $r) {
        $total += (float) $r->balance;
    }
    return $total;
};
$initial = $sumAllAccounts();
// ... 10 sends ...
$final = $sumAllAccounts();
$this->assertEquals($initial, $final);
```

**Result:** 17000.00 = 17000.00 ✓

---

### Invariant #4 — `Account` balance mutations are restricted to the canonical services

**Status:** ✅ HELD (with a documented gap)

**Expected behavior:** Direct `Account::update(['balance' => X])` calls outside the canonical services are blocked.

**Actual behavior:** `Account::booted()` (lines 175-195) blocks the `updating` event unless:
- `accounting.balance_guard.block_unauthorized_updates = false`, OR
- `app()->runningUnitTests()` AND `accounting.balance_guard.disable_in_testing = true`, OR
- `LedgerBalanceMutationGuard::isAllowed()` returns true.

**Test in audit:** None directly (requires service-level mutation test). The guard is wired and was not bypassed by any of the audit tests.

**Gap:** `config('accounting.balance_guard.disable_in_testing', false)` could be a config-driven bypass in production if misconfigured. Recommend adding a CI check that asserts `config('accounting.balance_guard.disable_in_testing')` is `false` in production env.

---

### Invariant #5 — `WalletTransaction::delete()` produces an `عكس:` reversal, not a hard delete

**Status:** ✅ HELD

**Expected behavior:** A delete soft-deletes the `WalletTransaction` row and appends reverse entries to the ledger.

**Actual behavior:** Verified by:
- `Phase13RollbackTest::test_delete_reverses_journal_with_reversal_prefix`
- `Phase13RollbackTest::test_reversal_creates_new_entries_not_deletes`
- `Phase13RollbackTest::test_reversal_entries_have_reversal_marker`

**Method:** `TransactionService::reverseTransaction()` (line 280-360) creates new `AccountEntry` rows with the original debit/credit swapped, and updates the `Transaction.notes` to prepend `'عكس: '`.

**Reversal pattern:**

```
Original:  Income 100 to customer,   Transfer 100 from wallet
Reversal:  Income reversal 100 from customer,  Transfer reversal 100 to wallet
```

Both pairs are tagged with `'عكس القيد #N'` (on the entry) and `'عكس: ...'` (on the transaction).

---

### Invariant #6 — `App\Services\Wallet\WalletTransactionService::createTransaction()` is wrapped in `DB::transaction`

**Status:** ✅ HELD

**Expected behavior:** Any failure mid-write rolls back ALL changes.

**Actual behavior:** `DB::transaction(function () use ($data) { ... })` at line 82. Verified by:
- `Phase08NegativeTest::test_failed_post_no_wallet_transaction`
- `Phase08NegativeTest::test_failed_post_no_ledger_transaction`
- `Phase08NegativeTest::test_failed_post_no_account_entry`
- `Phase08NegativeTest::test_failed_post_balances_unchanged`
- `Phase13RollbackTest::test_failed_post_no_wallet_transaction`

A failed POST (insufficient balance) leaves zero traces in the database.

**CAVEAT (CONC-1):** The `WalletTransaction::create()` call happens BEFORE the `lockForUpdate()` block. The outer DB transaction wrapper does NOT prevent the race window. Two concurrent sends can both create their WT rows before the lock is acquired.

---

## Invariants about clearing accounts

### Invariant #7 — `walletIncomeClearing` and `walletExpenseClearing` exist per module

**Status:** ⚠️ PARTIALLY BROKEN (system auto-creates its own)

**Expected behavior:** The system reuses pre-seeded clearing accounts if available.

**Actual behavior:** The system auto-creates `إقفال إيرادات المحافظات` and `إقفال تكاليف المحافظات` accounts on first use, IGNORING the `WalletTestCase`-seeded ones (which have the same name).

**Test in audit:**

```
Phase00SmokeTest::test_clearing_accounts_exist_for_wallet_module
  → Asserts that the WalletTestCase-seeded accounts exist by name.
  → PASSED (the lookup is by name; the seeded accounts are there).
```

But the production code (`LedgerClearingAccounts::resolveForName`) auto-creates accounts with the same name, leading to duplicate clearing accounts in the same database. The audit observed this in `Phase12ConcurrencyTest::test_total_system_money_conservation` (debug output showed TWO pairs of clearing accounts: ids 1/2 from the fixture and ids 8/9 auto-created).

**Impact:**
- Money flow is split between the two pairs.
- The auto-created pair collects the actual journal entries.
- The seeded pair is unused.

**Recommendation:**

- Either delete the seeded accounts in `WalletTestCase` (and let the system auto-create them), OR
- Make the resolver check for existing accounts before auto-creating.

---

## Invariants about the customer's ledger account

### Invariant #8 — A customer's first wallet transaction auto-creates an `Account` linked to that customer

**Status:** ✅ HELD

**Expected behavior:** `ensureCustomerAccount()` creates an `Account` of type `Customer` with `module_type='wallet_transfer'` and links it to the customer row.

**Actual behavior:** Verified by:
- `Phase07PositiveTest::test_send_updates_accounts_correctly_for_send`
- `Phase07PositiveTest::test_receive_updates_accounts_correctly`

**CAVEAT (CONC-2):** The check-and-create path is not wrapped in a row-level lock. Two concurrent first-time sends CAN create two `Account` rows (orphan + winner).

---

## Invariants about audit logs

### Invariant #9 — Every `WalletTransaction` write creates an `audit_logs` row

**Status:** ✅ HELD

**Expected behavior:** `created`, `updated`, `deleted` actions are recorded.

**Actual behavior:** Verified by:
- `Phase07PositiveTest::test_wallet_transaction_writes_audit_log`
- `Phase14FullE2ETest::test_e2e_audit_logs_complete_lifecycle`
- `Phase16FinalSecurityAuditTest::test_audit_log_records_user_id`

**Caveat:** The audit log uses `model_type`/`model_id` columns (FIN-5) instead of `auditable_type`/`auditable_id`. This is an interop gap, not a correctness gap.

---

## Summary table — invariants, status, and audit phase

| # | Invariant | Status | Pinned by |
|---|---|---|---|
| 1 | `Account.balance = SUM(credit) − SUM(debit)` | ⚠️ BROKEN (FIN-1) | Phase 6, 7, 15 |
| 2 | `SUM(debit) == SUM(credit)` per transaction | ✅ HELD | Phase 7, 12, 13 |
| 3 | Total system money conserved | ✅ HELD | Phase 12, 15 |
| 4 | `Account.balance` mutations guarded | ✅ HELD | (not directly tested) |
| 5 | Delete = "عكس:" reversal, not hard delete | ✅ HELD | Phase 13 |
| 6 | `createTransaction` wrapped in `DB::transaction` | ✅ HELD (with race window — CONC-1) | Phase 8, 13 |
| 7 | Reuse pre-seeded clearing accounts | ⚠️ PARTIALLY BROKEN | Phase 12 (debug) |
| 8 | Customer Account auto-created on first transaction | ✅ HELD (with race window — CONC-2) | Phase 7 |
| 9 | Audit log row created on every write | ✅ HELD | Phase 7, 14, 16 |

---

*End of WALLET_TRANSFER_FINANCIAL_INVARIANTS.md*
