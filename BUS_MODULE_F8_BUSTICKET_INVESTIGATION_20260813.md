# F-8 Investigation — BusTicket Orphan Module

**Date:** 2026-08-13
**Status:** Investigation only — **ZERO code changes applied**
**Investigator:** Bus Module Audit (read-only scan)

---

## 1. Executive Summary

| Question | Answer |
|---|---|
| Is BusTicket truly orphan/dead code? | **YES** — orphan |
| Is it referenced by any other live functionality? | **NO** |
| Does it have production data? | **NO — 0 rows in MySQL `bus_tickets`** |
| Does it have API endpoints? | **NO — `BusTicketController` exists but is NOT registered in any routes file** |
| Does it have Vue/frontend UI? | **NO** |
| Does it have tests? | **NO** |
| Is it safe to remove? | **YES** (after product sign-off — see §8) |
| Recommended action | **🟡 NEEDS PRODUCT DECISION** — proceed to **REMOVE** in planned sweep |
| Confidence | **HIGH** (10+ independent lines of evidence) |

---

## 2. Files Inventory (Module Surface)

The BusTicket module consists of **9 files** total:

| # | File | Status | Notes |
|---|---|---|---|
| 1 | `app/Models/BusTicket.php` | Exists | Legacy model, has `ModelDeletionGuard` + `ModelProfitMutationGuard` |
| 2 | `app/Services/BusTicketService.php` | Exists | CRUD: create, update, delete, list, getById, getDailyReport, getMonthlyReport |
| 3 | `app/Http/Controllers/Api/BusTicketController.php` | Exists | Full REST controller (index, store, show, update, destroy, report) |
| 4 | `app/Http/Requests/Bus/StoreBusTicketRequest.php` | Exists | Validation rules |
| 5 | `app/Http/Requests/Bus/UpdateBusTicketRequest.php` | Exists | Validation rules |
| 6 | `app/Http/Resources/BusTicketResource.php` | Exists | JSON shape |
| 7 | `app/Filament/Admin/Resources/BusTickets/BusTicketResource.php` | Exists | **Hidden from nav** (`shouldRegisterNavigation=false`) |
| 8 | `app/Filament/Admin/Resources/BusTickets/Pages/ManageBusTickets.php` | Exists | Filament ManageRecords page |
| 9 | `database/migrations/2026_04_27_160500_create_bus_tickets_table.php` | Exists | `bus_tickets` table — **0 rows** |
| 10 | `database/migrations/2026_05_24_232618_add_performance_indexes.php` | Adds indexes to `bus_tickets` | Per-row performance indexes |

**No seeders, no factories, no policies.**

---

## 3. Dependency Map (Who Uses BusTicket?)

### 3.1 Production Code (`app/`)

| Consumer | Location | Purpose |
|---|---|---|
| `BusTicket` (self) | `app/Models/BusTicket.php` | Self |
| `BusTicketService` | `app/Services/BusTicketService.php` | Self |
| `BusTicketController` | `app/Http/Controllers/Api/BusTicketController.php` | Dependency-injects `BusTicketService` |
| `BusTicketResource` (API) | `app/Http/Resources/BusTicketResource.php` | API JSON shape |
| `StoreBusTicketRequest` | `app/Http/Requests/Bus/StoreBusTicketRequest.php` | Validation |
| `UpdateBusTicketRequest` | `app/Http/Requests/Bus/UpdateBusTicketRequest.php` | Validation |
| `BusTicketResource` (Filament) | `app/Filament/Admin/Resources/BusTickets/BusTicketResource.php` | Filament table + form |
| `ManageBusTickets` (Filament page) | `app/Filament/Admin/Resources/BusTickets/Pages/ManageBusTickets.php` | Filament page |

**All 8 internal consumers are part of the BusTicket module itself. ZERO external production code imports `BusTicketService`, `BusTicket` model, or any BusTicket resource.**

### 3.2 Routes

