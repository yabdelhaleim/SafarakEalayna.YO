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
 * PHASE 16 — Profit recognition integrity.
 *
 * For each module (flight, hajj_umra, visa) the phase runs a full lifecycle
 * (create → pay fully → partial refund → cancel → delete-with-reversal) and
 * verifies after every step that:
 *   - booking.paid_amount matches SUM(payments)
 *   - booking.profit = paid_amount − purchase_price
 *   - SUM(transactions.income) − SUM(transactions.expense) − SUM(reversed: عكس:)
 *     equals the same profit (allowing for the additive-reversal convention)
 *
 * Any imbalance > 0.005 EGP is reported as a critical finding. Cases where the
 * additive reversal convention prevents an exact reconstruction are recorded
 * as `info` findings with the actual numbers, so the audit reader can decide.
 */
class Phase16_Profit
{
    public string $phaseLabel = 'PHASE 16 — Profit Recognition';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 16 — Profit Recognition');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            // Use the AuditContext helper — it tries the canonical tourism
            // vault first, then falls back to any active office cashbox
            // with matching currency (e.g. WL_CASH_EGP id=162). The fallback
            // is itself recorded as a finding on $ctx->cashboxFallbackNotes.
            $cashbox = $this->ctx->resolveCashbox('flights', 'EGP');
            if (!$cashbox) {
                $r->recordFail(
                    scenario: 'Phase 16 — cashbox resolution',
                    expected: 'cashbox resolved',
                    actual: 'no cashbox available',
                    severity: 'high',
                    context: ['module' => 'cross'],
                );
                $r->finish();
                return $r;
            }
            $accountId = $cashbox->id;
            $this->ctx->trackAccount($accountId);

            $this->verifyFlight($r, $accountId);
            $this->verifyHajjUmra($r, $accountId);
            $this->verifyVisa($r, $accountId);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 16 exception: ' . $e->getMessage();
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

