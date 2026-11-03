<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Read-Only Historical Refunds Audit Script
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Scans all historical refund requests and flight refunds.
 * Identifies:
 *   1. Requests where automatic cash drain occurred without customer payout request.
 *   2. Incomplete revenue reversal (where cancellation fee was omitted from fee revenue).
 *   3. Missing supplier leg tracking (unrecorded supplier refund / credit).
 *   4. Difference between legacy recorded balances and correct dual-leg balances.
 *
 * STRICTLY READ-ONLY: Never modifies or deletes any historical records.
 *
 * Run: php scripts/audit_historical_refunds.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "           HISTORICAL REFUNDS AUDIT REPORT (READ-ONLY)                         \n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";

try {
    DB::connection()->getPdo();
} catch (\Throwable $e) {
    echo "Notice: Could not connect to default database (" . $e->getMessage() . ").\n";
    echo "Run this audit directly in Laragon / production shell where MySQL is accessible:\n";
    echo "  php scripts/audit_historical_refunds.php\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    exit(0);
}

$auditResults = [
    'scanned_at' => now()->toIso8601String(),
    'total_refund_requests' => 0,
    'legacy_flawed_requests' => 0,
    'clean_new_requests' => 0,
    'total_cancellation_fees_untracked' => 0.0,
    'total_auto_cash_drained' => 0.0,
    'items' => [],
];

// If database table refund_requests exists, query it
if (! \Illuminate\Support\Facades\Schema::hasTable('refund_requests')) {
    echo "Table 'refund_requests' does not exist in current connection.\n";
    exit(0);
}

$requests = RefundRequest::with(['booking', 'treasury'])->get();
$auditResults['total_refund_requests'] = $requests->count();

echo "Found {$requests->count()} refund requests in database.\n\n";

$headers = sprintf(
    "%-6s | %-12s | %-10s | %-10s | %-10s | %-12s | %-12s | %-15s\n",
    "ID", "Booking #", "Orig Amount", "Fee", "Net Refund", "Auto Cashbox", "Supplier Cost", "Status / Issues"
);
echo $headers;
echo str_repeat("─", 95) . "\n";

foreach ($requests as $req) {
    $issues = [];
    $booking = $req->booking;
    $bookingNumber = $booking ? $booking->booking_number : 'N/A';
    $cost = $booking ? (float)($booking->purchase_price ?: $booking->cost) : 0.0;
    $origAmount = (float)$req->original_amount;
    $fee = (float)$req->cancellation_fee;
    $netRefund = (float)$req->refund_amount;

    $isLegacy = false;

    // Issue 1: Legacy row without supplier leg metadata
    if ($req->supplier_cost === null && $req->supplier_refund_amount === null) {
        $isLegacy = true;
        $issues[] = "Missing supplier leg (legacy schema)";
    }

    // Issue 2: Automatic cashbox drain in Step C of legacy RefundService
    $autoCashDrain = 0.0;
    if ($req->destination === 'agency_treasury' && $req->status === 'processed') {
        $treasuryTx = TreasuryTransaction::where('refund_request_id', $req->id)
            ->where('transaction_type', 'refund')
            ->first();
        if ($treasuryTx) {
            $autoCashDrain = (float)$treasuryTx->amount;
            $auditResults['total_auto_cash_drained'] += $autoCashDrain;
            $issues[] = "Automatic cash drain ({$autoCashDrain} EGP)";
        }
    }

    // Issue 3: Cancellation fee not credited to Fee Revenue
    if ($fee > 0 && $isLegacy) {
        $auditResults['total_cancellation_fees_untracked'] += $fee;
        $issues[] = "Fee not in Fee Revenue ({$fee} EGP)";
    }

    // Issue 4: Customer credit not tracked
    if ($req->customer_credit_amount === null || ($isLegacy && $req->customer_credit_amount == 0 && $autoCashDrain == 0)) {
        $issues[] = "Customer credit untracked";
    }

    if (!empty($issues)) {
        $auditResults['legacy_flawed_requests']++;
    } else {
        $auditResults['clean_new_requests']++;
    }

    $statusStr = empty($issues) ? 'CLEAN (New Logic)' : implode('; ', $issues);

    printf(
        "%-6d | %-12s | %-10.2f | %-10.2f | %-10.2f | %-12.2f | %-12.2f | %-15s\n",
        $req->id,
        substr($bookingNumber, 0, 12),
        $origAmount,
        $fee,
        $netRefund,
        $autoCashDrain,
        $cost,
        substr($statusStr, 0, 35)
    );

    $auditResults['items'][] = [
        'refund_request_id' => $req->id,
        'booking_number' => $bookingNumber,
        'original_amount' => $origAmount,
        'cancellation_fee' => $fee,
        'net_refund' => $netRefund,
        'supplier_cost' => $cost,
        'auto_cash_drain' => $autoCashDrain,
        'status' => $req->status,
        'issues' => $issues,
    ];
}

echo str_repeat("─", 95) . "\n";
echo "SUMMARY:\n";
echo "  Total Requests:                  {$auditResults['total_refund_requests']}\n";
echo "  Legacy Flawed Requests:          {$auditResults['legacy_flawed_requests']}\n";
echo "  Clean Requests (New Logic):      {$auditResults['clean_new_requests']}\n";
echo "  Untracked Cancellation Fees:     " . number_format($auditResults['total_cancellation_fees_untracked'], 2) . " EGP\n";
echo "  Total Automatic Cashbox Drained: " . number_format($auditResults['total_auto_cash_drained'], 2) . " EGP\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";

// Save JSON log
$jsonPath = storage_path('logs/historical_refunds_audit.json');
@file_put_contents($jsonPath, json_encode($auditResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Audit report saved to: {$jsonPath}\n";
