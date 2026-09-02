# WALLET_TRANSFER_RUN_REPORT.md

**Audit:** Wallet & Transfers — Full E2E / Security / Financial Integrity
**Date:** 2026-08-20
**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Mode:** REPORT-ONLY (no application code modified)
**Auditor:** MiniMax-M3 / ZCode session

---

## 1. Executive summary

The Wallet & Transfers module was audited across **11 test phases** (PHASE 6–16) producing **135 tests with 880 assertions**. All phase tests are **GREEN** under per-file PHPUnit invocation.

The audit uncovered **23 distinct findings** including **2 CRITICAL**, **7 HIGH**, **9 MEDIUM**, and **5 LOW** severity issues. The most severe are:

1. **IDM-1 (CRITICAL)** — No idempotency mechanism on POST `/api/v1/wallet/transactions`. A double-click, network retry, or payload replay will create duplicate financial transactions.
2. **SEC-1 (CRITICAL)** — Any user with `role='employee'` automatically has `manage_treasury` permission (which gates `wallet.create`). There is no way to explicitly deny this.
3. **CONC-1 (HIGH)** — `WalletTransaction::create()` runs BEFORE the row-level lock. A burst of concurrent sends can race past the lock, creating phantom WT rows before balance is checked.
4. **FIN-2 (HIGH)** — `accountForSend` posts duplicate active income when `amount_paid > 0` + registered customer. The existing `WalletTransactionCrudTest::test_can_create_send_transaction` is failing for exactly this reason.

The module's **double-entry invariant** (debit == credit per transaction) is **HELD** across all 135 tests. The **ledger-level money conservation** is **HELD** across all 135 tests. The **balance column invariant** (`Account.balance = SUM(credit) - SUM(debit)`) is **BROKEN** for accounts with non-zero opening balance (FIN-1).

---

## 2. Phases & result count

| Phase | Title | Tests | Assertions | Status |
|---|---|---|---|---|
| 6 | Test architecture | 5 | 20 | ✅ |
| 7 | Positive tests | 13 | 98 | ✅ |
| 8 | Negative tests | 32 | 43 | ✅ |
| 9 | Security / Authorization | 14 | 28 | ✅ |
| 10 | Precision / Calculation | 15 | 44 | ✅ |
| 11 | Idempotency | 7 | 136 | ✅ |
| 12 | Concurrency | 7 | 247 | ✅ |
| 13 | Failure / Rollback | 13 | 45 | ✅ |
| 14 | Full E2E | 9 | 58 | ✅ |
| 15 | Reconciliation | 7 | 33 | ✅ |
| 16 | Final security audit | 13 | 128 | ✅ |
| **TOTAL** | | **135** | **880** | ✅ |

---

## 3. Per-phase coverage notes

### PHASE 6 (Test architecture)
- Created `tests/Feature/Wallet/WalletTestCase.php` (RefreshDatabase + fixtures).
- Created `tests/Feature/Wallet/Support/Decimal.php` (bcmath oracle with proper rounding).
- Created `tests/Feature/Wallet/Support/AccountState.php` (independent DB reader).
- Created `tests/Feature/Wallet/Support/Assertions.php` (invariant assertions).
- Discovered the **FIN-1** invariant gap.

### PHASE 7 (Positive tests)
- Confirmed **FIN-1** (balance invariant broken for non-zero opening balances).
- Discovered **FIN-2** (duplicate income in `accountForSend`).
- Discovered **FIN-3** (`recordExpense` writes `type='transfer'`).
- Discovered **FIN-4** (daily summary sums `amount` not `total_amount`).
- Discovered **FIN-5** (audit log uses `model_type`/`model_id`).
- Confirmed **R1-A** (GET `/wallet/transactions` index route missing).

### PHASE 8 (Negative tests)
- Confirmed insufficient balance fails with Arabic error message.
- Confirmed `wallet_account_id == cash_account_id` produces **FIN-6** (asymmetric journal, fee lost).
- Confirmed inactive wallet account is accepted (**FIN-7**).
- Confirmed no idempotency (this was later confirmed in PHASE 11).
- Discovered: cross-currency wallet is accepted.
- Confirmed: failed POST leaves zero trace (rollback works).

