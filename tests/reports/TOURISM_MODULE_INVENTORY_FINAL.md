# TOURISM MODULE INVENTORY — FINAL

**Audit date:** 2026-08-17
**Scope:** Tourism ONLY (Flight, Hajj/Umrah, Visa)
**Database:** local MySQL `safarakealayna` (90 tables)
**Repository root:** `C:\travile\SafarakEalayna`

---

## 1. OFFICIAL MODULE BOUNDARY (NON-NEGOTIABLE)

Per `App\Support\Finance\AccountModuleContract` (final, canonical):

| Division | Primary Marker | Members |
|---|---|---|
| **TOURISM** | `module_type='tourism'` | `tourism`, `flights`, `hajj_umra`, `visas` |
| **OFFICE** | `module_type='office'` | `office`, `bus`, `fawry`, `online`, `wallet_transfer` |
| LEGACY | `module_type='general'` | NOT in either division. Backward-compat only. |

Legacy Vue/legacy DB values (mapped via `AccountModuleDivision::LEGACY_MODULE_TO_TYPE`):
- `flight` → `flights`
- `visa` → `visas`
- `hajj` / `umrah` → `hajj_umra`
- `wallet` → `wallet_transfer`

**Sign Convention** (`App\Models\Account` docblock):
```
balance = SUM(credit) - SUM(debit)   (CREDIT increases, DEBIT decreases)
```

---

## 2. TOURISM DIVISION — TABLES (32 tourism-only tables)

### 2.1 Flight (16 tables)

| Table | Migration | Purpose |
|---|---|---|
| `flight_bookings` | `2026_04_26_211424` | Parent booking (FK→customers, flight_systems/carriers/groups) |
| `flight_segments` | `2026_04_26_211451` | Itinerary segments |
| `passengers` (shared) | `2026_04_26_211511` | Passengers of any booking type (Flight uses) |
| `flight_tickets` | `2026_05_04_100000` | Tickets |
| `flight_pricings` | `2026_05_14_162735` | Supplemental pricing |
| `flight_payments` | `2026_04_27_123013` | Payments + `idempotency_key` (`2026_08_15_150000`) |
| `flight_refunds` | `2026_05_01_183316` | Direct cancellation refunds |
| `refund_requests` | `2026_05_12_000002` | Multi-step refund workflow |
| `airline_credits` | `2026_05_12_000003` | Carrier credit vouchers |
| `ticket_modifications` | `2026_05_12_000006` | Ticket changes |
| `airline_accounts` | `2026_05_02_154235` | Legacy airline account abstraction |
| `airline_transactions` | `2026_05_02_154812` | Carrier sub-ledger |
| `flight_systems` | `2026_05_03_143626` | GDS / prepaid system |
| `flight_carriers` | `2026_05_03_143632` | Carrier |
| `flight_groups` | `2026_05_03_143632` | Carrier group with thresholds |
| `flight_system_transactions` | `2026_05_04_210000` | System sub-ledger |
| `flight_group_transactions` | `2026_05_25_194850` | Group sub-ledger |

### 2.2 Hajj/Umrah (9 tables)

| Table | Migration | Purpose |
|---|---|---|
| `programs` | `2026_04_27_124250` | Program (itinerary, season, hotel links) |
| `hajj_umra_bookings` | `2026_04_27_124551` | Parent booking (FK→customers, programs, umrah_suppliers) |
| `hajj_umra_payments` | `2026_04_27_145756` | Payments + `idempotency_key` (`2026_08_15_143500`) |
| `umrah_suppliers` | `2026_06_03_220000` | Supplier (account_id FK) |
| `hotels` | (HajjUmra namespace) | Mecca/Medina hotels |
| `hajj_umra_executing_companies` | `2026_05_06_080000` | External executing company (account_id FK) |
| `trip_supervisors` | `2026_05_06_080000` | Reference data |
| `accommodation_types` | `2026_05_06_080000` | Reference data |
| `umrah_transaction_passengers` | `2026_06_03_220000` | Passenger breakdown |

