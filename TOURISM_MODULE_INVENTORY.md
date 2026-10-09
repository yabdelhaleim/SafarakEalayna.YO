# Tourism Module Inventory — SafarakEalayna (Laravel 13 + Filament 5)

Audit date: 2026-08-17
Audit target: local `safarakealayna` MySQL database, `APP_ENV=local`.
Prepared by: Tourism Full System + Financial Integrity Audit (Phase 0 — Architecture Discovery).
Status: read-only inventory. No production code was modified.

---

## 1. Top-level architecture

SafarakEalayna is a Laravel 13 / Filament 5 / Vue 3 travel-agency platform organized around a **division → module → booking → ledger-entry** hierarchy. Two top-level divisions exist (`tourism` and `office`) at `app/Enums/TransactionModule.php:5-17`. The **Tourism division** groups four product modules — **Flight, Hajj/Umra, Visa, Bus** — plus touch points in the **Office division** (Fawry, Online Services, Wallet/Transfer, Office treasury). Every module owns its models, services, controllers, Filament resources, Vue views, request validators, and JSON resources. All financial flows funnel through a centralized double-entry ledger (`Transaction`, `Account`, `AccountEntry`, `Transfer`) in `app/Services/Finance/` and are guarded by `LedgerBalanceMutationGuard` and `ModelDeletionGuard`. See `CLAUDE.md:31-127` and `app/Models/Account.php:1-60`.

The accounting convention enforced project-wide is:

```
account.balance = SUM(account_entries.credit) − SUM(account_entries.debit)
```

(opposite the textbook double-entry meaning, but documented as the project invariant and enforced by `Account::updating()` plus `LedgerBalanceMutationGuard`).

---

## 2. Tourism-division modules (canonical members)

### 2.1 Flight — module_type `flights` / `flight`

- Models: `app/Models/Flight/FlightBooking.php`, `FlightPassenger.php`, `FlightPayment.php`, `FlightSegment.php`, `FlightSystem.php`, `FlightCarrier.php`, `FlightGroup.php`, `FlightGroupTransaction.php`, `FlightSystemTransaction.php`, `FlightTicket.php`, `FlightRefund.php`, `FlightPricing.php`, `AirlineAccount.php`, `AirlineCredit.php`, `AirlineTransaction.php`, `RefundRequest.php`, `TicketModification.php`.
- Controllers: `app/Http/Controllers/Api/V1/Flight/FlightController.php` (index/store/show/update/updatePrices/confirm/addPayment/cancel/destroy + `systemTypes`, `employeesForBooking`, `sendTicketEmail`), `AviationController.php`, `FlightBookingController.php`, `FlightCarrierController.php`, `FlightSystemController.php`, `FlightGroupController.php` (incl. `payDebt`, `statement`, `thresholdSummary`, `notifications`), `FlightDashboardController.php`, `FlightTreasuryController.php`, `RefundController.php`, `ModificationController.php`, `PassengerController.php`, `AirlineAccountController.php`, `AirportController.php`.
- Services: `app/Services/Flight/FlightBookingService.php` (createBooking at L213, `deleteBookingWithReversal`, `backfillMissingCustomerSaleLedgers`, `createFlightTickets`, locked-rate + multi-currency helpers), `RefundService.php`, `ModificationService.php`, `FlightCarrierRechargeService.php`, `FlightSystemRechargeService.php`, `FlightGroupThresholdService.php`, `AviationService.php`, `AirlineAccountDebitService.php`.
- Filament resources: `app/Filament/Admin/Resources/FlightBookings/`, `FlightCarriers/`, `FlightGroups/`, `FlightSystems/`, `FlightWallets/`, `BankAccounts/`, `Airports/`. Plus legacy `app/Filament/Resources/Flight/`. Module nav `app/Filament/Admin/Support/FlightModuleNavigation.php` + trait `app/Filament/Admin/Concerns/BelongsToFlightModuleNavigation.php`. Admin page `app/Filament/Admin/Pages/FlightSystemsBalancesPage.php`.
- Vue routes: `/flights/*` (`resources/js/router/index.js:56-138`); views in `resources/js/views/flights/`.

### 2.2 Hajj/Umrah — module_type `hajj_umra` (legacy `hajj`)

