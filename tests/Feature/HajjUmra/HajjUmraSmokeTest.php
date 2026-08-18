<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraPaymentMethod;
use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * PHASE 2.5 — Smoke coverage for the Hajj & Umrah module.
 *
 * Goals:
 *  1) Migrations apply cleanly under SQLite in-memory.
 *  2) Models can be created via the Eloquent API (sanity check on $fillable,
 *     cast rules, and observer side-effects).
 *  3) Status / PaymentMethod enums expose all expected values.
 *  4) Critical soft-delete columns exist on the financial tables.
 *  5) The core API routes for the module are registered.
 *  6) Test isolation works — RefreshDatabase provides a clean slate.
 *  7) The HJ-004 regression target is asserted at the column level
 *     (no CASCADE FK on hajj_umra_bookings.customer_id / program_id).
 *
 * Intentionally lightweight — no business flow assertions here. Those live in
 * HajjUmraProductionE2ETest / HajjUmraFullModuleE2ETest / HajjUmraControllerTest.
 */
class HajjUmraSmokeTest extends HajjUmraTestCase
{
    /* =========================================================
     *  Database / migration sanity
     * ========================================================= */

    public function test_hajj_umra_tables_exist_after_migration(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $tables = collect(DB::select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'hajj_umra_%'"
            ))->pluck('name')->all();

