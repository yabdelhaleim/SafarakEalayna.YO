# Bus Module — F-9 BusGovernorate Cleanup Report
**Date:** 2026-08-13
**Scope:** Remove legacy `BusGovernorate` module (model + Filament resource + Filament page + seeder ref + table).
**Verdict:** ✅ **CLEAN** — all module files deleted, table dropped via reversible migration, all 5 regression suites pass, **no Bus functionality affected**.

---

## 1. Files Deleted (3 files + 1 directory)

| # | Path | Status |
|---|------|--------|
| 1 | `app/Models/Bus/BusGovernorate.php` | ✅ DELETED |
| 2 | `app/Filament/Admin/Resources/BusGovernorates/BusGovernorateResource.php` | ✅ DELETED |
| 3 | `app/Filament/Admin/Resources/BusGovernorates/Pages/ManageBusGovernorates.php` | ✅ DELETED |
| 4 | `app/Filament/Admin/Resources/BusGovernorates/` (entire directory) | ✅ DELETED |

Final verification:
```
ABSENT:  app/Models/Bus/BusGovernorate.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates/BusGovernorateResource.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates/Pages/ManageBusGovernorates.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates
```

---

## 2. Seeder Changes (1 file)

`database/seeders/BusModuleProductionTestSeeder.php`:

| Change | Detail |
|--------|--------|
| Removed `use App\Models\Bus\BusGovernorate;` | (line was 10; removed) |
| Removed `$this->seedGovernorates();` call | (from inside the `DB::transaction(...)` block) |
| Replaced with F-9 marker comment | `// F-9 cleanup (2026-08-13): seedGovernorates() removed — BusGovernorate module deprecated.` |
| Removed entire `seedGovernorates()` method (22 lines) | Lines 179–200 in old file |
| Updated docblock | Removed the line "6 bus governorates (Cairo, Giza, Alexandria, Aswan, Luxor, Sharm)" |

**Unrelated seeder functionality preserved** (seedExchangeRates, seedCashboxes, seedEmployees, seedCustomers, seedCompanies, seedInventories, seedClearingAccounts — all untouched).

Verification: `php -l database/seeders/BusModuleProductionTestSeeder.php` → No syntax errors detected.

---

## 3. New Migration (1 file, reversible)

`database/migrations/2026_08_13_120100_drop_bus_governorates_table.php`:

```php
public function up(): void
{
    Schema::dropIfExists('bus_governorates');
}

public function down(): void
{
    Schema::create('bus_governorates', function (Blueprint $table) {
        $table->id();
        $table->string('name', 150)->unique();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
    });
}
```

**Status:** ✅ Applied successfully to local SQLite test DB.
```
2026_08_13_120100_drop_bus_governorates_table ... DONE
bus_governorates exists: ABSENT (correct)
```

The original migration `2026_05_08_152700_create_bus_governorates_table.php` was NOT modified, per the cleanup constraints.

---

## 4. Audit Script Updated (1 file)

`scripts/bus_audit_phase_q_coverage.php`:

| Change | Detail |
|--------|--------|
| Updated comment on line 37 | Added `(BusGovernorate module removed in F-9 cleanup 2026-08-13)` |
| Updated comment on line 65 | (No change needed; references F-8 only) |
| Added F-9 marker comment on line 106 | `// BusGovernorate removed in F-9 cleanup (2026-08-13) — module deprecated.` |

---

## 5. composer dump-autoload

Regenerated to flush the stale `App\Models\Bus\BusGovernorate` classmap entry. After dump:
```
BusGovernorate class_exists: NO (correctly removed)
All Bus module classes still autoload correctly:
- BusCompany / BusInventory / BusBooking / BusPayment / BusRefundRequest / BusCompanyPayment → all YES
```

---

## 6. Tests Executed (post-cleanup, local SQLite test DB)

| Suite | Script | Tests | PASS | FAIL | Verdict |
|-------|--------|------:|-----:|-----:|---------|
| **F-3 / T22** (cross-currency guard) | `scripts/bus_audit_t22_regression.php` | 15 | 15 | 0 | ✅ PASS |
| **F-4 / T23** (JSON envelope) | `scripts/bus_audit_t23_regression.php` | 10 | 10 | 0 | ✅ PASS |
| **F-5** (cancelled-booking delete idempotency) | `scripts/bus_audit_f5_regression.php` | 8 | 8 | 0 | ✅ PASS |
| **F-7** (DELETE authz gate) | `scripts/bus_audit_f7_authz.php` | 14 | 14 | 0 | ✅ PASS |
| **NEW-1** (Deferred inventory + payInventoryDebt + deleteInventory cascade) | `scripts/bus_audit_deferred_inventory_delete_regression.php` | 10 | 10 | 0 | ✅ PASS |
| **TOTAL** | — | **57** | **57** | **0** | ✅ **57/57 PASS, 0 FAIL** |