### 2.3 Visa (4 tables)

| Table | Migration | Purpose |
|---|---|---|
| `visa_bookings` | `2026_04_27_124645` | Parent booking |
| `visa_payments` | `2026_04_27_145910` | Payments + `idempotency_key` (`2026_08_15_200000`) |
| `visa_details` | `2026_04_27_124640` | Visa type/country/agent |
| `visa_durations` | (consolidated 2026_04_27_170000) | Duration lookup |
| `visa_agents` | (consolidated) | Agent (account_id FK) |

### 2.4 Shared / Tourism Finance (5 tables)

| Table | Migration | Purpose |
|---|---|---|
| `accounts` | `2026_04_27_170117` | Money-holding accounts (`module_type` distinguishes Tourism/Office) |
| `transactions` | `2026_04_27_170117` | Double-entry transaction header (`module='flight'/'hajj_umra'/'visa'/'tourism'`) |
| `account_entries` | `2026_04_27_170118` | Append-only debit/credit legs |
| `customers` | (legacy) | Customer master (shared with Office, but Tourism uses `account_id`) |
| `treasury_transactions` | `2026_04_27_170100` | Treasury mirror (some Flight rows) |

---

## 3. TOURISM MODELS (`app/Models/`)

### 3.1 Flight

| File | Class | Role |
|---|---|---|
| `Flight/FlightBooking.php` | `FlightBooking` | Parent booking (SoftDeletes + guards) |
| `Flight/FlightPayment.php` | `FlightPayment` | Child payment |
| `Flight/FlightRefund.php` | `FlightRefund` | Direct cancellation refund |
| `Flight/FlightCarrier.php` | `FlightCarrier` | Carrier w/ sub-ledger |
| `Flight/FlightSystem.php` | `FlightSystem` | GDS system w/ sub-ledger |
| `Flight/FlightGroup.php` | `FlightGroup` | Carrier group w/ thresholds |
| `Flight/FlightSegment.php` | `FlightSegment` | Itinerary segment |
| `Flight/FlightTicket.php` | `FlightTicket` | Ticket |
| `Flight/FlightPassenger.php` | `FlightPassenger` | Passenger (uses `passengers` table) |
| `Flight/FlightGroupTransaction.php` | `FlightGroupTransaction` | Group sub-ledger |
| `Flight/FlightSystemTransaction.php` | `FlightSystemTransaction` | System sub-ledger |
| `Flight/AirlineAccount.php` | `AirlineAccount` | Legacy airline account |
| `Flight/AirlineTransaction.php` | `AirlineTransaction` | Airline sub-ledger |
| `Flight/AirlineCredit.php` | `AirlineCredit` | Carrier credit voucher |
| `Flight/RefundRequest.php` | `RefundRequest` | Multi-step refund workflow |
| `Flight/TicketModification.php` | `TicketModification` | Ticket change record |
| `FlightPricing.php` | `FlightPricing` | Supplemental pricing |

### 3.2 Hajj/Umrah

| File | Class | Role |
|---|---|---|
| `HajjUmraBooking.php` | `HajjUmraBooking` | Parent booking (SoftDeletes + guards) |
| `HajjUmraPayment.php` | `HajjUmraPayment` | Child payment |
| `Program.php` | `Program` | Itinerary |
| `UmrahTransactionPassenger.php` | `UmrahTransactionPassenger` | Passenger breakdown |
| `HajjUmra/UmrahSupplier.php` | `UmrahSupplier` | Supplier (account_id FK) |
| `HajjUmra/Hotel.php` | `Hotel` | Mecca/Medina hotel |
| `HajjUmra/HajjUmraExecutingCompany.php` | `HajjUmraExecutingCompany` | External executing co (auto-creates Account) |
| `HajjUmra/TripSupervisor.php` | `TripSupervisor` | Reference |
| `HajjUmra/AccommodationType.php` | `AccommodationType` | Reference |

### 3.3 Visa

