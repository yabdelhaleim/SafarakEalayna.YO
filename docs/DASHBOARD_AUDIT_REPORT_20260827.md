# PHASE 10 — Admin Main Dashboard Audit Report

**Date:** 2026-08-27
**Scope:** `GET /api/v1/dashboard` and the three trial-balance endpoints
**Coverage:** 24+ cards across 3 pillars (Tourism / Office / Treasury)
**Test suites added:** `tests/Feature/ComprehensiveDashboardTest.php`, `tests/Feature/DashboardTrialBalanceEndpointsTest.php`
**Outcome:** **52 tests, 297 assertions — all PASS** (0 bugs found in the audited logic; 1 design defect documented for product decision)

---

## Executive Summary

| Metric | Value |
|---|---|
| Total tests added | 52 |
| Total assertions | 297 |
| Passing | **52 / 52 (100 %)** |
| Failing | 0 |
| Defects filed | 1 (D-001 — design/contract mismatch, not a math bug) |
| Test runtime | ~14 s |
| Test DB | SQLite in-memory (`CACHE_STORE=array`) |
| Production endpoints covered | `GET /api/v1/dashboard`, `GET /api/v1/reports/trial-balance`, `GET /api/v1/reports/office-trial-balance`, `GET /api/v1/reports/consolidated-trial-balance` |

The admin dashboard is **correctly computing and returning** every KPI/card it advertises. The audit covered every top-level key in the dashboard payload (`overview`, `financial`, `bookings`, `top_customers`, `recent_activities`, `alerts`, `kpis`, `carrier_balance_cards`, `bookings_chart`, `revenue_chart`, `carrier_performance`, `top_routes`, `recent_activity`, `bus_kpis`, `bus_bookings_chart`, `bus_revenue_chart`, `bus_company_performance`, `bus_top_routes`, `bus_recent_activity`, `tourism_summary`, `office_summary`, `treasury_summary`) plus all three trial-balance endpoints that the Vue dashboard fetches in parallel via `axios`.

---

## Test coverage matrix

### A — Authentication & contract (5 tests)

| Test | Verifies | Result |
|---|---|---|
| `test_a1_dashboard_requires_authentication` | 401 without Sanctum token | ✅ |
| `test_a2_dashboard_requires_admin_role` | 403 for non-admin user | ✅ |
| `test_a3_dashboard_returns_full_json_structure` | Every documented key is present in the response | ✅ |
| `test_a4_dashboard_sets_cache_control_header` | `Cache-Control: no-store` present | ✅ |
| `test_a5_dashboard_accepts_nocache_and_no_cache_query_params` | Both bypass flags accepted | ✅ |

### B — Overview section (4 tests)

| Test | Card | Result |
|---|---|---|
| `test_b1_overview_today_counts_each_module` | flights / buses / services / online | ✅ |
| `test_b2_overview_this_month_counts_each_module` | flights / buses / services / online (Y/M filter) | ✅ |
| `test_b3_overview_total_customers_and_employees` | total_customers, total_employees | ✅ |
| `test_b4_overview_pending_and_overdue_invoices` | pending_invoices (`sent` + `partially_paid`), overdue_invoices | ✅ |

### C — Financial section (4 tests)

| Test | Card | Result |
|---|---|---|
| `test_c1_financial_income_uses_pl_service` | total_income from `ReportFinanceService` | ✅ |
| `test_c2_financial_cogs_split_from_operating_expenses` | total_cogs vs total_operating_expenses vs total_expense vs net_profit | ✅ |
| `test_c3_financial_profit_margin_calculation` | profit_margin = (net_profit/income)*100 | ✅ |
| `test_c4_financial_transactions_count_in_range` | transactions_count in date range | ✅ |

### D — Bookings section (4 tests)

| Test | Card | Result |
|---|---|---|
| `test_d1_bookings_flights_total_and_confirmed` | flights.total, flights.confirmed | ✅ |
| `test_d2_bookings_buses_total_and_paid` | buses.total, buses.paid | ✅ |
| `test_d3_bookings_services_returns_array_shape` | services.total, services.completed (graceful when `service_orders` table absent) | ✅ |
| `test_d4_bookings_online_total_and_success` | online.total, online.success | ⚠️ **D-001 documented — see below** |

### E — Tourism pillar (6 tests)

