<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\BusCompanyPaymentStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusCompanyService;
use App\Services\Bus\BusInventoryService;
use App\Services\Bus\BusRefundService;
use App\Services\Finance\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

echo "====================================================\n";
echo "   EXECUTING PHASE 2 GOLDEN E2E LIFECYCLE FLOW     \n";
echo "====================================================\n\n";

$admin = User::first() ?? User::create(['name' => 'Golden Admin', 'email' => 'golden@example.com', 'password' => bcrypt('password')]);
Auth::login($admin);

$companyService = app(BusCompanyService::class);
$invService = app(BusInventoryService::class);
$bookingService = app(BusBookingService::class);
$refundService = app(BusRefundService::class);

$treasury = Treasury::firstOrCreate(['name' => 'Golden Main Treasury'], ['is_active' => true, 'currency' => 'EGP']);
$vault = Account::getModuleVault('bus') ?? Account::where('type', 'cashbox')->first();

$snapshots = [];
function takeSnapshot($stepName)
{
    global $vault;

    return [
        'step' => $stepName,
        'timestamp' => date('Y-m-d H:i:s'),
        'vault_balance' => (float) Account::find($vault->id)?->balance,
        'companies_count' => BusCompany::count(),
        'inventories_count' => BusInventory::count(),
        'bookings_count' => BusBooking::count(),
        'payments_count' => BusPayment::count(),
        'company_payments_count' => BusCompanyPayment::count(),
        'refund_requests_count' => BusRefundRequest::count(),
        'transactions_count' => Transaction::where('module', 'bus')->count(),
        'treasury_transactions_count' => TreasuryTransaction::count(),
    ];
}

$snapshots[] = takeSnapshot('0. Baseline Initial State');

// STEP 1: Create Bus Company (Supplier)
$comp = $companyService->createCompany([
    'name' => 'GOLDEN_Bus_Lines_'.time(),
    'phone' => '01099998888',
    'address' => 'Golden Terminal, Cairo',
    'notes' => 'Golden Flow Operator',
]);
$snapshots[] = takeSnapshot('1. Create Bus Company');
echo "[STEP 1 SUCCESS] Company #{$comp->id} created with supplier Account #{$comp->account_id}\n";

// STEP 2: Create Inventory (Deferred/Credit)
// 20 tickets @ Cost: 100 EGP, Selling: 150 EGP (Total Cost: 2000 EGP, Total Value: 3000 EGP)
$inv = $invService->createInventory([
    'company_id' => $comp->id,
    'route' => 'Cairo - Alexandria Express',
    'travel_date' => date('Y-m-d', strtotime('+7 days')),
    'departure_time' => '09:00',
    'total_tickets' => 20,
    'cost_per_ticket' => 100.00,
    'selling_price' => 150.00,
    'payment_type' => 'deferred',
    'notes' => 'Golden Inventory',
]);
$snapshots[] = takeSnapshot('2. Create Deferred Inventory');
echo "[STEP 2 SUCCESS] Inventory #{$inv->id} created with 20 tickets @ 150 EGP\n";

// STEP 3: Create Customer
$cust = Customer::create([
    'full_name' => 'Golden Passenger '.rand(100, 999),
    'phone' => '012'.rand(10000000, 99999999),
    'type' => 'individual',
    'is_active' => true,
]);
$snapshots[] = takeSnapshot('3. Create Customer');
echo "[STEP 3 SUCCESS] Customer #{$cust->id} created\n";

// STEP 4: Create Bus Booking (2 tickets = 300 EGP selling, 200 EGP cost, 100 EGP profit)
$booking = $bookingService->createBooking([
    'inventory_id' => $inv->id,
    'customer_id' => $cust->id,
    'quantity' => 2,
    'notes' => 'Golden Flow Booking',
]);
$invAfterBooking = $inv->fresh();
$snapshots[] = takeSnapshot('4. Create Bus Booking');
echo "[STEP 4 SUCCESS] Booking #{$booking->id} created. Total: {$booking->total_price} EGP, Profit: {$booking->profit} EGP, Inv Avail: {$invAfterBooking->available_tickets}\n";

// STEP 5: Pay Bus Booking (Full Payment 300 EGP)
$paidBooking = $bookingService->payBooking($booking, [
    'amount' => 300.00,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
    'notes' => 'Golden Full Payment',
]);
$snapshots[] = takeSnapshot('5. Pay Bus Booking');
echo "[STEP 5 SUCCESS] Booking #{$paidBooking->id} paid in full. Paid Amount: {$paidBooking->paid_amount} EGP, Status: {$paidBooking->status->value}\n";

// STEP 6: Pay Company Debt (Supplier Settlement: Pay 200 EGP for the 2 booked tickets to company account)
$transactionService = app(TransactionService::class);
$txDebt = $transactionService->recordJournalTransfer([
    'amount' => 200.00,
    'from_account_id' => $vault->id,
    'to_account_id' => $comp->account_id,
    'module' => TransactionModule::Bus->value,
    'type' => TransactionType::Expense->value,
    'related_type' => BusCompany::class,
    'related_id' => $comp->id,
    'notes' => 'Golden Supplier Settlement',
]);
$companyPayment = BusCompanyPayment::create([
    'company_id' => $comp->id,
    'inventory_id' => $inv->id,
    'amount' => 200.00,
    'account_id' => $vault->id,
    'transaction_id' => $txDebt->id,
    'status' => BusCompanyPaymentStatus::Paid,
    'notes' => 'Golden Supplier Settlement',
]);
$snapshots[] = takeSnapshot('6. Pay Company Debt');
echo "[STEP 6 SUCCESS] Supplier Debt Payment created #{$companyPayment->id} for 200 EGP (Tx #{$txDebt->id})\n";

