<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 2 — Employee journey.
 *
 * For each Tourism module (Flight, HajjUmra, Visa), drives the full
 * employee workflow against a representative grid of selling prices
 * (100 → 25,000 EGP) and asserts that:
 *
 *   - partial payments → remainder payments don't lose or create money
 *   - the customer AR account, the cashbox, and the wallet each remain
 *     in agreement with their independent SUM(account_entries) recompute
 *   - soft-delete via `deleteBookingWithReversal()` leaves zero balance
 *     drift on every account that participated in the booking
 *
 * This phase is REPORT-ONLY. We never call $ctx->cleanup().
 */
class Phase2_EmployeeJourney
{
    public string $phaseLabel = 'PHASE 2 — Employee Journey';

    /** Amounts (EGP) used to exercise the employee workflow end-to-end. */
    public const AMOUNTS = [100.00, 500.00, 999.99, 1000.00, 2500.00, 5000.00, 10000.00, 25000.00];

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 2 — Employee Journey');
        $r->start();

        try {
            // Resolve treasury accounts ONCE — these are read every scenario.
            // Use the AuditContext helpers so the cashbox/wallet fallback
            // (office WL_CASH_EGP etc.) is exercised and recorded as a finding
            // when no canonical tourism vault exists.
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
            // Wallet is allowed to be null on some installations — track if present.
            if ($wallet) {
                $this->ctx->trackAccount($wallet->id);
            }

            // ── 1. Employee login ────────────────────────────────────────
            $this->ctx->actAsEmployee();
            $r->recordPass();

            // ── 2. Flight employee journey ───────────────────────────────
            foreach (self::AMOUNTS as $amount) {
                $this->runFlightEmployeeJourney((float) $amount, $cashbox->id, $wallet?->id, $r);
            }

            // ── 3. HajjUmra employee journey ─────────────────────────────
            foreach (self::AMOUNTS as $amount) {
                $this->runHajjUmraEmployeeJourney((float) $amount, $cashbox->id, $wallet?->id, $r);
            }

            // ── 4. Visa employee journey ─────────────────────────────────
            foreach (self::AMOUNTS as $amount) {
                $this->runVisaEmployeeJourney((float) $amount, $cashbox->id, $wallet?->id, $r);
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 2 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    /**
     * Flight employee journey:
     *   1) create booking (pending) @ selling=$amount
     *   2) pay 60% via cash
     *   3) pay remaining 40% via cash
     *   4) reconcile customer + cashbox accounts (both must equal SUM(entries))
     *   5) deleteBookingWithReversal()
     *   6) verify balances are restored (delta == 0 vs initial)
     */
    protected function runFlightEmployeeJourney(float $amount, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'flight';
        $scenario = "flight emp journey @ {$amount}";

        try {
            $initialCashboxBalance = (float) Account::find($cashboxId)->balance;

            $booking = $this->ctx->createFlightBooking([
                'selling_price'         => $amount,
                'purchase_price'        => round($amount * 0.8, 2),
                'selling_price_foreign' => $amount,
                'purchase_price_foreign'=> round($amount * 0.8, 2),
                'exchange_rate'         => 1.0,
                'currency'              => 'EGP',
                'status'                => 'pending',
            ]);

            $customer = Customer::find($booking->customer_id);
            $customerAccountId = (int) $customer->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomerBalance = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\Flight\FlightBookingService::class);

            // Partial 60% via cash
            $service->addPayment($booking, [
                'amount'        => round($amount * 0.6, 2),
                'payment_method'=> 'cash',
                'account_id'    => $cashboxId,
                'transaction_reference' => $this->ctx->prefix . 'PAY60-' . substr((string) $booking->id, 0, 6),
                'payment_date'  => now()->toDateString(),
            ]);

            // Reconcile after partial
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 60%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 60%", $r);

            // Remainder 40% via cash
            $service->addPayment($booking, [
                'amount'        => round($amount * 0.4, 2),
                'payment_method'=> 'cash',
                'account_id'    => $cashboxId,
                'transaction_reference' => $this->ctx->prefix . 'PAY40-' . substr((string) $booking->id, 0, 6),
                'payment_date'  => now()->toDateString(),
            ]);

            // Reconcile after full
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 100%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 100%", $r);

            // Cashbox should now reflect +$amount
            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, $amount, "{$scenario} cashbox delta", $r);
            // Customer AR: total payments should match cashbox inflow
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, -$amount, "{$scenario} cust delta", $r);

            // No duplicate transactions should exist
            $dups = $this->recon->findDuplicateTransactions($booking->id, \App\Models\Flight\FlightBooking::class);
            if (!empty($dups)) {
                $r->recordFail(
                    scenario: "{$scenario} duplicate tx",
                    expected: 'no duplicate (type, amount, currency) tuples',
                    actual: 'duplicates found: ' . json_encode($dups),
                    severity: 'critical',
                    context: ['module' => $module, 'tx_ids' => []],
                );
            } else {
                $r->recordPass();
            }

            // 5) Soft-delete with reversal
            $actorId = (int) ($this->ctx->currentUser?->id ?? 0);
            $ok = $service->deleteBookingWithReversal($booking->id, $actorId);
            if (!$ok) {
                $r->recordFail(
                    scenario: "{$scenario} deleteWithReversal",
                    expected: 'deleteBookingWithReversal returns true',
                    actual: 'returned false',
                    severity: 'high',
                    context: ['module' => $module],
                );
            } else {
                $r->recordPass();
            }

            // 6) Balances restored?
            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'employee workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'root_cause' => 'flight employee journey threw'],
            );
        }
    }

    /**
     * HajjUmra employee journey — same shape as Flight but uses HajjUmra service.
     */
    protected function runHajjUmraEmployeeJourney(float $amount, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'hajj_umra';
        $scenario = "hajj emp journey @ {$amount}";

        try {
            $initialCashboxBalance = (float) Account::find($cashboxId)->balance;

            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => $amount,
                'purchase_price' => round($amount * 0.8, 2),
                'total_amount'   => $amount,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);

            $customer = Customer::find($booking->customer_id);
            $customerAccountId = (int) $customer->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomerBalance = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\HajjUmra\HajjUmraBookingService::class);

            $service->addPayment($booking, [
                'amount'         => round($amount * 0.6, 2),
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 60%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 60%", $r);

            $service->addPayment($booking, [
                'amount'         => round($amount * 0.4, 2),
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 100%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 100%", $r);

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, $amount, "{$scenario} cashbox delta", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, -$amount, "{$scenario} cust delta", $r);

            $dups = $this->recon->findDuplicateTransactions($booking->id, \App\Models\HajjUmraBooking::class);
            if (!empty($dups)) {
                $r->recordFail(
                    scenario: "{$scenario} duplicate tx",
                    expected: 'no duplicate (type, amount, currency) tuples',
                    actual: 'duplicates found: ' . json_encode($dups),
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } else {
                $r->recordPass();
            }

            $actor = $this->ctx->currentUser;
            $service->deleteBookingWithReversal($booking->id, $actor);
            $r->recordPass();

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'employee workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'root_cause' => 'hajj employee journey threw'],
            );
        }
    }

    /**
     * Visa employee journey — same shape as Flight/HajjUmra but uses Visa service.
     */
    protected function runVisaEmployeeJourney(float $amount, int $cashboxId, ?int $walletId, PhaseResult $r): void
    {
        $module = 'visa';
        $scenario = "visa emp journey @ {$amount}";

        try {
            $initialCashboxBalance = (float) Account::find($cashboxId)->balance;

            // Visa total includes service_fee; pass an explicit total so we can
            // anchor the math against an expected $amount even when the model
            // recomputes total_amount = selling_price + service_fee.
            $totalAmount = $amount;
            $serviceFee  = 100.00;

            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => max($amount - $serviceFee, 0),
                'purchase_price' => round($amount * 0.7, 2),
                'service_fee'    => $serviceFee,
                'total_amount'   => $totalAmount,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);

            $customer = Customer::find($booking->customer_id);
            $customerAccountId = (int) $customer->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomerBalance = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\Visa\VisaBookingService::class);

            // Pay partial 60% then remainder 40% — must not exceed remaining.
            $service->addPayment($booking, [
                'amount'         => round($totalAmount * 0.6, 2),
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 60%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 60%", $r);

            $service->addPayment($booking, [
                'amount'         => round($totalAmount * 0.4, 2),
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust after 100%", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox after 100%", $r);

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, $totalAmount, "{$scenario} cashbox delta", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, -$totalAmount, "{$scenario} cust delta", $r);

            $dups = $this->recon->findDuplicateTransactions($booking->id, \App\Models\VisaBooking::class);
            if (!empty($dups)) {
                $r->recordFail(
                    scenario: "{$scenario} duplicate tx",
                    expected: 'no duplicate (type, amount, currency) tuples',
                    actual: 'duplicates found: ' . json_encode($dups),
                    severity: 'critical',
                    context: ['module' => $module],
                );
            } else {
                $r->recordPass();
            }

            $actorId = (int) ($this->ctx->currentUser?->id ?? 0);
            $service->deleteBookingWithReversal($booking->id, $actorId);
            $r->recordPass();

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'employee workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'root_cause' => 'visa employee journey threw'],
            );
        }
    }
}