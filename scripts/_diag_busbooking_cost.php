<?php
/**
 * Step 2 of loss investigation — verify cost-per-booking math.
 *
 * For each BusBooking in the period, joins:
 *   - passengers count (from notes regex /or booking_passengers table)
 *   - revenue (sum of income transactions linked to booking)
 *   - cost (sum of expense transactions linked to booking)
 *   - per-booking profit = revenue - cost
 *   - per-seat implied cost and revenue
 *
 * Surfacing bookings where:
 *   - cost > revenue (every booking is a loss)
 *   - per-seat cost > per-seat revenue (charging less than cost)
 *   - cost is much higher than the typical 380/booking
 *
 * Read-only. No DB writes.
 *
 * Usage:
 *   php scripts/_diag_busbooking_cost.php
 *   php scripts/_diag_busbooking_cost.php 2026-07-01 2026-08-13
 */

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ─── Args ────────────────────────────────────────────────────────────
$argv = $argv; array_shift($argv);
if (count($argv) >= 2) {
    $from = $argv[0];
    $to = $argv[1];
} else {
    $from = '2026-07-01';
    $to = '2026-08-13';
}

echo "=== Step 2: BusBooking Cost vs Revenue ===\n";
echo "Period: {$from} → {$to}\n\n";

// Step 2a: aggregate transactions per booking
$rows = DB::table('transactions as t')
    ->where('t.related_type', 'App\\Models\\BusBooking')
    ->whereBetween('t.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->select(
        't.related_id as booking_id',
        DB::raw("SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as revenue"),
        DB::raw("SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as cost"),
        DB::raw("SUM(CASE WHEN t.type = 'refund' THEN t.amount ELSE 0 END) as refund"),
        DB::raw("SUM(CASE WHEN t.type = 'writeoff' THEN t.amount ELSE 0 END) as writeoff"),
        DB::raw('COUNT(*) as tx_count'),
        DB::raw('GROUP_CONCAT(DISTINCT t.module) as modules')
    )
    ->groupBy('t.related_id')
    ->orderBy(DB::raw('revenue - cost'), 'asc')
    ->get();

if ($rows->isEmpty()) {
    echo "❌ No bus bookings found in this period.\n";
    exit(0);
}

// Step 2b: pull passenger count per booking from booking tables
// First, let me discover the booking table structure
echo "── Passenger count discovery ─────────────────────────────────\n";
$tables = DB::select("SHOW TABLES LIKE '%booking%'");
foreach ($tables as $t) {
    foreach ((array) $t as $name) {
        echo "  table: {$name}\n";
    }
}

// Try to find a passenger count column
$passengerCounts = [];
try {
    // First try BusBooking.passengers
    $cols = DB::select("SHOW COLUMNS FROM bus_bookings");
    $hasPassengers = false;
    foreach ($cols as $c) {
        if ($c->Field === 'passengers' || $c->Field === 'passengers_count' || $c->Field === 'seats') {
            $hasPassengers = true;
            echo "  bus_bookings.{$c->Field} ({$c->Type})\n";
        }
    }

    if ($hasPassengers) {
        $bookings = DB::table('bus_bookings')
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('id', 'passengers', 'seats')
            ->get();
        foreach ($bookings as $b) {
            $passengerCounts[$b->id] = (int) ($b->passengers ?? $b->seats ?? 0);
        }
    }
} catch (\Throwable $e) {
    echo "  ⚠️ Could not introspect bus_bookings: " . $e->getMessage() . "\n";
}

echo "\n";

// Step 2c: try to derive passenger count from notes (e.g. "4 مقاعد")
echo "── Per-booking ledger ─────────────────────────────────────\n";
printf("%-7s %-7s %-12s %-12s %-12s %-12s %-10s %s\n",
    'BK', 'Pax', 'Revenue', 'Cost', 'Refund', 'Margin', 'Txs', 'Notes');
echo str_repeat('-', 100) . "\n";

$totalRevenue = 0; $totalCost = 0; $totalRefund = 0;
$impliedLowCount = 0; $impliedLossCount = 0;
$seatImplied = []; // [booking_id => 'cost_per_seat' => float, 'revenue_per_seat' => float]

