# BUS OPERATIONAL TEST DATA PLAN

This plan outlines the strategy, prerequisites, hierarchy, and validation rules for generating operational test data to support full E2E lifecycle and golden flow testing in the Bus Module.

## 1. Test Data Generation Strategy

We follow a 4-level hierarchy to ensure realistic transactions without bypassing application business rules or financial guards:

* **Level 1 (Existing Safe Test Records)**: Reuse valid pre-existing system accounts, liquidity vaults (`Account::getModuleVault('bus')`), and test users where applicable.
* **Level 2 (Factories & Seeders)**: Use Eloquent factories (`BusCompanyFactory`, `BusInventoryFactory`, `BusBookingFactory`) for generating isolated master datasets during unit tests.
* **Level 3 (Application API & Services - PREFERRED)**: Use high-level domain services (`BusCompanyService`, `BusInventoryService`, `BusBookingService`, `BusRefundService`) or HTTP APIs for operational data creation to trigger all observers, journal transfers, multi-currency conversions, and inventory locks.
* **Level 4 (Direct DB Insertion)**: Strictly avoided for business transactions; used only for seeding initial environment parameters if required.

---

## 2. Required Prerequisites & Entity Hierarchy

| Entity | Required Fields | Creation Method | Dependencies | Purpose / Why Needed |
| --- | --- | --- | --- | --- |
| **User (Admin)** | `name`, `email`, `password` | `User::firstOrCreate` | None | Authenticated actor for admin endpoints (`pay-debt`, `cancel`, `refund`). |
| **Office Vault Account** | `name`, `type='cashbox'`, `module_type='office'`, `is_module_vault=true` | `Account::getModuleVault('bus')` | None | Target liquidity account for collecting customer cash/bank payments. |
| **Expense Clearing Account** | `code`, `name`, `type='expense'` | `LedgerClearingAccounts` | None | Contra clearing account for inventory cost posting. |
| **Bus Company** | `name`, `phone`, `address` | `BusCompanyService::createCompany` | Admin User | Supplier operator entity. Automatically creates supplier `Account`. |
| **Bus Inventory (Deferred)** | `company_id`, `route`, `travel_date`, `total_tickets`, `cost_per_ticket`, `selling_price`, `payment_type='deferred'` | `BusInventoryService::createInventory` | BusCompany | Ticket allocation on credit; creates supplier debt. |
| **Bus Inventory (Cash)** | `company_id`, `route`, `travel_date`, `total_tickets`, `cost_per_ticket`, `selling_price`, `payment_type='cash'`, `account_id` | `BusInventoryService::createInventory` | BusCompany, Office Vault | Ticket allocation paid up-front from vault. |
| **Customer** | `full_name`, `phone`, `type='individual'` | `Customer::create` | None | Purchaser passenger. Automatically creates Customer AR `Account` on booking. |
| **Bus Booking** | `inventory_id`, `customer_id`, `quantity` | `BusBookingService::createBooking` | BusInventory, Customer | Ticket reservation. Decrements tickets, creates Customer AR sale, posts cost clearing. |
| **Bus Payment** | `booking_id`, `amount`, `payment_method`, `account_id` | `BusBookingService::payBooking` | BusBooking, Office Vault | Customer cash collection. Transfers cash from AR account to Vault, updates status to paid. |
| **Bus Refund Request** | `bus_booking_id`, `cancellation_fee`, `destination='agency_treasury'`, `treasury_id` | `BusRefundService::createRefundRequest` | BusBooking (Paid), Treasury | Cancellation & refund workflow. |
| **Company Debt Payment** | `company_id`, `amount`, `account_id` | `BusCompanyService::payCompanyDebt` | BusCompany, Office Vault | Supplier debt settlement. Reduces supplier payable balance. |

---

## 3. Data Validation & Verification Protocol

Before executing any lifecycle phase, every created record MUST satisfy:
1. Primary ID > 0 and successfully retrieved via `find()`.
2. Linked ledger accounts (`account_id`) exist and match expected types (`supplier`, `customer`, `cashbox`).
3. Balances and remaining debts match exact formulas:
   - Inventory remaining debt = `total_cost - amount_paid`
   - Booking total price = `quantity * selling_price`
   - Booking profit = `(selling_price - cost_per_ticket) * quantity`
4. Available tickets in inventory = `total_tickets - sum(active_booking_quantities)`.
