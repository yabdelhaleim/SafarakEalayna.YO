# Hajj & Umrah Module — Complete Inventory

> **Audit date:** 2026-08-14
> **Purpose:** Discover every Hajj & Umrah operation from the actual codebase to drive the 18-phase audit.
> **Method:** Static-only inspection of routes, controllers, services, models, requests, resources, migrations, Filament, Vue, and Pinia.

---

## 1. Backend Models

### Root models (`app/Models/`)

| Model | Table | Soft delete | Key fields | Notes |
|-------|-------|:-:|------------|-------|
| `HajjUmraBooking` | `hajj_umra_bookings` | ✅ | `customer_id`, `companion_customer_id`, `program_id`, `supplier_id`, `purchase_price`, `companion_purchase_price`, `selling_price`, `companion_selling_price`, `profit`, `currency`, `per_person`, `accommodation_choice`, `accommodation_extra_charge`, `status`, `agent_name`, `notes`, `baggage`, `account_id`, `employee_id`, `expense_transaction_id`, `income_transaction_id` | Booted hooks: `deleting` blocks unless inside `HajjUmraBooking::isAllowed()`; `saving` blocks `profit` writes unless whitelisted by `ModelProfitMutationGuard` |
| `HajjUmraPayment` | `hajj_umra_payments` | ✅ | `hajj_umra_booking_id`, `account_id`, `transaction_id`, `payment_method`, `amount`, `currency`, `treasury_account`, `transaction_reference`, `payment_date`, `paid_by`, `created_by` | Lazy-load via `booking->payments` |
| `Program` | `programs` | ✅ | `program_name`, `program_type`, `season`, `total_nights`, `accommodation_type`, `accommodation_type_id`, `mecca_hotel_*`, `medina_hotel_*`, `departure_date`, `return_date`, `airline`, `trip_supervisor`, `trip_supervisor_id`, `executing_company`, `executing_company_id`, `departure_point`, `booking_status`, `program_price_tier`, `default_purchase_price`, `default_selling_price`, `is_active` | Booted saving hook auto-syncs accommodation/executing_company/trip_supervisor by name ↔ id |
| `Customer` | `customers` | ✅ | name/phone/national_id/passport/passport_expiry/dob/city/affiliation/module_type/account_id | `module_type` tracks which module owns the customer |
| `Account` | `accounts` | ❌ | name/type/balance/currency/is_active/owner_type/module_type/module/is_module_vault/wallet_provider/wallet_number | Booted saving hook enforces **division contract** (liquidity → tourism/office; subject → specific module) |
| `Transaction` | `transactions` | ❌ | type/amount/currency/module/related_type/related_id/program_id/from_account_id/to_account_id/notes/correlation_id | Append-only financial record |
| `AccountEntry` | `account_entries` | ❌ | account_id/transaction_id/debit/credit/balance_after/notes | Append-only; no destructive deletes |
| `UmrahTransactionPassenger` | `umrah_transaction_passengers` | ❌ | transaction_id(category FK by booking_id)/category/count/unit_price/subtotal | Categories: adult/child_with_bed/child_no_bed/infant |

### Sub-namespace models (`app/Models/HajjUmra/`)

| Model | Table | Soft delete | Notes |
|-------|-------|:-:|-------|
| `AccommodationType` | `accommodation_types` | ❌ | Reference table; code/name_ar/name_en/capacity/sort_order/is_active |
| `HajjUmraExecutingCompany` | `hajj_umra_executing_companies` | ✅ | Booted saving auto-creates `Supplier` Account with `module_type='hajj_umra'` when `account_id` is null |
| `Hotel` | `hotels` | ✅ | name/city/country/stars/price_per_night/total_rooms/available_rooms |
| `TripSupervisor` | `trip_supervisors` | ✅ | full_name/phone/national_id/notes/is_active |
| `UmrahSupplier` | `umrah_suppliers` | ✅ | name/phone/account_id/default_cost_price/is_active |
| `VisaAgent` | `visa_agents` | ✅ | company_name/contact_person/phone/email/country/visa_type/default_cost_price/account_id/notes/is_active |
| `VisaDuration` | `visa_durations` | ❌ | Reference table; code/label_ar/label_en/months/entry_type/sort_order/is_active |

---

## 2. Backend Controllers