### PHASE 9 (Security / Authorization)
- Discovered **SEC-1** (CRITICAL): default-employee role grants `manage_treasury` automatically.
- Discovered **SEC-2** (MED): show/statement don't filter by creator.
- Confirmed mass-assignment protection works for `created_by`, `balance`, `income_transaction_id`, `status`.
- Confirmed invalid tokens return 401.

### PHASE 10 (Precision / Calculation)
- Confirmed decimal precision is intact (test 0.01, 0.123456789, 100.123).
- Confirmed fee arithmetic (zero fee, high fee, fee=amount, fee>amount).
- Confirmed multi-currency isolation (EGP, USD, SAR).
- Confirmed round-trip: post + delete returns to opening balance.
- Confirmed cross-currency wallet is accepted (no FX conversion).

### PHASE 11 (Idempotency)
- **IDM-1 CRITICAL confirmed**: 100 identical POSTs create 100 transactions.
- `Idempotency-Key` header is NOT honored.
- `X-Request-Id` header is NOT honored.
- Replays at the boundary (100 succeeded, 101st failed with insufficient balance) — no extras created.

### PHASE 12 (Concurrency)
- 50 sends in tight loop: balance invariant holds.
- At balance boundary: 100th send succeeds, 101st fails with insufficient balance.
- Total system money conserved across 10 sends.
- `CONC-1, CONC-2` documented.
- Confirmed customer balances accumulate correctly.

### PHASE 13 (Failure / Rollback)
- Failed POST leaves zero trace (WT row, ledger row, account entry, customer account).
- Delete reverses with "عكس:" prefix on transaction and "عكس القيد #N" on entries.
- Reversal ADDS new entries (append-only), doesn't delete originals.
- Double-delete returns 404/422.
- Multiple create+delete cycles preserve balance.

### PHASE 14 (Full E2E)
- Customer journey: send → receive → delete = full lifecycle.
- Multi-customer / multi-wallet flow.
- Walk-in customer cash collection.
- Multi-currency independent.
- Ledger entries match balance.
- Audit trail complete.
- Customer statement returns transactions.

### PHASE 15 (Reconciliation)
- **FIN-1 gap**: stored=10000, derived=0, gap=10000 (opening balance).
- Reconciliation report matches expected formula after applying the opening-balance offset.
- Total system money conserved.
- Wallet-module-only reconciliation works.

### PHASE 16 (Final Security Audit)
- Mass-assignment: balance, created_by, status, deleted_at, created_at all ignored.
- Bulk operations: 100 rapid POSTs create 100 rows.
- Cross-branch (cross-tenant) account usage is allowed.
- Audit log integrity: user_id is the authenticated user.
- Soft-deleted access: 404 or 200 (status-dependent).
- User deactivation after auth: future requests rejected.
- XSS in notes: stored verbatim (warning).
- SQL injection: protected by Eloquent parameter binding.
- Non-admin can post but cannot delete.
- 500-level errors don't leak stack traces.

---

## 4. Findings severity histogram

```
CRITICAL: 2  ██                              (IDM-1, SEC-1)
HIGH:     7  ███████                         (FIN-1, FIN-2, FIN-3, FIN-6, FIN-7, R1-A, CONC-1, VAL-1)
MED:      9  █████████                       (FIN-4, FIN-5, R1-B, SEC-2, SEC-3, CONC-2, REC-1, UX-1, VAL-4)
LOW:      5  █████                           (UX-2, SEC-4, VAL-2, VAL-3, ...)
```

---

## 5. Per-finding priority matrix