| File | Class | Role |
|---|---|---|
| `VisaBooking.php` | `VisaBooking` | Parent booking (SoftDeletes + guards) |
| `VisaPayment.php` | `VisaPayment` | Child payment |
| `VisaDetail.php` | `VisaDetail` | Visa type/country/agent/duration |
| `HajjUmra/VisaAgent.php` | `VisaAgent` | Agent (account_id FK) |
| `HajjUmra/VisaDuration.php` | `VisaDuration` | Duration lookup |

### 3.4 Shared Finance

| File | Class | Role |
|---|---|---|
| `Account.php` | `Account` | Money-holding entity (guarded balance writes) |
| `AccountEntry.php` | `AccountEntry` | Append-only ledger entries |
| `Transaction.php` | `Transaction` | Transaction header (module='flight'/'hajj_umra'/'visa') |
| `Customer.php` | `Customer` | Customer master |
| `Supplier.php` | `Supplier` | Supplier master (some Hajj/Visa suppliers) |

---

## 4. TOURISM SERVICES (`app/Services/`)

### 4.1 Flight (`app/Services/Flight/`)

| File | Class | Purpose |
|---|---|---|
| `FlightBookingService.php` | `FlightBookingService` | Canonical booking/payment/cancel flow |
| `AviationService.php` | `AviationService` | Legacy/alternate aviation facade (not canonical) |
| `RefundService.php` | `RefundService` | Multi-step refund-request workflow |
| `ModificationService.php` | `ModificationService` | Ticket modification flow |
| `AirlineAccountDebitService.php` | `AirlineAccountDebitService` | Airline account debit/credit |
| `FlightCarrierRechargeService.php` | `FlightCarrierRechargeService` | Carrier recharge from Account |
| `FlightSystemRechargeService.php` | `FlightSystemRechargeService` | System recharge from Account |
| `FlightGroupThresholdService.php` | `FlightGroupThresholdService` | Group balance threshold evaluation |

### 4.2 Hajj/Umrah (`app/Services/HajjUmra/`)

| File | Class | Purpose |
|---|---|---|
| `HajjUmraBookingService.php` | `HajjUmraBookingService` | Canonical booking/payment flow |
| `HajjUmraRefundService.php` | `HajjUmraRefundService` | Full refund (status=Refunded) |

### 4.3 Visa (`app/Services/Visa/`)

| File | Class | Purpose |
|---|---|---|
| `VisaBookingService.php` | `VisaBookingService` | Canonical booking/payment flow |
| `VisaModificationService.php` | `VisaModificationService` | Repost expense/income on edit |
| `VisaRefundService.php` | `VisaRefundService` | Cancel/refund/delete |

### 4.4 Shared Finance (`app/Services/Finance/`)

| File | Class | Purpose |
|---|---|---|
| `TransactionService.php` | `TransactionService` | Double-entry boundary (recordExpense/Income/Journal/Reverse) |
| `AccountService.php` | `AccountService` | debitAccount/creditAccount |
| `AccountingService.php` | `AccountingService` | Higher-level accounting ops |
| `LedgerClearingAccounts.php` | `LedgerClearingAccounts` | Clearing account maps |
| `LedgerEntryDescriptionResolver.php` | `LedgerEntryDescriptionResolver` | Entry notes |
| `LedgerReconciliationService.php` | `LedgerReconciliationService` | Reconciliation |
| `LedgerRepairService.php` | `LedgerRepairService` | Repair helpers |
| `PrepaidLedgerService.php` | `PrepaidLedgerService` | Prepaid balances |
| `SupplierAccountService.php` | `SupplierAccountService` | Supplier ops |
| `AccountRechargeService.php` | `AccountRechargeService` | Recharge from Account |
| `CurrencyService.php` | `CurrencyService` | FX |
| `ApprovalService.php` | `ApprovalService` | Workflow |
| `AuditService.php` | `AuditService` | Audit log |
| `TreasuryService.php` | `TreasuryService` | Treasury ops |
| `TreasuryAccountResolver.php` | `TreasuryAccountResolver` | Resolve treasury |
| `TreasuryLedgerMirror.php` | `TreasuryLedgerMirror` | Mirror writes |
| `TransactionAuditStamper.php` | `TransactionAuditStamper` | Stamp transactions |
| `TrialBalanceExportService.php` | `TrialBalanceExportService` | Export TB |
| `DeferredTransactionDeletionGuard.php` | `DeferredTransactionDeletionGuard` | Guards |

