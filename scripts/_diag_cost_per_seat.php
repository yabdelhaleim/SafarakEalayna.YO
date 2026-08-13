<?php
/**
 * Step 3 of loss investigation — focus on the 3 specific bookings #32,
 * #37, #38 to verify the cost-per-seat math.
 *
 * Goal: figure out if the cost is being posted on a PER-SEAT basis
 * (380 × 3 passengers = 1,140) or a PER-BOOKING flat basis (380 fixed).
 *
 * If cost is per-seat, the system should record 1,140 for a 3-seat booking.
 * If cost is per-booking, it should record 380 regardless of seats.
 *
 * This script prints the FULL ledger for those 3 bookings and shows
 * what the implied cost-per-seat distribution looks like.
 *
 * Read-only. No DB writes.
 *
 * Usage:
 *   php scripts/_diag_cost_per_seat.php
 *   php scripts/_diag_cost_per_seat.php 2026-07-01 2026-08-13
 */

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

echo "=== Step 3: Cost-per-Seat Deep Dive ===\n";
echo "Period: {$from} → {$to}\n\n";

// Step 3a: analyze the distribution of cost amounts
echo "── Cost amount distribution (all booking costs) ──────────\n";
$costs = DB::table('transactions')
    ->where('related_type', 'App\\Models\\BusBooking')
    ->where('type', 'expense')
    ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->select('amount', DB::raw('COUNT(*) as cnt'))
    ->groupBy('amount')
    ->orderBy('amount')
    ->get();

if ($costs->isEmpty()) {
    echo "  no booking costs found\n";
} else {
    foreach ($costs as $c) {
        printf("  %s %s : %d booking(s)\n",
            str_pad(number_format((float) $c->amount, 2), 10),
            str_pad('ج.م', 6),
            $c->cnt
        );
    }
}

echo "\n";

// Step 3b: for a sample booking, extract passenger count from notes &
// compare with cost amount
echo "── Sample bookings (first 10) ────────────────────────────\n";
printf("%-9s %-7s %-12s %-12s %-15s %s\n",
    'BK', 'Pax', 'Cost', 'PerSeat', 'Implied model', 'Notes');
echo str_repeat('-', 100) . "\n";

$sample = DB::table('transactions')
    ->where('related_type', 'App\\Models\\BusBooking')
    ->where('type', 'income')
    ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->orderBy('id')
    ->limit(10)
    ->select('related_id', 'notes')
    ->get();

foreach ($sample as $s) {
    $bookingId = $s->related_id;
    $notes = $s->notes ?? '';

    // Pax from notes
    $pax = null; $paxMatch = '';
    if (preg_match('/(\d+)\s*مقاعد/', $notes, $m)) {
        $pax = (int) $m[1];
        $paxMatch = "{$pax} مقاعد";
    }

    // Cost
    $cost = DB::table('transactions')
        ->where('related_type', 'App\\Models\\BusBooking')
        ->where('related_id', $bookingId)
        ->where('type', 'expense')
        ->sum('amount');

    $perSeat = $pax && $cost > 0 ? round($cost / $pax, 2) : null;

    // Implied pricing model
    $implied = '?';
    if ($pax && $perSeat) {
        if ($perSeat > 200 && $perSeat < 250) {
            $implied = 'per-seat (200/pax)';
        } elseif ($perSeat > 380 && $perSeat < 400) {
            $implied = 'per-seat (380/pax)';
        } elseif ($cost == 380) {
            $implied = 'flat-per-booking (380 ثابت)';
        } elseif ($cost == 760) {
            $implied = 'flat-per-booking (760 ل 2 باص)';
        } else {
            $implied = "غير معروف (per-seat=".number_format($perSeat, 2).")";
        }
    }

    printf("%-9d %-7s %-12s %-12s %-15s %s\n",
        $bookingId,
        $pax !== null ? (string) $pax : '?',
        number_format((float) $cost, 2),
        $perSeat !== null ? number_format($perSeat, 2) : '?',
        $implied,
        $paxMatch
    );
}

