# WALLET_TRANSFER_TEST_INDEX.md

**Audit:** Wallet & Transfers
**Date:** 2026-08-20
**Status:** All 11 phase test files GREEN under per-file execution.

---

## How to run

```bash
# Run all wallet phase tests in isolation (uses SQLite in-memory, no MySQL needed)
cd /c/travile/SafarakEalayna
for f in tests/Feature/Wallet/Phases/Phase*.php; do
  echo "=== $f ==="
  vendor/bin/phpunit "$f" --no-coverage 2>&1 | tail -2
done

# Or run any single phase
vendor/bin/phpunit tests/Feature/Wallet/Phases/Phase07PositiveTest.php --no-coverage
```

**Important:** The Hajj/Umra + Visa conflict markers in `tests/Feature/HajjUmra/*` and `tests/Feature/WalletTransactionCrudTest.php` (the legacy CRUD test) prevent `phpunit.xml`'s default test suite from running. The wallet phase tests are designed to be invoked **per-file** so they don't load the broken test files.

---

## File index

| File | Tests | What it covers |
|---|---|---|
| `Phase00SmokeTest.php` | 5 | Test infrastructure, decimal oracle, balance invariant discovery (FIN-1) |
| `Phase07PositiveTest.php` | 13 | Happy path: send/receive, walk-in vs registered, update, show, daily-summary, audit log, ledger balance (FIN-1, FIN-3, FIN-4, FIN-5, R1-A documented) |
| `Phase08NegativeTest.php` | 32 | Validation rejections, insufficient balance, currency mismatch, inactive accounts, double-wallet (FIN-6, FIN-7 documented), no idempotency |
| `Phase09SecurityTest.php` | 14 | Auth, RBAC, IDOR, parameter tampering, mass-assignment (SEC-1, SEC-2 documented) |
| `Phase10PrecisionTest.php` | 15 | Decimal precision, fee arithmetic, multi-currency isolation, round-trip |
| `Phase11IdempotencyTest.php` | 7 | Idempotency-Key, X-Request-Id, replay (IDM-1 CRITICAL confirmed) |
| `Phase12ConcurrencyTest.php` | 7 | Tight-loop balance, system-money conservation, customer balance accumulation |
| `Phase13RollbackTest.php` | 13 | Failed POST leaves no trace, delete = reversal with "عكس:" prefix |
| `Phase14FullE2ETest.php` | 9 | Customer journey, multi-customer, walk-in, multi-currency, statement, audit trail |
| `Phase15ReconciliationTest.php` | 7 | Reconciliation gap = opening balance (FIN-1), money conservation |
| `Phase16FinalSecurityAuditTest.php` | 13 | Mass assignment, RBAC, SQL injection, XSS, audit log, soft-deleted access |

**Total:** 135 tests, 880 assertions

---

## Test infrastructure

- `tests/Feature/Wallet/WalletTestCase.php` — base class (RefreshDatabase, fixtures, helpers)
- `tests/Feature/Wallet/Support/Decimal.php` — bcmath exact-decimal oracle (rounding-aware)
- `tests/Feature/Wallet/Support/AccountState.php` — independent DB reader
- `tests/Feature/Wallet/Support/Assertions.php` — invariant assertions

These files were created during PHASE 6 and are not part of any existing test class.

---

## Known pre-existing test failures

`tests/Feature/Wallet/WalletTransactionCrudTest.php` (the legacy CRUD test) has 7+1 failures that are NOT introduced by this audit:

| # | Test | Status | Reason |
|---|---|---|---|
| 1 | `test_can_create_send_transaction` | FAIL | 422 — FIN-2 (duplicate income when amount_paid is default) |
| 2 | `test_send_updates_accounts_correctly` | FAIL | Balance unchanged — caused by #1's failed POST |
| 3 | `test_can_list_transactions` | FAIL | 405 — R1-A (missing index route) |
| 4 | `test_can_filter_by_type` | FAIL | 405 — R1-A |
| 5 | `test_can_show_transaction` | FAIL | 405 — `$id` is null (chained from #1) |
| 6 | `test_can_update_transaction_notes` | FAIL | 405 — chained from #1 |
| 7 | `test_daily_summary` | FAIL | Only 1 transaction counted (consequence of #1) |
| 8 | `test_can_delete_transaction_and_reverses_accounting` | ERROR | `WalletTransaction::first()` returns null |

This audit does NOT modify `WalletTransactionCrudTest.php`. The failures are documented as findings (FIN-2, R1-A) and the new Phase tests use `amount_paid=0` to validate the post-fix behavior.

---

## Audit coverage summary

| Audit area | Phase | Status |
|---|---|---|
| Test architecture | 6 | ✅ |
| Positive paths | 7 | ✅ |
| Negative paths | 8 | ✅ |
| Security / RBAC | 9, 16 | ✅ |
| Precision | 10 | ✅ |
| Idempotency | 11 | ✅ |
| Concurrency | 12 | ✅ |
| Rollback | 13 | ✅ |
| Full E2E | 14 | ✅ |
| Reconciliation | 15 | ✅ |
| Final security | 16 | ✅ |

---

*End of WALLET_TRANSFER_TEST_INDEX.md*
