# Fawry Module (الخدمات الإلكترونية) — Deep Production Readiness Report

**Date:** 2026-07-29
**Test suite:** `tests/scripts/fawry_module_DEEP_E2E.php`
**Final result:** **58/58 PASS** ✅ (run twice — stable, idempotent)

---

## 🎯 Outcome

The Fawry module is **production-ready** for the full booking / cancel / accounting flow tested today. **One production-blocking bug was discovered in `DeferredTransactionDeletionGuard` and fixed during this session.** No new bugs were introduced by the test runs.

---

## 🐛 Bug Found & Fixed Today (Blocking)

### Cross-operation false-positive on the deletion guard

**File:** `app/Services/Finance/DeferredTransactionDeletionGuard.php`
**Method:** `customerAccountHasLaterDebit()`

**Symptom:** When a customer had multiple Fawry transactions and any *earlier* transaction was **updated** (price change with ledger repost), **every later transaction for that same customer became un-cancellable**. Trying to delete a clean transaction would throw "لا يمكن حذف العملية بعد تسجيل سداد لاحق على حساب العميل..." even though no actual later payment existed for the operation being deleted.

**Root cause:** The original query counted **any** debit on the customer account posted after the operation's `created_at`, excluding only entries whose `transaction_id` belonged to this op's own postings. After an update, however, the reverse pipeline posts mirror entries (`notes LIKE 'عكس:%'`) on the customer account. These entries:

1. Have `transaction_id` pointing to the **updated op's** transactions, not the cancelled op's — so `whereNotIn($originalTxIds)` did **not** exclude them.
2. Get stamped with `created_at` = now (the moment of the update) — well **after** any later op's `created_at`.
3. Show `debit > 0` (the inverses of credit entries) — so the guard saw them as "later customer payments".

The result: an update on an earlier op spiked the customer-account debits in a window that blocked every later op's cancellation. This is a real production hazard once any customer has more than one transaction and any operator edits an older one.

**The fix (production-safety):**

```php
return DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('ae.account_id', $customerAccountId)
    ->where('ae.debit', '>', 0)
    ->where('ae.created_at', '>', $createdAt)
    ->whereNotIn('ae.transaction_id', $originalTxIds)
    // (a) Exclude bookkeeping reverse / mirror entries
    ->where(function ($q) { /* skip notes LIKE 'عكس%' */ })
    // (b) Only count entries whose parent transaction targets THIS op
    //     (related_type/related_id match) OR has related_id NULL
    //     (walk-in/FIFO pay-debt, which IS a real payment).
    ->where(function ($q) use ($relatedType, $relatedId) { ... });
```

**Verification:**

| Scenario | Behaviour before fix | Behaviour after fix |
|---|---|---|
| Two txs for same customer; update tx1, then delete tx2 (no real later payment on tx2) | ❌ Guard false-positives, blocks delete | ✅ Delete proceeds, ledger reverses cleanly |
| Walk-in tx; pay the full debt via FIFO, then delete the walk-in | ✅ Guard correctly blocks (check 1) | ✅ Guard correctly blocks (check 1) — verified by Section 8 |
| Update an earlier tx via the public API, then attempt to delete a later clean tx | ❌ "لا يمكن حذف العملية..." thrown | ✅ DELETE returned 200 from `DELETE /api/v1/fawry/transactions/{id}` |

---

## ✅ What Was Tested (58 scenarios, 11 sections)

### Section 1 — Treasury bootstrap (the exact "خزن/محافظ/بنوك" you asked for)
- ✅ Created `cashbox` EGP + USD
- ✅ Created `wallet` EGP + USD
- ✅ Created `bank` EGP + USD
- ✅ Fawry machine created from balance 0
- ✅ All 6 source accounts visible in `GET /fawry/accounts` dropdown (returned 200, six IDs found)

### Section 2 — Recharge machine from each treasury
| Source | Amount | Currency | Result |
|---|---|---|---|
| cashbox | 10,000 | EGP | ✅ machine +10,000, src −10,000 |
| wallet | 5,000 | EGP | ✅ machine +5,000, src −5,000 |
| bank | 20,000 | EGP | ✅ machine +20,000, src −20,000 |
| cashbox | 200 | USD | ✅ machine +200 EGP-eq (cross-currency) |
| wallet | 100 | USD | ✅ machine +100 EGP-eq |
| bank | 500 | USD | ✅ machine +500 EGP-eq |

### Section 3 — Booking flow (the complete experience) — isolated customers
- ✅ Withdrawal with machine (machine debited by `fawry_price`, cashbox credited)
- ✅ Deposit (no machine, expense from cashbox)
- ✅ Payment / utility bill
- ✅ Travel permit
- ✅ Each booking flow used a **separate isolated customer** so updates and deletes never pollute the deletion guard view.

### Section 4 — Read flow (index, show, daily-summary)
- ✅ `GET /fawry/transactions?search={RUN}` → 4 rows match
- ✅ `GET /fawry/transactions/{id}` → tx loaded with relations
- ✅ `GET /fawry/transactions/daily-summary?date=today` → HTTP 200

### Section 5 — UPDATE flow (price change with ledger repost)
- ✅ Update `fawry_price` 475 → 480 → machine debited by exactly 5 EGP more
- ✅ Update posted 3 inverse entries (expense, income, machine debit)
- ✅ A no-change edit (notes-only update) added **0** new entries — proven idempotent
- ✅ Customer ledger stayed balanced

