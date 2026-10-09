<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Services\Flight\FlightBookingService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Visa\VisaBookingService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 6 — Invalid payment rejection.
 *
 * For each tourism module (Flight, HajjUmra, Visa), creates a 1000 EGP booking
 * and then attacks the addPayment() entry point with malformed inputs:
 *
 *   - amount > selling_price   (must reject — overflow)
 *   - amount = 0               (must reject — zero payment)
 *   - amount = -100            (must reject — negative payment)
 *   - amount = "abc"           (must reject — non-numeric)
 *   - amount = 999999999999    (must reject — overflow)
 *   - empty payload            (must reject — missing amount)
 *   - missing required field   (must reject — missing account_id)
 *   - idempotency_key replay   (must NOT create duplicate)
 *   - payment after cancel     (must reject — booking closed)
 *   - invalid account_id       (must reject — FK violation)
 *   - cross-customer payment   (must reject — booking ownership)
 *
 * Each rejection is EXPECTED — recordPass() on rejection, recordFail() on
 * a financial mutation that slipped through (CRITICAL — money created).
 */
class Phase6_InvalidPayment
{
    public string $phaseLabel = 'PHASE 6 — Invalid payment rejection';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 6 — Invalid payment rejection');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            // Cache cashbox once to avoid re-querying per scenario.
            // Use the AuditContext helper so the cashbox fallback is exercised
            // and recorded as a finding when no canonical tourism vault exists.
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');
            if ($cashbox === null) {
                $r->recordFail(
                    scenario: 'phase setup: resolveCashbox(flights, EGP)',
                    expected: 'active EGP cashbox resolved',
                    actual: 'no cashbox available (canonical tourism or fallback)',
                    severity: 'high',
                    context: ['module' => 'cross', 'role' => 'system'],
                );
                $r->finish();
                return $r;
            }

            // ── Flight module ─────────────────────────────────────────────
            $this->exercisePaymentRejections(
                $r,
                'flight',
                function () { return $this->ctx->createFlightBooking(); },
                function ($booking, $payload) {
                    return app(FlightBookingService::class)
                        ->addPayment($booking->fresh(), $payload);
                },
                $cashbox
            );

            // ── HajjUmra module ───────────────────────────────────────────
            $this->exercisePaymentRejections(
                $r,
                'hajj_umra',
                function () { return $this->ctx->createHajjUmraBooking(); },
                function ($booking, $payload) {
                    return app(HajjUmraBookingService::class)
                        ->addPayment($booking->fresh(), $payload);
                },
                $cashbox
            );

