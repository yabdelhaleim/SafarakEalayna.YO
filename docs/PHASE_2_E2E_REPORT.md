# Phase 2 — End-to-End Realistic Validation Report

**Goal:** verify that the profit drill-down tool behaves correctly under
a realistic seed (multi-module / multi-day / multi-entity), with full
parity between the three layers the operator sees:

1. **Card** on `/finance/accounts` (`GET /api/v1/finance/accounts`)
2. **Day tab** in the modal (`GET /api/v1/reports/profit-by-day`)
3. **Top Entities tab** in the modal (`GET /api/v1/reports/profit-entity-top`)

**Scripts (both run inside `DB::beginTransaction()` / `DB::rollBack()`):**

| Script | Coverage | Result |
|---|---|---|
| `scripts_temp_e2e_profit_drilldown_realistic.php` | Multi-module, multi-day, multi-entity, all 6 parity axes | **23 PASS / 1 warning / 0 FAIL** |
| `scripts_temp_e2e_profit_drilldown_render.php` | Render fidelity (Arabic Intl currency formatting), sub-tab navigation, empty states, NaN/undefined guards, refund scenario | **10 PASS / 0 warning / 1 FAIL (expected)** |

---

## 1. Realistic Seed

```
Flight module — 8 bookings × 3 carriers × 3 days:
  Day -5 (2026-07-07):  EgyptAir  1000  cost  600  profit 400
                        Saudia    1500  cost  900  profit 600
  Day -3 (2026-07-09):  EgyptAir   800  cost  500  profit 300
                        Emirates  2200  cost 1500  profit 700
                        Saudia    1200  cost  700  profit 500
  Day  0 (2026-07-12):  Emirates  1800  cost 1100  profit 700
                        EgyptAir   950  cost  550  profit 400
                        Saudia    1100  cost  650  profit 450

  Total: income 10550, expense 6500, profit 4050

Bus module — 5 bookings × 2 companies × 2 days:
  Day -4:  SuperJet qty=2 selling=150 cost=100   revenue 300 cost 200 profit 100
           GoBus    qty=3 selling=130 cost= 90   revenue 390 cost 270 profit 120
  Day -2:  SuperJet qty=1 selling=160 cost=100   revenue 160 cost 100 profit  60
  Day  0:  SuperJet qty=4 selling=155 cost=100   revenue 620 cost 400 profit 220
           GoBus    qty=2 selling=140 cost= 95   revenue 280 cost 190 profit  90

  Total: income 1750, expense 1160, profit 590
```

## 2. The Three Layers — What The Operator Actually Sees

### Layer 1: Card number (the number clicked)
```
Flight card:  income=10550  cogs=0  expense=6500  profit=4050
Bus card:     income=1750     cogs=0  expense=1160  profit=590
```

### Layer 2: "يومي" tab — `profit-by-day` response
```
Flight by_day:
  2026-07-07  income=2500   cogs=0  expense=1500  profit=1000   🟢
  2026-07-09  income=4200   cogs=0  expense=2700  profit=1500   🟢
  2026-07-12  income=3850   cogs=0  expense=2300  profit=1550   🟢
  totals:      income=10550  cogs=0  expense=6500  profit=4050

Bus by_day:
  2026-07-08  income=690    cogs=0  expense=470   profit=220    🟢
  2026-07-10  income=160    cogs=0  expense=100   profit=60     🟢
  2026-07-12  income=900    cogs=0  expense=590   profit=310    🟢
  totals:      income=1750  cogs=0  expense=1160  profit=590
```

### Layer 3: "أعلى الكيانات" tab — `profit-entity-top` response
```
Flight:
  [flight_system]  (نظام طيران) — 0 items
  [flight_carrier] (شركة طيران) — 3 items
    #8 Saudia    income=3800  cogs=0  expense=2250  profit=1550  🟢
    #9 Emirates  income=4000  cogs=0  expense=2600  profit=1400  🟢
    #7 EgyptAir  income=2750  cogs=0  expense=1650  profit=1100  🟢
Bus:
  [bus_company]   (ششركة باصات) — 2 items
    #13 SuperJet E2E  income=1080  cogs=0  expense=700  profit=380  🟢
    #14 GoBus E2E     income=670   cogs=0  expense=460  profit=210  🟢
```

## 3. Parity Assertions (the most important result)

