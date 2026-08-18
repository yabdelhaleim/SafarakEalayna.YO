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
 * PHASE 7 — Refund happy path & audit-log verification.
 *
 * For each tourism module, creates a 1000 EGP booking, pays it in full, then
 * exercises the refund flow:
 *
 *   1. Full refund — verify RefundAuditLog + audit_logs rows created
 *   2. Partial refund (300) — verify cumulative cap on a second refund (700)
 *
 * For Flight the refund flow uses RefundRequest + processRefundRequest.
 * For HajjUmra and Visa the simpler service-level refund() is used.
 */
class Phase7_Refund
{
    public string $phaseLabel = 'PHASE 7 — Refund happy path';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 7 — Refund happy path');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            // Cache the cashbox + treasury.
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

            // ── Flight ────────────────────────────────────────────────────
            $this->exerciseFlightFullRefund($r, $cashbox, $treasury);
            $this->exerciseFlightPartialRefund($r, $cashbox, $treasury);

            // ── HajjUmra ──────────────────────────────────────────────────
            $this->exerciseHajjRefund($r);

            // ── Visa ──────────────────────────────────────────────────────
            $this->exerciseVisaRefund($r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase7 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ── FLIGHT ───────────────────────────────────────────────────────────────

    protected function exerciseFlightFullRefund(PhaseResult $r, ?Account $cashbox, ?Treasury $treasury): void
    {
        $module = 'flight';

        try {
            // 1) Create + pay full.
            $booking = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            app(FlightBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );

            // 2) Issue refund request.
            $svc = app(FlightRefundService::class);
            $rr = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => 1000,
                    'currency' => 'EGP',
                    'reason' => 'audit full refund',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'AUDIT_FULL_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );

            $this->ctx->trackRefundRequest($rr->id);

            // 3) Process.
            $svc->processRefundRequest($rr->id, $this->ctx->currentUser->id);

            // 4) Verify RefundAuditLog row.
            $ralExists = DB::table('refund_audit_logs')
                ->where('booking_id', $booking->id)
                ->exists();
            if ($ralExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'flight: RefundAuditLog row created for full refund',
                    expected: 'refund_audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }

            // 5) Verify audit_logs row.
            $auditExists = DB::table('audit_logs')
                ->where('action', 'like', 'refund.%')
                ->where('related_id', $booking->id)
                ->exists();
            if ($auditExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'flight: audit_logs row with action refund.* for full refund',
                    expected: 'audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: full refund happy path',
                expected: 'Refund + audit rows created',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    protected function exerciseFlightPartialRefund(PhaseResult $r, ?Account $cashbox, ?Treasury $treasury): void
    {
        $module = 'flight';

        try {
            $booking = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            // Pay full.
            app(FlightBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );

            $svc = app(FlightRefundService::class);

            // First partial refund of 300.
            $r1 = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => 300,
                    'currency' => 'EGP',
                    'reason' => 'partial 300',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'AUDIT_P1_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
            $svc->processRefundRequest($r1->id, $this->ctx->currentUser->id);

            // Second partial refund of 700.
            $r2 = $svc->createRefundRequest(
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => 700,
                    'currency' => 'EGP',
                    'reason' => 'partial 700',
                    'treasury_id' => $treasury?->id,
                    'idempotency_key' => 'AUDIT_P2_' . Str::uuid(),
                ],
                $this->ctx->currentUser->id
            );
            $svc->processRefundRequest($r2->id, $this->ctx->currentUser->id);

            // Verify both RefundAuditLog rows exist for this booking.
            $count = DB::table('refund_audit_logs')
                ->where('booking_id', $booking->id)
                ->count();

            if ($count >= 2) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'flight: partial refund cumulative cap (300+700)',
                    expected: '2 RefundAuditLog rows',
                    actual: "{$count} rows",
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: partial refund cumulative path',
                expected: 'Two sequential refunds succeed',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── HAJJ ─────────────────────────────────────────────────────────────────

    protected function exerciseHajjRefund(PhaseResult $r): void
    {
        $module = 'hajj_umra';

        try {
            $booking = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
            $cashbox = $this->ctx->resolveCashbox('hajj_umra', 'EGP');
            if ($cashbox === null) {
                $r->recordFail(
                    scenario: 'hajj_umra: resolveCashbox(hajj_umra, EGP)',
                    expected: 'active EGP cashbox resolved',
                    actual: 'no cashbox available',
                    severity: 'high',
                    context: ['module' => $module],
                );
                return;
            }
            app(HajjUmraBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox->id, 'payment_method' => 'cash']
            );

            app(HajjUmraRefundService::class)
                ->refund($booking->fresh(), 'audit hajj refund');

            $ralExists = DB::table('refund_audit_logs')
                ->where('booking_id', $booking->id)
                ->exists();
            if ($ralExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'hajj_umra: RefundAuditLog row created',
                    expected: 'refund_audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }

            $auditExists = DB::table('audit_logs')
                ->where('action', 'like', 'refund.%')
                ->where('related_id', $booking->id)
                ->exists();
            if ($auditExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'hajj_umra: audit_logs row with action refund.*',
                    expected: 'audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: full refund happy path',
                expected: 'Refund + audit rows created',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── VISA ──────────────────────────────────────────────────────────────────

    protected function exerciseVisaRefund(PhaseResult $r): void
    {
        $module = 'visa';

        try {
            $booking = $this->ctx->createVisaBooking(['selling_price' => 1000]);
            $cashbox = $this->ctx->resolveCashbox('visas', 'EGP');
            if ($cashbox === null) {
                $r->recordFail(
                    scenario: 'visa: resolveCashbox(visas, EGP)',
                    expected: 'active EGP cashbox resolved',
                    actual: 'no cashbox available',
                    severity: 'high',
                    context: ['module' => $module],
                );
                return;
            }
            app(VisaBookingService::class)->addPayment(
                $booking->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox->id, 'payment_method' => 'cash']
            );

            app(VisaRefundService::class)
                ->refund($booking->fresh(), 'audit visa refund');

            $ralExists = DB::table('refund_audit_logs')
                ->where('booking_id', $booking->id)
                ->exists();
            if ($ralExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'visa: RefundAuditLog row created',
                    expected: 'refund_audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }

            $auditExists = DB::table('audit_logs')
                ->where('action', 'like', 'refund.%')
                ->where('related_id', $booking->id)
                ->exists();
            if ($auditExists) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'visa: audit_logs row with action refund.*',
                    expected: 'audit_logs row exists',
                    actual: 'Not found',
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: full refund happy path',
                expected: 'Refund + audit rows created',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    protected function resolveTreasury(): ?Treasury
    {
        $t = Treasury::where('currency', 'EGP')->where('is_active', true)->first();
        return $t;
    }
}