| Pri | ID | Title | Justification |
|---|---|---|---|
| P0 | IDM-1 | No idempotency on POST | Direct financial loss in production. A retry, double-click, or replay creates duplicate transactions. |
| P0 | SEC-1 | Default-employee grants manage_treasury | Any new employee can post wallet transactions. Cannot be revoked without changing role. |
| P1 | FIN-2 | `accountForSend` duplicate income | Production cashier cannot post a Send for a registered customer with default `amount_paid > 0`. Existing test is red. |
| P1 | FIN-3 | `recordExpense` semantic loss | Reports and audits that filter by `type='expense'` miss all clearing-account expenses. |
| P1 | FIN-1 | Balance invariant broken for opening balances | Reconciliation produces phantom deltas daily. |
| P1 | CONC-1 | Race window before lock | Burst of POSTs can create phantom WT rows. |
| P1 | FIN-6 | Self-loop wallet creates asymmetric journal | Fee is lost; reconciliation will diverge. |
| P1 | FIN-7 | Inactive wallet account accepted | Closed accounts can move money. |
| P1 | R1-A | GET index route missing | Page 2 of the cash register UI cannot fetch. |
| P1 | VAL-1 | No currency-mismatch validation | Cross-currency transactions accepted silently. |
| P2 | FIN-4 | Daily summary sums `amount` not `total_amount` | Cash-flow report understates by fees. |
| P2 | FIN-5 | AuditLog uses `model_type`/`model_id` | Off-the-shelf audit consumers cannot plug in. |
| P2 | SEC-2 | Show / statement no creator filter | Horizontal privilege escalation (IDOR). |
| P2 | SEC-3 | Cross-branch account usage allowed | Multi-tenancy boundary not enforced. |
| P2 | CONC-2 | `ensureCustomerAccount` not locked | Orphan customer accounts possible. |
| P2 | REC-1 | Reconciliation gap = opening balance | Reconciliation queries need a workaround. |
| P2 | R1-B | `routes/finance.php` dead code | Confusion / maintenance hazard. |
| P2 | UX-1 | All exceptions → 422 | Client can't distinguish validation from business logic. |
| P2 | VAL-4 | Notes XSS | Stored as-is; if rendered in admin, XSS possible. |
| P3 | UX-2 | Arabic error messages | Not localizable. |
| P3 | SEC-4 | Soft-deleted WT may still be reachable | Status-dependent. |
| P3 | VAL-2 | No dust-attack protection | Anything ≥ 0.01 accepted. |
| P3 | VAL-3 | No max-amount cap | Only fails on insufficient balance. |

---

## 6. Out-of-scope (per user override)

- **Hajj/Umra** audit — explicitly excluded.
- **Visa** audit — explicitly excluded.
- **Flight / Bus / Fawry / Online / HR** — out of scope for this audit.
- **Existing `tests/Feature/Wallet/WalletTransactionCrudTest.php`** — the legacy CRUD test was NOT modified; its 7 failures are documented as the canonical manifestation of FIN-2 and R1-A.

---

## 7. Files created in this audit

### Test files
- `tests/Feature/Wallet/WalletTestCase.php`
- `tests/Feature/Wallet/Support/Decimal.php`
- `tests/Feature/Wallet/Support/AccountState.php`
- `tests/Feature/Wallet/Support/Assertions.php`
- `tests/Feature/Wallet/Phases/Phase00SmokeTest.php`
- `tests/Feature/Wallet/Phases/Phase07PositiveTest.php`
- `tests/Feature/Wallet/Phases/Phase08NegativeTest.php`
- `tests/Feature/Wallet/Phases/Phase09SecurityTest.php`
- `tests/Feature/Wallet/Phases/Phase10PrecisionTest.php`
- `tests/Feature/Wallet/Phases/Phase11IdempotencyTest.php`
- `tests/Feature/Wallet/Phases/Phase12ConcurrencyTest.php`
- `tests/Feature/Wallet/Phases/Phase13RollbackTest.php`
- `tests/Feature/Wallet/Phases/Phase14FullE2ETest.php`
- `tests/Feature/Wallet/Phases/Phase15ReconciliationTest.php`
- `tests/Feature/Wallet/Phases/Phase16FinalSecurityAuditTest.php`

### Documentation artifacts
- `WALLET_TRANSFER_FINDINGS.md` — 23 findings with severity, phase, reproduction, recommendation
- `WALLET_TRANSFER_TEST_INDEX.md` — test file index and how-to-run
- `WALLET_TRANSFER_API_CATALOG.md` — endpoint × role × middleware × finding catalog
- `WALLET_TRANSFER_FINANCIAL_INVARIANTS.md` — invariants verified, with status
- `WALLET_TRANSFER_SECURITY_MATRIX.md` — authorization matrix, mass-assignment, race, soft-delete
- `WALLET_TRANSFER_RECONCILIATION.md` — SQL queries for production reconciliation
- `WALLET_TRANSFER_RUN_REPORT.md` — this file

