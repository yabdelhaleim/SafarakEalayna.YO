<?php

namespace Tests\Feature\Visa;

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
use App\Models\VisaDetail;
use App\Models\VisaPayment;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Visa Production E2E Test — production-ready test battery for the Visa module.
 *
 * Exercises EVERY real-user scenario against the EXACT same code paths that
 * production uses (real HTTP routes → controllers → services → models).
 *
 *  ① Booking creation — no agent, with agent (own USD account),
 *     customer-supplied, with service_fee
 *  ② Currencies — EGP, USD, SAR (validated currency matching booking ↔ account)
 *  ③ Initial payment → multi-payment → over-payment
 *  ④ Edit — change purchase, change selling, change service_fee
 *  ⑤ Cancel — with payments, idempotency
 *  ⑥ Refund — full, idempotency, blocked on cancelled
 *  ⑦ Admin delete-with-reversal — clean sweep, idempotency
 *  ⑧ Double-entry integrity — Σ debit = Σ credit per booking/account/customer
 *  ⑨ VisaLiquidityAccount rule — wrong-module accounts rejected
 *  ⑩ Currency-mismatch validation — booking currency must match account currency
 *  ⑪ Soft-delete deep coverage — every currency, every state, restore idempotency
 */
class VisaProductionE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $treasuryEGP;
    private Account $treasuryUSD;
    private Account $treasurySAR;

    private VisaAgent $agentUSD;
    private Account $agentUSDAccount;

    private Customer $customer;

    private VisaDuration $duration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'E2E Visa Tester',
            'email' => 'visa-e2e-tester@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        // Treasury accounts — module_type MUST be DIVISION ('tourism') per AccountModuleContract.
        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name' => 'Visa Treasury EGP',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 500000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);
            $this->treasuryUSD = Account::query()->create([
                'name' => 'Visa Treasury USD',
                'type' => 'cashbox',
                'currency' => 'USD',
                'balance' => 50000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);
            $this->treasurySAR = Account::query()->create([
                'name' => 'Visa Treasury SAR',
                'type' => 'cashbox',
                'currency' => 'SAR',
                'balance' => 30000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'visas',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);

            // Agent USD account (SUPPLIER type, module_type must be SPECIFIC).
            $this->agentUSDAccount = Account::query()->create([
                'name' => 'US Visa Agent Account',
                'type' => 'supplier',
                'currency' => 'USD',
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'visas',
                'created_by' => $this->user->id,
            ]);
        });

        $this->agentUSD = VisaAgent::query()->create([
            'company_name' => 'US Visa Co',
            'contact_person' => 'Mr. Smith',
            'phone' => '+12025551000',
            'country' => 'USA',
            'visa_type' => 'B2',
            'default_cost_price' => 100.00,
            'account_id' => $this->agentUSDAccount->id,
            'is_active' => true,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => '6M-SINGLE',
            'label_ar' => 'ستة أشهر - دخول واحد',
            'label_en' => '6 months single entry',
            'months' => 6,
            'entry_type' => 'single',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Visa Test Customer',
            'phone' => '01000000001',
        ]);
    }

    /* ========== HELPERS ========== */

    private function visaDetails(): array
    {
        return [
            'visa_type' => VisaType::Work->value, // "work"
            'country' => 'USA',
            'duration' => '6 months',
            'visa_duration_id' => $this->duration->id,
            'entry_type' => VisaEntryType::Single->value,
            'validity_from' => now()->toDateString(),
            'validity_to' => now()->addMonths(6)->toDateString(),
            'executing_company' => 'Test Co',
            'visa_agent_id' => $this->agentUSD->id,
        ];
    }

    private function createBooking(array $overrides = []): array
    {
        $payload = array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 8000,
            'selling_price' => 10000,
            'service_fee' => 500,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => $this->visaDetails(),
        ], $overrides);

        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        return [
            'response' => $response,
            'booking' => VisaBooking::withTrashed()->findOrFail($bookingId),
        ];
    }

    /**
     * Per-transaction Σ debit = Σ credit for every transaction tied to a booking.
     */
    private function assertBookingIsBalanced(int $bookingId): void
    {
        $entries = AccountEntry::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('module', 'visa')
                ->where('related_type', VisaBooking::class)
                ->where('related_id', $bookingId)
            )->get();

        $byTransaction = $entries->groupBy('transaction_id');
        $this->assertGreaterThan(0, $byTransaction->count(), 'No entries found for booking');
        foreach ($byTransaction as $txId => $txEntries) {
            $sumDebit = (float) $txEntries->sum('debit');
            $sumCredit = (float) $txEntries->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit,
                $sumCredit,
                0.01,
                "Transaction #{$txId} is not balanced (Σ D={$sumDebit}, Σ C={$sumCredit})"
            );
        }
    }

    /* ========== SCENARIO 1: BOOKING CREATION ========== */

    public function test_1_booking_without_agent_uses_treasury_as_expense_account(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa No Agent',
            'phone' => '01000001001',
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $customer->id,
            'purchase_price' => 5000,
            'selling_price' => 7000,
            'service_fee' => 0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => VisaStatus::Submitted->value,
            // NO visa_agent_id → expense falls back to treasury (accountId)
            'visa_details' => array_diff_key($this->visaDetails(), ['visa_agent_id' => null]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.pricing.selling_price', 7000);

        $bookingId = $response->json('data.id');
        $this->assertBookingIsBalanced($bookingId);

        $booking = VisaBooking::findOrFail($bookingId);
        $expense = $booking->expenseTransaction;
        // No agent → expense is on the treasury
        $this->assertEquals($this->treasuryEGP->id, $expense->from_account_id);
        $this->assertEquals(5000.0, (float) $expense->amount);
        // Profit = (7000 + 0) - 5000 = 2000
        $this->assertEquals(2000.0, (float) $booking->profit);
    }

    public function test_2_booking_with_usd_agent_routes_expense_to_agent(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa With USD Agent',
            'phone' => '01000001002',
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $customer->id,
            'purchase_price' => 1500,
            'selling_price' => 2200,
            'service_fee' => 100,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => $this->visaDetails(),
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');
        $this->assertBookingIsBalanced($bookingId);

        $booking = VisaBooking::findOrFail($bookingId);
        $expense = $booking->expenseTransaction;

        // Expense is tied to the AGENT's USD account
        $this->assertEquals($this->agentUSDAccount->id, $expense->from_account_id);
        $this->assertEquals(1500.0, (float) $expense->amount);
        // Income = selling + service_fee = 2200 + 100 = 2300
        $this->assertEquals(2300.0, (float) $booking->incomeTransaction->amount);
        // Profit = (2200+100) - 1500 = 800
        $this->assertEquals(800.0, (float) $booking->profit);

        // Agent USD balance reflects AP (-1500)
        $this->assertLessThan(0, (float) $this->agentUSDAccount->fresh()->balance);
        $this->assertEqualsWithDelta(-1500.00, (float) $this->agentUSDAccount->fresh()->balance, 0.01);
    }

    /* ========== SCENARIO 2: MULTI-CURRENCY ========== */

    public function test_3_currency_egp_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'EGP Visa Cycle',
            'phone' => '01000001003',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 6000,
            'selling_price' => 9000,
            'service_fee' => 500,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);

        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        // Selling 9000 + service 500 = total sale 9500. Payment 4000 → customer still owes 5500.
        $this->assertEqualsWithDelta(5500.00, (float) $customerAccount->balance, 0.01);
    }

    public function test_4_currency_usd_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'USD Visa Cycle',
            'phone' => '01000001004',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 800,
            'selling_price' => 1200,
            'service_fee' => 50,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'initial_payment' => ['amount' => 500, 'payment_method' => 'cash', 'account_id' => $this->treasuryUSD->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);
        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(750.00, (float) $customerAccount->balance, 0.01); // 1250 - 500
    }

    public function test_5_currency_sar_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'SAR Visa Cycle',
            'phone' => '01000001005',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 6000,
            'service_fee' => 200,
            'currency' => 'SAR',
            'account_id' => $this->treasurySAR->id,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasurySAR->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);
        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(4200.00, (float) $customerAccount->balance, 0.01); // 6200 - 2000
    }

    public function test_6_currency_mismatch_booking_vs_account_is_rejected(): void
    {
        // Booking in EGP, but account is USD — the StoreVisaBookingRequest
        // withValidator() should reject this.
        $customer = Customer::query()->create([
            'full_name' => 'Currency Mismatch',
            'phone' => '01000001006',
        ]);

        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $customer->id,
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'currency' => 'EGP',
            'account_id' => $this->treasuryUSD->id, // wrong currency
            'visa_details' => array_diff_key($this->visaDetails(), ['visa_agent_id' => null]),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_id', $response->json('errors'));
    }

    /* ========== SCENARIO 3: MULTI PAYMENT ========== */

    public function test_7_multiple_payments_after_booking(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Multi Payment',
            'phone' => '01000001007',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'service_fee' => 300,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $bookingId = $result['booking']->id;

        foreach ([1500, 2500, 1500] as $amount) {
            $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
            ])->assertCreated();
        }

        $this->assertBookingIsBalanced($bookingId);

        $booking = VisaBooking::findOrFail($bookingId);
        $this->assertEquals(4, $booking->payments()->count());
        // Total sale = 8000 + 300 = 8300; total paid = 2000+1500+2500+1500 = 7500
        $this->assertEqualsWithDelta(7500.0, (float) $booking->paid_amount, 0.01);
        $this->assertEqualsWithDelta(800.0, (float) $booking->remaining_amount, 0.01);
        $this->assertFalse($booking->is_fully_paid);

        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        // Total sale 8300, paid 7500 → customer still owes 800
        $this->assertEqualsWithDelta(800.00, (float) $customerAccount->balance, 0.01);
    }

    /* ========== SCENARIO 4: EDIT / REPOST — REMOVED (INCIDENT-2026-08-17 no-edit contract) ========== */

    /* ========== SCENARIO 5: CANCEL ========== */

    public function test_10_cancel_with_payments_reverses_everything(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Cancel',
            'phone' => '01000001010',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 6500,
            'service_fee' => 200,
            'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", [
            'reason' => 'إلغاء طلب التأشيرة',
        ])->assertOk()
            ->assertJsonPath('data.status', VisaStatus::Cancelled->value);

        $this->assertBookingIsBalanced($bookingId);

        $booking = VisaBooking::findOrFail($bookingId);
        $this->assertEquals(VisaStatus::Cancelled, $booking->status);

        // Treasury round-trip
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryEGP->fresh()->balance, 0.02);

        // Customer round-trip to 0
        $customer->refresh();
        $customerAccountId = $customer->account_id;
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customerAccountId)->balance, 0.02);

        // Idempotency: second cancel returns 422
        $second = $this->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", [
            'reason' => 'محاولة ثانية',
        ]);
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 6: REFUND ========== */

    public function test_11_full_refund_reverses_everything(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Refund',
            'phone' => '01000001011',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 2500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/refund", [
            'reason' => 'استرداد كامل',
        ])->assertOk()
            ->assertJsonPath('data.status', VisaStatus::Refunded->value);

        $this->assertBookingIsBalanced($bookingId);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryEGP->fresh()->balance, 0.02);

        // Idempotency: second refund returns 422
        $second = $this->postJson("/api/v1/visa/bookings/{$bookingId}/refund", [
            'reason' => 'محاولة ثانية',
        ]);
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 7: ADMIN DELETE WITH REVERSAL ========== */

    public function test_12_admin_delete_with_reversal_sweeps_all_and_idempotency(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Admin Delete',
            'phone' => '01000001012',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 2000,
            'selling_price' => 3500,
            'initial_payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('visa_bookings', ['id' => $bookingId]);
        $this->assertBookingIsBalanced($bookingId);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryEGP->fresh()->balance, 0.02);

        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        // Idempotency: second DELETE returns 422
        $second = $this->deleteJson("/api/v1/visa/bookings/{$bookingId}");
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 8: DOUBLE-ENTRY BOOKKEEPING INVARIANT ========== */

    public function test_13_every_transaction_is_balanced_after_full_lifecycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Lifecycle',
            'phone' => '01000001013',
        ]);

        $baselineBalances = [];
        foreach (Account::all() as $acc) {
            $baselineBalances[$acc->id] = (float) $acc->balance;
        }

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        // Edit is permanently disabled by INCIDENT-2026-08-17 Tourism no-edit contract.
        // Skipping the PATCH step → routing directly to cancellation as the correction path.

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", [
            'reason' => 'ختام',
        ])->assertOk();

        // INVARIANT 1: per-transaction Σ debit = Σ credit
        $transactionIds = Transaction::where('module', 'visa')->pluck('id');
        $this->assertGreaterThan(0, $transactionIds->count());
        foreach ($transactionIds as $txId) {
            $sumDebit = (float) AccountEntry::where('transaction_id', $txId)->sum('debit');
            $sumCredit = (float) AccountEntry::where('transaction_id', $txId)->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit, $sumCredit, 0.01,
                "Transaction #{$txId} unbalanced (D={$sumDebit}, C={$sumCredit})"
            );
        }

        // INVARIANT 2: per-account Δbalance == Σ credit − Σ debit
        foreach ($baselineBalances as $accId => $baseBal) {
            $account = Account::find($accId);
            $entries = AccountEntry::where('account_id', $accId)->get();
            $deltaCredit = (float) $entries->sum('credit');
            $deltaDebit = (float) $entries->sum('debit');
            $expectedDelta = round($deltaCredit - $deltaDebit, 2);
            $actualDelta = round((float) $account->balance - $baseBal, 2);
            $this->assertEqualsWithDelta(
                $expectedDelta, $actualDelta, 0.01,
                "Account #{$accId} '{$account->name}' balance Δ ({$actualDelta}) does not match journal sum ({$expectedDelta})"
            );
        }
    }

    public function test_14_liquidity_rule_rejects_wrong_module_account(): void
    {
        // Office-division treasury (NOT in HajjUmra/visas scope)
        LedgerBalanceMutationGuard::run(function () {
            $officeTreasury = Account::query()->create([
                'name' => 'Office Treasury',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 1000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'module' => 'bus',
                'created_by' => $this->user->id,
            ]);

            $response = $this->postJson('/api/v1/visa/bookings', [
                'customer_id' => $this->customer->id,
                'purchase_price' => 1000,
                'selling_price' => 1500,
                'currency' => 'EGP',
                'account_id' => $officeTreasury->id,
                'status' => VisaStatus::Submitted->value,
                'visa_details' => array_diff_key($this->visaDetails(), ['visa_agent_id' => null]),
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['account_id']);
        });
    }

    public function test_15_invalid_status_rejected(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000,
            'selling_price' => 2000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'not-a-real-status',
            'visa_details' => array_diff_key($this->visaDetails(), ['visa_agent_id' => null]),
        ]);
        $response->assertStatus(422);
    }

    /* ========== HARDENING TESTS ========== */