| Route | Status |
|---|---|
| `GET /api/v1/bus-tickets` (index) | ❌ **NOT REGISTERED** |
| `POST /api/v1/bus-tickets` (store) | ❌ **NOT REGISTERED** |
| `GET /api/v1/bus-tickets/{id}` (show) | ❌ **NOT REGISTERED** |
| `PUT /api/v1/bus-tickets/{id}` (update) | ❌ **NOT REGISTERED** |
| `DELETE /api/v1/bus-tickets/{id}` (destroy) | ❌ **NOT REGISTERED** |
| `GET /api/v1/bus-tickets/report` (report) | ❌ **NOT REGISTERED** |
| `GET /admin/bus-tickets` (Filament) | ✅ **REGISTERED** (via Filament auto-discovery) |

**Confirmed via `php artisan route:list`:** Only the Filament route appears. The entire `BusTicketController` is dead code — the controller class exists but cannot be reached via any HTTP request.

> **Forbidden grep:**
> ```
> $ grep -rn "BusTicket" routes/
> (no output)
> ```

### 3.3 Vue / Frontend (`resources/js/`)

| Search | Result |
|---|---|
| `grep -rn "BusTicket\|bus-tickets\|bus_tickets" resources/js/` | **0 hits** |

**No Vue page, no Pinia store, no composable, no API client, no translation key references BusTicket.**

### 3.4 Database Layer

| Search | Result |
|---|---|
| FK references to `bus_tickets` from other tables | **0 FKs** |
| Other tables referencing `bus_tickets` | **none** |
| Seeders creating `bus_tickets` rows | **0 seeders** |
| Factories for `BusTicket` | **0 factories** |
| Production data in `bus_tickets` | **0 rows** (verified via MySQL) |

### 3.5 Policies / Authz

| Search | Result |
|---|---|
| Policies for BusTicket | **0 policies** |
| `BusTicketPolicy` class | does not exist |
| Spatie permissions dedicated to BusTicket | none (covered by generic `admin` + `auth:sanctum`) |

### 3.6 Events / Listeners / Jobs / Notifications

| Search | Result |
|---|---|
| `BusTicket` in `app/Events/` | **0 hits** |
| `BusTicket` in `app/Listeners/` | **0 hits** |
| `BusTicket` in `app/Jobs/` | **directory does not exist** |
| `BusTicket` in `app/Notifications/` | **0 hits** |

### 3.7 Tests

| Search | Result |
|---|---|
| `BusTicket` in `tests/` | **0 hits** |
| PHPUnit tests for BusTicket | **0** |
| Feature tests for BusTicket | **0** |
| E2E tests for BusTicket | **0** |

The module was added without any test coverage.

### 3.8 Other Bus Entities

| Related entity | Has `bus_ticket_id` FK? | Has any reference to BusTicket model? |
|---|---|---|
| `BusBooking` | No | No |
| `BusInventory` | No | No |
| `BusCompany` | No | No |
| `BusPayment` | No | No |
| `BusRefundRequest` | No | No |
| `BusCompanyPayment` | No | No |
| `BusGovernorate` | No | No |
| `Customer` | No | No |

**BusTicket is fully isolated from the rest of the Bus module and from the finance/customer modules.**

### 3.9 Other App References

| File | Reference | Type |
|---|---|---|
| `app/Services/Finance/AccountService.php:320` | `// those belong to flight_bookings and bus_tickets respectively.` | **Comment only** — `BusTicket` is NOT in the morphTo search list (line 308-312 listing is FlightBooking, BusBooking, OnlineTransaction, VisaBooking, HajjUmraBooking) |

**BusTicket is NOT part of the global account statement search.**

---

## 4. Existing Documentation That Already Calls It Orphan

The codebase already contains **multiple independent references** that document BusTicket as legacy/orphan:

