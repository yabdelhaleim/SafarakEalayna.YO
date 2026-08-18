<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Customer;

/**
 * PHASE 5 — Debt lifecycle.
 *
 * For each Tourism module, creates a 5000 EGP booking and steps through four
 * partial payments whose running balance drives the remaining debt down to
 * exactly zero. After each step the audit asserts:
 *
 *   - booking.paid_amount equals the SUM of the partial payments recorded
 *     (independent recomputation via AuditReconciliation::totalPaymentsRecorded)
 *   - booking.total_amount - booking.paid_amount equals the expected debt
 *     (assertZeroEGPDiff)
 *   - the customer AR balance equals -(paid_amount) (i.e. -SUM(payments))
 *   - the SUM(account_entries) invariant still holds on every account touched
 *
 * If a service throws on an over-payment (e.g. amount > remaining), we record
 * the rejection as `info` ("excessive debt payment rejected (expected)").
 */
class Phase5_Debt
{
    public string $phaseLabel = 'PHASE 5 — Debt';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 5 — Debt');
        $r->start();

        try {
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

            $wallet = $this->ctx->resolveWallet('flights', 'EGP');
            if ($wallet) {
                $this->ctx->trackAccount($wallet->id);
            }

            $this->ctx->actAsEmployee();
            $r->recordPass();

            $this->runFlightDebt($cashbox->id, $wallet?->id, $r);
            $this->runHajjUmraDebt($cashbox->id, $wallet?->id, $r);
            $this->runVisaDebt($cashbox->id, $wallet?->id, $r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 5 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Flight debt lifecycle
    // ─────────────────────────────────────────────────────────────────────

    protected function runFlightDebt(int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'flight';
        $total = 5000.00;
        $scenario = 'flight debt';
        $paymentTable = 'flight_payments';
        $fkColumn = 'flight_booking_id';

        try {
            $booking = $this->ctx->createFlightBooking([
                'selling_price'         => $total,
                'purchase_price'        => 4000.00,
                'selling_price_foreign' => $total,
                'purchase_price_foreign'=> 4000.00,
                'currency'              => 'EGP',
                'status'                => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\Flight\FlightBookingService::class);

            $steps = [
                ['amount' => 2000.00, 'method' => 'cash'],
                ['amount' => 1000.00, 'method' => 'wallet'],
                ['amount' =>  500.00, 'method' => 'cash'],
                ['amount' => 1500.00, 'method' => 'wallet'],
            ];

            $cumulativePaid = 0.0;
            foreach ($steps as $idx => $step) {
                $stepLabel = "{$scenario} step " . ($idx + 1) . " ({$step['method']} {$step['amount']})";
                $method = $step['method'];
                $amount = (float) $step['amount'];
                $accountId = $method === 'wallet' ? ($walletId ?? $cashboxId) : $cashboxId;
                if ($method === 'wallet' && !$walletId) {
                    $r->recordSkip("{$stepLabel} wallet step", 'no wallet account seeded');
                    continue;
                }

                $cumulativePaid += $amount;
                $service->addPayment($booking, [
                    'amount'         => $amount,
                    'payment_method' => $method,
                    'account_id'     => $accountId,
                    'transaction_reference' => $this->ctx->prefix . 'DEBT-' . substr((string) $booking->id, 0, 6) . "-{$idx}",
                    'payment_date'   => now()->toDateString(),
                ]);

                // Refresh from DB so paid_amount reflects the persisted value.
                $booking->refresh();

                $recorded = (float) $this->recon->totalPaymentsRecorded($booking->id, $paymentTable, $fkColumn);
                $this->recon->assertZeroEGPDiff($cumulativePaid, $recorded, "{$stepLabel} SUM(payments)", $r, $module);
                $this->recon->assertZeroEGPDiff($cumulativePaid, (float) $booking->paid_amount, "{$stepLabel} booking.paid_amount", $r, $module);

                $expectedDebt = $total - $cumulativePaid;
                $this->recon->assertZeroEGPDiff($expectedDebt, (float) $booking->total_amount - (float) $booking->paid_amount, "{$stepLabel} debt = total - paid", $r, $module);

                // Customer AR balance must be -(paid) at this point.
                $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$cumulativePaid, "{$stepLabel} cust balance", $r);

                $this->recon->assertAccountInvariant($customerAccountId, "{$stepLabel} cust invariant", $r);
                $this->recon->assertAccountInvariant($accountId, "{$stepLabel} treasury invariant", $r);
            }

            // Sanity: debt at the end must be 0.0
            $this->recon->assertZeroEGPDiff(0.0, (float) $booking->total_amount - (float) $booking->paid_amount, "{$scenario} final debt = 0", $r, $module);

            // ── Excessive-payment rejection test (info if rejected) ───────
            try {
                $service->addPayment($booking, [
                    'amount'         => 1.00,
                    'payment_method' => 'cash',
                    'account_id'     => $cashboxId,
                    'transaction_reference' => $this->ctx->prefix . 'OVERPAY-' . substr((string) $booking->id, 0, 6),
                    'payment_date'   => now()->toDateString(),
                ]);
                $r->recordFail(
                    scenario: 'excessive debt payment rejected (expected)',
                    expected: 'RuntimeException thrown for over-payment',
                    actual: 'payment accepted — over-payment guard absent',
                    severity: 'high',
                    context: ['module' => $module, 'root_cause' => 'over-payment guard missing on flight addPayment'],
                );
            } catch (\Throwable $expected) {
                $r->recordInfo('excessive debt payment rejected (expected)', 'OK: ' . get_class($expected) . ' thrown');
            }

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'debt lifecycle completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // HajjUmra debt lifecycle
    // ─────────────────────────────────────────────────────────────────────

    protected function runHajjUmraDebt(int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'hajj_umra';
        $total = 5000.00;
        $scenario = 'hajj debt';
        $paymentTable = 'hajj_umra_payments';
        $fkColumn = 'hajj_umra_booking_id';

        try {
            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => $total,
                'purchase_price' => 4000.00,
                'total_amount'   => $total,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\HajjUmra\HajjUmraBookingService::class);

            $steps = [
                ['amount' => 2000.00, 'method' => 'cash'],
                ['amount' => 1000.00, 'method' => 'wallet'],
                ['amount' =>  500.00, 'method' => 'cash'],
                ['amount' => 1500.00, 'method' => 'wallet'],
            ];

            $cumulativePaid = 0.0;
            foreach ($steps as $idx => $step) {
                $stepLabel = "{$scenario} step " . ($idx + 1) . " ({$step['method']} {$step['amount']})";
                $method = $step['method'];
                $amount = (float) $step['amount'];
                if ($method === 'wallet' && !$walletId) {
                    $r->recordSkip("{$stepLabel} wallet step", 'no wallet account seeded');
                    continue;
                }
                $accountId = $method === 'wallet' ? $walletId : $cashboxId;

                $cumulativePaid += $amount;
                $service->addPayment($booking, [
                    'amount'         => $amount,
                    'payment_method' => $method,
                    'account_id'     => $accountId,
                    'currency'       => 'EGP',
                    'payment_date'   => now()->toDateString(),
                ]);

                $booking->refresh();

                $recorded = (float) $this->recon->totalPaymentsRecorded($booking->id, $paymentTable, $fkColumn);
                $this->recon->assertZeroEGPDiff($cumulativePaid, $recorded, "{$stepLabel} SUM(payments)", $r, $module);
                $this->recon->assertZeroEGPDiff($cumulativePaid, (float) $booking->paid_amount, "{$stepLabel} booking.paid_amount", $r, $module);

                $expectedDebt = $total - $cumulativePaid;
                $this->recon->assertZeroEGPDiff($expectedDebt, (float) $booking->total_amount - (float) $booking->paid_amount, "{$stepLabel} debt = total - paid", $r, $module);

                $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$cumulativePaid, "{$stepLabel} cust balance", $r);
                $this->recon->assertAccountInvariant($customerAccountId, "{$stepLabel} cust invariant", $r);
                $this->recon->assertAccountInvariant($accountId, "{$stepLabel} treasury invariant", $r);
            }

            $this->recon->assertZeroEGPDiff(0.0, (float) $booking->total_amount - (float) $booking->paid_amount, "{$scenario} final debt = 0", $r, $module);

            try {
                $service->addPayment($booking, [
                    'amount'         => 1.00,
                    'payment_method' => 'cash',
                    'account_id'     => $cashboxId,
                    'currency'       => 'EGP',
                    'payment_date'   => now()->toDateString(),
                ]);
                $r->recordFail(
                    scenario: 'excessive debt payment rejected (expected)',
                    expected: 'RuntimeException thrown for over-payment',
                    actual: 'payment accepted — over-payment guard absent',
                    severity: 'high',
                    context: ['module' => $module, 'root_cause' => 'over-payment guard missing on hajj addPayment'],
                );
            } catch (\Throwable $expected) {
                $r->recordInfo('excessive debt payment rejected (expected)', 'OK: ' . get_class($expected) . ' thrown');
            }

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'debt lifecycle completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Visa debt lifecycle
    // ─────────────────────────────────────────────────────────────────────

    protected function runVisaDebt(int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'visa';
        $total = 5000.00;
        $serviceFee = 100.00;
        $scenario = 'visa debt';
        $paymentTable = 'visa_payments';
        $fkColumn = 'visa_booking_id';

        try {
            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => max($total - $serviceFee, 0),
                'purchase_price' => 4000.00,
                'service_fee'    => $serviceFee,
                'total_amount'   => $total,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\Visa\VisaBookingService::class);

            $steps = [
                ['amount' => 2000.00, 'method' => 'cash'],
                ['amount' => 1000.00, 'method' => 'wallet'],
                ['amount' =>  500.00, 'method' => 'cash'],
                ['amount' => 1500.00, 'method' => 'wallet'],
            ];

            $cumulativePaid = 0.0;
            foreach ($steps as $idx => $step) {
                $stepLabel = "{$scenario} step " . ($idx + 1) . " ({$step['method']} {$step['amount']})";
                $method = $step['method'];
                $amount = (float) $step['amount'];
                if ($method === 'wallet' && !$walletId) {
                    $r->recordSkip("{$stepLabel} wallet step", 'no wallet account seeded');
                    continue;
                }
                $accountId = $method === 'wallet' ? $walletId : $cashboxId;

                $cumulativePaid += $amount;
                $service->addPayment($booking, [
                    'amount'         => $amount,
                    'payment_method' => $method,
                    'account_id'     => $accountId,
                    'currency'       => 'EGP',
                    'payment_date'   => now()->toDateString(),
                ]);

                $booking->refresh();

                $recorded = (float) $this->recon->totalPaymentsRecorded($booking->id, $paymentTable, $fkColumn);
                $this->recon->assertZeroEGPDiff($cumulativePaid, $recorded, "{$stepLabel} SUM(payments)", $r, $module);
                $this->recon->assertZeroEGPDiff($cumulativePaid, (float) $booking->paid_amount, "{$stepLabel} booking.paid_amount", $r, $module);

                $expectedDebt = $total - $cumulativePaid;
                $this->recon->assertZeroEGPDiff($expectedDebt, (float) $booking->total_amount - (float) $booking->paid_amount, "{$stepLabel} debt = total - paid", $r, $module);

                $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$cumulativePaid, "{$stepLabel} cust balance", $r);
                $this->recon->assertAccountInvariant($customerAccountId, "{$stepLabel} cust invariant", $r);
                $this->recon->assertAccountInvariant($accountId, "{$stepLabel} treasury invariant", $r);
            }

            $this->recon->assertZeroEGPDiff(0.0, (float) $booking->total_amount - (float) $booking->paid_amount, "{$scenario} final debt = 0", $r, $module);

            try {
                $service->addPayment($booking, [
                    'amount'         => 1.00,
                    'payment_method' => 'cash',
                    'account_id'     => $cashboxId,
                    'currency'       => 'EGP',
                    'payment_date'   => now()->toDateString(),
                ]);
                $r->recordFail(
                    scenario: 'excessive debt payment rejected (expected)',
                    expected: 'RuntimeException thrown for over-payment',
                    actual: 'payment accepted — over-payment guard absent',
                    severity: 'high',
                    context: ['module' => $module, 'root_cause' => 'over-payment guard missing on visa addPayment'],
                );
            } catch (\Throwable $expected) {
                $r->recordInfo('excessive debt payment rejected (expected)', 'OK: ' . get_class($expected) . ' thrown');
            }

        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'debt lifecycle completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }
}