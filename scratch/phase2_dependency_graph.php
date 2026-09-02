<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$tables = [
    'users',
    'accounts',
    'customers',
    'bus_companies',
    'bus_inventories',
    'bus_bookings',
    'bus_payments',
    'bus_company_payments',
    'bus_refund_requests',
    'treasuries',
    'treasury_transactions',
    'transactions',
    'account_entries',
];

$graph = "# BUS ACTUAL DATABASE DEPENDENCY GRAPH\n\n";
$graph .= 'Database: `'.DB::getDatabaseName()."`\n";
$graph .= 'Environment: `'.config('app.env')."`\n\n";

$graph .= "## 1. Table Statistics & Row Counts\n\n";
$graph .= "| Table Name | Row Count | Soft Delete Column | Primary Key |\n";
$graph .= "| --- | --- | --- | --- |\n";

foreach ($tables as $t) {
    $count = DB::table($t)->count();
    $cols = DB::select("SHOW COLUMNS FROM `{$t}`");
    $hasSoftDelete = 'NO';
    $pk = 'id';
    foreach ($cols as $c) {
        if ($c->Field === 'deleted_at') {
            $hasSoftDelete = 'YES ('.$c->Type.')';
        }
        if ($c->Key === 'PRI') {
            $pk = $c->Field;
        }
    }
    $graph .= "| `{$t}` | {$count} | {$hasSoftDelete} | `{$pk}` |\n";
}

$graph .= "\n---\n\n## 2. Actual Database Foreign Key Relationships\n\n";
$fkQuery = "
SELECT 
    TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    REFERENCED_TABLE_SCHEMA = ?
    AND TABLE_NAME IN ('".implode("','", $tables)."')
ORDER BY TABLE_NAME, COLUMN_NAME
";
$fks = DB::select($fkQuery, [DB::getDatabaseName()]);

$graph .= "| Child Table | FK Column | Constraint | Parent Table | Parent Column |\n";
$graph .= "| --- | --- | --- | --- | --- |\n";
foreach ($fks as $fk) {
    $graph .= "| `{$fk->TABLE_NAME}` | `{$fk->COLUMN_NAME}` | `{$fk->CONSTRAINT_NAME}` | `{$fk->REFERENCED_TABLE_NAME}` | `{$fk->REFERENCED_COLUMN_NAME}` |\n";
}

$graph .= "\n---\n\n## 3. Bus Module Relationship Map (Mermaid Visual Graph)\n\n";
$graph .= "```mermaid\ngraph TD\n";
$graph .= "    User[\"User (Employee/Admin)\"]\n";
$graph .= "    Account[\"Account (Chart of Accounts / Liquidity / AR / AP)\"]\n";
$graph .= "    Customer[\"Customer (Passenger / Client)\"]\n";
$graph .= "    BusCompany[\"BusCompany (Transport Supplier)\"]\n";
$graph .= "    BusInventory[\"BusInventory (Route Batch / Trip Seats)\"]\n";
$graph .= "    BusBooking[\"BusBooking (Ticket Reservation / Sale)\"]\n";
$graph .= "    BusPayment[\"BusPayment (Customer Payment Collection)\"]\n";
$graph .= "    BusCompanyPayment[\"BusCompanyPayment (Supplier Debt Settlement)\"]\n";
$graph .= "    BusRefundRequest[\"BusRefundRequest (Cancellation / Refund Request)\"]\n";
$graph .= "    Treasury[\"Treasury (Vault / Cash Operations)\"]\n";
$graph .= "    TreasuryTransaction[\"TreasuryTransaction (Treasury Cash Movement)\"]\n";
$graph .= "    Transaction[\"Transaction (GL Journal Voucher Header)\"]\n";
$graph .= "    AccountEntry[\"AccountEntry (GL Double-Entry Debit/Credit Lines)\"]\n\n";

$graph .= "    Account --> BusCompany\n";
$graph .= "    Account --> Customer\n";
$graph .= "    User --> BusCompany\n";
$graph .= "    BusCompany --> BusInventory\n";
$graph .= "    Account --> BusInventory\n";
$graph .= "    User --> BusInventory\n";
$graph .= "    BusInventory --> BusBooking\n";
$graph .= "    Customer --> BusBooking\n";
$graph .= "    User --> BusBooking\n";
$graph .= "    Account --> BusBooking\n";
$graph .= "    BusBooking --> BusPayment\n";
$graph .= "    Account --> BusPayment\n";
$graph .= "    BusCompany --> BusCompanyPayment\n";
$graph .= "    BusInventory --> BusCompanyPayment\n";
$graph .= "    Account --> BusCompanyPayment\n";
$graph .= "    BusBooking --> BusRefundRequest\n";
$graph .= "    BusCompany --> BusRefundRequest\n";
$graph .= "    Treasury --> BusRefundRequest\n";
$graph .= "    Account --> BusRefundRequest\n";
$graph .= "    Transaction --> AccountEntry\n";
$graph .= "    Account --> AccountEntry\n";
$graph .= "    BusBooking --> TreasuryTransaction\n";
$graph .= "    Treasury --> TreasuryTransaction\n";
$graph .= "```\n\n";

$graph .= "---\n\n## 4. Entity Dependency Creation Hierarchy\n\n";
$graph .= "To execute a complete Bus transaction lifecycle from scratch, entities must be instantiated in this exact order:\n\n";
$graph .= "1. **System Prerequisite Level**: `User` (Admin / Operator), `Treasury` (Agency Cash Vault), `Account` (System Clearing & Liquidity Accounts)\n";
$graph .= "2. **Supplier Level**: `BusCompany` (linked to supplier payable `Account`)\n";
$graph .= "3. **Inventory Level**: `BusInventory` (linked to `BusCompany` and optional liquidity `Account` for cash purchases)\n";
$graph .= "4. **Customer Level**: `Customer` (linked to client receivable `Account`)\n";
$graph .= "5. **Booking Level**: `BusBooking` (locks seats in `BusInventory`, posts AR sale to `Account`, creates journal `Transaction` and `AccountEntry` lines)\n";
$graph .= "6. **Payment Level**: `BusPayment` (transfers cash from Customer AR `Account` to Vault `Account`, records `TreasuryTransaction`)\n";
$graph .= "7. **Refund & Cancellation Level**: `BusRefundRequest` (restores `BusInventory` tickets, reverses ledger entries, transfers cash from `Treasury` / `Account`)\n";
$graph .= "8. **Supplier Settlement Level**: `BusCompanyPayment` (pays `BusCompany` payable debt from Vault `Account`)\n";

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_ACTUAL_DATABASE_DEPENDENCY_GRAPH.md', $graph);
file_put_contents(__DIR__.'/../BUS_ACTUAL_DATABASE_DEPENDENCY_GRAPH.md', $graph);

echo "BUS_ACTUAL_DATABASE_DEPENDENCY_GRAPH.md generated successfully!\n";