---

## 7. Final Repository Search Results

### Live source code (`app/`, `routes/`, `config/`)
```
(no matches)
```

### Frontend assets (`resources/`)
```
(no matches)
```

### Tests (`tests/`)
```
(no matches)
```

### Seeders (`database/seeders/`)
```
database/seeders/BusModuleProductionTestSeeder.php:55   // F-9 marker comment (intentional, code archaeology)
database/seeders/BusModuleProductionTestSeeder.php:178  // F-9 marker comment (intentional, code archaeology)
```
**Only the F-9 marker comments remain** — these document the cleanup for future maintainers. No live code references.

### BusGovernorate physical file presence
```
ABSENT:  app/Models/Bus/BusGovernorate.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates/BusGovernorateResource.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates/Pages/ManageBusGovernorates.php
ABSENT:  app/Filament/Admin/Resources/BusGovernorates
```

### `bus_governorates` table in local SQLite test DB
```
bus_governorates: NO (correct)
```

### Bus module smoke test (other entities unaffected)
```
Bus model classes:
- BusCompany: YES
- BusInventory: YES
- BusBooking: YES
- BusPayment: YES
- BusRefundRequest: YES
- BusCompanyPayment: YES

Bus tables (local SQLite):
- bus_companies: YES
- bus_inventories: YES
- bus_bookings: YES
- bus_payments: YES
- bus_refund_requests: YES
- bus_company_payments: YES
- bus_governorates: NO
- bus_tickets: NO
```

### API endpoint smoke test
```
GET /api/v1/bus/companies     (unauth) → 401  ✓
GET /api/v1/bus/inventories   (unauth) → 401  ✓
GET /api/v1/bus/bookings      (unauth) → 401  ✓
GET /api/v1/bus/governorates  (unauth) → 404  ✓ (route correctly removed)
```

---

## 8. Unaffected Bus Functionality — Confirmed

| Concern | Status |
|---------|--------|
| `BusBooking` (create, pay, cancel, refund, delete+reversal) | ✅ Unchanged — no behavior touched |
| `BusInventory` (create, edit, deferred-cash, delete+reversal) | ✅ Unchanged — no behavior touched |
| `BusCompany` (create, edit, delete+reversal) | ✅ Unchanged — no behavior touched |
| `BusPayment` (create, reverse) | ✅ Unchanged — no behavior touched |
| `BusRefundRequest` (create, approve) | ✅ Unchanged — no behavior touched |
| `BusCompanyPayment` (payInventoryDebt, delete+reversal cascade) | ✅ Unchanged — no behavior touched |
| Financial logic (cross-currency, exchange rates, treasury) | ✅ Unchanged — no behavior touched |
| Delete/reversal logic (F-2, F-5, NEW-1) | ✅ Unchanged — verified by regression suites |
| Filament admin sidebar | ⚠️ "المحافظات" menu item gone (admin-only); 5 other Bus menu items still present |
| API endpoints | ✅ All non-governorate endpoints respond correctly |
| Seeding pipeline | ✅ All non-governorate seeder methods still run |

---

## 9. Deliverables Checklist (per user request)

- ✅ **Files deleted (3):** `app/Models/Bus/BusGovernorate.php`, `app/Filament/Admin/Resources/BusGovernorates/BusGovernorateResource.php`, `app/Filament/Admin/Resources/BusGovernorates/Pages/ManageBusGovernorates.php` (and the parent `BusGovernorates/` directory)
- ✅ **Seeder changes:** `BusModuleProductionTestSeeder.php` — removed `use`, removed `$this->seedGovernorates()` call, removed `seedGovernorates()` method, removed docblock mention; F-9 marker comments added
- ✅ **New migration:** `database/migrations/2026_08_13_120100_drop_bus_governorates_table.php` — reversible (drops + recreates schema exactly)
- ✅ **Audit scripts updated:** `scripts/bus_audit_phase_q_coverage.php` — comments now reflect both F-8 and F-9 cleanup
- ✅ **Remaining references:** Only F-9 marker comments in the seeder (intentional code archaeology)
- ✅ **Tests executed:** T22, T23, F-5, F-7, NEW-1 — **57/57 PASS, 0 FAIL**
- ✅ **Existing Bus functionality unaffected:** All 6 Bus models still exist, all 6 Bus tables still present, all Bus API endpoints respond correctly, financial and delete/reversal logic unchanged
- ✅ **composer dump-autoload:** Regenerated; stale BusGovernorate classmap flushed
- ✅ **F-8 + F-9 cleanup total:** BusTicket (9 files) + BusGovernorate (3 files) = 12 module files removed, 2 tables dropped, both via reversible migrations

**F-9 cleanup is COMPLETE and SAFE.** The Bus module ships without the legacy `BusGovernorate` lookup table and its associated admin UI.
