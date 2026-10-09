# Bus Module — Audit Inventory (Phase 1-3 Discovery Output)

> **تاريخ:** 2026-08-13  
> **الغرض:** output الـ Discovery Phase من الـ 35-phase audit (read-only — لا اختبارات هنا)  
> **يعتمد على:** explore agents + reads على الـ codebase الفعلي  
> **استخدم:** كـ baseline للـ matrix + كـ setup للـ final report

---

## 1. الـ High-Level Summary

| Aspect | Count | Note |
|---|---|---|
| Models in `app/Models/Bus/*` | 7 | Booking, Inventory, Company, Payment, RefundRequest, CompanyPayment, Governorate, Ticket |
| Services in `app/Services/Bus/*` | 4 | Booking, Company, Inventory, Refund |
| Filament Resources | 8 | BusCompanies, BusInventories, BusBookings, BusCompanyPayments, BusGovernorates, BusTickets, BusBanks, BusWallets |
| Filament Pages | 1 | BusCompanyDebtStatement |
| API endpoints | 31 (Bus prefix) | See routes/api.php lines 266–304 |
| Vue pages | 9 | BusDashboard, BusIndex, BusCreate, BusShow, BusInventoryIndex, BusCompanyIndex, BusCompanyStatement, BusTreasury, BusCustomerIndex |
| Vue components | 1 Bus-specific | BusRefundWizard (embedded in BusShow) |
| Enums | 4 | BusBookingStatus, BusPaymentStatus, BusInventoryPaymentType, BusCompanyPaymentStatus |
| PHPUnit tests | 167 assertions | across `tests/Feature/Bus/*` + Tourism Division test |
| Existing e2e scenarios | 23 | in `scripts/bus_module_full_e2e.php` |

---

## 2. الـ Models الـ Soft-Deletable

| Model | File | Migration | SoftDeletes | Bouncers/Gates |
|---|---|---|---|---|
| `BusBooking` | `app/Models/Bus/BusBooking.php:45` | `2026_04_27_230404_create_bus_bookings_table.php` | ✅ | `ModelDeletionGuard` + `deleting` observer at line 79–89 |
| `BusInventory` | `app/Models/Bus/BusInventory.php:43` | `2026_04_27_230403_create_bus_inventories_table.php` | ✅ | `ModelDeletionGuard` + observer at 84–109 |
| `BusCompany` | `app/Models/Bus/BusCompany.php:26` | `2026_04_27_230344_create_bus_companies_table.php` | ✅ | `ModelDeletionGuard` + observer at 50–67 |
| `BusPayment` | `app/Models/Bus/BusPayment.php` | `2026_05_02_030000_*` + `2026_07_11_140000_*` | ✅ (added 2026-07-11) | none |
| `BusRefundRequest` | `app/Models/Bus/BusRefundRequest.php` | `2026_05_14_230032_*` + `2026_07_11_140000_*` | ✅ | none |
| `BusCompanyPayment` | `app/Models/Bus/BusCompanyPayment.php` | `2026_04_27_230404_*` + `2026_07_11_140000_*` | ✅ | none |
| `BusTicket` | `app/Models/Bus/BusTicket.php` | `2026_04_27_160500_*` | ✅ (orphan module) | none |

**Cleanup of related models:**
- `Account` — NO SoftDeletes (ledger is immutable)
- `Transaction` — NO SoftDeletes
- `Customer` — see below

---

## 3. الـ Services الـ Public API (financial)

### `BusBookingService` (`app/Services/Bus/BusBookingService.php`, 1337 lines)