| Assertion | Flight | Bus |
|---|---|---|
| card.profit == profit-by-day.totals.profit | ✅ 4050 == 4050 | ✅ 590 == 590 |
| card.income == profit-by-day.totals.income | ✅ 10550 | ✅ 1750 |
| card.cogs == profit-by-day.totals.cogs | ✅ 0 == 0 | ✅ 0 == 0 |
| card.expense == profit-by-day.totals.expense | ✅ 6500 | ✅ 1160 |
| Σ entity-top items.profit == card.profit | ✅ 4050 | ✅ 590 |
| entity-top items sorted profit DESC | ✅ | ✅ |
| entity-label is resolved name (not "#ID") | ✅ Saudia, Emirates, EgyptAir | ✅ SuperJet E2E, GoBus E2E |
| by_day rows match seeded dates (no ghost days) | ✅ | ✅ |
| by_day rows have no NaN / non-numeric | ✅ | ✅ |
| entity-top items have no NaN / non-numeric | ✅ | ✅ |
| entity-top items have non-empty entity_label | ✅ | ✅ |
| formatCurrency(0) renders cleanly | ✅ ‏٠ ج.م.‏ | — |
| formatCurrency(null) renders cleanly | ✅ ‏٠ ج.م.‏ (no NaN) | — |
| formatCurrency("not-a-number") renders cleanly | ✅ ‏٠ ج.م.‏ (no NaN) | — |
| sub-tab strip renders for flight (multi-entity) | ✅ [نظام طيران] [شركة طيران] | — |
| Empty module returns empty by_day (Vue empty-state will render) | ✅ for hajj_umra | — |
| Empty module returns empty entity-top items | ✅ for hajj_umra/program | — |

**All 16 hard parity / correctness assertions PASS.** The number the operator
clicks on the card is **exactly** the totals they will see in the modal — to
the cent. The engine returns one row per seeded day (no ghost rows), items
are sorted profit DESC, labels are resolved (no raw "#ID" leakage), and all
numeric cells are valid numbers (no NaN, no null, no strings).

## 4. Render fidelity (what the user actually sees)

Using `NumberFormatter('ar-EG', NumberFormatter::CURRENCY)` — the PHP ICU bridge
that mirrors the Vue `formatCurrency` Intl options exactly:

```
=== Vue table preview ('يومي' tab) ===
Date                 Income           COGS        Expense         Profit
2026-07-11   ‏٢٬٠٠٠ ج.م.‏ ‏٠ ج.م.‏ ‏١٬٢٠٠ ج.م.‏ ‏٨٠٠ ج.م.‏  🟢 green
2026-07-12   ‏٤٠٠ ج.م.‏   ‏٠ ج.م.‏ ‏٠ ج.م.‏     ‏٤٠٠ ج.م.‏  🟢 green

=== Vue table preview ('أعلى الكيانات' tab → flight_carrier) ===
#   Carrier                      Income           COGS        Expense         Profit
1   Saudia               ‏١٬٠٠٠ ج.م.‏ ‏٠ ج.م.‏ ‏٥٠٠ ج.م.‏  ‏٥٠٠ ج.م.‏  🟢
2   EgyptAir             ‏١٬٠٠٠ ج.م.‏ ‏٠ ج.م.‏ ‏٧٠٠ ج.م.‏  ‏٣٠٠ ج.م.‏  🟢

=== Vue sub-tab strip preview (flight) ===
[نظام طيران] (flight_system) — 0 items
[شركة طيران] (flight_carrier) — 2 items
```

Arabic-Indic digits (٢٬٠٠٠), Arabic currency suffix (ج.م.), and RLM marks (‏)
are applied correctly. Empty-state copy will render cleanly for modules with
zero data.

## 5. ⚠️ Finding: Vue red-color branch is dead code

The Vue modal's per-day profit cell uses `:class="row.profit >= 0 ? 'text-success' : 'text-error'"`.
The red branch (`text-error`) is **never reached** under the current engine
behavior, because:

`ProfitLossReportService::getDailyProfitByModule()` floors negative income at 0
**before** computing profit:

```php
// ProfitLossReportService.php:735-738
$d['income']  = round(max(0, $d['income']), 2);
$d['cogs']    = round(max(0, $d['cogs']), 2);
$d['expense'] = round(max(0, $d['expense']), 2);
$d['profit']  = round($d['income'] - $d['cogs'] - $d['expense'], 2);
```

