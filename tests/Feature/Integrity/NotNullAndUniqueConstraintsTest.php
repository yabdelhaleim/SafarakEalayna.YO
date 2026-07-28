<?php

namespace Tests\Feature\Integrity;

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6 Follow-up — NOT NULL + composite unique regression tests.
 *
 * Verifies the two constraints added in
 * `2026_07_28_150358_add_phase6_followup_constraints.php`:
 *
 *  1. hajj_umra_bookings.account_id is NOT NULL
 *  2. customers has composite UNIQUE on (phone, national_id)
 *  3. FK on hajj_umra_bookings.account_id uses ON DELETE RESTRICT
 */
class NotNullAndUniqueConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Program $program;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Constraint Test User',
            'email' => 'constraint-test@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Constraint Test Treasury',
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

        $this->program = Program::query()->create([
            'program_name' => 'Constraint Test Program',
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

        $this->customer = Customer::query()->create([
            'full_name' => 'Constraint Test Customer',
            'phone' => '01000000999',
            'national_id' => '12345678901234',
            'created_by' => $this->user->id,
        ]);
    }

    /* =========================================================
     * NOT NULL on hajj_umra_bookings.account_id
     * ========================================================= */

    public function test_hajjumra_booking_cannot_be_created_without_account_id(): void
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
            // account_id intentionally omitted → NOT NULL violation
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_cannot_be_created_with_null_account_id(): void
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
            'account_id' => null, // explicit NULL → NOT NULL violation
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hajjumra_booking_succeeds_with_valid_account_id(): void
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
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertNotNull($booking->id);
    }

    /* =========================================================
     * ON DELETE RESTRICT on hajj_umra_bookings.account_id
     * ========================================================= */

    public function test_cannot_delete_account_with_existing_bookings(): void
    {
        HajjUmraBooking::query()->create([
            'customer_id' => $this->customer->id,
            'program_id' => $this->program->id,
            'module' => 'hajj_umra',
            'selling_price' => 15000,
            'purchase_price' => 10000,
            'profit' => 5000,
            'currency' => 'EGP',
            'per_person' => true,
            'accommodation_choice' => 'standard',
            'status' => 'confirmed',
            'agent_name' => 'Test',
            'account_id' => $this->treasury->id,
            'created_by' => $this->user->id,
        ]);

        // Attempting to hard-delete the treasury account should fail
        // because of the RESTRICT foreign key on hajj_umra_bookings.account_id.
        $this->expectException(QueryException::class);

        DB::table('accounts')->where('id', $this->treasury->id)->delete();
    }

    /* =========================================================
     * Composite UNIQUE on customers(phone, national_id)
     * ========================================================= */

    public function test_duplicate_phone_and_national_id_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // Same phone + same national_id as $this->customer → unique violation
        Customer::query()->create([
            'full_name' => 'Duplicate Person',
            'phone' => $this->customer->phone,
            'national_id' => $this->customer->national_id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_same_phone_with_different_national_id_is_allowed(): void
    {
        // Family members share a phone but have different national_id
        $sibling = Customer::query()->create([
            'full_name' => 'Sibling Customer',
            'phone' => $this->customer->phone, // same phone
            'national_id' => '99999999999999', // different national_id
            'created_by' => $this->user->id,
        ]);

        $this->assertNotNull($sibling->id);
    }

    public function test_different_phone_with_same_national_id_is_allowed(): void
    {
        // One person, two phone numbers (mobile + work)
        Customer::query()->create([
            'full_name' => 'Customer Alt Phone',
            'phone' => '01000000888', // different phone
            'national_id' => $this->customer->national_id, // same national_id
            'created_by' => $this->user->id,
        ]);

        $this->assertTrue(true); // no exception
    }

    public function test_multiple_null_national_ids_are_allowed(): void
    {
        // MySQL treats NULL != NULL in unique indexes, so multiple customers
        // with NULL national_id (and same phone) are allowed.
        Customer::query()->create([
            'full_name' => 'Anonymous Customer 1',
            'phone' => '01000000777',
            'national_id' => null,
            'created_by' => $this->user->id,
        ]);
        Customer::query()->create([
            'full_name' => 'Anonymous Customer 2',
            'phone' => '01000000777',
            'national_id' => null,
            'created_by' => $this->user->id,
        ]);

        $count = Customer::query()->where('phone', '01000000777')->whereNull('national_id')->count();
        $this->assertSame(2, $count);
    }

    public function test_existing_customer_with_null_national_id_can_coexist_with_new_one(): void
    {
        // The current $this->customer has a non-null national_id; verify a new
        // customer with same phone but NULL national_id is allowed.
        Customer::query()->create([
            'full_name' => 'Anonymous Customer',
            'phone' => $this->customer->phone,
            'national_id' => null,
            'created_by' => $this->user->id,
        ]);

        $this->assertTrue(true);
    }

    /* =========================================================
     * Schema introspection (MySQL only)
     * ========================================================= */

    public function test_account_id_column_is_not_null(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped('information_schema only on MySQL');
        }

        $cols = DB::select(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['hajj_umra_bookings', 'account_id']
        );

        $this->assertNotEmpty($cols);
        $this->assertSame('NO', $cols[0]->IS_NULLABLE);
    }

    public function test_account_id_fk_uses_restrict(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped('information_schema only on MySQL');
        }

        $fks = DB::select(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            ['hajj_umra_bookings', 'hajj_umra_bookings_account_id_foreign']
        );

        $this->assertNotEmpty($fks);
        $this->assertSame('RESTRICT', $fks[0]->DELETE_RULE);
    }

    public function test_customers_composite_unique_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped('information_schema only on MySQL');
        }

        $rows = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
               AND NON_UNIQUE = 0
             ORDER BY SEQ_IN_INDEX',
            ['customers', 'customers_phone_national_id_unique']
        );

        $columns = array_column($rows, 'COLUMN_NAME');
        $this->assertSame(['phone', 'national_id'], $columns);
    }
}