| Source | Statement |
|---|---|
| `BusTicketResource.php:28` | `/** موديل قديم — إدارة الباص الحالية من موارد BusCompany / BusInventory / BusBooking. */` — "Legacy model — current bus management uses BusCompany / BusInventory / BusBooking resources" |
| `BusTicketResource.php:29` | `protected static bool $shouldRegisterNavigation = false;` — Hidden from Filament nav |
| `BusTicketResource.php:35-37` | All labels: `'تذاكر الباص (قديم)'` / `'تذكرة باص (قديم)'` — "Bus Tickets (Legacy)" |
| `BusTicketResource.php:200-205` | Comment: "Even though this resource is hidden from navigation (`shouldRegisterNavigation=false`), the deletion entry point is still wired through the service to keep the same ModelDeletionGuard contract as the rest of the Bus module." |
| `BusTicket.php:91-93` | `deleting` observer: "Everything else (Filament `DeleteAction`, raw tinker, accidental API calls, etc.) is blocked to prevent accidental loss of legacy ticket records..." |
| `scripts/bus_audit_soft_delete_run.php:547` | `'BusTicket' => 'Orphan module — has Filament resource but no Service/Route/Vue.'` |
| `scripts/bus_audit_phase_q_coverage.php:37,65` | Lists it as a discovered model but immediately notes `NOT_TESTABLE` for SD1-SD14 |

The original codebase authors and the audit team have already classified BusTicket as legacy/orphan.

---

## 5. Production Data State

```sql
SELECT COUNT(*) FROM bus_tickets;
-- Result: 0

SELECT COUNT(*) FROM bus_tickets WHERE deleted_at IS NOT NULL;
-- Result: 0 (no soft-deleted records either)
```

**The `bus_tickets` table exists in production MySQL but is completely empty.** No risk of data loss from removal.

---

## 6. What Functionality Depends on BusTicket?

**None.** Removing BusTicket will not affect:

- ❌ Booking flow (uses `BusBooking`)
- ❌ Inventory management (uses `BusInventory`)
- ❌ Company management (uses `BusCompany`)
- ❌ Payment flow (uses `BusPayment`)
- ❌ Refund flow (uses `BusRefundRequest`)
- ❌ Debt-payment (uses `BusCompanyPayment`)
- ❌ Treasury / account statements
- ❌ Global search (BusTicket is NOT in the morphTo search list)
- ❌ Filament navigation (already hidden)
- ❌ Vue pages (no references exist)
- ❌ Any external test / script / migration

The only "dependents" are:
- 8 internal module files (can be deleted together)
- 1 audit script (`scripts/bus_audit_soft_delete_run.php`) that just observes it as orphan
- 1 migration (`2026_05_24_232618_add_performance_indexes.php`) that adds indexes to `bus_tickets` — can be left or removed

---

## 7. Risks & Concerns

### 7.1 Low Risk

| Risk | Mitigation |
|---|---|
| DB migration is still in the migration list | Migration remains — only the route registration / model / service are dead |
| `ModelDeletionGuard` + `ModelProfitMutationGuard` traits are loaded | Once the model is removed, the traits have no consumers — fine |
| `BusPaymentMethod` enum has `cash_wallet`, `office_safe`, `office_drawer` that may have been considered for BusTicket | Not used elsewhere — not affected |
| The `BusTicketMigration` adds a `status` enum in the Filament resource that **does NOT exist in the DB schema** | Pre-existing bug — would be fixed by removal |

### 7.2 Pre-existing latent bug (irrelevant to orphan status)

The Filament `BusTicketResource` has a `status` column with `'pending'`, `'confirmed'`, `'cancelled'`, `'completed'` filters and badges, but the `bus_tickets` table **does NOT have a `status` column**. This means loading the Filament admin page would throw a SQL error if attempted. **Not investigated further** because the page is hidden from navigation and the table is empty.

### 7.3 No Financial Implication

| Concern | Reality |
|---|---|
| Soft-delete transactions | None — `bus_tickets` has no foreign key to `transactions` or `account_entries` |
| Account balance impact | None — BusTicket has no financial wiring |
| Idempotency guards | None — beyond the legacy `ModelDeletionGuard` |

---

## 8. Recommended Action

| Option | Description | Recommendation |
|---|---|---|
| **KEEP** | Leave BusTicket as-is | ❌ Not recommended — pure dead code |
| **REMOVE** | Delete the 8 module files + the `bus_tickets` migration in a planned sweep | ✅ **Recommended** (after product sign-off) |
| **NEEDS PRODUCT DECISION** | Ask product: "Is the legacy customer/agent ticket-booking flow needed for historical reporting?" | ✅ **THIS IS THE CORRECT FIRST STEP** |