- Models: `app/Models/HajjUmraBooking.php`, `app/Models/HajjUmra/Hotel.php`, `HajjUmraExecutingCompany.php`, `UmrahSupplier.php`, `TripSupervisor.php`, `VisaAgent.php` (shared with Visa), `VisaDuration.php` (shared), `AccommodationType.php`, `app/Models/UmrahTransactionPassenger.php`, `app/Models/HajjUmraPayment.php`.
- Controllers: `app/Http/Controllers/Api/V1/HajjUmraController.php` (index/store/show/update/destroy/cancel/refund/addPayment + `customerBalances`, `customerStatement`), `app/Http/Controllers/Api/V1/HajjUmra/HajjUmraDashboardController.php`, `HajjUmraProgramController.php`, `HajjUmraTreasuryController.php`, `HajjUmraExecutingCompanyFinanceController.php`, `UmrahSupplierApiController.php`, plus shared `app/Http/Controllers/Api/V1/HajjUmraReferenceController.php`.
- Services: `app/Services/HajjUmra/HajjUmraBookingService.php` (create L103, cancel L507, `deleteBookingWithReversal` L598, `addPayment` L669, `repostExpenseTransaction` L267, `repostIncomeTransaction` L327), `app/Services/HajjUmra/HajjUmraRefundService.php`.
- Filament: `app/Filament/Admin/Resources/HajjUmraBookings/`, `HajjUmraExecutingCompanies/`, `HajjUmraBankAccounts/`, `HajjUmraWallets/`, `Programs/`, `TripSupervisors/`, `AccommodationTypes/`. Admin pages `HajjUmraExecutingCompanyAdvances.php`. Module nav `HajjUmraModuleNavigation.php` + trait `BelongsToHajjUmraModuleNavigation`.
- Vue routes: `/hajj-umra/*` (`resources/js/router/index.js:141-219`); views in `resources/js/views/hajjUmra/`.

### 2.3 Visa — module_type `visa` (DB alias `visas`)

- Models: `app/Models/VisaBooking.php`, `VisaDetail.php`, `VisaPayment.php`, shared `app/Models/HajjUmra/VisaAgent.php`, `VisaDuration.php`.
- Controllers: `app/Http/Controllers/Api/V1/VisaController.php` (`customerBalances`, `customerStatement`, `payCustomerDebt` L206), `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php`, `VisaTreasuryController.php`, `VisaAgentApiController.php`, `VisaAgentFinanceController.php` (`dues`, `withdraw`, `repay`).
- Services: `app/Services/Visa/VisaBookingService.php` (create L133, update L276, cancel L41, `deleteWithReversal` L163, `addDebtPayment` L419, `addPayment` L484, `repostExpenseTransaction`/`repostIncomeTransaction`), `app/Services/Visa/VisaModificationService.php`, `app/Services/Visa/VisaRefundService.php` (cancel L43, refund L102, `deleteWithReversal`).
- Filament: `app/Filament/Admin/Resources/VisaBookings/`, `VisaAgents/`, `VisaBanks/`, `VisaWallets/`, `VisaDurations/`. Visa has its own Cluster `app/Filament/Clusters/VisaCluster.php`. Admin page `VisaAgentDebtStatement.php`.
- Vue routes: `/visas/*` (`resources/js/router/index.js:223-281`); views in `resources/js/views/visa/`.

### 2.4 Bus — module_type `bus` (office division but full tourism-style booking module)

- Models: `app/Models/Bus/BusBooking.php`, `BusCompany.php`, `BusInventory.php`, `BusGovernorate.php`, `BusCompanyPayment.php`, `BusPayment.php`, `BusRefundRequest.php` (legacy `BusTicket.php` is scheduled for drop).
- Controllers: `app/Http/Controllers/Api/V1/Bus/BusBookingController.php`, `BusCompanyController.php`, `BusInventoryController.php`, `BusCustomerController.php`, `BusDashboardController.php`, `BusTreasuryController.php`, `BusRefundController.php`. Public endpoints at `app/Http/Controllers/Api/V1/BusTicketController.php`.
- Services: `app/Services/Bus/BusBookingService.php` (createBooking L205, `payBooking` L455, `cancelBooking` L604 → returns `BusRefundRequest`, `deleteBooking` L957, `deleteBookingWithReversal` L1062, `applyCompanyCreditOnCancel` L786, `reverseCustomerSaleDebt` L861), `BusRefundService.php`, `BusInventoryService.php` (`payInventoryDebt` L261), `BusCompanyService.php`, `BusTransactionTypeClassifier.php`. Contract documented in `app/Services/Bus/README.md`.
- Filament: `app/Filament/Admin/Resources/BusBookings/`, `BusCompanies/`, `BusInventories/`, `BusBanks/`, `BusWallets/`, `BusGovernorates/`, `BusCompanyPayments/`, `BusTickets/` (hidden). Module nav `BusModuleNavigation.php` + trait `BelongsToBusModuleNavigation`.
- Vue routes: `/bus/*` (`resources/js/router/index.js:283-346`); views in `resources/js/views/bus/`.

---

## 3. Office-division modules participating in the tourism financial surface

### 3.1 Online Services — module_type `online`

