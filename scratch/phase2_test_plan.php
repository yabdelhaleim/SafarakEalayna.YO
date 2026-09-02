<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$plan = "# BUS OPERATIONAL TEST DATA PLAN\n\n";
$plan .= "This plan outlines the strategy, prerequisites, hierarchy, and validation rules for generating operational test data to support full E2E lifecycle and golden flow testing in the Bus Module.\n\n";

$plan .= "## 1. Test Data Generation Strategy\n\n";
$plan .= "We follow a 4-level hierarchy to ensure realistic transactions without bypassing application business rules or financial guards:\n\n";
$plan .= "* **Level 1 (Existing Safe Test Records)**: Reuse valid pre-existing system accounts, liquidity vaults (`Account::getModuleVault('bus')`), and test users where applicable.\n";
$plan .= "* **Level 2 (Factories & Seeders)**: Use Eloquent factories (`BusCompanyFactory`, `BusInventoryFactory`, `BusBookingFactory`) for generating isolated master datasets during unit tests.\n";
$plan .= "* **Level 3 (Application API & Services - PREFERRED)**: Use high-level domain services (`BusCompanyService`, `BusInventoryService`, `BusBookingService`, `BusRefundService`) or HTTP APIs for operational data creation to trigger all observers, journal transfers, multi-currency conversions, and inventory locks.\n";
$plan .= "* **Level 4 (Direct DB Insertion)**: Strictly avoided for business transactions; used only for seeding initial environment parameters if required.\n\n";

$plan .= "---\n\n## 2. Required Prerequisites & Entity Hierarchy\n\n";
$plan .= "| Entity | Required Fields | Creation Method | Dependencies | Purpose / Why Needed |\n";
$plan .= "| --- | --- | --- | --- | --- |\n";
$plan .= "| **User (Admin)** | `name`, `email`, `password` | `User::firstOrCreate` | None | Authenticated actor for admin endpoints (`pay-debt`, `cancel`, `refund`). |\n";
$plan .= "| **Office Vault Account** | `name`, `type='cashbox'`, `module_type='office'`, `is_module_vault=true` | `Account::getModuleVault('bus')` | None | Target liquidity account for collecting customer cash/bank payments. |\n";
$plan .= "| **Expense Clearing Account** | `code`, `name`, `type='expense'` | `LedgerClearingAccounts` | None | Contra clearing account for inventory cost posting. |\n";
$plan .= "| **Bus Company** | `name`, `phone`, `address` | `BusCompanyService::createCompany` | Admin User | Supplier operator entity. Automatically creates supplier `Account`. |\n";
$plan .= "| **Bus Inventory (Deferred)** | `company_id`, `route`, `travel_date`, `total_tickets`, `cost_per_ticket`, `selling_price`, `payment_type='deferred'` | `BusInventoryService::createInventory` | BusCompany | Ticket allocation on credit; creates supplier debt. |\n";
$plan .= "| **Bus Inventory (Cash)** | `company_id`, `route`, `travel_date`, `total_tickets`, `cost_per_ticket`, `selling_price`, `payment_type='cash'`, `account_id` | `BusInventoryService::createInventory` | BusCompany, Office Vault | Ticket allocation paid up-front from vault. |\n";
$plan .= "| **Customer** | `full_name`, `phone`, `type='individual'` | `Customer::create` | None | Purchaser passenger. Automatically creates Customer AR `Account` on booking. |\n";
$plan .= "| **Bus Booking** | `inventory_id`, `customer_id`, `quantity` | `BusBookingService::createBooking` | BusInventory, Customer | Ticket reservation. Decrements tickets, creates Customer AR sale, posts cost clearing. |\n";
$plan .= "| **Bus Payment** | `booking_id`, `amount`, `payment_method`, `account_id` | `BusBookingService::payBooking` | BusBooking, Office Vault | Customer cash collection. Transfers cash from AR account to Vault, updates status to paid. |\n";
$plan .= "| **Bus Refund Request** | `bus_booking_id`, `cancellation_fee`, `destination='agency_treasury'`, `treasury_id` | `BusRefundService::createRefundRequest` | BusBooking (Paid), Treasury | Cancellation & refund workflow. |\n";
$plan .= "| **Company Debt Payment** | `company_id`, `amount`, `account_id` | `BusCompanyService::payCompanyDebt` | BusCompany, Office Vault | Supplier debt settlement. Reduces supplier payable balance. |\n\n";

$plan .= "---\n\n## 3. Data Validation & Verification Protocol\n\n";
$plan .= "Before executing any lifecycle phase, every created record MUST satisfy:\n";
$plan .= "1. Primary ID > 0 and successfully retrieved via `find()`.\n";
$plan .= "2. Linked ledger accounts (`account_id`) exist and match expected types (`supplier`, `customer`, `cashbox`).\n";
$plan .= "3. Balances and remaining debts match exact formulas:\n";
$plan .= "   - Inventory remaining debt = `total_cost - amount_paid`\n";
$plan .= "   - Booking total price = `quantity * selling_price`\n";
$plan .= "   - Booking profit = `(selling_price - cost_per_ticket) * quantity`\n";
$plan .= "4. Available tickets in inventory = `total_tickets - sum(active_booking_quantities)`.\n";

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_OPERATIONAL_TEST_DATA_PLAN.md', $plan);
file_put_contents(__DIR__.'/../BUS_OPERATIONAL_TEST_DATA_PLAN.md', $plan);

echo "BUS_OPERATIONAL_TEST_DATA_PLAN.md generated successfully!\n";
