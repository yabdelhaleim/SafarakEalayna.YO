<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Customer;

/**
 * PHASE 4 — Payment method matrix.
 *
 * For each Tourism module, drives four payment patterns and asserts that the
 * SUM(account_entries) invariant still holds on every account touched:
 *
 *   A. Cash only           — single 1000 EGP cash payment, full settlement.
 *   B. Wallet only         — single 1000 EGP wallet payment, full settlement.
 *   C. Cash + Wallet partial — 400 wallet, 600 cash, full settlement.
 *   D. Multiple partial    — 4 fragmented payments totalling 5000 EGP.
 *
 * Expected delta on the customer AR account is always -$total (they owe us).
 * Expected delta on the treasury (cashbox or wallet) is +$total (we collected).
 */
class Phase4_PaymentMatrix
{
    public string $phaseLabel = 'PHASE 4 — Payment Matrix';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 4 — Payment Matrix');
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

            // ── Flight scenarios ─────────────────────────────────────────
            $this->runFlightScenario('A', $cashbox->id, $wallet?->id, $r);
            $this->runFlightScenario('B', $cashbox->id, $wallet?->id, $r);
            $this->runFlightScenario('C', $cashbox->id, $wallet?->id, $r);
            $this->runFlightScenario('D', $cashbox->id, $wallet?->id, $r);

            // ── HajjUmra scenarios ───────────────────────────────────────
            $this->runHajjUmraScenario('A', $cashbox->id, $wallet?->id, $r);
            $this->runHajjUmraScenario('B', $cashbox->id, $wallet?->id, $r);
            $this->runHajjUmraScenario('C', $cashbox->id, $wallet?->id, $r);
            $this->runHajjUmraScenario('D', $cashbox->id, $wallet?->id, $r);

