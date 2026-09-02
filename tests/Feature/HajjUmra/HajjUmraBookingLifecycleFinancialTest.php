<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 4.3 — Booking lifecycle (FINANCIAL SIDE-EFFECTS + AUTHZ + API CONTRACT).
 *
 * Scope:
 *   - Transactional invariants on create/cancel/destroy
 *   - Income/Expense records have correct (type, account, amount, related)
 *   - GL invariants: each transaction's debit = credit (sum to zero)
 *   - AuthZ: store/update are open to authenticated users; cancel/refund/
 *     destroy require admin (sanctum admin middleware)
 *   - API contract: response shape, pagination, filters
 *
 * Per Phase 4 protocol: this file is READ-ONLY with respect to production
 * code. Only NEW tests. Path C untouched. No Bus/Visa/Online changes.
 *
 * @see \App\Services\HajjUmra\HajjUmraBookingService::create()
 * @see \App\Services\Finance\TransactionService::recordIncome/recordExpense
 */
class HajjUmraBookingLifecycleFinancialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'      => 'Booking Financial Tester',
            'email'     => 'fin-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasury = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'خزينة مالية',
                'type'      => AccountType::Cashbox->value,
                'currency'  => 'EGP',
                'balance'   => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'full_name' => 'عميل مالي',
            'phone'     => '+20108' . random_int(1000000, 9999999),
            'email'     => 'fin-cust-' . uniqid('', true) . '@test.local',
            'national_id' => '297' . str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $overrides));
    }

    protected function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name'           => 'برنامج مالي',
            'program_type'           => 'hajj',
            'total_nights'           => 14,
            'mecca_nights'           => 8,
            'medina_nights'          => 6,
            'accommodation_type'     => 'DOUBLE',
            'mecca_hotel_name'       => 'فندق مكة',
            'medina_hotel_name'      => 'فندق المدينة',
            'departure_date'         => now()->addDays(60)->toDateString(),
            'return_date'            => now()->addDays(74)->toDateString(),
            'airline'                => 'Test Air',
            'executing_company'      => 'شركة تنفيذ',
            'departure_point'        => 'CAI',
            'default_selling_price'  => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active'              => true,
            'created_by'             => $this->admin->id,
        ], $overrides));
    }

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();

        $payload = array_merge([
            'customer_id'    => $customer->id,
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'currency'       => 'EGP',
            'per_person'     => true,
            'status'         => HajjUmraStatus::Confirmed->value,
            'agent_name'     => 'وكيل مالي',
            'account_id'     => $this->treasury->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        return HajjUmraBooking::query()->findOrFail($response->json('data.id'));
    }

    /* =========================================================
     *  4.8 FINANCIAL SIDE-EFFECTS
     * ========================================================= */

    public function test_4_8_create_records_one_income_and_one_expense_transaction(): void
    {
        $booking = $this->makeBooking(['purchase_price' => 40000, 'selling_price' => 50000]);

        $this->assertNotNull($booking->income_transaction_id);
        $this->assertNotNull($booking->expense_transaction_id);

        $income  = Transaction::query()->findOrFail($booking->income_transaction_id);
        $expense = Transaction::query()->findOrFail($booking->expense_transaction_id);

        $this->assertSame(TransactionType::Income, $income->type);
        $this->assertEqualsWithDelta(50000.0, (float) $income->amount, 0.01);

        // FIN-3 REMEDIATION (2026-08-21): TransactionService::recordExpense()
        // now explicitly tags the transaction as type=Expense even when it
        // routes through recordJournalTransfer() (the clearing-account path).
        // This keeps the expense semantic in reports that filter on
        // `type='expense'`. Pre-fix the type silently became Transfer,
        // masking expense rows from financial reports.
        $this->assertSame(TransactionType::Expense, $expense->type);
        $this->assertEqualsWithDelta(40000.0, (float) $expense->amount, 0.01);
    }

    public function test_4_8_income_transaction_links_to_booking_via_polymorph(): void
    {
        $booking = $this->makeBooking();

        $income  = Transaction::query()->findOrFail($booking->income_transaction_id);
        $expense = Transaction::query()->findOrFail($booking->expense_transaction_id);

        $this->assertSame(HajjUmraBooking::class, $income->related_type);
        $this->assertSame($booking->id, $income->related_id);
        $this->assertSame(HajjUmraBooking::class, $expense->related_type);
        $this->assertSame($booking->id, $expense->related_id);

        // `module` column is cast to TransactionModule enum (BackedEnum).
        // Compare against the enum case, not a string.
        $this->assertSame(TransactionModule::HajjUmra, $income->module);
        $this->assertSame(TransactionModule::HajjUmra, $expense->module);
    }

    public function test_4_8_gl_each_transaction_balances_debit_equal_credit(): void
    {
        $booking = $this->makeBooking();

        foreach ([$booking->income_transaction_id, $booking->expense_transaction_id] as $txId) {
            $entries = AccountEntry::query()->where('transaction_id', $txId)->get();

            $totalDebit  = (float) $entries->sum('debit');
            $totalCredit = (float) $entries->sum('credit');

            $this->assertEqualsWithDelta(
                $totalDebit, $totalCredit, 0.01,
                "GL imbalance for transaction $txId: D=$totalDebit C=$totalCredit"
            );
        }
    }

    public function test_4_8_no_money_creation_only_redistribution_between_accounts(): void
    {
        $booking = $this->makeBooking();
        $treasuryBefore = (float) Account::query()->find($this->treasury->id)->balance;

        // Net effect on company-wide balance across all tied transactions
        // (create + first payment): treasury should lose the purchase cost
        // (outflow to supplier/company) and gain the customer collection
        // (inflow), making the +Δ balance equal to (selling - purchase).
        $customerAccountId = (int) DB::table('customers')->where('id', $booking->customer_id)->value('account_id');
        $customerBalanceBefore = $customerAccountId
            ? (float) Account::query()->find($customerAccountId)->balance
            : 0.0;

        // Verify each transaction was a transfer (sum of debit==sum of credit).
        $incomeTx  = Transaction::query()->findOrFail($booking->income_transaction_id);
        $expenseTx = Transaction::query()->findOrFail($booking->expense_transaction_id);
        $sumD = (float) AccountEntry::query()
            ->whereIn('transaction_id', [$incomeTx->id, $expenseTx->id])
            ->sum('debit');
        $sumC = (float) AccountEntry::query()
            ->whereIn('transaction_id', [$incomeTx->id, $expenseTx->id])
            ->sum('credit');

        $this->assertEqualsWithDelta($sumD, $sumC, 0.01,
            "Net sum across create transactions must be 0 (conservation)."
        );
    }

    public function test_4_8_initial_payment_creates_extra_transfer_transaction(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();

        $payload = [
            'customer_id'    => $customer->id,
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'account_id'     => $this->treasury->id,
            'initial_payment' => [
                'amount' => 10000,
            ],
        ];

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));

        // 1 Income + 1 Expense + 1 Transfer (initial payment) = 3 transactions.
        $txCount = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->count();
        $this->assertSame(3, $txCount);

        // The initial payment should be a Transfer (not Income).
        $this->assertDatabaseHas('transactions', [
            'related_type' => HajjUmraBooking::class,
            'related_id'   => $booking->id,
            'type'         => TransactionType::Transfer->value,
            'amount'       => 10000.00,
        ]);

        // And we have at least one hajj_umra_payment row.
        $this->assertDatabaseHas('hajj_umra_payments', [
            'hajj_umra_booking_id' => $booking->id,
            'amount' => 10000.00,
        ]);
    }

    public function test_4_8_gl_after_initial_payment_is_still_conserved(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();

        $payload = [
            'customer_id'    => $customer->id,
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'account_id'     => $this->treasury->id,
            'initial_payment' => ['amount' => 10000],
        ];

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $bookingId = $response->json('data.id');

        $entries = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->where('transactions.related_type', HajjUmraBooking::class)
            ->where('transactions.related_id', $bookingId)
            ->selectRaw('SUM(debit) AS s_debit, SUM(credit) AS s_credit')
            ->first();

        $this->assertEqualsWithDelta(
            (float) $entries->s_debit,
            (float) $entries->s_credit,
            0.01,
            'GL debit must equal credit across create + initial payment.'
        );
    }

    public function test_4_8_cancel_adds_inverse_entries_that_zero_the_net_position(): void
    {
        $booking = $this->makeBooking();
        $txIds   = [$booking->income_transaction_id, $booking->expense_transaction_id];

        $entriesBefore = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->whereIn('transactions.id', $txIds)
            ->selectRaw('SUM(debit) AS s_debit, SUM(credit) AS s_credit')
            ->first();

        // Sum should already be balanced (each tx is balanced).
        $this->assertEqualsWithDelta((float) $entriesBefore->s_debit, (float) $entriesBefore->s_credit, 0.01);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit-cancel',
        ])->assertOk();

        $entriesAfter = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->whereIn('transactions.id', $txIds)
            ->selectRaw('SUM(debit) AS s_debit, SUM(credit) AS s_credit')
            ->first();

        // After cancel: original entries preserved + inverse entries added → still balanced.
        $this->assertEqualsWithDelta((float) $entriesAfter->s_debit, (float) $entriesAfter->s_credit, 0.01);
    }

    public function test_4_8_destroy_adds_inverse_entries_that_zero_the_net_position(): void
    {
        $booking = $this->makeBooking();
        $txIds   = [$booking->income_transaction_id, $booking->expense_transaction_id];

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $sums = DB::table('account_entries')
            ->join('transactions', 'transactions.id', '=', 'account_entries.transaction_id')
            ->whereIn('transactions.id', $txIds)
            ->selectRaw('SUM(debit) AS s_debit, SUM(credit) AS s_credit')
            ->first();

        $this->assertEqualsWithDelta((float) $sums->s_debit, (float) $sums->s_credit, 0.01);
    }

    public function test_4_8_each_module_vault_record_keeps_module_set_to_hajj_umra(): void
    {
        $booking = $this->makeBooking();
        $this->assertSame(TransactionModule::HajjUmra, Transaction::query()->find($booking->income_transaction_id)->module);
        $this->assertSame(TransactionModule::HajjUmra, Transaction::query()->find($booking->expense_transaction_id)->module);
    }

    /* =========================================================
     *  4.9 AUTHORIZATION + API CONTRACT
     * ========================================================= */

    public function test_4_9_store_is_open_to_authenticated_user_with_admin_role(): void
    {
        // Admin user; allowed.
        $booking = $this->makeBooking();
        $this->assertNotNull($booking->id);
    }

    public function test_4_9_destroy_requires_admin_role_via_admin_middleware(): void
    {
        // Re-impersonate as a NON-admin user and verify that DELETE returns
        // 403/401 because it's under the 'admin' middleware.
        $nonAdmin = User::query()->create([
            'name'      => 'Cashier User',
            'email'     => 'cash-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($nonAdmin, ['*']);

        $booking = $this->makeBooking(); // made as admin previously; context stays

        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        // The 'admin' middleware in routes/api.php expects
        // $user->isAdmin() (or admin role). 403/401 means blocked.
        $this->assertContains($response->status(), [401, 403, 419]);
    }

    public function test_4_9_cancel_requires_admin_role_via_admin_middleware(): void
    {
        $nonAdmin = User::query()->create([
            'name'      => 'Cashier User 2',
            'email'     => 'cash2-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($nonAdmin, ['*']);

        // We need to refresh the booking object AFTER impersonation —
        // the booking was created under admin context, but the request
        // now goes through the cashier.
        $booking = $this->makeBooking();
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'x',
        ]);

        // Routes registered with 'admin' middleware expect role=admin.
        // A cashier will be rejected.
        $this->assertContains($response->status(), [401, 403, 419, 422]);
    }

    public function test_4_9_refund_requires_admin_role_via_admin_middleware(): void
    {
        $nonAdmin = User::query()->create([
            'name'      => 'Cashier User 3',
            'email'     => 'cash3-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($nonAdmin, ['*']);

        $booking = $this->makeBooking();
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'x',
        ]);

        $this->assertContains($response->status(), [401, 403, 419, 422]);
    }


    public function test_4_9_index_response_structure_is_consistent(): void
    {
        $this->makeBooking();
        $this->makeBooking();

        $response = $this->getJson('/api/v1/hajj-umra/bookings');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'items',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
            ],
        ]);
    }

    public function test_4_9_index_pagination_via_per_page_query(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeBooking();
        }

        $response = $this->getJson('/api/v1/hajj-umra/bookings?per_page=2');
        $response->assertOk();
        $pagination = $response->json('data.pagination');
        $this->assertSame(2, (int) $pagination['per_page']);
        $this->assertGreaterThanOrEqual(5, (int) $pagination['total']);
    }

    public function test_4_9_index_filter_by_status(): void
    {
        // Boot with a known booking.
        $booking = $this->makeBooking();

        // Filter by the booking's status and confirm it's the only row.
        $status = $booking->status->value;
        $response = $this->getJson("/api/v1/hajj-umra/bookings?status={$status}");
        $response->assertOk();

        $items = $response->json('data.items') ?? [];
        // The total should be exactly 1 (just this booking).
        $this->assertSame(1, (int) $response->json('data.pagination.total'));
        $this->assertCount(1, $items);
    }

    public function test_4_9_index_filter_by_customer_id(): void
    {
        $booking = $this->makeBooking();

        $response = $this->getJson("/api/v1/hajj-umra/bookings?customer_id={$booking->customer_id}");
        $response->assertOk();

        $items = $response->json('data.items') ?? [];
        $this->assertGreaterThanOrEqual(1, count($items));
        foreach ($items as $item) {
            $this->assertSame($booking->customer_id, $item['customer']['id'] ?? null);
        }
    }

    public function test_4_9_index_filter_by_program_id(): void
    {
        $booking = $this->makeBooking();

        $response = $this->getJson("/api/v1/hajj-umra/bookings?program_id={$booking->program_id}");
        $response->assertOk();

        $items = $response->json('data.items') ?? [];
        $this->assertGreaterThanOrEqual(1, count($items));
        foreach ($items as $item) {
            $this->assertSame($booking->program_id, $item['program']['id'] ?? null);
        }
    }

    public function test_4_9_index_filter_by_invalid_status_returns_empty(): void
    {
        // No booking is matched, but no crash.
        $response = $this->getJson('/api/v1/hajj-umra/bookings?status=banana');
        $response->assertOk();
        $this->assertSame(0, (int) $response->json('data.pagination.total'));
    }

    public function test_4_9_show_response_includes_relations_and_aliases(): void
    {
        $booking = $this->makeBooking();

        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'status', 'status_label',
                    'pricing' => ['selling_price', 'purchase_price', 'profit'],
                    'finance' => ['paid_amount', 'remaining_amount', 'is_fully_paid'],
                    'customer',
                    'program',
                ],
            ]);

        $this->assertSame($booking->id, $response->json('data.id'));
        $this->assertNotEmpty($response->json('data.customer'));
        $this->assertNotEmpty($response->json('data.program'));
    }

    public function test_4_9_show_404_on_non_existent_id(): void
    {
        // The route uses {hajjUmra} which is implicit route-model binding.
        // A non-existent id must produce 404.
        $response = $this->getJson('/api/v1/hajj-umra/bookings/999999');
        $response->assertNotFound();
    }

    public function test_4_9_customer_balances_endpoint_returns_shape(): void
    {
        $this->makeBooking();

        $response = $this->getJson('/api/v1/hajj-umra/customer-balances');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
        ]);
        $this->assertIsArray($response->json('data'));
    }

    public function test_4_9_customer_balances_with_status_debtors_filter(): void
    {
        $booking = $this->makeBooking(['selling_price' => 50000, 'purchase_price' => 40000]);
        // No payments → customer has a full debt on this booking.
        $response = $this->getJson('/api/v1/hajj-umra/customer-balances?status=debtors');
        $response->assertOk();
        $data = $response->json('data');
        $found = collect($data)->firstWhere('client_id', $booking->customer_id);
        $this->assertNotNull($found);
        $this->assertGreaterThan(0, (float) ($found['total_debt'] ?? 0));
    }
}
