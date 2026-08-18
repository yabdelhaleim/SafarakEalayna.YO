<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 9 — Visa Full Audit.
 *
 * Tests:
 *  - Booking creation with visa type/agent/duration
 *  - Payments (single, partial, multiple, full)
 *  - D02 — Negative price rejected at service layer
 *  - D01 — Payment uses recordJournalTransfer (NOT recordIncome)
 *  - Idempotency replay
 *  - Cancellation (VisaRefundService::cancel)
 *  - Refund (VisaRefundService::refund)
 *  - Modification (repost expense/income)
 *  - Lifecycle guards
 */
class VisaFullAuditTest extends TourismAuditTestCase
{
    protected VisaBookingService $bookingService;

    protected VisaRefundService $refundService;

    protected Customer $customer;

    protected VisaDuration $duration;

    protected VisaAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(VisaBookingService::class);
        $this->refundService = app(VisaRefundService::class);

        $this->customer = Customer::query()->create([
            'full_name' => 'Visa Audit Customer',
            'phone' => '01400000001',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => 'AUDIT-V-30D',
            'label_ar' => '30 يوم',
            'label_en' => '30 days',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        LedgerBalanceMutationGuard::run(function () {
            $agentAccount = Account::query()->create([
                'name' => 'حساب وكيل فيزا - Audit',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'notes' => 'Visa Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);

            $this->agent = VisaAgent::query()->create([
                'company_name' => 'Audit Visa Agent',
                'contact_person' => 'Audit Contact',
                'phone' => '01400000002',
                'email' => 'audit-visa-agent@example.com',
                'country' => 'EG',
                'visa_type' => 'tourist',
                'default_cost_price' => 800.0,
                'account_id' => $agentAccount->id,
                'is_active' => true,
                'notes' => 'Visa Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);
        });
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'agent_name' => 'Audit Test Agent',
            'notes' => 'Audit 2026-08-17',
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'AUDIT-LAND',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'Audit Executing Co',
                'executing_agent' => 'Audit Agent',
                'executing_agent_contact' => '01000000000',
                'visa_agent_id' => $this->agent->id,
            ],
        ], $overrides);
    }

    /**
     * Booking creation happy path.
     */
    public function test_create_booking(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->assertNotNull($booking);
        $this->assertSame(VisaBooking::class, get_class($booking));

        // Verify transactions
        $this->assertNotNull($booking->fresh()->expense_transaction_id);
        $this->assertNotNull($booking->fresh()->income_transaction_id);

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * D02 — Negative prices rejected at service layer.
     */
    public function test_service_create_rejects_negative_purchase_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->create($this->bookingPayload([
            'purchase_price' => -100.0,
        ]));
    }

    public function test_service_create_rejects_negative_selling_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->create($this->bookingPayload([
            'selling_price' => -100.0,
        ]));
    }

    public function test_service_create_rejects_negative_service_fee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->create($this->bookingPayload([
            'service_fee' => -100.0,
        ]));
    }

    /**
     * D01 — Payment uses recordJournalTransfer (NOT recordIncome).
     */
    public function test_payment_uses_transfer_not_income(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $payment = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->assertNotNull($payment->transaction_id);

        $paymentTx = Transaction::query()->find($payment->transaction_id);
        $this->assertNotNull($paymentTx);
        $this->assertSame('transfer', $paymentTx->type->value ?? (string) $paymentTx->type, 'D01: payment must be Transfer, not Income');

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Idempotency replay.
     */
    public function test_payment_idempotency_replay(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $key = 'audit-visa-idem-'.uniqid();

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertFalse($first->idempotent_replay ?? false);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => $key,
        ]);

        $this->assertTrue($second->idempotent_replay ?? false);
        $this->assertSame($first->id, $second->id);

