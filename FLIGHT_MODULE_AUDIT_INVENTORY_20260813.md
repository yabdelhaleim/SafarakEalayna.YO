# ✈️ Flight Module — Audit Inventory (Phase 0 Discovery)

> **تاريخ:** 2026-08-13
> **الغرض:** Discovery-only inventory for the Flight Module. Read-only. لا يوجد تنفيذ اختبارات هنا.
> **يعتمد على:** الفحص الفعلي للمشروع (`C:\travile\SafarakEalayna`).
> **يُستخدم كأساس لـ:** `FLIGHT_MODULE_SOFT_DELETE_MATRIX_20260813.md` و `FLIGHT_MODULE_FULL_E2E_AUDIT_20260813.md`.

---

## 1. High-Level Summary

| Layer | Count | Notes |
|---|---|---|
| **Models** | 18 | تحت `App\Models\Flight\` + 2 root-level (`App\Models\Flight`, `App\Models\FlightPricing`) |
| **Services (Flight-specific)** | 8 | تحت `App\Services\Flight\` |
| **Services (Shared Finance)** | 8 | تحت `App\Services\Finance/` + `App\Services\Treasury/` يستخدمها Flight |
| **Filament Resources (root)** | 9 | تحت `app/Filament/Resources/` — **ملاحظة: كثير منها مكسور / ناقص Pages** |
| **Filament Resources (AdminPanel)** | 7 | تحت `app/Filament/Admin/Resources/` — **هذا الـ Canonical Surface** |
| **Filament Pages** | 2 | `FlightDashboard`, `FlightSystemsBalancesPage` |
| **Filament Widgets (Flight)** | 4 | `FlightStatsWidget`, `RecentFlightBookingsWidget`, `ModificationStatsWidget` (×2) |
| **Filament Relation Managers** | 2 | `FlightSystemBookingsRelationManager`, `FlightSystemTransactionsRelationManager` |
| **API Routes (Flight)** | 97+ | تحت `v1/flight/*` |
| **Controllers (Flight-specific)** | 12 | تحت `app/Http/Controllers/Api/V1/Flight/` |
| **FormRequests (Flight)** | 6 | تحت `app/Http/Requests/Flight/` |
| **Vue Views** | 14 | تحت `resources/js/views/flights/` |
| **Vue Components** | 15 | تحت `resources/js/components/flights/` |
| **Pinia Stores** | 1 | `flightStore.js` |
| **Migrations** | 49 | Flight-related في `database/migrations/` |
| **Enums** | 6+ | `FlightBookingStatus`, `FlightSystemType`, `FlightPaymentMethod`, `PassengerType`, `TripType`, `BookingChannelType` |
| **Existing E2E Scripts** | 2 | `scripts/flight_module_full_e2e.php` (12 scenarios), `scripts/flight_module_e2e_audit.php` (older) |
| **PHPUnit Tests** | 0 | لا توجد Flight-specific PHPUnit tests |

---

## 2. الـ Models Inventory

### 2.1 Models with `SoftDeletes`

| # | Model | File | Lines | SoftDeletes | Balance Protected |
|---|---|---|---|---|---|
| 1 | `Flight` (legacy) | `app/Models/Flight.php` | 41 | ✅ | n/a |
| 2 | `FlightBooking` | `app/Models/Flight/FlightBooking.php` | 331 | ✅ | guards profit mutation |
| 3 | `FlightCarrier` | `app/Models/Flight/FlightCarrier.php` | 213 | ✅ | ✅ blocks direct balance update |
| 4 | `FlightGroup` | `app/Models/Flight/FlightGroup.php` | 111 | ✅ | n/a |
| 5 | `FlightSystem` | `app/Models/Flight/FlightSystem.php` | 190 | ✅ | ✅ blocks direct balance update |
| 6 | `AirlineCredit` | `app/Models/Flight/AirlineCredit.php` | 109 | ✅ | n/a |
| 7 | `FlightPayment` | `app/Models/Flight/FlightPayment.php` | 62 | ✅ | n/a |
| 8 | `RefundRequest` | `app/Models/Flight/RefundRequest.php` | 105 | ✅ | n/a |
| 9 | `TicketModification` | `app/Models/Flight/TicketModification.php` | 111 | ✅ | n/a |

### 2.2 Models WITHOUT `SoftDeletes`

| # | Model | File | Lines | Notes |
|---|---|---|---|---|
| 10 | `FlightPricing` (legacy single-row) | `app/Models/FlightPricing.php` | 47 | legacy EGP-only pricing row |
| 11 | `AirlineAccount` | `app/Models/Flight/AirlineAccount.php` | 171 | comment: "legacy schema — hard delete only" |
| 12 | `AirlineTransaction` | `app/Models/Flight/AirlineTransaction.php` | 72 | ledger — explicitly NO soft delete (correct) |
| 13 | `FlightPassenger` | `app/Models/Flight/FlightPassenger.php` | 57 | table = `passengers` |
| 14 | `FlightRefund` | `app/Models/Flight/FlightRefund.php` | 56 | ledger-side |
| 15 | `FlightSegment` | `app/Models/Flight/FlightSegment.php` | 76 | booking child |
| 16 | `FlightSystemTransaction` | `app/Models/Flight/FlightSystemTransaction.php` | 45 | ledger — NO soft delete (correct) |
| 17 | `FlightGroupTransaction` | `app/Models/Flight/FlightGroupTransaction.php` | 41 | ledger |
| 18 | `FlightTicket` | `app/Models/Flight/FlightTicket.php` | 26 | minimal — no SoftDeletes, no casts |

### 2.3 `FlightBooking` Fields — Multi-Currency Coverage

`FlightBooking` هو الـ entity الأكثر تعقيدًا. الـ fields المتعلقة بـ Multi-Currency:

| Field | Type | Purpose |
|---|---|---|
| `currency` | string(3) | Booking currency (EGP, USD, KWD, SAR, EUR, AED) |
| `foreign_currency` | string(3) nullable | Original foreign currency if EGP is settlement |
| `original_currency` | string(3) nullable | Original booking currency (post 2026_05_12 migration) |
| `original_amount` | decimal(15,2) nullable | Original amount before refund |
| `purchase_price` | decimal(15,2) | In `currency` |
| `purchase_price_foreign` | decimal(15,2) nullable | In foreign currency (carrier-side) |
| `purchase_price_egp` | decimal(15,2) | In EGP for ledger |
| `selling_price` | decimal(15,2) | In `currency` |
| `selling_price_foreign` | decimal(15,2) nullable | In foreign currency (added 2026_07_29) |
| `profit` | decimal(15,2) | In `currency` (auto-computed, guarded by `ModelProfitMutationGuard`) |
| `exchange_rate` | decimal(15,4) | Snapshot at booking time |
| `exchange_rate_used` | decimal(15,6) | Higher precision (settlement snapshot) |
| `booking_exchange_rate` | decimal(15,6) nullable | Pre-refund snapshot |
| `currency_used` | string(10) | Settlement snapshot (added 2026_05_05) |
| `balance_currency_used` | string(10) | Currency of carrier/system balance used |
| `base_currency_amount` | decimal(15,2) | EGP equivalent for reporting |
| `purchase_balance_source` | string(20) | `carrier` / `system` / `both` |

### 2.4 `FlightBooking` Accessors / Scopes

- `getPaidAmountAttribute(): float` — sum of `FlightPayment.amount` (only non-soft-deleted)
- `getRemainingAmountAttribute(): float` — `selling_price - paid_amount`
- `computePaymentStatus(): string` — returns `paid` / `partial` / `unpaid`
- Scopes: `scopeByStatus`, `scopeByCustomer`, `scopeByEmployee`, `scopeBySystemType`, `scopeByDateRange`, `scopeByDepartureDateRange`, `scopeByRoute`

### 2.5 `FlightBooking` Boot Observers

- `static::deleting` → blocks raw delete unless via `ModelDeletionGuard` or unit tests
- `static::saving` → blocks direct `profit` mutation unless via `LedgerBalanceMutationGuard` / `isProfitMutationAllowed` / tests
- `static::saving` → auto-nullifies `original_currency`/`original_amount` when they equal `currency` (audit fix from 2026_07_21)

---

## 3. الـ Services Inventory (Flight-Specific)

| # | Service | File | Lines | Purpose |
|---|---|---|---|---|
| 1 | `AirlineAccountDebitService` | `app/Services/Flight/AirlineAccountDebitService.php` | 287 | Phase 1v2: debit/credit AirlineAccount for modifications |
| 2 | `AviationService` | `app/Services/Flight/AviationService.php` | 406 | Legacy surface: BL-04/06/op-01..op-07 |
| 3 | `FlightBookingService` | `app/Services/Flight/FlightBookingService.php` | 3243 | **الـ Workhorse الرئيسي** — create / update / pay / cancel / delete-account-with-reversal |
| 4 | `FlightCarrierRechargeService` | `app/Services/Flight/FlightCarrierRechargeService.php` | 149 | Recharge carrier from account |
| 5 | `FlightGroupThresholdService` | `app/Services/Flight/FlightGroupThresholdService.php` | 223 | Balance threshold notifications |
| 6 | `FlightSystemRechargeService` | `app/Services/Flight/FlightSystemRechargeService.php` | 151 | Recharge flight system from account |
| 7 | `ModificationService` | `app/Services/Flight/ModificationService.php` | 327 | Ticket modification state machine |
| 8 | `RefundService` | `app/Services/Flight/RefundService.php` | 720 | Refund requests: create / process / reverse |

### 3.1 `FlightBookingService` — Public Methods Map

| Method | Line | DB Transaction | Lock | Purpose |
|---|---|---|---|---|
| `getAllBookings(array)` | 90 | – | – | Paginated list with eager loads |
| `createBooking(array)` | 213 | ✅ line 218 | – | Create booking with passengers + pricing + optional payment |
| `backfillMissingCustomerSaleLedgers(?int)` | 1202 | ✅ line 1218 | – | Backfill GL tx for legacy bookings |
| `updateBooking(FlightBooking, array)` | 1455 | ✅ line 1458 | – | Update booking fields |
| `updatePrices(FlightBooking, float, float)` | 1665 | – | – | Update purchase/selling prices |
| `confirmBooking(FlightBooking)` | 1722 | ✅ line 1729 | – | Confirm a pending booking |
| `addPayment(FlightBooking, array)` | 1772 | ✅ line 1779 | ✅ lockForUpdate line 1787 | Add payment to a booking |
| `cancelBooking(FlightBooking, array)` | 1994 | ✅ line 2001 | – | Cancel booking with refund |
| `getBookingById(int)` | 2529 | – | – | Find by ID |
| `deleteBookingWithReversal(int, int)` | 2559 | ✅ line 2567 | ✅ lockForUpdate line 2574 | Delete booking + reverse GL |

### 3.2 `FlightBookingService` — Protected/Private Helper Inventory

| Helper | Purpose |
|---|---|
| `generateBookingNumber()` | Creates FLT-… reference |
| `prepareFlightBookingPayload(array)` | Normalizes payload |
| `resolveCustomerOriginalCurrency(array, string)` | Determines original currency |
| `resolveCustomerOriginalAmount(array, string, float)` | Determines original amount |
| `shouldPreserveBookingFieldOnEmptyUpdate(string, mixed)` | PHP-empty-safe updater |
| `debitFlightCarrier(...)` | Decrements FlightCarrier.balance via service |
| `debitFlightSystem(...)` | Decrements FlightSystem.balance via service |
| `creditTreasuryAccount(...)` | Credits treasury ledger |
| `flightLedgerContraAccountId()` | Returns contra-account for flight ledger |
| `ensureFlightIncomeClearingAccount(int)` | Winged creation of GL clearing account |
| `reverseFlightBookingSaleLedger(...)` | Reverses customer sale GL |
| `createFlightTickets(FlightBooking)` | Creates FlightTicket rows |
| `generateTicketNumber(FlightBooking, ?int)` | FLT-TKT-… generation |
| `createPassengers(...)` | Inserts FlightPassenger rows |
| `createSegments(...)` | Inserts FlightSegment rows |
| `creditBackFlightCarrier(...)` | Reverses debitFlow |
| `creditBackFlightCarrierExact(...)` | Exact rollback path |
| `creditBackFlightSystem(...)` / `Exact` | Mirror pair |
| `refundTreasuryAccount(...)` | Mirror back to treasury |
| `reverseSinglePayment(...)` | Reverses one FlightPayment |
| `ensureCustomerAccount(int)` | Idempotent account creation |
| `recordSaleToCustomer(...)` | Posts customer sale GL |
| `recordPurchaseFromGroup(...)` | Posts group purchase GL |
| `reverseGroupPurchase(...)` | Reverses group purchase GL |
| `egpPerUnitOfCurrency(string)` | Fallback rates (USD 48.5, KWD 157.5, SAR 12.9, EUR 52.3, GBP 61.2) |
| `lockedEgpPerBalanceUnit(...)` | Locks live rate |
| `persistedSettlementSnapshot(...)` | Builds snapshot dict |
| `resolvePurchaseBalanceSource(array)` | Maps `carrier` / `system` / `both` |
| `lockForEntityDebit(...)` | Wraps entity `lockForUpdate` |
| `lockedRateFromBookingSnapshot(FlightBooking, string)` | Reads snapshot |
| `normalizeSegmentTimeValue(mixed)` | "HH:MM" / "HH:MM:SS" / null |
| `normalizeSegmentDateValue(mixed)` | Date validator |

### 3.3 Shared Finance Services used by Flight

| Service | Used by | Purpose |
|---|---|---|
| `TransactionService` | `AviationService`, `FlightBookingService`, `RefundService` | GL journal entries |
| `TreasuryService` | `AviationService`, `FlightBookingService` | Treasury ledger |
| `LedgerClearingAccounts` | `FlightBookingService`, `FlightCarrierRechargeService`, `FlightSystemRechargeService`, `RefundService` | GL clearing account setup |
| `PrepaidLedgerService` | `AirlineAccountDebitService`, `FlightBookingService`, `FlightCarrierRechargeService`, `FlightSystemRechargeService` | Prepaid ledger |
| `TreasuryAccountResolver` | `AviationService` | Resolve treasury account |
| `TreasuryLedgerMirror` | `AviationService`, `FlightBookingService` | Mirror to treasury |
| `LedgerEntryDescriptionResolver` | `FlightBookingService` | Human-readable descriptions |
| `LedgerBalanceMutationGuard` | Many | Wraps balance mutations |

---

## 4. الـ Filament Resources

### 4.1 الـ Root Panel (Legacy) — `app/Filament/Resources/`

| Resource | File | Status | Pages |
|---|---|---|---|
| `FlightBookingResource` | `Resources/Flight/FlightBookingResource.php` | ⚠️ **Broken** — `getPages()` references `Pages\ListFlightBookings` etc., but Page classes don't exist | ❌ |
| `AirlineCreditResource` | `Resources/Flight/AirlineCreditResource.php` | ✅ Working | ✅ List+Create+Edit |
| `RefundRequestResource` | `Resources/Flight/RefundRequestResource.php` | ✅ Working | ✅ List+Create+Edit |
| `TicketModificationResource` | `Resources/Flight/TicketModificationResource.php` | ✅ Working | ✅ List+Create+Edit (`HeaderWidgets` → `ModificationStatsWidget`) |
| `FlightCarrierResource` | `Resources/FlightCarrier/FlightCarrierResource.php` | ⚠️ **Broken** — Pages directory missing | ❌ |
| `FlightGroupResource` | `Resources/FlightGroup/FlightGroupResource.php` | ⚠️ **Broken** — Pages directory missing | ❌ |
| `FlightSystemResource` | `Resources/FlightSystem/FlightSystemResource.php` | ⚠️ **Broken** — Pages directory missing | ❌ |
| `Airport\AirportResource` | `Resources/Airport/AirportResource.php` | ⚠️ Pages exist in `Resources/AirportResource/Pages/` (different dir) | ✅ |
| `AirportResource` (v2) | `Resources/AirportResource.php` | ✅ | ✅ List+Create+Edit |

### 4.2 الـ AdminPanel (Canonical) — `app/Filament/Admin/Resources/`

| Resource | File | Pages | TrashedFilter | Restore | ForceDelete |
|---|---|---|---|---|---|
| `FlightBookings\FlightBookingResource` | `Admin/Resources/FlightBookings/FlightBookingResource.php` | L+C+E | ✅ L273 | – | only via `deleteWithReversal` (L317-345) |
| `FlightCarriers\FlightCarrierResource` | `Admin/Resources/FlightCarriers/FlightCarrierResource.php` | L+C+E | ✅ L242 | ✅ imported | ✅ imported |
| `FlightGroups\FlightGroupResource` | `Admin/Resources/FlightGroups/FlightGroupResource.php` | L+C+E | ✅ L278 | ✅ imported | ✅ imported |
| `FlightSystems\FlightSystemResource` | `Admin/Resources/FlightSystems/FlightSystemResource.php` | L+C+E+**View** | ✅ L251 | ✅ imported | ✅ imported |
| `FlightWallets\FlightWalletResource` | `Admin/Resources/FlightWallets/FlightWalletResource.php` | L+C+E | – | – | – |
| `TicketModifications\TicketModificationResource` | `Admin/Resources/TicketModifications/TicketModificationResource.php` | L+C+E | – | – | only via `reverseConfirmation` bulk |
| `Airports\AirportResource` | `Admin/Resources/Airports/AirportResource.php` | L+C+E | – | – | – |

### 4.3 Filament Pages (Custom)

| Page | File | Purpose |
|---|---|---|
| `FlightDashboard` | `app/Filament/Admin/Pages/FlightDashboard.php` | Header: `FlightStatsWidget`; Footer: `RecentFlightBookingsWidget` |
| `FlightSystemsBalancesPage` | `app/Filament/Admin/Pages/FlightSystemsBalancesPage.php` | Livewire with `rechargeFlightSystem` header action |

### 4.4 Filament Widgets (Flight)

| Widget | File | Used by |
|---|---|---|
| `FlightStatsWidget` | `Admin/Widgets/FlightStatsWidget.php` | `FlightDashboard` header |
| `RecentFlightBookingsWidget` | `Admin/Widgets/RecentFlightBookingsWidget.php` | `FlightDashboard` footer |
| `ModificationStatsWidget` (admin) | `Admin/Resources/TicketModifications/Widgets/ModificationStatsWidget.php` | `ListTicketModifications` |
| `ModificationStatsWidget` (root) | `Resources/Flight/TicketModificationResource/Widgets/ModificationStatsWidget.php` | Same (extends admin version) |

---

## 5. الـ API Routes (97+)

Under `v1/flight/*` prefix, all wrapped with `auth:sanctum` + `active` + `CaptureFinancialPostingContext` + `RejectBannedFinancialBypassMarkers`. Sub-groups:

### 5.1 Bookings & Aviation (12 routes)

| Method | URI | Name | Action |
|---|---|---|---|
| GET | `flight/bookings` | `flight_bookings.index` | `FlightController@index` |
| POST | `flight/bookings` | `flight_bookings.store` | `FlightController@store` |
| GET | `flight/bookings/{flightBooking}` | `flight_bookings.show` | `FlightController@show` |
| PUT/PATCH | `flight/bookings/{flightBooking}` | `flight_bookings.update` | `FlightController@update` |
| DELETE | `flight/bookings/{flightBooking}` | `flight_bookings.destroy` | `FlightController@destroy` |
| POST | `flight/bookings/{flightBooking}/prices` | – | `FlightController@updatePrices` |
| POST | `flight/bookings/{flightBooking}/confirm` | – | `FlightController@confirm` |
| POST | `flight/bookings/{flightBooking}/payments` | – | `FlightController@addPayment` |
| POST | `flight/bookings/{flightBooking}/send-ticket-email` | – | `FlightController@sendTicketEmail` |
| POST | `flight/bookings/{flightBooking}/cancel` | – | `FlightController@cancel` |
| GET | `flight/aviation` | – | `AviationController@index` |
| POST | `flight/aviation` | – | `AviationController@store` |
| GET | `flight/aviation/{idOrRef}` | – | `AviationController@show` |
| PUT | `flight/aviation/{id}` | – | `AviationController@update` |
| DELETE | `flight/aviation/{id}` | – | `AviationController@destroy` |
| GET | `flight/aviation/next-number` | – | `AviationController@nextNumber` (note: registered at root, not in prefix) |
| GET | `flight/system-types` | – | `FlightController@systemTypes` |
| GET | `flight/booking-form/employees` | – | `FlightController@employeesForBooking` |

### 5.2 Dashboard & Treasury (8 routes)

| Method | URI | Action |
|---|---|---|
| GET | `flight/dashboard` | `FlightDashboardController@index` |
| GET | `flight/treasury/overview` | `FlightTreasuryController@overview` |
| GET | `flight/treasury/systems/{system}/transactions` | `FlightTreasuryController@systemTransactions` |
| GET | `flight/treasury/carriers/{carrier}/transactions` | `FlightTreasuryController@carrierTransactions` |
| POST | `flight/treasury/systems/{system}/recharge` | `FlightTreasuryController@rechargeSystem` |
| GET | `flight/treasury/accounts/{account}/flight-transactions` | `FlightTreasuryController@accountFlightTransactions` |

### 5.3 Systems / Carriers / Groups (20 routes)

| Resource | Routes |
|---|---|
| Flight Systems | GET/POST/PUT/DELETE/PATCH all + show |
| Flight Carriers | GET/POST/PUT/DELETE + balance, recharge, groups |
| Flight Groups | GET/POST + threshold-summary, statement, pay-debt, notifications, getByCarrier |

### 5.4 Airports (6 routes)

| Method | URI | Action |
|---|---|---|
| GET | `flight/airports/` | `AirportController@index` |
| GET | `flight/airports/search` | `AirportController@search` |
| GET | `flight/airports/popular` | `AirportController@popular` (hard-coded: CAI/JED/RUH/KWI/DXB/DOH/IST/LHR/CDG/JFK) |
| GET | `flight/airports/by-iata` | `AirportController@getByIata` |
| GET | `flight/airports/grouped` | `AirportController@groupedByCountry` |

### 5.5 Airline Accounts (Legacy) (6 routes)

| Method | URI | Action |
|---|---|---|
| GET/POST | `flight/airline-accounts/` | index/store |
| PUT | `flight/airline-accounts/{id}` | update |
| DELETE | `flight/airline-accounts/{id}` | destroy |
| POST | `flight/airline-accounts/add-credit` | addCredit |
| GET | `flight/airline-accounts/{accountId}/transactions` | transactions |

### 5.6 Refunds / Modifications / Passengers (12 routes)

| Method | URI | Action |
|---|---|---|
| GET | `flight/refunds/treasuries` | `RefundController@treasuries` |
| GET | `flight/refunds/airline-credits` | `RefundController@airlineCredits` |
| POST/GET | `flight/refunds/` + `/{id}` | store/show |
| POST | `flight/refunds/{id}/process` | process |
| DELETE | `flight/refunds/{id}` | destroy |
| POST/GET/PATCH | `flight/modifications/` + `/{id}/status` + `confirm` + `reconcile` | modification lifecycle |
| GET | `flight/passengers/` | `PassengerController@index` |
| POST | `flight/passengers/{id}/mark-traveled` + `unmark-traveled` | mark/unmark |
| Various | `flight/passengers/alert-settings` + `notifications` | notifications |

### 5.7 Reports (1 route)

| Method | URI | Action |
|---|---|---|
| GET | `v1/reports/flights/detailed` | `FinancialReportController@detailedFlightReport` (requires `admin`) |

---

## 6. الـ Controllers Inventory

### 6.1 Flight-Specific Controllers (`app/Http/Controllers/Api/V1/Flight/`)

| Controller | Lines | DI | Key Methods |
|---|---|---|---|
| `FlightController` | 353 | `FlightBookingService` | `index`, `store`, `show`, `update`, `updatePrices`, `confirm`, `addPayment`, `cancel`, `destroy`, `sendTicketEmail`, `systemTypes`, `employeesForBooking` |
| `AviationController` | 211 | `AviationService` | `nextNumber`, `index`, `store`, `show`, `update`, `cancel` (not routed!), `destroy`, `report`, `treasuryTransaction` |
| `FlightCarrierController` | 324 | – | `index`, `store` (excludes `balance`), `show`, `update`, `destroy` (refuses if balance ≠ 0 OR has bookings), `balance`, `recharge` |
| `FlightSystemController` | 240 | – | `index`, `store`, `show`, `update`, `destroy` (refuses if balance ≠ 0 OR has carriers) |
| `FlightGroupController` | 311 | – | `getByCarrier`, `index`, `show`, `updateNotifications`, `thresholdSummary`, `statement`, `payDebt` |
| `FlightDashboardController` | 185 | – | `index` (monthly revenue, cashbox/bank/wallet sums via `TreasuryService::getAveragePurchaseRate`, systems+carriers+accounts liquidity by currency) |
| `FlightTreasuryController` | 228 | – | `overview`, `systemTransactions`, `carrierTransactions`, `accountFlightTransactions`, `rechargeSystem` (uses `RechargeFlightSystemRequest`) |
| `AirportController` | 105 | – | `index`, `search`, `getByIata`, `popular` (hard-coded list), `groupedByCountry` |
| `AirlineAccountController` | 426 | – | `index`, `transactions`, `addCredit`, `store`, `update`, `destroy` |
| `RefundController` | 185 | `RefundService` | `store`, `show`, `process`, `treasuries`, `airlineCredits`, `destroy` |
| `ModificationController` | 240 | `ModificationService` | `authorizeMatrix`, `store`, `show`, `updateStatus`, `confirm`, `reconcile`, `bookingModifications`, `destroy` |
| `PassengerController` | 456 | – | `index` (uses `withTrashed()` on booking eager-load, L29-34), `formatPassengerSegmentRow`, `formatPassengerRow`, `buildDateLabel`, `markTraveled`, `unmarkTraveled`, `getAlertSettings`, `updateAlertSettings`, `getNotifications`, `markNotificationRead`, `markAllNotificationsRead` |

### 6.2 Dead Code

| File | Lines | Status |
|---|---|---|
| `app/Http/Controllers/Api/FlightBookingController.php` | 49 | ❌ **Dead stub** — 5 empty methods, no DI, never imported in `routes/api.php` |

---

## 7. الـ FormRequests (`app/Http/Requests/Flight/`)

| Class | File | Lines | Used by |
|---|---|---|---|
| `StoreFlightBookingRequest` | `StoreFlightBookingRequest.php` | 316 | `FlightController@store` |
| `UpdateFlightBookingRequest` | `UpdateFlightBookingRequest.php` | 190 | `FlightController@update` |
| `StoreFlightPaymentRequest` | `StoreFlightPaymentRequest.php` | 58 | `FlightController@addPayment` |
| `StoreFlightRefundRequest` | `StoreFlightRefundRequest.php` | 59 | `FlightController@cancel` (only `airline_penalty/office_penalty/account_id/notes`) |
| `StoreAviationBookingRequest` | `StoreAviationBookingRequest.php` | 80 | `AviationController@store` (with `booking_reference` UNIQUE + `pricing/flight/booking_channel/passengers` blocks) |
| `UpdateFlightPricesRequest` | `UpdateFlightPricesRequest.php` | 51 | `FlightController@updatePrices` |
| `RechargeFlightSystemRequest` | `RechargeFlightSystemRequest.php` | 75 | `FlightTreasuryController@rechargeSystem` (with `withValidator` enforcing currency match + active account + module_type flights/tourism) |

⚠️ **Gap:** `StoreFlightRefundRequest` لا يتحقق من `flight_booking_id` — الـ validation الفعلية للـ `refund_currency`/`destination`/`cancellation_fee` تتم inline في `RefundController@store` (L29-72).

---

## 8. الـ Vue Frontend Inventory

### 8.1 Views (`resources/js/views/flights/`)

| File | Lines | Routes → Name | Key API calls |
|---|---|---|---|
| `FlightDashboard.vue` | 455 | `/flights/dashboard` → `flights.dashboard` | `GET /api/v1/flight/dashboard` |
| `FlightIndex.vue` | 859 | `/flights/list` → `flights.list` | `GET /api/v1/flight/bookings` |
| `FlightCreate.vue` | 4,450 | `/flights/create` → `flights.create` (noKeepAlive) | `POST /api/v1/flight/bookings`, `POST /api/v1/flight/aviation`, `GET /api/v1/flight/carriers`, `GET /api/v1/flight/systems`, `GET /api/v1/flight/groups`, `GET /api/v1/flight/airports`, `GET /api/v1/flight/aviation/next-number`, `GET /api/v1/flight/booking-form/employees` |
| `FlightShow.vue` | 1,712 | `/flights/:id` → `flights.show` | `GET /api/v1/flight/bookings/{id}`, `POST /payments`, `POST /cancel`, `DELETE`, `POST /send-ticket-email` |
| `FlightEdit.vue` | 263 | `/flights/:id/edit` → `flights.edit` | `GET/PUT /api/v1/flight/bookings/{id}` |
| `FlightCustomersIndex.vue` | 2,228 | `/flights/customers` → `flights.customers` | Customer management endpoints |
| `FlightTreasuryOverview.vue` | 1,297 | `/flights/treasury` → `flights.treasury` (perm `manage_finance`) | `GET /api/v1/flight/treasury/overview`, `POST /recharge`, `POST /carriers/{id}/recharge` |
| `FlightAirlineAccountsIndex.vue` | 425 | (no router entry — only linked from other pages) | `GET /api/v1/flight/airline-accounts`, `POST /add-credit`, `POST/PUT/DELETE` |
| `FlightAirlineTransactions.vue` | 401 | `/flights/airline-accounts/:id/transactions` → `flights.airline-transactions` | `GET /api/v1/flight/airline-accounts/{id}/transactions` |
| `FlightCarriersDebt.vue` | 482 | `/flights/carriers-debt` → `flights.carriers-debt` (perm `manage_finance`) | Carrier debt endpoints |
| `FlightCarrierDetails.vue` | 308 | `/flights/carriers/:id` → `flights.carriers.show` | `GET /api/v1/flight/carriers/{id}`, `GET /api/v1/flight/carriers/{id}/groups` |
| `FlightGroupsIndex.vue` | 256 | `/flights/groups` → `flights.groups.index` (perm `manage_finance`) | `GET /api/v1/flight/groups`, `GET /api/v1/flight/groups/{id}`, `PUT /api/v1/flight/groups/{id}/notifications` |
| `PassengersIndex.vue` | 695 | `/flights/passengers` → `flights.passengers` | `GET /api/v1/flight/passengers`, `POST /api/v1/flight/passengers/{id}/mark-traveled` |

### 8.2 Components (`resources/js/components/flights/`)

| File | Lines | Purpose |
|---|---|---|
| `AirlineCreditBadge.vue` | 125 | Badge for airline credit status |
| `AirportSearchInput.vue` | 360 | Searchable IATA airport input |
| `BookingSummary.vue` | 151 | Booking summary panel |
| `CompactPassengerList.vue` | 56 | Compact list of passengers |
| `CustomerSelect.vue` | 361 | Customer picker |
| `FlightSegmentForm.vue` | 187 | Segment (leg) editor |
| `GroupNotificationsModal.vue` | 344 | Group threshold notification settings |
| `GroupThresholdWidget.vue` | 196 | Group threshold dashboard widget |
| `ModificationWizard.vue` | 448 | Ticket modification wizard |
| `PassengerForm.vue` | 169 | Passenger editor |
| `PricingBox.vue` | 127 | Pricing display + editor |
| `RechargeCarrierModal.vue` | 202 | Recharge a flight carrier |
| `RefundWizard.vue` | 505 | Refund request wizard |
| `TimePicker.vue` | 66 | Time picker |
| `TreasuryCard.vue` | 90 | Treasury card display |

### 8.3 Pinia Store (`resources/js/stores/flightStore.js`)

State fields: `bookings`, `currentBooking`, `customers`, `carriers`, `systems`, `groups`, `airports`, `popularAirports`, `tripTypes`, `currencies`, `bookingStatuses`, `paymentFilterStatuses`, `systemTypeEnumOptions`, `passengerTypes`, `systemTypes`, `airlineAccounts`, `treasuryOverview`, `groupThresholdSummary`, `loading` map, `errors`, `toasts`, `filters`, `pagination`.

Getters: `filteredBookings(filters)`.

Actions call 30+ API endpoints (full list in Inventory §5).

### 8.4 Router (`resources/js/router/index.js`)

12 routes under `/flights/*` (L54-138). All gate-checked with `middleware: 'auth'`. Permission gates (`manage_finance`) on treasury/debt routes.

---

## 9. الـ Database Migrations Inventory

49 migrations touching Flight tables. Key changes:

| Date | Table | Migration | Notes |
|---|---|---|---|
| 2026_04_26 | `flight_bookings` | `create_flight_bookings_table.php` | Initial: `booking_reference` unique, `status` PENDING, `passengers`, indexes |
| 2026_04_26 | `flight_segments`, `flight_pricing`, `passengers` | (3 migrations) | Initial child tables |
| 2026_04_27 | `flight_payments` | `create_flight_payments_table.php` | `payment_method` string, `currency` EGP |
| 2026_04_29 | `flight_bookings` | `update_flight_bookings_table_structure.php` | adds `employee_id`, `account_id`, `created_by`, `booking_number`, `system_type`, `pnr`, `airline_name`, `from_airport`, `to_airport`, `arrival_time`, `trip_details`, `purchase_price`, `selling_price`, `profit`, `currency` SAR. Defaults backfill `booking_reference` → `booking_number`. |
| 2026_05_01 | `flight_bookings` | `update_flight_bookings_system_type_enum.php` | expands `system_type` to (NDC, Amadeus, Sabre, Galileo, manual, online, gds, api, other) |
| 2026_05_01 | `flight_segments` | (`make_nullable`, `add_missing_fields`) | nullable `departure_date`, adds `flight_class`, `duration_minutes`, `is_stop`, `stop_duration_minutes` |
| 2026_05_01 | `flight_refunds` | `create_flight_refunds_table.php` | `airline_penalty/office_penalty/total_paid/refund_amount` |
| 2026_05_02 | `airline_accounts` | `create_airline_accounts_table.php` | legacy debit-side ledger |
| 2026_05_02 | `airline_transactions` | `create_airline_transactions_table_final.php` | `airline_account_id` FK, `type` enum(credit/debit/refund) |
| 2026_05_03 | `flight_systems`, `flight_carriers`, `flight_groups` | (3 migrations) | New financial system with `softDeletes` |
| 2026_05_03 | `passengers` | `update_flight_passengers_table_add_english_names.php` | adds `first_name_en`, `last_name_en`, `birth_date` |
| 2026_05_03 | `flight_bookings` | `update_flight_bookings_table_add_foreign_currency.php` | adds `foreign_currency`, `purchase_price_foreign`, `exchange_rate`, `purchase_price_egp`, FKs to systems+carriers+groups+airports |
| 2026_05_03 | `flight_payments` | `update_flight_payments_table_add_payment_details.php` | adds `bank_name`, `account_holder_name`, `wallet_number`, etc. |
| 2026_05_04 | `flight_tickets` | `create_flight_tickets_table.php` | `ticket_number` UNIQUE |
| 2026_05_04 | `flight_bookings` | `add_sale_gl_transaction_id_to_flight_bookings_table.php` | FK to `transactions` |
| 2026_05_04 | `flight_systems` | `add_financial_fields_to_flight_systems_table.php` | adds `currency`, `balance`, `credit_limit` |
| 2026_05_04 | `flight_system_transactions` | `create_flight_system_transactions_table.php` | ledger side |
| 2026_05_05 | `flight_bookings` | `add_purchase_balance_source_to_flight_bookings_table.php` | `purchase_balance_source` (carrier/system/both) |
| 2026_05_05 | `flight_bookings` | `add_settlement_snapshot_to_flight_bookings_table.php` | `currency_used`, `balance_currency_used`, `exchange_rate_used` (decimal 18,6) |
| 2026_05_05 | `flight_payments` | `add_accounting_columns_to_flight_payments_table.php` | adds `account_id`, `transaction_id`, `notes`, `created_by` |
| 2026_05_05 | `airline_transactions` | `add_flight_carrier_id_to_airline_transactions_table.php` | adds `flight_carrier_id` FK |
| 2026_05_05 | `flight_payments` | `expand_flight_payments_payment_method_enum.php` | MySQL-only ENUM expansion |
| 2026_05_09 | `flight_carriers` | `make_flight_system_id_nullable_in_flight_carriers.php` | makes `flight_system_id` nullable |
| 2026_05_09 | `passengers` | (`add_baggage_and_passport`, `add_national_id`) | adds `passport_number`, `national_id`, `baggage_allowance_kg` |
| 2026_05_12 | `refund_requests` | `create_refund_requests_table.php` | new refund flow with `destination` (airline_credit/agency_treasury), `refund_exchange_rate` decimal 15,6 |
| 2026_05_12 | `airline_credits` | `create_airline_credits_table.php` | `flight_carrier_id`, `customer_id`, `amount`, `status` |
| 2026_05_12 | `flight_bookings` | `add_refund_fields_to_flight_bookings_table.php` | `original_currency`, `original_amount`, `booking_exchange_rate`, `base_currency_amount` |
| 2026_05_12 | `ticket_modifications` | `create_ticket_modifications_table.php` | with `currency_snapshot`, `exchange_rate_snapshot`, `reconciliation_status` |
| 2026_05_14 | `flight_bookings` | `add_airline_account_id_to_flight_bookings_table.php` | adds `airline_account_id` FK |
| 2026_05_14 | `flight_pricings` (plural) | `create_flight_pricings_table.php` | newer (note: co-exists with singular `flight_pricing`) |
| 2026_05_17 | `flights` | `create_flights_table_custom.php` | legacy single-flight table with `softDeletes` |
| 2026_05_22 | `flight_groups` | `modify_flight_groups_table.php` | makes `flight_carrier_id` nullable, adds `account_id` FK |
| 2026_05_25 | `flight_group_transactions` | `create_flight_group_transactions_table.php` | `type` enum(debt/payment) |
| 2026_06_27 | `passengers` | `add_traveled_at_to_passengers_table.php` | `traveled_at` timestamp |
| 2026_07_10 | `flight_payments`, `refund_requests`, `ticket_modifications`, `airline_credits` | `add_soft_deletes_to_flight_financial_tables.php` | adds `deleted_at` (audit fix) — explicitly notes `transactions` and `account_entries` must NEVER gain `deleted_at` |
| 2026_07_16 | `flight_groups` | `add_credit_limit_to_flight_groups.php` | `credit_limit` default 999999999 ("auto-credit") |
| 2026_07_16 | `ticket_modifications` | `add_currency_snapshot_to_ticket_modifications.php` | `currency_snapshot` |
| 2026_07_16 | `flight_payments` | `add_original_amount_to_flight_payments.php` | `original_amount` decimal 15,4 |
| 2026_07_21 | `flight_bookings` | `clean_redundant_original_currency_on_flight_bookings.php` | NULLs `original_currency`/`original_amount` where they equal `currency` |
| 2026_07_22 | `flight_groups` | `add_notification_settings_to_flight_groups.php` | adds 6 notification threshold fields |
| 2026_07_29 | `flight_bookings` | `add_selling_price_foreign_to_flight_bookings_table.php` | adds `selling_price_foreign` |
| 2026_08_09 | `flight_groups` | `add_currency_to_flight_groups_table.php` | adds `currency` (default EGP) |

---

## 10. الـ Authorization Map

### 10.1 Policies

لا توجد `Flight*Policy` classes. Authorization either:
- **Inherits from global middleware** (`auth:sanctum` + `active` + `CaptureFinancialPostingContext` + `RejectBannedFinancialBypassMarkers`)
- **Inline checks** in controllers (e.g., `ModificationController::authorizeMatrix`, `FlightCarrierResource::rechargeBalance`)
- **Spatie Permissions** (e.g., `manage_finance`)

### 10.2 Inline Authorization Matrix

| Action | Allows | Source |
|---|---|---|
| `modifications.confirm` | `modifications.confirm` permission OR role in `['admin','owner','head_of_finance']` | `FlightCarrierResource` L273, `ModificationController::authorizeMatrix` |
| `modifications.quote` (create) | `employee`, `agent`, `manager` | `ModificationController::authorizeMatrix` |
| `modifications.approve` | `finance`, `manager` (but allow `admin/owner/head_of_finance` to skip) | `ModificationController::authorizeMatrix` |
| `flight.treasury` (Vue route) | `manage_finance` permission | `resources/js/router/index.js` |
| `flight.carriers-debt` (Vue route) | `manage_finance` permission | `resources/js/router/index.js` |
| `flight.groups` (Vue route) | `manage_finance` permission | `resources/js/router/index.js` |
| `v1/reports/flights/detailed` API | `admin` middleware | `routes/api.php` L487 |

### 10.3 No-Admin-Routes Gap

⚠️ **Critical:** لا توجد `->middleware('admin')` على أي route من الـ 97+ Flight routes. كل authenticated user يمكنه الوصول لكل العمليات (مثل bus / hajj-umra / visa / wallet التي تستخدم `admin` middleware).

---

## 11. الـ Existing Tests Coverage

| File | Type | Count | Notes |
|---|---|---|---|
| `scripts/flight_module_full_e2e.php` | Manual E2E script | 12 scenarios | Production DB + `TX-FULL-E2E-` prefix |
| `scripts/flight_module_e2e_audit.php` | Older E2E audit | ~50 KB file | Predecessor, may be stale |
| `tests/Feature/Flight*Test.php` | PHPUnit | 0 | No tests exist |
| `tests/Unit/Flight*Test.php` | PHPUnit | 0 | No tests exist |

### 11.1 Existing 12 Scenarios Reference

`scripts/flight_module_full_e2e.php` covers:
- T1: Manual EGP booking + full payment
- T2: EGP booking + partial then full payment
- T3: USD booking with conversion
- T4: SAR booking with conversion
- T5: KWD booking with conversion
- T6: Booking + Cancel
- T7: Booking via flight group (B2B)
- T8: Booking via flight carrier
- T9: Booking via flight system
- T10: TX-201 BUG FIX VERIFICATION (سند صرف must subtract)
- T11: سند قبض regression (must subtract)
- T12: Full cycle: book + pay

---

## 12. الـ Pre-Discovered Gaps (Numbered)

> ⚠️ هذه gaps مكتشفة قبل التنفيذ. ستُعاد تقييمها في الـ Full E2E Audit.

**Finding #1 — Dual Filament Resources (CRITICAL)**
- `app/Filament/Resources/Flight/*` (root) مكسور / ناقص Pages لـ `FlightBooking`, `FlightCarrier`, `FlightGroup`, `FlightSystem`. الـ Admin Panel تحت `app/Filament/Admin/Resources/` هو الـ canonical surface.
- يجب اختبار: أي panel يصله الـ User؟ هل يصل Panel مكسور؟

**Finding #2 — No `admin` middleware on Flight routes (CRITICAL)**
- كل الـ 97+ Flight routes متاحة لأي authenticated user. لا توجد `->middleware('admin')` كما في Bus.
- يجب اختبار: هل يستطيع موظف عادي حذف booking؟ تعديل refund؟

**Finding #3 — Dead Stub Controller**
- `app/Http/Controllers/Api/FlightBookingController.php` (49 lines) مكسور — 5 methods فارغة. لم يُضف في `routes/api.php`.
- قرار: حذف أم إكمال أم تجاهل؟

**Finding #4 — Two parallel Booking Surfaces**
- `FlightController` و `AviationController` كلاهما ينشئ bookings، لكن بـ schemas مختلفة. `AviationController@cancel` معرّف لكن غير معرّف في route.
- يجب اختبار: هل يستخدم Vue كلا الـ surfaces بشكل صحيح؟ هل تتعارض البيانات؟

**Finding #5 — Hard Delete behavior على بعض الـ Models**
- `AirlineAccount`, `FlightPassenger`, `FlightTicket`, `FlightSegment`, `FlightRefund`, `FlightSystemTransaction`, `FlightGroupTransaction`, `AirlineTransaction`, `FlightPricing` ليسوا soft-deletable.
- يجب اختبار: هل حذفها يكسر relationships؟ هل يجب تجنب الحذف ما دامت children موجودة؟

**Finding #6 — `AviationController@cancel` Not Routed**
- كشف `AviationController@cancel` لكن لا يُستدعى من route. فقط `destroy` معرّف.
- أثر: المستخدم الـ Vue / API قد يحاول `/flight/aviation/cancel/{id}` ويصطدم بـ 404.

**Finding #7 — `AviationController@nextNumber` Routed at wrong prefix**
- `GET flight/aviation/next-number` مسجّل *خارج* `Route::prefix('flight')` في `routes/api.php` L119، فالـ URL الحقيقي هو `v1/aviation/next-number` (ليس `v1/flight/aviation/next-number`).
- Vue calls `GET /api/v1/flight/aviation/next-number` — قد يفشل في staging.

**Finding #8 — `FlightAirlineAccountsIndex.vue` Not Registered in Router**
- 425-line view غير مربوط بـ router. فقط internal links.
- قرار: هل هذه صفحة يجب اختبارها؟ (لا يمكن الـ audit الوصول إليها عبر الـ Vue router.)

**Finding #9 — `StoreFlightRefundRequest` Missing Fields**
- يقبل فقط `airline_penalty/office_penalty/account_id/notes`. لا يتحقق من `flight_booking_id` (binding via route).
- الـ actual fields `refund_currency/destination/cancellation_fee` تتحقق inline في `RefundController@store`.

**Finding #10 — `FlightPricing` (singular) vs `flight_pricings` (plural)**
- جدولان موجودان. الـ model `FlightPricing` يستخدم `flight_pricing` (singular). جدول `flight_pricings` (plural) تم إنشاؤه 2026_05_14 ولا يوجد Model مرتبط ظاهر.
- فحص: هل يستخدم الكود أحدهما؟ الاثنان؟ هل هو خلل ترحيل؟

**Finding #11 — `airline_accounts` Legacy With No SoftDelete**
- `AirlineAccount` (legacy) لا يستخدم `SoftDeletes`. الـ migrations 2026_05_02. باقي الـ Flight system يستخدم `flight_carriers` (الجديد).
- يجب اختبار: هل لا يزال `airline_accounts` مستخدمًا؟ هل يمثل debit-side legacy بدون حماية؟

**Finding #12 — `paid_amount` Eloquent Accessor with SoftDeleted FlightPayments**
- `FlightBooking::getPaidAmountAttribute()` يستثني الـ soft-deleted payments. إذا تم soft-delete لـ payment بالخطأ، الـ paid_amount يقل، لكن `amount` الـ row لازال في DB.
- يجب اختبار: ماذا يحدث إذا soft-delete لـ payment ثم استعادته؟ (هل paid_amount يرجع؟)

**Finding #13 — `FlightBooking::original_currency` Auto-Nullified by Observer**
- `static::saving` observer ينظف `original_currency`/`original_amount` إذا تساويا مع `currency`. هذا قد يخفي نية المبرمج.
- يجب اختبار: لو user يحدد `original_currency = USD, currency = USD, amount = 100` — الحقول تُمسح بعد save.

**Finding #14 — `FlightBooking::profit` Guarded**
- `static::saving` observer يحظر تعديل `profit` مباشرة إلا عبر `isProfitMutationAllowed` flag.
- يجب اختبار: لو الفاتورة تُحدّث بدون ضبط الـ flag، الـ profit لا يتغير.

**Finding #15 — `LedgerBalanceMutationGuard` على `FlightCarrier.balance` و `FlightSystem.balance`**
- تعديل مباشر للـ `balance` عبر `update()` يرمي `RuntimeException`. التعديل يتم فقط عبر `FlightCarrierRechargeService` / `FlightSystemRechargeService`.
- يجب اختبار: لو Filament resource يحاول تحديث balance → يرمي. (لكن: `FlightCarrierResource::store/update` لا تطلب `balance` — صحيح.)

**Finding #16 — `flight/groups/threshold-summary` Literal Route**
- معرّف قبل `{group}` لمنع Laravel من تفسيره كـ id. لا يزال fragile إذا أضيف route literal آخر.

**Finding #17 — `popular` Airports Hard-coded**
- `AirportController@popular` يحتوي قائمة ثابتة من 10 مطارات. لن يعكس airports جديدة مُضافة.

**Finding #18 — `paid_amount` لو تعدّدت currencies**
- `FlightBooking::getPaidAmountAttribute()` يجمع `FlightPayment.amount` بدون تحويل للـ `booking.currency`. لو booking بـ USD و payments بـ EGP, المبلغ المجمّع غير متّسق.
- يجب اختبار: لو خليط currencies في payments لنفس booking، هل الـ remaining_amount منطقي؟

**Finding #19 — `lockForUpdate` Sort in Recharge Services**
- `FlightCarrierRechargeService` و `FlightSystemRechargeService` يرتّبان الـ locks بترتيب تصاعدي حسب الـ id (ليس دائم الترتيب). قد يسبب deadlock في scale عالٍ.

**Finding #20 — `ModificationController::authorizeMatrix` لا يستخدم Policy**
- الـ RBAC matrix inline. صعب الـ audit.
- يجب اختبار: هل user بدون `modifications.confirm` permission ولكن role `employee` يمكنه confirm؟

**Finding #21 — `FlightBookingService::createBooking` Length (3243 Lines Service)**
- الـ service يحتوي 3243 سطر. صعب الـ E2E. الوظائف قد تكون في helper methods لكن تتطلب تتبع دقيق.

**Finding #22 — `AddFlightCurrency` مسارات تختلف عن `BookingCurrency`**
- `BookingCurrency` (EGP/USD/KWD/SAR/EUR/AED) ≠ `GroupCurrency` (EGP) ≠ `CarrierCurrency` (EGP/KWD/SAR/USD) ≠ `SystemCurrency` (EGP).
- يجب اختبار: إنشاء booking بـ EUR + group بـ EGP + carrier بـ USD — هل النظام يقبل؟ هل يحول بشكل صحيح؟

**Finding #23 — `FALLBACK_EGP_PER_UNIT` Hard-coded in `FlightBookingService`**
- `USD=48.5, KWD=157.5, SAR=12.9, EUR=52.3, GBP=61.2` — قيم hard-coded. لو الـ currency service لا يجد rate، يستخدم هذه.
- يجب اختبار: هل فعليًا يُستخدم؟ في أي paths؟

**Finding #24 — `FlightBooking::paid_amount` صفر بعد Cancel with Refund**
- بعد cancel مع refund كامل، `paid_amount` يحسب الـ payments الأصلية (غير soft-deleted). لكن لو الـ controller الـ refund لا يحذف الـ payments DB rows، تبقى مرئية.
- يجب اختبار: هل بعد cancel + refund، `paid_amount = 0` أم يحسب الـ payments الأصلية؟

**Finding #25 — No Vue Test Coverage**
- لا توجد Vue tests (Vitest/Jest) لمكونات Flight. الـ audit يجب أن يركز على الـ API contracts بدلاً من الـ DOM.

**Finding #26 — Service has `flight.ExchangeRate` Fallback Methods**
- `egpPerUnitOfCurrency` و `lockedEgpPerBalanceUnit` يستخدمان fallback rates. لو الـ API للـ live rates يفشل، الـ audit يجب أن يفهم الفرق.

**Finding #27 — `FlightCarrierRechargeService` Currency Match Validation**
- يرمي `RuntimeException` لو `source->currency !== carrier->currency`. لكن الـ exception غير ممرّر بشكل نظيف للـ API.
- يجب اختبار: هل الـ API يرجع 422 أم 500 لـ currency mismatch؟

**Finding #28 — `PassengerController::index` uses `withTrashed()` on eager-load**
- `L29-34` يشرح أن الـ bookings soft-deleted تُعرض لأنها مرتبطة بـ passengers. لكنه لا يوضح منطق القائمة الأساسية.

**Finding #29 — `DetailedFlightReport` Requires Admin**
- `GET v1/reports/flights/detailed` يتطلب `admin` middleware. لو Vue يستهلكها بدون admin token، ستفشل.

**Finding #30 — `FlightCreate.vue` 4,450 Lines**
- أكبر ملف Vue في المشروع. يخلط booking form + segment editor + passenger repeater + pricing + submit. صعوبة صيانة.

---

## 13. الـ Coverage Estimate (Pre-Execution)

| Layer | Testable | Partial | Not Supported | Not Testable |
|---|---|---|---|---|
| Models | 18 | 0 | 0 | 0 |
| Services | 8 | 0 | 0 | 0 |
| API Routes | 95 | 2 | 0 | 0 |
| Vue Views | 13 | 1 (orphan) | 0 | 0 |
| Filament Res (Admin) | 7 | 0 | 0 | 0 |
| Filament Res (Root) | 3 | 0 | 6 (broken) | 0 |
| FormRequests | 6 | 1 (incomplete) | 0 | 0 |
| Currency Coverage | 6 (EGP/USD/KWD/SAR/EUR/AED) | 0 | 0 | 0 |
| Multi-Currency paths | 4 paths | 0 | 0 | 0 |

---

## 14. الـ Multi-Currency Coverage Map (Critical)

| Currency | Booking | Payment | Refund | Debt | Account | Treasury | Reports |
|---|---|---|---|---|---|---|---|
| EGP | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| USD | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| KWD | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SAR | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| EUR | ✅ (Filament) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| AED | ✅ (Filament) | ✅ | ✅ | ? | ? | ? | ? |
| GBP | fallback rate only | ? | ? | ? | ? | ? | ? |

⚠️ **Currency paths to test specifically:**
- Booking in USD, payment in EGP → conversion
- Booking in KWD, refund in SAR → multi-hop conversion
- Booking in EUR, debt in AED → currency mismatch (should it be allowed?)
- Booking in SAR, refund with `airline_credit` destination → credit balance in SAR
- Booking in EGP, payment in USD, account in USD → triple currency

---

## 15. الـ Audit Artifacts Plan

الـ artifacts التالية سيتم إنشاؤها:

1. `FLIGHT_MODULE_AUDIT_INVENTORY_20260813.md` — **هذا الملف** (Phase 0)
2. `FLIGHT_MODULE_SOFT_DELETE_MATRIX_20260813.md` — Soft-delete matrix (SD1-SD17 × entities)
3. `scripts/flight_audit_phase_*.php` — Phase scripts (A, F, H, I, J, L, M, N, O, P, Q, T, V)
4. `FLIGHT_MODULE_FULL_E2E_AUDIT_20260813.md` — Final report

---

## 16. الـ Cleanup Strategy

كل audit scripts تستخدم prefix `TX-FLIGHT-E2E-20260813-` على:
- `Customer.name` / `Account.name`
- Booking references (`booking_reference`)
- FlightGroup names
- FlightCarrier names
- Airport names (لا، لن نلمس الـ master data)

Cleanup tinker snippet سيتم توفيره في نهاية كل script.

---

## 17. Multi-Currency Financial Reconciliation Plan

لكل currency combination في booking:
- ✅ Original Amount
- ✅ Original Currency
- ✅ Payment Currency
- ✅ Account Currency
- ✅ Transaction Currency
- ✅ Exchange Rate (snapshot)
- ✅ Converted Amount (booking_currency, base_currency)
- ✅ Rounding (decimal 2 / 4 / 6)
- ✅ Debt Currency
- ✅ Refund Currency
- ✅ Final Account Balance

سيتم اختبار 4 cross-currency scenarios:
1. Single-currency booking (USD, EGP, KWD, SAR)
2. Booking in foreign currency + payment in EGP (FX conversion)
3. Booking in foreign currency + payment in same currency (no conversion)
4. Booking in foreign currency + multi-currency payments (compound)
5. Refund in different currency than booking (refund_exchange_rate)

---

## 18. الـ Accepted Scope

ضمن الـ Audit:
- ✅ جميع Models
- ✅ جميع Services
- ✅ جميع Routes (97+)
- ✅ جميع Filament Resources اللي تعمل (Broken ones ستُسجل NOT_APPLICABLE)
- ✅ Vue Frontend (API contracts + key user flows)
- ✅ Multi-Currency (6+ currencies)
- ✅ Soft Delete (9 entities مع `deleted_at`)
- ✅ Validation (6 FormRequests)
- ✅ DB Integrity
- ✅ Reports Parity
- ✅ Real-Life Scenarios

خارج الـ Audit:
- ❌ PHPUnit tests (لا توجد)
- ❌ Vue Component DOM tests (لا توجد)
- ❌ External integrations (Fawry, Email, SMS) — sandbox config فقط
- ❌ Production data

---

## 19. Acceptance Criteria

- ✅ **PASS** — السلوك الصحيح في كل الطبقات
- ⚠️ **WARN** — يعمل لكن مع caveats
- ❌ **FAIL** — سلوك خاطئ / financial inconsistency
- 🚫 **NOT_SUPPORTED** — الـ feature غير مطبق
- ❓ **NOT_TESTABLE** — لا يمكن اختباره (e.g., broken Pages)

**Verdict Rule:**
- أي واحد من: duplicate booking / payment / transaction / refund / incorrect debt / incorrect balance / currency mismatch / financial mismatch / seat double-booking / missing transaction → **NO-GO**
- 3+ HIGH findings → **NO-GO**
- 1-2 HIGH findings → **GO WITH FINDINGS**
- 0 HIGH, no critical → **GO**

---

## 20. القائمة - Pre-Execution Snapshot

✅ Inventory مكتمل.
⬜ Soft-Delete Matrix (SD1-SD17 × entities).
⬜ Staging DB connection verification.
⬜ Baseline re-run of `flight_module_full_e2e.php` (12 scenarios).
⬜ Phase A — Auth & Permissions.
⬜ Phase F — Filament UI.
⬜ Phase H — Multi-Currency Cross-Border.
⬜ Phase I — Transaction Type & Dedupe.
⬜ Phase J — Treasury Reconciliation.
⬜ Phase L — Validation (FormRequests).
⬜ Phase M — Reports Parity.
⬜ Phase N — DB Integrity.
⬜ Phase O — Real-Life Scenarios.
⬜ Phase P — Regression.
⬜ Phase Q — Coverage Matrix.
⬜ Phase T — Tenant Isolation / Idempotency.
⬜ Phase V — Vue Frontend.
⬜ Final Report.

---

**END OF INVENTORY**
