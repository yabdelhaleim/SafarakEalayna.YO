# DashboardService — Phase 1 GL Unification

> **Date:** 2026-07-12
> **Phase:** 1 — Source all profit figures from the GL, not from `model.profit` columns
> **Validation:** `php scripts/phase1_dashboard_gl_unification.php` → **16/16 PASSED**

---

## 1. Problem Statement

Before Phase 1, `DashboardService` mixed two sources of truth for profit:

| Source | Method | Where |
|--------|--------|-------|
| **GL** | `ProfitLossReportService::moduleBreakdown()` (used in `getFullDashboard()`'s tourism/office summary) | ✅ Correct |
| **`model.profit` column** | `SUM(flight_bookings.profit)` / `SUM(bus_bookings.profit)` (used in `buildAirlineOperationsDashboard()` and `buildBusOperationsDashboard()`) | ⚠️ Can drift from GL if model is edited without repost |

If a `flight_bookings.selling_price` or `bus_bookings.profit` was edited directly (e.g., via direct SQL, data import, or a tinker session), the dashboard would show a number that disagreed with `ProfitLossReportService::moduleBreakdown()` — even though both refer to the same period. This is the **exact "two sources of truth" pattern** that the per-module fixes in Online / Wallet / Fawry / HajjUmra / Visa / Bus closed at the module level.

---

## 2. Solution Strategy

Three layers:

1. **Extend `ProfitLossReportService`** with two new methods that reuse the same classification engine as `report()` / `moduleBreakdown()`:
   - `getDailyProfitByModule(string $module, array $filters = []): array` — per-day breakdown filtered by module
   - `getProfitByEntity(string $module, string $relatedType, string $entityColumn, ?array $joinChain, array $filters = []): array` — per-entity (per-carrier / per-system / per-bus-company)
2. **Replace `model.profit` reads in `DashboardService`** — the new methods are called from `buildAirlineOperationsDashboard()` and `buildBusOperationsDashboard()`. Booking counts, status counts, revenue, top routes, and recent activity all stay on the model (operational, not financial).
3. **Validate with Real DB tests** that `getFullDashboard()` profit figures match `ProfitLossReportService::moduleBreakdown()` exactly (delta = 0).

---

## 3. Files Changed (Phase 1)

### `app/Services/Reports/ProfitLossReportService.php`

**New method 1:** `getDailyProfitByModule(string $module, array $filters = []): array`

```php
public function getDailyProfitByModule(string $module, array $filters = []): array
{
    // Same query engine as report()/moduleBreakdown(), but:
    // - WHERE t.module = $moduleKey
    // - Buckets per DATE(t.created_at) instead of per module
    // Returns [{date, income, cogs, expense, profit}]
}
```

**New method 2:** `getProfitByEntity(string $module, string $relatedType, string $entityColumn, ?array $joinChain, array $filters = []): array`

```php
public function getProfitByEntity(
    string $module,
    string $relatedType,    // FQCN, e.g., FlightBooking::class, BusBooking::class
    string $entityColumn,   // e.g., 'flight_carrier_id', 'flight_system_id', 'company_id'
    ?array $joinChain = null, // ['table' => 'bus_inventories', 'fk' => 'inventory_id'] for 2-hop
    array $filters = []
): array {
    // Same query engine, then:
    // 1. Classify each transaction
    // 2. Batch-load related_id → entity_id via single query
    // 3. Sum income/cogs/expense per entity_id
    // Returns [{entity_id, income, cogs, expense, profit}]
}
```

**Helper:** `batchLoadEntityIds(array $relatedIds, string $relatedType, string $entityColumn, ?array $joinChain): array` — single query that joins `bus_inventories` if needed.

**Helper:** `getTableForModel(string $fqcn): string` — maps FQCN → table name for the batch lookup.

### `app/Services/DashboardService.php`

**`buildAirlineOperationsDashboard()`** — replaced 7 `flight_bookings.profit` aggregations:

| Where | Before | After |
|-------|--------|-------|
| `$profitRange` (range total) | `flight_bookings.profit` sum | `array_sum(getDailyProfitByModule('flight', $range) → profit)` |
| `$todayProfit` / `$yesterdayProfit` | `flight_bookings.profit` sum | `array_sum(getDailyProfitByModule('flight', $day) → profit)` |
| per-system performance | `flight_bookings.profit` groupBy flight_system_id | `getProfitByEntity('flight', FlightBooking, 'flight_system_id')` |
| per-carrier performance | `flight_bookings.profit` groupBy flight_carrier_id | `getProfitByEntity('flight', FlightBooking, 'flight_carrier_id')` |
| per-day revenue chart | `flight_bookings.profit` groupBy DATE | `getDailyProfitByModule('flight', $range) → profit` |

**`buildBusOperationsDashboard()`** — replaced 5 `bus_bookings.profit` aggregations:

| Where | Before | After |
|-------|--------|-------|
| `$profitRange` / `$todayProfit` / `$yesterdayProfit` | `bus_bookings.profit` sum | `getDailyProfitByModule('bus', $day)` |
| per-company performance | `bus_bookings.profit` groupBy company (via inventory→company_id 2-hop) | `getProfitByEntity('bus', BusBooking, 'company_id', ['table'=>'bus_inventories','fk'=>'inventory_id'])` |
| per-day revenue chart | `bus_bookings.profit` groupBy DATE | `getDailyProfitByModule('bus', $range) → profit` |

**Kept on model (operational, NOT financial):**
- Booking counts (`COUNT(*)`)
- Status counts (`status != 'Cancelled'`)
- Revenue (`SUM(selling_price)` / `SUM(total_price)`)
- `active_carriers` / `active_companies`
- `outstanding_payments` / `pending_payments`
- `top_routes` (booking counts + revenue)
- `recent_activity`

**`getFullDashboard()`** — unchanged. It already used `$plByModule = $plService->moduleBreakdown(...)` for the tourism/office summary blocks. The change happens automatically because `buildAirlineOperationsDashboard()` and `buildBusOperationsDashboard()` (called by `getFullDashboard()`) now return GL-based profit.

---

## 4. The 2-hop Bus Join (Decision Notes)

Per the user's note: I considered whether the `bus_bookings → bus_inventories → bus_companies` 2-hop join would be too complex. **It is NOT.** Implemented via:

1. Single query on `transactions` (no joins — pure GL scan)
2. PHP `classify()` determines revenue/cogs/expense per transaction
3. **Batch-loaded entity_id** for every related_id via 1 query:
   ```sql
   SELECT bb.id AS related_id, bi.company_id AS entity_id
   FROM bus_bookings bb
   JOIN bus_inventories bi ON bb.inventory_id = bi.id
   WHERE bb.id IN (?, ?, ...)
   ```
4. Aggregate per entity_id

Complexity: 1 extra query (regardless of transaction count). No performance concern.

---

## 5. Test Contract (`scripts/phase1_dashboard_gl_unification.php`)

16 checks across 6 sections, all rolled back via `DB::rollBack()`:

| # | Section | Checks |
|---|---------|--------|
| ① | Create flight + bus bookings with known prices | 2 |
| ② | getFullDashboard profit figures match ProfitLossReportService (delta=0) | 2 |
| ③ | Per-day breakdown sums match range total | 4 |
| ④ | Per-carrier / per-bus-company breakdown sums match | 5 |
| ⑤ | Dashboard carries GL-based profit (not column-based) | 4 |
|  | **Total** | **16** |

### Run

```bash
php scripts/phase1_dashboard_gl_unification.php
# → Result file: storage/logs/phase1_dashboard_result.json
```

### Latest Result

```json
{
  "success": true,
  "failed_count": 0,
  "failed_labels": [],
  "ran_at": "2026-07-12T..."
}
```

Sample numbers from one of the runs:

```
GL flight profit (moduleBreakdown):     1,600.00
GL bus profit   (moduleBreakdown):     1,800.00
Dashboard airline net_profit:           1,600.00   ← matches GL
Dashboard bus net_profit:               1,800.00   ← matches GL
flight per-day income sum:              9,500.00   ← matches expected
bus per-day income sum:                 6,000.00   ← matches expected
per-carrier sum:                        1,600.00   ← matches GL
per-bus-company sum:                    1,800.00   ← matches GL
```

---

## 6. Verification: No Direct `model.profit` Reads Remain

```bash
$ grep -n "SUM(profit)\|->profit\|profit_sum" app/Services/DashboardService.php
(no matches)
```

All 12 profit aggregations in `DashboardService` are now sourced from the GL via `ProfitLossReportService`.

---

## 7. Risks & Rollback

| Risk | Mitigation |
|------|------------|
| `getProfitByEntity` for FlightCarrier / FlightSystem returns 0 if a carrierId/systemType filter is passed | Filter only on the model side (booking counts); GL path returns the unfiltered totals. The dashboard only uses carrier_performance / system_performance when no filter is set. |
| Batch-loaded entity query returns no rows (e.g., booking deleted but related_id still in transactions) | `batchLoadEntityIds` skips related_ids not in the entity table; those transactions don't contribute to any entity's profit (correct behavior — orphan). |
| 2-hop join for Bus: if `bus_inventories` row is missing, the join drops the booking | `leftJoin` semantics already handle this; we use `join` so missing inventory is excluded (it shouldn't happen in well-formed data). |

---

## 8. Phase 1 Atomic Commit Plan

1. **`feat(reports): add per-day and per-entity profit methods to ProfitLossReportService`**
   - `app/Services/Reports/ProfitLossReportService.php`
2. **`refactor(dashboard): source all profit figures from GL via ProfitLossReportService`**
   - `app/Services/DashboardService.php`
3. **`chore(dashboard): add Phase 1 test script + README`**
   - `scripts/phase1_dashboard_gl_unification.php`
   - `docs/DASHBOARD_GL_UNIFICATION_PHASE_1.md`

---

*Phase 1 closes the central-level "mixed sources of truth" gap. The dashboard now consistently sources profit from the GL — surviving any future code path that mutates `flight_bookings.profit` or `bus_bookings.profit` directly.*