        $this->assertSame(1, VisaPayment::query()->where('visa_booking_id', $booking->id)->count());
        $this->assertLedgerGloballyBalanced();
    }

    public function test_payment_different_keys_create_distinct(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $first = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 300.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-visa-key-A',
        ]);

        $second = $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 700.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
            'idempotency_key' => 'audit-visa-key-B',
        ]);

        $this->assertNotSame($first->id, $second->id);

        $this->assertEquals(1000.0, round((float) $booking->fresh()->paid_amount, 2));
    }

    /**
     * Multiple partial payments.
     */
    public function test_multiple_payments(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->assertEquals(1600.0, round((float) $booking->fresh()->paid_amount, 2));
        $this->assertTrue($booking->fresh()->is_fully_paid);

        $this->assertLedgerGloballyBalanced();
    }

    /**
     * Cancellation additive reversal.
     */
    public function test_cancellation_additive_reversal(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $originalExpenseId = $booking->fresh()->expense_transaction_id;
        $originalIncomeId = $booking->fresh()->income_transaction_id;

        $this->refundService->cancel($booking->fresh(), 'Audit cancel');

        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status->value ?? (string) $fresh->status);

        // Original transactions must still exist
        $this->assertNotNull(Transaction::query()->find($originalExpenseId));
        $this->assertNotNull(Transaction::query()->find($originalIncomeId));

        $this->assertLedgerGloballyBalanced();
    }

    public function test_double_cancel_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->refundService->cancel($booking->fresh(), 'First');

        $this->expectException(\Exception::class);
        $this->refundService->cancel($booking->fresh(), 'Second');
    }

    /**
     * Refund sets status=refunded.
     */
    public function test_refund(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $this->refundService->refund($booking->fresh(), 'Audit refund');

        $fresh = $booking->fresh();
        $this->assertSame('refunded', $fresh->status->value ?? (string) $fresh->status);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_refund_on_cancelled_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->refundService->cancel($booking->fresh(), 'Cancel');

        $this->expectException(\Exception::class);
        $this->refundService->refund($booking->fresh(), 'Refund');
    }

    /**
     * Payment on cancelled booking is blocked.
     */
    public function test_payment_on_cancelled_blocked(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->refundService->cancel($booking->fresh(), 'Cancel');

        $this->expectException(\Exception::class);
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);
    }

    // test_modification_reposts_income / test_modification_rejects_negative_price
// — REMOVED (INCIDENT-2026-08-17 Tourism no-edit contract)
//   Both tests called $this->bookingService->update(...) to verify price-repost
//   accounting behavior. With Edit permanently disabled (LogicException stub) the
//   premise is moot — no Edit path exists at all. Cancellation is the correction path.

/**
 * All Visa transactions tagged as Tourism.
 */
    public function test_all_transactions_tagged_as_tourism(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 500.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
            'currency' => 'EGP',
        ]);

        $paymentId = VisaPayment::query()->where('visa_booking_id', $booking->id)->first()?->id ?? 0;

        $transactions = Transaction::query()
            ->where(function ($q) use ($booking, $paymentId) {
                $q->where(function ($q2) use ($booking) {
                    $q2->where('related_type', VisaBooking::class)->where('related_id', $booking->id);
                });
                if ($paymentId) {
                    $q->orWhere(function ($q2) use ($paymentId) {
                        $q2->where('related_type', VisaPayment::class)->where('related_id', $paymentId);
                    });
                }
            })
            ->get();

        $this->assertGreaterThan(0, $transactions->count());
        foreach ($transactions as $tx) {
            $this->assertTransactionIsTourism($tx);
        }
    }

    /**
     * Customer account uses visas module_type after booking.
     */
    public function test_customer_account_module_type_visas(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $customerAccount = $this->customer->fresh()->ledgerAccount;
        $this->assertNotNull($customerAccount);
        $this->assertSame('visas', $customerAccount->fresh()->module_type);
    }

    /**
     * Visa agent account used for expense posting.
     */
    public function test_expense_posted_to_visa_agent_account(): void
    {
        $booking = $this->bookingService->create($this->bookingPayload());

        $expenseTx = Transaction::query()->find($booking->fresh()->expense_transaction_id);
        $this->assertNotNull($expenseTx);

        // The expense should be posted to either the visa agent account or the vault.
        $entries = \App\Models\AccountEntry::query()->where('transaction_id', $expenseTx->id)->get();
        $accountIds = $entries->pluck('account_id')->toArray();

        $this->assertContains($this->agent->account_id, $accountIds, 'Expense should be posted to visa agent account');
    }
}
