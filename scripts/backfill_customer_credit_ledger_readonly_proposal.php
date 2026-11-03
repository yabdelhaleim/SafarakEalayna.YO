<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * READ-ONLY ANALYSIS SCRIPT — DO NOT EXECUTE IN PRODUCTION WITHOUT:
 *   1. Full database backup confirmed.
 *   2. DBA review and sign-off.
 *   3. Running in a staging environment first.
 *   4. Scheduling during a low-traffic maintenance window.
 *
 * Purpose: Identify historical records (old refunds & overpayments) that have
 *          credit balances not yet reflected in the customer_credit_ledger table
 *          (introduced in migration 2026_11_02_140000).
 *          This script performs SELECT-only queries and outputs a dry-run summary.
 *
 * Usage:
 *   php scripts/backfill_customer_credit_ledger_readonly_proposal.php [--verbose]
 *
 * Output:
 *   - Summary counts and total amounts for each category.
 *   - Per-record detail in --verbose mode.
 *   - No writes to any table.
 * ════════════════════════════════════════════════════════════════════════════
 */

// ── Bootstrap ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$verbose = in_array('--verbose', $argv ?? [], true);

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  CUSTOMER CREDIT LEDGER — HISTORICAL BACKFILL ANALYSIS\n";
echo "  READ-ONLY DRY-RUN — " . now()->format('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$grandTotalCount  = 0;
$grandTotalAmount = 0.0;

// ════════════════════════════════════════════════════════════════════════════
// CATEGORY 1: Processed refunds with customer_credit_amount > 0
//             but no matching customer_credit_ledger entry.
// ════════════════════════════════════════════════════════════════════════════
echo "CATEGORY 1: Processed refunds with orphaned customer credit\n";
echo "──────────────────────────────────────────────────────────────\n";

$orphanedRefunds = DB::table('refund_requests as rr')
    ->leftJoin('customer_credit_ledger as ccl', function ($join) {
        $join->on('ccl.source_type', DB::raw("'refund'"))
             ->on('ccl.source_id', '=', 'rr.id');
    })
    ->where('rr.status', 'processed')
    ->where('rr.customer_credit_amount', '>', 0.005)
    ->whereNull('rr.deleted_at')
    ->whereNull('ccl.id')   // no ledger entry exists
    ->select([
        'rr.id',
        'rr.flight_booking_id',
        'rr.customer_credit_amount',
        'rr.refund_currency',
        'rr.processed_at',
        'rr.company_id',
    ])
    ->orderBy('rr.processed_at')
    ->get();

$cat1Count  = $orphanedRefunds->count();
$cat1Amount = $orphanedRefunds->sum('customer_credit_amount');

echo "  Found: {$cat1Count} refund requests\n";
echo "  Total customer_credit_amount: " . number_format($cat1Amount, 2) . " (mixed currencies)\n";

if ($verbose && $cat1Count > 0) {
    echo "\n  Detail:\n";
    foreach ($orphanedRefunds as $row) {
        echo sprintf(
            "    refund_request #%d | booking #%d | credit: %.2f %s | processed: %s%s\n",
            $row->id,
            $row->flight_booking_id,
            $row->customer_credit_amount,
            $row->refund_currency ?? 'EGP',
            $row->processed_at,
            $row->company_id ? " | company_id: {$row->company_id}" : ''
        );
    }
}

$grandTotalCount  += $cat1Count;
$grandTotalAmount += $cat1Amount;

// ════════════════════════════════════════════════════════════════════════════
// CATEGORY 2: Old customer_credit_disbursements (status='completed') that
//             have no matching customer_credit_ledger DEBIT entry.
//             These are disbursements made before the new ledger existed.
// ════════════════════════════════════════════════════════════════════════════
echo "\nCATEGORY 2: Old disbursements with no ledger DEBIT entry\n";
echo "──────────────────────────────────────────────────────────────\n";

// Check if customer_credit_disbursements table exists first
$disbTableExists = DB::getSchemaBuilder()->hasTable('customer_credit_disbursements');

if (! $disbTableExists) {
    echo "  [SKIP] Table customer_credit_disbursements does not exist.\n";
    $cat2Count  = 0;
    $cat2Amount = 0.0;
} else {
    $orphanedDisbursements = DB::table('customer_credit_disbursements as ccd')
        ->leftJoin('customer_credit_ledger as ccl', function ($join) {
            $join->on('ccl.source_type', DB::raw("'disbursement'"))
                 ->on('ccl.source_id', '=', 'ccd.id');
        })
        ->where('ccd.status', 'completed')
        ->whereNull('ccl.id')
        ->select([
            'ccd.id',
            'ccd.refund_request_id',
            'ccd.amount',
            'ccd.disbursement_type',
            'ccd.created_at',
        ])
        ->orderBy('ccd.created_at')
        ->get();

    $cat2Count  = $orphanedDisbursements->count();
    $cat2Amount = $orphanedDisbursements->sum('amount');

    echo "  Found: {$cat2Count} disbursements\n";
    echo "  Total amount: " . number_format($cat2Amount, 2) . " EGP\n";

    if ($verbose && $cat2Count > 0) {
        echo "\n  Detail:\n";
        foreach ($orphanedDisbursements as $row) {
            echo sprintf(
                "    disbursement #%d | refund_req #%d | amount: %.2f | type: %s | date: %s\n",
                $row->id,
                $row->refund_request_id,
                $row->amount,
                $row->disbursement_type,
                $row->created_at
            );
        }
    }

    $grandTotalCount  += $cat2Count;
    $grandTotalAmount += $cat2Amount;
}

// ════════════════════════════════════════════════════════════════════════════
// CATEGORY 3: Historic overpayments in flight_payments.
//             A payment where amount > booking.selling_price at the time,
//             and no customer_credit_ledger entry with source_type='overpayment'
//             exists for that payment.
// ════════════════════════════════════════════════════════════════════════════
echo "\nCATEGORY 3: Historic overpayments (payment amount > booking selling_price)\n";
echo "──────────────────────────────────────────────────────────────\n";

/*
 * Note: flight_payments.amount stores the EGP-equivalent capped at selling_price
 * (per Case 3 fix). To detect OLD overpayments (before the fix), we look for:
 *   SUM(fp.amount) > fb.selling_price on the same booking,
 *   with no ledger entry of source_type='overpayment' for that booking.
 */
$overpaidBookings = DB::table('flight_bookings as fb')
    ->join('flight_payments as fp', 'fp.flight_booking_id', '=', 'fb.id')
    ->leftJoin('customer_credit_ledger as ccl', function ($join) {
        $join->on('ccl.source_type', DB::raw("'overpayment'"))
             ->on('ccl.customer_id', '=', 'fb.customer_id');
    })
    ->whereNull('fb.deleted_at')
    ->whereNull('fp.deleted_at')
    ->whereNull('ccl.id')
    ->groupBy('fb.id', 'fb.customer_id', 'fb.selling_price', 'fb.booking_number', 'fb.currency')
    ->havingRaw('SUM(fp.amount) > fb.selling_price + 0.005')
    ->select([
        'fb.id as booking_id',
        'fb.booking_number',
        'fb.customer_id',
        'fb.selling_price',
        'fb.currency',
        DB::raw('SUM(fp.amount) as total_paid'),
        DB::raw('SUM(fp.amount) - fb.selling_price as overpayment'),
    ])
    ->orderBy('fb.id')
    ->get();

$cat3Count  = $overpaidBookings->count();
$cat3Amount = $overpaidBookings->sum('overpayment');

echo "  Found: {$cat3Count} bookings with potential overpayment\n";
echo "  Total overpayment amount: " . number_format($cat3Amount, 2) . " EGP-equivalent\n";

if ($verbose && $cat3Count > 0) {
    echo "\n  Detail:\n";
    foreach ($overpaidBookings as $row) {
        echo sprintf(
            "    booking #%d (%s) | customer #%d | selling: %.2f | paid: %.2f | excess: %.2f %s\n",
            $row->booking_id,
            $row->booking_number,
            $row->customer_id,
            $row->selling_price,
            $row->total_paid,
            $row->overpayment,
            $row->currency
        );
    }
}

$grandTotalCount  += $cat3Count;
$grandTotalAmount += $cat3Amount;

// ════════════════════════════════════════════════════════════════════════════
// CATEGORY 4: Refunds with credit_ledger_entry_id IS NULL but
//             customer_credit_amount > 0 (may be partially processed old records)
// ════════════════════════════════════════════════════════════════════════════
echo "\nCATEGORY 4: Refunds with credit_ledger_entry_id=NULL but credit > 0\n";
echo "──────────────────────────────────────────────────────────────\n";

$ledgerLinkMissing = DB::table('refund_requests')
    ->where('status', 'processed')
    ->where('customer_credit_amount', '>', 0.005)
    ->whereNull('credit_ledger_entry_id')
    ->whereNull('deleted_at')
    ->select(['id', 'flight_booking_id', 'customer_credit_amount', 'refund_currency', 'processed_at'])
    ->orderBy('processed_at')
    ->get();

$cat4Count  = $ledgerLinkMissing->count();
$cat4Amount = $ledgerLinkMissing->sum('customer_credit_amount');

echo "  Found: {$cat4Count} records\n";
echo "  Total credit_amount: " . number_format($cat4Amount, 2) . "\n";

if ($verbose && $cat4Count > 0) {
    echo "\n  Detail:\n";
    foreach ($ledgerLinkMissing as $row) {
        echo sprintf(
            "    refund_request #%d | booking #%d | credit: %.2f %s | processed: %s\n",
            $row->id,
            $row->flight_booking_id,
            $row->customer_credit_amount,
            $row->refund_currency ?? 'EGP',
            $row->processed_at
        );
    }
}

$grandTotalCount  += $cat4Count;

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════════════
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  DRY-RUN SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Cat 1 — Orphaned refund credits:   {$cat1Count} records, " . number_format($cat1Amount, 2) . " EGP\n";
echo "  Cat 2 — Orphaned disbursements:    {$cat2Count} records, " . number_format($cat2Amount, 2) . " EGP\n";
echo "  Cat 3 — Historic overpayments:     {$cat3Count} bookings, " . number_format($cat3Amount, 2) . " EGP excess\n";
echo "  Cat 4 — Missing ledger link:       {$cat4Count} records\n";
echo "  ─────────────────────────────────────────────────────────────\n";
echo "  Grand total records to migrate:    {$grandTotalCount}\n";
echo "\n";
echo "  ⚠️  NO DATA WAS WRITTEN. This is a dry-run analysis only.\n";
echo "  ⚠️  To perform the actual backfill, a separate WRITE script\n";
echo "      must be created, reviewed, and approved by the DBA.\n";
echo "      Backfill order should be: Cat 1 → Cat 4 (link fix) → Cat 3.\n";
echo "      Cat 2 requires manual reconciliation per disbursement.\n";
echo "═══════════════════════════════════════════════════════════════\n\n";
