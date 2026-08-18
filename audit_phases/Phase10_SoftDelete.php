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
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\RefundService as FlightRefundService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 10 — Soft-delete paths & balance restoration.
 *
 * For each module:
 *
 *   - Create booking, verify NOT soft-deleted (deleted_at IS NULL)
 *   - Call service delete method
 *   - Verify booking soft-deleted (deleted_at NOT NULL)
 *   - Verify original transactions PRESERVED (count rows for related_id)
 *   - Verify reversal entries ADDED (count account_entries)
 *   - Try delete again          → reject (already trashed)
 *   - Try addPayment after delete → reject
 *   - Try cancel after delete   → reject
 *   - Try refund after delete   → reject
 *   - Verify account balance restored to initial state
 *
 * The last check uses assertBalanceDelta with initialBalance and expectedDelta=0.
 */
class Phase10_SoftDelete
{
    public string $phaseLabel = 'PHASE 10 — Soft-delete & balance restoration';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 10 — Soft-delete & balance restoration');
        $r->start();

        try {
            $this->ctx->actAsAdmin();
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');
            $treasury = DB::table('treasuries')->where('currency', 'EGP')->where('is_active', true)->first();

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
                $this->exerciseFlight($r, $cashbox, $treasury?->id);
                $this->exerciseHajj($r, $cashbox);
                $this->exerciseVisa($r, $cashbox);
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase10 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ── FLIGHT ───────────────────────────────────────────────────────────────

    protected function exerciseFlight(PhaseResult $r, ?Account $cashbox, ?int $treasuryId): void
    {
        $module = 'flight';
        $bookSvc = app(FlightBookingService::class);
        $refSvc = app(FlightRefundService::class);

        // Create + pay + delete.
        $booking = $this->ctx->createFlightBooking(['selling_price' => 1000]);
        $bookSvc->addPayment(
            $booking->fresh(),
            ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
        );

        // Pre-delete: deleted_at is null.
        $row = FlightBooking::find($booking->id);
        if ($row && $row->deleted_at === null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'flight: pre-delete deleted_at is null',
                expected: 'NULL',
                actual: 'Not null or missing',
                severity: 'medium',
                context: ['module' => $module],
            );
        }

        // Count transactions before delete.
        $txCountBefore = $this->recon->countTransactions(
            $booking->id,
            FlightBooking::class
        );
        $entriesBefore = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', FlightBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();

        // Snapshot cashbox balance BEFORE delete.
        $cashboxBalanceBefore = $cashbox ? (float) $cashbox->fresh()->balance : 0.0;

        // Perform delete.
        try {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'flight: deleteBookingWithReversal happy path',
                expected: 'Success',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
            return;
        }

        // Post-delete: deleted_at NOT null.
        $row = FlightBooking::withTrashed()->find($booking->id);
        if ($row && $row->deleted_at !== null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'flight: post-delete deleted_at is set',
                expected: 'NOT NULL',
                actual: 'NULL or missing',
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // Original transactions preserved (count must be >= txCountBefore).
        $txCountAfter = $this->recon->countTransactions(
            $booking->id,
            FlightBooking::class
        );
        if ($txCountAfter >= $txCountBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'flight: original transactions preserved after delete',
                expected: "txCount >= {$txCountBefore}",
                actual: "txCount = {$txCountAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // Reversal entries added (entries count > before).
        $entriesAfter = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', FlightBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();
        if ($entriesAfter > $entriesBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'flight: reversal entries added after delete',
                expected: "entries > {$entriesBefore}",
                actual: "entries = {$entriesAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        // Balance restored: cashbox delta vs initial = 0 (we did refund-style reversal).
        if ($cashbox) {
            $this->recon->assertBalanceDelta(
                $cashbox->id,
                $cashboxBalanceBefore,
                0.0,
                'flight delete-after-pay restores cashbox',
                $r
            );
        }

        // Post-delete operation rejections.
        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module, $booking): void {
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

        // Delete again
        $expectReject('delete already-trashed booking', function () use ($bookSvc, $booking) {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
        });

        // addPayment after delete
        $expectReject('addPayment after delete', function () use ($bookSvc, $booking, $cashbox) {
            $bookSvc->addPayment(
                $booking->fresh(),
                ['amount' => 100, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
            );
        });

        // cancel after delete
        $expectReject('cancel after delete', function () use ($bookSvc, $booking) {
            $bookSvc->cancelBooking($booking->fresh(), ['airline_penalty' => 0, 'office_penalty' => 0]);
        });

        // refund after delete
        $expectReject('refund after delete', function () use ($refSvc, $booking, $treasuryId) {
            $refSvc->createRefundRequest(
                [
                    'flight_booking_id' => $booking->id,
                    'amount' => 100,
                    'currency' => 'EGP',
                    'reason' => 'attack',
                    'treasury_id' => $treasuryId,
                    'idempotency_key' => 'PH10_' . bin2hex(random_bytes(6)),
                ],
                $this->ctx->currentUser->id
            );
        });
    }

    // ── HAJJ ─────────────────────────────────────────────────────────────────

    protected function exerciseHajj(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'hajj_umra';
        $bookSvc = app(HajjUmraBookingService::class);
        $refSvc = app(HajjUmraRefundService::class);

        $booking = $this->ctx->createHajjUmraBooking(['selling_price' => 1000]);
        $bookSvc->addPayment(
            $booking->fresh(),
            ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
        );

        $row = HajjUmraBooking::find($booking->id);
        if ($row && $row->deleted_at === null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'hajj_umra: pre-delete deleted_at is null',
                expected: 'NULL',
                actual: 'Not null or missing',
                severity: 'medium',
                context: ['module' => $module],
            );
        }

        $txCountBefore = $this->recon->countTransactions(
            $booking->id,
            HajjUmraBooking::class
        );
        $entriesBefore = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', HajjUmraBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();

        $cashboxBalanceBefore = $cashbox ? (float) $cashbox->fresh()->balance : 0.0;

        try {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'hajj_umra: deleteBookingWithReversal happy path',
                expected: 'Success',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
            return;
        }

        $row = HajjUmraBooking::withTrashed()->find($booking->id);
        if ($row && $row->deleted_at !== null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'hajj_umra: post-delete deleted_at is set',
                expected: 'NOT NULL',
                actual: 'NULL or missing',
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $txCountAfter = $this->recon->countTransactions(
            $booking->id,
            HajjUmraBooking::class
        );
        if ($txCountAfter >= $txCountBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'hajj_umra: original transactions preserved',
                expected: "txCount >= {$txCountBefore}",
                actual: "txCount = {$txCountAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $entriesAfter = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', HajjUmraBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();
        if ($entriesAfter > $entriesBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'hajj_umra: reversal entries added',
                expected: "entries > {$entriesBefore}",
                actual: "entries = {$entriesAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        if ($cashbox) {
            $this->recon->assertBalanceDelta(
                $cashbox->id,
                $cashboxBalanceBefore,
                0.0,
                'hajj delete-after-pay restores cashbox',
                $r
            );
        }

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module, $booking): void {
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

        $expectReject('delete already-trashed', function () use ($bookSvc, $booking) {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
        });
        $expectReject('addPayment after delete', function () use ($bookSvc, $booking, $cashbox) {
            $bookSvc->addPayment($booking->fresh(), ['amount' => 100, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });
        $expectReject('cancel after delete', function () use ($bookSvc, $booking) {
            $bookSvc->cancel($booking->fresh(), 'attack');
        });
        $expectReject('refund after delete', function () use ($refSvc, $booking) {
            $refSvc->refund($booking->fresh(), 'attack');
        });
    }

    // ── VISA ──────────────────────────────────────────────────────────────────

    protected function exerciseVisa(PhaseResult $r, ?Account $cashbox): void
    {
        $module = 'visa';
        $bookSvc = app(VisaBookingService::class);
        $refSvc = app(VisaRefundService::class);

        $booking = $this->ctx->createVisaBooking(['selling_price' => 1000]);
        $bookSvc->addPayment(
            $booking->fresh(),
            ['amount' => 1000, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']
        );

        $row = VisaBooking::find($booking->id);
        if ($row && $row->deleted_at === null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'visa: pre-delete deleted_at is null',
                expected: 'NULL',
                actual: 'Not null or missing',
                severity: 'medium',
                context: ['module' => $module],
            );
        }

        $txCountBefore = $this->recon->countTransactions(
            $booking->id,
            VisaBooking::class
        );
        $entriesBefore = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', VisaBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();

        $cashboxBalanceBefore = $cashbox ? (float) $cashbox->fresh()->balance : 0.0;

        try {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'visa: deleteBookingWithReversal happy path',
                expected: 'Success',
                actual: $e->getMessage(),
                severity: 'critical',
                context: ['module' => $module],
            );
            return;
        }

        $row = VisaBooking::withTrashed()->find($booking->id);
        if ($row && $row->deleted_at !== null) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'visa: post-delete deleted_at is set',
                expected: 'NOT NULL',
                actual: 'NULL or missing',
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $txCountAfter = $this->recon->countTransactions(
            $booking->id,
            VisaBooking::class
        );
        if ($txCountAfter >= $txCountBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'visa: original transactions preserved',
                expected: "txCount >= {$txCountBefore}",
                actual: "txCount = {$txCountAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        $entriesAfter = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->where('t.related_type', VisaBooking::class)
            ->where('t.related_id', $booking->id)
            ->count();
        if ($entriesAfter > $entriesBefore) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'visa: reversal entries added',
                expected: "entries > {$entriesBefore}",
                actual: "entries = {$entriesAfter}",
                severity: 'critical',
                context: ['module' => $module],
            );
        }

        if ($cashbox) {
            $this->recon->assertBalanceDelta(
                $cashbox->id,
                $cashboxBalanceBefore,
                0.0,
                'visa delete-after-pay restores cashbox',
                $r
            );
        }

        $expectReject = function (string $scenario, \Closure $fn) use ($r, $module, $booking): void {
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

        $expectReject('delete already-trashed', function () use ($bookSvc, $booking) {
            $bookSvc->deleteBookingWithReversal($booking->id, $this->ctx->currentUser->id);
        });
        $expectReject('addPayment after delete', function () use ($bookSvc, $booking, $cashbox) {
            $bookSvc->addPayment($booking->fresh(), ['amount' => 100, 'account_id' => $cashbox?->id, 'payment_method' => 'cash']);
        });
        $expectReject('cancel after delete', function () use ($bookSvc, $booking) {
            $bookSvc->cancel($booking->fresh(), 'attack');
        });
        $expectReject('refund after delete', function () use ($refSvc, $booking) {
            $refSvc->refund($booking->fresh(), 'attack');
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────

}
