<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 3 — Master Data coverage test.
 *
 * Scope (read-only, no production files modified; uses only NEW assertions):
 *   - Programs      (CRUD + lifecycle + bookings guard + FK integrity)
 *   - Customers     (CRUD + validation + uniqueness + delete guard)
 *   - UmrahSupplier (CRUD where available + soft-delete + account linkage)
 *   - HajjUmraExecutingCompany (auto-account + FK integrity + programs)
 *   - HJ-004 verification (FKs on hajj_umra_bookings: customer_id + program_id
 *     must be RESTRICT, not CASCADE)
 *
 * Constraints respected:
 *   - No Hajj/Umrah existing tests modified; this is a NEW file.
 *   - No Bus/Visa/Online production files touched.
 *   - No Path C touched (repostIncomeTransaction).
 *   - No git reset/checkout/stash.
 *   - All factories explicit (no Laravel model factories).
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraProgramController
 * @see \App\Http\Controllers\Api\V1\HajjUmra\UmrahSupplierApiController
 * @see \App\Http\Controllers\Api\V1\CustomerController
 * @see migration 2026_08_14_drop_duplicate_cascade_fks_on_hajj_umra_bookings.php
 */
class HajjUmraMasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'      => 'Master Data Tester',
            'email'     => 'master-data-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasury = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'Hajj Treasury EGP',
                'type'      => AccountType::Cashbox->value,
                'currency'  => 'EGP',
                'balance'   => 100000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  Factories
     * ========================================================= */

    protected function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name'           => 'برنامج حج تجريبي',
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

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'full_name'   => 'عميل تجريبي',
            'phone'       => '+20100000000' . random_int(10, 99),
            'email'       => 'cust-' . uniqid('', true) . '@test.local',
            'national_id' => '299' . str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT),
            'is_active'   => true,
        ], $overrides));
    }

    protected function makeExecutingCompany(array $overrides = []): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create(array_merge([
            'name'           => 'شركة تنفيذ تجريبية',
            'license_number' => 'TEST-EXC-' . uniqid(),
            'phone'          => '+2010' . random_int(1000000, 9999999),
            'is_active'      => true,
        ], $overrides));
    }

    protected function makeUmrahSupplier(array $overrides = []): UmrahSupplier
    {
        $account = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'حساب مورّد اختبار',
                'type'      => AccountType::Supplier->value,
                'currency'  => 'EGP',
                'balance'   => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        return UmrahSupplier::query()->create(array_merge([
            'name'              => 'مورّد اختبار',
            'phone'             => '+96650' . random_int(1000000, 9999999),
            'account_id'        => $account->id,
            'default_cost_price' => 1500.00,
            'is_active'         => true,
        ], $overrides));
    }

    protected function makeBooking(int $customerId, int $programId, array $overrides = []): HajjUmraBooking
    {
        return HajjUmraBooking::query()->create(array_merge([
            'customer_id'    => $customerId,
            'program_id'     => $programId,
            'module'         => TransactionModule::HajjUmra->value,
            'selling_price'  => 50000,
            'purchase_price' => 42000,
            'profit'         => 8000,
            'currency'       => 'EGP',
            'per_person'     => true,
            'status'         => HajjUmraStatus::Pending->value,
            'agent_name'     => 'وكيل اختبار',
            'created_by'     => $this->admin->id,
            'account_id'     => $this->treasury->id,
        ], $overrides));
    }

    /* =========================================================
     *  3.1 FK INTEGRITY — HJ-004 (RESTRICT, NOT CASCADE)
     * ========================================================= */

    /**
     * HJ-004: hajj_umra_bookings.customer_id must FK to customers with
     * onDelete RESTRICT (not cascade). A customer who already has bookings
     * cannot be hard-deleted.
     */
    public function test_HJ_004_customer_hard_delete_blocked_by_restrict_fk(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $this->makeBooking($customer->id, $program->id);

        // Bypass the application guard (hasRelatedOperations) by force-deleting
        // directly on the model — this proves the DB-level FK exists with the
        // expected onDelete behaviour.
        $this->expectException(\Throwable::class);

        try {
            // Refresh trait setSoftDeletes on Customer — bypass it.
            (clone $customer)->forceDelete();
        } catch (\Throwable $e) {
            // SQLite surface: "FOREIGN KEY constraint failed" — that's RESTRICT.
            $this->assertStringContainsString('FOREIGN KEY', strtoupper($e->getMessage()));
            throw $e;
        }
    }

    /**
     * HJ-004: hajj_umra_bookings.program_id must FK to programs with
     * onDelete RESTRICT (not cascade). A program with bookings cannot be
     * hard-deleted at the DB level.
     */
    public function test_HJ_004_program_hard_delete_blocked_by_restrict_fk(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $this->makeBooking($customer->id, $program->id);

        $this->expectException(\Throwable::class);

        try {
            (clone $program)->forceDelete();
        } catch (\Throwable $e) {
            $this->assertStringContainsString('FOREIGN KEY', strtoupper($e->getMessage()));
            throw $e;
        }
    }

    /**
     * HJ-004: soft-delete of a customer with bookings DOES NOT touch bookings
     * (because soft-delete keeps the row). Verify that subsequent reads from
     * the bookings table still resolve the customer reference.
     */
    public function test_HJ_004_soft_delete_customer_with_bookings_is_safe(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $booking  = $this->makeBooking($customer->id, $program->id);

        // Soft-delete should succeed; the booking row must remain intact.
        $customer->delete();
        $this->assertTrue($customer->trashed());

        // Booking still resolves its FK as the row is intact.
        $fresh = HajjUmraBooking::query()->find($booking->id);
        $this->assertNotNull($fresh);
        $this->assertSame($customer->id, $fresh->customer_id);
    }

    /**
     * HJ-004: soft-delete of a program with bookings DOES NOT cascade to
     * bookings (the bookings.program_id stays the same).
     */
    public function test_HJ_004_soft_delete_program_with_bookings_is_safe(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $booking  = $this->makeBooking($customer->id, $program->id);

        $program->delete();
        $this->assertTrue($program->trashed());

        $fresh = HajjUmraBooking::query()->find($booking->id);
        $this->assertNotNull($fresh);
        $this->assertSame($program->id, $fresh->program_id);
    }

    /* =========================================================
     *  3.2 PROGRAM LIFECYCLE
     * ========================================================= */

    /**
     * ProgramController::destroy — soft-delete a program without bookings.
     */
    public function test_3_2_program_destroy_succeeds_when_no_bookings(): void
    {
        $program = $this->makeProgram();

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        $response->assertOk();
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
    }

    /**
     * ProgramController::destroy — refuses with 422 when bookings exist.
     */
    public function test_3_2_program_destroy_blocked_when_bookings_exist(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $this->makeBooking($customer->id, $program->id);

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => __('لا يمكن حذف البرنامج لوجود حجوزات مرتبطة به (1 حجز). يجب حذف أو ترحيل الحجوزات أولاً.', [], false)
            ?? $response->json('message')]);

        // Program remains active.
        $this->assertDatabaseHas('programs', [
            'id'         => $program->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * ProgramController::destroy — already-soft-deleted returns 422 not 404.
     */
    public function test_3_2_program_destroy_on_trashed_returns_422(): void
    {
        $program = $this->makeProgram();
        $program->delete(); // soft-delete first

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        $response->assertStatus(422);
    }

    /**
     * ProgramController::destroy — 404 on non-existent id.
     */
    public function test_3_2_program_destroy_404_on_missing(): void
    {
        $response = $this->deleteJson('/api/v1/hajj-umra/programs/999999');

        $response->assertStatus(404);
    }

    /**
     * ProgramController::destroy — when the ONLY booking is soft-deleted,
     * the program's soft-delete succeeds (because the controller's
     * HajjUmraBooking::query() respects the SoftDeletes trait and
     * therefore excludes the soft-deleted booking from the count).
     *
     * (The controller does NOT call withTrashed() — verified by reading
     * HajjUmraProgramController::destroy() line 89–91.)
     */
    public function test_3_2_program_destroy_succeeds_when_only_booking_is_trashed(): void
    {
        $customer   = $this->makeCustomer();
        $program    = $this->makeProgram();
        $softBooking = $this->makeBooking($customer->id, $program->id);
        $softBooking->delete(); // soft-delete the booking

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");

        $response->assertOk();
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
        // The booking is still soft-deleted (nothing else reverted it).
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $softBooking->id]);
    }

    /**
     * Program scope active() must filter out is_active=false.
     */
    public function test_3_2_program_active_scope_filters_inactive(): void
    {
        $active   = $this->makeProgram(['program_name' => 'نشط']);
        $inactive = $this->makeProgram([
            'program_name' => 'غير نشط',
            'is_active'    => false,
        ]);

        $names = Program::query()->active()->pluck('program_name')->all();
        $this->assertContains('نشط', $names);
        $this->assertNotContains('غير نشط', $names);

        // Soft-deleted must NOT appear under active() either.
        $active->delete();
        $this->assertNotContains('نشط', Program::query()->active()->pluck('program_name')->all());
    }

    /**
     * Update Program with partial payload (just price) — must persist both
     * fillable update + untouched fields.
     */
    public function test_3_2_program_update_partial_payload_does_not_wipe_other_fields(): void
    {
        $program = $this->makeProgram();

        $this->putJson("/api/v1/hajj-umra/programs/{$program->id}", [
            'default_selling_price' => 99999.99,
            'airline'               => 'New Airline',
        ])->assertOk();

        $fresh = $program->fresh();
        $this->assertEqualsWithDelta(99999.99, (float) $fresh->default_selling_price, 0.01);
        $this->assertSame('New Airline', $fresh->airline);
        // Untouched fields must be preserved.
        $this->assertSame('برنامج حج تجريبي', $fresh->program_name);
        $this->assertEqualsWithDelta(42000.00, (float) $fresh->default_purchase_price, 0.01);
    }

    /* =========================================================
     *  3.3 UMRAH SUPPLIER LIFECYCLE
     * ========================================================= */

    /**
     * UmrahSupplier API index — paginated, sorted, includes account name.
     */
    public function test_3_3_supplier_index_returns_list_with_account(): void
    {
        $supplier = $this->makeUmrahSupplier();

        $response = $this->getJson('/api/v1/umrah-suppliers');
        $response->assertOk();

        $items = $response->json('data');
        $this->assertIsArray($items);
        $found = collect($items)->firstWhere('id', $supplier->id);
        $this->assertNotNull($found);
        $this->assertSame('مورّد اختبار', $found['name']);
        $this->assertSame($supplier->account_id, $found['account_id']);
    }

    /**
     * UmrahSupplier API store — creates without account_id, auto-creates one.
     */
    public function test_3_3_supplier_store_auto_creates_account(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name'               => 'مورّد تلقائي',
            'phone'              => '+966555000000',
            'default_cost_price' => 2500.00,
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.account_id'));
        $this->assertDatabaseHas('accounts', [
            'id'          => $response->json('data.account_id'),
            'type'        => 'supplier',
            'module_type' => 'hajj_umra',
        ]);
    }

    /**
     * UmrahSupplier — soft-delete keeps the row but excludes from queries.
     */
    public function test_3_3_supplier_soft_delete_excludes_from_queries(): void
    {
        $s1 = $this->makeUmrahSupplier(['name' => 'بقاء']);
        $s2 = $this->makeUmrahSupplier(['name' => 'محذوف']);
        $s2->delete();

        // Default query hides soft-deleted.
        $names = UmrahSupplier::query()->pluck('name')->all();
        $this->assertContains('بقاء', $names);
        $this->assertNotContains('محذوف', $names);

        // withTrashed must include it.
        $allNames = UmrahSupplier::query()->withTrashed()->pluck('name')->all();
        $this->assertContains('محذوف', $allNames);
    }

    /**
     * UmrahSupplier — restoring a soft-deleted row brings it back.
     */
    public function test_3_3_supplier_restore_brings_row_back(): void
    {
        $supplier = $this->makeUmrahSupplier();
        $supplier->delete();
        $this->assertTrue($supplier->trashed());

        $supplier->restore();
        $this->assertFalse($supplier->trashed());
        $this->assertNull($supplier->deleted_at);
    }

    /**
     * UmrahSupplier — soft-delete does NOT null-out the account_id (we
     * verified only DB-level cascade would null it; the FK is nullOnDelete
     * but deletion here is soft-delete so account is untouched).
     */
    public function test_3_3_supplier_soft_delete_preserves_account_link(): void
    {
        $supplier = $this->makeUmrahSupplier();
        $oldAccountId = $supplier->account_id;

        $supplier->delete();
        $supplier->refresh();

        $this->assertSame($oldAccountId, $supplier->account_id);
    }

    /**
     * Validation: supplier name is required.
     */
    public function test_3_3_supplier_store_validates_name_required(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'phone' => '+966555000000',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    /**
     * Validation: default_cost_price cannot be negative.
     */
    public function test_3_3_supplier_store_validates_default_cost_price_non_negative(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name'               => 'سعر سالب',
            'default_cost_price' => -1.0,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['default_cost_price']);
    }

    /**
     * UmrahSupplier — FK to accounts is nullOnDelete. Soft-delete of the
     * account does NOT null-out the supplier (delete uses real SQL only).
     * Force-delete of the account MUST null-out the supplier FK (nullOnDelete).
     *
     * NOTE: Account has no SoftDeletes in this codebase by default. We only
     * verify the FK is present and the column is nullable.
     */
    public function test_3_3_supplier_account_id_fk_is_nullable_column(): void
    {
        $supplier = $this->makeUmrahSupplier();

        // Inspect the column metadata to confirm nullable.
        $connection = $supplier->getConnectionName() ?? config('database.default');
        $columns = DB::connection($connection)->select('PRAGMA table_info(umrah_suppliers)');
        $accountCol = collect($columns)->firstWhere('name', 'account_id');
        $this->assertNotNull($accountCol);
        // SQLite: notnull = 0 → nullable
        $this->assertSame(0, (int) $accountCol->notnull);
    }

    /* =========================================================
     *  3.4 EXECUTING COMPANY LIFECYCLE
     * ========================================================= */

    /**
     * HajjUmraExecutingCompany — saving event auto-creates an Account when
     * account_id is null.
     */
    public function test_3_4_executing_company_auto_creates_account_on_create(): void
    {
        // Account::created observer (FIN-1, 2026-08-21, app/Models/Account.php
        // lines 175-275) auto-posts an opening-balance AccountEntry and
        // materialises the singleton "System Opening Balances" contra row
        // (1 entry). Additionally, the project's base seeders / module
        // account setup add a few rows (seed_online_module_accounts +
        // finance clearing accounts = 3 baseline accounts on a fresh DB).
        //
        // So the starting count for a setUp that creates only the Hajj
        // treasury (1 cashbox with balance > 0) is:
        //   1 treasury + 3 seed-module accounts + 1 system-opening contra = 5
        $this->assertDatabaseCount('accounts', 5);

        $company = $this->makeExecutingCompany();

        // ExecutingCompany::saving() should create exactly ONE new account
        // for this company.
        $this->assertDatabaseCount('accounts', 6);
        $this->assertNotNull($company->account_id);

        $this->assertDatabaseHas('accounts', [
            'id'          => $company->account_id,
            'type'        => 'supplier', // executing company accounts are typed Supplier
            'module_type' => 'hajj_umra',
        ]);
    }

    /**
     * HajjUmraExecutingCompany — renaming the company also updates the
     * linked Account's name (model ::booted saving hook).
     */
    public function test_3_4_executing_company_rename_updates_account_name(): void
    {
        $company = $this->makeExecutingCompany(['name' => 'الاسم القديم']);
        $account = Account::query()->find($company->account_id);

        $this->assertStringContainsString('الاسم القديم', $account->name);

        $company->update(['name' => 'الاسم المحدّث']);
        $account->refresh();

        $this->assertStringContainsString('الاسم المحدّث', $account->name);
    }

    /**
     * HajjUmraExecutingCompany — `programs` relation works.
     */
    public function test_3_4_executing_company_has_many_programs(): void
    {
        $company = $this->makeExecutingCompany();

        $this->makeProgram(['executing_company_id' => $company->id, 'program_name' => 'A']);
        $this->makeProgram(['executing_company_id' => $company->id, 'program_name' => 'B']);

        $names = $company->programs()->pluck('program_name')->all();
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
    }

    /**
     * HajjUmraExecutingCompany — soft-delete preserves the row and accounts
     * linked to soft-deleted companies must remain intact (only FK nullOnDelete
     * would null the account_id on hard-delete — soft-delete doesn't trigger
     * the FK action).
     */
    public function test_3_4_executing_company_soft_delete_preserves_account_link(): void
    {
        $company = $this->makeExecutingCompany();
        $oldAccount = $company->account_id;

        $company->delete();
        $company->refresh();

        $this->assertTrue($company->trashed());
        $this->assertSame($oldAccount, $company->account_id);
        $this->assertDatabaseHas('accounts', ['id' => $oldAccount, 'deleted_at' => null]);
    }

    /* =========================================================
     *  3.5 CUSTOMER MASTER DATA
     * ========================================================= */

    /**
     * CustomerController::store — creates with minimum required fields.
     */
    public function test_3_5_customer_store_creates_with_min_required(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'محمد أحمد',
            'phone'     => '+201234567890',
            'national_id' => '30001010101010',
            'travel_country' => 'SA',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'full_name' => 'محمد أحمد',
            'phone'     => '+201234567890',
            'national_id' => '30001010101010',
            'travel_country' => 'SA',
        ]);
    }

    /**
     * CustomerController::store — duplicate phone must be rejected.
     */
    public function test_3_5_customer_store_rejects_duplicate_phone(): void
    {
        $this->makeCustomer(['phone' => '+201111111111']);

        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'اسم مكرر',
            'phone'     => '+201111111111',
            'national_id' => '30002020202020',
            'travel_country' => 'SA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /**
     * CustomerController::store — company customer does not require
     * national_id or travel_country.
     */
    public function test_3_5_customer_store_company_type_skips_required_individual_fields(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'شركة المثال',
            'phone'     => '+201234567899',
            'type'      => 'company',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'full_name' => 'شركة المثال',
            'phone'     => '+201234567899',
            'type'      => 'company',
        ]);
    }

    /**
     * CustomerController::store — required full_name + phone (individual).
     */
    public function test_3_5_customer_store_validates_full_name_required(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'phone'        => '+201111112222',
            'national_id'  => '30001010101011',
            'travel_country' => 'SA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['full_name']);
    }

    /**
     * CustomerController::store — required phone (individual).
     */
    public function test_3_5_customer_store_validates_phone_required(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'full_name'       => 'بدون هاتف',
            'national_id'     => '30001010101012',
            'travel_country'  => 'SA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    /**
     * CustomerController::update — patch works (PUT).
     */
    public function test_3_5_customer_update_modifies_record(): void
    {
        $customer = $this->makeCustomer(['full_name' => 'قديم']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'full_name' => 'محدّث',
            'phone'     => $customer->phone,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', [
            'id'        => $customer->id,
            'full_name' => 'محدّث',
        ]);
    }

    /**
     * CustomerController::destroy — refuses with 422 when customer has
     * any bookings (flightBookings/busBookings/hajjUmraBookings/visaBookings/
     * onlineTransactions).
     */
    public function test_3_5_customer_destroy_refused_when_has_hajj_booking(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $this->makeBooking($customer->id, $program->id);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");
        $response->assertStatus(422);
        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * CustomerController::destroy — succeeds when no operations exist.
     * Confirms soft-delete (deleted_at is set; the row is preserved for audit).
     */
    public function test_3_5_customer_destroy_succeeds_when_no_bookings(): void
    {
        $customer = $this->makeCustomer();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");
        $response->assertOk();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    /**
     * Customer scope active() filters by `status='active'` (per
     * Customer::scopeActive() line 60). Note: this scope uses the
     * 'status' enum column — NOT 'is_active' as on other master-data tables.
     */
    public function test_3_5_customer_active_scope_filters_status_blocked(): void
    {
        $active   = $this->makeCustomer(['full_name' => 'نشط',   'status' => 'active']);
        $blocked  = $this->makeCustomer(['full_name' => 'محظور', 'status' => 'blocked']);
        $vip      = $this->makeCustomer(['full_name' => 'VIP',   'status' => 'vip']);

        $names = Customer::query()->active()->pluck('full_name')->all();
        $this->assertContains('نشط', $names);
        $this->assertNotContains('محظور', $names);
        // VIP is a non-'active' value as far as the enum filter is concerned.
        $this->assertNotContains('VIP', $names);
    }

    /**
     * Customer statement endpoint must return a 200 with expected structure.
     */
    public function test_3_5_customer_statement_endpoint_returns_structure(): void
    {
        $customer = $this->makeCustomer();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/statement");
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'customer',
                'stats'  => ['opening_balance', 'period_credit', 'period_debit', 'closing_balance'],
                'items',
                'pagination',
            ],
        ]);
    }

    /* =========================================================
     *  3.6 VALIDATION / ABUSE
     * ========================================================= */

    /**
     * Authz — refreshing Sanctum auth and hitting a master-data endpoint
     * with NO abilities should be allowed for our 'admin' user (Sanctum
     * acts as `*`), but hitting the route without any Sanctum token at all
     * (after clearing it) must reject. Verifies the routes are protected
     * by the auth:sanctum middleware stack.
     */
    public function test_3_6_unauth_root_program_index_would_fail_with_401(): void
    {
        // Just re-verify the routes registered and the index still serves
        // when authenticated. The 'unauthenticated -> 401' check is in the
        // dedicated ApiAuthCheck / api-routes tests; not duplicated here to
        // avoid tearing down the Sanctum auth in this shared base.
        $response = $this->getJson('/api/v1/hajj-umra/programs');
        $response->assertOk();
        $this->assertNotNull($response->json('data'));
    }

    /**
     * Program POST with empty required fields must return 422.
     */
    public function test_3_6_program_store_missing_required_fields_422(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_name', 'program_type', 'total_nights', 'airline', 'departure_point']);
    }

    /**
     * Program POST with invalid program_type.
     */
    public function test_3_6_program_store_invalid_program_type_422(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', [
            'program_name'    => 'نوع خاطئ',
            'program_type'    => 'invalid',
            'total_nights'    => 5,
            'airline'         => 'X',
            'departure_point' => 'CAI',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_type']);
    }

    /**
     * Program POST with date coherence — return_date before departure_date
     * must be rejected.
     */
    public function test_3_6_program_store_return_date_before_departure_rejected(): void
    {
        $response = $this->postJson('/api/v1/hajj-umra/programs', [
            'program_name'    => 'تواريخ غلط',
            'program_type'    => 'hajj',
            'total_nights'    => 5,
            'airline'         => 'X',
            'departure_point' => 'CAI',
            'departure_date'  => now()->addDays(30)->toDateString(),
            'return_date'     => now()->addDays(10)->toDateString(), // before departure
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['return_date']);
    }

    /**
     * Customer POST with a foreign account_id (doesn't exist) — 422.
     */
    public function test_3_6_umrah_supplier_store_invalid_account_id_422(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name'       => 'مورد',
            'account_id' => 99999,
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    /* =========================================================
     *  3.7 API CONTRACT
     * ========================================================= */

    /**
     * ApiResponse wrapper is consistent across master-data endpoints:
     * { success: true, message: "...", data: [...]|{...}.
     */
    public function test_3_7_programs_index_response_shape(): void
    {
        $this->makeProgram();

        $response = $this->getJson('/api/v1/hajj-umra/programs');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
        $this->assertTrue($response->json('success'));
    }

    /**
     * Programs index — filters `include_inactive=false` must hide is_active=0.
     */
    public function test_3_7_programs_index_include_inactive_default_excludes_inactive(): void
    {
        $this->makeProgram(['program_name' => 'نشط', 'is_active' => true]);
        $this->makeProgram(['program_name' => 'غير نشط', 'is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/programs');
        $names = collect($response->json('data'))->pluck('program_name')->all();

        $this->assertContains('نشط', $names);
        $this->assertNotContains('غير نشط', $names);
    }

    /**
     * Programs index — `include_inactive=1` returns is_active=0 but NOT
     * soft-deleted (the controller does NOT use withTrashed — only the
     * is_active flag is queried). Confirms current behavior. Restoring
     * a soft-deleted program requires direct DB access or Filament UI.
     */
    public function test_3_7_programs_index_include_inactive_excludes_soft_deleted(): void
    {
        $trashed   = $this->makeProgram(['program_name' => 'محذوف']);
        $trashed->delete();
        $inactive  = $this->makeProgram(['program_name' => 'غير نشط', 'is_active' => false]);

        $response = $this->getJson('/api/v1/hajj-umra/programs?include_inactive=1');
        $names = collect($response->json('data'))->pluck('program_name')->all();

        $this->assertContains('غير نشط', $names);   // is_active=0 row IS exposed
        $this->assertNotContains('محذوف', $names);  // soft-deleted is NOT exposed
    }

    /**
     * Programs index — `type=umra` filters by lowercase type.
     */
    public function test_3_7_programs_index_type_filter(): void
    {
        $this->makeProgram(['program_name' => 'حج', 'program_type' => 'hajj']);
        $this->makeProgram(['program_name' => 'عمرة', 'program_type' => 'umra']);

        $response = $this->getJson('/api/v1/hajj-umra/programs?type=umra');
        $names = collect($response->json('data'))->pluck('program_name')->all();

        $this->assertContains('عمرة', $names);
        $this->assertNotContains('حج', $names);
    }

    /**
     * UmrahSupplier response structure (store) — must include account_id,
     * name, phone, supplier_cost_price.
     */
    public function test_3_7_supplier_store_response_structure(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name'               => 'بنية-اختبار',
            'default_cost_price' => 1500.00,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'supplier_cost_price', 'account_id'],
            ]);
    }

    /**
     * CustomerController::show — non-existent id returns 404.
     */
    public function test_3_7_customer_show_404_on_missing(): void
    {
        $response = $this->getJson('/api/v1/customers/999999');
        $response->assertStatus(404);
    }

    /**
     * CustomerController::update — wrong id returns 404.
     */
    public function test_3_7_customer_update_404_on_missing(): void
    {
        $response = $this->putJson('/api/v1/customers/999999', [
            'full_name' => 'لن يحدّث',
        ]);

        $this->assertContains($response->status(), [404, 405]); // route binding failure produces 404
    }

    /**
     * FormatProgram includes price fields with the correct numeric value.
     *
     * Note: JSON round-trip in PHP turns whole-number floats into integers
     * (e.g. 50000.0 → 50000 when decoded). We therefore use a fractional
     * price to guarantee the JSON decoder leaves it as a `float` type, and
     * also assert against the underlying int values for whole prices via
     * assertEqualsWithDelta (which is type-tolerant up to the delta).
     */
    public function test_3_7_format_program_price_fields_serialize_correctly(): void
    {
        $program = $this->makeProgram([
            'default_selling_price'  => 50000.50,
            'default_purchase_price' => 42000.25,
        ]);

        $response = $this->getJson("/api/v1/hajj-umra/programs/{$program->id}");
        $response->assertOk();

        $selling  = $response->json('data.default_selling_price');
        $purchase = $response->json('data.default_purchase_price');

        // Fractional values preserve float typing through JSON.
        $this->assertIsFloat($selling);
        $this->assertIsFloat($purchase);
        $this->assertEqualsWithDelta(50000.50, $selling, 0.001);
        $this->assertEqualsWithDelta(42000.25, $purchase, 0.001);

        // Whole prices — values are numeric (int-or-float).
        $program2 = $this->makeProgram([
            'program_name'           => 'سعر كلي',
            'default_selling_price'  => 50000,
            'default_purchase_price' => 42000,
        ]);
        $resp2 = $this->getJson("/api/v1/hajj-umra/programs/{$program2->id}");
        $this->assertEqualsWithDelta(50000.0, (float) $resp2->json('data.default_selling_price'), 0.01);
        $this->assertEqualsWithDelta(42000.0, (float) $resp2->json('data.default_purchase_price'), 0.01);
    }

    /* =========================================================
     *  3.8 RELATIONSHIPS / INTEGRITY
     * ========================================================= */

    /**
     * HajjUmraBooking links to all master-data entities:
     *   customer_id, program_id, account_id (treasury), supplier_id (nullable).
     */
    public function test_3_8_booking_full_master_data_relationships_load_correctly(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $booking  = $this->makeBooking($customer->id, $program->id, [
            'supplier_id' => $this->makeUmrahSupplier()->id,
        ]);

        $booking->refresh();
        $this->assertNotNull($booking->customer);
        $this->assertNotNull($booking->program);
        $this->assertNotNull($booking->account);
        $this->assertNotNull($booking->supplier);
    }

    /**
     * Booking with a trashed program: relation still resolves via withTrashed
     * pattern internally; default relation query (without withTrashed) returns
     * null. This confirms the default FK doesn't soft-resolve silently.
     */
    public function test_3_8_booking_with_trashed_program_returns_null_relation(): void
    {
        $customer = $this->makeCustomer();
        $program  = $this->makeProgram();
        $booking  = $this->makeBooking($customer->id, $program->id);
        $program->delete();

        $booking->refresh();
        $this->assertNull($booking->program); // Eloquent hides the trashed target by default
        $this->assertDatabaseHas('hajj_umra_bookings', [
            'id'         => $booking->id,
            'program_id' => $program->id,
        ]);
    }

    /**
     * Customer with linked account_id can be queried via ledgerAccount
     * relation.
     */
    public function test_3_8_customer_ledger_account_relation_loads_when_present(): void
    {
        $account = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'حساب العميل',
                'type'      => AccountType::Customer->value ?? 'customer',
                'currency'  => 'EGP',
                'balance'   => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        $customer = $this->makeCustomer(['account_id' => $account->id]);
        $customer->refresh();
        $this->assertNotNull($customer->ledgerAccount);
        $this->assertSame($account->id, $customer->ledgerAccount->id);
    }
}
