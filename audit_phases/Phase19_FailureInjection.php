<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\RefundService as FlightRefundService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 19 — Atomicity / failure-injection test.
 *
 * For every module, force exceptions at the most dangerous points and
 * verify that NO partial mutation leaks through:
 *   - payment with invalid account_id
 *   - payment with negative amount
 *   - payment with empty-string currency
 *   - payment > remaining_amount (overpayment)
 *   - double-payment with distinct idempotency_keys (should reject the
 *     second one once remaining is exhausted)
 *   - refund with insufficient cashbox balance (when feasible to set up)
 *
 * Each test:
 *   1. Snapshots booking.paid_amount + transaction count + account_entries
 *      sum before the call.
 *   2. Wraps the call in try/catch.
 *   3. After the exception, re-queries and asserts nothing leaked.
 *
 * A leak (any of the post-conditions not matching the pre-snapshot) is a
 * critical NO-GO finding.
 */
class Phase19_FailureInjection
{
    public string $phaseLabel = 'PHASE 19 — Failure Injection & Atomicity';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 19 — Failure Injection & Atomicity');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            // Use the AuditContext helper — it tries the canonical tourism
            // vault first, then falls back to any active office cashbox
            // with matching currency (e.g. WL_CASH_EGP id=162). The fallback
            // usage is itself recorded as a finding on $ctx->cashboxFallbackNotes.
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');
            if (!$cashbox) {
                $r->recordFail(
                    scenario: 'Phase 19 — cashbox resolution',
                    expected: 'cashbox resolved',
                    actual: 'no cashbox available',
                    severity: 'high',
                    context: ['module' => 'cross'],
                );
                $r->finish();
                return $r;
            }
            $this->ctx->trackAccount($cashbox->id);

            $this->exerciseFlight($r, $cashbox->id);
            $this->exerciseHajjUmra($r, $cashbox->id);
            $this->exerciseVisa($r, $cashbox->id);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 19 exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    protected function resolveCashbox(): ?Account
    {
        // Backwards-compat alias — delegates to AuditContext::resolveCashbox()
        // so any old caller still resolves through the same fallback chain.
        return $this->ctx->resolveCashbox('flights', 'EGP');
    }

    /**
     * Snapshot the bookkeeping state we care about before / after a failure.
     * @return array{paid:float, tx:int, ae_count:int, ae_sum:float}
     */
    protected function snapshotBooking(string $table, int $bookingId): array
    {
        $booking = DB::table($table)->where('id', $bookingId)->first();
        $paid = $booking ? (float) ($booking->paid_amount ?? 0) : 0.0;

        $txIds = DB::table('transactions')
            ->whereIn('related_type', [
                \App\Models\Flight\FlightBooking::class,
                \App\Models\HajjUmraBooking::class,
                \App\Models\VisaBooking::class,
                \App\Models\Flight\FlightPayment::class,
                \App\Models\HajjUmraPayment::class,
                \App\Models\VisaPayment::class,
                \App\Models\Flight\FlightRefund::class,
                \App\Models\RefundRequest::class,
            ])
            ->where('related_id', $bookingId)
            ->pluck('id')
            ->toArray();

        $aeCount = empty($txIds) ? 0 : (int) DB::table('account_entries')->whereIn('transaction_id', $txIds)->count();
        $aeSum   = empty($txIds) ? 0.0 : (float) DB::table('account_entries')
            ->whereIn('transaction_id', $txIds)
            ->sum(DB::raw('CASE WHEN type = "credit" THEN amount WHEN type = "debit" THEN -amount ELSE 0 END'));

        return ['paid' => $paid, 'tx' => count($txIds), 'ae_count' => $aeCount, 'ae_sum' => $aeSum];
    }

    protected function assertNoLeak(
        PhaseResult $r,
        string $module,
        string $scenario,
        array $before,
        array $after
    ): void {
        $paidDrift = abs($before['paid'] - $after['paid']);
        $txDelta   = $after['tx'] - $before['tx'];
        $aeDelta   = $after['ae_count'] - $before['ae_count'];
        $aeSumDrift = abs($before['ae_sum'] - $after['ae_sum']);

        if ($paidDrift > AuditReconciliation::EPSILON_DISPLAY || $txDelta > 0 || $aeDelta > 0 || $aeSumDrift > AuditReconciliation::EPSILON_DISPLAY) {
            $r->recordFail(
                scenario: "Atomicity leak: {$module} — {$scenario}",
                expected: sprintf('paid=%.4f tx=%d ae=%d ae_sum=%.4f', $before['paid'], $before['tx'], $before['ae_count'], $before['ae_sum']),
                actual: sprintf('paid=%.4f (drift %.4f), tx+%d, ae+%d (drift %.4f)', $after['paid'], $paidDrift, $txDelta, $aeDelta, $aeSumDrift),
                severity: 'critical',
                context: [
                    'module'    => $module,
                    'diff_egp'  => max($paidDrift, $aeSumDrift),
                    'root_cause'=> 'Exception was thrown but a partial mutation leaked through DB::transaction rollback',
                ],
            );
        } else {
            $r->recordPass();
        }
    }