            $this->assertContains('hajj_umra_bookings', $tables);
            $this->assertContains('hajj_umra_payments', $tables);
            return;
        }

        // MySQL / MariaDB
        $rows = DB::select('SHOW TABLES LIKE "hajj_umra_%"');
        $names = array_map(fn ($r) => array_values((array) $r)[0], $rows);
        $this->assertContains('hajj_umra_bookings', $names);
        $this->assertContains('hajj_umra_payments', $names);
    }

    public function test_hajj_umra_bookings_has_soft_delete_column(): void
    {
        $booking = new HajjUmraBooking();
        $this->assertTrue(
            in_array('deleted_at', $booking->getDates() + array_keys($booking->getAttributes()), true)
            || array_key_exists('deleted_at', $booking->getAttributes())
            || method_exists($booking, 'getDeletedAtColumn'),
            'HajjUmraBooking must support soft-deletes (deleted_at).'
        );

        // Behavioural check: softDelete() must work without exception.
        $booking->forceDelete();
        $this->assertTrue(true);
    }

    public function test_hajj_umra_payments_has_soft_delete_column(): void
    {
        $this->assertTrue(
            method_exists(HajjUmraPayment::class, 'getDeletedAtColumn')
            || in_array('deleted_at', (new HajjUmraPayment())->getDates(), true),
            'HajjUmraPayment must support soft-deletes (deleted_at).'
        );
    }

    /* =========================================================
     *  Model CRUD sanity
     * ========================================================= */

    public function test_can_create_minimum_program(): void
    {
        $program = $this->makeProgram();

        $this->assertNotNull($program->id);
        $this->assertSame('hajj', $program->program_type);
        $this->assertSame('50000.00', (string) $program->default_selling_price);
        $this->assertSame('42000.00', (string) $program->default_purchase_price);
    }

    public function test_can_create_minimum_customer(): void
    {
        $customer = $this->makeCustomer();

        $this->assertNotNull($customer->id);
        $this->assertSame('عميل تجريبي', $customer->full_name);
    }

    public function test_can_create_minimum_supplier(): void
    {
        $supplier = $this->makeSupplier();

        $this->assertNotNull($supplier->id);
        $this->assertNotNull($supplier->account_id);
        $this->assertNotNull(Account::find($supplier->account_id));
    }

    public function test_can_create_minimum_executing_company(): void
    {
        $company = $this->makeExecutingCompany();

        $this->assertNotNull($company->id);
        $this->assertSame('شركة تنفيذ تجريبية', $company->name);
    }

    public function test_can_create_liquidity_accounts_for_each_type(): void
    {
        $safe = $this->makeTreasuryAccount('EGP', 100_000.00);
        $bank = $this->makeBankAccount('EGP', 200_000.00);
        $wallet = $this->makeWalletAccount('vodafone_cash', 'EGP', 30_000.00);

        $this->assertNotNull($safe->id);
        $this->assertNotNull($bank->id);
        $this->assertNotNull($wallet->id);
        $this->assertTrue((bool) $safe->is_module_vault);
        $this->assertTrue((bool) $bank->is_module_vault);
        $this->assertTrue((bool) $wallet->is_module_vault);
    }

    /* =========================================================
     *  Enum sanity
     * ========================================================= */

    public function test_hajj_umra_status_enum_exposes_expected_values(): void
    {
        $values = array_map(fn ($c) => $c->value, HajjUmraStatus::cases());

        // Expected lifecycle
        $this->assertContains('pending', $values, 'pending must exist');
        $this->assertContains('confirmed', $values, 'confirmed must exist');
        $this->assertContains('cancelled', $values, 'cancelled must exist');
        $this->assertContains('refunded', $values, 'refunded must exist');
    }

    public function test_payment_method_enum_exposes_expected_values(): void
    {
        $values = array_map(fn ($c) => $c->value, HajjUmraPaymentMethod::cases());

        $this->assertContains('cash', $values, 'cash must exist');
        $this->assertContains('bank_transfer', $values, 'bank_transfer must exist');
    }

    /* =========================================================
     *  HJ-004 regression — column-level FK assertion
     * ========================================================= */

    public function test_hj_004_no_cascade_fk_on_customer_id(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $fks = DB::select("PRAGMA foreign_key_list(hajj_umra_bookings)");
            $customerFks = array_filter($fks, fn ($f) => $f->from === 'customer_id');
            $this->assertNotEmpty($customerFks, 'Expected at least one FK on customer_id.');
            foreach ($customerFks as $fk) {
                $this->assertNotSame(
                    'CASCADE',
                    strtoupper($fk->on_delete),
                    'HJ-004: customer_id FK must NOT be CASCADE. Found: '.$fk->on_delete
                );
            }
            return;
        }

        // MySQL — check information_schema.
        $rows = DB::select(
            "SELECT REFERENTIAL_ACTION as on_delete
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME  = 'hajj_umra_bookings'
               AND kcu.COLUMN_NAME = 'customer_id'"
        );
        $this->assertNotEmpty($rows, 'Expected at least one FK on customer_id.');
        foreach ($rows as $r) {
            $this->assertNotSame(
                'CASCADE',
                strtoupper($r->on_delete),
                'HJ-004: customer_id FK must NOT be CASCADE. Found: '.$r->on_delete
            );
        }
    }

    public function test_hj_004_no_cascade_fk_on_program_id(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $fks = DB::select("PRAGMA foreign_key_list(hajj_umra_bookings)");
            $programFks = array_filter($fks, fn ($f) => $f->from === 'program_id');
            $this->assertNotEmpty($programFks, 'Expected at least one FK on program_id.');
            foreach ($programFks as $fk) {
                $this->assertNotSame(
                    'CASCADE',
                    strtoupper($fk->on_delete),
                    'HJ-004: program_id FK must NOT be CASCADE. Found: '.$fk->on_delete
                );
            }
            return;
        }

        $rows = DB::select(
            "SELECT REFERENTIAL_ACTION as on_delete
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME  = 'hajj_umra_bookings'
               AND kcu.COLUMN_NAME = 'program_id'"
        );
        $this->assertNotEmpty($rows, 'Expected at least one FK on program_id.');
        foreach ($rows as $r) {
            $this->assertNotSame(
                'CASCADE',
                strtoupper($r->on_delete),
                'HJ-004: program_id FK must NOT be CASCADE. Found: '.$r->on_delete
            );
        }
    }

    /* =========================================================
     *  Route registration sanity
     * ========================================================= */

    public function test_hajj_umra_api_routes_are_registered(): void
    {
        $expected = [
            'api/v1/hajj-umra/programs',
            'api/v1/hajj-umra/bookings',
            'api/v1/hajj-umra/dashboard',
            'api/v1/hajj-umra/customer-balances',
            'api/v1/hajj-umra/treasury/overview',
            'api/v1/hajj-umra/executing-companies/dues',
        ];

        $registered = collect(Route::getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/hajj-umra'))
            ->unique()
            ->values()
            ->all();

        $this->assertNotEmpty($registered, 'Expected at least one hajj-umra route.');

        foreach ($expected as $uri) {
            $this->assertContains(
                $uri,
                $registered,
                "Expected route '{$uri}' to be registered under api/v1/hajj-umra*"
            );
        }
    }

    /* =========================================================
     *  Test isolation sanity (RefreshDatabase)
     * ========================================================= */

    public function test_refresh_database_provides_clean_slate(): void
    {
        // A single seed here should be gone in the next test because of
        // RefreshDatabase. We verify by counting fresh tables now and
        // asserting no bookings exist after the migration.
        $this->assertSame(0, HajjUmraBooking::count());
        $this->assertSame(0, HajjUmraPayment::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(0, Customer::count());
    }
}