- Models: `app/Models/Online/OnlineTransaction.php`, `OnlineServiceType.php`, `OnlineServiceProvider.php`.
- Controllers: `app/Http/Controllers/Api/V1/Online/OnlineTransactionController.php`, `OnlineSettingsController.php`, `OnlineServiceTypeController.php`, `OnlineServiceProviderController.php`, `OnlineCustomerController.php`.
- Services: `app/Services/Online/OnlineTransactionService.php` (create L110, update L211, **delete-as-cancel** L611, `repostIncomeTransaction`/`repostExpenseTransaction`/`repostCashPaymentTransaction`, `reclaimWalkInArExcess`, `ensureCustomerAccount`). Contract documented in `app/Services/Online/README.md` — Online is the only module where `delete()` is **status swap + additive reversal**, never row erase.
- Filament: `OnlineTransactions/`, `OnlineServiceTypes/`, `OnlineServiceProviders/`, `OnlineBankAccounts/`, `OnlineWallets/`. Module nav `OnlineModuleNavigation` + trait `BelongsToOnlineModuleNavigation`.
- Vue routes: `/online/*` (`resources/js/router/index.js:395-454`); views in `resources/js/views/online/`.

### 3.2 Fawry — module_type `fawry`

- Models: `app/Models/Fawry/FawryTransaction.php`, `FawryCurrency.php`, `FawryMachine.php`, `FawryMachineTransaction.php`, `FawryOperationType.php`, `FawryPaymentMethod.php`.
- Controllers: `app/Http/Controllers/Api/V1/Fawry/FawryTransactionController.php`, `FawryMachineApiController.php`, `FawryWalkInPaymentController.php`, `FawrySettingsController.php`, `FawryDashboardController.php`, `FawryTreasuryController.php`. `FawryWalkInPaymentController::payDebt` L34 is the cashbox-side debt-repayment entry.
- Services: `app/Services/Fawry/FawryTransactionService.php` (`createTransaction` L82, `updateTransaction` L357, `deleteTransaction` L524, `correctDeficitIfAny`, `ensureCustomerAccount`), `FawryMachineRechargeService.php`.
- Filament: `FawryTransactions/`, `FawryMachines/`, `FawryBanks/`, `FawryCashboxes/`, `FawryWallets/`, `FawryCurrencies/`, `FawryOperationTypes/`, `FawryPaymentMethods/`. Module nav `FawryModuleNavigation` + trait `BelongsToFawryModuleNavigation`.
- Vue routes: `/fawry/*` (`resources/js/router/index.js:455-511`); views in `resources/js/views/fawry/`.

### 3.3 Wallet / Transfer — module_type `wallet` / `wallet_transfer`

- Models: `app/Models/Wallet/WalletTransaction.php`, `WalletType.php`.
- Controllers: `app/Http/Controllers/Api/V1/Wallet/WalletTransactionController.php`, `WalletTypeController.php`, `TransferDashboardController.php`, `TransferTreasuryController.php`.
- Services: `app/Services/Wallet/WalletTransactionService.php` (`createTransaction` L79, `updateTransaction` L176, `deleteTransaction` L730, `repostMainTransactions` L321, `repostSettlementTransaction` L383, `postMainSendPair`/`postSettlementSend`/`postMainReceivePair`/`postSettlementReceive`, `ensureCustomerAccount`).
- Filament: `WalletTransactions/`, `WalletTypes/`, `WalletAccounts/`, `TransferAccounts/`. Module nav `WalletModuleNavigation` + trait `BelongsToWalletModuleNavigation`.
- Vue routes: `/wallet/*` (`resources/js/router/index.js:349-394`); views in `resources/js/views/wallet/`.
- Note: `TransactionModule` enum defines only `Wallet`, but `CustomerController::payDebt` accepts `wallet_transfer`; a `transfer_dashboard` / `transfer_treasury` controller pair also exists.

### 3.4 Office division cross-cutting surfaces (tourism-relevant)

- `app/Http/Controllers/Api/V1/Office/OfficeTreasuryController.php` (`accountTransactions` L83, `belongsToOfficeDivision` L273) — unified view across Bus, Fawry, Online, Wallet/Transfer.
- `app/Filament/Admin/Resources/OfficeAccounts/{OfficeBankResource,OfficeCashboxResource,OfficeWalletResource}.php`.
- Division filter logic in `app/Models/Account.php` (scopes `scopeTourism`, `scopeOffice`, `scopeModule`, `scopeCurrency`) and `app/Services/Finance/AccountService.php` (`AccountModuleDivision::applyModuleTypeFilter`).

---

## 4. Adjacent and shared surfaces

### 4.1 HR / Employee — not tourism product but adjacent

- Models: `app/Models/Employee.php`, `app/Models/Employee/EmployeeBonus.php`, `EmployeeAttendance.php`, `Payroll.php`.
- Controllers: `app/Http/Controllers/Api/V1/Employee/EmployeeController.php`, `EmployeeBonusController.php`, `AttendanceController.php`, `EmployeeDashboardController.php`, `EmployeeReportController.php`.
- Filament Cluster `app/Filament/Clusters/EmployeeCluster.php`.

