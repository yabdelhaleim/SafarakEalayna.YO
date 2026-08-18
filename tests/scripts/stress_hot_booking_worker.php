<?php

declare(strict_types=1);

/**
 * stress_hot_booking_worker.php — Hot Booking payment worker.
 * Spawned by stress_hot_booking.php.
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Tests\Stress\Support\StressSafetyAbort;
use Tests\Stress\Support\StressSafetyGuard;

$batchId = 0; $bookingId = 0; $amount = 1000.0;
foreach ($argv as $arg) {
    if (preg_match('/^--batch-id=(\d+)$/', $arg, $m)) $batchId = (int) $m[1];
    if (preg_match('/^--booking-id=(\d+)$/', $arg, $m)) $bookingId = (int) $m[1];
    if (preg_match('/^--amount=([\d.]+)$/', $arg, $m)) $amount = (float) $m[1];
}

try {
    StressSafetyGuard::assertSafeEnvironment(null);
} catch (StressSafetyAbort $e) {
    fwrite(STDERR, "WORKER {$batchId} SAFETY ABORT: ".$e->getMessage()."\n");
    exit(2);
}

mt_srand(20260814 + $batchId);
$accepted = 0; $rejected = 0; $deadlocks = 0;
$maxAttempts = 5;
for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    try {
        DB::transaction(function () use ($bookingId, $amount, &$accepted) {
            $booking = \App\Models\Bus\BusBooking::lockForUpdate()->find($bookingId);
            if (!$booking) throw new \RuntimeException('booking missing');
            $remaining = (float) $booking->total_price - (float) $booking->paid_amount;
            if ($remaining < $amount) throw new \RuntimeException('insufficient remaining');
            $booking->paid_amount = (float) $booking->paid_amount + $amount;
            if ($booking->paid_amount >= $booking->total_price - 0.001) {
                $booking->payment_status = 'paid';
                $booking->status = 'confirmed';
            } else {
                $booking->payment_status = 'partial';
            }
            $booking->save();
            // Record a BusPayment row
            $customer = \App\Models\Customer::find($booking->customer_id);
            $accountId = $customer->account_id ?? null;
            \App\Models\Bus\BusPayment::create([
                'booking_id' => $booking->id,
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $accountId,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'notes' => '[HOT-BOOK-PAY]',
                'created_by' => 1,
            ]);
            $accepted = 1;
        });
        break;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '1213') || str_contains($msg, 'Deadlock')) {
            $deadlocks++;
            usleep(100_000 * ($attempt + 1));
            continue;
        }
        $rejected = 1;
        break;
    }
}

fwrite(STDOUT, "METRICS ".json_encode([
    'accepted' => $accepted,
    'rejected' => $rejected,
    'deadlocks' => $deadlocks,
])."\n");
exit(0);
