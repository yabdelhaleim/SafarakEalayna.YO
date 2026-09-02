<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Transaction;
use App\Models\TreasuryTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "=== PHASE 4: SAFETY GATE & BASELINE CAPTURE ===\n";
echo 'APP_ENV: '.config('app.env')."\n";
echo 'DB_CONNECTION: '.config('database.default')."\n";
$conn = config('database.default');
echo 'DB_HOST: '.config("database.connections.{$conn}.host")."\n";
echo 'DB_DATABASE: '.config("database.connections.{$conn}.database")."\n";
$db = DB::select('SELECT DATABASE() as db');
echo 'SELECT DATABASE(): '.($db[0]->db ?? 'N/A')."\n";

$isLocal = config('app.env') === 'local' || config('app.env') === 'testing';
$isLocalHost = in_array(config("database.connections.{$conn}.host"), ['127.0.0.1', 'localhost']);
if (! $isLocal || ! $isLocalHost) {
    echo "CRITICAL SAFETY FAILURE: NOT A LOCAL TEST ENVIRONMENT. STOPPING.\n";
    exit(1);
}

echo "\n[CONFIRMED] Local Test Database is Safe for Concurrency Testing.\n\n";

$baseline = [
    'captured_at' => date('Y-m-d H:i:s'),
    'environment' => config('app.env'),
    'database' => DB::getDatabaseName(),
    'counts' => [
        'bus_companies' => BusCompany::count(),
        'bus_inventories' => BusInventory::count(),
        'bus_bookings' => BusBooking::count(),
        'bus_payments' => BusPayment::count(),
        'bus_company_payments' => BusCompanyPayment::count(),
        'bus_refund_requests' => BusRefundRequest::count(),
        'accounts' => Account::count(),
        'bus_transactions' => Transaction::where('module', 'bus')->count(),
        'account_entries' => AccountEntry::count(),
        'treasury_transactions' => TreasuryTransaction::count(),
    ],
    'sums' => [
        'booking_total_price' => (float) BusBooking::where('status', '!=', 'cancelled')->sum('total_price'),
        'booking_paid_amount' => (float) BusBooking::where('status', '!=', 'cancelled')->sum('paid_amount'),
        'payment_rows_sum' => (float) BusPayment::sum('amount'),
        'company_payments_sum' => (float) BusCompanyPayment::sum('amount'),
        'refund_requests_sum' => (float) BusRefundRequest::where('status', 'processed')->sum('refund_amount'),
        'total_inventory_tickets' => (int) BusInventory::sum('total_tickets'),
        'available_inventory_tickets' => (int) BusInventory::sum('available_tickets'),
    ],
];

$doc = "# BUS PHASE 4 BASELINE REPORT\n\n";
$doc .= 'Captured At: `'.$baseline['captured_at'].'` | Environment: `'.$baseline['environment'].'` | Database: `'.$baseline['database']."`\n\n";

$doc .= "## 1. Initial Entity Counts\n\n";
$doc .= "| Entity / Table | Record Count |\n";
$doc .= "| --- | --- |\n";
foreach ($baseline['counts'] as $table => $count) {
    $doc .= "| `{$table}` | {$count} |\n";
}

$doc .= "\n## 2. Initial Financial & Inventory Metrics\n\n";
$doc .= "| Metric | Baseline Value |\n";
$doc .= "| --- | --- |\n";
foreach ($baseline['sums'] as $metric => $val) {
    $formatted = is_float($val) ? number_format($val, 2).' EGP' : $val;
    $doc .= '| `'.ucwords(str_replace('_', ' ', $metric))."` | {$formatted} |\n";
}

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_PHASE_4_BASELINE.md', $doc);
file_put_contents(__DIR__.'/../BUS_PHASE_4_BASELINE.md', $doc);

echo "BUS_PHASE_4_BASELINE.md generated successfully!\n";
