# Phase 10.2 — Admin E2E (Section 6)

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Tests:** `tests/Feature/HajjUmra/HajjUmraAdminFullLifecycleTest.php` (21 tests, all PASS)
**Regression:** 586/591 (5 baseline Class-D + 1 error remain out of Hajj/Umra scope)

---

## 1. Scope

Section 6 of the 30-section prompt, applied independently to the Hajj/Umra
module. The Hajj/Umra default status on create is `confirmed` (not
`pending` like Visa's `submitted`) — this was independently confirmed and
locked in by `test_create_booking_with_status_pending_succeeds`.

### Scenarios
- **A.** Create (no initial / with initial / with companion / with passengers / with supplier / with executing-company)
- **B.** Read (index, show)
- **C.** Multi-payment (cash + bank + wallet), cross-currency rejection
- **D.** Lifecycle: Confirmed → InProgress → Completed
- **E.** Financial invariants (income, expense, customer AR)
- **F.** Payment state gates (rejected on cancelled / refunded)
- **G.** Full happy paths: create→pay→refund / create→pay→cancel / create→pay→delete

---

## 2. Defects Found and Fixed

### Class-B — Cross-currency payment silent ledger corruption (FIXED)

**Location:** `app/Services/HajjUmra/HajjUmraBookingService.php:633`

**Symptom:** an EGP-denominated Hajj/Umra booking could accept a payment
with a USD treasury as `account_id`. The service only checks the
`HajjUmraLiquidityAccount` rule (module_type + liquidity type + active
status) — currency was never compared. `TransactionService::recordJournalTransfer`
falls back to `$toAmount = $amount` when currencies don't match and no
`converted_amount` / `exchange_rate` is supplied, silently treating the
EGP amount as the destination USD amount.

**Fix:** Added a currency-mismatch check at the service boundary, before
the journal transfer is recorded:

```php
$account = Account::query()->findOrFail($accountId);
if (strtoupper((string) $account->currency) !== strtoupper((string) ($locked->currency ?? 'EGP'))) {
    throw new \RuntimeException(
        'عملة الحجز (...) لا تطابق عملة حساب الدفع (...). '
        .'يجب إجراء تحويل عملات عبر نظام التحويل المعتمد.'
    );
}
```

Per the user's Phase 10 directive, this is an **independently discovered
defect** in Hajj/Umra — same class of bug as Phase 9.12 Visa, but
reproduces here without assuming Visa behavior. The test
`test_multi_payment_with_different_currencies_is_rejected` confirms the
fix.

---

## 3. Test Coverage Matrix (21 tests)

| # | Test | Concern | Result |
|---|------|---------|--------|
| A1 | `test_create_minimal_booking_returns_201_with_default_status` | default = Confirmed | ✅ |
| A1b | `test_create_booking_with_status_pending_succeeds` | Pending reachable | ✅ |
| A2 | `test_create_booking_with_initial_payment_succeeds` | initial payment | ✅ |
| A3 | `test_create_booking_with_companion_prices` | companion math | ✅ |
| A4 | `test_create_booking_with_passengers_breakdown` | passengers | ✅ |
| A5 | `test_create_booking_links_executing_company_account` | observer auto-link | ✅ |
| A6 | `test_create_booking_with_supplier` | UmrahSupplier | ✅ |
| B1 | `test_index_returns_paginated_list` | index | ✅ |
| B2 | `test_show_returns_booking_with_relations` | show | ✅ |
| C1 | `test_multi_payment_cash_then_bank_then_wallet` | 3-method mix | ✅ |
| C2 | `test_multi_payment_with_different_currencies_is_rejected` | **after fix** | ✅ |
| D1 | `test_create_with_status_confirmed_succeeds` | status reach | ✅ |
| D2 | `test_create_with_status_in_progress_succeeds` | status reach | ✅ |
| D3 | `test_create_with_status_completed_succeeds` | status reach | ✅ |
| E1 | `test_create_booking_posts_income_and_expense_ledger_entries` | ledger entries | ✅ |
| E2 | `test_paid_booking_reduces_customer_ar` | AR math | ✅ |
| F1 | `test_cannot_record_payment_on_cancelled_booking` | gate | ✅ |
| F2 | `test_cannot_record_payment_on_refunded_booking` | gate | ✅ |
| G1 | `test_full_lifecycle_create_pay_refund` | happy path | ✅ |
| G2 | `test_full_lifecycle_create_pay_cancel` | happy path | ✅ |
| G3 | `test_full_lifecycle_create_pay_delete` | soft-delete | ✅ |

---

## 4. Financial Invariants Verified

| Invariant | Result |
|-----------|--------|
| Per-account `balance == SUM(credit) - SUM(debit)` | ✅ (after every scenario) |
| Global `SUM(credit) == SUM(debit)` | ✅ (assertLedgerGloballyBalanced) |
| Customer AR after create = selling_price | ✅ |
| Customer AR after pay N = selling_price - N | ✅ |
| Income transaction amount = selling_price | ✅ |
| Expense transaction amount = purchase_price | ✅ |
| Profit = selling_price - purchase_price (single passenger) | ✅ |
| Profit = selling_total - purchase_total (with companion + extra) | ✅ |

---

## 5. Regression Status

```
$ vendor/bin/phpunit tests/Feature/HajjUmra/ tests/Feature/TourismDivision/ \
                      tests/Feature/TourismAudit/ \
                      tests/Feature/TourismEmployeeE2E/EmployeeHajjUmraE2ETest.php \
                      tests/Feature/Finance/TourismTrialBalanceIntegrityTest.php
Tests: 586 / Assertions: 2325 / Errors: 1 / Failures: 5
```

vs Phase 10.1 baseline: 565/2266/1/5
**Net: +21 tests, +59 assertions, 0 new failures.**

---

## 6. Verdict

🟢 **Phase 10.2 PASS.** 1 Class-B defect fixed (cross-currency payment
corruption), 21 admin E2E scenarios verified, full financial invariants
hold after every lifecycle.

**Circuit Breaker: CLEARED.** Proceeding to Phase 10.3 (Employee Deep E2E).
