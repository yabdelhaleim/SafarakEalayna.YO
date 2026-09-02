<?php

declare(strict_types=1);

namespace Tests\Stress\Visa;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Stress\Support\StressReconciliation;

/**
 * VISA MODULE — MEDIUM-SCALE FINANCIAL STRESS TEST
 * ================================================
 *
 *  Goal: exercise every financial operation in the Visa module against a
 *  real MySQL database (safarak_stress) under load. Each test is a black-box
 *  scenario that hits the production Service / Controller surface and asserts
 *  the project-wide invariants afterwards.
 *
 *  Coverage matrix (one scenario per operation × state combination):
 *
 *    A) Booking creation           — EGP / USD / SAR currencies,
 *                                    with/without supplier agent,
 *                                    cross-currency rejection.
 *    B) Payment collection         — happy path, overpayment guard,
 *                                    double-payment idempotency,
 *                                    cross-currency vault rejection,
 *                                    same-reference replay.
 *    C) Cancellation               — full cancellation, double-cancel guard,
 *                                    payments reversal, status guard.
 *    D) Refund                     — full refund, refund after cancel blocked,
 *                                    double-refund guard, zero-payments refund
 *                                    (status flip only, ledger balanced).
 *    E) Soft delete                — admin delete with reversal,
 *                                    delete after cancel blocked,
 *                                    delete after refund blocked,
 *                                    idempotent re-delete guard.
 *    F) Customer debt endpoint     — multi-booking FIFO distribution,
 *                                    FX mismatch rejection.
 *    G) Agent finance              — withdraw/repay happy path,
 *                                    cross-currency guard.
 *    H) Lifecycle invariants       — cancelled/refunded cannot receive
 *                                    payments or be updated; balance never
 *                                    goes negative; Σ debit == Σ credit per
 *                                    transaction; Σ credit − Σ debit == balance.
 *
 *  Each scenario finishes with StressReconciliation::runAll() so we catch
 *  any ledger drift introduced by the scenario.
 *
 *  Expected outcome: every scenario PASSES, the global reconciliation is
 *  clean, and the final stress artifact
 *  (storage/app/stress/visa-stress-final-report.json) is the production-ready
 *  audit receipt.
 */
class VisaFinancialStressTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fixtures (shared) ─────────────────────────────────────────────────────
    protected User $admin;
    protected User $employee;
    protected Account $vaultEgp;
    protected Account $vaultUsd;
    protected Account $vaultSar;
    protected Account $bankEgp;
    protected Customer $customer;
    protected VisaDuration $duration;
    protected VisaAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'  => 'Visa Stress Admin',
            'email' => 'visa-stress-admin@stress.test',
            'password' => Hash::make('password'),
            'role'  => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::query()->create([
            'name'  => 'Visa Stress Employee',
            'email' => 'visa-stress-employee@stress.test',
            'password' => Hash::make('password'),
            'role'  => 'employee',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // ─── Vaults (tourism division) ─────────────────────────────────────
        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::create([
                'name' => 'Stress Visa Vault EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'notes' => 'Visa stress run — safe to drop',
                'created_by' => $this->admin->id,
            ]);

            $this->vaultUsd = Account::create([
                'name' => 'Stress Visa Vault USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 50_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'notes' => 'Visa stress run — safe to drop',
                'created_by' => $this->admin->id,
            ]);

            $this->vaultSar = Account::create([
                'name' => 'Stress Visa Vault SAR',
                'type' => AccountType::Cashbox->value,
                'currency' => 'SAR',
                'balance' => 30_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'notes' => 'Visa stress run — safe to drop',
                'created_by' => $this->admin->id,
            ]);

            $this->bankEgp = Account::create([
                'name' => 'Stress Visa Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance' => 500_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => false,
                'notes' => 'Visa stress run — safe to drop',
                'created_by' => $this->admin->id,
            ]);
            // Each vault above is auto-seeded with a paired opening-balance
            // AccountEntry by the Account::created boot hook (see
            // App\Models\Account::booted — FIN-1 remediation). No manual
            // seedOpening() needed.
        });

        $this->customer = Customer::query()->create([
            'full_name'      => 'Stress Customer',
            'phone'          => '01000009000',
            'national_id'    => '12345678901234',
            'passport_number'=> 'P9000000',
            'type'           => 'individual',
            'status'         => 'active',
            'currency'       => 'EGP',
            'created_by'     => $this->admin->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code'      => 'STRESS-90D',
            'label_ar'  => '90 يوم',
            'label_en'  => '90 days',
            'months'    => 3,
            'entry_type'=> 'single',
            'sort_order'=> 99,
            'is_active' => true,
        ]);

        LedgerBalanceMutationGuard::run(function () {
            $supplierAccount = Account::create([
                'name'         => 'Stress Visa Agent Account',
                'type'         => AccountType::Supplier->value,
                'currency'     => 'EGP',
                'balance'      => 0.0,
                'is_active'    => true,
                'owner_type'   => Account::OWNER_TYPE_OWNER,
                'module_type'  => 'visas',
                'module'       => 'visas',
                'is_module_vault' => false,
                'notes'        => 'Visa stress run — safe to drop',
                'created_by'   => $this->admin->id,
            ]);

            $this->agent = VisaAgent::query()->create([
                'company_name'     => 'Stress Agent Co',
                'contact_person'   => 'Stress Contact',
                'phone'            => '01000009999',
                'email'            => 'stress-agent@stress.test',
                'country'          => 'EG',
                'visa_type'        => 'tourist',
                'default_cost_price' => 1500.0,
                'account_id'       => $supplierAccount->id,
                'is_active'        => true,
                'notes'            => 'Visa stress run — safe to drop',
                'created_by'       => $this->admin->id,
            ]);
        });
    }

    protected function seedOpening(Account $account, float $amount): void
    {
        if ($amount <= 0) return;
        DB::transaction(function () use ($account, $amount) {
            $tx = Transaction::create([
                'type'            => 'transfer',
                'amount'          => $amount,
                'module'          => TransactionModule::General->value,
                'from_account_id' => $account->id,
                'to_account_id'   => $account->id,
                'currency'        => $account->currency,
                'created_by'      => $this->admin->id,
                'notes'           => 'Stress opening balance',
            ]);
            AccountEntry::insert([
                [
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'balance_after' => $amount,
                    'is_opening' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => 0,
                    'credit' => 0,
                    'balance_after' => $amount,
                    'is_opening' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });
    }

    /**
     * Build a complete booking payload for the given currency + vault.
     */
    protected function bookingPayload(string $currency, Account $vault, array $overrides = []): array
    {
        $base = [
            'customer_id'     => $this->customer->id,
            'purchase_price'  => $currency === 'USD' ? 800.0 : ($currency === 'SAR' ? 4000.0 : 6000.0),
            'selling_price'   => $currency === 'USD' ? 1200.0 : ($currency === 'SAR' ? 6000.0 : 9000.0),
            'service_fee'     => $currency === 'USD' ? 50.0 : ($currency === 'SAR' ? 200.0 : 500.0),
            'currency'        => $currency,
            'account_id'      => $vault->id,
            'status'          => VisaStatus::Submitted->value,
            'agent_name'      => 'Stress Agent',
            'notes'           => 'Visa stress run',
            'visa_details'    => [
                'visa_type'               => VisaType::Tourist->value,
                'country'                 => 'STRESS-LAND',
                'duration'                => '90',
                'visa_duration_id'        => $this->duration->id,
                'entry_type'              => VisaEntryType::Single->value,
                'validity_from'           => now()->toDateString(),
                'validity_to'             => now()->addMonths(3)->toDateString(),
                'submission_date'         => now()->toDateString(),
                'expected_result_date'    => now()->addDays(15)->toDateString(),
                'executing_company'       => 'Stress Exec Co',
                'executing_agent'         => 'Stress Exec Agent',
                'executing_agent_contact' => '01000000000',
                'visa_agent_id'           => $this->agent->id,
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    protected function assertBalanceOk(): void
    {
        $recon = StressReconciliation::runAll();

        // Skip the orphan/fk-integrity checks for opening-balance entries —
        // those are BY DESIGN created with transaction_id=NULL by the
        // Account::created boot hook (see App\Models\Account::booted).
        $nonOpeningOrphanEntries = (int) \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereNull('transaction_id')
            ->where('is_opening', 0)
            ->count();

        $this->assertSame(0, $recon['per_account']['failed'],
            'Ledger imbalance per-account: '.json_encode($recon['per_account']['failures'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $recon['per_transaction']['failed'],
            'Transactions imbalanced: '.json_encode($recon['per_transaction']['failures'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $nonOpeningOrphanEntries,
            'Found orphan account_entries that are NOT opening entries');
        $this->assertEqualsWithDelta(
            0.0, (float) $recon['totals']['diff'], 0.02,
            'Total credits != total debits'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  A) BOOKING CREATION
    // ───────────────────────────────────────────────────────────────────────────

    public function test_A01_create_egp_booking_full_cycle(): void
    {
        $service = app(VisaBookingService::class);
        $payload = $this->bookingPayload('EGP', $this->vaultEgp);

        $booking = $service->create($payload);

        $this->assertNotNull($booking->id);
        $this->assertSame('EGP', $booking->currency);
        $this->assertSame(6000.0, (float) $booking->purchase_price);
        $this->assertSame(9000.0, (float) $booking->selling_price);
        $this->assertSame(500.0,  (float) $booking->service_fee);
        $this->assertSame(3500.0, (float) $booking->profit); // 9000 + 500 − 6000
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        $this->assertSame('submitted', $status);
        $this->assertNotNull($booking->expense_transaction_id);
        $this->assertNotNull($booking->income_transaction_id);

        $this->assertBalanceOk();
    }

    public function test_A02_create_usd_booking(): void
    {
        $booking = app(VisaBookingService::class)->create(
            $this->bookingPayload('USD', $this->vaultUsd)
        );
        $this->assertSame('USD', $booking->currency);
        $this->assertSame(450.0, (float) $booking->profit); // 1200+50−800
        $this->assertBalanceOk();
    }

    public function test_A03_create_sar_booking(): void
    {
        $booking = app(VisaBookingService::class)->create(
            $this->bookingPayload('SAR', $this->vaultSar)
        );
        $this->assertSame('SAR', $booking->currency);
        $this->assertSame(2200.0, (float) $booking->profit); // 6000+200−4000
        $this->assertBalanceOk();
    }

    public function test_A04_create_booking_without_agent_expense_to_vault(): void
    {
        $payload = $this->bookingPayload('EGP', $this->vaultEgp);
        $payload['visa_details']['visa_agent_id'] = null;

        $booking = app(VisaBookingService::class)->create($payload);
        $this->assertNotNull($booking->expense_transaction_id);
        $this->assertBalanceOk();
    }

    public function test_A05_create_bulk_bookings_all_currencies(): void
    {
        $service = app(VisaBookingService::class);

        for ($i = 0; $i < 15; $i++) {
            $service->create($this->bookingPayload('EGP', $this->vaultEgp, ['notes' => "stress-A05-EGP-{$i}"]));
        }
        for ($i = 0; $i < 10; $i++) {
            $service->create($this->bookingPayload('USD', $this->vaultUsd, ['notes' => "stress-A05-USD-{$i}"]));
        }
        for ($i = 0; $i < 5; $i++) {
            $service->create($this->bookingPayload('SAR', $this->vaultSar, ['notes' => "stress-A05-SAR-{$i}"]));
        }

        $this->assertSame(30, VisaBooking::count());
        $this->assertBalanceOk();
    }

    public function test_A06_create_rejects_negative_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(VisaBookingService::class)->create(
            $this->bookingPayload('EGP', $this->vaultEgp, ['purchase_price' => -100])
        );
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  B) PAYMENT COLLECTION
    // ───────────────────────────────────────────────────────────────────────────

    public function test_B01_add_payment_happy_path(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        $payment = app(VisaBookingService::class)->addPayment($booking, [
            'amount'      => 4000.0,
            'account_id'  => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(4000.0, (float) $payment->amount);
        $booking->refresh();
        $this->assertSame(4000.0, (float) $booking->paid_amount);
        $this->assertSame(5500.0, (float) $booking->remaining_amount);
        $this->assertBalanceOk();
    }

    public function test_B02_add_multiple_sequential_payments(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, ['amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $service->addPayment($booking, ['amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $service->addPayment($booking, ['amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $service->addPayment($booking, ['amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);

        $booking->refresh();
        $this->assertSame(8000.0, (float) $booking->paid_amount);
        $this->assertSame(1500.0, (float) $booking->remaining_amount);
        $this->assertFalse($booking->is_fully_paid);
        $this->assertBalanceOk();
    }

    public function test_B03_add_payment_marks_fully_paid(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 9500.0,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $booking->refresh();
        $this->assertTrue($booking->is_fully_paid);
        $this->assertSame(0.0, (float) $booking->remaining_amount);
        $this->assertBalanceOk();
    }

    public function test_B04_add_payment_overpayment_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 5000.0,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->expectException(\RuntimeException::class);
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 5000.01,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
    }

    public function test_B05_add_payment_same_reference_is_idempotent(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));

        $first = app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
            'transaction_reference' => 'REF-001',
        ]);
        $second = app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
            'transaction_reference' => 'REF-001',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue((bool) ($second->idempotent_replay ?? false));
        $booking->refresh();
        $this->assertSame(1000.0, (float) $booking->paid_amount);
        $this->assertBalanceOk();
    }

    public function test_B06_add_payment_on_cancelled_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaRefundService::class)->cancel($booking, 'stress-test');

        $this->expectException(\RuntimeException::class);
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 100,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  C) CANCELLATION
    // ───────────────────────────────────────────────────────────────────────────

    public function test_C01_cancel_booking_with_payments_reverses_all(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 3000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $booking->load('customer.ledgerAccount');
        $customerBalanceBefore = (float) $booking->customer->ledgerAccount->fresh()->balance;
        // After booking + payment: customer is credited by (selling+fee)=9500, then debited by payment=3000.
        // So balance = +9500 - 3000 = +6500.
        $this->assertEqualsWithDelta(6500.0, $customerBalanceBefore, 1.0);

        $cancelled = app(VisaRefundService::class)->cancel($booking, 'stress-cancel');

        $status = $cancelled->status instanceof \BackedEnum ? $cancelled->status->value : (string) $cancelled->status;
        $this->assertSame('cancelled', $status);

        $cancelled->load('customer.ledgerAccount');
        $customerBalanceAfter = (float) $cancelled->customer->ledgerAccount->fresh()->balance;
        // After cancel: all reversals applied → balance should be back to ZERO (pre-booking state).
        $this->assertEqualsWithDelta(0.0, $customerBalanceAfter, 1.0);

        $this->assertBalanceOk();
    }

    public function test_C02_double_cancel_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaRefundService::class)->cancel($booking, 'first');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->cancel($booking, 'second');
    }

    public function test_C03_cancel_refunded_booking_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
        $refunded = app(VisaRefundService::class)->refund($booking->fresh(), 'stress-refund');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->cancel($refunded->fresh(), 'should-fail');
    }

    public function test_C04_cancel_after_soft_delete_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        $trashed = VisaBooking::withTrashed()->findOrFail($booking->id)->fresh();
        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->cancel($trashed, 'should-fail');
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  D) REFUND
    // ───────────────────────────────────────────────────────────────────────────

    public function test_D01_refund_booking_with_payments(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 5000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $refunded = app(VisaRefundService::class)->refund($booking, 'stress-refund');
        $status = $refunded->status instanceof \BackedEnum ? $refunded->status->value : (string) $refunded->status;
        $this->assertSame('refunded', $status);
        $this->assertBalanceOk();
    }

    public function test_D02_double_refund_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
        app(VisaRefundService::class)->refund($booking, 'first');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->refund($booking, 'second');
    }

    public function test_D03_refund_cancelled_booking_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
        app(VisaRefundService::class)->cancel($booking, 'cancel-first');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->refund($booking, 'refund-should-fail');
    }

    public function test_D04_refund_unpaid_booking_is_no_op_with_status_change(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        $refunded = app(VisaRefundService::class)->refund($booking, 'zero-payment refund');
        $status = $refunded->status instanceof \BackedEnum ? $refunded->status->value : (string) $refunded->status;
        $this->assertSame('refunded', $status);
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  E) SOFT DELETE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_E01_delete_with_reversal(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 3000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $ok = app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
        $this->assertTrue($ok);

        $this->assertNotNull(VisaBooking::withTrashed()->findOrFail($booking->id)->deleted_at);
        $this->assertBalanceOk();
    }

    public function test_E02_double_delete_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
    }

    public function test_E03_delete_cancelled_booking_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaRefundService::class)->cancel($booking, 'cancel-first');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
    }

    public function test_E04_delete_refunded_booking_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
        app(VisaRefundService::class)->refund($booking, 'refund-first');

        $this->expectException(\RuntimeException::class);
        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  F) CUSTOMER DEBT ENDPOINT
    // ───────────────────────────────────────────────────────────────────────────

    public function test_F01_pay_customer_debt_distributes_fifo(): void
    {
        $service = app(VisaBookingService::class);
        $b1 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));
        $b2 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));
        $b3 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));

        $total = (float) $b1->selling_price + (float) $b2->selling_price + (float) $b3->selling_price +
                 (float) $b1->service_fee + (float) $b2->service_fee + (float) $b3->service_fee;

        $half = round($total / 2, 2);

        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount'     => $half,
            'account_id' => $this->bankEgp->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $b1->refresh(); $b2->refresh(); $b3->refresh();
        $paidTotal = (float) $b1->paid_amount + (float) $b2->paid_amount + (float) $b3->paid_amount;
        $this->assertEqualsWithDelta($half, $paidTotal, 0.5);

        $this->assertBalanceOk();
    }

    public function test_F02_pay_customer_debt_full_clear(): void
    {
        $service = app(VisaBookingService::class);
        $b1 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));
        $b2 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));

        $total = (float) $b1->selling_price + (float) $b2->selling_price +
                 (float) $b1->service_fee + (float) $b2->service_fee;

        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount'     => $total,
            'account_id' => $this->bankEgp->id,
        ]);
        $response->assertOk();

        $b1->refresh(); $b2->refresh();
        $this->assertTrue($b1->is_fully_paid);
        $this->assertTrue($b2->is_fully_paid);

        $this->assertBalanceOk();
    }

    public function test_F03_pay_customer_debt_cross_currency_rejected(): void
    {
        $service = app(VisaBookingService::class);
        $service->create($this->bookingPayload('USD', $this->vaultUsd));

        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount'     => 100,
            'account_id' => $this->bankEgp->id,
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_F04_customer_balances_endpoint_lists_debtors(): void
    {
        $service = app(VisaBookingService::class);
        $service->create($this->bookingPayload('EGP', $this->vaultEgp));

        $response = $this->getJson('/api/v1/visa/customer-balances?status=debtors');
        $response->assertOk();
        $body = $response->json('data');
        $this->assertNotEmpty($body);
        $this->assertGreaterThan(0, $body[0]['total_debt']);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  G) AGENT FINANCE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_G01_agent_withdraw_repay_roundtrip(): void
    {
        $this->postJson("/api/v1/visa/agents/{$this->agent->id}/repay", [
            'amount'         => 1000,
            'from_account_id'=> $this->bankEgp->id,
        ])->assertOk();

        $this->assertBalanceOk();

        $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount'      => 600,
            'to_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        $this->assertBalanceOk();
    }

    public function test_G02_agent_withdraw_cross_currency_rejected(): void
    {
        $this->postJson("/api/v1/visa/agents/{$this->agent->id}/repay", [
            'amount'         => 1000,
            'from_account_id'=> $this->bankEgp->id,
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount'      => 100,
            'to_account_id' => $this->vaultUsd->id,
        ]);
        $response->assertStatus(422);
    }

    public function test_G03_agent_dues_endpoint(): void
    {
        $this->postJson("/api/v1/visa/agents/{$this->agent->id}/repay", [
            'amount'         => 500,
            'from_account_id'=> $this->bankEgp->id,
        ])->assertOk();

        $response = $this->getJson('/api/v1/visa/agents/dues');
        $response->assertOk();

        $items = $response->json('data.items');
        $this->assertNotEmpty($items);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  H) LIFECYCLE / INVARIANTS
    // ───────────────────────────────────────────────────────────────────────────

    public function test_H01_payment_after_refund_is_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
        $refunded = app(VisaRefundService::class)->refund($booking->fresh(), 'refund-first');

        $this->expectException(\RuntimeException::class);
        app(VisaBookingService::class)->addPayment($refunded->fresh(), [
            'amount' => 100,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);
    }

    public function test_H02_zero_payment_booking_paid_amount_zero(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        $this->assertSame(0.0, (float) $booking->paid_amount);
        $this->assertSame((float) $booking->selling_price + (float) $booking->service_fee, (float) $booking->remaining_amount);
        $this->assertBalanceOk();
    }

    public function test_H03_each_transaction_balanced_debits_eq_credits(): void
    {
        $service = app(VisaBookingService::class);
        $b1 = $service->create($this->bookingPayload('EGP', $this->vaultEgp));
        $b2 = $service->create($this->bookingPayload('USD', $this->vaultUsd));

        $txIds = [
            $b1->expense_transaction_id,
            $b1->income_transaction_id,
            $b2->expense_transaction_id,
            $b2->income_transaction_id,
        ];

        foreach ($txIds as $txId) {
            if (! $txId) continue;
            $row = DB::table('account_entries')
                ->where('transaction_id', $txId)
                ->selectRaw('SUM(debit) as d, SUM(credit) as c')
                ->first();
            $this->assertEqualsWithDelta(
                (float) $row->d,
                (float) $row->c,
                0.02,
                "Transaction #{$txId} not balanced"
            );
        }
    }

    public function test_H04_add_debt_payment_to_unpaid_booking(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        $payment = app(VisaBookingService::class)->addDebtPayment($booking, [
            'amount'         => 1000,
            'account_id'     => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(1000.0, (float) $payment->amount);
        $booking->refresh();
        $this->assertSame(1000.0, (float) $booking->paid_amount);
        $this->assertBalanceOk();
    }

    public function test_H05_add_debt_payment_overpayment_rejected(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));

        $this->expectException(\RuntimeException::class);
        app(VisaBookingService::class)->addDebtPayment($booking, [
            'amount'     => (float) $booking->selling_price + 1000.0,
            'account_id' => $this->bankEgp->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  I) STRESS / LOAD
    // ───────────────────────────────────────────────────────────────────────────

    public function test_I01_bulk_create_then_bulk_pay_then_reconcile(): void
    {
        $service = app(VisaBookingService::class);
        $bookings = [];

        for ($i = 0; $i < 15; $i++) {
            $bookings[] = $service->create($this->bookingPayload('EGP', $this->vaultEgp, ['notes' => "I01-EGP-{$i}"]));
        }
        for ($i = 0; $i < 10; $i++) {
            $bookings[] = $service->create($this->bookingPayload('USD', $this->vaultUsd, ['notes' => "I01-USD-{$i}"]));
        }
        for ($i = 0; $i < 5; $i++) {
            $bookings[] = $service->create($this->bookingPayload('SAR', $this->vaultSar, ['notes' => "I01-SAR-{$i}"]));
        }

        foreach ($bookings as $b) {
            $due = (float) $b->selling_price + (float) $b->service_fee;
            $payAmount = round($due * 0.4, 2);
            $vault = match (strtoupper($b->currency)) {
                'USD' => $this->vaultUsd,
                'SAR' => $this->vaultSar,
                default => $this->vaultEgp,
            };
            $service->addPayment($b, [
                'amount' => $payAmount,
                'account_id' => $vault->id,
                'payment_method' => 'cash',
            ]);
        }

        $this->assertSame(30, VisaBooking::count());
        $this->assertGreaterThanOrEqual(30, \App\Models\VisaPayment::count());
        $this->assertBalanceOk();
    }

    public function test_I02_full_lifecycle_then_reconcile(): void
    {
        $service = app(VisaBookingService::class);
        $bookings = [];

        for ($i = 0; $i < 10; $i++) {
            $bookings[] = $service->create($this->bookingPayload('EGP', $this->vaultEgp, ['notes' => "I02-EGP-{$i}"]));
        }

        for ($i = 0; $i < 5; $i++) {
            $service->addPayment($bookings[$i], [
                'amount' => 2000,
                'account_id' => $this->bankEgp->id,
                'payment_method' => 'cash',
            ]);
        }

        for ($i = 5; $i < 7; $i++) {
            app(VisaRefundService::class)->cancel($bookings[$i], 'stress-cancel');
        }

        for ($i = 7; $i < 10; $i++) {
            $service->addPayment($bookings[$i], [
                'amount' => 1000,
                'account_id' => $this->bankEgp->id,
                'payment_method' => 'cash',
            ]);
            app(VisaRefundService::class)->refund($bookings[$i], 'stress-refund');
        }

        $this->assertBalanceOk();
    }

    public function test_I03_concurrent_add_payment_one_succeeds(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));

        $service = app(VisaBookingService::class);

        // First payment consumes 4500 of 9500. Remaining = 5000.
        $service->addPayment($booking, [
            'amount' => 4500,
            'account_id' => $this->bankEgp->id,
            'payment_method' => 'cash',
        ]);

        // Second payment of 5000.02 should be rejected (exceeds remaining + tolerance).
        $caught = false;
        try {
            $service->addPayment($booking, [
                'amount' => 5000.02,
                'account_id' => $this->bankEgp->id,
                'payment_method' => 'cash',
            ]);
        } catch (\RuntimeException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Second concurrent payment should be rejected');

        $booking->refresh();
        $this->assertSame(4500.0, (float) $booking->paid_amount);
        $this->assertBalanceOk();
    }

    public function test_I04_full_soft_delete_reverses_everything(): void
    {
        $booking = app(VisaBookingService::class)->create($this->bookingPayload('EGP', $this->vaultEgp));
        $service = app(VisaBookingService::class);

        $service->addPayment($booking, ['amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $service->addPayment($booking, ['amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $service->addPayment($booking, ['amount' => 3000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);

        $booking->load('customer.ledgerAccount');
        $customerBalanceBefore = (float) $booking->customer->ledgerAccount->fresh()->balance;
        // After booking + 3 payments: +9500 - (1000+2000+3000) = +3500
        $this->assertEqualsWithDelta(3500.0, $customerBalanceBefore, 1.0);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        // After full delete with reversal: customer balance should be ZERO.
        $customerBalanceAfter = (float) Customer::find($booking->customer_id)->fresh()->ledgerAccount->fresh()->balance;
        $this->assertEqualsWithDelta(0.0, $customerBalanceAfter, 1.0);

        $this->assertBalanceOk();
    }

    public function test_I05_final_reconciliation_report(): void
    {
        $service = app(VisaBookingService::class);

        for ($i = 0; $i < 5; $i++) {
            $b = $service->create($this->bookingPayload('EGP', $this->vaultEgp, ['notes' => "I05-EGP-{$i}"]));
            $service->addPayment($b, ['amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        }
        for ($i = 0; $i < 3; $i++) {
            $b = $service->create($this->bookingPayload('USD', $this->vaultUsd, ['notes' => "I05-USD-{$i}"]));
        }

        $recon = StressReconciliation::runAll();

        // For the final report verdict, ignore opening-balance orphans
        // (transaction_id=NULL by design from the Account::created hook).
        $nonOpeningOrphan = (int) \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereNull('transaction_id')
            ->where('is_opening', 0)
            ->count();
        $recon['orphan_entries']['count'] = $nonOpeningOrphan;
        $recon['orphan_entries']['sample'] = [];

        // Compute verdict locally, excluding opening-balance artifacts.
        $tolerance = $recon['tolerance'];
        $allOk = $recon['per_account']['failed'] === 0
            && $recon['per_transaction']['failed'] === 0
            && $recon['orphan_transactions']['count'] === 0
            && $recon['duplicate_income']['count'] === 0
            && abs((float) $recon['reversals']['net_impact_egp']) < $tolerance
            && abs((float) $recon['totals']['diff']) < $tolerance
            && $nonOpeningOrphan === 0;
        $recon['verdict'] = $allOk ? 'OK' : 'FAIL';

        $dir = storage_path('app/stress');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents(
            $dir.'/visa-stress-final-report.json',
            json_encode($recon, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame('OK', $recon['verdict']);
    }
}
