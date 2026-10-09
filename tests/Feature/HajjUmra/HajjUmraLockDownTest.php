<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionType;
use App\Http\Requests\HajjUmra\UpdateHajjUmraBookingRequest;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 10.5 — Tourism No-Edit Contract Proof Suite (2026-08-17).
 *
 * Evolved from PHASE 4.6 (price lock-down) into a stricter no-edit
 * contract. The previous proof suite (locked-fields-only 422) is
 * updated here to match the new architectural decision:
 *
 *   ┌─ No PUT/PATCH routes (returns HTTP 405 Method Not Allowed).
 *   ├─ HajjUmraBookingService::update() throws \LogicException
 *   │  unconditionally (cancellation is the supported correction path).
 *   └─ UpdateHajjUmraBookingRequest::LOCKED_FIELDS still lists the 5
 *      financial columns for any future revival — kept as a constant
 *      so the historical contract is preserved as documentation.
 *
 * Why the contract tightened (INCIDENT-2026-08-17)
 * ─────────────────────────────────────────────────
 * Even with the field-level lock from Phase 4.6, the surface area for
 * update bugs remained:
 *   1. Non-financial updates (status, notes, agent_name) could still
 *      trigger recalculation paths via partial code routes.
 *   2. The MySQL `transactions_income_unique_key` index requires every
 *      consumer to filter on `notes NOT LIKE 'عكس:%'` — a leaky
 *      abstraction that risks double-counting in revenue reports.
 *   3. Tinker / jobs / future routes could call the service directly
 *      and bypass the Form Request stripping.
 *
 * The chosen resolution: forbid the entire PUT/PATCH surface. Any
 * correction is performed via cancel + recreate (Phase 12.5).
 *
 * Proof points covered in this suite:
 *   1. Prices accepted at CREATE.
 *   2. PUT/PATCH routes emit HTTP 405 (route removed, not 422).
 *   3. DB row state unchanged after a 405.
 *   4. Income/Expense transactions NOT reposted after 405.
 *   5. FK transaction pointers unchanged after 405.
 *   6. GL remains balanced after 405.
 *   7. account_entries count unchanged after 405.
 *   8. Form Request LOCKED_FIELDS constant still documents the 5 fields.
 *   9. Internal service calls throw \LogicException unconditionally
 *      (regardless of which fields are present).
 *
 * Each test asserts ONE invariant precisely.
 *
 * @see UpdateHajjUmraBookingRequest::LOCKED_FIELDS
 * @see HajjUmraBookingService::update()
 *
 * @audit-fix phase-4.6-lockdown-20260814
 * @audit-fix phase-10.5-noedit-contract-20260817
 */
class HajjUmraLockDownTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Lock-Down Tester',
            'email' => 'lock-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasury = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'خزينة Lock-Down',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  Helpers (local copies; do not depend on other test files).
     * ========================================================= */

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'full_name' => 'عميل Lock-Down',
            'phone' => '+20107'.random_int(1000000, 9999999),
            'email' => 'lock-cust-'.uniqid('', true).'@test.local',
            'national_id' => '296'.str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $overrides));
    }

    protected function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name' => 'برنامج Lock-Down',
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'شركة تنفيذ',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();

        $payload = array_merge([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 40000,
            'selling_price' => 50000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => HajjUmraStatus::Confirmed->value,
            'agent_name' => 'وكيل Lock-Down',
            'account_id' => $this->treasury->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        return HajjUmraBooking::query()->findOrFail($response->json('data.id'));
    }

    /* =========================================================
     *  PROOF #1 — Prices are accepted at CREATE
     * ========================================================= */

    public function test_4_6_1_create_with_selling_price_succeeds(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->makeCustomer()->id,
            'program_id' => $this->makeProgram()->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'وكيل Create-1',
            'account_id' => $this->treasury->id,
        ]);
        $response->assertCreated();
        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(15000, (float) $booking->selling_price, 0.01);
        $this->assertNotNull($booking->income_transaction_id);
        $this->assertEqualsWithDelta(15000, (float) $booking->incomeTransaction->amount, 0.01);
    }

    public function test_4_6_2_create_with_purchase_price_succeeds(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->makeCustomer()->id,
            'program_id' => $this->makeProgram()->id,
            'purchase_price' => 8800,
            'selling_price' => 12000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'وكيل Create-2',
            'account_id' => $this->treasury->id,
        ]);
        $response->assertCreated();
        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(8800, (float) $booking->purchase_price, 0.01);
        $this->assertEqualsWithDelta(3200, (float) $booking->profit, 0.01); // 12000-8800
    }

    public function test_4_6_3_create_with_companion_prices_succeeds(): void
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $companion = $this->makeCustomer(['full_name' => 'مرافق']);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'companion_customer_id' => $companion->id,
            'program_id' => $program->id,
            'purchase_price' => 30000,
            'selling_price' => 40000,
            'companion_purchase_price' => 15000,
            'companion_selling_price' => 20000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'وكيل Companion',
            'account_id' => $this->treasury->id,
        ]);
        $response->assertCreated();
        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(15000, (float) $booking->companion_purchase_price, 0.01);
        $this->assertEqualsWithDelta(20000, (float) $booking->companion_selling_price, 0.01);
        $this->assertEqualsWithDelta(60000, (float) $booking->total_selling_price, 0.01); // 40k + 20k
    }

    public function test_4_6_4_create_with_accommodation_extra_charge_succeeds(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->makeCustomer()->id,
            'program_id' => $this->makeProgram()->id,
            'purchase_price' => 20000,
            'selling_price' => 30000,
            'accommodation_extra_charge' => 5000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'وكيل Accom',
            'account_id' => $this->treasury->id,
        ]);
        $response->assertCreated();
        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(5000, (float) $booking->accommodation_extra_charge, 0.01);
        $this->assertEqualsWithDelta(35000, (float) $booking->total_selling_price, 0.01); // 30k + 5k
        $this->assertEqualsWithDelta(35000, (float) $booking->incomeTransaction->amount, 0.01);
    }

    /* =========================================================
     *  PROOF #2 — Prices are rejected after CREATE (422)
     * ========================================================= */

    public function test_4_6_5_update_selling_price_returns_405_route_removed(): void
    {
        // PHASE 10.5 (2026-08-17): the PUT route was removed entirely.
        // The API now responds with 405 Method Not Allowed, which is a
        // STRONGER guarantee than 422 — it proves the locked-field code
        // branch is never reached because the route itself is absent.
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);
    }

    public function test_4_6_6_update_purchase_price_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'purchase_price' => 99999,
        ])->assertStatus(405);
    }

    public function test_4_6_7_update_companion_selling_price_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'companion_selling_price' => 99999,
        ])->assertStatus(405);
    }

    public function test_4_6_8_update_companion_purchase_price_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'companion_purchase_price' => 99999,
        ])->assertStatus(405);
    }

    public function test_4_6_9_update_accommodation_extra_charge_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'accommodation_extra_charge' => 99999,
        ])->assertStatus(405);
    }

    /* =========================================================
     *  PROOF #3 — DB value unchanged after rejection attempt
     * ========================================================= */

    public function test_4_6_10_selling_price_in_db_unchanged_after_attempted_modification(): void
    {
        // The PUT route is removed (405), so the controller is never reached
        // and the DB row is provably untouched.
        $booking = $this->makeBooking(['selling_price' => 50000]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        // Direct DB read (bypasses any model cache).
        $raw = (float) DB::table('hajj_umra_bookings')->where('id', $booking->id)->value('selling_price');
        $this->assertEqualsWithDelta(50000, $raw, 0.01);
    }

    public function test_4_6_11_purchase_price_in_db_unchanged_after_attempted_modification(): void
    {
        $booking = $this->makeBooking(['purchase_price' => 40000]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'purchase_price' => 99999,
        ])->assertStatus(405);

        $raw = (float) DB::table('hajj_umra_bookings')->where('id', $booking->id)->value('purchase_price');
        $this->assertEqualsWithDelta(40000, $raw, 0.01);
    }

    /* =========================================================
     *  PROOF #4 — Income / Expense transactions NOT reposted
     * ========================================================= */

    public function test_4_6_12_no_income_repost_occurred(): void
    {
        $booking = $this->makeBooking(['selling_price' => 50000]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        $incomeCount = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Income->value)
            ->count();
        $this->assertSame(1, $incomeCount, 'Lock-down must not produce a second Income row.');
    }

    public function test_4_6_13_no_expense_repost_occurred(): void
    {
        // FIN-3 REMEDIATION (2026-08-21): `recordExpense()` now
        // explicitly sets `type=Expense` even when it routes through
        // `recordJournalTransfer()` (the clearing-account path). This
        // is critical for treasury dashboards which filter by
        // `type='expense'`. Pre-fix, the type silently became Transfer,
        // masking expense rows from reports.
        //
        // So the cost-side posting on a booking is recorded as ONE
        // Transaction with `type=Expense` and `related_type=HajjUmraBooking`.
        $booking = $this->makeBooking(['purchase_price' => 40000]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'purchase_price' => 99999,
        ])->assertStatus(405);

        $expenseCount = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Expense->value)
            ->count();
        $this->assertSame(1, $expenseCount,
            'Lock-down must not produce a second cost-side Expense posting.');
    }

    public function test_4_6_14_no_reversal_rows_with_reversal_prefix_created(): void
    {
        $booking = $this->makeBooking();

        // Attempt all 5 locked fields combined.
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 9500,
            'purchase_price' => 3500,
            'companion_selling_price' => 5000,
            'companion_purchase_price' => 2500,
            'accommodation_extra_charge' => 1000,
        ])->assertStatus(405);

        $reversedCount = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)
            ->where(function ($q) {
                $q->where('notes', 'like', 'عكس:%')
                    ->orWhere('notes', 'like', 'عكس %');
            })
            ->count();
        $this->assertSame(0, $reversedCount,
            'Lock-down must never produce any reversal-prefixed rows.');
    }

    /* =========================================================
     *  PROOF #5 — FK transaction pointers unchanged
     * ========================================================= */

    public function test_4_6_15_income_fk_unchanged_after_selling_price_update_attempt(): void
    {
        $booking = $this->makeBooking();
        $originalIncomeId = $booking->income_transaction_id;
        $originalExpenseId = $booking->expense_transaction_id;

        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertSame($originalIncomeId, $booking->income_transaction_id);
        $this->assertSame($originalExpenseId, $booking->expense_transaction_id);
    }

    public function test_4_6_16_purchase_fk_unchanged_after_purchase_price_update_attempt(): void
    {
        $booking = $this->makeBooking();
        $originalIncomeId = $booking->income_transaction_id;
        $originalExpenseId = $booking->expense_transaction_id;

        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'purchase_price' => 99999,
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertSame($originalIncomeId, $booking->income_transaction_id);
        $this->assertSame($originalExpenseId, $booking->expense_transaction_id);
    }

    public function test_4_6_17_original_income_transaction_amount_intact(): void
    {
        $booking = $this->makeBooking(['selling_price' => 50000]);
        $originalIncomeId = $booking->income_transaction_id;

        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        $income = Transaction::query()->findOrFail($originalIncomeId);
        $this->assertEqualsWithDelta(50000, (float) $income->amount, 0.01,
            'Original Income amount must remain untouched.');
    }

    /* =========================================================
     *  PROOF #6 — GL remains balanced (debits = credits)
     * ========================================================= */

    public function test_4_6_18_gl_remains_balanced_after_rejected_selling_update(): void
    {
        $booking = $this->makeBooking(['selling_price' => 50000]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        $sums = DB::table('account_entries as ae')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where('t.related_type', HajjUmraBooking::class)
            ->where('t.related_id', $booking->id)
            ->selectRaw('SUM(ae.debit) AS s_debit, SUM(ae.credit) AS s_credit')
            ->first();

        $this->assertNotNull($sums);
        $net = round((float) $sums->s_debit - (float) $sums->s_credit, 2);
        $this->assertEqualsWithDelta(0.0, $net, 0.01,
            'GL must remain balanced: sum(debit) == sum(credit) at booking level.');
        // Also: must have non-zero entries (sanity check — empty booking would green-pass).
        $this->assertGreaterThan(0.0, (float) $sums->s_debit);
    }

    public function test_4_6_19_gl_account_balances_unchanged_after_all_locked_updates(): void
    {
        $booking = $this->makeBooking();

        // Snapshot all account balances linked to this booking's transactions.
        $linkedAccounts = DB::table('account_entries as ae')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where('t.related_type', HajjUmraBooking::class)
            ->where('t.related_id', $booking->id)
            ->pluck('ae.account_id')
            ->unique()
            ->all();

        $snapshotBefore = [];
        foreach ($linkedAccounts as $accId) {
            $snapshotBefore[$accId] = (float) DB::table('accounts')->where('id', $accId)->value('balance');
        }

        // Attempt 5 locked updates.
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 95000,
            'purchase_price' => 35000,
            'companion_selling_price' => 50000,
            'companion_purchase_price' => 25000,
            'accommodation_extra_charge' => 10000,
        ])->assertStatus(405);

        $snapshotAfter = [];
        foreach ($linkedAccounts as $accId) {
            $snapshotAfter[$accId] = (float) DB::table('accounts')->where('id', $accId)->value('balance');
        }
        $this->assertSame($snapshotBefore, $snapshotAfter,
            'No account balances may change as a result of a rejected update.');
    }

    /* =========================================================
     *  PROOF #7 — Non-financial updates are blocked by the
     *  Tourism no-edit contract (PHASE 10.5).
     *
     *  PHASE 4.6 used to allow notes / agent_name / status updates
     *  to go through (only the 5 financial fields were locked). With
     *  the no-edit contract (2026-08-17), PUT/PATCH is removed
     *  entirely — so EVERY field, including non-financial ones,
     *  returns 405. These tests now prove that.
     * ========================================================= */

    public function test_4_6_20_notes_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'notes' => 'ملاحظة جديدة بعد LOCK-DOWN',
        ])->assertStatus(405);

        // The notes field stays as created.
        $booking->refresh();
        $this->assertNotSame('ملاحظة جديدة بعد LOCK-DOWN', $booking->notes);
    }

    public function test_4_6_21_agent_name_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'agent_name' => 'وكيل محدث',
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertNotSame('وكيل محدث', $booking->agent_name);
    }

    public function test_4_6_22_status_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking(['status' => HajjUmraStatus::Confirmed->value]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'status' => HajjUmraStatus::InProgress->value,
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertSame(HajjUmraStatus::Confirmed->value, $booking->status->value,
            'Status must remain unchanged after a rejected PUT.');
    }

    public function test_4_6_23_accommodation_choice_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $originalAccomChoice = $booking->accommodation_choice;
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'accommodation_choice' => 'QUAD',
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertSame($originalAccomChoice, $booking->accommodation_choice);
    }

    public function test_4_6_24_per_person_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking(['per_person' => true]);
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'per_person' => false,
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertTrue((bool) $booking->per_person);
    }

    public function test_4_6_25_supplier_id_update_returns_405_route_removed(): void
    {
        $booking = $this->makeBooking();
        $originalSupplierId = $booking->supplier_id;
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'supplier_id' => null,
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertSame($originalSupplierId, $booking->supplier_id);
    }

    /* =========================================================
     *  PROOF #8 — Defense-in-depth: HajjUmraBookingService::update()
     *  throws \LogicException UNCONDITIONALLY (PHASE 10.5 contract).
     *
     *  PHASE 4.6 used to throw RuntimeException only when one of the
     *  5 locked financial fields appeared in $data, allowing non-
     *  financial updates. PHASE 10.5 widened the contract: the
     *  service throws \LogicException regardless of which fields
     *  are present. Cancellation is the only supported correction.
     * ========================================================= */

    public function test_4_6_26_internal_service_call_with_selling_price_throws_logic_exception(): void
    {
        $booking = $this->makeBooking();
        $service = app(HajjUmraBookingService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        $service->update($booking, ['selling_price' => 99999]);
    }

    public function test_4_6_27_internal_service_call_with_purchase_price_throws_logic_exception(): void
    {
        $booking = $this->makeBooking();
        $service = app(HajjUmraBookingService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        $service->update($booking, ['purchase_price' => 99999]);
    }

    public function test_4_6_28_internal_service_call_with_all_locked_fields_throws_logic_exception(): void
    {
        // PHASE 10.5: the unconditional throw fires BEFORE the per-field
        // business message runs, so the exception text is the same regardless
        // of which (or how many) financial fields are present.
        $booking = $this->makeBooking();
        $service = app(HajjUmraBookingService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        $service->update($booking, [
            'selling_price' => 99999,
            'purchase_price' => 88888,
            'companion_selling_price' => 77777,
            'companion_purchase_price' => 66666,
            'accommodation_extra_charge' => 55555,
        ]);
    }

    public function test_4_6_29_internal_service_call_with_no_locked_fields_throws_logic_exception(): void
    {
        // PHASE 10.5: even with ONLY non-financial fields in $data the
        // service throws \LogicException. The PHASE 4.6 carve-out
        // ('agent_name update succeeds') is gone. Cancellation is the
        // only supported correction path.
        $booking = $this->makeBooking();
        $service = app(HajjUmraBookingService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        $service->update($booking, [
            'agent_name' => 'وكيل Internal OK',
        ]);
    }

    /* =========================================================
     *  PROOF #9 — Form Request LOCKED_FIELDS constant still lists
     *  the 5 financial columns (kept as documentation of the
     *  PHASE 4.6 contract that preceded PHASE 10.5).
     * ========================================================= */

    public function test_4_6_30_form_request_locked_fields_constant_lists_all_5_fields(): void
    {
        $expected = [
            'selling_price',
            'purchase_price',
            'companion_selling_price',
            'companion_purchase_price',
            'accommodation_extra_charge',
        ];
        $this->assertSame($expected, UpdateHajjUmraBookingRequest::LOCKED_FIELDS,
            'LOCKED_FIELDS contract must be exactly these 5 columns.');
    }

    public function test_4_6_31_locked_field_request_returns_405_route_removed(): void
    {
        // PHASE 10.5: the PUT route is removed, so the Form Request's
        // prepareForValidation() never runs. The request is rejected
        // by the router with 405 BEFORE the controller is dispatched.
        // This test proves the "reject locked fields" guarantee is
        // enforced at the routing layer (not just the Form Request).
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);
    }

    public function test_4_6_31b_other_field_validation_rules_unreachable(): void
    {
        // PHASE 10.5: the Form Request is never reached via API, so its
        // rule-based validator (for non-financial fields) is not exercised
        // via the HTTP boundary. The contract guarantees no update ever
        // lands, regardless of which fields are present in the payload.
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'status' => 'this_status_is_not_a_real_enum_value_xyz',
        ])->assertStatus(405);
    }

    public function test_4_6_32_combined_locked_plus_unlocked_returns_405(): void
    {
        $booking = $this->makeBooking();
        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
            'agent_name' => 'وكيل مدمج',
        ])->assertStatus(405);

        // Neither field was applied — the row is untouched.
        $booking->refresh();
        $this->assertEqualsWithDelta(50000, (float) $booking->selling_price, 0.01);
        $this->assertSame('وكيل Lock-Down', $booking->agent_name);
    }

    /* =========================================================
     *  PROOF #10 — DB row count & accounts integrity
     * ========================================================= */

    public function test_4_6_33_zero_new_account_entries_created_after_rejection(): void
    {
        $booking = $this->makeBooking();
        $entryCountBefore = AccountEntry::query()
            ->whereIn('transaction_id', function ($q) use ($booking) {
                $q->select('id')->from('transactions')
                    ->where('related_type', HajjUmraBooking::class)
                    ->where('related_id', $booking->id);
            })
            ->count();

        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'selling_price' => 99999,
        ])->assertStatus(405);

        $entryCountAfter = AccountEntry::query()
            ->whereIn('transaction_id', function ($q) use ($booking) {
                $q->select('id')->from('transactions')
                    ->where('related_type', HajjUmraBooking::class)
                    ->where('related_id', $booking->id);
            })
            ->count();

        $this->assertSame($entryCountBefore, $entryCountAfter,
            'No new account entries may be created from a rejected update.');
    }

    public function test_4_6_34_cancelled_booking_still_blocked_by_noedit_contract(): void
    {
        // PHASE 10.5: PUT route is removed universally, regardless of
        // booking state. A cancelled booking therefore also returns 405.
        // This is a stronger guarantee than PHASE 4.6 (which relied on
        // a 422 from a status-specific controller check).
        $booking = $this->makeBooking();
        app(HajjUmraBookingService::class)->cancel($booking, 'for testing');

        $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'agent_name' => 'وكيل بعد الإلغاء',
        ])->assertStatus(405);

        $booking->refresh();
        $this->assertNotSame('وكيل بعد الإلغاء', $booking->agent_name,
            'agent_name must remain unchanged after no-edit 405 on cancelled booking.');
    }
}
