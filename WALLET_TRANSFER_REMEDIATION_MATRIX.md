# Wallet & Transfers — Remediation Matrix (Finding → File → Test → Status)

**Date:** 2026-08-20
**Branch:** `phase-10-tourism-production-audit-hajj-umra`

This is the exact mapping of every finding in `WALLET_TRANSFER_FINDINGS.md` to the production code touched, the test that verifies the fix, and the final status. 22/22 findings are closed or explicitly accepted.

Legend:
- **FIXED** — code changed; test now asserts post-fix behavior
- **ACCEPTED** — risk explicitly accepted (project-wide, not in scope, or out-of-scope per user)
- **AUTO** — auto-resolved by another fix (no standalone change)

---

## CRITICAL (2)

| ID | Title | Severity | Status | Files Touched | Test |
|----|-------|----------|--------|---------------|------|
| IDM-1 | No idempotency on double-submit | CRITICAL | DONE (previous batch) | `WalletTransactionService.php`, `WalletTransactionController.php` | `PhaseIdempotencyRemediationTest` (10 tests) |
| SEC-1 | Default-permissions grant when empty | CRITICAL | FIXED | `app/Support/UserPermissions.php` | `UserManagementTest`, `EmployeePermissionsWiringTest`, `Phase09SecurityTest`, `VisaPermissionTest`, `HajjUmraIDORTest` |

**SEC-1 change:** `effectiveFor()` for employee with empty permissions returns `[]` (deny-all), not `defaultEmployeeModules()`. Admin/owner unchanged.

```diff
- if (empty($stored)) {
-     return self::defaultEmployeeModules();
- }
+ $stored = array_values(array_intersect($stored, self::keys()));
+ if (in_array($user->role, ['admin', 'owner'], true)) {
+     return $stored !== [] ? $stored : self::all();
+ }
+ return $stored;  // POST-FIX: deny-by-default
```

---

## HIGH (8)

| ID | Title | Severity | Status | Files Touched | Test |
|----|-------|----------|--------|---------------|------|
| FIN-1 | Balance invariant unsatisfiable for accounts with non-zero opening | HIGH | FIXED | `database/migrations/2026_08_21_add_is_opening_to_account_entries.php` (NEW), `app/Models/Account.php`, `app/Models/AccountEntry.php` | `Phase00SmokeTest::test_balance_invariant_for_initial_fixtures` (flipped), `Phase07PositiveTest::test_balance_invariant_does_NOT_hold_for_non_zero_opening_balance_FIN_1` (flipped), `Phase15ReconciliationTest::test_reconciliation_detects_FIN_1_opening_balance_gap` (flipped), `Phase12ConcurrencyTest::test_balance_read_consistency_across_paths` (flipped), `Phase13RollbackTest::test_failed_post_no_account_entry` (flipped — count filtered to transaction-attached only), `Phase15ReconciliationTest::test_reconciliation_after_posting_includes_opening_balance` (flipped), `Phase15ReconciliationTest::test_wallet_module_only_reconciliation` (flipped), `Phase15ReconciliationTest::test_reconciliation_report_matches_opening_balance_sum` (flipped — excludes "System Opening Balances" contra) |
| FIN-2 | Settlement recorded as Income, not Transfer | HIGH | FIXED | `app/Services/Wallet/WalletTransactionService.php::postSettlementSend` | `Phase07PositiveTest::test_send_with_amount_paid_positive_succeeds_with_transfer_settlement` (NEW) |
| FIN-3 | recordExpense types as Transfer | HIGH | FIXED | `app/Services/Finance/TransactionService.php` | `Phase07PositiveTest::test_send_creates_two_journal_transactions_with_expected_types` (flipped) |
| FIN-6 | Same wallet/cashbox allowed | HIGH | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (withValidator) | `Phase08NegativeTest::test_wallet_account_equals_cash_account_FIN_6_*` (flipped → 422) |
| FIN-7 | Inactive accounts accepted | HIGH | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (withValidator) | `Phase08NegativeTest::test_inactive_wallet_account_is_still_accepted_FIN_7` (flipped → 422) |
| R1-A | Missing GET /transactions index route | HIGH | FIXED | `routes/api.php` | `Phase07PositiveTest::test_index_endpoint_returns_405_*` (flipped → 200) |
| CONC-1 | Lock acquired AFTER WT insert | HIGH | FIXED | `app/Services/Wallet/WalletTransactionService.php::createTransaction` | `Phase12ConcurrencyTest::test_tight_loop_50_sends_balance_invariant`, `test_at_balance_boundary_no_overdraw`, `test_balance_read_consistency_across_paths` |
| VAL-1 | Currency mismatch silently accepted | HIGH | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (withValidator) | `Phase08NegativeTest::test_currency_mismatch_wallet_egp_cash_usd_is_accepted` (flipped → 422) |

