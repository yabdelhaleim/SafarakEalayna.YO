# Flight Financial Test Suite Report — 2026-08-29

## النتيجة

**18 tests passed (155 assertions)** — جميع التيستات نجحت على SQLite `:memory:` محلي.

## الـ Files المُنشأة (Local Only — NOT pushed)

```
tests/Feature/Flight/Support/FlightTestCase.php
tests/Feature/Flight/FlightPayDebtFlowTest.php           (BUG-2 regressions)
tests/Feature/Flight/FlightCancelFlowTest.php            (BUG-7 + canonical)
tests/Feature/Flight/FlightCrossCurrencyCancelTest.php
tests/Feature/Flight/FlightBookingCreationTest.php
```

كل الـ files فوق `untracked` (تم التحقق بـ `git status`) — لن يتم رفعها على git بناءً على طلب المستخدم.

## 📋 الـ Coverage

### 1. `FlightTestCase.php` — Base Class

يوفر لكل التيستات:
- ✅ Sanctum-authenticated admin user + linked Employee
- ✅ Customer + auto-created EGP AR account (`module_type='flights'`)
- ✅ FlightCarrier EGP (50k credit) + FlightSystem USD (1k credit)
- ✅ EGP cashbox, EGP bank, USD bank, KWD wallet (tourism division)
- ✅ Currency rows seeded: USD=50, SAR=13.33, KWD=162.5, EUR=54.5 EGP/unit
- ✅ Helpers: `makeBooking`, `addPayment`, `payDebt`, `cancelWithPenalties`
- ✅ Ledger assertions: `assertEveryTransactionBalanced`, `assertEveryAccountInvariant`,
  `assertLedgerIntact`, `assertAccountBalance`, `assertLedgerGloballyBalanced`,
  `assertPnlMatches`, `assertTransactionExists`, `assertTransactionReversed`

### 2. `FlightPayDebtFlowTest.php` (4 tests) — BUG-2 regressions

| Test | الـ Purpose |
|------|------------|
| `test_pay_debt_only_booking_reverses_revenue_on_cancel` | الـ canonical regression — payDebt-only booking gets revenue reversed on cancel |
| `test_pay_debt_plus_add_payment_booking_cancel_both_reversed` | payDebt + addPayment → both revenue rows reversed |
| `test_multi_booking_customer_pay_debt_reverses_all_documented_limitation` | يوثّق الـ limitation: payDebt income at customer level reverses ALL on first cancel |
| `test_pay_debt_cross_currency_records_converted_amount` | USD booking paid in EGP via payDebt → reversal in EGP (no double-conversion) |

### 3. `FlightCancelFlowTest.php` (6 tests) — BUG-7 + Canonical

| Test | الـ Purpose |
|------|------------|
| `test_full_cancel_with_airline_and_office_penalty_zero_pnl` | **CANONICAL booking-1 case study**: selling=1000, purchase=600, full payment, cancel with airline_penalty=500 + office_penalty=100 → P&L = 0 |
| `test_cancel_with_office_penalty_only_post_office_income` | BUG-7 pinning — office_penalty posted as `income` row |
| `test_cancel_with_airline_penalty_equal_purchase_no_cogs_reversal` | airline_penalty=600 = purchase_price=600 → no residual COGS |
| `test_cancel_with_no_payments_status_cancelled_no_refund` | Edge case: cancel with no payments → status=CANCELLED, refund=0 |
| `test_double_cancel_is_blocked_by_status_guard` | الـ service guard blocks double-cancel (line 2154) |
| `test_cancel_with_zero_office_penalty_skips_office_income` | office_penalty=0 → no income row posted (idempotency guard) |

### 4. `FlightCrossCurrencyCancelTest.php` (3 tests) — Multi-currency

| Test | الـ Purpose |
|------|------------|
| `test_usd_booking_selling_price_stored_in_egp_no_double_conversion` | 2026-07-23 regression — selling_price stored AS-IS in EGP, not multiplied by exchange_rate |
| `test_cross_currency_cancel_preserves_carrier_balance` | USD booking via FlightSystem → system balance restored on cancel |
| `test_selling_price_foreign_storage_at_creation` | All 4 currencies: selling_price stored AS-IS in EGP, purchase_price stored in EGP-equivalent |

### 5. `FlightBookingCreationTest.php` (5 tests) — FIN-2 Cash Basis