### 4.2 Shared cross-module master data

- `app/Models/Customer.php` (root model with `module_type` discriminator L38, `ensureCustomerAccount` factory used by every module's service).
- `app/Models/Supplier.php` (root, `account()`, `scopeWithDebt`, `getRemainingCreditAttribute`).
- `app/Models/Program.php` (Hajj/Umrah programs), `Airport.php`, `Bank.php`, `ExchangeRate.php`, `Invoice.php` + `InvoiceItem.php` + `InvoicePayment.php`.
- Filament master resources: `CustomerResource.php`, supplier and currency resources, exchange rates, bank accounts, operation types, payment methods, print settings.

### 4.3 Observers, middleware, rules

- Observers: `app/Observers/{CustomerLedgerObserver.php, FlightGroupObserver.php, HajjUmraExecutingCompanyObserver.php, UmrahSupplierObserver.php, VisaAgentObserver.php}`.
- Middleware: `app/Http/Middleware/{EnsureIsActive.php, EnsureIsAdmin.php, CheckPermission.php, CaptureFinancialPostingContext.php, RejectBannedFinancialBypassMarkers.php, StandardizeApiResponse.php}`.
- Liquidity rules: `app/Rules/{BusLiquidityAccount.php, FawryLiquidityAccount.php, HajjUmraLiquidityAccount.php, OnlineLiquidityAccount.php, TransferLiquidityAccount.php, VisaLiquidityAccount.php}` (phase 5 broadened liquidity rule per module).

### 4.4 Finance core (cross-cutting ledger)

- Models: `app/Models/{Account.php, Transaction.php, AccountEntry.php, Transfer.php, Invoice.php, ApprovalWorkflow.php, AuditLog.php, LedgerReconciliationRun.php, LedgerReconciliationFinding.php}`.
- Services: `app/Services/Finance/{AccountService.php, TransactionService.php, TreasuryService.php, TreasuryLedgerMirror.php, TreasuryAccountResolver.php, AccountingService.php, ApprovalService.php, AuditService.php, CurrencyService.php, PrepaidLedgerService.php, SupplierAccountService.php, LedgerClearingAccounts.php, LedgerEntryDescriptionResolver.php, LedgerReconciliationService.php, LedgerRepairService.php, AccountRechargeService.php, TransactionAuditStamper.php, DeferredTransactionDeletionGuard.php, TrialBalanceExportService.php, InvoiceService.php}`.
- Controllers: `app/Http/Controllers/Api/V1/Finance/{AccountController.php, TransactionController.php, TreasuryController.php, ApprovalController.php, AuditController.php, CurrencyController.php, ExpenseController.php, SupplierAccountController.php}`.
- Filament cluster `app/Filament/Clusters/Finance/FinanceCluster.php` (`app/Filament/Resources/Finance/AccountResource.php`).
- Reports services: `app/Services/Reports/{FinancialReportService.php, FinanceOperationsReportService.php, ProfitLossReportService.php, ReportCustomerService.php, ReportEmployeeService.php, ReportFinanceService.php, ReportOperationsService.php}` plus `app/Http/Controllers/Api/V1/Reports/{FinancialReportController.php, ReportController.php}`.

---

## 5. Payment / refund / cancellation / reversal paths (evidence-based)

### 5.1 Flight

- **Payment**: `FlightController::addPayment` (`app/Http/Controllers/Api/V1/Flight/FlightController.php:268`) → `FlightBookingService` create/update + `FlightPayment` rows. Idempotency column added by `2026_08_15_150000_add_idempotency_key_to_flight_payments.php`.
- **Multi-currency refund (Ticket Refund System)**: `routes/api.php:229-236` → `Flight/RefundController.php::store/process/show` → `app/Services/Flight/RefundService.php` (`createRefundRequest` L136, `processRefundRequest` L246, `reverseRefundRequest` L533).
- **Modification**: `routes/api.php:239-248` → `ModificationController::store/updateStatus/confirm/reconcile/destroy` → `app/Services/Flight/ModificationService.php`. Backed by `airline_account_debits` via `AirlineAccountDebitService::debitForModification`/`creditBackForModification`.
- **Cancellation**: `FlightController::cancel` L317 (uses `StoreFlightRefundRequest`).
- **Soft delete with reversal**: `FlightController::destroy` L356 → service-level `deleteBookingWithReversal`.
- **Airline credit lifecycle**: `AirlineCreditResource.php` + `RefundRequestResource.php` Filament panels.
- **Group debt pay-down**: `FlightGroupController::payDebt` L173 → writes `FlightGroupTransaction` + `Transaction` pair via `TransactionService`. Threshold-based notifications via `FlightGroupThresholdService::evaluateAndNotify`.

### 5.2 Hajj/Umrah

- **Payment**: `HajjUmraController::addPayment` (`app/Http/Controllers/Api/V1/HajjUmraController.php:142`) → `HajjUmraBookingService::addPayment` (`app/Services/HajjUmra/HajjUmraBookingService.php:669`) with idempotency (`database/migrations/2026_08_15_143500_add_idempotency_key_to_hajj_umra_payments.php`).
- **Cancel (additive reversal)**: `HajjUmraController::cancel` L131 → `HajjUmraBookingService::cancel` L507. Admin-only per route group L546-548.
- **Refund**: `HajjUmraController::refund` L179 → `HajjUmraRefundService::refund` (`app/Services/HajjUmra/HajjUmraRefundService.php:48`).
- **Soft-delete with reversal**: `HajjUmraController::destroy` L104 → `HajjUmraBookingService::deleteBookingWithReversal` L598.
- **Repost** of expense/income transactions used for live re-pricing: `HajjUmraBookingService::repostExpenseTransaction` L267, `repostIncomeTransaction` L327.
- **Supplier/executing-company finances**: `HajjUmraExecutingCompanyFinanceController::dues/withdraw/repay`; Filament page `HajjUmraExecutingCompanyAdvances.php`.

### 5.3 Visa

- **Payment**: `VisaBookingController::addPayment` (`app/Http/Controllers/Api/V1/Visa/VisaBookingController.php:171`) → `VisaBookingService::addPayment` L484 (idempotent via migration `2026_08_15_200000`).
- **Debt payment**: `VisaBookingService::addDebtPayment` L419 (separate from `addPayment`).
- **Cancel**: `VisaBookingController::cancel` L156 → `VisaRefundService::cancel` L43. Admin-only at `routes/api.php:578`.
- **Refund**: `VisaBookingController::refund` L199 → `VisaRefundService::refund` L102.
- **Soft-delete with reversal**: `VisaBookingController::destroy` L128 → `VisaRefundService::deleteWithReversal` L163.
- **Visa customer debt (cashbook-side)**: `VisaController::payCustomerDebt` L206 (`app/Http/Controllers/Api/V1/VisaController.php`); admin-only at `routes/api.php:593`.
- **Visa agent (supplier) finances**: `VisaAgentFinanceController::dues/withdraw/repay`. Filament page `VisaAgentDebtStatement.php`.

### 5.4 Bus (full additive-reversal contract; documented at `app/Services/Bus/README.md`)

- **Three deletion paths**:
  - `BusBookingService::cancelBooking` L604 → status `Cancelled`/`Refunded`/`PartiallyRefunded`; creates `BusRefundRequest`; refund via `recordExpense`; reverses company cost (`applyCompanyCreditOnCancel` L786) and customer sale debt (`reverseCustomerSaleDebt` L861).
  - `BusBookingService::deleteBooking` L957 (no payments only).
  - `BusBookingService::deleteBookingWithReversal` L1062 (any state; soft-deletes payments too).
- **Payment**: `BusBookingController::pay` L152 → `BusBookingService::payBooking` L455.
- **Bus supplier debt**: `BusCompanyController::payDebt` L254; `BusInventoryController::payDebt` L131 → `BusInventoryService::payInventoryDebt` L261.
- **Filament rule**: every `DeleteAction`/`DeleteBulkAction` is **forbidden**; service delegation only.
- **ModelDeletionGuard** wired in `app/Models/Bus/BusBooking.php`.

### 5.5 Online Services (cancellation-only contract; documented at `app/Services/Online/README.md`)

- **Payment**: implicit in `OnlineTransactionService::create` L110 (`postFinancialEntries` L925); cash payment for walk-in customers posted as separate `Income` row.
- **Cancel (delete-as-cancel)**: `OnlineTransactionController::destroy` L112 → `OnlineTransactionService::delete` L611 → sets `status=Cancelled` + additive `reverseTransaction()` per related `Transaction` row.
- **Edit / repost**: `OnlineTransactionController::update` L98 → `OnlineTransactionService::update` L211 (repost-only when amounts change; phase 9 fix per README §4).
- **Always-throws observer**: `app/Models/Online/OnlineTransaction.php:91-104` (no soft delete path even for tests).
- **Walk-in overpayment reclaim**: `OnlineTransactionService::reclaimWalkInArExcess` L769.
- **Customer debt paydown**: `CustomerController::payDebt` with `module=online`.

### 5.6 Fawry

- **Create**: `FawryTransactionController::store` L65 → `FawryTransactionService::createTransaction` L82 → `FawryTransaction::runProfitMutation`.
- **Payment / cash walk-in**: `FawryWalkInPaymentController::payDebt` L34 → FIFO updates `fawry_transactions.amount`.
- **Update / re-price**: `FawryTransactionService::updateTransaction` L357; `correctDeficitIfAny` L777.
- **Delete (admin-only)**: `FawryTransactionController::destroy` L120 → `FawryTransactionService::deleteTransaction` L524.
- **Machine recharge**: `FawryMachineApiController::recharge` (admin-only) → `FawryMachineRechargeService::rechargeFromAccount`.

### 5.7 Wallet / Transfer

- **Create**: `WalletTransactionController::store` L42 → `WalletTransactionService::createTransaction` L79.
- **Send/Receive ledger pairs**: `postMainSendPair` L508, `postSettlementSend` L573, `postMainReceivePair` L636, `postSettlementReceive` L701.
- **Update / repost**: `WalletTransactionService::updateTransaction` L176 → `repostMainTransactions` L321 + `repostSettlementTransaction` L383.
- **Delete (admin-only)**: `WalletTransactionController::destroy` L84 → `WalletTransactionService::deleteTransaction` L730.
- **Customer balances/statement**: `customerBalances` L110, `customerStatement` L223.

---

## 6. Customer / supplier debt paths

### 6.1 Customer debt (accounts receivable)

- **Master statement & balance**: `CustomerController::statement` L160 + `payDebt` L206 (`app/Http/Controllers/Api/V1/CustomerController.php`).
  - Module-aware account creation `CustomerController.php:223-247` (resolves `module_type` from `module` input; default `flights`).
  - Bug fix TX-201 in pay-debt branch: always journal `from`=customer, `to`=treasury regardless of receipt/payment type.
- **Visa-only**: `VisaController::payCustomerDebt` L206 (`app/Http/Controllers/Api/V1/VisaController.php`).
- **Fawry walk-in**: `FawryWalkInPaymentController::payDebt` L34.
- **Reports**: `FinancialReportController::customerDebtsReport` L184, `customerLedgerBalances` L573, `debtsReport` L608.

### 6.2 Supplier (payable) debt

- **Master service**: `app/Services/SupplierService.php` (`createSupplier`, `updateSupplier`, `getSuppliersDebt` L109, `updateDebt` L80).
- **Master API**: `app/Http/Controllers/Api/V1/SupplierController.php` (`getDebt` L123) + `app/Http/Controllers/Api/V1/Finance/SupplierAccountController.php` (`recharge` L23, `statement` L44, `balance` L66).
- **Service-layer supplier debt**: `app/Services/Finance/SupplierAccountService.php` (`rechargeSupplierAccount` L25, `debitSupplierAccount` L75, `creditSupplierAccount` L119, `getSupplierStatement` L159, `getSupplierBalance` L173).
- **Flight B2B groups**: `FlightGroupController::payDebt` L173 → `FlightGroupTransaction` + journal entry.
- **Bus companies**: `BusCompanyController::payDebt` L254 → `BusCompanyService::ensureCompanyAccount` L211; `BusInventoryController::payDebt` L131 → `BusInventoryService::payInventoryDebt` L261.
- **Hajj/Umrah executing companies**: `HajjUmraExecutingCompanyFinanceController::dues/withdraw/repay`. Filament page `HajjUmraExecutingCompanyAdvances.php`. Observer `app/Observers/HajjUmraExecutingCompanyObserver.php`.
- **Visa agents**: `VisaAgentFinanceController::dues/withdraw/repay`. Observer `app/Observers/VisaAgentObserver.php`. Filament page `VisaAgentDebtStatement.php`.
- **Umrah suppliers**: `UmrahSupplierApiController`; observer `app/Observers/UmrahSupplierObserver.php`.
- **Reports**: `FinancialReportController::supplierDebtsReport` L198.

### 6.3 Master ledger / division policy

- Invariant enforced in `app/Models/Account.php:173-330` (`booted()` updating guard on `Account.balance`).
- Division classification in `app/Models/Account.php:419-454` (`scopeTourism`, `scopeOffice`, `scopeModule`, `scopeCurrency`).
- `app/Services/Finance/AccountService.php:84-135` (module_type filter on accounts).
- `app/Support/Finance/AccountModuleContract.php` and `app/Support/Finance/LedgerBalanceMutationGuard.php` enforce the additive-reversal contract project-wide.

---

## 7. Filament wiring summary

- **Clusters** (`app/Filament/Clusters/`):
  - `FinanceCluster` (`app/Filament/Clusters/Finance/FinanceCluster.php`).
  - `VisaCluster` (`app/Filament/Clusters/VisaCluster.php`) — Visa's cluster contains bookings + agents + banks + wallets (and the page `VisaAgentDebtStatement` is cluster-resident via its parent resource).
  - `EmployeeCluster` (`app/Filament/Clusters/EmployeeCluster.php`) — HR cluster (not tourism product).
- **Module nav groups** (no cluster, parent-item grouping): `app/Filament/Admin/Support/{FlightModuleNavigation, HajjUmraModuleNavigation, BusModuleNavigation, OnlineModuleNavigation, FawryModuleNavigation, WalletModuleNavigation}.php` plus matching traits in `app/Filament/Admin/Concerns/BelongsTo*ModuleNavigation.php`. Bus additionally uses `protected static ?string $navigationGroup = 'الباصات';` style grouping on legacy resources.
- **Admin resources** (54 directories under `app/Filament/Admin/Resources/`):
  - Flight: `FlightBookings, FlightCarriers, FlightGroups, FlightSystems, FlightWallets, BankAccounts, Airports`.
  - Hajj/Umrah: `HajjUmraBookings, HajjUmraExecutingCompanies, HajjUmraBankAccounts, HajjUmraWallets, Programs, TripSupervisors, AccommodationTypes`.
  - Visa: `VisaBookings, VisaAgents, VisaBanks, VisaWallets, VisaDurations`.
  - Bus: `BusBookings, BusCompanies, BusInventories, BusBanks, BusWallets, BusGovernorates, BusCompanyPayments, BusTickets (hidden)`.
  - Online: `OnlineTransactions, OnlineServiceTypes, OnlineServiceProviders, OnlineBankAccounts, OnlineWallets`.
  - Fawry: `FawryTransactions, FawryMachines, FawryBanks, FawryCashboxes, FawryWallets, FawryCurrencies, FawryOperationTypes, FawryPaymentMethods`.
  - Wallet/Transfer: `WalletTransactions, WalletTypes, WalletAccounts, TransferAccounts`.
  - Office: `OfficeAccounts` (cross-module office division vaults).
  - Tourism division root: `TourismAccounts` (Bank, Wallet, Cashbox — `TourismBankResource.php`, `TourismWalletResource.php`, `TourismCashboxResource.php`).
  - HR: `EmployeeBonuses, Payrolls`.
  - Master: `Accounts, Currencies, ExchangeRates, ExpenseAccounts, TicketModifications, AccommodationTypes`.
- **Legacy resources** under `app/Filament/Resources/` (Filament v3 style, pre-cluster): `CustomerResource, SupplierResource, AirportResource, Airport, AirportResource.php, TreasuryResource, Flight/{AirlineCreditResource.php, FlightBookingResource.php, RefundRequestResource.php, TicketModificationResource.php}, Employee/{EmployeeResource.php, EmployeeAttendanceResource.php, EmployeeBonusResource.php}, Finance/AccountResource.php, Setting/{OperationTypeResource, PaymentMethodResource}.php`, plus `FlightSystem, FlightCarrier, FlightGroup` top-level resource files.
- **Custom admin pages** (`app/Filament/Admin/Pages/`): `Dashboard.php, CashFlowStatement.php (division='tourism' default), ProfitLossAnalysis.php, CurrencyTreasuryExchangePage.php, FlightDashboard.php, FlightSystemsBalancesPage.php, HajjUmraExecutingCompanyAdvances.php, VisaAgentDebtStatement.php, BusCompanyDebtStatement.php, AccountStatement.php, MaintenanceModePage.php, Auth/Login.php`.
- **Widgets** (`app/Filament/Widgets/`): `BookingsChartWidget, RecentBookingsWidget, StatsOverviewWidget, TopDestinationsWidget`. Admin widgets include `DashboardChartWidget, FinancialStatsWidget, FlightStatsWidget, QuickStatsWidget, RecentActivitiesWidget, RecentFlightBookingsWidget, AdminPortalWidget`.
- **Concerns** (`app/Filament/Admin/Concerns/`): `BelongsToFlightModuleNavigation, BelongsToBusModuleNavigation, BelongsToHajjUmraModuleNavigation, BelongsToFawryModuleNavigation, BelongsToOnlineModuleNavigation, BelongsToWalletModuleNavigation, HasSafarakFlightModulePageStyles, HasSafarakWalletModulePageStyles`.
- **Support** (`app/Filament/Admin/Support/`): 6 `*ModuleNavigation` final classes plus `AccountTableFilters.php`.

---

## 8. API & Vue route map

Routes are registered in `C:\travile\SafarakEalayna\routes\api.php` (634 lines). Top-level prefixes (under `/api/v1` after auth/active middleware at L105-110):

| Prefix | Controller set | Tourism role |
|---|---|---|
| `auth` | `Api/Auth/AuthController.php` | login/refresh |
| `public` | `Bus/{BusCompanyController,BusInventoryController}` | public inventories |
| `settings` | `SettingController, PrintSettingController` | master data |
| `dashboard` | `DashboardController` | admin only |
| `finance` | `Finance/{AccountController,TreasuryController,CurrencyController,ApprovalController,AuditController,SupplierAccountController,TransactionController,ExpenseController}` | core ledger, admin-gated |
| `flight` | `Flight/*` (12 controllers) | core tourism |
| `bus` | `Bus/*` (7 controllers) | office-division but tourism-relevant |
| `office/treasury` | `Office/OfficeTreasuryController` | unified office view |
| `wallet` | `Wallet/*` (4 controllers) | office-division |
| `online` | `Online/*` (5 controllers) | office-division |
| `fawry` | `Fawry/*` (6 controllers) | office-division |
| `employee` | `Employee/*` (5 controllers) | HR |
| `reports` | `Finance/ReportController, FinancialReportController` | admin |
| `customers` | `CustomerController` (statement + pay-debt) | shared |
| `hajj-umra` | `HajjUmraController, HajjUmra/*, HajjUmraReferenceController` | core tourism |
| `visa` | `VisaController, Visa/*` | core tourism |
| `invoices` | `InvoiceController` | admin |
| `suppliers` | `SupplierController, Finance/SupplierAccountController` | admin |
| `users` | `UserController` | admin |
| `visa-agents`, `umrah-suppliers`, `clients`, `accounts` | global aliases | compat |

Vue routes registered in `C:\travile\SafarakEalayna\resources\js\router\index.js` (765 lines, 116 path entries): every API prefix has a matching top-level Vue route (`/flights`, `/hajj-umra`, `/visas`, `/bus`, `/wallet`, `/online`, `/fawry`, `/customers`, `/finance`, `/employees`, `/reports`, `/suppliers`, `/treasury`, `/users`, `/settings`). Tourism-specific views under `resources/js/views/{flights, hajjUmra, visa, bus, online, fawry, wallet, customers, finance, reports, employees, settings}`.

---

## 9. Seeders, tests, scripts inventory

- Seeders (`database/seeders/`): `DatabaseSeeder, UserSeeder, UnifiedVaultsSeeder, AccountingTestDataSeeder, StressBulkSeeder, BusModuleProductionTestSeeder, FawryModuleProductionTestSeeder, OnlineModuleProductionTestSeeder, WalletModuleProductionTestSeeder, WalletModuleProductionTestSeeder2026`. Phase-7 unification produces exactly one office vault + one tourism vault per `UnifiedVaultsSeeder`.
- Tests (206 files under `tests/Feature/`):
  - Per-module folders: `Bus/, Customer/, Fawry/, Finance/, Flight/, HajjUmra/, Online/, Reports/, Visa/, Wallet/, Filament/, Integrity/, Security/`.
  - Cross-cutting: `tests/Feature/TourismDivision/` (e.g. `HajjUmraProductionTest`, `FlightProductionTest`, `BusProductionTest`, `FawryProductionTest`, `DebtorsProductionTest`, `JournalEntryProductionTest`, `FlightGroupCarrierIntegrationTest`, `HajjUmraExecutingCompanyIntegrationTest`, `FullDeleteFlowE2ETest`, `ApiErrorTranslationTest`).
  - `tests/Unit/`, `tests/e2e/tourism/`, `tests/reports/`, `tests/Stress/`, `tests/Browser/`, `tests/Filament/`.
- Document contracts inside repo: `app/Services/Bus/README.md` and `app/Services/Online/README.md` document the additive-reversal and status-swap contracts respectively. `CLAUDE.md` documents the service-layer / DB::transaction / enum conventions.
- `phpunit.xml` (SQLite testing), `phpunit.stress.xml` (SQLite stress), and `tests/Stress/StressBulkSeeder.php` indicate a stress profile.

---

## 10. Audit scope decision

Given the breadth of modules and the user's directive to cover **all Tourism modules and any other Tourism-related services**, the audit must classify each module:

| Module | Tourism division | Audit scope |
|---|---|---|
| Flight | yes (core) | full lifecycle + Flight financial surface (airline-accounting, group-debt, refund, modification, deletion) |
| Hajj/Umrah | yes (core) | full lifecycle + supplier/executing-company, customer AR, refund, deletion, repost |
| Visa | yes (core) | full lifecycle + visa-agent finances, customer AR, refund, deletion, repost |
| Bus | yes (core, office division) | full lifecycle + bus-company supplier, inventory, cancellation, refund, deletion |
| Online Services | yes (touch) | status-swap deletion, customer AR, walk-in overpayment |
| Fawry | yes (touch) | cashier walk-in, FIFO debt repayment, machine recharge, delete |
| Wallet / Transfer | yes (touch) | send/receive pairs, repost, delete, customer balances |
| Office Treasury | yes (cross-cutting) | unified view across office division |
| Finance core | yes (cross-cutting) | Account, Transaction, AccountEntry, Transfer, Approval, Audit, Currency, Supplier-Account, Expense, Invoicing |
| Reports | yes (cross-cutting) | P&L, Trial Balance, customer-ledger balances, supplier-debt, daily, financial-position |
| HR / Employee | no (adjacent) | out of scope unless financial impact crosses division |
| Settings / Master data | yes (shared) | idempotency keys, currencies, exchange rates, payment methods |

Next phase: build the local audit runner that creates identifiable fixtures, exercises the full lifecycle through real services, and reconciles every ledger/reporting surface back to the canonical Transaction/AccountEntry data.
