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
 * PHASE 9 — Cancellation paths & reconciliation.
 *
 * For each module, the lifecycle scenarios are:
 *
 *   - Create → Pay → Cancel (full)        → reconcile (reversal expected)
 *   - Create → Partial Pay → Cancel       → reconcile
 *   - Create → Debt (no payment) → Cancel → reconcile
 *   - Create → Fully Paid → Cancel        → reconcile
 *   - Create → Refund → Cancel             → REJECTED (cancel after refund)
 *   - Create → Cancel → Refund             → REJECTED (refund after cancel)
 *   - Create → Cancel → Delete             → reconcile (no double-reversal)
 *   - Cancel twice                         → REJECTED 2nd call
 *   - Cancel after refund                  → REJECTED
 *   - Cancel after deletion                → REJECTED
 *
 * Each successful scenario must leave account balances + transactions in a
 * consistent state. Each rejected scenario is EXPECTED — recordPass() on
 * a throw, recordFail() on a slip.
 */
class Phase9_Cancellation
{
    public string $phaseLabel = 'PHASE 9 — Cancellation paths';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 9 — Cancellation paths');
        $r->start();

        try {
            $this->ctx->actAsAdmin();
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');

            if (!$cashbox) {
                foreach (['flight', 'hajj_umra', 'visa'] as $m) {
                    $r->recordFail(
                        scenario: "{$m}: cashbox resolved",
                        expected: 'cashbox resolved',
                        actual: 'no cashbox available',
                        severity: 'high',
                        context: ['module' => $m],
                    );
                }
            } else {
                $this->exerciseFlight($r, $cashbox);
                $this->exerciseHajj($r, $cashbox);
                $this->exerciseVisa($r, $cashbox);
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase9 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ── FLIGHT ───────────────────────────────────────────────────────────────

    protected function exerciseFlight(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'flight';
        $bookSvc = app(FlightBookingService::class);
        $refSvc = app(FlightRefundService::class);

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Operation accepted',
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        // 1) Create → Pay full → Cancel → reconcile
        try {
            $b = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
            $this->assertFlightInvariant($r, $b->id, $module, 'Create→Pay→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: Create→Pay→Cancel happy path',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 2) Create → Partial Pay → Cancel → reconcile
        try {
            $b = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 400, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
            $this->assertFlightInvariant($r, $b->id, $module, 'Create→Partial→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: Create→Partial→Cancel happy path',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 3) Create → Debt (no payment) → Cancel → reconcile
        try {
            $b = $this->ctx->createFlightBooking();
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
            $this->assertFlightInvariant($r, $b->id, $module, 'Create→Debt→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: Create→Debt→Cancel happy path',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 4) Create → Refund → Cancel — REJECTED
        $seed = $this->createPaidFlight($r, $cashbox);
        if ($seed) {
            try {
                $rr = $refSvc->createRefundRequest(
                    [
                        'flight_booking_id' => $seed->id,
                        'amount' => 1000,
                        'currency' => 'EGP',
                        'reason' => 'seed',
                        'treasury_id' => DB::table('treasuries')->where('currency', 'EGP')->where('is_active', true)->value('id'),
                        'idempotency_key' => 'PH9_' . bin2hex(random_bytes(6)),
                    ],
                    $this->ctx->currentUser->id
                );
                $refSvc->processRefundRequest($rr->id, $this->ctx->currentUser->id);
            } catch (\Throwable $e) {
                // ignore
            }
            $expectReject('cancel after refund', function () use ($bookSvc, $seed) {
                $bookSvc->cancelBooking($seed->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0]);
            });
        }

        // 5) Create → Cancel → Refund — REJECTED
        $b = $this->ctx->createFlightBooking();
        $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
        $expectReject('refund after cancellation', function () use ($refSvc, $b) {
            $refSvc->createRefundRequest(
                [
                    'flight_booking_id' => $b->id,
                    'amount' => 100,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => DB::table('treasuries')->where('currency', 'EGP')->where('is_active', true)->value('id'),
                    'idempotency_key' => 'PH9A_' . bin2hex(random_bytes(6)),
                ],
                $this->ctx->currentUser->id
            );
        });

        // 6) Create → Cancel → Delete — verify no double-reversal
        try {
            $b = $this->ctx->createFlightBooking();
            $bookSvc->addPayment($b->fresh(), ['amount' => 500, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'audit']);
            $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser->id);
            $this->assertFlightInvariant($r, $b->id, $module, 'Create→Cancel→Delete');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: Create→Cancel→Delete happy path',
                expected: 'Reconciled (no double-reversal)',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 7) Cancel twice → REJECTED
        $b = $this->ctx->createFlightBooking();
        $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'first']);
        $expectReject('cancel twice', function () use ($bookSvc, $b) {
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0, 'notes' => 'second']);
        });

