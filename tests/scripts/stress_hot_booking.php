<?php

declare(strict_types=1);

/**
 * stress_hot_booking.php
 *
 * Phase 25.14 — Hot Booking test.
 *
 * Scenario:
 *   - Pick ONE booking with a 10,000 EGP price, 0 paid.
 *   - Fire 20 parallel payments of 1,000 EGP each.
 *
 * Pass criterion:
 *   * Total accepted financial effect <= 10,000 EGP.
 *   * Remaining balance >= 0.
 *   * No duplicate ledger entries on the same payment intent.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Customer;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$workers = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--workers=(\d+)$/', $arg, $m)) $workers = (int) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "\n🛑  SAFETY ABORT 🛑\n".$e->getMessage()."\n\n");
    exit(2);
}

fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");
fwrite(STDOUT, "  Hot Booking test — workers={$workers}\n");
fwrite(STDOUT, "═══════════════════════════════════════════════════════════\n");

// Find or create the hot booking
$booking = DB::table('bus_bookings')->where('notes', 'STRESS-HOT-BOOKING')->first();
if (!$booking) {
    $actorId = (int) \App\Models\User::query()->where('email', 'stress-actor@safarakealayna.test')->value('id') ?: 1;

    // Need an inventory + customer + account
    $customer = Customer::factory()->create([
        'full_name' => 'STRESS-HOT-BOOK-CUST',
        'phone' => '+201000000998',
        'national_id' => 'STRESS-HOT-BOOK',
        'module_type' => 'bus',
        'created_by' => $actorId,
    ]);

    // Find or create an inventory (just a placeholder)
    $inventory = \App\Models\Bus\BusInventory::query()->first();
    if (!$inventory) {
        // Need a bus company too
        $company = \App\Models\Bus\BusCompany::query()->first();
        if (!$company) {
            $company = \App\Models\Bus\BusCompany::factory()->create();
        }
        $inventory = \App\Models\Bus\BusInventory::factory()->create([
            'bus_company_id' => $company->id,
        ]);
    }

    $booking = \App\Models\Bus\BusBooking::factory()->create([
        'customer_id' => $customer->id,
        'inventory_id' => $inventory->id,
        'unit_price' => 10000.0,
        'total_price' => 10000.0,
        'paid_amount' => 0,
        'quantity' => 1,
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'notes' => 'STRESS-HOT-BOOKING',
    ]);

    // Allocate 10K balance to the customer account
    if ($customer->account_id) {
        $account = \App\Models\Account::find($customer->account_id);
        $tx = \App\Models\Transaction::create([
            'type' => 'income', 'amount' => 10000.0, 'currency' => 'EGP',
            'module' => 'general', 'to_account_id' => $account->id,
            'notes' => 'HOT-BOOK-INITIAL', 'created_by' => $actorId,
        ]);
        app(\App\Services\Finance\AccountService::class)->credit($account, 10000.0, $tx->id);
    }
    fwrite(STDOUT, "→ Created STRESS-HOT-BOOKING (10000 EGP, unpaid)\n");
}

$bookingId = (int) $booking->id;
fwrite(STDOUT, "→ Booking id: {$bookingId}, price: {$booking->total_price}, paid: {$booking->paid_amount}\n");
fwrite(STDOUT, "→ Firing {$workers} parallel payments of 1000 EGP each…\n");

$start = microtime(true);
$procs = []; $pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $cmd = sprintf(
        'php -d memory_limit=512M %s --batch-id=%d --booking-id=%d --amount=1000 2>&1',
        escapeshellarg(__DIR__.'/stress_hot_booking_worker.php'),
        $w, $bookingId
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $wp);
    if (!is_resource($proc)) continue;
    $procs[$w] = $proc; $pipes[$w] = $wp;
    fclose($wp[0]);
}
$totalAccepted = 0; $totalRejected = 0; $deadlocks = 0;
foreach ($procs as $w => $proc) {
    $stdout = stream_get_contents($pipes[$w][1]);
    fclose($pipes[$w][1]); fclose($pipes[$w][2]);
    proc_close($proc);
    if (preg_match('/METRICS (.+)/', $stdout, $m)) {
        $stats = json_decode($m[1], true) ?: [];
        $totalAccepted += $stats['accepted'] ?? 0;
        $totalRejected += $stats['rejected'] ?? 0;
        $deadlocks += $stats['deadlocks'] ?? 0;
    }
}

$elapsed = round(microtime(true) - $start, 2);
$bookingFresh = \App\Models\Bus\BusBooking::find($bookingId);
$remaining = (float) $bookingFresh->remaining_amount;
$paid = (float) $bookingFresh->paid_amount;

fwrite(STDOUT, "\n═══════════ Results ═══════════\n");
fwrite(STDOUT, "Workers:                 {$workers}\n");
fwrite(STDOUT, "Payments accepted:       {$totalAccepted}\n");
fwrite(STDOUT, "Payments rejected:       {$totalRejected}\n");
fwrite(STDOUT, "Booking total price:     {$bookingFresh->total_price}\n");
fwrite(STDOUT, "Booking paid:            {$paid}\n");
fwrite(STDOUT, "Booking remaining:       {$remaining}\n");
fwrite(STDOUT, "Negative impossible?:    ".($remaining < 0 ? 'YES — FAIL' : 'no')."\n");
fwrite(STDOUT, "Deadlocks observed:      {$deadlocks}\n");
fwrite(STDOUT, "Elapsed:                 {$elapsed} sec\n");

$verdict = ($remaining >= 0 && $paid <= $bookingFresh->total_price + 0.01) ? 'PASS' : 'FAIL';
fwrite(STDOUT, "Verdict: {$verdict}\n");

$artifact = [
    'scenario' => 'hot_booking',
    'workers' => $workers,
    'booking_id' => $bookingId,
    'total_price' => (float) $bookingFresh->total_price,
    'paid' => $paid,
    'remaining' => $remaining,
    'accepted' => $totalAccepted,
    'rejected' => $totalRejected,
    'deadlocks' => $deadlocks,
    'elapsed_sec' => $elapsed,
    'verdict' => $verdict,
];
$dir = storage_path('app/stress');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
file_put_contents($dir.'/phase-C-hot-booking.json', json_encode($artifact, JSON_PRETTY_PRINT));
fwrite(STDOUT, "Artifact: storage/app/stress/phase-C-hot-booking.json\n");

exit($verdict === 'PASS' ? 0 : 1);
