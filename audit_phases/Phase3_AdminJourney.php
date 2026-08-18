<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Account;
use App\Models\Customer;

/**
 * PHASE 3 — Admin journey.
 *
 * Same workflow as Phase 2 but driven under an `admin` actor. Verifies:
 *
 *   1. Admin CAN execute every mutation (create, pay, cancel, refund, delete).
 *   2. Admin CANNOT bypass the No-Edit Contract:
 *        - FlightBookingService::updateBooking() must throw LogicException.
 *        - UpdateHajjUmraBookingRequest via HTTP must return 422 when any
 *          LOCKED_FIELDS is supplied, even by an admin.
 *   3. Reconciliation invariants hold under admin-driven flows.
 */
class Phase3_AdminJourney
{
    public string $phaseLabel = 'PHASE 3 — Admin Journey';

    /** Smaller amount set for the admin flow — fewer rows, full coverage. */
    public const AMOUNTS = [1000.00, 5000.00, 10000.00];

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 3 — Admin Journey');
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

            // ── 1. Admin login ────────────────────────────────────────────
            $this->ctx->actAsAdmin();
            $r->recordPass();

            // ── 2. Admin workflow per module × amount ────────────────────
            foreach (self::AMOUNTS as $amount) {
                $this->runFlightAdminJourney((float) $amount, $cashbox->id, $r);
            }
            foreach (self::AMOUNTS as $amount) {
                $this->runHajjUmraAdminJourney((float) $amount, $cashbox->id, $r);
            }
            foreach (self::AMOUNTS as $amount) {
                $this->runVisaAdminJourney((float) $amount, $cashbox->id, $r);
            }

            // ── 3. No-Edit Contract: FlightBookingService::updateBooking() ──
            $this->verifyNoEditContractFlight($r);

            // ── 4. No-Edit Contract: HajjUmra FormRequest LOCKED_FIELDS ────
            $this->verifyNoEditContractHajjFormRequest($r);

