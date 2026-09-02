# BUS MODULE DISCOVERY

## 1. Executive Overview & Module Boundaries
The **Bus Module** manages the lifecycle of bus operations, bus companies/operators, ticket inventory (batches of trip tickets for specific routes/dates), customer bookings, seat allocation, payments, debt settlements with bus companies, refunds/cancellations, and financial ledger integration (Accounts, Treasury Transactions, Accounts Receivable/Payable, Multi-Currency exchange).

### Core Entities & Boundaries
* **BusCompany**: Transport operator/supplier. Has a designated ledger account (`account_id`) for supplier debt/payables.
* **BusInventory**: A batch or trip allocation from a `BusCompany` for a specific route and travel date. Defines total tickets, available tickets, cost per ticket, selling price, and payment type (prepaid/cash vs debt/on-credit).
* **BusBooking**: A customer's ticket reservation/purchase against a specific `BusInventory`. Tracks quantity, unit price, total price, paid amount, profit, currency, exchange rate, booking status (`pending`, `confirmed`, `cancelled`, `refunded`, `partially_refunded`), and payment status (`unpaid`, `partially_paid`, `paid`).
* **BusPayment**: Payments received from customers toward a `BusBooking`. Tracks amount, payment method (cash, bank transfer, vault, etc.), target liquidity account (`account_id`), ledger transaction, currency, and exchange rate.
* **BusCompanyPayment**: Debt settlements paid to `BusCompany` for inventory purchases on credit.
* **BusRefundRequest**: Refund operations for cancelled/refunded bookings. Handles penalties (company penalty, office penalty, cancellation fee), currency conversions, treasury transaction payout, and ledger updates.
* **Customer**: The passenger/purchaser linked to ledger account for receivable tracking.

---

## 2. Key Architecture Files

