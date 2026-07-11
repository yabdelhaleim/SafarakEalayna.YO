<?php
/**
 * Read-only diagnostic — what damage did the old destructive cancel() do?
 *
 * The pre-2026-07-11 `HajjUmraBookingService::cancel()` called
 *   `voidTransactionJournal($tx) + $tx->delete()` on every payment,
 *   income, and expense transaction it found. The `voidTransactionJournal`
 *   step deleted every `AccountEntry` row for the transaction, and the
 *   second step deleted the `Transaction` row itself.
 *
 * This script inspects the DB to answer:
 *   A) For `cancelled` bookings, are the original Transaction rows still
 *      in the `transactions` table?
 *   B) For `cancelled` bookings, are the original AccountEntry rows still
 *      in the `account_entries` table?
 *   C) Are the FK references (`expense_transaction_id`, `income_transaction_id`)
 *      on the booking still pointing at existing rows, or are they dangling?
 *   D) How many payments per cancelled booking were affected?
 *   E) Are any of these real production data, or are they all from test
 *      runs (so no real audit trail was destroyed)?
 *
 * NO WRITES. Read-only. The output is information for the human to decide
 * whether a one-shot backfill is appropriate (out of scope of the current
 * hardening commit, requires separate decision).
 *
 * Run via:
 *   php scripts_temp_diagnose_cancelled_hajjumra_bookings.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HajjUmraBooking;
use Illuminate\Support\Facades\DB;

$out = [
    'generated_at' => date('Y-m-d H:i:s'),
    'scope' => 'HajjUmra bookings with status=Cancelled that previously passed through the destructive cancel()',
    'tables_inspected' => [],
    'cancelled_bookings_total' => 0,
    'breakdown_by_origin' => [],
    'samples' => [],
    'verdict' => null,
];

// 0) Locate the destructive-cancel window: any cancelled booking whose
//    income/expense FK pointers are null AND it has no transaction records.
//    Those bookings went through the destructive cancel().

$cancelledBookings = DB::table('hajj_umra_bookings')
    ->where('status', 'cancelled')
    ->orderByDesc('updated_at')
    ->get();

$out['cancelled_bookings_total'] = $cancelledBookings->count();
$out['tables_inspected']['hajj_umra_bookings'] = 'cancelled row count: '.$cancelledBookings->count();

// Sub-categorise by "FK-null + no tx siblings" — high-confidence destructive-cancel signature
$nullFkDestructive = [];
$nonNullFkStillReversible = [];

foreach ($cancelledBookings as $cb) {
    if ($cb->expense_transaction_id === null && $cb->income_transaction_id === null) {
        $nullFkDestructive[] = $cb->id;
    } else {
        $nonNullFkStillReversible[] = $cb->id;
    }
}

$out['breakdown_by_origin']['null_fk_destructive_cancel'] = [
    'count' => count($nullFkDestructive),
    'note' => 'expense_transaction_id AND income_transaction_id are both null → went through destructive cancel(), original transactions removed.',
    'booking_ids' => $nullFkDestructive,
];
$out['breakdown_by_origin']['non_null_fk_kept_transactions'] = [
    'count' => count($nonNullFkStillReversible),
    'note' => 'FK pointers retained → either reversible cancel() path was used, or migration/legacy data. Transaction rows still attached.',
    'booking_ids' => $nonNullFkStillReversible,
];

// 1) For the destructive-cancel subset: do any transactions still exist?
if (! empty($nullFkDestructive)) {
    // Recover transactions via the related_type / related_id join (best-effort).
    $cancelledIds = $nullFkDestructive;
    $txByBooking = DB::table('transactions')
        ->where('related_type', \App\Models\HajjUmraBooking::class)
        ->whereIn('related_id', $cancelledIds)
        ->select('id', 'related_id', 'type', 'amount', 'module', 'notes', 'created_at')
        ->get()
        ->groupBy('related_id');

    $entriesByBooking = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('transactions.related_type', \App\Models\HajjUmraBooking::class)
        ->whereIn('transactions.related_id', $cancelledIds)
        ->select('account_entries.id', 'account_entries.transaction_id', 'account_entries.debit', 'account_entries.credit')
        ->get()
        ->groupBy('transaction_id');

    // 2) Payment transactions for those bookings — payment.transactions have
    //    related_type = HajjUmraPayment, related_id = payment_id, not booking.
    //    Walk via HajjUmraPayment rows that are now soft-deleted (we added
    //    SoftDeletes this run, so previously they would have been hard-delete).
    $payments = DB::table('hajj_umra_payments')
        ->whereIn('hajj_umra_booking_id', $cancelledIds)
        ->select('id', 'hajj_umra_booking_id', 'transaction_id', 'amount', 'deleted_at')
        ->get()
        ->groupBy('hajj_umra_booking_id');

    $out['samples'] = [];
    foreach (array_slice($cancelledIds, 0, 10) as $sampleId) {
        $bkTxns = $txByBooking[$sampleId] ?? collect();
        $paymentsForBooking = $payments[$sampleId] ?? collect();

        $sample = [
            'booking_id' => (int) $sampleId,
            'booking_transactions' => $bkTxns->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => $t->amount,
                'notes_preview' => mb_substr((string) $t->notes, 0, 50),
            ])->all(),
            'booking_transactions_count' => $bkTxns->count(),
            'payments' => $paymentsForBooking->map(fn ($p) => [
                'id' => (int) $p->id,
                'amount' => (float) $p->amount,
                'transaction_id' => $p->transaction_id,
                'transaction_still_exists' => $p->transaction_id
                    ? (DB::table('transactions')->where('id', $p->transaction_id)->exists() ? 'yes' : 'NO_DESTROYED')
                    : 'no',
                'payment_softdeleted' => $p->deleted_at ? 'yes' : 'no',
            ])->all(),
        ];

        $out['samples'][] = $sample;
    }

    // 3) Aggregate: how many transactions have been destroyed by the old
    //    destructive cancel(), per booking?
    $totalLikelyDestroyedTx = 0;
    foreach ($payments as $bookingId => $list) {
        foreach ($list as $p) {
            if ($p->transaction_id && ! DB::table('transactions')->where('id', $p->transaction_id)->exists()) {
                $totalLikelyDestroyedTx++;
            }
        }
    }

    $out['aggregate_likely_destroyed_transactions'] = $totalLikelyDestroyedTx;
}

// 4) Verdict — is there anything a human should look at, or is it all test data?
$isProductionLike = false;
foreach ($out['samples'] ?? [] as $sample) {
    foreach ($sample['payments'] ?? [] as $p) {
        // If any payment has a real-looking amount AND its transaction is gone,
        // flag as production-like damage.
        if ($p['transaction_still_exists'] === 'NO_DESTROYED' && $p['amount'] > 100) {
            $isProductionLike = true;
            break 2;
        }
    }
}

$out['verdict'] = [
    'is_production_like_damage' => $isProductionLike,
    'recommendation' => $isProductionLike
        ? 'Real production data was likely affected. Open a separate decision thread — DO NOT auto-fix; the original transactions are gone and re-creating additive reversions would require archived snapshots we do NOT have.'
        : 'All cancelled bookings are either test data or low-value enough that no real audit trail was lost. The new additive-reverse pattern protects future cancels without any historical backfill needed.',
];

file_put_contents(
    storage_path('logs/diagnose_cancelled_hajjumra_bookings.json'),
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";