### `app/Http/Controllers/Api/V1/HajjUmraController.php` (417 lines)

| Method | HTTP | URI | Notes |
|--------|:----:|-----|-------|
| `index` | GET | `hajj-umra/bookings` | Paginated; filters: status, program_id, customer_id, from_date, to_date, search, per_page, program_type; cache 60s |
| `store` | POST | `hajj-umra/bookings` | Delegates to `HajjUmraBookingService::create()` |
| `show` | GET | `hajj-umra/bookings/{hajjUmra}` | Full resource |
| `update` | PUT/PATCH | `hajj-umra/bookings/{hajjUmra}` | Delegates to service.update |
| `destroy` | DELETE | `hajj-umra/bookings/{hajjUmra}` | Soft-delete with additive reversal (admin only) |
| `cancel` | POST | `hajj-umra/bookings/{hajjUmra}/cancel` | Light cancel — additive reversal of payments/income/expense (admin only) |
| `addPayment` | POST | `hajj-umra/bookings/{hajjUmra}/payments` | Delegates to `service.addPayment()` |
| `refund` | POST | `hajj-umra/bookings/{hajjUmra}/refund` | Full refund workflow (admin only) |
| `customerBalances` | GET | `hajj-umra/customer-balances` | Aggregates debt by customer (excludes cancelled); search/dateFrom/dateTo/status |
| `customerStatement` | GET | `hajj-umra/customer-statement` | Customer ledger statement (requires `client_id`); running balance |

### Sub-namespace controllers (`app/Http/Controllers/Api/V1/HajjUmra/`)

| Controller | Methods |
|------------|---------|
| `HajjUmraDashboardController` | `index` (monthly revenue, bookings count, cashbox/bank/wallet totals, 10 recent bookings) |
| `HajjUmraExecutingCompanyFinanceController` | `dues` (aggregates withdrawn/repaid/net_due), `withdraw` (admin), `repay` (admin) |
| `HajjUmraProgramController` | `index`/`store`/`show`/`update`/`destroy` (admin) |
| `HajjUmraTreasuryController` | `overview` (settlement accounts + executing companies + recent tx), `accountHajjUmraTransactions` (paginated) |
| `UmrahSupplierApiController` | `index`, `store` |
| `HajjUmraReferenceController` (top-level) | `programs`/`visaAgents`/`executingCompanies`/`tripSupervisors`/`accommodationTypes`/`visaDurations`/`statuses` |

---

## 3. Backend Services

### `App\Services\HajjUmra\HajjUmraBookingService` (749 lines)

Public methods (all wrapped in `DB::transaction` and `LedgerBalanceMutationGuard` where appropriate):

| Method | Purpose | Side effects |
|--------|---------|--------------|
| `paginate($filters)` | List bookings with eager-loaded relations | None (read) |
| `find($id)` | Load one booking with all relations | None (read) |
| `create($data)` | Create booking + full double-entry (expense + income + optional payment) | Expense + income tx + 2 AccountEntries each; HajjUmraPayment row; ensures customer account |
| `update($booking, $data)` | Update fields + reposts expense/income via additive `reverseTransaction` then new posting | New tx + additive reverse entries on each |
| `cancel($booking, $reason)` | Idempotency: 422 if already cancelled; additive reverse of every payment/income/expense | Status → Cancelled; notes appended |
| `deleteBookingWithReversal($id, $userId)` | Admin delete: `withTrashed()->lockForUpdate()->findOrFail`; reverse every tx; soft-delete payments; soft-delete booking | All accounts restored to pre-booking state |
| `addPayment($booking, $data)` | Records income; creates HajjUmraPayment row with transaction_id pointer | 1 new tx + 2 entries |
| `resolveCustomer`, `ensureCustomerAccount` | Helpers | Account row + customer.account_id |
| `repostExpenseTransaction`, `repostIncomeTransaction` | Private helpers for additive reversal + repost | — |

### `App\Services\HajjUmra\HajjUmraRefundService` (134 lines)

| Method | Purpose | Side effects |
|--------|---------|--------------|
| `refund($booking, $reason, $userId)` | BUG-FIX 2026-07-27: throws on cancelled/refunded/trashed. Reverses every payment/income/expense. Status → refunded. | Additive reverse of each; notes appended |

---

## 4. Form Requests