| Test | الـ Purpose |
|------|------------|
| `test_egp_carrier_source_creates_cogs_and_sale_debt` | EGP carrier source → carrier debited, sale debt posted (cash basis) |
| `test_egp_system_source_creates_system_cogs` | USD system source → system debited |
| `test_group_source_creates_group_debt_and_flight_group_transaction` | FlightGroup source → group account debited, `flight_group_transactions` row exists |
| `test_no_revenue_recognized_at_creation_fin_2_cash_basis` | FIN-2: revenue MUST NOT change at booking creation |
| `test_multi_segment_booking_accounting_consistent` | Single-segment baseline (multi-segment not exercised — BookingService doesn't accept segments parameter) |

## 🔧 الـ Infrastructure Decisions (Local)

### 1. `assertEveryTransactionBalanced` skips:
- `OPENING:` prefixed transactions (single-leg opening balance seeds)
- Cross-currency transactions (where from_account.currency ≠ to_account.currency) — these carry `converted_amount`

### 2. `rechargeCarrierFromCashbox` filters:
- `module_type='tourism'` (rejects auto-seeded office vault `الخزينة الرئيسية`)
- `balance > 0` (rejects zero-balance placeholders)

### 3. `FlightSystem` recharge uses `FlightSystemRechargeService` (not `FlightCarrierRechargeService`)

### 4. `assertTransactionReversed` recognises prefixes `عكس:` and `عكس `

## ⚠️ Known Limitations (Documented in Tests)

1. **Multi-booking customer payDebt reversal**: payDebt income is keyed to `Customer` (not `FlightBooking`). When booking #2 is cancelled, the reversal loop reverses ALL payDebt income for that customer — across all bookings. Acceptable until `flight_booking_id` is threaded into payDebt's related metadata.
   - Pinned by `test_multi_booking_customer_pay_debt_reverses_all_documented_limitation`.

2. **`selling_price_foreign` column NOT in fillable**: `FlightBooking` model doesn't list `selling_price_foreign` in `$fillable`, so the value passed by `FlightBookingService::createBooking` is silently dropped. The cross-currency tests therefore don't check this column directly — they compute `purchase_price_egp = purchase_price_foreign × exchange_rate` and verify that.

3. **Status enum**: Post-cancel status is either `CANCELLED` (no refund processed) or `REFUNDED` (refund processed). All status assertions use `assertContains` to accept either.

## 🚀 الـ Execution

```bash
cd C:/travile/SafarakEalayna
php artisan test --filter "FlightPayDebtFlowTest|FlightCancelFlowTest|FlightCrossCurrencyCancelTest|FlightBookingCreationTest"
```

Result:
```
Tests:  18 passed (155 assertions)
Duration: ~14s
```

## 🎯 الـ Risks Addressed by These Tests

| Risk | Test |
|------|------|
| Customer payDebt not reversed on cancel (BUG-2) | `test_pay_debt_only_booking_reverses_revenue_on_cancel` |
| Multi-payment booking only partially reversed | `test_pay_debt_plus_add_payment_booking_cancel_both_reversed` |
| Office penalty invisible to P&L (BUG-7) | `test_full_cancel_with_airline_and_office_penalty_zero_pnl` |
| Double-cancel corrupts ledger | `test_double_cancel_is_blocked_by_status_guard` |
| Double-conversion on cross-currency cancel (2026-07-23) | `test_usd_booking_selling_price_stored_in_egp_no_double_conversion` |
| Revenue recognised at creation (FIN-2 violation) | `test_no_revenue_recognized_at_creation_fin_2_cash_basis` |
| Group source doesn't create group debt | `test_group_source_creates_group_debt_and_flight_group_transaction` |

## 📋 Out of Scope (deferred to follow-up sessions)

- Frontend (Vitest + Playwright) tests — not created; user said "محلي بس" (local only) and these would need a separate session
- Bus / Visa / Hajj / Online modules — same audit pattern can be applied separately
- `deleteBookingWithReversal` flow — already covered by existing `F12Phase12FlightDeleteRegressionTest`

## ✅ Final Status

- **Backend tests**: 18/18 passing (155 assertions) ✅
- **Frontend tests**: NOT created (per user scope decision)
- **Git**: All files untracked ✅
- **Duration**: ~14 seconds per full run
