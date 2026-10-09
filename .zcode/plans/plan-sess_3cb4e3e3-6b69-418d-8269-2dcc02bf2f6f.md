
## Comprehensive Flight Financial Test Suite

### 📋 الـ Files هتتعمل

#### Backend (PHPUnit)

1. **`tests/Feature/Flight/Support/FlightTestCase.php`** — Base class (mirror of `BusTestCase`)
   - Authenticated admin user
   - Customer + auto-created EGP AR account
   - FlightCarrier + FlightSystem (multi-currency)
   - EGP cashbox, USD bank, KWD wallet, EGP bank
   - Exchange rates helpers
   - Helper methods: `createPaidBooking()`, `payDebt()`, `addPayment()`, `cancelWithPenalties()`, `assertPnlMatches()`

2. **`tests/Feature/Flight/Support/AssertsLedgerIntegrity.php`** — Reusable trait
   - `assertEveryTransactionBalanced()` — per-tx debit == credit
   - `assertEveryAccountInvariant()` — `accounts.balance == SUM(credit) - SUM(debit)`
   - `assertPnlIsCorrect($expected)` — wraps `ProfitLossReportService->report()` and asserts revenue/cogs/profit
   - `assertTransactionExists($type, $fromName, $toName, $amount, $notesPrefix, $relatedType, $relatedId)`
   - `assertTransactionReversed($txId)` / `assertTransactionNotReversed($txId)`

3. **`tests/Feature/Flight/FlightPayDebtFlowTest.php`** — FIN-3 payDebt scenarios (regression pinning for BUG-2)
   - `test_pay_debt_only_booking_reverses_revenue_on_cancel`
   - `test_pay_debt_plus_add_payment_booking_cancel_both_reversed`
   - `test_multi_booking_customer_pay_debt_reverse_all_documented_limitation`
   - `test_pay_debt_cross_currency_records_converted_amount`

4. **`tests/Feature/Flight/FlightCancelFlowTest.php`** — Cancel + BUG-7 office_penalty
   - `test_full_cancel_with_airline_and_office_penalty_zero_pnl` ← the canonical regression for booking 1
   - `test_cancel_with_office_penalty_only_post_office_income` ← BUG-7 pinning
   - `test_cancel_with_airline_penalty_equal_purchase_no_cogs_reversal`
   - `test_cancel_with_no_payments_status_cancelled_no_refund`
   - `test_double_cancel_is_blocked_by_status_guard`
   - `test_cancel_with_zero_office_penalty_skips_office_income`

5. **`tests/Feature/Flight/FlightCrossCurrencyCancelTest.php`** — Multi-currency edge cases
   - `test_usd_booking_paid_egp_no_double_conversion` ← 2026-07-23 regression
   - `test_kwd_cancel_refund_in_egp_preserves_carrier_balance`
   - `test_selling_price_foreign_storage_at_creation`

6. **`tests/Feature/Flight/FlightBookingCreationTest.php`** — Creation + sale debt + COGS
   - `test_egp_carrier_source_creates_cogs_and_sale_debt`
   - `test_egp_system_source_creates_system_cogs`
   - `test_group_source_creates_group_debt_and_flight_group_transaction`
   - `test_no_revenue_recognized_at_creation_fin_2_cash_basis`
   - `test_multi_segment_booking_accounting_consistent`

#### Frontend (Vitest + Playwright)

7. **`resources/js/components/flights/RefundWizard.spec.js`** — Vitest unit
   - `totalPaid computed` falls back to `selling_price` when payments list is empty (BUG-3 + 127f978)
   - `totalPaid` from `booking.totalPaid` (API response) takes precedence when present
   - Refund wizard displays `airline_penalty + office_penalty` correctly
   - Currency formatting works for EGP and USD

8. **`resources/js/views/flights/FlightShow.spec.js`** — Vitest unit
   - `paidAmount` rendered from `booking.totalPaid`
   - `paymentStatusLabel` shows "مدفوع بالكامل" when status === 'paid'
   - `remaining` is `selling_price - paid_amount`
   - Booking 1 case: paid_amount = 600 (after payDebt) → status = paid

9. **`tests/E2E/FlightBookingRefund.spec.js`** — Playwright
   - Login as admin
   - Create flight booking (EGP, selling=1000, purchase=600)
   - Pay via `/customers/{id}/pay-debt`
   - Open refund wizard, refund full
   - Navigate to dashboard, assert tourism P&L = 0 for this period
   - Cleanup: mark test booking as deleted (soft-delete)

### �️ الـ Infrastructure (Local Only)

- All test files created under existing directories (`tests/Feature/Flight/` for backend, `resources/js/...` for frontend)
- No `.env.testing` changes — reuse existing SQLite `:memory:` setup
- Vitest config already supports `happy-dom` + `resources/js/test-setup.js`
- Playwright config: target `https://staging.remotel.ly1.site` (will require a `.env.playwright` with credentials, but tests will run from user's local machine)

### 📋 الـ Execution Steps

1. Create `FlightTestCase` base class
2. Create `AssertsLedgerIntegrity` trait
3. Write `FlightPayDebtFlowTest` (BUG-2 regressions)
4. Write `FlightCancelFlowTest` (BUG-7 + canonical scenarios)
5. Write `FlightCrossCurrencyCancelTest` (cross-currency edge cases)
6. Write `FlightBookingCreationTest` (creation flow)
7. Write Vue component specs (RefundWizard, FlightShow)
8. Write Playwright E2E spec
9. Run all tests on local SQLite `:memory:`
10. Run Playwright against staging (manual trigger by user)
11. Document results in `.zcode/plans/FLIGHT_TEST_SUITE_REPORT_20260829.md` (local only)

### ⚠️ Risk & Constraints

- **All test files stay local** (per user preference) — they will NOT be added to git
- Some tests may fail on first run due to data setup gaps — will iterate
- Playwright tests require staging credentials (user runs locally)
- Cross-currency math uses round-safe amounts to avoid floating-point assertions noise

### Out of Scope (deferred)

- Tests for Bus / Visa / Hajj / Online modules (separate audit needed; BUG-7 may exist there too)
- `deleteBookingWithReversal` flow (covered by existing `F12Phase12FlightDeleteRegressionTest`)
- Stress / concurrency / race condition tests