**FIN-1 implementation:** `Account::created` boot hook auto-seeds paired opening entry when balance > 0:

```php
static::created(function (Account $account): void {
    $balance = (float) $account->balance;
    if ($balance <= 0.0) return;
    $contra = Account::query()->firstOrCreate(
        ['name' => 'System Opening Balances', 'type' => AccountType::Owner->value],
        ['currency' => $account->currency ?? 'EGP', 'balance' => 0,
         'is_active' => true, 'owner_type' => self::OWNER_TYPE_OWNER,
         'module_type' => 'office', 'is_module_vault' => false]
    );
    AccountEntry::insert([
        // CREDIT on new account, DEBIT on contra, paired opening entries
        ['account_id' => $account->id, 'transaction_id' => null,
         'debit' => 0, 'credit' => $balance, 'balance_after' => $balance,
         'is_opening' => true, ...],
        ['account_id' => $contra->id, 'transaction_id' => null,
         'debit' => $balance, 'credit' => 0, 'balance_after' => -$balance,
         'is_opening' => true, ...],
    ]);
});
```

**FIN-2 implementation:** Settlement now uses `recordJournalTransfer` with explicit `type=Transfer`:

```diff
- $cashboxIncome = $this->transactionService->recordIncome([
-     'amount' => $amountPaid,
-     ...
- ]);
+ $cashboxIncome = $this->transactionService->recordJournalTransfer([
+     'amount' => $amountPaid,
+     'type' => TransactionType::Transfer,
+     ...
+ ]);
```

**CONC-1 implementation:** Lock acquired BEFORE insert:

```php
$walletAccountId = (int) $data['wallet_account_id'];
$cashAccountId = (int) $data['cash_account_id'];
if ($walletAccountId > 0) {
    Account::query()->where('id', $walletAccountId)->lockForUpdate()->first();
}
if ($cashAccountId > 0 && $cashAccountId !== $walletAccountId) {
    Account::query()->where('id', $cashAccountId)->lockForUpdate()->first();
}
// THEN: WalletTransaction::create([...])
```

**VAL-1 / FIN-6 / FIN-7 implementation:** `withValidator` closure on FormRequest:

```php
$validator->after(function ($v) use ($data) {
    $wallet = Account::find($data['wallet_account_id']);
    $cash = Account::find($data['cash_account_id']);
    if ($wallet->id === $cash->id) {
        $v->errors()->add('cash_account_id', 'لا يمكن أن يكون حساب المحفظة هو نفسه حساب الكاش.');
    }
    if (! $wallet->is_active || ! $cash->is_active) {
        $v->errors()->add('wallet_account_id', 'حساب غير نشط.');
    }
    if ($wallet->currency !== $cash->currency) {
        $v->errors()->add('cash_account_id', 'العملات غير متطابقة.');
    }
});
```

---

## MED (9)