| Method | Line | Purpose |
|---|---|---|
| `getAllBookings(array)` | 44 | Paginated list w/ filters |
| `getBookingStats(): array` | 136 | Multi-currency aware stats |
| `createBooking(array): BusBooking` | 205 | Mode A/B (auto-inventory vs explicit) |
| `payBooking(BusBooking, array): BusBooking` | 455 | **NO currency guard** (Finding #2) |
| `cancelBooking(BusBooking, array): BusRefundRequest` | 604 | Additive reversal + refund |
| `deleteBooking(BusBooking): bool` | 957 | Refuses if payments exist |
| `deleteBookingWithReversal(int, ?int): bool` | 1062 | Full additive reversal (canonical admin path) |
| `getBookingById(int): BusBooking` | 918 | |
| `ensureCustomerAccount(int, ?string): Account` | 1188 | Per-currency AR |
| `createCustomerCurrencyAccount(...)` | 1231 | |
| `recordSaleToCustomer(...)` | 1275 | Cross-currency Transfer |

### `BusCompanyService` (`app/Services/Bus/BusCompanyService.php`, 260 lines)
- `createCompany()`, `updateCompany()`, `deleteCompany()` — auto-creates ledger account
- `getCompanyById()`, `payDebt()`, `ensureCompanyAccount()`

### `BusInventoryService` (`app/Services/Bus/BusInventoryService.php`, 422 lines)
- `createInventory()` — Cash posts expense; Deferred sets debt
- `payInventoryDebt()`, `getInventoryById()`, `deleteInventory()` — reverses cash expense

### `BusRefundService` (`app/Services/Bus/BusRefundService.php`, 213 lines)
- `createRefundRequest()`, `processRefundRequest()` — supplier reversal + treasury credit

---

## 4. الـ API Endpoints

From `routes/api.php` (lines 266–304, all under `prefix('bus')`):

| Method | URL | Middleware | Notes |
|---|---|---|---|
| GET | `bus/inventories/available` | auth | line 267 |
| GET | `bus/bookings/stats` | auth | line 268 |
| GET | `bus/dashboard` | auth | line 269 |
| GET | `bus/treasury/overview` | auth | line 272 |
| GET | `bus/treasury/accounts/{account}/bus-transactions` | auth | line 273 |
| GET | `bus/customers` | auth | line 276 |
| GET | `bus/companies/{company}/statement` | auth | line 279 |
| POST | `bus/companies/{company}/pay-debt` | **admin** | line 281 |
| POST | `bus/inventories/{busInventory}/pay-debt` | **admin** | line 282 |
| POST/PATCH | `bus/bookings/{busBooking}/cancel` | **admin** | line 283 |
| Resource | `bus/companies` | auth | line 285 (DELETE not gated by admin!) |
| Resource | `bus/inventories` | auth | line 286–287 (DELETE not gated by admin!) |
| Resource | `bus/bookings` (except `update`) | auth | line 288–291 (DELETE not gated by admin!) |
| POST | `bus/bookings/{busBooking}/pay` | auth | line 293 |
| GET | `bus/refunds/treasuries` | auth | line 297 |
| GET | `bus/refunds/{id}` | auth | line 298 |
| POST | `bus/refunds/` | **admin** | line 300 |
| POST | `bus/refunds/{id}/process` | **admin** | line 301 |

> **🚨 finding:** DELETE endpoints (Resource routes) are NOT protected by `admin` middleware — only `auth:sanctum`. Authorization relies on `CheckPermission` middleware running inside controllers.

---

## 5. الـ Authorization Map

### Legacy Role Map (`app/Http/Middleware/CheckPermission.php`)

| Role | Permission granted |
|---|---|
| `admin` | `buses.*` (full wildcard) |
| `manager` | `buses.view`, `buses.create`, `buses.edit` |
| `employee` | `buses.view`, `buses.create` |

**NOT in legacy map:** `buses.delete`, `buses.cancel`, `buses.refund`, `buses.restore`, `buses.force_delete`.

### Policies (`app/Policies/`)
- NO Bus-specific policy classes (no `BusBookingPolicy`, no `BusCompanyPolicy`, etc.)
- Authorization relies solely on `admin` middleware + legacy role map via `CheckPermission` middleware

---

## 6. الـ Filament Resources (Surfaces الـ user-facing)

| Resource | Pages | Delete via UI | Restore via UI |
|---|---|---|---|
| `BusCompanies\BusCompanyResource` | List/Create/Edit + RelationManager(Inventory) | ✅ Custom `Action::make('deleteCompany')` via service | ❌ TrashedFilter only |
| `BusInventories\BusInventoryResource` | ManageBusInventories | ❌ Only edit + payDebt | ❌ |
| `BusBookings\BusBookingResource` | ManageBusBookings | ❌ Only edit | ❌ |
| `BusCompanyPayments\BusCompanyPaymentResource` | ManageBusCompanyPayments | ✅ | ❌ |
| `BusGovernorates\BusGovernorateResource` | ManageBusGovernorates | ✅ | ❌ |
| `BusTickets\BusTicketResource` | ManageBusTickets | ✅ | ❌ |
| `BusBanks\BusBankResource` | List/Create/Edit | ❌ | ❌ |
| `BusWallets\BusWalletResource` | List/Create/Edit | ❌ | ❌ |

**Orphan module: `BusTicket`** — table + migration + Filament resource exist but no service, no API route, no Vue page.

---

## 7. الـ Vue Frontend Inventory

| Page | Route | Critical actions via UI |
|---|---|---|
| `BusDashboard.vue` | `bus.dashboard` | View KPIs (auto-refresh 15s) |
| `BusIndex.vue` | `bus.list` | List, pay (modal), cancel (modal), delete (modal), pagination |
| `BusCreate.vue` | `bus.create` | 4-step wizard: Company+Route → Customer → Tickets+Payment → Review |
| `BusShow.vue` | `bus.show` | Detail, pay, delete, cancel, refund (BusRefundWizard), print |
| `BusInventoryIndex.vue` | `bus.inventory` | CRUD; delete modal |
| `BusCompanyIndex.vue` | `bus.companies` | List + payDebt modal |
| `BusCompanyStatement.vue` | `bus.companies.statement` | Ledger view, payment modal |
| `BusTreasury.vue` | `bus.treasury` | Cross-module account view |
| `BusCustomerIndex.vue` | `bus.customers` | Debt-only filter, companies tab |

### busStore.js (Pinia)
- 687 lines
- Actions: `fetchBookings`/`createBooking`/`payBooking`/`cancelBooking`/`deleteBooking`/etc.
- NO `restoreBooking` / `forceDeleteBooking` / `restoreInventory` / `forceDeleteInventory` actions
- NO delete action for `BusPayment`, `BusRefundRequest`, `BusCompanyPayment`

---

## 8. الـ Existing Tests Coverage

| Type | File | Count |
|---|---|---|
| Backend service tests | `tests/Feature/Bus/BusBookingServiceTest.php` | 5 |
| API tests | `tests/Feature/Bus/BusApiTest.php` | 8 |
| PHPUnit API CRUD | `tests/Feature/BusApiCrudTest.php` | 3 |
| Booking flow tests | `tests/Feature/Bus/BusBookingFlowTest.php` | 8 |
| Payment type tests | `tests/Feature/Bus/BusBookingPaymentTypeTest.php` | (count TBD) |
| Deadlock retry tests | `tests/Feature/Bus/BusDeadlockRetryTest.php` | 7 |
| Concurrency tests | `tests/Feature/Bus/BusDeepConcurrencyTest.php` | 8 |
| Multi-currency delete tests | `tests/Feature/Bus/BusDeletionMultiCurrencyTest.php` | 5 |
| Infrastructure smoke | `tests/Feature/Bus/BusInfrastructureSmokeTest.php` | 12 |
| Payment method validation | `tests/Feature/Bus/BusPaymentMethodValidationTest.php` | 5 |
| Refund FX hardening | `tests/Feature/Bus/BusRefundServiceFxHardeningTest.php` | 3 |
| Filament CRUD | `tests/Feature/Filament/BusFilamentCrudTest.php` | 3 |
| Production-style tests | `tests/Feature/TourismDivision/BusProductionTest.php` | 7 |
| **Existing e2e** | `scripts/bus_module_full_e2e.php` | **23 scenarios / 57 assertions** |

---

## 9. الـ Gaps الـ Known

1. **No service-level cross-currency guard** in `BusBookingService::payBooking()` — only FormRequest checks (Finding #2, unfixed).
2. **JSON envelope drift** — `ApiResponse::success()` uses `success` key; CLAUDE.md says `status` (Finding #3, unfixed).
3. **No Treasury seed** in `UnifiedVaultsSeeder` → T11 refund happy-path was SKIPPED in 2026-08-12 audit.
4. **No restore / force-delete** anywhere user-facing.
5. **`BusTicket` and `BusGovernorate`** are orphan modules.
6. **No Bus-policy classes** — no per-resource authorization.
7. **Vue has no seat-map picker** — phase 9 (seat integrity) is NOT_TESTABLE by implementation, not by audit gap.
8. **Vue has no per-passenger record** — phase 10 (passenger) is partially NOT_TESTABLE.

---

## 10. الـ Authorization Hardening Recommendation

The DELETE routes on the API use **no `admin` middleware**. This means:
- A `manager` user could potentially DELETE a booking via API call
- A `manager` user could potentially DELETE an inventory via API call
- A `manager` user could potentially DELETE a company via API call

This is gated only by `auth:sanctum` + the legacy `CheckPermission` middleware (which is invoked at the route level or controller level — needs to be verified).

**Risk:** If `CheckPermission` is NOT invoked inside Bus controllers, a manager user could delete records they shouldn't.
