# Vue Dashboard Cache Fix — 2026-08-24

## Symptom

Vue Dashboard at `/dashboard` (resources/js/views/Dashboard.vue) showed
all 0 numbers for tourism KPIs while office numbers (60.6K) still
appeared. The dashboard's tourism net profit specifically was reported
broken.

## Root Cause

`DashboardController::index` was the only report endpoint that did NOT
set `Cache-Control: no-store` on its response. The sibling endpoints
in `FinancialReportController` (trial-balance, profit-by-day,
debts-report, capital-analysis, etc.) all set this header to prevent
the browser from caching responses that reflect the live GL state.

Without the header, the browser held onto the response from the first
load and continued serving the same JSON for the full cache TTL — even
after a deploy added new data on the server. The 5-minute server-side
Laravel cache (300 s `Cache::remember`) made the staleness worse.

The Vue SPA at `/dashboard` calls `/api/v1/dashboard` every 15 seconds
(polling) and on every refresh, so once the browser cached an empty
response, every subsequent poll returned the same 0-snapshot until a
hard refresh or the 5-minute TTL expired.

## Fix

Two coordinated changes:

### 1. `app/Http/Controllers/Api/V1/DashboardController.php`

- Adds `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
  and `Pragma: no-cache` to the response so the browser stops caching
  the dashboard snapshot.
- Adds a `?nocache=1` (and `?no_cache=1`) bypass so a single request
  can skip the server-side `Cache::remember` — useful right after a
  deploy when the cached value is known to be stale.

### 2. `resources/js/views/Dashboard.vue`

- Passes `nocache=1` when `isRefreshing` is true (i.e. the user clicked
  the "تحديث Data الحية" refresh button). This guarantees the refresh
  button always returns fresh data instead of the cached snapshot.

## Verification

Local test (SQLite, simulated data) confirmed:
- `ProfitLossReportService::moduleBreakdown()` returns correct flight
  revenue from a `type='transfer'` row going income_clearing → cashbox.
- `ProfitLossReportService::report(['category' => 'tourism'])` returns
  correct `totalRevenues` / `netProfit` from the same row.
- `DashboardService::getFullDashboard()` correctly assembles the
  `tourism_summary` from the two upstream services.
- `DashboardController::index` sets the `Cache-Control` header (test
  `test_dashboard_endpoint_sets_cache_control_header` PASS).
- The endpoint accepts `?nocache=1` and `?no_cache=1` without error
  (test `test_dashboard_endpoint_accepts_nocache_query_param` PASS).
- The endpoint returns the full Vue Dashboard JSON contract
  (test `test_dashboard_endpoint_returns_full_structure` PASS — 38
  assertions).

Existing `UnifiedDashboardTest::unified_dashboard_returns_correct_data_structure_and_values`
failure is pre-existing (reproduces on a clean stash without these
changes) — unrelated to this fix.

## On Staging

After this commit is deployed:
1. Open `/dashboard` — the first request after deploy will bypass the
   stale cache (browsers that already cached the old empty response
   may need one hard refresh; the new `Cache-Control` header prevents
   this in subsequent loads).
2. Click "تحديث البيانات الحية" to force a cache-bypass via `?nocache=1`.
3. KPIs should reflect the live `ProfitLossReportService` results.

## Files Changed

- `app/Http/Controllers/Api/V1/DashboardController.php` — production
- `resources/js/views/Dashboard.vue` — production (Vue SPA)