    protected function exerciseFlight(PhaseResult $r, int $cashboxId): void
    {
        $module = 'flight';
        $svc = app(FlightBookingService::class);

        try {
            $booking = $this->ctx->createFlightBooking([
                'selling_price'  => 1000.00,
                'purchase_price' => 800.00,
                'status'         => 'pending',
            ]);

            // Test 1: invalid account_id
            $before = $this->snapshotBooking('flight_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 100,
                    'payment_method'  => 'cash',
                    'account_id'      => 999999,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-flight-invalid-acc-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} invalid account_id — no exception thrown",
                    expected: 'exception thrown',
                    actual: 'addPayment returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'invalid_account', $before, $after);
            }

            // Test 2: negative amount
            $before = $this->snapshotBooking('flight_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => -100,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-flight-neg-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} negative amount — no exception thrown",
                    expected: 'exception thrown',
                    actual: 'addPayment returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'negative_amount', $before, $after);
            }

            // Test 3: empty currency
            $before = $this->snapshotBooking('flight_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 100,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => '',
                    'idempotency_key' => 'p19-flight-empty-ccy-' . $booking->id,
                ]);
                // Some implementations may coerce empty-string to EGP; treat as info if so.
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                if ($after['paid'] > $before['paid'] + AuditReconciliation::EPSILON_DISPLAY) {
                    $r->recordFail(
                        scenario: "Failure injection: {$module} empty currency",
                        expected: 'rejected or no mutation',
                        actual: 'paid mutated to ' . $after['paid'],
                        severity: 'high',
                        context: ['module' => $module, 'diff_egp' => $after['paid'] - $before['paid']],
                    );
                } else {
                    $r->recordPass();
                }
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'empty_currency', $before, $after);
            }

            // Test 4: overpayment (2000 > 1000 selling_price)
            $before = $this->snapshotBooking('flight_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 2000,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-flight-over-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} overpayment — no exception thrown",
                    expected: 'exception thrown',
                    actual: 'addPayment returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'overpayment', $before, $after);
            }

            // Test 5: double-payment with DIFFERENT idempotency keys, second
            // exceeds remaining → second must reject.
            $beforeFirst = $this->snapshotBooking('flight_bookings', $booking->id);
            $svc->addPayment($booking, [
                'amount'          => 1000,
                'payment_method'  => 'cash',
                'account_id'      => $cashboxId,
                'currency'        => 'EGP',
                'idempotency_key' => 'p19-flight-full-' . $booking->id,
            ]);
            $afterFirst = $this->snapshotBooking('flight_bookings', $booking->id);

            try {
                $svc->addPayment($booking, [
                    'amount'          => 100,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-flight-second-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} overpayment-after-full — no exception thrown",
                    expected: 'exception thrown',
                    actual: 'addPayment returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('flight_bookings', $booking->id);
                // No further mutation should have happened after $afterFirst.
                $this->assertNoLeak($r, $module, 'double_payment_distinct_key', $afterFirst, $after);
            }

            // Reference $beforeFirst so the analyzer doesn't complain about
            // unused snapshots.
            unset($beforeFirst);

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase 19 flight exercise — uncaught exception',
                expected: 'all sub-tests to run',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    protected function exerciseHajjUmra(PhaseResult $r, int $cashboxId): void
    {
        $module = 'hajj_umra';
        $svc = app(HajjUmraBookingService::class);

        try {
            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => 5000.00,
                'purchase_price' => 4000.00,
                'status'         => 'pending',
            ]);

            $before = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 100,
                    'payment_method'  => 'cash',
                    'account_id'      => 999999,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-hajj-invalid-acc-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} invalid account_id — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'invalid_account', $before, $after);
            }

            $before = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => -50,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-hajj-neg-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} negative amount — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'negative_amount', $before, $after);
            }

            $before = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 10000,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-hajj-over-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} overpayment — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('hajj_umra_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'overpayment', $before, $after);
            }

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase 19 hajj exercise — uncaught exception',
                expected: 'all sub-tests to run',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    protected function exerciseVisa(PhaseResult $r, int $cashboxId): void
    {
        $module = 'visa';
        $svc = app(VisaBookingService::class);

        try {
            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => 1000.00,
                'purchase_price' => 700.00,
                'service_fee'    => 100.00,
                'total_amount'   => 1100.00,
                'status'         => 'pending',
            ]);

            $before = $this->snapshotBooking('visa_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 100,
                    'payment_method'  => 'cash',
                    'account_id'      => 999999,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-visa-invalid-acc-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} invalid account_id — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('visa_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'invalid_account', $before, $after);
            }

            $before = $this->snapshotBooking('visa_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => -10,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-visa-neg-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} negative amount — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('visa_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'negative_amount', $before, $after);
            }

            $before = $this->snapshotBooking('visa_bookings', $booking->id);
            try {
                $svc->addPayment($booking, [
                    'amount'          => 5000,
                    'payment_method'  => 'cash',
                    'account_id'      => $cashboxId,
                    'currency'        => 'EGP',
                    'idempotency_key' => 'p19-visa-over-' . $booking->id,
                ]);
                $r->recordFail(
                    scenario: "Failure injection: {$module} overpayment — no exception",
                    expected: 'exception',
                    actual: 'returned normally',
                    severity: 'medium',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $after = $this->snapshotBooking('visa_bookings', $booking->id);
                $this->assertNoLeak($r, $module, 'overpayment', $before, $after);
            }

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase 19 visa exercise — uncaught exception',
                expected: 'all sub-tests to run',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }
}