### 🟡 Recommended Action: **NEEDS PRODUCT DECISION**

Before code removal, the user/product owner should confirm:

1. **Is `bus_tickets` used as a legacy receipt/journal for old customer ticket records?** (No current rows, but the `ModelDeletionGuard` comment suggests there may have been important historical records.)
2. **Should the `bus_tickets` table remain as a read-only archive for compliance/audit purposes?** (In which case, keep the migration + simplify the model + remove the controller/service/filament.)
3. **Should the module be completely removed?**

If the answer is "completely remove" — proceed with the cleanup plan in §9.

---

## 9. Proposed Cleanup Plan (NOT EXECUTED — pending sign-off)

| Step | Action | Files Affected |
|---|---|---|
| 1 | Delete model | `app/Models/BusTicket.php` |
| 2 | Delete service | `app/Services/BusTicketService.php` |
| 3 | Delete API controller | `app/Http/Controllers/Api/BusTicketController.php` |
| 4 | Delete API requests | `app/Http/Requests/Bus/StoreBusTicketRequest.php`, `UpdateBusTicketRequest.php` |
| 5 | Delete API resource | `app/Http/Resources/BusTicketResource.php` |
| 6 | Delete Filament resource + page | `app/Filament/Admin/Resources/BusTickets/` (entire directory) |
| 7 | Delete the performance index migration **OR** just remove the `bus_tickets` line from it | `database/migrations/2026_05_24_232618_add_performance_indexes.php` |
| 8 | Add a new migration that drops `bus_tickets` | `database/migrations/YYYY_MM_DD_HHMMSS_drop_bus_tickets_table.php` |
| 9 | Update affected audit scripts | `scripts/bus_audit_soft_delete_run.php`, `scripts/bus_audit_phase_q_coverage.php` (remove BusTicket from model lists) |
| 10 | Run `php artisan migrate` to apply the drop migration | (in production after a backup) |

**Estimated impact:** ~9 file deletions + 1 migration + 2 script edits. No code that depends on BusTicket is affected.

---

## 10. Evidence Summary

| Evidence | Source | Conclusion |
|---|---|---|
| `bus_tickets` has 0 rows in production MySQL | `SELECT COUNT(*) FROM bus_tickets` | No data to preserve |
| `BusTicketController` not in any routes file | `grep -rn "BusTicket" routes/` → 0 results | Unreachable API |
| Only Filament `/admin/bus-tickets` route exists | `php artisan route:list` | Only admin can reach — and nav is hidden |
| No Vue/frontend references | `grep -rn "BusTicket\|bus-tickets\|bus_tickets" resources/js/` → 0 results | No UI |
| No tests | `grep -rn "BusTicket" tests/` → 0 results | No coverage |
| No policies / events / listeners / jobs / notifications | grep against each directory → 0 results each | No wiring |
| No foreign keys from other tables | `grep -rn "bus_ticket_id" database/migrations/` → 0 results | No dependents |
| No seeders / factories | `grep -rn "BusTicket" database/seeders/ database/factories/` → 0 results | No fixtures |
| Filament resource explicitly documents as legacy | `BusTicketResource.php:28, 35-37` | Author-confirmed legacy |
| Audit scripts document it as orphan | `scripts/bus_audit_soft_delete_run.php:547` | Audit-confirmed orphan |
| `BusTicket` not in AccountService search | `AccountService.php:308-312` (morphTo list) | Not in global search |
| `BusTicket` has no relationship to other Bus entities | `BusTicket.php` (only `employee()` belongsTo) | Fully isolated |

---

## 11. Final Verdict

**🟡 NEEDS PRODUCT DECISION** — then proceed to **REMOVE** in a planned sweep.

**Confidence:** HIGH
**Risk:** LOW (0 rows, no dependents, no UI, no tests, no FKs)
**Cost of removal:** ~9 files + 1 migration
**Cost of keeping:** ongoing maintenance burden, surface area for future bugs, confusion for new developers

The investigation is complete. **No code changes were made.** Awaiting product decision before executing the plan in §9.