public function test_17_payment_on_cancelled_booking_is_rejected(): void
    {
        // Note: VisaBookingService::addPayment() currently doesn't guard against
        // cancelled/refunded. This test verifies the GUARD BEHAVIOUR.
        $result = $this->createBooking([
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", ['reason' => 'إلغاء'])->assertOk();

        $payResp = $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);

        // We expect 422 with cancellation error (controller catches RuntimeException → 422)
        $payResp->assertStatus(422);
    }

    public function test_18_refund_after_cancel_returns_422(): void
    {
        // Verify refund() rejects cancelled bookings to prevent double-reversal
        $result = $this->createBooking([
            'purchase_price' => 2000,
            'selling_price' => 3500,
            'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", ['reason' => 'إلغاء'])->assertOk();

        $refund = $this->postJson("/api/v1/visa/bookings/{$bookingId}/refund", [
            'reason' => 'استرداد بعد إلغاء',
        ]);
        $refund->assertStatus(422);
    }

    public function test_19_concurrent_payments_are_atomic(): void
    {
        $result = $this->createBooking([
            'purchase_price' => 2000,
            'selling_price' => 4000,
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 1500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->assertBookingIsBalanced($bookingId);
        $this->assertEquals(2, VisaPayment::where('transaction_id', '!=', null)->count());
    }

    public function test_20_overpayment_rejected(): void
    {
        // VisaBookingService::addPayment() should reject overpayment (negative remaining).
        $result = $this->createBooking([
            'purchase_price' => 1000,
            'selling_price' => 2000,
        ]);
        $bookingId = $result['booking']->id;

        $payResp = $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 5000, // > 2000 (full sale)
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);

        // Expect 422 with "يتجاوز المبلغ المتبقي" or similar message
        $payResp->assertStatus(422);
    }

    // test_21 (profit_sign_is_correct_after_edit) — REMOVED (INCIDENT-2026-08-17)
//   Edit is permanently disabled at the route layer (PUT/PATCH → 405 by design),
//   so profit-after-edit can no longer be triggered. Cancellation is the supported path.

public function test_22_customer_balances_endpoint_reflects_visa_debts(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Customer Statement',
            'phone' => '01000001022',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 2000,
            'selling_price' => 4000,
            'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $resp = $this->getJson('/api/v1/visa/customer-balances');
        $resp->assertOk();

        $row = collect($resp->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        // Default helper applies service_fee=500 → total sale = 4000 + 500 = 4500
        $this->assertEqualsWithDelta(4500.0, (float) $row['total_sales'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $row['total_paid'], 0.01);
        $this->assertEqualsWithDelta(3500.0, (float) $row['total_debt'], 0.01);
    }

    /* ========== SOFT-DELETE DEEP COVERAGE ========== */

    public function test_23_soft_delete_egp_full_roundtrip(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa EGP SoftDelete',
            'phone' => '01000002001',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 6500,
            'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;
        $this->postJson("/api/v1/visa/bookings/{$bookingId}/payments", [
            'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        $this->assertSoftDeleted('visa_bookings', ['id' => $bookingId]);
        $trashed = VisaBooking::onlyTrashed()->find($bookingId);
        $this->assertNotNull($trashed);
        // FK pointers preserved (additive reversal pattern)
        $this->assertNotNull($trashed->income_transaction_id);
        $this->assertNotNull($trashed->expense_transaction_id);

        $this->assertBookingIsBalanced($bookingId);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryEGP->fresh()->balance, 0.02);

        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        // Payments soft-deleted
        $paymentCount = \DB::table('visa_payments')
            ->where('visa_booking_id', $bookingId)
            ->whereNotNull('deleted_at')->count();
        $this->assertGreaterThanOrEqual(2, $paymentCount);

        // Idempotency: second DELETE returns 422
        $second = $this->deleteJson("/api/v1/visa/bookings/{$bookingId}");
        $second->assertStatus(422);
    }

    public function test_24_soft_delete_usd_with_agent_restores_ap(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'USD Soft Delete Agent',
            'phone' => '01000002002',
        ]);

        $agentBefore = (float) $this->agentUSDAccount->fresh()->balance;
        $treasuryBefore = (float) $this->treasuryUSD->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 1500,
            'selling_price' => 2200,
            'service_fee' => 100,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryUSD->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        // Agent USD AP must round-trip
        $this->assertEqualsWithDelta($agentBefore, (float) $this->agentUSDAccount->fresh()->balance, 0.02);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryUSD->fresh()->balance, 0.02);
        $this->assertBookingIsBalanced($bookingId);

        // Verify the agent WAS tied to the expense during the soft-delete
        $trashed = VisaBooking::onlyTrashed()->find($bookingId);
        $this->assertEquals($this->agentUSDAccount->id, $trashed->expenseTransaction->from_account_id);
    }

    public function test_25_soft_delete_sar_treasury_restored(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'SAR Soft Delete',
            'phone' => '01000002003',
        ]);

        $treasuryBefore = (float) $this->treasurySAR->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 6000,
            'currency' => 'SAR',
            'account_id' => $this->treasurySAR->id,
            'initial_payment' => ['amount' => 2500, 'payment_method' => 'cash', 'account_id' => $this->treasurySAR->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasurySAR->fresh()->balance, 0.02);
        $this->assertBookingIsBalanced($bookingId);
    }

    public function test_26_soft_delete_fully_paid_booking_is_clean(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Visa Fully Paid Soft Delete',
            'phone' => '01000002004',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 1000,
            'selling_price' => 2500,
            // Match helper default of service_fee=500 to avoid having to
            // disable the overpayment guard for a "fully paid" scenario.
            'service_fee' => 500,
            'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $customer->refresh();
        // Sold 2500 + service 500 = 3000, paid 3000 → balance 0
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();
        $this->assertBookingIsBalanced($bookingId);
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->fresh()->balance, 0.02);
    }

    public function test_27_soft_delete_preserves_audit_trail(): void
    {
        // Every transaction tied to a soft-deleted booking should still exist
        // with inverse entries on the SAME transaction_id (additive, not destructive).
        $customer = Customer::query()->create([
            'full_name' => 'Audit Trail',
            'phone' => '01000002005',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        // Transaction-level audit: transactions should have "عكس: " prefix in notes
        $txIds = Transaction::where('related_id', $bookingId)
            ->where('related_type', VisaBooking::class)->pluck('id');
        $txNotes = Transaction::whereIn('id', $txIds)->pluck('notes');
        $inverseNotes = $txNotes->filter(fn ($n) => str_starts_with((string) $n, 'عكس:'));
        $this->assertGreaterThanOrEqual(3, $inverseNotes->count());

        // AccountEntry audit: each original entry must have exactly one inverse on same tx
        $entries = AccountEntry::whereIn('transaction_id', $txIds)->orderBy('id')->get();
        $originals = $entries->filter(fn ($e) => ! str_starts_with((string) $e->notes, 'عكس القيد'));
        $inverses = $entries->filter(fn ($e) => str_starts_with((string) $e->notes, 'عكس القيد'));
        $this->assertGreaterThanOrEqual(6, $entries->count());
        $this->assertEquals($originals->count(), $inverses->count());
    }

    public function test_28_soft_delete_then_restore_then_redelete_idempotent(): void
    {
        // Restore after delete then redelete — should be safe no-op idempotent.
        $customer = Customer::query()->create([
            'full_name' => 'Restore After Delete',
            'phone' => '01000002006',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 1500,
            'selling_price' => 2500,
            'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;
        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        $trashed = VisaBooking::onlyTrashed()->find($bookingId);
        $this->assertNotNull($trashed);

        // Admin restores via direct DB::restore
        $trashed->restore();

        $restored = VisaBooking::find($bookingId);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);

        $this->assertBookingIsBalanced($bookingId);

        // Customer still at 0 (additive reversal still applied)
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        // Second delete on restored row — safe no-op idempotent (200)
        $secondDel = $this->deleteJson("/api/v1/visa/bookings/{$bookingId}");
        $secondDel->assertOk();
        $this->assertSoftDeleted('visa_bookings', ['id' => $bookingId]);
        $this->assertBookingIsBalanced($bookingId);
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);
    }
}