| ID | Title | Severity | Status | Files Touched | Test |
|----|-------|----------|--------|---------------|------|
| REC-1 | Reconciliation gap = opening balance | MED | AUTO (resolved by FIN-1) | (none — auto) | (Phase15 tests flipped as part of FIN-1) |
| CONC-2 | First-time customer send race | MED | FIXED | `app/Services/Wallet/WalletTransactionService.php::ensureCustomerAccount` | `Phase12ConcurrencyTest::test_concurrent_first_time_sends_create_one_customer_account_CONC_2_FIXED` (NEW) |
| SEC-2 | IDOR on customer balances/statement | MED | FIXED | `app/Models/Wallet/WalletTransaction.php` (scopeVisibleTo), `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` | `Phase09SecurityTest::test_show_other_users_transaction_does_not_filter_by_creator`, `test_statement_does_not_filter_by_creator` (flipped → 404), new admin-bypass test |
| SEC-3 | Cross-branch tenant isolation missing | MED | ACCEPTED | (none — no `branch_id` schema exists) | (out of scope — schema change would require user direction) |
| SEC-4 | Soft-deleted rows exposed via route-model bypass | MED | FIXED | `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php::show` | `Phase16FinalSecurityAuditTest::test_show_on_soft_deleted_*` (flipped → 404) |
| UX-1 | Business rule violations → 422 | MED | FIXED | `app/Exceptions/BusinessLogicException.php` (NEW), `app/Services/Finance/TransactionService.php`, `bootstrap/app.php`, `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php` | `Phase08NegativeTest::test_send_exceeding_wallet_balance_returns_409_not_500` (flipped), `Phase12ConcurrencyTest::test_at_balance_boundary_no_overdraw` (flipped), `Phase11IdempotencyTest::test_creating_transaction_with_amount_too_high_after_first_succeeds` (flipped), `Phase13RollbackTest::*` (5 tests flipped), `Phase08NegativeTest::test_huge_amount_within_range_succeeds` (flipped), `Phase08NegativeTest::test_failed_send_does_not_change_balances` (flipped) |
| VAL-4 | HTML/script in notes accepted | MED | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` (prepareForValidation) | `Phase16FinalSecurityAuditTest::test_html_in_notes_is_not_executed` (flipped → 422) |
| FIN-4 | Daily summary uses `amount` not `total_amount` | MED | FIXED | `app/Services/Wallet/WalletTransactionService.php::getDailySummary` | `Phase07PositiveTest::test_daily_summary_uses_amount_not_total_amount_FIN_4` (flipped) |
| FIN-5 | Audit-log column-name divergence | MED | FIXED (non-breaking enhancement) | `app/Services/Wallet/WalletTransactionService.php::writeAuditLog` | (no flip — backward-compatible enhancement; columns added by migration `2026_08_19_120000`) |
| R1-B | `routes/finance.php` dead code | MED | ACCEPTED | (none — user said do not delete) | (n/a) |

**CONC-2 implementation:** Customer row locked at start of ensureCustomerAccount:

```php
return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customerId) {
    $customer = Customer::query()
        ->where('id', $customerId)
        ->lockForUpdate()
        ->firstOrFail();
    // re-check $customer->account_id under the lock
    // ...
}));
```

**SEC-2 implementation:** `scopeVisibleTo` on the model + inline filters on controllers:

```php
// Model scope
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if (in_array($user->role, ['admin', 'owner'], true)) return $query;
    return $query->where('created_by', $user->id);
}

// Controller show
if ($user && ! in_array($user->role, ['admin', 'owner'], true)
    && (int) $transaction->created_by !== (int) $user->id) {
    return ApiResponse::error('Wallet transaction not found.', null, 404);
}
```

**UX-1 implementation:** New exception class + bootstrap mapping:

```php
// app/Exceptions/BusinessLogicException.php
class BusinessLogicException extends RuntimeException
{
    protected array $context;
    public function __construct(string $message, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }
    public function context(): array { return $this->context; }
}

// bootstrap/app.php → withExceptions → render closure
} elseif ($e instanceof \App\Exceptions\BusinessLogicException) {
    $statusCode = 409;
}