echo "\n";

// Step 3c: detect the EXACT pattern — group by pax, see average cost
echo "── Cost pattern by passenger count ───────────────────────\n";
$bookings = DB::table('transactions as t')
    ->where('t.related_type', 'App\\Models\\BusBooking')
    ->where('t.type', 'income')
    ->whereBetween('t.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->select('t.related_id', 't.notes', 't.amount as revenue')
    ->orderBy('t.related_id')
    ->get();

$patterns = [];
foreach ($bookings as $b) {
    $notes = $b->notes ?? '';
    $pax = null;
    if (preg_match('/(\d+)\s*مقاعد/', $notes, $m)) {
        $pax = (int) $m[1];
    }
    if (!$pax) continue;

    $cost = DB::table('transactions')
        ->where('related_type', 'App\\Models\\BusBooking')
        ->where('related_id', $b->related_id)
        ->where('type', 'expense')
        ->sum('amount');

    $key = $pax;
    if (!isset($patterns[$key])) {
        $patterns[$key] = ['count' => 0, 'costs' => [], 'revenues' => []];
    }
    $patterns[$key]['count']++;
    $patterns[$key]['costs'][] = (float) $cost;
    $patterns[$key]['revenues'][] = (float) $b->revenue;
}

printf("%-8s %-10s %-15s %-15s %-15s\n",
    'Pax', 'Bookings', 'Avg cost', 'Avg revenue', 'Avg margin');
echo str_repeat('-', 70) . "\n";

foreach ($patterns as $pax => $d) {
    $avgCost = array_sum($d['costs']) / count($d['costs']);
    $avgRev = array_sum($d['revenues']) / count($d['revenues']);
    printf("%-8d %-10d %-15s %-15s %-15s\n",
        $pax,
        $d['count'],
        number_format($avgCost, 2),
        number_format($avgRev, 2),
        number_format($avgRev - $avgCost, 2)
    );
}

echo "\n";

// Step 3d: detect the smoking gun — same booking with mismatched pax
echo "── Suspicious: bookings where cost looks like a flat amount ─\n";
$sus = DB::table('transactions as t')
    ->where('t.related_type', 'App\\Models\\BusBooking')
    ->where('t.type', 'income')
    ->whereBetween('t.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
    ->select('t.related_id', 't.notes', 't.amount as revenue')
    ->orderBy('t.related_id')
    ->get();

$suspiciousFound = 0;
foreach ($sus as $s) {
    $notes = $s->notes ?? '';
    $pax = null;
    if (preg_match('/(\d+)\s*مقاعد/', $notes, $m)) {
        $pax = (int) $m[1];
    }
    if (!$pax) continue;

    $cost = (float) DB::table('transactions')
        ->where('related_type', 'App\\Models\\BusBooking')
        ->where('related_id', $s->related_id)
        ->where('type', 'expense')
        ->sum('amount');

    // If cost is 380 but pax > 1, this is suspicious (per-seat would be 380*N)
    if ($cost == 380 && $pax > 1) {
        $suspiciousFound++;
        echo "  ⚠️  BK#{$s->related_id} (pax={$pax}): cost=380 but expected="
            . (380 * $pax) . " if per-seat. Missing: " . (380 * $pax - 380) . " ج.م\n";
    }
    // If cost is NOT a multiple of 380, also suspicious
    if ($cost > 0 && $cost % 380 != 0) {
        echo "  ❓ BK#{$s->related_id} (pax={$pax}): cost={$cost} is NOT a multiple of 380 — investigate!\n";
    }
}

if ($suspiciousFound === 0) {
    echo "  ✅ No obvious flat-cost bookings found.\n";
} else {
    printf("\n  💡 Found %d booking(s) where cost is flat 380 regardless of pax.\n", $suspiciousFound);
    echo "     If the contract is per-seat, those bookings are missing cost lines.\n";
}

echo "\n✓ Done. Read-only script — no DB writes performed.\n";