### 4.5 Reports (`app/Services/Reports/`)

| File | Class | Purpose |
|---|---|---|
| `FinancialReportService.php` | `FinancialReportService` | Customer/supplier statements, accounts |
| `ProfitLossReportService.php` | `ProfitLossReportService` | P&L + moduleBreakdown |
| `ReportCustomerService.php` | `ReportCustomerService` | Customer balances/activity |
| `ReportFinanceService.php` | `ReportFinanceService` | Finance operations |
| `ReportEmployeeService.php` | `ReportEmployeeService` | Employee reports |
| `ReportOperationsService.php` | `ReportOperationsService` | Operations dashboard |
| `FinanceOperationsReportService.php` | `FinanceOperationsReportService` | Finance ops |

---

## 5. TOURISM ACCOUNT MODULE/TYPE/DIVISION IDENTIFIERS

### 5.1 Account Module Column (`accounts.module_type`)

| AccountType | Required `module_type` | Tourism? |
|---|---|---|
| `cashbox`, `bank`, `wallet` (liquidity) | `office` OR `tourism` (DIVISION) | Yes if `tourism` |
| `customer`, `supplier` (subject) | Specific module: `flights`, `hajj_umra`, `visas` (Tourism), or `bus`, `fawry`, `online`, `wallet_transfer` (Office) | Yes if `flights`/`hajj_umra`/`visas` |
| `expense`, `revenue`, `liability`, `owner` (internal) | Constraint enforced at config (clearing rows); hook does not over-restrict | N/A |

### 5.2 Transaction Module Column (`transactions.module`)

`App\Enums\TransactionModule`:
- Tourism: `flight` (legacy alias), `hajj_umra` (incl. legacy `hajj`), `visa`, `tourism` (division marker)
- Office: `bus`, `fawry`, `online`, `wallet` (legacy alias), `office` (division marker)
- General: `general` (not assigned to a division)

### 5.3 Service-Layer Account Resolution

- `Account::getModuleVault(string $moduleType): ?Account` — resolves division-unified vault (Phase 5)
- `Account::scopeTourism()` — `module_type IN ('tourism','flights','hajj_umra','visas')`
- `Account::scopeOffice()` — `module_type IN ('office','bus','fawry','online','wallet_transfer','general')`
- `AccountModuleContract::divisionFor(?string $module): ?string` — `office`/`tourism`/null
- `AccountModuleContract::isTourismModule(?string $module): bool`

### 5.4 Liquidity Account Type Filter

`AccountModuleDivision::applyLiquidityTreasuryScope(Builder $query): void` — excludes `customer`/`supplier` names, prepaid rows, and `wallet_transfer`-style rows when scoping tourism treasury accounts.

---

## 6. TOURISM TRANSACTION TYPES (`transactions.type`)

| Type | Source | Tourism? |
|---|---|---|
| `income` | `TransactionService::recordIncome()` | Yes (Flight sale, Hajj sale, Visa sale) |
| `expense` | `TransactionService::recordExpense()` | Yes (Flight purchase, Hajj purchase, Visa purchase) |
| `transfer` | `TransactionService::recordJournalTransfer()` (type='transfer') | Yes (payments from AR to cashbox) |
| `refund` | `TransactionService::recordRefund()` | Yes (cancellation/refund) |

---

## 7. TOURISM REPORT SERVICES (canonical entry points)

