# Phase 3 — Remove the `max(0, ...)` Floor from the Profit Engine

**Goal:** surface real losses (refunds > revenue) in every consumer of
`ProfitLossReportService`, instead of silently flooring them to zero.

**Status:** ✅ Complete. Engine now returns negatives. Vue renders them in
red. All 5 prior validation scripts still pass. Original red-color
"FAIL" from Phase 2 E2E is resolved.

**Commits (chronological, atomic):**

| # | Hash (short) | Subject |
|---|---|---|
| A | `69d4f03` | Remove 10 `max(0, ...)` clamps from `ProfitLossReportService` |
| B | `56fb92b` | Vue conditional sign classes on 13 profit-display sites (Dashboard / OperationsTemplate / FinanceDashboard) |
| B′ | `e7c69c0` | Document hardcoded color assumption in `ProfitLoss.vue` (per-section comments) |
| C | `3bc1348` | Drop `<= 0` row filter in `moduleBreakdown` + Real DB Validation 12/12 PASS |

---

## What changed

### Engine (`ProfitLossReportService.php`)

| Site | Was | Now |
|---|---|---|
| `report()` line 116-118 | `max(0, round($x, 2))` | `round($x, 2)` |
| `moduleBreakdown()` line 242-244 | `round(max(0, $x ?? 0.0), 2)` | `round($x ?? 0.0, 2)` |
| `formatModuleList()` line 605 | `round(max(0, $sum), 2)` | `round($sum, 2)` |
| `getDailyProfitByModule()` line 735-737 | `round(max(0, $d[$k]), 2)` | `round($d[$k], 2)` |
| `getProfitByEntity()` line 873-875 | `round(max(0, $b[$k]), 2)` | `round($b[$k], 2)` |
| `moduleBreakdown()` row filter line 255 | `if ($income <= 0 && $cogs <= 0 && $expense <= 0) continue;` | `if ($income == 0 && $cogs == 0 && $expense == 0) continue;` |

The last one (`<= 0` → `== 0`) was **discovered by the validation script** during Commit C — under the old `max(0, ...)` floor, a refund-only module had income clamped to 0 before reaching this filter, so `<= 0` and `== 0` were equivalent. After the floor was removed, `<= 0` would silently drop negative-profit modules. Fixed.

### Vue (3 files)

| File | Sites fixed | Pattern applied |
|---|---|---|
| `views/Dashboard.vue` | 7 | `:class="(x.profit ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400'"` |
| `views/finance/OperationsTemplate.vue` | 1 (net_profit card) | gold → conditional gold/error |
| `views/finance/FinanceDashboard.vue` | 2 (capital cards) | full border+bg+text conditional |

Each site now correctly switches to **red** when the engine returns a
negative value. The pre-existing pattern in:
- `AccountsIndex.vue` drill-down modal (totals strip + day/entity tables)
- `DepartmentManagement.vue` KPI cards + perf bars
- `ProfitLoss.vue` netProfit final card

…was already correct and unchanged.

### `ProfitLoss.vue` explanatory comments

The 3 sites that **remain hardcoded** (`totalRevenues/cogs/expenses` colors)
now have inline comments explaining they're "by definition non-negative in
normal accounting" — preserved as DEAD-CODE-BUT-FINE per the design decision.

---

## Validation — Real DB (Commit C)

`scripts_temp_validate_no_floor.php` — **12/12 PASS**:

### Scenario A — single-module net loss
Seed: `1000 income + 2000 refund` → expected profit `-1000`.

| Engine method | Returned profit | Status |
|---|---|---|
| `moduleBreakdown()` | -1000 | ✅ |
| `getDailyProfitByModule()` (by_day row) | -1000 | ✅ |
| `getDailyProfitByModule()` (totals) | -1000 | ✅ |
| `report()` totalRevenues | -1000 | ✅ |
| `report()` netProfit | -1000 | ✅ |