            // ── Visa scenarios ───────────────────────────────────────────
            $this->runVisaScenario('A', $cashbox->id, $wallet?->id, $r);
            $this->runVisaScenario('B', $cashbox->id, $wallet?->id, $r);
            $this->runVisaScenario('C', $cashbox->id, $wallet?->id, $r);
            $this->runVisaScenario('D', $cashbox->id, $wallet?->id, $r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 4 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Flight scenarios
    // ─────────────────────────────────────────────────────────────────────

    protected function runFlightScenario(string $code, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'flight';
        $total = 1000.00;
        $scenario = "flight matrix {$code}";

        try {
            $booking = $this->ctx->createFlightBooking([
                'selling_price'         => $total,
                'purchase_price'        => round($total * 0.8, 2),
                'selling_price_foreign' => $total,
                'purchase_price_foreign'=> round($total * 0.8, 2),
                'currency'              => 'EGP',
                'status'                => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;
            $initialCashbox  = (float) Account::find($cashboxId)->balance;
            $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

            $service = app(\App\Services\Flight\FlightBookingService::class);

            switch ($code) {
                case 'A':
                    $service->addPayment($booking, $this->payload('cash', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, $total, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'B':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, $total, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'C':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', 400.00, $cashboxId, $walletId));
                    $service->addPayment($booking, $this->payload('cash', 600.00, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, 400.00, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 600.00, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -1000.00, "{$scenario} cust", $r);
                    break;

                case 'D':
                    $booking2 = $booking;  // use same booking pattern but increase selling
                    // Re-create with 5000 total to keep partial logic correct.
                    $booking2 = $this->ctx->createFlightBooking([
                        'selling_price'         => 5000.00,
                        'purchase_price'        => 4000.00,
                        'selling_price_foreign' => 5000.00,
                        'purchase_price_foreign'=> 4000.00,
                        'currency'              => 'EGP',
                        'status'                => 'pending',
                    ]);
                    $customerAccountId = (int) Customer::find($booking2->customer_id)->account_id;
                    $this->ctx->trackAccount($customerAccountId);
                    $initialCustomer = (float) Account::find($customerAccountId)->balance;
                    $initialCashbox  = (float) Account::find($cashboxId)->balance;
                    $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

                    $service->addPayment($booking2, $this->payload('cash', 1000.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 750.00, $cashboxId, $walletId) : $this->payload('cash', 750.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $this->payload('cash', 1250.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 2000.00, $cashboxId, $walletId) : $this->payload('cash', 2000.00, $cashboxId, $walletId));

                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 1000.00 + 1250.00, "{$scenario} cashbox", $r);
                    if ($walletId) {
                        $this->recon->assertBalanceDelta($walletId, $initialWallet, 750.00 + 2000.00, "{$scenario} wallet", $r);
                    }
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -5000.00, "{$scenario} cust", $r);
                    break;
            }

            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust invariant", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox invariant", $r);
            if ($walletId) {
                $this->recon->assertAccountInvariant($walletId, "{$scenario} wallet invariant", $r);
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'payment matrix scenario completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // HajjUmra scenarios
    // ─────────────────────────────────────────────────────────────────────

    protected function runHajjUmraScenario(string $code, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'hajj_umra';
        $total = 1000.00;
        $scenario = "hajj matrix {$code}";

        try {
            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => $total,
                'purchase_price' => round($total * 0.8, 2),
                'total_amount'   => $total,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;
            $initialCashbox  = (float) Account::find($cashboxId)->balance;
            $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

            $service = app(\App\Services\HajjUmra\HajjUmraBookingService::class);

            switch ($code) {
                case 'A':
                    $service->addPayment($booking, $this->payload('cash', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, $total, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'B':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, $total, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'C':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', 400.00, $cashboxId, $walletId));
                    $service->addPayment($booking, $this->payload('cash', 600.00, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, 400.00, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 600.00, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -1000.00, "{$scenario} cust", $r);
                    break;

                case 'D':
                    $booking2 = $this->ctx->createHajjUmraBooking([
                        'selling_price'  => 5000.00,
                        'purchase_price' => 4000.00,
                        'total_amount'   => 5000.00,
                        'paid_amount'    => 0,
                        'currency'       => 'EGP',
                        'status'         => 'pending',
                    ]);
                    $customerAccountId = (int) Customer::find($booking2->customer_id)->account_id;
                    $this->ctx->trackAccount($customerAccountId);
                    $initialCustomer = (float) Account::find($customerAccountId)->balance;
                    $initialCashbox  = (float) Account::find($cashboxId)->balance;
                    $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

                    $service->addPayment($booking2, $this->payload('cash', 1000.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 750.00, $cashboxId, $walletId) : $this->payload('cash', 750.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $this->payload('cash', 1250.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 2000.00, $cashboxId, $walletId) : $this->payload('cash', 2000.00, $cashboxId, $walletId));

                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 1000.00 + 1250.00, "{$scenario} cashbox", $r);
                    if ($walletId) {
                        $this->recon->assertBalanceDelta($walletId, $initialWallet, 750.00 + 2000.00, "{$scenario} wallet", $r);
                    }
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -5000.00, "{$scenario} cust", $r);
                    break;
            }

            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust invariant", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox invariant", $r);
            if ($walletId) {
                $this->recon->assertAccountInvariant($walletId, "{$scenario} wallet invariant", $r);
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'payment matrix scenario completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Visa scenarios
    // ─────────────────────────────────────────────────────────────────────

    protected function runVisaScenario(string $code, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'visa';
        $total = 1000.00;
        $serviceFee = 100.00;
        $scenario = "visa matrix {$code}";

        try {
            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => max($total - $serviceFee, 0),
                'purchase_price' => round($total * 0.7, 2),
                'service_fee'    => $serviceFee,
                'total_amount'   => $total,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);
            $customerAccountId = (int) Customer::find($booking->customer_id)->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomer = (float) Account::find($customerAccountId)->balance;
            $initialCashbox  = (float) Account::find($cashboxId)->balance;
            $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

            $service = app(\App\Services\Visa\VisaBookingService::class);

            switch ($code) {
                case 'A':
                    $service->addPayment($booking, $this->payload('cash', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, $total, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'B':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', $total, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, $total, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -$total, "{$scenario} cust", $r);
                    break;

                case 'C':
                    if (!$walletId) {
                        $r->recordSkip($scenario, 'no wallet account seeded');
                        return;
                    }
                    $service->addPayment($booking, $this->payload('wallet', 400.00, $cashboxId, $walletId));
                    $service->addPayment($booking, $this->payload('cash', 600.00, $cashboxId, $walletId));
                    $this->recon->assertBalanceDelta($walletId, $initialWallet, 400.00, "{$scenario} wallet", $r);
                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 600.00, "{$scenario} cashbox", $r);
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -1000.00, "{$scenario} cust", $r);
                    break;

                case 'D':
                    $booking2 = $this->ctx->createVisaBooking([
                        'selling_price'  => max(5000 - $serviceFee, 0),
                        'purchase_price' => 4000.00,
                        'service_fee'    => $serviceFee,
                        'total_amount'   => 5000.00,
                        'paid_amount'    => 0,
                        'currency'       => 'EGP',
                        'status'         => 'pending',
                    ]);
                    $customerAccountId = (int) Customer::find($booking2->customer_id)->account_id;
                    $this->ctx->trackAccount($customerAccountId);
                    $initialCustomer = (float) Account::find($customerAccountId)->balance;
                    $initialCashbox  = (float) Account::find($cashboxId)->balance;
                    $initialWallet   = $walletId ? (float) Account::find($walletId)->balance : 0.0;

                    $service->addPayment($booking2, $this->payload('cash', 1000.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 750.00, $cashboxId, $walletId) : $this->payload('cash', 750.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $this->payload('cash', 1250.00, $cashboxId, $walletId));
                    $service->addPayment($booking2, $walletId ? $this->payload('wallet', 2000.00, $cashboxId, $walletId) : $this->payload('cash', 2000.00, $cashboxId, $walletId));

                    $this->recon->assertBalanceDelta($cashboxId, $initialCashbox, 1000.00 + 1250.00, "{$scenario} cashbox", $r);
                    if ($walletId) {
                        $this->recon->assertBalanceDelta($walletId, $initialWallet, 750.00 + 2000.00, "{$scenario} wallet", $r);
                    }
                    $this->recon->assertBalanceDelta($customerAccountId, $initialCustomer, -5000.00, "{$scenario} cust", $r);
                    break;
            }

            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust invariant", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox invariant", $r);
            if ($walletId) {
                $this->recon->assertAccountInvariant($walletId, "{$scenario} wallet invariant", $r);
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'payment matrix scenario completes',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module],
            );
        }
    }

    /**
     * Build the payment payload used by all addPayment() calls in this phase.
     * The cashbox / wallet pick is automatic based on payment_method.
     */
    protected function payload(string $method, float $amount, int $cashboxId, ?int $walletId): array
    {
        if ($method === 'wallet') {
            if (!$walletId) {
                throw new \RuntimeException('wallet method requested but no wallet account available');
            }
            return [
                'amount'         => $amount,
                'payment_method' => 'wallet',
                'account_id'     => $walletId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ];
        }
        return [
            'amount'         => $amount,
            'payment_method' => 'cash',
            'account_id'     => $cashboxId,
            'currency'       => 'EGP',
            'payment_date'   => now()->toDateString(),
        ];
    }
}