### Resource objects (REPORT-ONLY)
- `.zcode/plans/wallet-transfer-audit-discovery-20260820.md` — pre-discovery (from Phase 0)

---

## 8. Recommendation summary

**Implement these in order of priority:**

1. **P0 — Add idempotency** to all POST endpoints. The `Idempotency-Key` header (RFC draft) is the standard. Persist key + response payload; replay returns cached response.

2. **P0 — Revoke default-employee `manage_treasury`** in `UserPermissions::effectiveFor()`. Require explicit grant for each module.

3. **P1 — Fix `accountForSend`** so the settlement flow doesn't POST a duplicate income. Either drop the settlement (let it be a separate Transfer action) or use a different `related_type` so the duplicate guard doesn't fire.

4. **P1 — Add `is_opening` flag to `account_entries`** and auto-create an opening-balance entry when an account is created with non-zero balance. Update the docblock.

5. **P1 — Add `category` column to `transactions`** so `recordExpense` can preserve the expense semantic independently of the journal pattern.

6. **P1 — Move `lockForUpdate` BEFORE `WalletTransaction::create()`** in `WalletTransactionService::createTransaction()`.

7. **P1 — Add validation**: `wallet_account_id != cash_account_id`, `wallet_account.is_active`, `wallet_account.currency == cash_account.currency`.

8. **P1 — Wire the `index()` route** in `routes/api.php`.

9. **P2 — Wrap `ensureCustomerAccount()` in a row-level lock** on the customer row.

10. **P2 — Add creator-based filtering** on `show` and `customerStatement` endpoints (or a policy).

11. **P2 — Either delete `routes/finance.php` or register it** in `bootstrap/app.php`.

12. **P2 — Fix `getDailySummary`** to include `total_amount` in the sum.

13. **P2 — Distinguish 422 from business-logic errors** in the controller.

14. **P3 — Sanitize `notes` field** on input (or document the responsibility belongs to the renderer).

15. **P3 — Add max amount** to validation.

---

## 9. Audit methodology

- **Discovery:** Phase 0 — read `routes/api.php`, `app/Services/Wallet/*`, `app/Models/Account.php`, `app/Models/Transaction.php`, `app/Services/Finance/TransactionService.php`, `bootstrap/app.php`.
- **Oracle:** Independent `Decimal.php` (bcmath) with proper half-away-from-zero rounding; `AccountState.php` reads via `DB::table()` only (never via the service under test).
- **Fixtures:** `WalletTestCase.php` seeds 1 wallet + 3 cashboxes + 2 clearing accounts + 3 users + 2 customers in a per-test RefreshDatabase transaction.
- **Per-file execution:** All phase tests run as `vendor/bin/phpunit tests/Feature/Wallet/Phases/PhaseNN.php --no-coverage` to avoid the parallel-runner conflict with the broken Hajj/Umra + Visa tests (`UU` markers in `git status`).
- **REPORT-ONLY:** No application code was modified. The `Decimal.php` rounding fix and the harness seed creation are part of the test infrastructure, not the application.

---

## 10. Conclusion

**The Wallet & Transfers module is FUNCTIONAL but has 2 CRITICAL security/financial holes that need immediate attention (IDM-1, SEC-1) and 7 HIGH-severity issues that affect integrity, validation, or concurrency.**

The double-entry invariant `SUM(debit) == SUM(credit)` per transaction is intact. Total system money is conserved. The append-only reversal pattern is correct.

The most urgent fix is idempotency (IDM-1) — without it, every retry, double-click, and network flake creates a duplicate financial transaction. The audit's 100-iteration tight loop in `Phase11IdempotencyTest` demonstrates this is a real, reachable production failure mode.

---

*End of WALLET_TRANSFER_RUN_REPORT.md*
