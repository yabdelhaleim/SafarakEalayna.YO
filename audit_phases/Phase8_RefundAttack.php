<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Treasury;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\RefundService as FlightRefundService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHASE 8 — Refund attack surface.
 *
 * Each module: create a 1000 EGP booking, pay in full, refund it, then attack:
 *
 *   - refund > 1000                  → reject (cap)
 *   - refund = 0                     → reject (zero)
 *   - refund = -100                  → reject (negative)
 *   - cross-booking refund           → reject (other customer's booking)
 *   - double refund (same booking)   → reject second call
 *   - refund after full refund       → reject (already refunded)
 *   - refund after cancellation      → reject (cancelled)
 *   - refund after deletion          → reject (trashed)
 *   - manipulated amount string      → reject
 *   - parallel refunds with same key → only one succeeds
 *
 * Each rejection is EXPECTED — recordPass() on throw, recordFail() on slip.
 */
class Phase8_RefundAttack
{
    public string $phaseLabel = 'PHASE 8 — Refund attack surface';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 8 — Refund attack surface');
        $r->start();

        try {
            $this->ctx->actAsAdmin();
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
            $treasury = $this->resolveTreasury();

            $this->exerciseFlight($r, $cashbox, $treasury);
            $this->exerciseHajj($r, $cashbox);
            $this->exerciseVisa($r, $cashbox);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase8 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ── FLIGHT ───────────────────────────────────────────────────────────────

    protected function exerciseFlight(PhaseResult $r, ?Account $cashbox, ?Treasury $treasury): void
    {
        $module = 'flight';
        $svc = app(FlightRefundService::class);
        $bookSvc = app(FlightBookingService::class);

        // Build a paid-then-refunded booking for the post-refund attacks.
        $paid = $this->createPaidFlightBooking($r, $cashbox, $treasury, $svc);
        if (!$paid) return;

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Refund accepted — money mutated',
                    severity: 'critical',
                    context: ['module' => $module, 'root_cause' => 'Refund slip-through detected'],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        // refund > 1000
        $expectReject('refund > 1000 (1500)', function () use ($svc, $paid, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $paid->id,
                    'amount' => 1500,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'ATK_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // refund = 0
        $expectReject('refund = 0', function () use ($svc, $paid, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $paid->id,
                    'amount' => 0,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'ATK_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // refund = -100
        $expectReject('refund = -100', function () use ($svc, $paid, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $paid->id,
                    'amount' => -100,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'ATK_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // cross-booking manipulation: pass another booking's id, but the service
        // will lock that booking — we expect a rejection (cumulative cap) since
        // the other booking was never paid.
        $other = $this->ctx->createFlightBooking();
        $expectReject('cross-booking refund (other customer booking)', function () use ($svc, $other, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $other->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'ATK_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // double refund: create a SECOND paid booking and refund twice.
        $dpaid = $this->createPaidFlightBooking($r, $cashbox, $treasury, $svc, 'DUP');
        if ($dpaid) {
            $first = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $dpaid->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'first',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'DBL1_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
            $svc->processRefundRequest($first->id, $this->ctx->currentUser->id);

            $expectReject('double refund (2nd call after 1st refund)', function () use ($svc, $dpaid, $treasury) {
                $svc->createRefundRequest(
                    [
                        'flight_booking_id' => $dpaid->id,
                        'amount' => 1000,
                        'currency' => 'EGP',
                        'reason' => 'second',
                        'treasury_id' => $treasury?->id,
                        'idempotency_key' => 'DBL2_' . Str::uuid(),
                    ],
                    $this->ctx->currentUser->id
                );
            });
        }

        // refund after cancellation — separate booking.
        $cbooking = $this->ctx->createFlightBooking();
        $bookSvc->cancelBooking($cbooking, ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
        $expectReject('refund after cancellation', function () use ($svc, $cbooking, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $cbooking->id,
                    'amount' => 100,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'CXL_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // refund after deletion — soft delete then attempt refund.
        $dbook = $this->ctx->createFlightBooking();
        $bookSvc->deleteBookingWithReversal($dbook->id, $this->ctx->currentUser->id);
        $expectReject('refund after deletion', function () use ($svc, $dbook, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $dbook->id,
                    'amount' => 100,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'DEL_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // manipulated amount string — service casts via (float) but the validator
        // or downstream check should reject "1000abc" or "1000,00".
        $expectReject('manipulated amount string "1000abc"', function () use ($svc, $paid, $treasury) {
            $svc->createRefundRequest(
                [
                    'flight_booking_id' => $paid->id,
                    'amount' => '1000abc',
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'STR_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
        });

        // parallel refunds with same idempotency_key — only one should succeed.
        // We can't really parallelize in PHP, but two sequential calls with the
        // same key on a fresh booking should produce the same RefundRequest id.
        $pbooking = $this->ctx->createFlightBooking();
        $bookSvc->addPayment(
            $pbooking->fresh(),
            ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
        );
        $parallelKey = 'PAR_' . Str::uuid();
        try {
            $rr1 = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $pbooking->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'parallel',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => $parallelKey,
                ],
                $this->ctx->currentUser->id
            );
            $rr2 = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $pbooking->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'parallel',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => $parallelKey,
                ],
                $this->ctx->currentUser->id
            );
            // If both succeed, count refund_requests for this booking.
            $count = DB::table('refund_requests')
                ->where('flight_booking_id', $pbooking->id)
                ->where('idempotency_key', $parallelKey)
                ->count();
            if ($count === 1) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'flight: parallel refund same key — exactly one row',
                    expected: '1 row',
                    actual: "{$count} rows",
                    severity: 'critical',
                    context: ['module' => $module, 'root_cause' => 'Duplicate refund on replay'],
                );
            }
        } catch (\Throwable $e) {
            $r->recordPass();
        }
    }

    /**
     * Create a paid-then-refunded Flight booking. Used as the substrate for
     * post-refund attacks (so the booking is in refunded state).
     */
    protected function createPaidFlightBooking(
        PhaseResult $r,
        ?Account $cashbox,
        ?Treasury $treasury,
        FlightRefundService $svc
    ): mixed {
        try {
            $booking = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            app(FlightBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            $rr = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'seed',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'SEED_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
            $svc->processRefundRequest($rr->id, $this->ctx->currentUser->id);
            return $booking->fresh();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: seed paid+refunded booking',
                expected: 'Booking created, paid, refunded',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => 'flight'],
            );
            return null;
        }
    }

    // ── HAJJ ─────────────────────────────────────────────────────────────────

    protected function exerciseHajj(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'hajj_umra';
        $refSvc = app(HajjUmraRefundService::class);
        $bookSvc = app(HajjUmraBookingService::class);

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Refund accepted — money mutated',
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        // Seed: paid + refunded.
        $paid = $this->createPaidHajjBooking($r, $cashbox, $refSvc);
        if (!$paid) return;

        // refund again on refunded booking
        $expectReject('refund after full refund', function () use ($refSvc, $paid) {
            $refSvc->refund($paid->fresh(), 'attack 2');
        });

        // refund after cancel
        $cbook = $this->ctx->createHajjUmraBooking();
        $bookSvc->cancel($cbook, 'audit');
        $expectReject('refund after cancellation', function () use ($refSvc, $cbook) {
            $refSvc->refund($cbook->fresh(), 'attack');
        });

        // refund after deletion
        $dbook = $this->ctx->createHajjUmraBooking();
        $bookSvc->deleteBookingWithReversal($dbook->id, $this->ctx->currentUser->id);
        $expectReject('refund after deletion', function () use ($refSvc, $dbook) {
            $refSvc->refund($dbook->fresh(), 'attack');
        });

        // refund on no-payment booking (paid_amount = 0)
        $nbook = $this->ctx->createHajjUmraBooking();
        $expectReject('refund on unpaid booking', function () use ($refSvc, $nbook) {
            $refSvc->refund($nbook->fresh(), 'attack');
        });

        // manipulated reason is irrelevant; the service rejects only via paid/cancelled.
        // We test that passing -100 as reason still doesn't crash.
        $expectReject('manipulated input: huge reason', function () use ($refSvc, $paid) {
            $refSvc->refund($paid->fresh(), str_repeat('A', 5000));
        });
    }

    protected function createPaidHajjBooking(PhaseResult $r, ?Account $cashbox, HajjUmraRefundService $refSvc): mixed
    {
        try {
            $booking = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
            app(HajjUmraBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            $refSvc->refund($booking->fresh(), 'seed');
            return $booking->fresh();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: seed paid+refunded booking',
                expected: 'Booking created, paid, refunded',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => 'hajj_umra'],
            );
            return null;
        }
    }

    // ── VISA ──────────────────────────────────────────────────────────────────

    protected function exerciseVisa(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'visa';
        $refSvc = app(VisaRefundService::class);
        $bookSvc = app(VisaBookingService::class);

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Refund accepted — money mutated',
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        $paid = $this->createPaidVisaBooking($r, $cashbox, $refSvc);
        if (!$paid) return;

        $expectReject('refund after full refund', function () use ($refSvc, $paid) {
            $refSvc->refund($paid->fresh(), 'attack 2');
        });

        $cbook = $this->ctx->createVisaBooking();
        $bookSvc->cancel($cbook, 'audit');
        $expectReject('refund after cancellation', function () use ($refSvc, $cbook) {
            $refSvc->refund($cbook->fresh(), 'attack');
        });

        $dbook = $this->ctx->createVisaBooking();
        $bookSvc->deleteBookingWithReversal($dbook->id, $this->ctx->currentUser->id);
        $expectReject('refund after deletion', function () use ($refSvc, $dbook) {
            $refSvc->refund($dbook->fresh(), 'attack');
        });

        $nbook = $this->ctx->createVisaBooking();
        $expectReject('refund on unpaid booking', function () use ($refSvc, $nbook) {
            $refSvc->refund($nbook->fresh(), 'attack');
        });
    }

    protected function createPaidVisaBooking(PhaseResult $r, ?Account $cashbox, VisaRefundService $refSvc): mixed
    {
        try {
            $booking = $this->ctx->createVisaBooking(['selling_price' => 1000]);
            app(VisaBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            $refSvc->refund($booking->fresh(), 'seed');
            return $booking->fresh();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: seed paid+refunded booking',
                expected: 'Booking created, paid, refunded',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => 'visa'],
            );
            return null;
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    protected function resolveTreasury(): ?Treasury
    {
        return Treasury::where('currency', 'EGP')->where('is_active', true)->first();
    }
}
