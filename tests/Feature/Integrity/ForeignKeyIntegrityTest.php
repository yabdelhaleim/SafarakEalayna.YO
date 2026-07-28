<?php

namespace Tests\Feature\Integrity;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6 — Database Integrity — Foreign key regression tests.
 *
 * Verifies that the FKs added in migration
 * `2026_07_28_143450_add_missing_foreign_keys_to_hajj_umra_tables`
 * actually enforce referential integrity:
 *
 *  - hajj_umra_bookings.customer_id → customers.id
 *  - hajj_umra_bookings.program_id  → programs.id
 *  - hajj_umra_bookings.supplier_id → umrah_suppliers.id
 *  - hajj_umra_bookings.account_id  → accounts.id
 *  - hajj_umra_bookings.created_by  → users.id
 *  - hajj_umra_payments.hajj_umra_booking_id → hajj_umra_bookings.id (CASCADE)
 *  - hajj_umra_payments.account_id  → accounts.id
 *  - hajj_umra_executing_companies.account_id → accounts.id
 *
 * @see \database\migrations\2026_07_28_143450_add_missing_foreign_keys_to_hajj_umra_tables.php
 */
class ForeignKeyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Customer $customer;

    protected Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'FK Test User',
            'email' => 'fk-test@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->treasury = Account::query()->create([
            'name' => 'FK Test Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'FK Test Customer',
            'phone' => '01000000999',
        ]);

        $this->program = Program::query()->create([
            'program_name' => 'FK Test Program',
            'program_type' => 'umrah',
            'total_nights' => 7,
            'mecca_hotel_name' => 'Hotel',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'Hotel',
            'medina_nights' => 3,
            'airline' => 'Airline',
            'executing_company' => 'Co',
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 10000,
            'default_selling_price' => 15000,
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(22)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    /* =========================================================
     * HAJJ_UMRA_BOOKINGS FKs
     * ========================================================= */

    public function test_hajjumra_booking_fk_to_customer_id_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => 999999, // non-existent
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => 1,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_fk_to_program_id_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => $this->customer->id,
            'program_id' => 999999, // non-existent
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => 1,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_fk_to_account_id_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => 1,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => 999999, // non-existent
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_fk_to_created_by_user_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => 1,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => 999999, // non-existent
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_fk_to_supplier_allows_null(): void
    {
        // supplier_id is nullable — should succeed with NULL
        $id = DB::table('hajj_umra_bookings')->insertGetId([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => 1,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
            'supplier_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull($id);
    }

    public function test_hajjumra_booking_fk_to_supplier_set_null_on_delete(): void
    {
        // Create a supplier linked to this booking, then delete the supplier.
        // With ON DELETE SET NULL, the booking's supplier_id should be NULL after.
        $supplierAccount = Account::query()->create([
            'name' => 'Supplier Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);
        $supplier = UmrahSupplier::query()->create([
            'name' => 'Test Supplier',
            'phone' => '+966500000000',
            'account_id' => $supplierAccount->id,
            'is_active' => true,
        ]);

        $booking = HajjUmraBooking::query()->create([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'supplier_id' => $supplier->id,
            'created_by' => $this->user->id,
        ]);

        // Force hard delete to trigger ON DELETE SET NULL
        $supplier->forceDelete();

        $booking->refresh();
        $this->assertNull($booking->supplier_id, 'supplier_id should be NULL after supplier is hard-deleted');
    }

    /* =========================================================
     * HAJJ_UMRA_PAYMENTS FKs
     * ========================================================= */

    public function test_hajjumra_payment_fk_to_booking_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_payments')->insert([
            'hajj_umra_booking_id' => 999999, // non-existent
            'payment_method' => 'cash',
            'amount' => 1000,
            'currency' => 'EGP',
            'treasury_account' => 'Test Treasury',
            'paid_by' => 'Customer',
            'payment_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_payment_cascade_deletes_with_booking(): void
    {
        $booking = HajjUmraBooking::query()->create([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => true,
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
        ]);

        HajjUmraPayment::query()->create([
            'hajj_umra_booking_id' => $booking->id,
            'payment_method' => 'cash',
            'amount' => 5000,
            'currency' => 'EGP',
            'treasury_account' => 'Test Treasury',
            'paid_by' => 'Customer',
            'payment_date' => now(),
            'created_by' => $this->user->id,
        ]);

        $paymentCountBefore = HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking->id)->count();
        $this->assertSame(1, $paymentCountBefore);

        // Hard delete the booking — ON DELETE CASCADE should delete the payment
        $booking->forceDelete();

        $paymentCountAfter = HajjUmraPayment::query()->where('hajj_umra_booking_id', $booking->id)->count();
        $this->assertSame(0, $paymentCountAfter, 'CASCADE should delete payments when booking is hard-deleted');
    }

    /* =========================================================
     * HAJJ_UMRA_EXECUTING_COMPANIES FKs
     * ========================================================= */

    public function test_hajjumra_executing_company_fk_to_account_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hajj_umra_executing_companies')->insert([
            'name' => 'FK Test Co',
            'phone' => '+966500000000',
            'account_id' => 999999, // non-existent
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_executing_company_can_be_created(): void
    {
        // The executing-company observer auto-creates an account on save
        // (it's been doing this since Phase 5). Verify the FK allows this
        // auto-creation pattern: the company row + its auto-created
        // account_id FK are both valid.
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'FK Test Co With Auto Account',
            'phone' => '+966500000001',
            'is_active' => true,
        ]);

        $this->assertNotNull($company->id);
        // After save, observer has set account_id (and that account_id
        // is a real account that exists).
        $this->assertNotNull($company->account_id);
        $this->assertNotNull(Account::query()->find($company->account_id));
    }

    /* =========================================================
     * FK SCHEMA INTROSPECTION (cross-driver)
     * ========================================================= */

    public function test_hajjumra_booking_has_all_required_fks(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped('information_schema only available on MySQL');
        }

        $expectedColumns = [
            'customer_id' => 'customers',
            'program_id' => 'programs',
            'account_id' => 'accounts',
            'created_by' => 'users',
        ];

        foreach ($expectedColumns as $column => $expectedTable) {
            $rows = DB::select(
                'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                ['hajj_umra_bookings', $column]
            );

            $this->assertNotEmpty($rows, "hajj_umra_bookings.{$column} should have a FK");
            $this->assertSame(
                $expectedTable,
                $rows[0]->REFERENCED_TABLE_NAME,
                "hajj_umra_bookings.{$column} should reference {$expectedTable}"
            );
        }
    }

    public function test_hajjumra_payment_has_booking_and_account_fks(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped('information_schema only available on MySQL');
        }

        foreach (['hajj_umra_booking_id' => 'hajj_umra_bookings', 'account_id' => 'accounts'] as $column => $expectedTable) {
            $rows = DB::select(
                'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                ['hajj_umra_payments', $column]
            );

            $this->assertNotEmpty($rows, "hajj_umra_payments.{$column} should have a FK");
            $this->assertSame($expectedTable, $rows[0]->REFERENCED_TABLE_NAME);
        }
    }
}