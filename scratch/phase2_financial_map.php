<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;

$output = "# BUS FINANCIAL ACCOUNT MAP\n\n";
$output .= "This document maps the exact financial account structure, division rules, liquidity rules, and double-entry ledger contracts for the **Bus Module**.\n\n";

$output .= "## 1. Division & Module Classification Contract\n\n";
$output .= "* **Division**: `office` (The Bus Module belongs to the **Office** division alongside Fawry, Online Payments, and Wallet Transfers).\n";
$output .= "* **Module Key**: `bus` (`TransactionModule::Bus->value`)\n";
$output .= "* **Base Operating Currency**: `EGP` (All ledger accounts and financial reports normalize to EGP).\n\n";

$output .= "---\n\n## 2. Enums and Status Values Map\n\n";
$output .= "| Entity | Field | Allowed Values | Meaning / Application Rule | Source |\n";
$output .= "| --- | --- | --- | --- | --- |\n";
$output .= "| `bus_bookings` | `status` | `pending`, `paid`, `cancelled`, `refunded`, `partially_refunded` | Booking lifecycle status. Checked in cancellation, payment, refund workflows. | `App\\Enums\\BusBookingStatus` |\n";
$output .= "| `bus_bookings` | `payment_status` | `pending`, `partial`, `paid`, `overdue` | Payment progress status. Automatically updated on payment collection. | `App\\Enums\\BusPaymentStatus` |\n";
$output .= "| `bus_inventories` | `payment_type` | `cash`, `deferred` | Supplier inventory purchase model. `cash` pays supplier immediately; `deferred` records company debt. | `App\\Enums\\BusInventoryPaymentType` |\n";
$output .= "| `bus_payments` | `payment_method` | `cash`, `bank_transfer`, `cash_wallet`, `postal_transfer`, `office_safe`, `office_drawer` | Customer payment channel. Validated prior to database persistence. | `BusBookingService::payBooking` |\n";
$output .= "| `bus_company_payments` | `status` | `pending`, `paid` | Status of supplier debt settlement payment. | `App\\Enums\\BusCompanyPaymentStatus` |\n";
$output .= "| `bus_refund_requests` | `status` | `pending`, `processed`, `rejected` | Refund request workflow state. | `BusRefundService` |\n";
$output .= "| `bus_refund_requests` | `destination` | `agency_treasury`, `customer_wallet`, `bank_account` | Destination of refunded funds. | `BusRefundService` |\n";

$output .= "\n---\n\n## 3. Account Types & Classification\n\n";
$output .= "| Account Category | AccountType Enum | Allowed Divisions (`module_type`) | Purpose / Usage in Bus Module |\n";
$output .= "| --- | --- | --- | --- |\n";
$output .= "| **Supplier Payable** | `supplier` | N/A | Dedicated ledger account for each `BusCompany` (`bus_companies.account_id`). Holds supplier debt when inventories are purchased on credit (`deferred`). |\n";
$output .= "| **Customer AR (Receivable)** | `customer` | N/A | Dedicated ledger account for each `Customer` (`customers.account_id`). Created dynamically in booking currency; holds customer debt for unpaid/partially paid bookings. |\n";
$output .= "| **Liquidity (Vault / Safe)** | `cashbox` | `office` | Office division vault/drawer/safe account used to collect customer payments or pay supplier cash inventories. Must have `is_module_vault = true` or `module_type = 'office'`. |\n";
$output .= "| **Liquidity (Bank Account)** | `bank` | `office` | Bank account used for bank transfer payments or supplier debt settlement. |\n";
$output .= "| **Liquidity (Wallet)** | `wallet` | `office` | Electronic wallet account used for digital customer payments. |\n";
$output .= "| **Expense Clearing Contra** | `expense` | N/A | Contra expense clearing account (`LedgerClearingAccounts::expenseContraIdForModule('bus')`). Offsets inventory cost on sale to avoid profit inflation. |\n";

$output .= "\n---\n\n## 4. Liquidity Account Validation Rules (`BusLiquidityAccount`)\n\n";
$output .= "To ensure strict separation of funds and prevent invalid accounting postings, all cash/bank payment methods in the Bus Module validate that the target `account_id` meets these strict rules:\n";
$output .= "1. Account must exist and have `is_active = true`.\n";
$output .= "2. Account `type` must be a valid liquidity type (`cashbox`, `wallet`, `bank`).\n";
$output .= "3. Account `module_type` MUST be set to division `'office'` (or `module = 'bus'`). Liquidity accounts under `'tourism'` division are strictly rejected.\n";
$output .= "4. Fallback mechanism: If `account_id` is omitted, the system resolves `Account::getModuleVault('bus')` which locates the designated office vault.\n";

$output .= "\n---\n\n## 5. Active Database Accounts Distribution Summary\n\n";

$accounts = Account::select('type', 'module_type', DB::raw('count(*) as count'), DB::raw('sum(balance) as total_balance'))
    ->groupBy('type', 'module_type')
    ->get();

$output .= "| AccountType (`type`) | Division (`module_type`) | Active Count | Total Balance (EGP) |\n";
$output .= "| --- | --- | --- | --- |\n";
foreach ($accounts as $acc) {
    $t = is_object($acc->type) ? $acc->type->value : $acc->type;
    $mt = $acc->module_type ?: 'unspecified';
    $output .= "| `{$t}` | `{$mt}` | {$acc->count} | ".number_format((float) $acc->total_balance, 2)." |\n";
}

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_FINANCIAL_ACCOUNT_MAP.md', $output);
file_put_contents(__DIR__.'/../BUS_FINANCIAL_ACCOUNT_MAP.md', $output);

echo "BUS_FINANCIAL_ACCOUNT_MAP.md generated successfully!\n";