| Report | Service::method | Filter keys |
|---|---|---|
| P&L | `ProfitLossReportService::report()` | `from_date`, `to_date`, `category='tourism'`/`'office'`, `module`, `section` |
| Per-module P&L | `ProfitLossReportService::moduleBreakdown()` | `from_date`, `to_date` |
| Customer balances | `ReportCustomerService::getCustomerBalances()` | `type`, `balance_sign`, `search` |
| Customer activity | `ReportCustomerService::getCustomerActivity()` | `customerId` |
| Top customers | `ReportCustomerService::getTopCustomers()` | `from_date`, `to_date`, `limit` |
| Customer statement | (in controller, HajjUmra/Visa) | `customerId` |
| Supplier statement | (in controller) | `supplierId` |

---

## 8. TOURISM STATEMENT SERVICES

- **Hajj/Umrah customer statement:** `HajjUmraController::customerStatement()` (`app/Http/Controllers/Api/V1/HajjUmraController.php:271`)
- **Visa customer statement:** `VisaController::customerStatement()` (`app/Http/Controllers/Api/V1/VisaController.php:97`)
- **Visa customer balances:** `VisaController::customerBalances()` (`app/Http/Controllers/Api/V1/VisaController.php:36`)
- **Visa agent dues:** `VisaAgentFinanceController::dues()` (`app/Http/Controllers/Api/V1/Visa/VisaAgentFinanceController.php:18`)

---

## 9. TOURISM API ROUTES (`routes/api.php`)

| Prefix | Endpoints |
|---|---|
| `/api/v1/flight/...` | bookings, payments, cancel, refund-requests, modifications, carriers, systems, groups, treasury |
| `/api/v1/hajj-umra/...` | bookings, payments, cancel, refund, programs, suppliers, executing-companies, treasury, customer-statement |
| `/api/v1/visa/...` | bookings, payments, cancel, refund, agents, treasury, customer-balances, customer-statement |