// Response shape: message preserved (Arabic), errors carries structured context
} elseif ($e instanceof \App\Exceptions\BusinessLogicException) {
    $response['message'] = $e->getMessage();
    $response['errors'] = $e->context();
}
```

**FIN-4 implementation:** New keys alongside backward-compat keys:

```php
->selectRaw('
    COUNT(*)                                       as total_transactions,
    ...
    SUM(CASE WHEN type = "send"    THEN amount ELSE 0 END)        as total_sent,
    SUM(CASE WHEN type = "receive" THEN amount ELSE 0 END)        as total_received,
    SUM(CASE WHEN type = "send"    THEN total_amount ELSE 0 END) as total_sent_with_fees,
    SUM(CASE WHEN type = "receive" THEN total_amount ELSE 0 END) as total_received_with_fees,
    SUM(service_fee) as total_fees
')
```

**FIN-5 implementation:** Write both pairs of polymorphic columns:

```php
AuditLog::create([
    'user_id' => Auth::id() ?? $record->created_by ?? 1,
    'action' => $action,
    'model_type' => WalletTransaction::class,    // legacy
    'model_id' => $record->id,                   // legacy
    'related_type' => WalletTransaction::class,  // modern convention
    'related_id' => $record->id,                 // modern convention
    // ...
]);
```

---

## LOW (4)

| ID | Title | Severity | Status | Files Touched | Test |
|----|-------|----------|--------|---------------|------|
| UX-2 | Arabic-only error messages | LOW | ACCEPTED | (none — project-wide i18n) | (out of scope — affects all modules, not just wallet) |
| SEC-4 detail | Soft-delete behavior consistency | LOW | DONE (covered above as MED) | (none additional) | (same as SEC-4 above) |
| VAL-2 | Dust-attack: min:0.01 too low | LOW | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` | `Phase10PrecisionTest::test_smallest_amount_below_one_is_rejected_VAL_2_FIXED` (NEW), `test_amount_one_is_accepted_VAL_2_FIXED` (NEW), `test_nine_decimal_precision_*` (adjusted to use 100.x) |
| VAL-3 | Overflow: no max on amount | LOW | FIXED | `app/Http/Requests/Wallet/StoreWalletTransactionRequest.php` | (covered by existing range tests + Phase10 tests; no flip needed) |

---

## Test files modified

11 test files updated (all test fixtures / flipped assertions; no test was weakened or removed):

**Production-side tests (wallet):**
1. `tests/Feature/Wallet/Phases/Phase00SmokeTest.php` — FIN-1 flip
2. `tests/Feature/Wallet/Phases/Phase07PositiveTest.php` — FIN-2 new + FIN-3, FIN-4, R1-A flips
3. `tests/Feature/Wallet/Phases/Phase08NegativeTest.php` — FIN-6, FIN-7, UX-1, VAL-1 flips
4. `tests/Feature/Wallet/Phases/Phase09SecurityTest.php` — SEC-1, SEC-2 flips
5. `tests/Feature/Wallet/Phases/Phase10PrecisionTest.php` — VAL-2 flips
6. `tests/Feature/Wallet/Phases/Phase11IdempotencyTest.php` — UX-1 flip
7. `tests/Feature/Wallet/Phases/Phase12ConcurrencyTest.php` — CONC-1, CONC-2 NEW, FIN-1 flip
8. `tests/Feature/Wallet/Phases/Phase13RollbackTest.php` — FIN-1, UX-1 flips
9. `tests/Feature/Wallet/Phases/Phase15ReconciliationTest.php` — FIN-1, REC-1 flips
10. `tests/Feature/Wallet/Phases/Phase16FinalSecurityAuditTest.php` — SEC-4, VAL-4 flips
11. `tests/Feature/Wallet/Phases/PhaseIdempotencyRemediationTest.php` — SEC-1 fixture updates

**Test-fixture updates (other modules):**
- `tests/Feature/UserManagementTest.php`
- `tests/Feature/TourismEmployeeE2E/EmployeePermissionsWiringTest.php`
- `tests/Feature/Visa/VisaTestCase.php`
- `tests/Feature/HajjUmra/HajjUmraIDORTest.php`

---

## Final test results

```
Wallet phase suite (Phase00-16):  156 passed /  0 failed
IDM-1 + Phase16:                  27 passed /  0 failed
─────────────────────────────────────────────────────────────
TOTAL WALLET:                    183 passed /  0 failed

Pre-existing baseline failures:   16 failures in 3 unrelated files
  - WalletTransactionCrudTest
  - WalletTransactionCrossModuleIsolationTest
  - UseOfficeDepartmentWalletsTest
```

The 16 pre-existing failures were verified to fail on clean HEAD before any of my changes via `git stash` + test run. They are NOT regressions from this remediation batch.

---

## Migration safety

The single new migration `2026_08_21_add_is_opening_to_account_entries.php` is:
- **Additive** — adds `is_opening BOOLEAN DEFAULT FALSE` + index
- **Reversible** — `down()` drops the index and column cleanly
- **No FK constraint** — purely additive column with default
- **No data backfill needed** — existing entries default to `is_opening = false` (the correct value, since they were transaction-attached)

No production DB changes were made. No `php artisan migrate` was run against production. All testing was done against the local SQLite in-memory test DB.