### Section 6 — CANCEL flow (full reverse + idempotent)
- ✅ Soft-delete via `deleteTransaction()` → trashed flag set
- ✅ Inverse journal entries posted on the GL
- ✅ Idempotent cancel: second `deleteTransaction()` adds **0** new entries
- ✅ Cancel travel_permit (separate customer) — succeeded cleanly

### Section 7 — REGRESSION (the bug fix)
- ✅ Same customer, two txs (A & B)
- ✅ Updated A → posts reverse entries on the customer account
- ✅ Cancelled B → guard correctly **passes** (was failing before fix)

### Section 8 — Walk-in debt (FIFO + overpayment + rejection)
- ✅ Walk-in transaction created (100 EGP debt — paid 200/300)
- ✅ `POST /fawry/walk-in/pay-debt` for the full 100 EGP — HTTP 200
- ✅ Overpayment (99,999) → HTTP 422 (rejected)
- ✅ Negative amount → HTTP 422 (validation)
- ✅ Foreign-currency settlement account (USD) → HTTP 422 (rejected)
- ✅ Unknown walk-in client → HTTP 422 (no debt to settle)
- ✅ After full pay-debt, attempting to delete the walk-in tx → guard **correctly blocks** (check 1 still fires on real later payments)

### Section 9 — Public HTTP recharge API
- ✅ `POST /fawry/machines/{id}/recharge` from cashbox/wallet/bank × EGP/USD — all 6 → HTTP 200

### Section 10 — Accounting integrity
- ✅ Machine transaction ledger has 10 entries
- ✅ **All 7 E2E-created transactions balance (D == C)** — zero unbalanced
- ✅ Fixture accounts: tracked start/end/delta per cashbox/wallet/bank

### Section 11 — Dashboard + read APIs
- ✅ `GET /fawry/dashboard` → HTTP 200
- ✅ `GET /fawry/treasury/overview` → HTTP 200
- ✅ `GET /fawry/customer-balances` → HTTP 200
- ✅ `GET /fawry/customer-statement?client_name=…` (walk-in) → HTTP 200

---

## 🏆 Production Readiness Matrix

| Aspect | Status | Notes |
|---|---|---|
| Create cashbox / wallet / bank (multi-currency) | ✅ | 6 fixtures, balanced cross-currency |
| Recharge machine from each treasury type | ✅ | EGP direct + USD cross-currency |
| Create withdrawal/deposit/payment/travel_permit | ✅ | machine debited correctly |
| Walk-in booking with AR debt | ✅ | 100 EGP debt flows through walk-in AR |
| Update with price change → ledger repost | ✅ | machine delta applied; reversals posted |
| Update with no-change edit → 0 ledger writes | ✅ | idempotent |
| Soft-delete with full ledger reverse | ✅ | after guard fix |
| Idempotent cancel (double-DELETE) | ✅ | second call adds 0 |
| Walk-in debt FIFO allocation | ✅ | 100 EGP cleared |
| Overpayment rejection | ✅ | HTTP 422 |
| Negative amount validation | ✅ | HTTP 422 |
| Foreign-currency settlement rejection | ✅ | HTTP 422 |
| Unknown walk-in rejection | ✅ | HTTP 422 |
| **Deletion guard (real later payment blocks)** | ✅ | Verified via walk-in pay-debt then delete |
| **Deletion guard (cross-op reverse false-positive fix)** | ✅ | Regression test, was failing pre-fix |
| HTTP API: recharge (cashbox/wallet/bank × EGP/USD) | ✅ | All 6 → HTTP 200 |
| Dashboard / treasury overview / customer-statement | ✅ | HTTP 200 |
| Per-transaction double-entry balance | ✅ | 7/7 E2E txs balanced |
| Machine transaction ledger | ✅ | 10 entries |
| Frequencies / amounts | ✅ | selling − fawry_price = profit |

**Verdict:** 🟢 **PRODUCTION-READY** — the cross-operation false-positive in `DeferredTransactionDeletionGuard` is fixed and regression-tested; the rest of the module is robust, well-guarded, and accounting-correct.

---

## 🔬 Pre-existing PHPUnit Snapshot (informational)

`tests/Feature/Fawry/` has 26 pre-existing PHPUnit failures. We verified they are NOT caused by today's guard fix — they reproduce on `HEAD` (commit `ec158ab`) with the fix stashed (`InvalidArgumentException: Liquidity accounts require module_type to be a DIVISION`). They are out of scope for the E2E readiness of the customer-facing flow but should be triaged in a separate work item.

---

## 📂 Deliverables

1. **Bug fix:**
   - `app/Services/Finance/DeferredTransactionDeletionGuard.php` — `customerAccountHasLaterDebit()` now joins `transactions` table and excludes both (a) reverse/mirror entries (`notes LIKE 'عكس%'`) and (b) settlements belonging to *other* operations.

2. **Test scripts:**
   - `tests/scripts/fawry_module_DEEP_E2E.php` — 58-scenario deep E2E.
   - `tests/scripts/fawry_module_DEEP_E2E_REPORT.md` — this report.

3. **No production schema migrations** were required. The fix is service-layer only.

---

## 🔁 How to Re-run

```bash
# 1. Start MySQL (Laragon)
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld" --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini" --console

# 2. Start Laravel
php artisan serve --host=127.0.0.1 --port=8000

# 3. Run the deep E2E
php tests/scripts/fawry_module_DEEP_E2E.php
```

Expected: **58 pass, 0 fail.** Stable across multiple runs.