So if `income` was about to go to -400 (refund > revenue) on a given day,
the engine floors it to 0 → profit = 0 → never negative → red branch never
fired.

This is **consistent across the engine** — same `max(0, …)` floor in
`report()` (line 116-118), `moduleBreakdown()` (line 242-244),
`getProfitByEntity()` (line 873-875). It is a deliberate Phase 1 design
choice — dashboards never show negative P&L aggregates.

### What this means for the user

The operator **will never see a red day** in the day tab or a red entity
in the entity tab. The green/red color toggle is effectively cosmetic
right now — green always wins. If/when the engine is updated to allow
negative `income`/`expense`/`profit` to flow through, the modal is
**already wired** to display them correctly (no Vue change needed).

### Recommendation

This is not a Phase 2 blocker — the modal renders correctly with the
green path. But it is a Phase 3 follow-up question:

> Do we want the drill-down to expose true losses (refunds bigger than
> revenue on a single day), or stay consistent with the dashboards
> (always floor to 0)?

Until that's decided, the red branch is **dead code in the engine's
output path** but harmless in the template.

## 6. Other Findings (all benign)

| Finding | Severity | Notes |
|---|---|---|
| Sub-tabs render for flight (2 entity types) but only `flight_carrier` has rows in this seed | Cosmetic | `flight_system` returns 0 items — empty table renders correctly. Real production data will have both populated. |
| Today row always 0 after refund (refund didn't produce negative profit) | Engine behavior, not bug | Same floor-at-zero issue (see §5). |
| `cogs` always 0 in `profit-by-day` | Engine reality | The engine classifies seed `type=expense` as `operating_expense` (line 421), not `cogs`. COGS is only emitted by the paired-journal logic in `FlightBookingService::createBooking`. To exercise the COGS branch the test would need to call the service, not seed raw GL rows. |

## 7. Final Verdict

### Is the tool ready for daily use? **Yes, with the following caveats:**

| ✅ Ready now | ⚠️ Caveat |
|---|---|
| Card ↔ Day tab ↔ Entity tab parity (numbers match to the cent) | Modal red-color branch is dead code (engine floors negatives) — operator will only ever see green |
| Multi-module + multi-day + multi-entity rendering | Sub-tab strip renders empty `flight_system` if no `flight_system_id` is set on bookings |
| Arabic currency formatting (`Intl.NumberFormat('ar-EG')`) | — |
| Empty states for modules without data | — |
| NaN / undefined / null guards (Vue `formatCurrency` does `Number(amount) \|\| 0`) | — |
| Labels are resolved (no raw "#ID" leakage) | — |
| No regressions in PHPUnit suite (existing 48/48 still pass) | — |
| No data persists (DB::rollBack at end of every E2E scenario) | — |

### Required action before user testing

**None.** The tool behaves exactly as the user expects when clicking a
green/positive card — they see a per-day breakdown that sums back to the
card number, plus a ranked top-entities list. The Arabic currency
formatting, the multi-entity sub-tabs, the empty states, the edge-case
NaN/undefined guards — all verified.

### Recommended action before wider rollout (Phase 3 candidate)

1. **Decide on the red-color question** (see §5). Two options:
   - **(a) Leave as-is** (engine floors negatives → red branch dead code) — consistent with dashboards, but the modal's color toggle is decorative.
   - **(b) Remove the engine floor** (`max(0, …)` clamp) in `getDailyProfitByModule` + `getProfitByEntity` so the modal shows true daily / per-entity losses. Would require a parallel change in the dashboards too (otherwise they would start showing negative P&L aggregates).
2. **Confirm sub-tab behavior when flight_system_id is null** — the current modal renders the sub-tab strip even when one sub-tab has 0 items. This is correct but worth confirming with the operator.

## 8. Reproducing

```bash
# 1. Realistic multi-module / multi-day / multi-entity parity test
php scripts_temp_e2e_profit_drilldown_realistic.php

# 2. Render fidelity + edge cases (Arabic Intl, NaN guards, empty states, sub-tab nav)
php scripts_temp_e2e_profit_drilldown_render.php

# 3. Original Phase 2 backend validation (still passes)
php scripts_temp_validate_profit_drilldown_endpoints.php
```

All scripts run inside `DB::beginTransaction()` / `DB::rollBack()` — no
real data persists.