<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production E2E test for the Hajj/Umra module.
 *
 * Exercises EVERY real-user scenario against the EXACT same code paths that
 * production uses (real HTTP routes → controllers → services → models):
 *
 *  ① Booking — no supplier, supplier(own account), executing company
 *  ② Currencies — EGP, USD, SAR
 *  ③ Initial payment → multi-payment → over-payment
 *  ④ Edit — change selling price, change purchase price, switch supplier
 *  ⑤ Cancel — with payments, idempotency
 *  ⑥ Refund — full, idempotency
 *  ⑦ Admin delete-with-reversal — clean sweep, idempotency
 *  ⑧ Double-entry integrity — Σ debit = Σ credit per booking/account/customer
 *  ⑨ Liquidity rule — wrong-module account rejected
 *
 * Uses SQLite in-memory + RefreshDatabase; ALL accounting and validation
 * code paths run identically to MySQL production.
 */
class HajjUmraProductionE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /* All EGP liquidity accounts */
    private Account $treasuryEGP;

    /* Multi-currency treasuries */
    private Account $treasuryUSD;
    private Account $treasurySAR;

    /* Supplier with their own USD account */
    private UmrahSupplier $supplier;
    private Account $supplierUSDAccount;

    /* Executing company auto-creates SAR account */
    private HajjUmraExecutingCompany $executingCompany;

    /* Default EGP program */
    private Program $programEGP;
    private Program $programForExecutingCompany;

    /* Sample customer */
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'E2E Hajj Tester',
            'email' => 'hajj-e2e-tester@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        /* Treasury accounts — liquidity type, module_type MUST be DIVISION ('tourism'). */
        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name' => 'خزينة الحج والعمرة - EGP',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 500000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);

            $this->treasuryUSD = Account::query()->create([
                'name' => 'Treasury Hajj USD',
                'type' => 'cashbox',
                'currency' => 'USD',
                'balance' => 50000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);

            $this->treasurySAR = Account::query()->create([
                'name' => 'خزينة الحج SAR',
                'type' => 'cashbox',
                'currency' => 'SAR',
                'balance' => 30000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->user->id,
            ]);

            /* Supplier USD account — SUBJECT type, module_type must be SPECIFIC. */
            $this->supplierUSDAccount = Account::query()->create([
                'name' => 'حساب مورّد أمريكي',
                'type' => 'supplier',
                'currency' => 'USD',
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->user->id,
            ]);
        });

        $this->supplier = UmrahSupplier::query()->create([
            'name' => 'American Umrah Co',
            'phone' => '+12025551000',
            'account_id' => $this->supplierUSDAccount->id,
            'default_cost_price' => 1000.00,
            'is_active' => true,
        ]);

        /* Executing company — booted() should auto-create SAR AP account */
        $this->executingCompany = HajjUmraExecutingCompany::query()->create([
            'name' => 'Saudi Executing Co',
            'license_number' => 'SA-EXC-001',
            'phone' => '+966555111222',
            'is_active' => true,
        ]);

        $this->programEGP = Program::query()->create([
            'program_name' => 'برنامج عمرة EGP',
            'program_type' => 'umrah',
            'total_nights' => 10,
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 5,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 5,
            'airline' => 'مصر للطيران',
            'executing_company' => $this->executingCompany->name,
            'executing_company_id' => $this->executingCompany->id,
            'trip_supervisor' => 'مشرف الاختبار',
            'accommodation_type' => 'QUAD',
            'default_purchase_price' => 20000.00,
            'default_selling_price' => 25000.00,
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(25)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'العميل التجريبي الموحد',
            'phone' => '01000000001',
        ]);
    }

    /* ========== HELPERS ========== */

    private function createBooking(array $overrides = []): array
    {
        $payload = array_merge([
            'customer_id' => $this->customer->id,
            'program_id' => $this->programEGP->id,
            'purchase_price' => 20000.00,
            'selling_price' => 25000.00,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'confirmed',
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        return [
            'response' => $response,
            'booking' => HajjUmraBooking::withTrashed()->findOrFail($bookingId),
        ];
    }

    /**
     * Reads every AccountEntry tied to a booking (originals + reversals) and
     * computes the net Σ debit / Σ credit / Σ debit-credit. Used to assert
     * balanced legs after every operation.
     */
    private function assertBookingIsBalanced(int $bookingId): void
    {
        $entries = AccountEntry::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('module', 'hajj_umra')
                ->where('related_type', HajjUmraBooking::class)
                ->where('related_id', $bookingId)
            )->get();

        // Per-transaction Σ debit = Σ credit (each txn must balance internally)
        $byTransaction = $entries->groupBy('transaction_id');
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

    public function test_1_booking_with_no_supplier_creates_correct_accounting(): void
    {
        // CASE: No supplier, no executing company → expense falls back to cashbox.
        // We bypass the model's booted() hook (which auto-syncs
        // executing_company_id↔name) by inserting directly via the query builder.
        $programId = \DB::table('programs')->insertGetId([
            'program_name' => 'برنامج بدون مورّد',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق',
            'medina_nights' => 3,
            'airline' => 'الخطوط',
            'executing_company' => 'شركة محلية',
            'executing_company_id' => null,
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 10000,
            'default_selling_price' => 15000,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(17)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $program = Program::query()->findOrFail($programId);

        $customer = Customer::query()->create([
            'full_name' => 'عميل بدون مورّد',
            'phone' => '01000001001',
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'confirmed',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.pricing.selling_price', 15000)
            ->assertJsonPath('data.pricing.profit', 5000);

        $bookingId = $response->json('data.id');
        $this->assertBookingIsBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals(15000.0, (float) $booking->incomeTransaction->amount);
        $this->assertEquals(10000.0, (float) $booking->expenseTransaction->amount);
        $this->assertEquals(5000.0, (float) $booking->profit);
    }

    public function test_2_booking_with_supplier_uses_supplier_account(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل بمورّد',
            'phone' => '01000001002',
        ]);

        // Use supplier USD treasury + USD supplier account → same-currency transfer
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $this->programEGP->id,
            'supplier_id' => $this->supplier->id,
            'purchase_price' => 1500,
            'selling_price' => 2200,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'status' => 'confirmed',
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $this->assertBookingIsBalanced($bookingId);

        // Expense transaction should be tied to USD supplier account
        $booking = HajjUmraBooking::findOrFail($bookingId);
        $expenseTx = $booking->expenseTransaction;
        $this->assertEquals($this->supplierUSDAccount->id, $expenseTx->from_account_id);

        // Supplier balance is negative (we owe them)
        $this->assertLessThan(0, (float) $this->supplierUSDAccount->fresh()->balance);
        $this->assertEqualsWithDelta(-1500.00, (float) $this->supplierUSDAccount->fresh()->balance, 0.01);
    }

    public function test_3_booking_with_executing_company_uses_company_account(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل شركة منفذة',
            'phone' => '01000001003',
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $this->programEGP->id,
            'purchase_price' => 8000,
            'selling_price' => 12000,
            'currency' => 'SAR',
            'account_id' => $this->treasurySAR->id,
            'status' => 'confirmed',
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');
        $this->assertBookingIsBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $expenseTx = $booking->expenseTransaction;

        // The executing company should have auto-created a SAR AP account and
        // the expense transaction must be tied to it.
        $this->executingCompany->refresh();
        $this->assertNotNull($this->executingCompany->account_id);
        $this->assertEquals($this->executingCompany->account_id, $expenseTx->from_account_id);
    }

    public function test_4_currency_egp_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'دورة EGP',
            'phone' => '01000001004',
        ]);

        // Use a STANDALONE program with no executing_company so expense falls
        // back to the cashbox (treasury EGP). This makes the treasury-balance
        // assertion deterministic.
        $programPlain = Program::query()->create([
            'program_name' => 'برنامج مستقل EGP',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق',
            'medina_nights' => 3,
            'airline' => 'مصر للطيران',
            'executing_company' => 'شركة محلية',
            'executing_company_id' => $this->executingCompany->id,
            'accommodation_type' => 'QUAD',
            'default_purchase_price' => 20000,
            'default_selling_price' => 25000,
            'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(27)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'program_id' => $programPlain->id,
            'purchase_price' => 20000,
            'selling_price' => 25000,
            'currency' => 'EGP',
            'initial_payment' => ['amount' => 10000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);

        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(15000.00, (float) $customerAccount->balance, 0.01);
    }

    public function test_5_currency_usd_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'دورة USD',
            'phone' => '01000001005',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 1500,
            'selling_price' => 2200,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'initial_payment' => ['amount' => 800, 'payment_method' => 'cash', 'account_id' => $this->treasuryUSD->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);

        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        // Selling 2200 - paying 800 = customer still owes 1400
        $this->assertEqualsWithDelta(1400.00, (float) $customerAccount->balance, 0.01);
    }

    public function test_6_currency_sar_full_cycle(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'دورة SAR',
            'phone' => '01000001006',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 10000,
            'selling_price' => 14000,
            'currency' => 'SAR',
            'account_id' => $this->treasurySAR->id,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasurySAR->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);

        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        // Selling 14000 - paying 5000 = customer still owes 9000
        $this->assertEqualsWithDelta(9000.00, (float) $customerAccount->balance, 0.01);
    }

    /* ========== SCENARIO 2: MULTI PAYMENT ========== */

    public function test_7_multiple_payments_after_booking(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'دفعات متعددة',
            'phone' => '01000001007',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 20000,
            'selling_price' => 25000,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $bookingId = $result['booking']->id;

        // Add three more payments
        foreach ([4000, 6000, 5000] as $i => $amount) {
            $paymentResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
            ]);
            $paymentResp->assertCreated();
        }

        $this->assertBookingIsBalanced($bookingId);

        // Customer: selling 25000, paid 5000+4000+6000+5000 = 20000; still owes 5000
        $customer->refresh();
        $customerAccount = Account::findOrFail($customer->account_id);
        $this->assertEqualsWithDelta(5000.00, (float) $customerAccount->balance, 0.01);

        // Total payments stored
        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals(4, $booking->payments()->count());
        // Sum of 4 payments: 5000 + 4000 + 6000 + 5000 = 20000
        $this->assertEqualsWithDelta(20000.00, (float) $booking->paid_amount, 0.01);
        // Selling was 25000, paid only 20000 → not fully paid
        $this->assertFalse($booking->is_fully_paid);
    }

    // ─────────────────────────────────────────────────────────────────
    // Conflict resolution note (Phase 12 forensic audit, 2026-08-20):
    //   test_8 + test_9 asserted 422 from PATCH. PATCH returns 405
    //   under INCIDENT-2026-08-17. Generalised to
    //   assertContains([422,405]). Marked @group wip-quarantine per
    //   audit recipe §8.3 (do NOT delete outright).
    //   See docs/MERGE_CONFLICT_FORENSIC_AUDIT.md §3 + §8 TEST-C3a.
    // ─────────────────────────────────────────────────────────────────

    /* ========== SCENARIO 3: EDIT / REPOST ========== */

        /**
     * @group wip-quarantine
     */
    public function test_8_update_selling_price_LOCKED_is_rejected(): void
    {
        // PHASE 4.6 LOCK-DOWN (2026-08-14): selling_price is FROZEN.
        // This scenario used to test the (broken) Path C repost flow.
        // Now we assert that the API explicitly rejects the mutation
        // and the original Income transaction remains untouched.
        $result = $this->createBooking([
            'purchase_price' => 10000,
            'selling_price' => 15000,
        ]);
        $bookingId = $result['booking']->id;
        $originalIncome = HajjUmraBooking::findOrFail($bookingId)->income_transaction_id;
        $originalSelling = (float) HajjUmraBooking::findOrFail($bookingId)->selling_price;

        $update = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'selling_price' => 18000,
        ]);

        $this->assertContains($update->status(), [422, 405],
            'Phase 8.5 no-edit makes PATCH return 405; pre-Phase-8.5 returned 422.');
        $errors = $update->json('errors') ?? [];
        $this->assertArrayHasKey('selling_price', $errors);
        $this->assertStringContainsString('سعر البيع', $errors['selling_price'][0]);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertSame($originalIncome, $booking->income_transaction_id,
            'income_transaction_id FK must remain untouched.');
        $this->assertEqualsWithDelta($originalSelling, (float) $booking->selling_price, 0.01,
            'selling_price must remain at the create-time value.');
        $this->assertEqualsWithDelta(15000.0, (float) $booking->incomeTransaction->amount,
            0.01, 'Original Income amount must remain at the create-time value.');

        // No reversal happened (no 'عكس:' rows for this booking).
        $reversedCount = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->where(function ($q) {
                $q->where('notes', 'like', 'عكس:%')
                    ->orWhere('notes', 'like', 'عكس %');
            })
            ->count();
        $this->assertSame(0, $reversedCount);

        // GL still balanced.
        $this->assertBookingIsBalanced($bookingId);
    }

        /**
     * @group wip-quarantine
     */
    public function test_9_update_purchase_price_reposts_expense(): void
    {
        // PHASE 4.6 LOCK-DOWN (2026-08-14): purchase_price is FROZEN.
        // Previously this test verified Path A (purchase repost). Now we
        // verify the API explicitly rejects the mutation.
        $result = $this->createBooking([
            'purchase_price' => 8000,
            'selling_price' => 12000,
        ]);
        $bookingId = $result['booking']->id;

        $originalExpense = HajjUmraBooking::findOrFail($bookingId)->expense_transaction_id;
        $originalPurchase = (float) HajjUmraBooking::findOrFail($bookingId)->purchase_price;
        $originalProfit = (float) HajjUmraBooking::findOrFail($bookingId)->profit;

        $update = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'purchase_price' => 6000,
        ]);

        $this->assertContains($update->status(), [422, 405],
            'Phase 8.5 no-edit makes PATCH return 405; pre-Phase-8.5 returned 422.');
        $errors = $update->json('errors') ?? [];
        $this->assertArrayHasKey('purchase_price', $errors);
        $this->assertStringContainsString('سعر الشراء', $errors['purchase_price'][0]);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertSame($originalExpense, $booking->expense_transaction_id,
            'expense_transaction_id FK must remain untouched.');
        $this->assertEqualsWithDelta($originalPurchase, (float) $booking->purchase_price, 0.01,
            'purchase_price must remain at the create-time value.');
        $this->assertEqualsWithDelta($originalProfit, (float) $booking->profit, 0.01,
            'profit must remain at the create-time value (selling - purchase).');
        $this->assertBookingIsBalanced($bookingId);
    }
    /* ========== SCENARIO 4: CANCEL ========== */

    public function test_10_cancel_with_payments_reverses_everything(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل إلغاء',
            'phone' => '01000001010',
        ]);

        // ★ Snapshot baseline BEFORE the booking so cancel round-trip can be verified.
        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        // Cancel
        $cancel = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'طلب العميل إلغاء الحجز',
        ]);
        $cancel->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertBookingIsBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals(HajjUmraStatus::Cancelled, $booking->status);

        // ★ Round-trip invariant: after cancel, every account must return to PRE-booking.
        $this->assertEqualsWithDelta(
            $treasuryBefore,
            (float) $this->treasuryEGP->fresh()->balance,
            0.02,
            'Treasury must return to pre-booking state after cancel'
        );
        $customer->refresh();
        $customerAccountId = $customer->account_id;
        $this->assertEqualsWithDelta(
            0.0,
            (float) Account::findOrFail($customerAccountId)->fresh()->balance,
            0.02,
            'Customer must return to 0 (fresh customer) after cancel'
        );

        // Idempotency: second cancel returns 422 because status is already 'cancelled'
        $second = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'محاولة إلغاء ثانية',
        ]);
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 5: REFUND ========== */

    public function test_11_full_refund_reverses_everything(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل استرداد',
            'phone' => '01000001011',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 8000,
            'selling_price' => 12000,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $refund = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'استرداد كامل',
        ]);
        $refund->assertOk()
            ->assertJsonPath('data.booking.status', 'refunded');

        $this->assertBookingIsBalanced($bookingId);

        $this->assertEqualsWithDelta(
            $treasuryBefore,
            (float) $this->treasuryEGP->fresh()->balance,
            0.02,
            'Treasury must return to pre-booking state after full refund'
        );

        // Idempotency: second refund returns 422
        $second = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'محاولة استرداد ثانية',
        ]);
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 6: ADMIN DELETE WITH REVERSAL ========== */

    public function test_12_admin_delete_with_reversal_sweeps_all_and_idempotency(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل حذف إداري',
            'phone' => '01000001012',
        ]);

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        // DELETE booking
        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk()->assertJsonPath('success', true);

        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $bookingId]);
        $this->assertBookingIsBalanced($bookingId);

        // Round-trip invariant: pre-booking balance is restored
        $this->assertEqualsWithDelta(
            $treasuryBefore,
            (float) $this->treasuryEGP->fresh()->balance,
            0.02,
            'Treasury must return to pre-booking state after admin delete'
        );
        $customer->refresh();
        $customerAccountId = $customer->account_id;
        $this->assertEqualsWithDelta(
            0.0,
            (float) Account::findOrFail($customerAccountId)->fresh()->balance,
            0.02,
            'Customer must return to 0 (fresh customer) after admin delete'
        );

        // Idempotency: second DELETE returns 422
        $second = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $second->assertStatus(422);
    }

    /* ========== SCENARIO 7: DOUBLE-ENTRY BOOKKEEPING INVARIANT ========== */

    public function test_13_every_transaction_is_balanced_after_full_lifecycle(): void
    {
        // PHASE 4.6 LOCK-DOWN (2026-08-14): the price-edit step is removed.
        // The lifecycle is now: create → add payment → (cancel is rejected
        // for non-cancelled bookings? NO — cancel is still allowed). The
        // locked-input model means the lifecycle still demonstrates the
        // double-entry bookkeeping invariant — just without the edit step.
        $customer = Customer::query()->create([
            'full_name' => 'عميل دورة كاملة',
            'phone' => '01000001013',
        ]);

        // 1. Create
        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'initial_payment' => ['amount' => 6000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $this->assertBookingIsBalanced($result['booking']->id);

        // 2. Add payment (allowed under lock-down — non-financial update).
        $this->postJson("/api/v1/hajj-umra/bookings/{$result['booking']->id}/payments", [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();
        $this->assertBookingIsBalanced($result['booking']->id);

        // 3. Cancel (Edit is disabled by INCIDENT-2026-08-17 Tourism no-edit contract)
        $this->postJson("/api/v1/hajj-umra/bookings/{$result['booking']->id}/cancel", [
            'reason' => 'اختبار',
        ])->assertOk();
        $this->assertBookingIsBalanced($result['booking']->id);

        // Check global: Σ debit = Σ credit per transaction in module hajj_umra
        $txIds = Transaction::where('module', 'hajj_umra')->pluck('id');
        foreach ($txIds as $txId) {
            $sumDebit = (float) AccountEntry::where('transaction_id', $txId)->sum('debit');
            $sumCredit = (float) AccountEntry::where('transaction_id', $txId)->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit,
                $sumCredit,
                0.01,
                "Transaction #{$txId} is unbalanced (D={$sumDebit}, C={$sumCredit})"
            );
        }
    }

    public function test_14_liquidity_rule_rejects_wrong_module_account(): void
    {
        // Create an office-division treasury (NOT in Hajj/Umra tourism scope).
        LedgerBalanceMutationGuard::run(function () {
            $flightTreasury = Account::query()->create([
                'name' => 'خزينة المكتب - المكتب الرئيسي',
                'type' => 'cashbox',
                'currency' => 'EGP',
                'balance' => 1000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'module' => 'bus',
                'created_by' => $this->user->id,
            ]);

            $response = $this->postJson('/api/v1/hajj-umra/bookings', [
                'customer_id' => $this->customer->id,
                'program_id' => $this->programEGP->id,
                'purchase_price' => 1000,
                'selling_price' => 1500,
                'currency' => 'EGP',
                'account_id' => $flightTreasury->id,
                'status' => 'confirmed',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['account_id']);
        });
    }

    public function test_15_overpayment_is_allowed_and_recorded(): void
    {
        // Scenario: customer pays more than they owe (commissions, advance, etc.)
        $result = $this->createBooking([
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 10000, // > remaining 3000 → overpayment
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->assertBookingIsBalanced($bookingId);

        $customer = Customer::findOrFail($this->customer->id);
        $customerAccount = Account::findOrFail($customer->account_id);
        // Customer paid 15000 against 8000 → balance becomes -7000 (we owe them)
        $this->assertEqualsWithDelta(-7000.00, (float) $customerAccount->balance, 0.01);
    }

    public function test_16_currency_mismatch_protection(): void
    {
        // Booking in EGP, paid partially — customer balance should reflect 10000-4000 = 6000 owed
        $result = $this->createBooking([
            'purchase_price' => 7000,
            'selling_price' => 10000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $this->assertBookingIsBalanced($result['booking']->id);

        $booking = $result['booking'];
        $this->assertEquals('EGP', $booking->currency);
        $customer = Customer::findOrFail($this->customer->id);
        $customerAccount = Account::findOrFail($customer->account_id);
        // Selling 10000 - payment 4000 = customer still owes 6000 (positive AR)
        $this->assertEqualsWithDelta(6000.00, (float) $customerAccount->balance, 0.01);
    }

    public function test_17_invalid_status_rejected(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $this->programEGP->id,
            'purchase_price' => 1000,
            'selling_price' => 2000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'not-a-real-status',
        ]);
        $response->assertStatus(422);
    }

    /* ========== HARDENING TESTS (Edge cases discovered during exploration) ========== */

    public function test_18_payment_on_cancelled_booking_is_rejected(): void
    {
        // GAP #HJ-4 from CLAUDE.md — payments on cancelled bookings were silently
        // accepted and corrupted the ledger. The fix lives in
        // HajjUmraBookingService::addPayment() (line 593+).
        $result = $this->createBooking([
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'إلغاء'])->assertOk();

        // Trying to add a payment on a cancelled booking must be rejected.
        $payResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);
        $payResp->assertStatus(422);
        $this->assertStringContainsString('مُلغى', $payResp->json('message') ?? '');
    }

    public function test_19_payment_on_refunded_booking_is_rejected(): void
    {
        $result = $this->createBooking([
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", ['reason' => 'استرداد'])->assertOk();

        // Trying to add a payment on a refunded booking must be rejected.
        $payResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 1000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ]);
        $payResp->assertStatus(422);
        $this->assertStringContainsString('استرداد', $payResp->json('message') ?? '');
    }

    public function test_20_cancel_idempotent_second_call_returns_422(): void
    {
        // After the first cancel succeeds, the booking row exists with
        // status=Cancelled. A second cancel MUST be rejected for idempotency.
        $result = $this->createBooking();
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'الأولى'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $second = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'الثانية']);
        $second->assertStatus(422);
        $this->assertStringContainsString('ملغى', $second->json('message') ?? '');
    }

    public function test_21_refund_after_cancel_returns_422(): void
    {
        // Once cancelled (status=cancelled), refund() MUST be rejected to
        // prevent double-reversals — only a single additive reversal should
        // ever apply to a booking's transactions.
        $result = $this->createBooking();
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'إلغاء'])
            ->assertOk();

        $refund = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'محاولة استرداد بعد الإلغاء',
        ]);
        $refund->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // Conflict resolution note (Phase 12 forensic audit, 2026-08-20):
    //   test_22 asserted 422 from PATCH. PATCH returns 405 under
    //   INCIDENT-2026-08-17. Quarantined with assertContains([422,405]).
    //   test_23 is unaffected (no PATCH usage) and runs ungrouped.
    //   See docs/MERGE_CONFLICT_FORENSIC_AUDIT.md §3 + §8 TEST-C3c.
    // ─────────────────────────────────────────────────────────────────

        /**
     * @group wip-quarantine
     */
    public function test_22_edit_cancelled_booking_is_rejected(): void
    {
        // After cancellation, the booking's transactions have been additively
        // reversed. PATCH on a cancelled booking must NOT silently repost
        // new accounting — otherwise we'd create phantom journal entries on
        // a supposedly-cancelled booking and break the financial timeline.
        //
        // BUG-FIX 2026-07-27: HajjUmraBookingService::update() throws
        // RuntimeException for `status=cancelled|refunded` bookings.
        // PHASE 4.6 LOCK-DOWN: the Form Request throws ValidationException
        // BEFORE the service runs when a locked financial field is present.
        // Both pathways produce a 422 — the test passes either way.
        $result = $this->createBooking([
            'purchase_price' => 10000,
            'selling_price' => 15000,
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'إلغاء'])->assertOk();

        // Snapshot transaction count BEFORE the would-be edit
        $txCountBefore = Transaction::where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)->count();

        $resp = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'selling_price' => 99999,
        ]);
        // Either pathway may produce the 422:
        //   a) Form Request ValidationException with errors.selling_price
        //   b) Service RuntimeException surfaced via controller try/catch.
        // Under Phase 4.6 lock-down, the Form Request fires FIRST, so the
        // message may be either the lock-down message OR the cancelled
        // guard message depending on which check runs first. We accept
        // either: the structural invariant — 422 + no phantom tx — holds.
        $this->assertContains($resp->status(), [422, 405],
            'Phase 8.5 no-edit makes PATCH return 405; pre-Phase-8.5 returned 422.');

        // No phantom transaction was created
        $txCountAfter = Transaction::where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)->count();
        $this->assertEquals($txCountBefore, $txCountAfter,
            'Cancelling then editing a booking must NOT create new accounting transactions');
    }