### Models (`app/Models/Bus/`)
* [BusCompany.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusCompany.php): Operator entity linked to `Account`.
* [BusInventory.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusInventory.php): Trip inventory allocation linked to `BusCompany` and `Account`.
* [BusBooking.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusBooking.php): Booking transaction linked to `BusInventory`, `Customer`, `User` (employee), and `Account`.
* [BusPayment.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusPayment.php): Customer payment records.
* [BusCompanyPayment.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusCompanyPayment.php): Supplier debt payment records.
* [BusRefundRequest.php](file:///c:/travile/SafarakEalayna/app/Models/Bus/BusRefundRequest.php): Refund processing records.

### Controllers (`app/Http/Controllers/Api/V1/Bus/`)
* [BusCompanyController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusCompanyController.php)
* [BusInventoryController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusInventoryController.php)
* [BusBookingController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusBookingController.php)
* [BusRefundController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusRefundController.php)
* [BusCustomerController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusCustomerController.php)
* [BusDashboardController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusDashboardController.php)
* [BusTreasuryController.php](file:///c:/travile/SafarakEalayna/app/Http/Controllers/Api/V1/Bus/BusTreasuryController.php)

### Services (`app/Services/Bus/`)
* [BusCompanyService.php](file:///c:/travile/SafarakEalayna/app/Services/Bus/BusCompanyService.php)
* [BusInventoryService.php](file:///c:/travile/SafarakEalayna/app/Services/Bus/BusInventoryService.php)
* [BusBookingService.php](file:///c:/travile/SafarakEalayna/app/Services/Bus/BusBookingService.php)
* [BusRefundService.php](file:///c:/travile/SafarakEalayna/app/Services/Bus/BusRefundService.php)
* [BusTransactionTypeClassifier.php](file:///c:/travile/SafarakEalayna/app/Services/Bus/BusTransactionTypeClassifier.php)

### Form Requests (`app/Http/Requests/Bus/`)
* [StoreBusCompanyRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/StoreBusCompanyRequest.php)
* [UpdateBusCompanyRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/UpdateBusCompanyRequest.php)
* [StoreBusInventoryRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/StoreBusInventoryRequest.php)
* [UpdateBusInventoryRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/UpdateBusInventoryRequest.php)
* [StoreBusBookingRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/StoreBusBookingRequest.php)
* [PayBusBookingRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/PayBusBookingRequest.php)
* [CancelBusBookingRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/CancelBusBookingRequest.php)
* [PayInventoryDebtRequest.php](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/PayInventoryDebtRequest.php)

### Migrations
* `2026_04_27_230344_create_bus_companies_table.php`
* `2026_04_27_230403_create_bus_inventories_table.php`
* `2026_04_27_230404_create_bus_bookings_table.php`
* `2026_04_27_230404_create_bus_company_payments_table.php`
* `2026_05_02_020107_add_payment_fields_to_bus_bookings_table.php`
* `2026_05_02_030000_create_bus_payments_table.php`
* `2026_05_14_230032_create_bus_refund_requests_table.php`
* `2026_05_14_230212_add_bus_booking_id_to_treasury_transactions_table.php`
* `2026_05_26_000001_add_auto_created_to_bus_inventories_table.php`
* `2026_06_08_120000_add_penalty_fields_to_bus_refund_requests_table.php`
* `2026_06_25_000000_add_refunded_statuses_to_bus_bookings.php`
* `2026_07_11_140000_add_soft_deletes_to_bus_payment_tables.php`
* `2026_07_18_120000_add_currency_columns_to_bus_inventories_table.php`
* `2026_07_18_120001_add_currency_columns_to_bus_bookings_table.php`
* `2026_07_18_120002_add_currency_columns_to_bus_payments_table.php`
* `2026_08_13_120000_drop_bus_tickets_table.php` (Legacy clean up)
* `2026_08_13_120100_drop_bus_governorates_table.php` (Legacy clean up)

---

## 3. Financial & Accounting Integration
* **Double-Entry Ledger Account Integration**: `Account`, `AccountEntry`, `TreasuryTransaction`.
* **Liquidity Rules**: Enforces valid cash/bank/vault accounts for cash transactions via `BusLiquidityAccount` rule and `ModelProfitMutationGuard`.
* **Multi-Currency**: Supports `EGP`, `USD`, `SAR`, `EUR`, `AED`, `KWD`, `GBP` with `exchange_rate_to_egp`. Base currency for ledger auditing is `EGP`.
* **Profit Calculation**:
  $$\text{Profit} = (\text{Selling Price} - \text{Cost Per Ticket}) \times \text{Quantity}$$
  Tracked per booking in `bus_bookings.profit`.
* **Debt / Payables**:
  * Inventory purchased on credit (`payment_type = debt`) creates remaining debt for `BusCompany`.
  * `BusCompanyPayment` entries reduce company debt via treasury/vault accounts.
* **Receivables**:
  * Bookings paid partially or unpaid leave `total_price - paid_amount` as customer debt.

---

## 4. Operational Dependencies
```
Account (Chart of Accounts)
   ↓
BusCompany (Supplier Account)
   ↓
BusInventory (Route, Date, Total Tickets, Cost, Price, Debt/Prepaid)
   ↓
Customer (Client Account)
   ↓
BusBooking (Inventory lock, Quantity, Pricing, Multi-currency, Profit)
   ↓
BusPayment / TreasuryTransaction (Customer Payout/Liquidity)
   ↓
BusRefundRequest (Cancellation, Penalty, Ledger Reversal)
```

---

## 5. Existing Test Suite Coverage
* `tests/Feature/Bus/` (25+ test classes covering BookingCreation, BookingPayment, BookingCancellation, Concurrency, MultiCurrency, SoftDelete, Refund, Deadlock, etc.)
* `tests/scripts/bus_full_module_e2e.php`
* `tests/scripts/bus_deep_concurrency_e2e.php`
* `tests/scripts/bus_module_accounting_audit.php`