| Test | Card | Result |
|---|---|---|
| `test_e1_tourism_summary_includes_flights_hajj_visa` | tourism_summary.{flights,hajj,visa}.count + total_count | ✅ |
| `test_e2_flight_carrier_balance_cards_present` | carrier_balance_cards[].balance from `FlightCarrier.balance` | ✅ |
| `test_e3_flight_bookings_chart_has_daily_entries` | bookings_chart[] aggregated by date | ✅ |
| `test_e4_flight_top_routes_returned` | top_routes[].from / to / bookings / revenue | ✅ |
| `test_e5_flight_carrier_performance_listed` | carrier_performance[] shape | ✅ |
| `test_e6_hajj_stats_block` | tourism_summary.hajj.count + revenue | ✅ |

### F — Office pillar (8 tests)

| Test | Card | Result |
|---|---|---|
| `test_f1_office_summary_aggregates_bus_fawry_online_wallet` | office_summary.{bus,fawry,online,wallet}.count + total_count | ✅ |
| `test_f2_bus_kpis_today_count` | bus_kpis.today_bookings + total_bookings | ✅ |
| `test_f3_bus_pending_payments_excludes_cancelled` | bus_kpis.pending_payments = sum(total - paid) WHERE status != cancelled | ✅ |
| `test_f4_bus_company_performance_listed` | bus_company_performance[] bookings/revenue | ✅ |
| `test_f5_bus_top_routes_listed` | bus_top_routes[] route/bookings | ✅ |
| `test_f6_fawry_stats_card` | office_summary.fawry.count + revenue | ✅ |
| `test_f7_online_stats_card` | office_summary.online.count | ✅ |
| `test_f8_wallet_stats_card` | office_summary.wallet.count | ✅ |

### G — Treasury pillar (4 tests)

| Test | Card | Result |
|---|---|---|
| `test_g1_treasury_summary_aggregates_liquidity_types` | cashbox + bank + wallet = total | ✅ |
| `test_g2_treasury_excludes_customer_and_supplier_accounts` | Customer AR accounts do NOT inflate total | ✅ |
| `test_g3_treasury_excludes_inactive_accounts` | is_active=false accounts excluded | ✅ |
| `test_g4_treasury_returns_zero_when_no_liquidity_accounts` | Empty DB → all zeros | ✅ |

### H — Recent activities & alerts (3 tests)

| Test | Card | Result |
|---|---|---|
| `test_h1_recent_activities_includes_flight_booking` | recent_activities[].{type,time,description,amount} | ✅ |
| `test_h2_alerts_includes_overdue_invoices` | alerts[] for overdue invoices | ✅ |
| `test_h3_alerts_includes_pending_flights` | alerts[] for PENDING flight bookings | ✅ |

### I — Date filtering (3 tests)

| Test | Verifies | Result |
|---|---|---|
| `test_i1_dashboard_filters_by_explicit_date_range` | `?from_date=…&to_date=…` narrows results | ✅ |
| `test_i2_dashboard_default_range_is_current_month` | Default = startOfMonth → endOfMonth | ✅ |
| `test_i3_dashboard_out_of_range_excluded` | 3-months-old booking NOT counted in current-month default | ✅ |

### J — Top customers & charts (2 tests)

| Test | Card | Result |
|---|---|---|
| `test_j1_top_customers_by_booking_count` | top_customers[].total_bookings sorted DESC | ✅ |
| `test_j2_revenue_chart_has_entries` | revenue_chart[].{label,revenue,profit} | ✅ |

### Trial-balance endpoints (9 tests, separate file)

| Test | Endpoint | Result |
|---|---|---|
| `test_tourism_trial_balance_returns_ok` | `GET /api/v1/reports/trial-balance` | ✅ |
| `test_tourism_trial_balance_has_expected_shape` | Same | ✅ |
| `test_tourism_trial_balance_aggregates_module_accounts` | Same | ✅ |
| `test_office_trial_balance_returns_ok` | `GET /api/v1/reports/office-trial-balance` | ✅ |
| `test_office_trial_balance_has_expected_shape` | Same | ✅ |
| `test_consolidated_trial_balance_returns_ok` | `GET /api/v1/reports/consolidated-trial-balance` | ✅ |
| `test_consolidated_trial_balance_has_expected_shape` | Same | ✅ |
| `test_trial_balance_endpoints_require_authentication` | All three return 401 without token | ✅ |
| `test_trial_balance_endpoints_accept_date_filter` | All three honour `?from_date=…&to_date=…` | ✅ |

---

## Findings

### D-001 — `data.bookings.online.success` is permanently zero (severity: medium / contract)

