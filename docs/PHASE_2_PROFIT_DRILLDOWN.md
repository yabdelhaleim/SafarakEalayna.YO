# Phase 2 — Profit Tracking Tool (Drill-Down) — Stage 1

**Scope:** Activate the "ربحية الأقسام" cards on `/finance/accounts` with
a modal that breaks each module's profit into per-day detail and top-N
entity ranking — so the operator can answer "الربح ده جاي منين؟" with
two clicks.

**Status:** ✅ Stage 1 complete (Option C from exploration report — modal
triggered from existing cards). Stage 2 (dedicated `/finance/profit-tracking`
page) deferred until Stage 1 usage is validated.

**Commits (chronological, atomic):**

| # | Hash (short) | Subject |
|---|---|---|
| B1+B2 | `1832db0` | Backend: `GET /api/v1/reports/profit-by-day` + `GET /api/v1/reports/profit-entity-top` with module whitelist + entity mapping + visa customer-fallback label clarity |
| Val | `d017f22` | Real DB Validation script — 17/17 PASS |
| F1+F2 | `1b9707a` | Frontend: `openProfitDrilldown` modal in `AccountsIndex.vue` (Day tab + Top Entities tab) |

---

## Why Stage 1 only

The exploration report flagged 3 candidate integration patterns:

- **(A) Tab inside `AccountStatement.vue`** — bloats an already 2160-line page; mentally awkward (per-account scope, but operator wants cross-module view).
- **(B) New dedicated page** — solid, but premature without usage data.
- **(C) Modal triggered from existing "ربحية الأقمسام" cards** ✅ — picked.

Stage 1 ships option (C). If Stage 1 surfaces real demand for a dedicated
analytical workspace, Stage 2 will add `/finance/profit-tracking` as a
sidebar entry next to "الأرباح والخسائر" with date-range filters,
exportable breakdowns, and what-if comparisons.

---

## Backend (Commit B1+B2)

### Endpoints

Both wrapped by the existing `auth:sanctum` + `admin` middleware group
(under `Route::prefix('reports')->middleware('admin')`).

| Method | Path | Wraps | Returns |
|---|---|---|---|
| GET | `/api/v1/reports/profit-by-day` | `ProfitLossReportService::getDailyProfitByModule()` | `{module, from_date, to_date, currency, by_day[], totals}` |
| GET | `/api/v1/reports/profit-entity-top` | `ProfitLossReportService::getProfitByEntity()` | `{module, from_date, to_date, currency, sort, limit, entity_types[]}` |

### Security

- `module` hard-whitelisted to `{flight, bus, hajj_umra, visa, fawry, online, wallet}` — anything else → HTTP 422.
- `relatedType`, `entityColumn`, `joinChain`, `label_model` are derived **server-side** from a `PROFIT_ENTITY_MAP` constant — the client never sends them, so a hostile caller cannot inject arbitrary FQCNs or join chains into the SQL.
- `limit` clamped to `[1, 100]` (default `20`).
- `sort` ∈ `{profit, loss}` only (default `profit`).
- `visa` falls back to `customer_id` (no `visa_agent` mapping in the schema today); `entity_type_label` explicitly reads `'عميل (معرّف العميل المرتبط بالحجز)'` so the operator cannot misread it as "visa_agent".

### Entity mapping (PROFIT_ENTITY_MAP)

| module | entity_type | relatedType | entityColumn | joinChain |
|---|---|---|---|---|
| `flight` | `flight_system` | `FlightBooking` | `flight_system_id` | — |
| `flight` | `flight_carrier` | `FlightBooking` | `flight_carrier_id` | — |
| `bus` | `bus_company` | `BusBooking` | `company_id` | `{table: 'bus_inventories', fk: 'inventory_id'}` (2-hop) |
| `hajj_umra` | `program` | `HajjUmraBooking` | `program_id` | — |
| `visa` | `customer` | `VisaBooking` | `customer_id` | — (fallback) |
| `fawry` | `customer` | `FawryTransaction` | `client_id` | — |
| `online` | `provider` | `OnlineTransaction` | `provider_id` | — |
| `wallet` | `customer` | `WalletTransaction` | `customer_id` | — |