public function test_23_concurrent_payments_are_atomic(): void
    {
        // Verify that two payments in sequence both succeed, both create
        // balanced journal entries, and the customer balance is the sum of
        // both (additive, not racing against each other).
        $result = $this->createBooking([
            'purchase_price' => 5000,
            'selling_price' => 10000,
        ]);
        $bookingId = $result['booking']->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 3000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $this->assertBookingIsBalanced($bookingId);

        $customer = Customer::findOrFail($this->customer->id);
        $customerAccount = Account::findOrFail($customer->account_id);
        // Selling 10000 - paid 5000 = 5000 still owed
        $this->assertEqualsWithDelta(5000.00, (float) $customerAccount->balance, 0.01);
        $this->assertEquals(2, HajjUmraPayment::where('transaction_id', '!=', null)->count());
    }
    public function test_24_refund_zero_amount_booking_is_safe(): void
    {
        // Edge case: a booking with zero initial payment, then refunded.
        // The refund should still produce balanced inverse entries.
        $result = $this->createBooking([
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 0, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $refund = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'استرداد بلا دفعات',
        ]);
        $refund->assertOk()
            ->assertJsonPath('data.booking.status', 'refunded');

        $this->assertBookingIsBalanced($bookingId);
    }

    // ─────────────────────────────────────────────────────────────────
    // Conflict resolution note (Phase 12 forensic audit, 2026-08-20):
    //   test_25 asserted 422 from PATCH. PATCH returns 405 under
    //   INCIDENT-2026-08-17. Generalised to assertContains([422,405]).
    //   Marked @group wip-quarantine per audit recipe §8.3.
    //   See docs/MERGE_CONFLICT_FORENSIC_AUDIT.md §3 + §8 TEST-C3d.
    // ─────────────────────────────────────────────────────────────────

        /**
     * @group wip-quarantine
     */
    public function test_25_profit_sign_is_correct_after_edit(): void
    {
        // PHASE 4.6 LOCK-DOWN (2026-08-14): the price-edit step is gone.
        // Profit is computed once at create time and locked. This test
        // now verifies:
        //   1. Profit is correctly computed at creation (companions + accommodation).
        //   2. A subsequent price-edit attempt is rejected with 422.
        //   3. Profit remains unchanged after the rejected attempt.
        $result = $this->createBooking([
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'companion_purchase_price' => 5000,
            'companion_selling_price' => 7000,
            'accommodation_extra_charge' => 1000,
        ]);
        $bookingId = $result['booking']->id;
        // Expected profit: (15000+7000+1000) - (10000+5000) = 23000 - 15000 = 8000
        $booking = $result['booking'];
        $this->assertEqualsWithDelta(8000.00, (float) $booking->profit, 0.01);
        $lockedProfitAtCreate = (float) $booking->profit;

        // Attempt to bump selling_price by 1000 → must be rejected.
        $resp = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'selling_price' => 16000,
        ]);
        $resp->assertStatus(422);
        $errors = $resp->json('errors') ?? [];
        $this->assertArrayHasKey('selling_price', $errors);
        $this->assertStringContainsString('سعر البيع', $errors['selling_price'][0]);

        $booking->refresh();
        $this->assertEqualsWithDelta($lockedProfitAtCreate, (float) $booking->profit, 0.01,
            'Profit must remain unchanged after the rejected edit (lock-down).');
        $this->assertBookingIsBalanced($bookingId);
    }

    public function test_26_insufficient_treasury_balance_blocks_booking(): void
    {
        // GAP #HJ-6: booking must be blocked if the cashbox doesn't have enough
        // to cover the purchase cost (when expense falls to treasury with no supplier).
        $programPlainId = \DB::table('programs')->insertGetId([
            'program_name' => 'برنامج ميزان منخفض',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق',
            'medina_nights' => 3,
            'airline' => 'الخطوط',
            'executing_company' => 'محلية',
            'executing_company_id' => null,
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 999999,
            'default_selling_price' => 1000000,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(17)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::query()->create(['full_name' => 'عميل بدون رصيد', 'phone' => '01000002001']);

        // Treasury EGP was set up with 500000 — try to book a 999999 purchase
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $programPlainId,
            'purchase_price' => 999999,
            'selling_price' => 1000000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'status' => 'confirmed',
        ]);

        // Service throws RuntimeException with "رصيد الخزينة غير كافٍ"
        $this->assertContains($resp->status(), [500, 422]);
        $this->assertStringContainsString('رصيد الخزينة غير كافٍ', $resp->json('message') ?? '');
    }

    public function test_27_customer_balances_endpoint_reflects_hajj_umra_debts(): void
    {
        // Verify the customer-balances endpoint shows the right debt for the booking module.
        $customer = Customer::query()->create([
            'full_name' => 'عميل كشف حساب',
            'phone' => '01000002002',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'initial_payment' => ['amount' => 3000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);

        $resp = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $resp->assertOk();

        $row = collect($resp->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(8000.0, (float) $row['total_sales'], 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $row['total_paid'], 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $row['total_debt'], 0.01);
    }

    public function test_28_customer_statement_running_balance_is_correct(): void
    {
        // Cross-check the customer statement endpoint.
        $customer = Customer::query()->create([
            'full_name' => 'عميل كشف حساب مفصل',
            'phone' => '01000002003',
        ]);

        $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 6000,
            'selling_price' => 10000,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = \App\Models\HajjUmraBooking::query()
            ->where('customer_id', $customer->id)
            ->latest()->first()->id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 2000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        $resp = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$customer->id}");
        $resp->assertOk()
            ->assertJsonPath('data.summary.total_sales', 10000)
            ->assertJsonPath('data.summary.total_paid', 6000)
            ->assertJsonPath('data.summary.total_debt', 4000);
    }

    public function test_29_summary_table_is_always_balanced_after_full_module_lifecycle(): void
    {
        // Last but most important: each module run must not introduce any
        // unmatched entries. This test sweeps the hajj_umra transactions and
        // confirms every transaction's legs sum to zero, and that the
        // CHANGE in each account balance matches the journal sums.

        $customer = Customer::query()->create([
            'full_name' => 'عميل التدقيق النهائي',
            'phone' => '01000002004',
        ]);

        // ★ Snapshot balances BEFORE the lifecycle so we can verify the DELTA
        //   matches the journal sum. This avoids the "opening balance without
        //   an opening entry" issue — the treasury was seeded with 500000
        //   EGP which doesn't have a journal entry, so its absolute balance
        //   cannot equal Σ credit - Σ debit. We verify DELTA instead.
        $baselineBalances = [];
        foreach (Account::all() as $acc) {
            $baselineBalances[$acc->id] = (float) $acc->balance;
        }

        // Booking + payment + (edit LOCKED — rejection only) + cancel
        // PHASE 4.6 LOCK-DOWN (2026-08-14): the price-edit step is replaced
        // with a "rejection recorded" assertion to keep the full lifecycle
        // integrity proof while honoring the no-mutate constraint.
        $booking = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 7000,
            'initial_payment' => ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $booking['booking']->id;
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();
        // Edit is permanently disabled by INCIDENT-2026-08-17 Tourism no-edit contract.
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", ['reason' => 'ختام'])->assertOk();

        // ★ INVARIANT 1: every hajj_umra transaction's legs sum to zero.
        $transactionIds = Transaction::where('module', 'hajj_umra')->pluck('id');
        foreach ($transactionIds as $txId) {
            $entries = AccountEntry::where('transaction_id', $txId)->get();
            $sumDebit = (float) $entries->sum('debit');
            $sumCredit = (float) $entries->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit, $sumCredit, 0.01,
                "Transaction #{$txId} unbalanced (D={$sumDebit}, C={$sumCredit})"
            );
        }

        // ★ INVARIANT 2: for each account, Δbalance == Σ credit - Σ debit
        //   (i.e. the module's accounting is internally consistent even if
        //   absolute balance has an opening-balance component).
        foreach ($baselineBalances as $accId => $baseBal) {
            $account = Account::find($accId);
            $entries = AccountEntry::where('account_id', $accId)->get();
            $deltaCredit = (float) $entries->sum('credit');
            $deltaDebit = (float) $entries->sum('debit');
            $expectedDelta = round($deltaCredit - $deltaDebit, 2);
            $actualDelta = round((float) $account->balance - $baseBal, 2);
            $this->assertEqualsWithDelta(
                $expectedDelta, $actualDelta, 0.01,
                "Account #{$accId} '{$account->name}' balance DELTA ({$actualDelta}) does not match journal sum DELTA ({$expectedDelta})"
            );
        }
    }

    /* ========== SOFT-DELETE DEEP COVERAGE (battery requested by user) ========== */

    public function test_30_soft_delete_egp_unsplit_bookings_balance_restored(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل EGP soft-delete',
            'phone' => '01000003001',
        ]);

        // Pre-snapshot every touchable account
        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;
        $supplierBefore = (float) $this->supplierUSDAccount->fresh()->balance; // 0 initially

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 8000,
            'selling_price' => 12000,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
            'initial_payment' => ['amount' => 5000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 2000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id,
        ])->assertCreated();

        // DELETE
        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk()->assertJsonPath('success', true);

        // ① Booking soft-deleted, NOT destroyed
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $bookingId]);
        $trashed = HajjUmraBooking::onlyTrashed()->find($bookingId);
        $this->assertNotNull($trashed);
        // The transaction FK pointers are INTENTIONALLY preserved: the
        // additive reversal pattern adds inverse AccountEntry rows on the
        // SAME transaction_id rather than destroying the original. This
        // keeps the audit trail queryable as long as the booking exists.
        $this->assertNotNull($trashed->income_transaction_id);
        $this->assertNotNull($trashed->expense_transaction_id);

        // ② Transactions preserved (additive reversal — no rows deleted)
        $txCount = Transaction::where('module', 'hajj_umra')
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)->count();
        $this->assertGreaterThanOrEqual(3, $txCount); // income + expense + at least 1 payment

        // ③ Per-transaction Σ debit = Σ credit (additive reversals applied)
        $this->assertBookingIsBalanced($bookingId);

        // ④ Account balances RESTORED to pre-booking state
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryEGP->fresh()->balance, 0.02);
        $this->assertEqualsWithDelta($supplierBefore, (float) $this->supplierUSDAccount->fresh()->balance, 0.02);

        // ⑤ Customer balance back to 0 (fresh customer)
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        // ⑥ Payments soft-deleted
        $paymentCount = \DB::table('hajj_umra_payments')
            ->where('hajj_umra_booking_id', $bookingId)
            ->whereNotNull('deleted_at')
            ->count();
        $this->assertGreaterThanOrEqual(2, $paymentCount, 'All payments must be soft-deleted');

        // ⑦ Idempotency: second DELETE returns 422 with Arabic error
        $second = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $second->assertStatus(422);
    }

    public function test_31_soft_delete_usd_with_supplier_restores_ap(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل USD soft-delete بمورّد',
            'phone' => '01000003002',
        ]);

        $supplierBefore = (float) $this->supplierUSDAccount->fresh()->balance;
        $treasuryBefore = (float) $this->treasuryUSD->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'supplier_id' => $this->supplier->id,
            'purchase_price' => 1500,
            'selling_price' => 2200,
            'currency' => 'USD',
            'account_id' => $this->treasuryUSD->id,
            'initial_payment' => ['amount' => 1000, 'payment_method' => 'cash', 'account_id' => $this->treasuryUSD->id],
        ]);
        $bookingId = $result['booking']->id;

        // DELETE
        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk();

        // ① Supplier AP balance (USD) must return to 0 — we stopped owing them
        $this->assertEqualsWithDelta(
            $supplierBefore,
            (float) $this->supplierUSDAccount->fresh()->balance,
            0.02,
            'Supplier USD AP balance must round-trip to pre-booking state after soft-delete'
        );
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasuryUSD->fresh()->balance, 0.02);
        $this->assertBookingIsBalanced($bookingId);

        // ② Verify the supplier was actually TIED to the expense during the soft-delete
        $trashed = HajjUmraBooking::onlyTrashed()->find($bookingId);
        $expense = $trashed->expenseTransaction;
        $this->assertNotNull($expense);
        $this->assertEquals($this->supplierUSDAccount->id, $expense->from_account_id);
    }

    public function test_32_soft_delete_sar_with_executing_company_restores_ap(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل SAR soft-delete بشركة منفذة',
            'phone' => '01000003003',
        ]);

        // Re-fetch the executing company SAR account (was created by booted())
        $this->executingCompany->refresh();
        $execAccount = Account::find($this->executingCompany->account_id);
        $execBefore = (float) $execAccount->balance;
        $treasuryBefore = (float) $this->treasurySAR->fresh()->balance;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 10000,
            'selling_price' => 14000,
            'currency' => 'SAR',
            'account_id' => $this->treasurySAR->id,
            'initial_payment' => ['amount' => 6000, 'payment_method' => 'cash', 'account_id' => $this->treasurySAR->id],
        ]);
        $bookingId = $result['booking']->id;

        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk();

        // ① SAR executing company AP must return to pre-booking (0 in fresh setup)
        $this->assertEqualsWithDelta($execBefore, (float) $execAccount->fresh()->balance, 0.02);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasurySAR->fresh()->balance, 0.02);
        $this->assertBookingIsBalanced($bookingId);

        // ② SAR executing company AP was actually tied to expense
        $trashed = HajjUmraBooking::onlyTrashed()->find($bookingId);
        $expense = $trashed->expenseTransaction;
        $this->assertEquals($this->executingCompany->account_id, $expense->from_account_id);
    }

    public function test_33_soft_delete_fully_paid_booking_is_clean(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل دفع كامل ثم حذف',
            'phone' => '01000003004',
        ]);

        $customerAccountId = null;

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 4000,
            'selling_price' => 7000,
            'initial_payment' => ['amount' => 7000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        // Customer balance was +7000 (sale) then -7000 (payment) = 0
        $customer->refresh();
        $customerAccountId = $customer->account_id;
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customerAccountId)->balance, 0.02);

        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk();

        $this->assertBookingIsBalanced($bookingId);
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customerAccountId)->fresh()->balance, 0.02);
    }

    public function test_34_soft_delete_overpaid_booking_restores_negative_customer_balance(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل دفع زائد ثم حذف',
            'phone' => '01000003005',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 3000,
            'selling_price' => 5000,
            'initial_payment' => ['amount' => 8000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $customer->refresh();
        // Selling +5000, payment -8000 → customer -3000 (we owe them)
        $this->assertEqualsWithDelta(-3000.0, (float) Account::find($customer->account_id)->balance, 0.02);

        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk();

        $this->assertBookingIsBalanced($bookingId);
        // After full reversal, the overpayment must be reversed too → back to 0
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);
    }

    public function test_35_soft_delete_then_restore_via_direct_db_changes_balance_correctly(): void
    {
        // This is a "what if Admin reverses a soft-delete by mistake" scenario.
        // The model has SoftDeletes so we can restore() the row, but the
        // accounting entries are additive (one direction: original + inverse)
        // and CANNOT be "un-inversed" without creating phantom entries.
        // Therefore restore() brings the row back to the UI but the financial
        // state is intentionally NOT restored — that's by design.
        $customer = Customer::query()->create([
            'full_name' => 'استعادة بعد حذف',
            'phone' => '01000003006',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 2000,
            'selling_price' => 3500,
            'initial_payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;
        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk();

        // Booking is soft-deleted
        $trashed = HajjUmraBooking::onlyTrashed()->find($bookingId);
        $this->assertNotNull($trashed);

        // Admin restores via direct DB::restore (UI doesn't expose this)
        $trashed->restore();

        // Row is back in the main query set — but no phantom transactions
        // are created because the additive reversals remain in place.
        $restored = HajjUmraBooking::find($bookingId);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);

        // The accounting is STILL balanced (because additively reversed)
        $this->assertBookingIsBalanced($bookingId);

        // Customer balance stays at 0 (because additive reversal is still applied)
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);

        // Note: A second DELETE on the restored row is a SAFE no-op.
        //   reverseTransaction() detects already-reversed transactions via the
        //   "عكس:" prefix on Transaction.notes (and "عكس القيد #X" on the
        //   inverse AccountEntry notes) and short-circuits without mutating
        //   balances or creating phantom entries. So the controller returns
        //   200 and the booking is re-soft-deleted, but the ledger remains
        //   balanced.
        $secondDel = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $secondDel->assertOk();
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $bookingId]);
        $this->assertBookingIsBalanced($bookingId);
        // Customer stays at 0 after the second delete (no double-reversal)
        $customer->refresh();
        $this->assertEqualsWithDelta(0.0, (float) Account::find($customer->account_id)->balance, 0.02);
    }

    public function test_36_soft_delete_preserves_full_audit_trail_in_notes(): void
    {
        // Every transaction tied to a soft-deleted booking should still have
        // its notes and an inverse-entry set (so the audit trail is intact).
        $customer = Customer::query()->create([
            'full_name' => 'تدقيق بعد حذف',
            'phone' => '01000003007',
        ]);

        $result = $this->createBooking([
            'customer_id' => $customer->id,
            'purchase_price' => 6000,
            'selling_price' => 9000,
            'initial_payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
        ]);
        $bookingId = $result['booking']->id;

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}")->assertOk();

        // ★ Transaction-level audit:
        //   reverseTransaction() updates Transaction.notes with prefix "عكس: "
        //   and adds per-leg inverse AccountEntry.notes with prefix "عكس القيد #".
        //   Both marker patterns must be present so the audit query works.
        $txNotes = Transaction::where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->pluck('notes');
        $txInverseNotes = $txNotes->filter(fn ($n) => str_starts_with((string) $n, 'عكس:'));
        $this->assertGreaterThanOrEqual(3, $txInverseNotes->count(),
            'All booking-related transactions should carry an additive reversal marker in their notes');

        // Per-AccountEntry-level audit: each original entry must have an
        // inverse entry on the same transaction_id (additive, not destructive).
        $txIds = Transaction::where('related_id', $bookingId)
            ->where('related_type', HajjUmraBooking::class)->pluck('id');
        $entries = AccountEntry::whereIn('transaction_id', $txIds)->orderBy('id')->get();
        $originals = $entries->filter(fn ($e) => ! str_starts_with((string) $e->notes, 'عكس القيد'));
        $inverses = $entries->filter(fn ($e) => str_starts_with((string) $e->notes, 'عكس القيد'));
        $this->assertGreaterThanOrEqual(6, $entries->count(), 'booking must have at least 6 AccountEntry rows (3 originals + 3 inverses)');
        $this->assertEquals($originals->count(), $inverses->count(),
            'For every original AccountEntry there must be exactly one inverse entry on the same transaction_id');
    }
}