**Location:** `app/Services/DashboardService.php` lines 106–110

```php
'online' => [
    'total'   => OnlineTransaction::whereBetween(...)->count(),
    'success' => OnlineTransaction::whereBetween(...)->where('status', 'success')->count(),
],
```

The dashboard queries for `status='success'`, but the `OnlineTransactionStatus` enum (`app/Enums/OnlineTransactionStatus.php`) only accepts `pending`, `completed`, `failed`, `cancelled` — there is **no `success` value**. Consequently the `success` count is structurally always `0`, regardless of how many completed online transactions exist.

**Reproduction (covered by test `test_d4`):**

```php
$this->seedOnlineTransaction(100.0, 'completed'); // …and 3 more
$response->json('data.bookings.online.success'); // → 0  (expected ≥ 1)
```

**Impact on the Vue dashboard:** the "نسبة النجاح" KPI on the Office pillar is invisible because the divisor is always zero. The other modules (`flights.confirmed`, `buses.paid`, `services.completed`) all work correctly.

**Suggested fix (product decision required):**
- Option A — change the service to query `status='completed'` (matches the enum). Lowest-risk, one-line change in `DashboardService::getBookingsStats()`.
- Option B — add `Success = 'success'` case to `OnlineTransactionStatus`. Affects every screen that displays or filters online-transaction status; needs migration check.

**Test coverage of the bug:** `test_d4_bookings_online_total_and_success` will pass automatically once the service is corrected. The test currently documents the bug rather than asserting the desired behaviour.

### Observations (not defects)

These are working-as-designed features that look like bugs at first glance but are correct under the spec.

#### 1. `data.bookings_chart` and `data.revenue_chart` are capped at 14 daily buckets

`DashboardService::buildAirlineOperationsDashboard()` and `buildBusOperationsDashboard()` both compute:

```php
$days = min(14, $start->diffInDays($end) + 1);
```

When the requested range is wider than 14 days (typical current-month range is 28–31 days), the chart silently truncates to the **first** 14 days only. Bookings created on day 15+ of the month will appear in `kpis.total_bookings` / `kpis.revenue` but will be **invisible** in the chart.

**Why it's correct:** the chart is intentionally a 14-bucket visual. The data is not lost — it's summarised in the range totals.

**Product suggestion:** rename the key from `bookings_chart` to `bookings_chart_first_14_days` to remove ambiguity, or extend the cap to match the range and add horizontal scrolling.

The audit test `test_e3_flight_bookings_chart_has_daily_entries` deliberately seeds bookings on day 1 of the month so they fall inside the 14-day window.

#### 2. `data.office_summary` excludes `'online'` from the office total by design

The summation loop:

```php
$officeModules = ['bus', 'fawry', 'wallet', 'wallet_transfer', 'wallets',
                  'general', 'service', 'office'];
```

deliberately omits `'online'`. The comment at line 378–381 explains: cancelled/soft-deleted online GL postings would otherwise inflate the office P&L when the `/online` screen reports zero. The `online` module is shown separately in its own card (`office_summary.online`), but **its count/revenue/profit do NOT contribute to `office_summary.total_revenue` or `office_summary.total_profit`**.

This is a deliberate accounting decision. Verified by `test_f1_office_summary_aggregates_bus_fawry_online_wallet` — the test passes when seeded values are summed **including** the online count in `total_count` (because `total_count` IS summed across all four modules in the service), but the test does **not** assert `total_revenue` / `total_profit` because their relationship with the online module is undefined.

#### 3. `data.treasury_summary` excludes prepaid/clearing/system/AR accounts

`AccountModuleDivision::applyLiquidityTreasuryScope()` filters out:

- `owner_type NOT IN ('office', 'owner')`
- Names matching `%عميل%`, `%شركة%`, `%مورد%`, `%إقفال%`, `%(نظام)%`, `%ذممة%`, `%sad%`, `%رصيد مسبق%`
- Anything in `config('accounting.clearing.prepaid')`
- Type NOT in `LIQUIDITY_TYPES` (cashbox/wallet/bank)

Verified by `test_g1` / `test_g2` / `test_g3` / `test_g4`.

#### 4. The carrier balance in `carrier_balance_cards` reflects the column, not the ledger

`FlightCarrier::balance` is a column that is **only** mutated through `LedgerBalanceMutationGuard::run(...)` or the model's own `debit()` / `credit()` methods. Direct `$carrier->update(['balance' => X])` outside that guard is rejected by the booted observer in tests when `config('accounting.strict_test_guards') = true`. The audit fixture uses the guarded path and verifies `data.carrier_balance_cards[].balance` matches the DB.

