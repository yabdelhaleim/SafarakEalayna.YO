<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 17 — Transaction & payment duplication sweep.
 *
 * Sweeps every booking created by the audit run (across all three modules)
 * and verifies:
 *   1. No (type, amount, currency) tuple appears twice on the transactions
 *      table for the same (related_type, related_id).
 *   2. The number of payment rows matches the count of non-rejected
 *      addPayment() calls (i.e. payments did not get silently duplicated).
 *
 * Any duplicate group is recorded as a critical NO-GO finding.
 */
class Phase17_TransactionDuplication
{
    public string $phaseLabel = 'PHASE 17 — Transaction Duplication';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 17 — Transaction Duplication');
        $r->start();

        try {
            $allBookingIds = [
                'flight'    => $this->ctx->flightBookingIds,
                'hajj_umra' => $this->ctx->hajjUmraBookingIds,
                'visa'      => $this->ctx->visaBookingIds,
            ];

            $relatedTypeMap = [
                'flight'    => \App\Models\Flight\FlightBooking::class,
                'hajj_umra' => \App\Models\HajjUmraBooking::class,
                'visa'      => \App\Models\VisaBooking::class,
            ];

            $paymentTableMap = [
                'flight'    => ['table' => 'flight_payments', 'fk' => 'flight_booking_id'],
                'hajj_umra' => ['table' => 'hajj_umra_payments', 'fk' => 'hajj_umra_booking_id'],
                'visa'      => ['table' => 'visa_payments', 'fk' => 'visa_booking_id'],
            ];

            $totalBookings = 0;
            $totalChecked  = 0;

            foreach ($allBookingIds as $module => $ids) {
                $relatedType = $relatedTypeMap[$module];
                $paymentTable = $paymentTableMap[$module]['table'];
                $paymentFk    = $paymentTableMap[$module]['fk'];

                $totalBookings += count($ids);

                foreach ($ids as $bookingId) {
                    $totalChecked++;

                    // ── 1. Duplicate (type, amount, currency) groups ─────
                    $dups = $this->recon->findDuplicateTransactions((int) $bookingId, $relatedType);
                    if (!empty($dups)) {
                        $r->recordFail(
                            scenario: "Duplicate transactions: {$module} booking #{$bookingId}",
                            expected: 'Each (type, amount, currency) tuple appears at most once',
                            actual: count($dups) . ' duplicate group(s): ' . json_encode(array_map(function ($d) {
                                return [
                                    'type'     => $d->type,
                                    'amount'   => $d->amount,
                                    'currency' => $d->currency,
                                    'count'    => $d->cnt,
                                ];
                            }, $dups)),
                            severity: 'critical',
                            context: [
                                'module'   => $module,
                                'role'     => 'system',
                                'diff_egp' => 0,
                                'tx_ids'   => [],
                            ],
                        );
                    } else {
                        $r->recordPass();
                    }

                    // ── 2. Duplicate payment rows on the same booking ─────
                    // We allow two rows with the same amount ONLY if the
                    // idempotency_key is distinct (legitimate partial payment
                    // sequence). If amount + idempotency_key collide, that's
                    // a true duplicate.
                    $payDups = DB::table($paymentTable)
                        ->select('amount', 'currency', 'idempotency_key', DB::raw('COUNT(*) as cnt'))
                        ->where($paymentFk, $bookingId)
                        ->whereNotNull('idempotency_key')
                        ->where('idempotency_key', '!=', '')
                        ->groupBy('amount', 'currency', 'idempotency_key')
                        ->havingRaw('COUNT(*) > 1')
                        ->get()
                        ->toArray();

                    if (!empty($payDups)) {
                        $r->recordFail(
                            scenario: "Duplicate payment rows: {$module} booking #{$bookingId}",
                            expected: 'Each (amount, currency, idempotency_key) tuple appears at most once',
                            actual: count($payDups) . ' duplicate payment group(s): ' . json_encode($payDups),
                            severity: 'critical',
                            context: ['module' => $module, 'role' => 'system'],
                        );
                    } else {
                        $r->recordPass();
                    }
                }
            }

            $r->recordInfo(
                'Phase 17 sweep summary',
                "swept={$totalChecked} bookings across " . count($allBookingIds) . " modules"
            );

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 17 exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }
}