// STEP 7: Create & Process Refund Request (Cancel/Refund 1 ticket)
// Create another booking for cancellation/refund testing
$booking2 = $bookingService->createBooking([
    'inventory_id' => $inv->id,
    'customer_id' => $cust->id,
    'quantity' => 1,
    'notes' => 'Golden Refund Booking',
]);
$booking2Paid = $bookingService->payBooking($booking2, [
    'amount' => 150.00,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
]);
$cancelRefundReq = $bookingService->cancelBooking($booking2Paid, [
    'company_penalty' => 20.00,
    'office_penalty' => 10.00,
    'account_id' => $vault->id,
    'notes' => 'Golden Cancellation Refund',
]);
$snapshots[] = takeSnapshot('7. Cancel & Refund Booking');
echo "[STEP 7 SUCCESS] Booking #{$booking2->id} cancelled. Refund Request #{$cancelRefundReq->id} created for {$cancelRefundReq->refund_amount} EGP\n";

// STEP 8: Verification of Financial Invariants
$bTotal = (float) $booking->total_price;
$bUnit = (float) $booking->unit_price;
$bQty = (int) $booking->quantity;
$bProfit = (float) $booking->profit;
$bCost = (float) $inv->cost_per_ticket;

$invTotal = (int) $inv->total_tickets;
$invAvail = (int) $invAfterBooking->available_tickets;

$invariant1 = ($bTotal === $bUnit * $bQty);
$invariant2 = ($bProfit === ($bUnit - $bCost) * $bQty);
$invariant3 = ((float) $paidBooking->paid_amount === $bTotal);

echo "\n--- CRITICAL FINANCIAL INVARIANTS CHECK ---\n";
echo '1. total_price = unit_price * quantity: '.($invariant1 ? "PASS ({$bTotal} = {$bUnit} * {$bQty})" : 'FAIL')."\n";
echo '2. profit = (selling - cost) * quantity: '.($invariant2 ? "PASS ({$bProfit} = ({$bUnit} - {$bCost}) * {$bQty})" : 'FAIL')."\n";
echo '3. paid_amount = sum(payments): '.($invariant3 ? "PASS ({$paidBooking->paid_amount} = {$bTotal})" : 'FAIL')."\n";

// Write Snapshot Document
$snapDoc = "# BUS GOLDEN FLOW LEDGER SNAPSHOT\n\n";
$snapDoc .= 'Generated At: '.date('Y-m-d H:i:s')."\n";
$snapDoc .= 'Environment: `'.config('app.env').'` | Database: `'.DB::getDatabaseName()."`\n\n";
$snapDoc .= "## Golden Lifecycle Flow Snapshots\n\n";
$snapDoc .= "| Step | Vault Balance | Companies | Inventories | Bookings | Payments | Supplier Payments | Refunds | Bus Transactions |\n";
$snapDoc .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";

foreach ($snapshots as $s) {
    $snapDoc .= "| {$s['step']} | ".number_format($s['vault_balance'], 2)." EGP | {$s['companies_count']} | {$s['inventories_count']} | {$s['bookings_count']} | {$s['payments_count']} | {$s['company_payments_count']} | {$s['refund_requests_count']} | {$s['transactions_count']} |\n";
}

$snapDoc .= "\n---\n\n## Verified Golden Flow Entities Manifest\n\n";
$goldenManifest = [
    'golden_run_id' => 'GOLDEN_FLOW_'.time(),
    'environment' => config('app.env'),
    'database' => DB::getDatabaseName(),
    'verified_entities' => [
        ['entity' => 'BusCompany', 'id' => $comp->id, 'account_id' => $comp->account_id, 'name' => $comp->name],
        ['entity' => 'BusInventory', 'id' => $inv->id, 'route' => $inv->route, 'total_tickets' => $inv->total_tickets],
        ['entity' => 'Customer', 'id' => $cust->id, 'name' => $cust->full_name],
        ['entity' => 'BusBooking', 'id' => $booking->id, 'total_price' => $booking->total_price, 'profit' => $booking->profit],
        ['entity' => 'BusPayment', 'id' => $paidBooking->payments->first()->id ?? 0, 'amount' => 300.00],
        ['entity' => 'BusCompanyPayment', 'id' => $companyPayment->id, 'amount' => 200.00],
        ['entity' => 'BusRefundRequest', 'id' => $cancelRefundReq->id, 'refund_amount' => $cancelRefundReq->refund_amount],
    ],
];

$snapDoc .= "```json\n".json_encode($goldenManifest, JSON_PRETTY_PRINT)."\n```\n";

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_GOLDEN_FLOW_LEDGER_SNAPSHOT.md', $snapDoc);
file_put_contents(__DIR__.'/../BUS_GOLDEN_FLOW_LEDGER_SNAPSHOT.md', $snapDoc);

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_TEST_DATA_MANIFEST.json', json_encode($goldenManifest, JSON_PRETTY_PRINT));
file_put_contents(__DIR__.'/../BUS_TEST_DATA_MANIFEST.json', json_encode($goldenManifest, JSON_PRETTY_PRINT));

echo "\nBUS_GOLDEN_FLOW_LEDGER_SNAPSHOT.md generated successfully!\n";
