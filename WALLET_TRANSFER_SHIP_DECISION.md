# WALLET_TRANSFER_SHIP_DECISION.md

**Audit:** Wallet & Transfers — Full E2E / Security / Financial Integrity
**Date:** 2026-08-20
**Verdict:** ❌ **DO NOT SHIP** as-is.

---

## TL;DR

The Wallet & Transfers module has **2 CRITICAL** and **7 HIGH** severity findings that must be remediated before this module ships to production. The structural invariants (double-entry, append-only reversal, money conservation) are intact, but the surface-level interfaces have security and data-integrity gaps that are exploitable in normal production conditions.

---

## Why not ship

### CRITICAL (P0 — must fix)

1. **IDM-1 — No idempotency mechanism on POST `/api/v1/wallet/transactions`**
   - Double-click, network retry, or payload replay = duplicate financial transaction.
   - The audit demonstrated 100 identical POSTs create 100 transactions.
   - In production, this is a direct financial-loss vector.

2. **SEC-1 — Default-employee role grants `manage_treasury` automatically**
   - Any new user with `role='employee'` can post wallet transactions.
   - There is no way to explicitly deny this permission without changing the role.
   - A compromised admin or registration flow can create a wallet-cashier-level user.

### HIGH (P1 — fix before next release)

3. **FIN-2 — `accountForSend` posts duplicate active income** (registered customer, `amount_paid > 0`)
   - The existing `tests/Feature/Wallet/WalletTransactionCrudTest.php` is failing for this reason.
   - Cashiers cannot post a Send via the existing UI/test without setting `amount_paid=0`.

4. **FIN-3 — `recordExpense` writes `type='transfer'` (semantic loss)**
   - Any report filtering by `type='expense'` misses all clearing-account expenses.
   - P&L, treasury dashboard, and audit queries are skewed.

5. **FIN-1 — Balance invariant broken for accounts with non-zero opening balance**
   - Every reconciliation will report a phantom delta equal to the sum of opening balances.
   - The daily `ledger:reconcile` command produces false-positive findings daily.

6. **CONC-1 — Race window before `lockForUpdate()`**
   - Burst of concurrent sends can create phantom WT rows before balance is checked.

7. **FIN-6 — `wallet_account_id == cash_account_id` creates asymmetric journal**
   - Fee is silently lost. Reconciliation will report a discrepancy.

8. **FIN-7 — Inactive `wallet_account_id` is accepted**
   - Closed accounts can move money. Regulatory risk.

9. **R1-A — `GET /api/v1/wallet/transactions` index route is missing**
   - Page 2 of the cash register UI cannot fetch.

10. **VAL-1 — No currency-mismatch validation**
    - Cross-currency transactions accepted silently (no FX conversion).

---

## What is intact (the GOOD)

These structural invariants are HELD across all 135 tests:

- ✅ **Double-entry per transaction** (`SUM(debit) == SUM(credit)` per `transaction_id`).
- ✅ **Total system money conserved** across thousands of operations.
- ✅ **Append-only reversal pattern** — soft-delete + "عكس:" prefix + new `AccountEntry` rows.
- ✅ **Mass-assignment protection** at the field level (created_by, balance, status, deleted_at, created_at are all server-controlled).
- ✅ **Audit log integrity** — every write produces a row with the correct user_id.
- ✅ **Failed POST leaves zero trace** — full rollback works.
- ✅ **Boundary enforcement** — the wallet cannot go negative (the 101st send fails with insufficient balance).
- ✅ **Decimal precision** — bcmath exact arithmetic, no floating-point drift.
- ✅ **Multi-currency isolation** — EGP, USD, SAR accounts are independent.
- ✅ **Round-trip preservation** — post + delete returns to opening balance.

---

## Production readiness checklist