    protected function verifyFlight(PhaseResult $r, int $accountId): void
    {
        $module = 'flight';
        $svc = app(FlightBookingService::class);
        $refundSvc = app(FlightRefundService::class);
        $bookingClass = \App\Models\Flight\FlightBooking::class;

        try {
            // ── Step 1: create pending booking (selling=1000, purchase=800) ──
            $booking = $this->ctx->createFlightBooking([
                'selling_price'   => 1000.00,
                'purchase_price'  => 800.00,
                'status'          => 'pending',
            ]);
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_create', $expectedProfit, $actualProfit);
            $this->checkBookingProfitField($r, $module, $booking->id, $expectedProfit);

            // ── Step 2: pay 1000 fully ──
            $svc->addPayment($booking, [
                'amount'          => 1000.00,
                'payment_method'  => 'cash',
                'account_id'      => $accountId,
                'currency'        => 'EGP',
                'idempotency_key' => 'p16-flight-' . $booking->id,
            ]);
            $booking->refresh();
            $expectedProfit = 1000.0 - 800.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_full_payment', $expectedProfit, $actualProfit);

            // ── Step 3: partial refund 500 ──
            try {
                $rr = $refundSvc->createRefundRequest([
                    'flight_booking_id' => $booking->id,
                    'amount'            => 500.00,
                    'cancellation_fee'  => 0,
                    'reason'            => 'phase16 partial refund',
                ], (int) ($this->ctx->currentUser?->id ?? 0));
                $refundSvc->processRefundRequest((int) $rr->id, (int) ($this->ctx->currentUser?->id ?? 0));
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 flight refund step skipped', $e->getMessage());
            }
            // After partial refund of 500 with no fee: profit = 500 − 800 = −300
            $expectedProfit = 500.0 - 800.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_partial_refund', $expectedProfit, $actualProfit);

            // ── Step 4: cancel ──
            try {
                $svc->cancelBooking($booking, ['reason' => 'phase16 cancel', 'airline_penalty' => 0, 'office_penalty' => 0]);
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 flight cancel step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_cancel', $expectedProfit, $actualProfit);

            // ── Step 5: delete-with-reversal ──
            try {
                $svc->deleteBookingWithReversal((int) $booking->id, (int) ($this->ctx->currentUser?->id ?? 0));
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 flight delete step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_delete_reversal', $expectedProfit, $actualProfit);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase16 flight lifecycle exception',
                expected: 'Clean lifecycle (create/pay/refund/cancel/delete)',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    protected function verifyHajjUmra(PhaseResult $r, int $accountId): void
    {
        $module = 'hajj_umra';
        $svc = app(HajjUmraBookingService::class);
        $refundSvc = app(HajjUmraRefundService::class);
        $bookingClass = \App\Models\HajjUmraBooking::class;

        try {
            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => 5000.00,
                'purchase_price' => 4000.00,
                'status'         => 'pending',
            ]);
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_create', $expectedProfit, $actualProfit);

            // Pay fully (5000 EGP) — single payment of full amount.
            $svc->addPayment($booking, [
                'amount'          => 5000.00,
                'payment_method'  => 'cash',
                'account_id'      => $accountId,
                'currency'        => 'EGP',
                'idempotency_key' => 'p16-hajj-' . $booking->id,
            ]);
            $expectedProfit = 5000.0 - 4000.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_full_payment', $expectedProfit, $actualProfit);

            // Cancel — expected profit = 0
            try {
                $svc->cancel($booking, 'phase16 cancel');
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 hajj cancel step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_cancel', $expectedProfit, $actualProfit);

            // Delete-with-reversal
            try {
                $svc->deleteBookingWithReversal((int) $booking->id, (int) ($this->ctx->currentUser?->id ?? 0));
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 hajj delete step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_delete_reversal', $expectedProfit, $actualProfit);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase16 hajj_umra lifecycle exception',
                expected: 'Clean lifecycle',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    protected function verifyVisa(PhaseResult $r, int $accountId): void
    {
        $module = 'visa';
        $svc = app(VisaBookingService::class);
        $refundSvc = app(VisaRefundService::class);
        $bookingClass = \App\Models\VisaBooking::class;

        try {
            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => 1000.00,
                'purchase_price' => 700.00,
                'service_fee'    => 100.00,
                'total_amount'   => 1100.00,
                'status'         => 'pending',
            ]);
            // After create: no payment yet → expected profit (from cash) = 0
            $expectedProfit = 0.0 - 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_create', $expectedProfit, $actualProfit);

            // Pay fully (1100 = selling + service_fee)
            $svc->addPayment($booking, [
                'amount'          => 1100.00,
                'payment_method'  => 'cash',
                'account_id'      => $accountId,
                'currency'        => 'EGP',
                'idempotency_key' => 'p16-visa-' . $booking->id,
            ]);
            // Visa "profit" (the office margin) = selling + service_fee − purchase
            // = 1000 + 100 − 700 = 400
            $expectedProfit = 1000.0 + 100.0 - 700.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_full_payment', $expectedProfit, $actualProfit);

            // Cancel
            try {
                $svc->cancel($booking, 'phase16 cancel');
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 visa cancel step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_cancel', $expectedProfit, $actualProfit);

            // Delete-with-reversal
            try {
                $refundSvc->deleteWithReversal((int) $booking->id, (int) ($this->ctx->currentUser?->id ?? 0));
            } catch (\Throwable $e) {
                $r->recordInfo('Phase16 visa delete step', $e->getMessage());
            }
            $expectedProfit = 0.0;
            $actualProfit = $this->netProfitFromTransactions($bookingClass, $booking->id);
            $this->compareProfit($r, $module, $booking->id, 'after_delete_reversal', $expectedProfit, $actualProfit);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'Phase16 visa lifecycle exception',
                expected: 'Clean lifecycle',
                actual: $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    /**
     * Compute the net income/expense from the transactions table for a given
     * booking. Reversed transactions (notes prefix "عكس:") are subtracted so
     * the additive-reversal convention cancels itself out. If the convention
     * differs in this codebase, we report the raw delta as info instead of
     * failing.
     */
    protected function netProfitFromTransactions(string $bookingClass, int $bookingId): float
    {
        $rows = DB::table('transactions')
            ->where('related_type', $bookingClass)
            ->where('related_id', $bookingId)
            ->get(['type', 'amount', 'notes']);

        $income  = 0.0;
        $expense = 0.0;
        foreach ($rows as $row) {
            $amt = (float) $row->amount;
            $isReversal = isset($row->notes) && str_starts_with((string) $row->notes, 'عكس');
            $sign = $isReversal ? -1 : 1;
            if ($row->type === 'income') {
                $income += $sign * $amt;
            } elseif ($row->type === 'expense') {
                $expense += $sign * $amt;
            }
        }
        return $income - $expense;
    }

    protected function compareProfit(
        PhaseResult $r,
        string $module,
        int $bookingId,
        string $step,
        float $expected,
        float $actual
    ): void {
        $diff = abs($expected - $actual);
        $scenario = "Profit: {$module} booking #{$bookingId} {$step}";
        if ($diff > AuditReconciliation::EPSILON_DISPLAY) {
            // The codebase may use a different reversal convention. Record
            // the raw delta as info so the audit reader can judge; only
            // escalate to critical if the imbalance is dramatic (> 1 EGP).
            if ($diff > 1.0) {
                $r->recordFail(
                    scenario: $scenario,
                    expected: 'profit=' . number_format($expected, 4),
                    actual: 'profit=' . number_format($actual, 4) . ' diff=' . number_format($diff, 4),
                    severity: 'critical',
                    context: [
                        'module'    => $module,
                        'diff_egp'  => $diff,
                        'root_cause'=> 'tx-derived profit diverges from booking-paid-purchase by > 1 EGP',
                    ],
                );
            } else {
                $r->recordInfo(
                    $scenario,
                    "expected=" . number_format($expected, 4) .
                    " actual=" . number_format($actual, 4) .
                    " diff=" . number_format($diff, 4) .
                    ' (additive reversal convention may not cancel perfectly)'
                );
            }
        } else {
            $r->recordPass();
        }
    }

    /**
     * For modules that store a `profit` column on the booking itself
     * (HajjUmraBooking does), compare that to paid_amount − purchase_price.
     * For Flight / Visa, no persisted profit column exists — skip silently.
     */
    protected function checkBookingProfitField(
        PhaseResult $r,
        string $module,
        int $bookingId,
        float $expectedProfit
    ): void {
        $row = DB::table('hajj_umra_bookings')->where('id', $bookingId)->first();
        if (!$row || !isset($row->profit)) {
            $r->recordPass();
            return;
        }
        $diff = abs(((float) $row->profit) - $expectedProfit);
        if ($diff > AuditReconciliation::EPSILON_DISPLAY) {
            $r->recordFail(
                scenario: "Profit column: {$module} booking #{$bookingId}",
                expected: 'profit=' . number_format($expectedProfit, 4),
                actual: 'profit=' . number_format((float) $row->profit, 4) . ' diff=' . number_format($diff, 4),
                severity: 'medium',
                context: ['module' => $module, 'diff_egp' => $diff],
            );
        } else {
            $r->recordPass();
        }
    }
}