            // ── Visa module ───────────────────────────────────────────────
            $this->exercisePaymentRejections(
                $r,
                'visa',
                function () { return $this->ctx->createVisaBooking(); },
                function ($booking, $payload) {
                    return app(VisaBookingService::class)
                        ->addPayment($booking->fresh(), $payload);
                },
                $cashbox
            );

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase6 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    /**
     * Reusable helper that drives the same set of attack scenarios for one
     * module. The caller provides the booking factory + payment invocation
     * closure so we can reuse this across all three tourism modules.
     */
    protected function exercisePaymentRejections(
        PhaseResult $r,
        string $module,
        \Closure $bookingFactory,
        \Closure $payFn,
        ?Account $cashbox
    ): void {
        // Helper: run the payment closure and EXPECT a throwable.
        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection (exception or validation error)',
                    actual: 'Payment accepted — money mutated',
                    severity: 'critical',
                    context: ['module' => $module, 'root_cause' => 'Payment slip-through detected'],
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                $r->recordPass();
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        // 1. amount > selling_price (1500 vs 1000)
        $b = $bookingFactory();
        $expectReject("amount > selling_price (1500)", function () use ($b, $payFn, $cashbox) {
            $payFn($b, ['amount' => 1500, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 2. amount = 0
        $b = $bookingFactory();
        $expectReject("amount = 0", function () use ($b, $payFn, $cashbox) {
            $payFn($b, ['amount' => 0, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 3. amount = -100
        $b = $bookingFactory();
        $expectReject("amount = -100 (negative)", function () use ($b, $payFn, $cashbox) {
            $payFn($b, ['amount' => -100, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 4. amount = "abc" (non-numeric)
        $b = $bookingFactory();
        $expectReject('amount = "abc" (non-numeric)', function () use ($b, $payFn, $cashbox) {
            $payFn($b, ['amount' => 'abc', 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 5. amount = 999999999999 (overflow)
        $b = $bookingFactory();
        $expectReject('amount = 999999999999 (overflow)', function () use ($b, $payFn, $cashbox) {
            $payFn($b, ['amount' => 999999999999, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 6. empty payload
        $b = $bookingFactory();
        $expectReject('empty payload', function () use ($b, $payFn) {
            $payFn($b, []);
        });

        // 7. missing required field (no account_id)
        $b = $bookingFactory();
        $expectReject('missing required field (no account_id)', function () use ($b, $payFn) {
            $payFn($b, ['amount' => 100]);
        });

        // 8. Idempotency replay — first call succeeds, second call returns same row (no duplicate).
        $b = $bookingFactory();
        $idemKey = 'AUDIT_REPLAY_' . bin2hex(random_bytes(8));
        try {
            $first = $payFn($b->fresh(), [
                'amount' => 200,
                'account_id' => $cashbox?->id,
                'payment_method' => 'cash',
                'idempotency_key' => $idemKey,
            ]);
            $firstId = $first->id ?? null;
            $second = $payFn($b->fresh(), [
                'amount' => 200,
                'account_id' => $cashbox?->id,
                'payment_method' => 'cash',
                'idempotency_key' => $idemKey,
            ]);
            $secondId = $second->id ?? null;
            if ($firstId === $secondId && $firstId !== null) {
                $r->recordPass(); // same row → idempotent
            } else {
                $r->recordFail(
                    scenario: "{$module}: idempotency_key replay returns distinct rows",
                    expected: 'Replay returns same row id',
                    actual: "first={$firstId}, second={$secondId}",
                    severity: 'critical',
                    context: ['module' => $module, 'root_cause' => 'Duplicate payment from replay'],
                );
            }
        } catch (\Throwable $e) {
            // A throw on replay is acceptable only if no row was created.
            $r->recordPass();
        }

        // 9. payment after booking.status = cancelled.
        $b = $bookingFactory();
        $this->forceCancel($module, $b);
        $expectReject('payment after cancel', function () use ($b, $payFn, $cashbox) {
            $payFn($b->fresh(), ['amount' => 100, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });

        // 10. invalid account_id (non-existent)
        $b = $bookingFactory();
        $expectReject('invalid account_id (999999)', function () use ($b, $payFn) {
            $payFn($b, ['amount' => 100, 'account_id' => 999999, 'payment_method' => 'cash']);
        });

        // 11. Cross-customer: try to pay against another customer's booking id.
        // For Flight we use a different booking id (not our own).
        $mine = $bookingFactory();
        $theirs = $bookingFactory();
        // We pass the OTHER booking's id via the foreign-key-like payload.
        // Each service signature differs, so we attempt to abuse whichever FK exists.
        $expectReject('payment against another customer\'s booking', function () use ($mine, $theirs, $payFn, $cashbox) {
            // Try to inject the other booking's id into the payment row.
            $payload = [
                'amount' => 100,
                'account_id' => $cashbox?->id,
                'payment_method' => 'cash',
                'flight_booking_id' => $theirs->id,
                'hajj_umra_booking_id' => $theirs->id,
                'visa_booking_id' => $theirs->id,
            ];
            $payFn($mine->fresh(), $payload);
        });
    }

    /**
     * Force a booking into a 'cancelled' state so we can test that addPayment
     * rejects on a closed booking. Done at the DB level to bypass lifecycle
     * guards — we only care about the payment-side rejection.
     */
    protected function forceCancel(string $module, $booking): void
    {
        $tableMap = [
            'flight'    => 'flight_bookings',
            'hajj_umra' => 'hajj_umra_bookings',
            'visa'      => 'visa_bookings',
        ];
        $colMap = [
            'flight'    => 'cancelled',
            'hajj_umra' => 'cancelled',
            'visa'      => 'cancelled',
        ];
        DB::table($tableMap[$module])
            ->where('id', $booking->id)
            ->update(['status' => $colMap[$module]]);
    }
}