| # | Item | Status | Required for ship? |
|---|---|---|---|
| 1 | IDM-1 (idempotency) | ❌ | YES |
| 2 | SEC-1 (default-employee) | ❌ | YES |
| 3 | FIN-2 (duplicate income) | ❌ | YES |
| 4 | FIN-3 (semantic loss) | ❌ | YES |
| 5 | FIN-1 (balance invariant) | ❌ | YES |
| 6 | CONC-1 (race window) | ⚠️ | YES |
| 7 | FIN-6 (self-loop) | ❌ | YES |
| 8 | FIN-7 (inactive accounts) | ❌ | YES |
| 9 | R1-A (missing index) | ❌ | YES |
| 10 | VAL-1 (currency mismatch) | ❌ | YES |
| 11 | FIN-4 (daily summary) | ⚠️ | NO (P2) |
| 12 | FIN-5 (audit log convention) | ⚠️ | NO (P2) |
| 13 | SEC-2 (no creator filter) | ⚠️ | NO (P2) |
| 14 | SEC-3 (cross-tenant) | ⚠️ | NO (P2) |
| 15 | CONC-2 (customer account race) | ⚠️ | NO (P2) |
| 16 | REC-1 (reconciliation gap) | ⚠️ | NO (P2) |
| 17 | R1-B (dead code) | ⚠️ | NO (P2) |
| 18 | UX-1 (422 errors) | ⚠️ | NO (P2) |
| 19 | VAL-4 (XSS) | ⚠️ | NO (P2) |
| 20 | UX-2 (Arabic errors) | ⚠️ | NO (P3) |
| 21 | SEC-4 (soft-deleted reach) | ⚠️ | NO (P3) |
| 22 | VAL-2 (no dust-attack) | ⚠️ | NO (P3) |
| 23 | VAL-3 (no max amount) | ⚠️ | NO (P3) |

---

## Recommended sequencing

**Sprint 1 (P0 — 1–2 days):**
- IDM-1: Add `Idempotency-Key` middleware to all POST endpoints.
- SEC-1: Refactor `UserPermissions::effectiveFor()` to require explicit permission sets.

**Sprint 2 (P1 — 3–5 days):**
- FIN-2: Drop `postSettlementSend` from `accountForSend`; or use a different `related_type`.
- FIN-3: Add `category` column to `transactions`.
- FIN-1: Add `is_opening` column to `account_entries`; auto-create opening entry.
- CONC-1: Move `lockForUpdate` before `WalletTransaction::create()`.
- FIN-6/FIN-7: Add validation in `StoreWalletTransactionRequest`.
- R1-A: Wire the index route.
- VAL-1: Add currency-mismatch validation.

**Sprint 3 (P2 — 1–2 weeks):**
- FIN-4: Fix `getDailySummary`.
- SEC-2: Add creator-based filtering or policies.
- SEC-3: Add branch isolation.
- CONC-2: Lock `ensureCustomerAccount`.
- REC-1: Update reconciliation tool.
- R1-B: Delete or register `routes/finance.php`.
- UX-1: Distinguish 422 from business-logic errors.
- VAL-4: Sanitize notes field.

**Sprint 4 (P3 — opportunistic):**
- UX-2, SEC-4, VAL-2, VAL-3.

---

## Who can ship RIGHT NOW

The module can ship with the following carve-outs:

- **Read-only access** (no POST, no DELETE, no PUT). The existing 5 GET endpoints are safe.
- **Single-tenant deployment** where employees are individually provisioned and the registration flow is tightly controlled.
- **Internal-only deployment** where network retries are managed by the client.

It cannot ship as a public-facing cashier / customer-facing tool until at least IDM-1 and SEC-1 are remediated.

---

## Sign-off criteria

A re-audit will sign off when:

1. All P0 items are fixed AND have passing tests.
2. All P1 items are fixed OR have a documented exception with a mitigation plan.
3. The `tests/Feature/Wallet/WalletTransactionCrudTest.php` (legacy CRUD test) passes.
4. The audit's new phase tests (`Phase07`-`Phase16`) still pass with the fixes applied.

---

## Re-audit plan

After the P0/P1 fixes:

1. Re-run all 135 phase tests.
2. Add tests for:
   - Idempotency-Key middleware (replay returns same response).
   - Negative permission (explicit `permissions=[]` for employee is honored).
   - `accountForSend` with `amount_paid > 0` does not crash.
   - Opening-balance AccountEntry is created on account creation.
3. Verify the legacy `WalletTransactionCrudTest.php` passes.
4. Run a 1000-iteration concurrent send test and verify wallet balance is exactly correct.

---

*End of WALLET_TRANSFER_SHIP_DECISION.md*
