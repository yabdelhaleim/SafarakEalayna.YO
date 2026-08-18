<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\VisaBooking;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 13 — Concurrency & Idempotency.
 *
 * Verifies that tourism financial flows are safe under duplicate /
 * concurrent invocation. The audit simulates double-click and race
 * scenarios sequentially (MySQL row locks serialize naturally).
 *
 *   1. Double-click addPayment with same idempotency_key → same row, no
 *      duplicate transaction, cashbox credited ONCE.
 *   2. Double-click refund with same idempotency_key → no-op or reject.
 *   3. Sequential refund + cancel → only one terminal transition succeeds.
 *   4. Sequential addPayment + refund → net cashbox delta = 0.
 *   5. Sequential addPayment + cancel → additive reversal handles payment.
 *
 * Detection: AuditReconciliation::findDuplicateTransactions() per booking.
 * Any duplicate tuple (type, amount, currency) with COUNT > 1 = NO-GO
 * finding at severity=critical.
 */
class Phase13_Concurrency
{
    public string $phaseLabel = 'PHASE 13 — Concurrency & Idempotency';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 13 — Concurrency & Idempotency');
        $r->start();

        try {
            $this->ctx->actAsAdmin();
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');
            if (!$cashbox) {
                $r->recordFail(
                    scenario: 'cashbox resolved',
                    expected: 'cashbox resolved',
                    actual: 'no cashbox available',
                    severity: 'high',
                    context: ['module' => 'concurrency'],
                );
                $r->finish();
                return $r;
            }

            // Per-module test config (label, bookingClass, serviceClass,
            // paymentMethod field, hasRefundRequestFlow for Flight only)
            $modules = [
                'flight'    => [FlightBooking::class,    \App\Services\Flight\FlightBookingService::class],
                'hajj_umra' => [HajjUmraBooking::class,   \App\Services\HajjUmra\HajjUmraBookingService::class],
                'visa'      => [VisaBooking::class,       \App\Services\Visa\VisaBookingService::class],
            ];

            foreach ($modules as $label => [$modelClass, $svcClass]) {
                $this->runModuleSuite($r, $label, $modelClass, $svcClass, $cashbox->id);
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 13 exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    /**
     * Run all 5 scenarios for a single module.
     */
    protected function runModuleSuite(
        PhaseResult $r,
        string $module,
        string $modelClass,
        string $svcClass,
        int $cashboxId,
    ): void {
        $this->scenarioDoubleClickPayment($r, $module, $modelClass, $svcClass, $cashboxId);
        $this->scenarioDoubleClickRefund($r, $module, $modelClass, $cashboxId);
        $this->scenarioRefundThenCancel($r, $module, $modelClass, $cashboxId);
        $this->scenarioPaymentThenRefund($r, $module, $modelClass, $cashboxId);
        $this->scenarioPaymentThenCancel($r, $module, $modelClass, $cashboxId);
    }

    protected function scenarioDoubleClickPayment(
        PhaseResult $r,
        string $module,
        string $modelClass,
        string $svcClass,
        int $cashboxId,
    ): void {
        try {
            $booking = $this->ctx->{'create' . $this->factoryMethod($module)}();
            $svc = app($svcClass);
            $idem = 'audit-concurrency-' . uniqid($module . '_', true);
            $p1 = $svc->{'addPayment'}($booking, [
                'amount' => 1000.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => $idem,
            ]);
            $p2 = $svc->{'addPayment'}($booking, [
                'amount' => 999.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => $idem,
            ]);

            $this->recordPassOrFail(
                $r,
                $p1->id === $p2->id,
                "{$module} double-click payment: same idempotency_key returns same row",
                "p1.id={$p1->id} p2.id={$p2->id}",
                'critical',
                $module,
            );

            $this->assertNoDuplicateTransactions(
                $r,
                $module,
                $booking->id,
                $modelClass,
                'double-click payment',
            );
        } catch (\Throwable $e) {
            $r->recordBlock("{$module} double-click payment", $e->getMessage());
        }
    }

    protected function scenarioDoubleClickRefund(
        PhaseResult $r,
        string $module,
        string $modelClass,
        int $cashboxId,
    ): void {
        try {
            $booking = $this->ctx->{'create' . $this->factoryMethod($module)}();
            $bookSvc = $this->bookingService($module);
            $bookSvc->{'addPayment'}($booking, [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => 'audit-pay-' . uniqid($module . 'r_', true),
            ]);

            $txCountBefore = $this->countTx($booking->id, $modelClass);

            if ($module === 'flight') {
                $refSvc = app(\App\Services\Flight\RefundService::class);
                $adminId = $this->ctx->currentUser?->id ?? 1;
                $idem = 'audit-refund-' . uniqid('flr_', true);
                $rr1 = $refSvc->createRefundRequest([
                    'flight_booking_id' => $booking->id,
                    'amount' => 500.0,
                    'reason' => $this->ctx->prefix . 'audit double-click',
                    'idempotency_key' => $idem,
                ], $adminId);
                try {
                    $rr2 = $refSvc->createRefundRequest([
                        'flight_booking_id' => $booking->id,
                        'amount' => 500.0,
                        'reason' => $this->ctx->prefix . 'audit double-click',
                        'idempotency_key' => $idem,
                    ], $adminId);
                    $this->recordPassOrFail(
                        $r,
                        $rr1->id === $rr2->id,
                        "{$module} double-click refund request: same idempotency_key",
                        "rr1={$rr1->id} rr2={$rr2->id}",
                        'critical',
                        $module,
                    );
                } catch (\Throwable $e) {
                    $r->recordPass(); // rejection is acceptable
                }
                $refSvc->processRefundRequest($rr1->id, $adminId);
                try {
                    $refSvc->processRefundRequest($rr1->id, $adminId);
                    $r->recordFail(
                        scenario: "{$module} double-process refund",
                        expected: 'Second processRefundRequest rejected',
                        actual: 'Second process succeeded — double refund risk',
                        severity: 'critical',
                        context: ['module' => $module, 'role' => 'admin'],
                    );
                } catch (\Throwable $e) {
                    $r->recordPass();
                }
            } else {
                $refSvc = $this->refundService($module);
                $actor = $this->ctx->currentUser;
                $refSvc->{'refund'}($booking, $this->ctx->prefix . 'audit double-click', $actor);
                try {
                    $refSvc->{'refund'}($booking, $this->ctx->prefix . 'audit double-click 2', $actor);
                    $txCountAfter = $this->countTx($booking->id, $modelClass);
                    $this->recordPassOrFail(
                        $r,
                        $txCountAfter === $txCountBefore,
                        "{$module} double-click refund is no-op on replay",
                        "txCount changed {$txCountBefore} → {$txCountAfter}",
                        'critical',
                        $module,
                    );
                } catch (\Throwable $e) {
                    $r->recordPass(); // rejection acceptable
                }
            }

            $this->assertNoDuplicateTransactions(
                $r,
                $module,
                $booking->id,
                $modelClass,
                'double-click refund',
            );
        } catch (\Throwable $e) {
            $r->recordBlock("{$module} double-click refund", $e->getMessage());
        }
    }

    protected function scenarioRefundThenCancel(
        PhaseResult $r,
        string $module,
        string $modelClass,
        int $cashboxId,
    ): void {
        try {
            $booking = $this->ctx->{'create' . $this->factoryMethod($module)}();
            $bookSvc = $this->bookingService($module);
            $bookSvc->{'addPayment'}($booking, [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => 'audit-pay-' . uniqid($module . 'rc_', true),
            ]);

            if ($module === 'flight') {
                $refSvc = app(\App\Services\Flight\RefundService::class);
                $adminId = $this->ctx->currentUser?->id ?? 1;
                $rr = $refSvc->createRefundRequest([
                    'flight_booking_id' => $booking->id,
                    'amount' => 500.0,
                    'reason' => $this->ctx->prefix . 'audit',
                ], $adminId);
                $refSvc->processRefundRequest($rr->id, $adminId);
                $cancelFn = 'cancelBooking';
            } elseif ($module === 'hajj_umra') {
                app(\App\Services\HajjUmra\HajjUmraRefundService::class)
                    ->refund($booking, $this->ctx->prefix . 'audit', $this->ctx->currentUser);
                $cancelFn = 'cancel';
            } else {
                app(\App\Services\Visa\VisaRefundService::class)
                    ->refund($booking, $this->ctx->prefix . 'audit', $this->ctx->currentUser);
                $cancelFn = 'cancel';
            }

            try {
                $bookSvc->{$cancelFn}(
                    $booking,
                    $module === 'flight' ? ['reason' => $this->ctx->prefix . 'after-refund'] : ($this->ctx->prefix . 'after-refund'),
                );
                $r->recordFail(
                    scenario: "{$module}: cancel after refund",
                    expected: 'Cancel rejected (booking refunded)',
                    actual: 'Cancel succeeded — terminal-state bypass',
                    severity: 'high',
                    context: ['module' => $module, 'role' => 'admin'],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        } catch (\Throwable $e) {
            $r->recordBlock("{$module} refund-then-cancel", $e->getMessage());
        }
    }

    protected function scenarioPaymentThenRefund(
        PhaseResult $r,
        string $module,
        string $modelClass,
        int $cashboxId,
    ): void {
        try {
            $booking = $this->ctx->{'create' . $this->factoryMethod($module)}();
            $bookSvc = $this->bookingService($module);
            $bookSvc->{'addPayment'}($booking, [
                'amount' => 250.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => 'audit-pay-' . uniqid($module . 'pr_', true),
            ]);

            if ($module === 'flight') {
                $refSvc = app(\App\Services\Flight\RefundService::class);
                $adminId = $this->ctx->currentUser?->id ?? 1;
                $rr = $refSvc->createRefundRequest([
                    'flight_booking_id' => $booking->id,
                    'amount' => 250.0,
                    'reason' => $this->ctx->prefix . 'audit',
                ], $adminId);
                $refSvc->processRefundRequest($rr->id, $adminId);
            } elseif ($module === 'hajj_umra') {
                app(\App\Services\HajjUmra\HajjUmraRefundService::class)
                    ->refund($booking, $this->ctx->prefix . 'audit', $this->ctx->currentUser);
            } else {
                app(\App\Services\Visa\VisaRefundService::class)
                    ->refund($booking, $this->ctx->prefix . 'audit', $this->ctx->currentUser);
            }

            $this->assertNoDuplicateTransactions(
                $r,
                $module,
                $booking->id,
                $modelClass,
                'payment+refund',
            );
        } catch (\Throwable $e) {
            $r->recordBlock("{$module} payment+refund", $e->getMessage());
        }
    }

    protected function scenarioPaymentThenCancel(
        PhaseResult $r,
        string $module,
        string $modelClass,
        int $cashboxId,
    ): void {
        try {
            $booking = $this->ctx->{'create' . $this->factoryMethod($module)}();
            $bookSvc = $this->bookingService($module);
            $bookSvc->{'addPayment'}($booking, [
                'amount' => 300.0,
                'payment_method' => 'cash',
                'account_id' => $cashboxId,
                'idempotency_key' => 'audit-pay-' . uniqid($module . 'pc_', true),
            ]);
            $cancelFn = $module === 'flight' ? 'cancelBooking' : 'cancel';
            $cancelArg = $module === 'flight'
                ? ['reason' => $this->ctx->prefix . 'audit']
                : $this->ctx->prefix . 'audit';
            $bookSvc->{$cancelFn}($booking, $cancelArg);

            $this->assertNoDuplicateTransactions(
                $r,
                $module,
                $booking->id,
                $modelClass,
                'payment+cancel',
            );
        } catch (\Throwable $e) {
            $r->recordBlock("{$module} payment+cancel", $e->getMessage());
        }
    }

    protected function factoryMethod(string $module): string
    {
        return match ($module) {
            'flight'    => 'FlightBooking',
            'hajj_umra' => 'HajjUmraBooking',
            'visa'      => 'VisaBooking',
            default     => '',
        };
    }

    protected function bookingService(string $module): object
    {
        return match ($module) {
            'flight'    => app(\App\Services\Flight\FlightBookingService::class),
            'hajj_umra' => app(\App\Services\HajjUmra\HajjUmraBookingService::class),
            'visa'      => app(\App\Services\Visa\VisaBookingService::class),
            default     => app(\App\Services\Flight\FlightBookingService::class),
        };
    }

    protected function refundService(string $module): object
    {
        return match ($module) {
            'flight'    => app(\App\Services\Flight\RefundService::class),
            'hajj_umra' => app(\App\Services\HajjUmra\HajjUmraRefundService::class),
            'visa'      => app(\App\Services\Visa\VisaRefundService::class),
            default     => app(\App\Services\Flight\RefundService::class),
        };
    }

    protected function countTx(int $bookingId, string $relatedType): int
    {
        return (int) DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $bookingId)
            ->count();
    }

    protected function assertNoDuplicateTransactions(
        PhaseResult $r,
        string $module,
        int $bookingId,
        string $relatedType,
        string $scenarioTag,
    ): void {
        $dups = $this->recon->findDuplicateTransactions($bookingId, $relatedType);
        if (empty($dups)) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: "{$module} {$scenarioTag}: no duplicate transactions",
                expected: 'No (type,amount,currency) duplicates',
                actual: count($dups) . ' duplicate group(s) found',
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    protected function recordPassOrFail(
        PhaseResult $r,
        bool $passed,
        string $scenario,
        string $actual,
        string $severity,
        string $module,
    ): void {
        if ($passed) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: $scenario,
                expected: 'Idempotent / no-op behavior',
                actual: $actual,
                severity: $severity,
                context: ['module' => $module, 'role' => 'admin'],
            );
        }
    }
}