(Detailed route list captured in the audit's exploration phase.)

---

## 10. TOURISM CRITICAL INVARIANTS

1. **Balance invariant:** `accounts.balance = SUM(credit) - SUM(debit)` per account.
2. **Liquidity contract:** `module_type` MUST be `office` or `tourism` (NOT a specific module).
3. **Subject contract:** `module_type` MUST be a specific module (NOT `office`/`tourism`).
4. **Additive reversal:** Cancellation/refund appends inverse `AccountEntry` rows on the SAME `transaction_id` with `notes='عكس القيد #…'`. Original rows preserved.
5. **Append-only ledger:** `account_entries` has NO soft-deletes.
6. **Locked financial columns on HajjUmra:** `selling_price`, `purchase_price`, `companion_*`, `accommodation_extra_charge` rejected at update.
7. **Idempotency:** UNIQUE index on `(booking_id, idempotency_key)` for `flight_payments`, `hajj_umra_payments`, `visa_payments` — NULLs allowed.
8. **D01 (Visa):** Payment uses `recordJournalTransfer` (type=transfer), NOT `recordIncome`.
9. **D02 (Visa):** Service-layer rejects negative `purchase_price`/`selling_price`/`service_fee`.
10. **Locked profit column on HajjUmra/Visa:** `profit` only writable inside `*::runProfitMutation(...)`.

---

## 11. TOURISM PAYMENT/CANCEL/REFUND ENTRY POINTS

| Flow | Flight | Hajj/Umrah | Visa |
|---|---|---|---|
| Create booking | `FlightBookingService::createBooking()` | `HajjUmraBookingService::create()` | `VisaBookingService::create()` |
| Add payment | `FlightBookingService::addPayment()` | `HajjUmraBookingService::addPayment()` | `VisaBookingService::addPayment()` |
| Cancel | `FlightBookingService::cancelBooking()` | `HajjUmraBookingService::cancel()` | `VisaRefundService::cancel()` |
| Refund | `RefundService::processRefundRequest()` | `HajjUmraRefundService::refund()` | `VisaRefundService::refund()` |
| Soft-delete | `FlightBookingService::deleteBookingWithReversal()` | `HajjUmraBookingService::deleteBookingWithReversal()` | `VisaRefundService::deleteWithReversal()` |
| Repost (modification) | `ModificationService::confirmModification()` | (rejected at update) | `VisaModificationService::repostExpense()`/`repostIncome()` |

---

## 12. FILE INDEX

```
app/
  Enums/
    AccountType.php
    TransactionModule.php
    TransactionType.php
    HajjUmraStatus.php
    HajjUmraPaymentMethod.php
    VisaStatus.php
    VisaType.php
    VisaEntryType.php
    VisaPaymentMethod.php
    WalletProvider.php
  Models/
    Account.php
    AccountEntry.php
    Transaction.php
    Customer.php
    Supplier.php
    HajjUmraBooking.php
    HajjUmraPayment.php
    Program.php
    UmrahTransactionPassenger.php
    VisaBooking.php
    VisaPayment.php
    VisaDetail.php
    Flight/
      FlightBooking.php, FlightPayment.php, FlightRefund.php, ...
    HajjUmra/
      UmrahSupplier.php, Hotel.php, HajjUmraExecutingCompany.php,
      TripSupervisor.php, AccommodationType.php, VisaAgent.php, VisaDuration.php
  Services/
    Flight/  (8 services)
    HajjUmra/ (2 services)
    Visa/    (3 services)
    Finance/ (15+ services — shared)
    Reports/ (7 services — shared)
  Support/
    Finance/
      AccountModuleContract.php   ← canonical contract
      AccountModuleDivision.php   ← legacy/compatibility
      LedgerBalanceMutationGuard.php
      ModelDeletionGuard.php
      ModelProfitMutationGuard.php
  Http/
    Controllers/Api/V1/
      Flight/    (FlightController, AviationController, RefundController, ...)
      HajjUmra/  (HajjUmraController, HajjUmraProgramController, ...)
      Visa/      (VisaBookingController, VisaAgentApiController, ...)
    Requests/
      Flight/    (StoreFlightBookingRequest, ...)
      HajjUmra/  (StoreHajjUmraBookingRequest, ...)
      Visa/      (StoreVisaBookingRequest, ...)
    Resources/
      HajjUmra/  (HajjUmraBookingResource.php)
      Visa/      (VisaBookingResource.php)
  Rules/
    HajjUmraLiquidityAccount.php
    VisaLiquidityAccount.php
    BusLiquidityAccount.php

database/migrations/
  2026_04_26_211424  flight_bookings
  2026_04_26_211451  flight_segments
  2026_04_26_211511  passengers
  2026_04_27_123013  flight_payments
  2026_04_27_124250  programs
  2026_04_27_124551  hajj_umra_bookings
  2026_04_27_124640  visa_details
  2026_04_27_124645  visa_bookings
  2026_04_27_145756  hajj_umra_payments
  2026_04_27_145910  visa_payments
  2026_04_27_170117  accounts, transactions
  2026_04_27_170118  account_entries
  2026_05_01_183316  flight_refunds
  2026_05_02_154235  airline_accounts
  2026_05_02_154812  airline_transactions
  2026_05_03_143626  flight_systems
  2026_05_03_143632  flight_carriers, flight_groups
  2026_05_04_100000  flight_tickets
  2026_05_04_210000  flight_system_transactions
  2026_05_12_000002  refund_requests
  2026_05_12_000003  airline_credits
  2026_05_12_000006  ticket_modifications
  2026_05_14_162735  flight_pricings
  2026_05_25_194850  flight_group_transactions
  2026_06_03_220000  upgrade visa + umrah (umrah_suppliers, umrah_transaction_passengers)
  2026_07_11_120000  soft deletes on hajj_umra_payments
  2026_07_11_130000  soft deletes on visa_payments
  2026_08_15_143500  idempotency_key on hajj_umra_payments  (UNIQUE (booking_id,key))
  2026_08_15_150000  idempotency_key on flight_payments    (UNIQUE (booking_id,key))
  2026_08_15_200000  idempotency_key on visa_payments       (UNIQUE (booking_id,key))
```

---

**End of Inventory. Used as canonical reference for all subsequent audit sections.**