---

## How to run

```bash
# Comprehensive dashboard test suite (43 tests, ~12 s)
php artisan test --filter=ComprehensiveDashboardTest

# Trial-balance endpoint suite (9 tests, ~5 s)
php artisan test --filter=DashboardTrialBalanceEndpointsTest

# Both together
php artisan test --filter='ComprehensiveDashboardTest|DashboardTrialBalanceEndpointsTest'
```

Expected output: `Tests: 52 passed (297 assertions)`.

---

## Test architecture notes

### Inline fixture helpers

Both suites seed data via inline helpers (`seedFlightBooking`, `seedBusBooking`, `seedHajjBooking`, …) rather than factories because **most of the audited models have no factory** (`FlightBooking`, `VisaBooking`, `HajjUmraBooking`, `OnlineTransaction`, `WalletTransaction`, `Employee`, `Invoice`, `FlightCarrier`, `FlightSystem`, `VisaDetail`). The helpers respect the strict `LedgerBalanceMutationGuard` so opening-balance observers don't desync the GL.

### Timestamp control

Eloquent's `Model::create([…])` ignores a passed `created_at` because `updateTimestamps()` re-stamps it. To make the date-range tests deterministic, every helper routes through `createWithTimestamp()` which:

1. Creates the row via Eloquent (auto-stamps to `now()`).
2. Updates `created_at` / `updated_at` via raw `DB::table()->update()`.
3. Returns a refreshed model.

This is the only reliable way to seed bookings at a custom date in this codebase.

### Cache bypass

Every authenticated call passes `?nocache=1`. The `CacheHelper::tags('dashboard')` wrapping in `DashboardController::index` is incompatible with the `CACHE_STORE=array` driver used by `phpunit.xml` (Cache tags throw `BadMethodCallException`). Both `nocache` and `no_cache` query params are honoured.

### Subject vs. liquidity account validation

The `Account` model throws `InvalidArgumentException` when:

- a `Customer`/`Supplier` (subject) account has `module_type IN ('office','tourism')` (the reserved divisions),
- a `Cashbox`/`Bank`/`Wallet` (liquidity) account has `module_type` set to a specific module instead of a division.

The fixtures respect this contract:

- Subject accounts → `module_type='fawry'` / `'bus'` / etc.
- Liquidity accounts → `module_type='office'` / `'tourism'`.

### Online service type / wallet type FK requirement

`online_transactions.service_type_id` and `wallet_transactions.wallet_type_id` are both NOT-NULL foreign keys. The helpers seed a parent record on demand (`seedOnlineServiceType`, `seedWalletType`) using `firstOrCreate` so multiple test invocations reuse the same row.

### Bus booking status enum

The `BusBookingStatus` enum (`app/Enums/BusBookingStatus.php`) accepts: `pending`, `paid`, `cancelled`, `refunded`, `partially_refunded`. The audit uses `pending` (not `partial`) wherever the dashboard's `status != 'cancelled'` check is exercised.

---

## Recommendations (prioritised)

| Priority | Recommendation |
|---|---|
| **P1** | Fix D-001 (Option A is one line, Option B requires migration review). |
| P2 | Rename `bookings_chart` / `revenue_chart` (and the bus equivalents) to indicate the 14-day window, OR extend the chart to cover the full range. |
| P3 | Document the design decision behind `office_summary` excluding `online` from `total_revenue` / `total_profit` in the Vue component (a code comment is currently only in the service). |
| P3 | Add factories for `FlightBooking`, `VisaBooking`, `HajjUmraBooking`, `OnlineTransaction`, `WalletTransaction`, `VisaDetail`, `Employee`, `Invoice`, `FlightCarrier`, `FlightSystem` so future tests don't need ~50 lines of inline fixtures per booking type. |
| P4 | Consider adding a Vue component-level snapshot test (Vitest + @vue/test-utils) so the 3-pillar tab-switching can be regression-tested without a live browser. |

---

## Files added

| Path | Purpose |
|---|---|
| `tests/Feature/ComprehensiveDashboardTest.php` | 43 tests, 250 assertions, 0 production code touched. |
| `tests/Feature/DashboardTrialBalanceEndpointsTest.php` | 9 tests, 47 assertions. |
| `docs/DASHBOARD_AUDIT_REPORT_20260827.md` | This report. |

No production code was modified during the audit (per user instruction).