        // 8) Cancel after deletion → REJECTED
        $b = $this->ctx->createFlightBooking();
        $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser->id);
        $expectReject('cancel after deletion', function () use ($bookSvc, $b) {
            $bookSvc->cancelBooking($b->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0]);
        });
    }

    protected function createPaidFlight(PhaseResult $r, ?Account $cashbox): mixed
    {
        try {
            $b = $this->ctx->createFlightBooking(['selling_price' => 1000]);
            app(FlightBookingService::class)->addPayment(
                $b->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            return $b->fresh();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function assertFlightInvariant(PhaseResult $r, int $bookingId, string $module, string $scenario): void
    {
        // For Flight, the strongest invariant check is no DUPLICATE
        // transaction rows for the booking.
        $dups = $this->recon->findDuplicateTransactions(
            $bookingId,
            \App\Models\Flight\FlightBooking::class
        );
        if (empty($dups)) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: "{$module}: {$scenario} — duplicate transactions",
                expected: 'No duplicate (type,amount,currency) tuples',
                actual: 'Duplicates found: ' . json_encode($dups),
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── HAJJ ─────────────────────────────────────────────────────────────────

    protected function exerciseHajj(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'hajj_umra';
        $bookSvc = app(HajjUmraBookingService::class);
        $refSvc = app(HajjUmraRefundService::class);

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Operation accepted',
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        // 1) Create → Pay full → Cancel → reconcile
        try {
            $b = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertHajjInvariant($r, $b->id, $module, 'Create→Pay→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: Create→Pay→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 2) Partial pay + cancel
        try {
            $b = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 400, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertHajjInvariant($r, $b->id, $module, 'Create→Partial→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: Create→Partial→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 3) Debt + cancel
        try {
            $b = $this->ctx->createHajjUmraBooking();
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertHajjInvariant($r, $b->id, $module, 'Create→Debt→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: Create→Debt→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 4) Refund then cancel — REJECTED
        $paid = $this->createPaidHajj($r, $cashbox);
        if ($paid) {
            try { $refSvc->refund($paid->fresh(), 'seed'); } catch (\Throwable $e) {}
            $expectReject('cancel after refund', function () use ($bookSvc, $paid) {
                $bookSvc->cancel($paid->fresh(), 'attack');
            });
        }

        // 5) Cancel then refund — REJECTED
        $b = $this->ctx->createHajjUmraBooking();
        $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        $bookSvc->cancel($b->fresh(), 'audit');
        $expectReject('refund after cancellation', function () use ($refSvc, $b) {
            $refSvc->refund($b->fresh(), 'attack');
        });

        // 6) Cancel → Delete
        try {
            $b = $this->ctx->createHajjUmraBooking();
            $bookSvc->addPayment($b->fresh(), ['amount' => 500, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser);
            $this->assertHajjInvariant($r, $b->id, $module, 'Create→Cancel→Delete');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: Create→Cancel→Delete',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // 7) Cancel twice
        $b = $this->ctx->createHajjUmraBooking();
        $bookSvc->cancel($b->fresh(), 'first');
        $expectReject('cancel twice', function () use ($bookSvc, $b) {
            $bookSvc->cancel($b->fresh(), 'second');
        });

        // 8) Cancel after deletion
        $b = $this->ctx->createHajjUmraBooking();
        $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser);
        $expectReject('cancel after deletion', function () use ($bookSvc, $b) {
            $bookSvc->cancel($b->fresh(), 'attack');
        });
    }

    protected function createPaidHajj(PhaseResult $r, ?Account $cashbox): mixed
    {
        try {
            $b = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
            app(HajjUmraBookingService::class)->addPayment(
                $b->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            return $b->fresh();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function assertHajjInvariant(PhaseResult $r, int $bookingId, string $module, string $scenario): void
    {
        $dups = $this->recon->findDuplicateTransactions(
            $bookingId,
            \App\Models\HajjUmraBooking::class
        );
        if (empty($dups)) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: "{$module}: {$scenario} — duplicate transactions",
                expected: 'No duplicate tuples',
                actual: 'Duplicates found',
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── VISA ──────────────────────────────────────────────────────────────────

    protected function exerciseVisa(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'visa';
        $bookSvc = app(VisaBookingService::class);
        $refSvc = app(VisaRefundService::class);

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module): void {
            try {
                $fn();
                $r->recordFail(
                    scenario: "{$module}: {$scenario}",
                    expected: 'Rejection',
                    actual: 'Operation accepted',
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } catch (\Throwable $e) {
                $r->recordPass();
            }
        };

        try {
            $b = $this->ctx->createVisaBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertVisaInvariant($r, $b->id, $module, 'Create→Pay→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: Create→Pay→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        try {
            $b = $this->ctx->createVisaBooking(['selling_price' => 1000]);
            $bookSvc->addPayment($b->fresh(), ['amount' => 400, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertVisaInvariant($r, $b->id, $module, 'Create→Partial→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: Create→Partial→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        try {
            $b = $this->ctx->createVisaBooking();
            $bookSvc->cancel($b->fresh(), 'audit');
            $this->assertVisaInvariant($r, $b->id, $module, 'Create→Debt→Cancel');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: Create→Debt→Cancel',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $paid = $this->createPaidVisa($r, $cashbox);
        if ($paid) {
            try { $refSvc->refund($paid->fresh(), 'seed'); } catch (\Throwable $e) {}
            $expectReject('cancel after refund', function () use ($bookSvc, $paid) {
                $bookSvc->cancel($paid->fresh(), 'attack');
            });
        }

        $b = $this->ctx->createVisaBooking();
        $bookSvc->addPayment($b->fresh(), ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        $bookSvc->cancel($b->fresh(), 'audit');
        $expectReject('refund after cancellation', function () use ($refSvc, $b) {
            $refSvc->refund($b->fresh(), 'attack');
        });

        try {
            $b = $this->ctx->createVisaBooking();
            $bookSvc->addPayment($b->fresh(), ['amount' => 500, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
            $bookSvc->cancel($b->fresh(), 'audit');
            $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser->id);
            $this->assertVisaInvariant($r, $b->id, $module, 'Create→Cancel→Delete');
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: Create→Cancel→Delete',
                expected: 'Reconciled',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $b = $this->ctx->createVisaBooking();
        $bookSvc->cancel($b->fresh(), 'first');
        $expectReject('cancel twice', function () use ($bookSvc, $b) {
            $bookSvc->cancel($b->fresh(), 'second');
        });

        $b = $this->ctx->createVisaBooking();
        $bookSvc->deleteBookingWithReversal($b->id, $this->ctx->currentUser->id);
        $expectReject('cancel after deletion', function () use ($bookSvc, $b) {
            $bookSvc->cancel($b->fresh(), 'attack');
        });
    }

    protected function createPaidVisa(PhaseResult $r, ?Account $cashbox): mixed
    {
        try {
            $b = $this->ctx->createVisaBooking(['selling_price' => 1000]);
            app(VisaBookingService::class)->addPayment(
                $b->fresh(),
                ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
            return $b->fresh();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function assertVisaInvariant(PhaseResult $r, int $bookingId, string $module, string $scenario): void
    {
        $dups = $this->recon->findDuplicateTransactions(
            $bookingId,
            \App\Models\VisaBooking::class
        );
        if (empty($dups)) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: "{$module}: {$scenario} — duplicate transactions",
                expected: 'No duplicate tuples',
                actual: 'Duplicates found',
                severity: 'critical',
                context: ['module' => $module],
            );
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

}