| Request | Key rules |
|---------|-----------|
| `StoreHajjUmraBookingRequest` | customer_id nullable + exists; customer.{name,phone} required_without:customer_id; program_id required; purchase_price/selling_price required numeric min:0; account_id required + `HajjUmraLiquidityAccount` rule; status in enum; passengers array with category in enum |
| `UpdateHajjUmraBookingRequest` | All fields sometimes/nullable |
| `StoreHajjUmraPaymentRequest` | amount required numeric gt:0; account_id required + `HajjUmraLiquidityAccount` |
| `StoreProgramRequest` | program_name required; program_type in `[hajj,umra]` (auto-normalizes `umrah→umra`); airline required (FIX #HJ-1); departure_point required |
| `UpdateProgramRequest` | Same with `sometimes` |

---

## 5. API Resource

`App\Http\Resources\HajjUmra\HajjUmraBookingResource` (111 lines) — exposes id, module, status, customer (whenLoaded), companion, program, pricing, supplier, passengers, finance (account, expense/income_tx_id, paid_amount, remaining_amount, is_fully_paid), agent_name, notes, employee, payments, timestamps.

---

## 6. Routes (`routes/api.php`, lines 509-557, 614-634)

Prefix `/api/v1/hajj-umra` (controllers: HajjUmraDashboardController, HajjUmraTreasuryController, HajjUmraExecutingCompanyFinanceController, HajjUmraProgramController, HajjUmraReferenceController, HajjUmraController).

| Method | URL | Handler | Middleware |
|--------|-----|---------|------------|
| GET | `hajj-umra/dashboard` | `HajjUmraDashboardController@index` | auth:sanctum |
| GET | `hajj-umra/treasury/overview` | `HajjUmraTreasuryController@overview` | auth:sanctum |
| GET | `hajj-umra/treasury/accounts/{account}/transactions` | `HajjUmraTreasuryController@accountHajjUmraTransactions` | auth:sanctum |
| GET | `hajj-umra/executing-companies/dues` | `HajjUmraExecutingCompanyFinanceController@dues` | auth:sanctum |
| POST | `hajj-umra/executing-companies/{company}/withdraw` | `HajjUmraExecutingCompanyFinanceController@withdraw` | auth:sanctum + admin |
| POST | `hajj-umra/executing-companies/{company}/repay` | `HajjUmraExecutingCompanyFinanceController@repay` | auth:sanctum + admin |
| GET | `hajj-umra/programs` | `HajjUmraProgramController@index` | auth:sanctum |
| POST | `hajj-umra/programs` | `HajjUmraProgramController@store` | auth:sanctum |
| GET | `hajj-umra/programs/{program}` | `HajjUmraProgramController@show` | auth:sanctum |
| PUT/PATCH | `hajj-umra/programs/{program}` | `HajjUmraProgramController@update` | auth:sanctum |
| DELETE | `hajj-umra/programs/{program}` | `HajjUmraProgramController@destroy` | auth:sanctum + admin |
| GET | `hajj-umra/settings/programs` | `HajjUmraReferenceController@programs` | auth:sanctum |
| GET | `hajj-umra/settings/executing-companies` | `HajjUmraReferenceController@executingCompanies` | auth:sanctum |
| GET | `hajj-umra/settings/trip-supervisors` | `HajjUmraReferenceController@tripSupervisors` | auth:sanctum |
| GET | `hajj-umra/settings/accommodation-types` | `HajjUmraReferenceController@accommodationTypes` | auth:sanctum |
| GET | `hajj-umra/settings/statuses` | `HajjUmraReferenceController@statuses` | auth:sanctum |
| GET | `hajj-umra/customer-balances` | `HajjUmraController@customerBalances` | auth:sanctum |
| GET | `hajj-umra/customer-statement` | `HajjUmraController@customerStatement` | auth:sanctum |
| DELETE | `hajj-umra/bookings/{hajjUmra}` | `HajjUmraController@destroy` | auth:sanctum + admin |
| POST | `hajj-umra/bookings/{hajjUmra}/cancel` | `HajjUmraController@cancel` | auth:sanctum + admin |
| POST | `hajj-umra/bookings/{hajjUmra}/refund` | `HajjUmraController@refund` | auth:sanctum + admin |
| GET | `hajj-umra/bookings` | `HajjUmraController@index` | auth:sanctum |
| POST | `hajj-umra/bookings` | `HajjUmraController@store` | auth:sanctum |
| GET | `hajj-umra/bookings/{hajjUmra}` | `HajjUmraController@show` | auth:sanctum |
| PUT/PATCH | `hajj-umra/bookings/{hajjUmra}` | `HajjUmraController@update` | auth:sanctum |
| POST | `hajj-umra/bookings/{hajjUmra}/payments` | `HajjUmraController@addPayment` | auth:sanctum |

Umrah Suppliers (separate prefix): `GET/POST umrah-suppliers`.

**Total endpoints (HajjUmra + UmrahSupplier):** 27

---

## 7. Database Migrations (Hajj & Umrah-touching)

| File | Action |
|------|--------|
| `2026_04_27_124250_create_programs_table.php` | Creates `programs` |
| `2026_04_27_124551_create_hajj_umra_bookings_table.php` | Creates `hajj_umra_bookings` |
| `2026_04_27_145756_create_hajj_umra_payments_table.php` | Creates `hajj_umra_payments` |
| `2026_04_27_170000_consolidate_hajj_visa_duplicates.php` | One-time data migration; maps old `hajj_umrah` → new |
| `2026_05_06_075703_make_programs_accommodation_type_nullable.php` | nullable accommodation_type |
| `2026_05_06_080000_setup_hajj_umra_and_visa_accounting.php` | Adds accounting fields; creates executing_companies/trip_supervisors/accommodation_types/visa_durations/visa_agents |
| `2026_05_07_025403_add_baggage_to_hajj_umra_bookings_table.php` | baggage |
| `2026_05_07_202920_add_hotel_links_to_programs.php` | mecca_hotel_id / medina_hotel_id |
| `2026_05_07_203013_add_program_id_to_transactions.php` | transactions.program_id |
| `2026_06_03_220000_upgrade_visa_and_umrah_tables.php` | Adds supplier_id / companion_* / accommodation_* to bookings; creates umrah_suppliers and umrah_transaction_passengers |
| `2026_06_25_160000_make_programs_executing_company_nullable.php` | nullable executing_company |
| `2026_07_11_000000_add_liability_and_revenue_to_accounts_type_enum.php` | accounts.type ENUM |
| `2026_07_11_120000_add_soft_deletes_to_hajj_umra_payments_table.php` | payments.deleted_at |
| `2026_07_28_143450_add_missing_foreign_keys_to_hajj_umra_tables.php` | Idempotent FKs (Phase 6) |
| `2026_07_28_150358_add_phase6_followup_constraints.php` | hajj_umra_bookings.account_id NOT NULL + customers composite UNIQUE |

---

## 8. Enums

| Enum | Cases relevant to HajjUmra |
|------|----------------------------|
| `HajjUmraStatus` | Pending / Confirmed / InProgress / Completed / Cancelled / Refunded |
| `HajjUmraPaymentMethod` | Cash / BankTransfer / CashWallet / PostalTransfer / OfficeSafe / OfficeDrawer / Mixed |
| `TransactionModule` | HajjUmra / Hajj (legacy) / plus Flight/Bus/Wallet/Online/Fawry/Visa/Tourism/Office/General |
| `AccountType` | Bank / Cashbox / Wallet / Customer / Supplier / Owner / Expense / Liability / Revenue |
| `WalletProvider` | Used for wallet accounts |

---

## 9. Frontend (Vue 3 SPA)

### Views — `resources/js/views/hajjUmra/` (8 top-level + 3 Programs)

| File | Route path |
|------|-----------|
| `HajjUmraDashboard.vue` | `/hajj-umra/dashboard` |
| `HajjUmraIndex.vue` | `/hajj-umra/list` |
| `HajjUmraCreate.vue` | `/hajj-umra/create` |
| `HajjUmraShow.vue` | `/hajj-umra/:id` |
| `HajjUmraEdit.vue` | `/hajj-umra/:id/edit` |
| `HajjUmraTreasury.vue` | `/hajj-umra/treasury` |
| `HajjUmraCustomerBalances.vue` | `/hajj-umra/customer-balances` |
| `HajjUmraExecutingCompaniesDue.vue` | `/hajj-umra/executing-companies` |
| `Programs/ProgramIndex.vue` | `/hajj-umra/programs` |
| `Programs/ProgramCreate.vue` | `/hajj-umra/programs/create` |
| `Programs/ProgramEdit.vue` | `/hajj-umra/programs/:id/edit` |

### Pinia store — `resources/js/stores/hajjUmraStore.js`

State: `bookings`, `currentBooking`, `customers`, `accounts`, `programs`, `executingCompanies`, `executingCompaniesFinance`, `tripSupervisors`, `accommodationTypes`, `suppliers`, `statuses`, `loading`, `errors`, `toasts`, `pagination`, `filters`.

Getters: `bookingStats`, `filteredBookings`.

Actions (18): `fetchBookings`, `fetchBookingById`, `createBooking`, `updateBooking`, `cancelBooking`, `deleteBooking`, `addPayment`, `fetchCustomers`, `createCustomer`, `fetchSettings`, `fetchAccounts`, `fetchPrograms`, `fetchExecutingCompaniesDues`, `recordExecutingCompanyWithdraw`, `recordExecutingCompanyRepay`, `fetchSuppliers`, `createSupplier`, `addToast`.

---

## 10. Filament Resources (admin panel)

| Resource | Navigation | Pages |
|----------|-----------|-------|
| `HajjUmraBookingResource` | Group `الحج والعمرة`, sort 0, icon `heroicon-o-building-library` | List, Create, Edit, View (+ widget `HajjUmraStats`) |
| `HajjUmraBankAccountResource` | Hidden nav (`shouldRegisterNavigation()=false`) | List, Create, Edit |
| `HajjUmraExecutingCompanyResource` | sort 10, icon `heroicon-o-building-office-2` | Manage (single page with Create) |
| `HajjUmraWalletResource` | Hidden nav | List, Create, Edit |
| `HajjUmraExecutingCompanyAdvances` (standalone page) | Not in nav; reached from executing-company row action | Single page with withdraw/repay actions |

Widget `HajjUmraStats`: 3 stat cards (total bookings, total profit, total collected payments).

---

## 11. Permissions

`App\Support\UserPermissions::MANAGE_HAJJ = 'manage_hajj'`. Used in `DashboardLayout.vue` to gate nav-group visibility. Backend destructive routes use `admin` middleware. No granular permission strings beyond admin role.

---

## 12. Architectural Invariants

1. **Additive reversal** — cancel/refund/delete/repost use `TransactionService::reverseTransaction()` to append inverse `AccountEntry` rows on the SAME `transaction_id`. Originals are NEVER destroyed. Marked with `عكس:` prefix in notes.
2. **Division contract** — Liquidity accounts (cashbox/wallet/bank) MUST have `module_type ∈ {office, tourism}`. Subject accounts (customer/supplier) MUST have `module_type` as a specific module. Enforced by `Account::saving` and `HajjUmraLiquidityAccount` rule.
3. **`profit` column is locked** — only `HajjUmraBookingService::create/update` may write it, via `ModelProfitMutationGuard`.
4. **Deletion is locked** — only `HajjUmraBookingService::deleteBookingWithReversal()` may soft-delete, via `HajjUmraBooking::run(...)` gate.
5. **Cashbox sufficiency guard** — booking and repay flows reject operations that would push source cashbox negative (GAP #HJ-6 fix).
6. **Account entries are append-only** — `AccountEntry` model explicitly forbids soft deletes; `deleting` observer throws.
7. **HajjUmra is an alias** for both `hajj_umra` and legacy `hajj`/`umrah`. Reflected in `HajjUmraLiquidityAccount::belongsToHajjUmraModule()` and `TransactionModule` enum.

---

## 13. Coverage Summary

- **27 API endpoints** — all prefixed `/api/v1/hajj-umra` or `/api/v1/umrah-suppliers`
- **5 main controllers + 5 sub-namespace + 1 reference** = 11 controllers
- **2 services** (booking + refund)
- **11 models** (4 root + 7 sub-namespace) + shared `Account`, `Transaction`, `AccountEntry`, `Customer`
- **5 form requests**
- **1 API resource**
- **14 migrations** (table-touching)
- **8 Vue views + 3 program views + 1 Pinia store**
- **4 Filament resources + 1 widget + 1 standalone page**
- **5 enums**
- **113 existing PHPUnit tests across 9 files**