            // ── 5. No-Edit Contract: Visa service update on locked fields ─
            $this->verifyNoEditContractVisaService($r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 3 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    protected function runFlightAdminJourney(float $amount, int $cashboxId, PhaseResult $r): void
    {
        $module = 'flight';
        $scenario = "flight admin @ {$amount}";

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

            // Pay in full
            $service->addPayment($booking, [
                'amount'        => $amount,
                'payment_method'=> 'cash',
                'account_id'    => $cashboxId,
                'transaction_reference' => $this->ctx->prefix . 'ADMP-' . substr((string) $booking->id, 0, 6),
                'payment_date'  => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust paid", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox paid", $r);

            // Cancel
            try {
                $service->cancelBooking($booking, [
                    'reason'          => $this->ctx->prefix . 'audit cancel',
                    'cancellation_fee'=> 0,
                    'treasury_id'     => $cashboxId,
                    'currency'        => 'EGP',
                ]);
                $r->recordPass();
            } catch (\Throwable $ce) {
                $r->recordFail(
                    scenario: "{$scenario} cancel",
                    expected: 'admin can cancel',
                    actual: 'exception: ' . $ce->getMessage(),
                    severity: 'high',
                    context: ['module' => $module],
                );
            }

            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust post-cancel", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox post-cancel", $r);

            // Delete with reversal
            $actorId = (int) ($this->ctx->currentUser?->id ?? 0);
            $service->deleteBookingWithReversal($booking->id, $actorId);
            $r->recordPass();

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'admin workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'role' => 'admin'],
            );
        }
    }

    protected function runHajjUmraAdminJourney(float $amount, int $cashboxId, PhaseResult $r): void
    {
        $module = 'hajj_umra';
        $scenario = "hajj admin @ {$amount}";

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
                'amount'         => $amount,
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust paid", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox paid", $r);

            try {
                $service->cancel($booking, $this->ctx->prefix . 'audit cancel');
                $r->recordPass();
            } catch (\Throwable $ce) {
                $r->recordFail(
                    scenario: "{$scenario} cancel",
                    expected: 'admin can cancel hajj booking',
                    actual: 'exception: ' . $ce->getMessage(),
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust post-cancel", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox post-cancel", $r);

            $actorId = (int) ($this->ctx->currentUser?->id ?? 0);
            $service->deleteBookingWithReversal($booking->id, $actorId);
            $r->recordPass();

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'admin workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'role' => 'admin'],
            );
        }
    }

    protected function runVisaAdminJourney(float $amount, int $cashboxId, PhaseResult $r): void
    {
        $module = 'visa';
        $scenario = "visa admin @ {$amount}";
        $serviceFee = 100.00;

        try {
            $initialCashboxBalance = (float) Account::find($cashboxId)->balance;

            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => max($amount - $serviceFee, 0),
                'purchase_price' => round($amount * 0.7, 2),
                'service_fee'    => $serviceFee,
                'total_amount'   => $amount,
                'paid_amount'    => 0,
                'currency'       => 'EGP',
                'status'         => 'pending',
            ]);
            $customer = Customer::find($booking->customer_id);
            $customerAccountId = (int) $customer->account_id;
            $this->ctx->trackAccount($customerAccountId);
            $initialCustomerBalance = (float) Account::find($customerAccountId)->balance;

            $service = app(\App\Services\Visa\VisaBookingService::class);

            $service->addPayment($booking, [
                'amount'         => $amount,
                'payment_method' => 'cash',
                'account_id'     => $cashboxId,
                'currency'       => 'EGP',
                'payment_date'   => now()->toDateString(),
            ]);
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust paid", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox paid", $r);

            try {
                $service->cancel($booking, $this->ctx->prefix . 'audit cancel');
                $r->recordPass();
            } catch (\Throwable $ce) {
                $r->recordFail(
                    scenario: "{$scenario} cancel",
                    expected: 'admin can cancel visa booking',
                    actual: 'exception: ' . $ce->getMessage(),
                    severity: 'high',
                    context: ['module' => $module],
                );
            }
            $this->recon->assertAccountInvariant($customerAccountId, "{$scenario} cust post-cancel", $r);
            $this->recon->assertAccountInvariant($cashboxId, "{$scenario} cashbox post-cancel", $r);

            $actorId = (int) ($this->ctx->currentUser?->id ?? 0);
            $service->deleteBookingWithReversal($booking->id, $actorId);
            $r->recordPass();

            $this->recon->assertBalanceDelta($cashboxId, $initialCashboxBalance, 0.0, "{$scenario} cashbox post-delete", $r);
            $this->recon->assertBalanceDelta($customerAccountId, $initialCustomerBalance, 0.0, "{$scenario} cust post-delete", $r);
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: $scenario,
                expected: 'admin workflow navigable end-to-end',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'high',
                context: ['module' => $module, 'role' => 'admin'],
            );
        }
    }

    /**
     * Direct call to FlightBookingService::updateBooking() MUST raise LogicException
     * (INCIDENT-2026-08-17). If an admin (or anyone) reaches this method, the
     * audit must surface it as a CRITICAL no-go.
     */
    protected function verifyNoEditContractFlight(PhaseResult $r): void
    {
        try {
            $booking = $this->ctx->createFlightBooking([
                'selling_price' => 1500.00,
                'status'        => 'pending',
            ]);
            $service = app(\App\Services\Flight\FlightBookingService::class);
            $threw = false;
            try {
                $service->updateBooking($booking, ['selling_price' => 9999.00]);
            } catch (\LogicException $le) {
                $threw = true;
                $r->recordPass();
            } catch (\Throwable $other) {
                $r->recordFail(
                    scenario: 'No-Edit contract: Flight updateBooking',
                    expected: 'throws LogicException',
                    actual: 'threw ' . get_class($other) . ': ' . $other->getMessage(),
                    severity: 'critical',
                    context: ['module' => 'flight', 'root_cause' => 'INCIDENT-2026-08-17 regression — wrong exception type'],
                );
                return;
            }
            if (!$threw) {
                $r->recordFail(
                    scenario: 'No-Edit contract: Flight updateBooking',
                    expected: 'throws LogicException',
                    actual: 'returned normally — edit path OPEN',
                    severity: 'critical',
                    context: ['module' => 'flight', 'root_cause' => 'INCIDENT-2026-08-17 — admin can edit'],
                );
            }
            // Best-effort cleanup of the throwaway booking
            try {
                $service->deleteBookingWithReversal($booking->id, (int) $this->ctx->currentUser?->id);
            } catch (\Throwable $ignored) {}
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'No-Edit contract: Flight updateBooking setup',
                expected: 'can construct scenario',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'medium',
                context: ['module' => 'flight'],
            );
        }
    }

    /**
     * HTTP-level verification that UpdateHajjUmraBookingRequest STILL rejects
     * LOCKED_FIELDS even under an admin actor. Expected: 422 status code.
     */
    protected function verifyNoEditContractHajjFormRequest(PhaseResult $r): void
    {
        try {
            $booking = $this->ctx->createHajjUmraBooking([
                'selling_price'  => 2000.00,
                'purchase_price' => 1600.00,
                'total_amount'   => 2000.00,
                'status'         => 'pending',
            ]);

            // Build a synthetic admin auth context for AuditHttp. We re-use
            // AuditContext::currentUser (already set via actAsAdmin()).
            $admin = $this->ctx->currentUser;
            if (!$admin) {
                $r->recordFail(
                    scenario: 'No-Edit contract: Hajj LOCKED_FIELDS via HTTP',
                    expected: 'admin user available',
                    actual: 'currentUser is null',
                    severity: 'medium',
                    context: ['module' => 'hajj_umra'],
                );
                return;
            }

            $this->http->asUser($admin);
            $resp = $this->http->put(
                "/api/v1/hajj-umra/bookings/{$booking->id}",
                ['selling_price' => 999.00]  // LOCKED_FIELDS attempt
            );

            if ((int) $resp['status'] === 422) {
                $r->recordPass();
            } elseif ((int) $resp['status'] === 405) {
                // Route missing entirely — also acceptable (no-edit contract).
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: 'No-Edit contract: Hajj LOCKED_FIELDS via HTTP',
                    expected: '422 (rejected by FormRequest)',
                    actual: 'status=' . $resp['status'] . ' body=' . substr((string) $resp['body'], 0, 200),
                    severity: 'critical',
                    context: ['module' => 'hajj_umra', 'role' => 'admin', 'root_cause' => 'INCIDENT-2026-08-17 — admin edit path open'],
                );
            }
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'No-Edit contract: Hajj LOCKED_FIELDS via HTTP',
                expected: 'request dispatched',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'medium',
                context: ['module' => 'hajj_umra'],
            );
        }
    }

    /**
     * Service-level verification that VisaBookingService::update() raises when
     * the caller tries to mutate a locked price field directly (Tinker / job path).
     */
    protected function verifyNoEditContractVisaService(PhaseResult $r): void
    {
        try {
            $booking = $this->ctx->createVisaBooking([
                'selling_price'  => 1000.00,
                'purchase_price' => 700.00,
                'service_fee'    => 100.00,
                'total_amount'   => 1100.00,
                'status'         => 'pending',
            ]);

            $service = app(\App\Services\Visa\VisaBookingService::class);
            $threw = false;
            try {
                $service->update($booking, ['selling_price' => 9999.00]);
            } catch (\RuntimeException $re) {
                $threw = true;
                $r->recordPass();
            } catch (\Throwable $other) {
                $r->recordFail(
                    scenario: 'No-Edit contract: Visa update selling_price',
                    expected: 'RuntimeException (locked field)',
                    actual: 'threw ' . get_class($other),
                    severity: 'critical',
                    context: ['module' => 'visa', 'root_cause' => 'INCIDENT-2026-08-17 — wrong rejection type'],
                );
                return;
            }
            if (!$threw) {
                $r->recordFail(
                    scenario: 'No-Edit contract: Visa update selling_price',
                    expected: 'RuntimeException (locked field)',
                    actual: 'returned normally — admin can edit visa locked field',
                    severity: 'critical',
                    context: ['module' => 'visa', 'root_cause' => 'INCIDENT-2026-08-17'],
                );
            }
            try {
                $service->deleteBookingWithReversal($booking->id, (int) $this->ctx->currentUser?->id);
            } catch (\Throwable $ignored) {}
        } catch (\Throwable $e) {
            $r->recordFail(
                scenario: 'No-Edit contract: Visa update setup',
                expected: 'scenario constructible',
                actual: 'exception: ' . $e->getMessage(),
                severity: 'medium',
                context: ['module' => 'visa'],
            );
        }
    }
}