### GL parity

Both endpoints use the same engine (`ProfitLossReportService`) that
the Filament dashboard widgets and the `accounts-list` `stats.performance`
ride-along payload already use. The Real DB Validation script verifies
this end-to-end: it seeds GL rows directly, calls the endpoint, and
asserts the returned numbers equal both the seed AND the dashboard's
`moduleBreakdown()` total.

## Backend Validation (Commit Val)

`scripts_temp_validate_profit_drilldown_endpoints.php` — 17/17 PASS:

| Group | Coverage |
|---|---|
| **A. Module whitelist** | invalid module rejected with 422 (×2 endpoints) + missing module rejected (×1) |
| **B. Empty DB** | both endpoints return empty `by_day`/`items` + zero totals, no errors |
| **C. Visa label clarity** | `entity_type_label` explicitly says "عميل" (not visa_agent) |
| **D. Sort + limit clamping** | limit 999 → 100; limit −5 → 1; sort "hacker" → "profit" |
| **E. E2E GL parity** | seeded 1000 income + 600 expense for flight module → endpoint returns income=1000, expense=600, profit=400 EXACTLY matching both the seed and `moduleBreakdown()` |
| **F. Limit honored** | every entity_type respects the limit cap |
| **G. Response shape** | every required key present at every level |

The script invokes controller methods directly (bypasses `auth:sanctum`)
to test business logic in isolation — auth is added by the route group,
not the controller.

---

## Frontend (Commit F1+F2)

### Card activation

Each "ربحية الأقسام" card in `AccountsIndex.vue` now has:

- `@click="openProfitDrilldown(mod)"` + `@keydown.enter/.space`
- `role="button"` + `tabindex="0"` (a11y)
- `cursor-pointer` + `hover:border-amber-500/40` + subtle `↗` icon that appears on hover (`group-hover/card:opacity-100`)
- `title="اضغط لعرض تفاصيل الربح لـ {module name}"`

### Modal pattern

Reuses the existing `<teleport to="body">` + `z-[200]` + `bg-black/75 backdrop-blur-md` shape from the file's Create/Edit modals. Same skeleton loader (`TextLineSkeleton`). Two tabs in a top strip (mirrors `AccountStatement.vue:127–146`).

### Day tab (`يومي`)

- 4-tile totals strip at the top (income / cogs / expense / profit) matching the card's color treatment.
- Full-width table: date · income · cogs · expense · profit (profit colored green/red).
- Numbers come from `/api/v1/reports/profit-by-day` for the period "start of month → today" — matches the card exactly, so the totals row always agrees with the number the operator clicked.

### Top Entities tab (`أعلى الكيانات`)

- For modules with multiple entity types (e.g. `flight` → `flight_system` + `flight_carrier`), a small sub-tab strip appears at the top.
- Ranked table: # · entity_label · income · cogs · expense · profit.
- Top 20 by default; configurable via `?limit=` if needed in future.

### Period (Stage 1)

Fixed: from start of month to today. Stage 2 will add an in-modal date range picker.

### Empty / loading / error states

- Loading: `TextLineSkeleton` rows.
- Empty: centered Arabic message.
- Error: error-colored banner.

---

## Files Touched

### New (2 files)

- `scripts_temp_validate_profit_drilldown_endpoints.php` — Backend validation
- `docs/PHASE_2_PROFIT_DRILLDOWN.md` — this file

### Modified (3 files)

- `app/Http/Controllers/Api/V1/Reports/FinancialReportController.php` — added 2 methods + 2 constants + 1 helper (303 lines added)
- `routes/api.php` — registered 2 routes (2 lines added)
- `resources/js/views/finance/AccountsIndex.vue` — click handler + modal + state (322 lines added, 7 removed)

---

## Stage 2 (deferred)

When usage data validates demand:

- New sidebar entry `تحليل الأرباح` → `/finance/profit-tracking`
- Date range picker (default = current month)
- Period comparison (this month vs last month, YoY)
- Exportable CSV / PDF
- Per-entity drill-down (click an entity row → opens that entity's ledger)

No code changes until Stage 1 ships to users and feedback arrives.