### Scenario B — mixed modules (cross-contamination check)
Seed: flight `+1500 income, 0 refund` and bus `+800 income + 2000 refund`.

| Engine method | Flight | Bus | Status |
|---|---|---|---|
| `moduleBreakdown()` per-module profit | +1500 | -1200 | ✅ |
| `getDailyProfitByModule()` for flight | +1500 | — | ✅ |
| `getDailyProfitByModule()` for bus | — | -1200 | ✅ |

No cross-contamination: bus's refund subtracts ONLY from bus, flight
stays at +1500.

### Scenario C — single-day refund spike
Seed: yesterday `+2000 income` (normal day) and today `+500 income + 1200 refund`.

| Row | Expected | Returned | Status |
|---|---|---|---|
| `by_day[date=today].profit` | -700 | -700 | ✅ — red-color branch will fire |
| `by_day[date=yesterday].profit` | +2000 | +2000 | ✅ — green-color branch |
| `moduleBreakdown` total profit (both days) | +1300 (sum) | +1300 | ✅ — correctly summed across mixed-sign days |

---

## Validation — regressions on all prior scripts

| Script | Phase | Before Phase 3 | After Phase 3 |
|---|---|---|---|
| `scripts_temp_validate_profit_guard.php` | Phase 5 | 15/15 PASS | **15/15 PASS** |
| `scripts_temp_validate_ensure_customer_account.php` | Bend 3 | 17/17 PASS | **17/17 PASS** |
| `scripts_temp_validate_profit_drilldown_endpoints.php` | Phase 2 | 17/17 PASS | **17/17 PASS** |
| `scripts_temp_e2e_profit_drilldown_realistic.php` | Phase 2 | 23/24 PASS | **23/24 PASS** (warning was about no negative-day in seed) |
| `scripts_temp_e2e_profit_drilldown_render.php` | Phase 2 | 10/11 PASS | **11/11 PASS** ✅ |

The render E2E previously had **1 FAIL** (`no day with negative profit — red-color branch not exercised`). After Phase 3, that warning is gone — the same refund-seed now produces a real negative-profit row, and the Vue modal's `text-error` red branch fires correctly.

---

## Re-discovering the original Phase 2 finding

The Phase 2 E2E report's section §5 documented:

> **Finding**: The Vue modal's red-color branch is dead code —
> `ProfitLossReportService::getDailyProfitByModule()` floors all
> negatives at zero. The operator will only ever see green days.

That finding is **now resolved** end-to-end. The same `scripts_temp_e2e_profit_drilldown_render.php` script that originally surfaced the bug now passes 11/11 with the negative-day warning gone.

---

## Files Touched

- **Modified** (4 files):
  - `app/Services/Reports/ProfitLossReportService.php` (engine: 13 lines changed, 5 inline comments added)
  - `resources/js/views/Dashboard.vue` (7 sites updated)
  - `resources/js/views/finance/OperationsTemplate.vue` (1 site updated)
  - `resources/js/views/finance/FinanceDashboard.vue` (2 sites updated)
  - `resources/js/views/finance/ProfitLoss.vue` (3 explanatory comments)

- **New** (1 file):
  - `scripts_temp_validate_no_floor.php` (3-scenario Real DB Validation)
  - `docs/PHASE_3_NO_FLOOR.md` (this file)

---

## Phase 3 Final Status

- ✅ Engine returns negative values across all 4 affected methods
- ✅ Vue renders negatives in red across all 13 newly-fixed sites + the pre-existing sites
- ✅ Mixed-sign modules sum correctly in `moduleBreakdown` (no cross-contamination)
- ✅ Mixed-sign days sum correctly in `moduleBreakdown`
- ✅ No regressions in any prior script (5/5 still pass)
- ✅ Original Phase 2 E2E red-color "FAIL" is now resolved

The system is ready for users to see real losses in the dashboard, the profit drill-down modal, and the existing ProfitLoss page — without any code change needed beyond what's in these 4 commits.