foreach ($rows as $r) {
    $bookingId = $r->booking_id;
    $revenue = (float) $r->revenue;
    $cost = (float) $r->cost;
    $refund = (float) $r->refund;
    $margin = $revenue - $cost - $refund;

    $totalRevenue += $revenue;
    $totalCost += $cost;
    $totalRefund += $refund;

    // Get booking notes to infer passenger count
    $notesRow = DB::table('transactions')
        ->where('related_type', 'App\\Models\\BusBooking')
        ->where('related_id', $bookingId)
        ->where('type', 'income')
        ->orderBy('id')
        ->first();
    $notes = $notesRow->notes ?? '';

    // Try to extract passenger count from notes "X مقاعد"
    $pax = null;
    if (preg_match('/(\d+)\s*مقاعد/', $notes, $m)) {
        $pax = (int) $m[1];
    } elseif (isset($passengerCounts[$bookingId])) {
        $pax = $passengerCounts[$bookingId];
    }

    // Sanity check: typical cost per seating
    $costPerSeat = $pax ? round($cost / $pax, 2) : null;
    $revenuePerSeat = $pax ? round($revenue / $pax, 2) : null;

    if ($pax && $revenuePerSeat && $costPerSeat > $revenuePerSeat) {
        $impliedLowCount++;
    }
    if ($margin < 0) {
        $impliedLossCount++;
    }

    $paxStr = $pax !== null ? (string) $pax : '?';
    $cPS = $costPerSeat !== null ? "{$costPerSeat}/seat" : '—';
    $rPS = $revenuePerSeat !== null ? "{$revenuePerSeat}/seat" : '—';

    $marker = $margin < 0 ? '❌' : ($margin < 50 ? '⚠️' : '✅');

    printf("%s %-5d %-7s %-12s %-12s %-12s %-12s %-10s %s\n",
        $marker,
        $bookingId,
        $paxStr,
        number_format($revenue, 2),
        number_format($cost, 2),
        number_format($refund, 2),
        number_format($margin, 2),
        $r->tx_count,
        mb_substr($notes, 0, 50)
    );

    $seatImplied[$bookingId] = [
        'pax' => $pax,
        'cost_per_seat' => $costPerSeat,
        'revenue_per_seat' => $revenuePerSeat,
    ];
}

echo "\n";

echo "── Summary ───────────────────────────────────────────────\n";
printf("Total revenue : %s\n", number_format($totalRevenue, 2));
printf("Total cost    : %s\n", number_format($totalCost, 2));
printf("Total refund  : %s\n", number_format($totalRefund, 2));
printf("Net margin    : %s\n", number_format($totalRevenue - $totalCost - $totalRefund, 2));
printf("Bookings in loss : %d / %d\n", $impliedLossCount, $rows->count());
printf("Bookings with cost > revenue per seat : %d\n", $impliedLowCount);

echo "\n";

// Step 2d: distribution of cost per seat
$buckets = ['0-100' => 0, '100-200' => 0, '200-300' => 0, '300-400' => 0, '400-500' => 0, '500-1000' => 0, '1000+' => 0];
$revenueBuckets = ['0-100' => 0, '100-200' => 0, '200-300' => 0, '300-400' => 0, '400-500' => 0, '500-1000' => 0, '1000+' => 0];
foreach ($seatImplied as $b) {
    if ($b['cost_per_seat'] === null) continue;
    $v = $b['cost_per_seat'];
    if ($v < 100) $buckets['0-100']++;
    elseif ($v < 200) $buckets['100-200']++;
    elseif ($v < 300) $buckets['200-300']++;
    elseif ($v < 400) $buckets['300-400']++;
    elseif ($v < 500) $buckets['400-500']++;
    elseif ($v < 1000) $buckets['500-1000']++;
    else $buckets['1000+']++;
    if ($b['revenue_per_seat'] !== null) {
        $v = $b['revenue_per_seat'];
        if ($v < 100) $revenueBuckets['0-100']++;
        elseif ($v < 200) $revenueBuckets['100-200']++;
        elseif ($v < 300) $revenueBuckets['200-300']++;
        elseif ($v < 400) $revenueBuckets['300-400']++;
        elseif ($v < 500) $revenueBuckets['400-500']++;
        elseif ($v < 1000) $revenueBuckets['500-1000']++;
        else $revenueBuckets['1000+']++;
    }
}

echo "── Cost per seat distribution ─────────────────────────────\n";
foreach ($buckets as $label => $count) {
    if ($count > 0) {
        printf("  %-12s : %d booking(s)\n", $label, $count);
    }
}

echo "\n── Revenue per seat distribution ─────────────────────────\n";
foreach ($revenueBuckets as $label => $count) {
    if ($count > 0) {
        printf("  %-12s : %d booking(s)\n", $label, $count);
    }
}

echo "\n✓ Done. Read-only script — no DB writes performed.\n";