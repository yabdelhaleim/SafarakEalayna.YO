<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;
use App\Models\User;
use App\Models\UmrahTransactionPassenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 2 — Database Integrity audit.
 *
 * Asserts the structural properties of every Hajj & Umrah table:
 *   • Primary keys
 *   • Foreign keys (declared + actually enforced)
 *   • Unique constraints
 *   • Indexes
 *   • Nullable / non-nullable columns
 *   • Decimal precision / currency handling
 *   • Status enum values
 *   • Soft-delete columns
 *   • Orphan records after lifecycle
 *
 * Uses SQLite in-memory + RefreshDatabase so PRAGMA queries return the
 * actual migration-applied schema.
 *
 * @see \database\migrations\2026_*_hajj_umra_*
 */
class HajjUmraDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /* =========================================================
     * SQLite PRAGMA helpers (portable; SQLite has no Doctrine SM)
     * ========================================================= */

    private function pr(string $sql): array
    {
        return DB::select($sql);
    }

    /** Returns assoc array: [column_name => ['type'=>..., 'notnull'=>0|1, 'default'=>..., 'pk'=>0|1]] */
    private function columns(string $table): array
    {
        $rows = $this->pr("PRAGMA table_info(\"{$table}\")");
        $out = [];
        foreach ($rows as $r) {
            $out[$r->name] = [
                'type' => $r->type,
                'notnull' => (int) $r->notnull,
                'default' => $r->dflt_value,
                'pk' => (int) $r->pk,
            ];
        }
        return $out;
    }

    /** Returns list of FKs: [['name'=>..., 'from'=>..., 'to'=>..., 'table'=>..., 'on_delete'=>..., 'on_update'=>...]] */
    private function foreignKeys(string $table): array
    {
        $rows = $this->pr("PRAGMA foreign_key_list(\"{$table}\")");
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => "fk_{$table}_{$r->from}",
                'from' => $r->from,
                'to' => $r->to,
                'table' => $r->table,
                'on_delete' => strtoupper((string) $r->on_delete),
                'on_update' => strtoupper((string) $r->on_update),
            ];
        }
        return $out;
    }

    /** Returns [['name'=>..., 'unique'=>0|1, 'origin'=>...]] */
    private function indexes(string $table): array
    {
        $rows = $this->pr("PRAGMA index_list(\"{$table}\")");
        $out = [];
        foreach ($rows as $r) {
            $cols = $this->pr("PRAGMA index_info(\"{$r->name}\")");
            $colNames = array_map(fn ($c) => $c->name, $cols);
            $out[] = [
                'name' => $r->name,
                'unique' => (int) $r->unique,
                'origin' => $r->origin, // 'c' = CREATE INDEX, 'u' = UNIQUE, 'pk' = PRIMARY KEY
                'columns' => $colNames,
            ];
        }
        return $out;
    }

    private function fkFromCol(string $table, string $col): ?array
    {
        foreach ($this->foreignKeys($table) as $fk) {
            if ($fk['from'] === $col) {
                return $fk;
            }
        }
        return null;
    }

    private function assertColumnExists(string $table, string $column): void
    {
        $this->assertArrayHasKey($column, $this->columns($table), "Column {$table}.{$column} does not exist");
    }

    /* =========================================================
     * PRIMARY KEYS
     * ========================================================= */

    public function test_hajj_umra_bookings_has_primary_key(): void
    {
        $cols = $this->columns('hajj_umra_bookings');
        $this->assertArrayHasKey('id', $cols);
        $this->assertSame(1, $cols['id']['pk'], 'id should be PK');
        $this->assertSame(1, $cols['id']['notnull'], 'id should be NOT NULL');
    }

    public function test_hajj_umra_payments_has_primary_key(): void
    {
        $cols = $this->columns('hajj_umra_payments');
        $this->assertSame(1, $cols['id']['pk']);
    }

    public function test_programs_has_primary_key(): void
    {
        $cols = $this->columns('programs');
        $this->assertSame(1, $cols['id']['pk']);
    }

    public function test_umrah_transaction_passengers_has_primary_key(): void
    {
        $cols = $this->columns('umrah_transaction_passengers');
        $this->assertSame(1, $cols['id']['pk']);
    }

    /* =========================================================
     * FOREIGN KEYS — hajj_umra_bookings
     * ========================================================= */

    public function test_bookings_fk_to_customers(): void
    {
        // HJ-004 — CRITICAL DEFECT TEST. Migration 2026_07_28_143450 attempted to
        // upgrade this FK from CASCADE (original 2026_04_27_124551) to RESTRICT,
        // but its `addForeignKeyIfMissing()` helper skipped the upgrade because
        // the original CASCADE FK already existed on the column. The result:
        //   - On MySQL production: only the original CASCADE FK is in place.
        //     Deleting a customer with bookings silently cascade-deletes every
        //     booking AND every associated income/expense transaction.
        //   - On SQLite (test env): BOTH FKs exist with different constraint
        //     names (`hajj_umra_bookings_customer_id_foreign` CASCADE, and
        //     `customer_id_foreign` RESTRICT added later).
        //
        // A follow-up migration `2026_08_14_*_drop_duplicate_cascade_fks_on_hajj_umra_bookings.php`
        // drops the duplicate CASCADE FK on both databases so this invariant
        // is enforced everywhere.
        //
        // The invariant we assert: NO CASCADE FK on these financial columns.
        // (Stronger than "is RESTRICT" — catches both states: only-CASCADE on
        // MySQL, or dual-CASCADE+RESTRICT on SQLite.)
        $fks = $this->foreignKeys('hajj_umra_bookings');
        $customerFks = array_values(array_filter(
            $fks,
            fn ($f) => $f['from'] === 'customer_id'
        ));
        $this->assertNotEmpty($customerFks, 'FK customer_id missing');
        foreach ($customerFks as $fk) {
            $this->assertNotSame(
                'CASCADE',
                $fk['on_delete'],
                'HJ-004: hajj_umra_bookings.customer_id must NOT have a CASCADE FK — '.
                'financial history must be protected from cascading delete.'
            );
        }
    }

    public function test_bookings_fk_to_programs(): void
    {
        // HJ-004 — see test_bookings_fk_to_customers above for full context.
        // Same defect applies to program_id: a Program with bookings must not
        // be deletable in a way that cascades into financial history.
        $fks = $this->foreignKeys('hajj_umra_bookings');
        $programFks = array_values(array_filter(
            $fks,
            fn ($f) => $f['from'] === 'program_id'
        ));
        $this->assertNotEmpty($programFks, 'FK program_id missing');
        foreach ($programFks as $fk) {
            $this->assertNotSame(
                'CASCADE',
                $fk['on_delete'],
                'HJ-004: hajj_umra_bookings.program_id must NOT have a CASCADE FK — '.
                'financial history must be protected from cascading delete.'
            );
        }
    }

    public function test_bookings_fk_to_companion_customer_nullable_setnull(): void
    {
        $fk = $this->fkFromCol('hajj_umra_bookings', 'companion_customer_id');
        $this->assertNotNull($fk, 'FK companion_customer_id missing');
        $this->assertSame('SET NULL', $fk['on_delete']);
        // column nullable
        $cols = $this->columns('hajj_umra_bookings');
        $this->assertSame(0, $cols['companion_customer_id']['notnull']);
    }

    public function test_bookings_account_id_not_null_after_phase6(): void
    {
        $cols = $this->columns('hajj_umra_bookings');
        $this->assertSame(1, $cols['account_id']['notnull'], 'account_id should be NOT NULL after Phase 6 migration');
    }

    public function test_bookings_fk_to_umrah_suppliers(): void
    {
        $fk = $this->fkFromCol('hajj_umra_bookings', 'supplier_id');
        $this->assertNotNull($fk, 'FK supplier_id missing');
        $this->assertSame('umrah_suppliers', $fk['table']);
    }

    public function test_bookings_fk_to_transactions_expense_and_income(): void
    {
        $fks = $this->foreignKeys('hajj_umra_bookings');
        $cols = array_map(fn ($f) => $f['from'], $fks);
        $this->assertContains('expense_transaction_id', $cols);
        $this->assertContains('income_transaction_id', $cols);
    }

    /* =========================================================
     * FOREIGN KEYS — hajj_umra_payments
     * ========================================================= */

    public function test_payments_fk_to_bookings_cascade(): void
    {
        $fk = $this->fkFromCol('hajj_umra_payments', 'hajj_umra_booking_id');
        $this->assertNotNull($fk, 'FK hajj_umra_booking_id missing');
        $this->assertSame('hajj_umra_bookings', $fk['table']);
        $this->assertSame('CASCADE', $fk['on_delete']);
    }

    public function test_payments_fk_to_transactions(): void
    {
        $fk = $this->fkFromCol('hajj_umra_payments', 'transaction_id');
        $this->assertNotNull($fk, 'FK transaction_id missing on hajj_umra_payments');
        $this->assertSame('transactions', $fk['table']);
    }

    /* =========================================================
     * SOFT DELETES
     * ========================================================= */

    public function test_bookings_has_soft_deletes(): void
    {
        $this->assertColumnExists('hajj_umra_bookings', 'deleted_at');
    }

    public function test_payments_has_soft_deletes(): void
    {
        $this->assertColumnExists('hajj_umra_payments', 'deleted_at');
    }

    public function test_programs_has_soft_deletes(): void
    {
        $this->assertColumnExists('programs', 'deleted_at');
    }

    /* =========================================================
     * DECIMAL PRECISION
     * ========================================================= */

    public function test_bookings_decimal_precision_is_15_2(): void
    {
        // INTENTIONALLY accepts both DECIMAL and NUMERIC — see defect HJ-002.
        // SQLite has only 5 storage classes (NULL, INTEGER, REAL, TEXT, BLOB)
        // and applies type-affinity rules: $table->decimal(...) maps to the
        // NUMERIC storage class. On MySQL the same schema produces DECIMAL(15,2).
        // The migration declares $table->decimal('purchase_price', 15, 2) — the
        // intent (numeric precision 15, scale 2) is preserved either way.
        //
        // On SQLite the stored column type string is just 'numeric' — SQLite
        // does NOT preserve the (precision, scale) tuple in the type-affinity
        // metadata. Precision must therefore be verified via the migration file
        // (see docs/HAJJ_UMRAH_COVERAGE_MATRIX.md) rather than via PRAGMA on
        // SQLite. The numeric storage class itself guarantees real-number storage
        // with reasonable precision, so we only assert the affinity class here.
        $driver = DB::connection()->getDriverName();
        $cols = $this->columns('hajj_umra_bookings');
        foreach (['purchase_price', 'selling_price', 'profit'] as $c) {
            $type = strtoupper($cols[$c]['type']);
            $this->assertTrue(
                str_contains($type, 'DECIMAL') || str_contains($type, 'NUMERIC'),
                "{$c} should be DECIMAL or NUMERIC, got {$type}"
            );
            if ($driver === 'mysql') {
                // MySQL preserves precision in the type metadata.
                $this->assertStringContainsString('15', $cols[$c]['type'], "{$c} precision should be 15");
                $this->assertStringContainsString('2', $cols[$c]['type'], "{$c} scale should be 2");
            }
            // On SQLite the type is 'numeric' only; precision is enforced at the
            // application layer via Laravel's decimal() cast.
        }
    }

    public function test_payments_amount_decimal_precision(): void
    {
        // INTENTIONALLY accepts both DECIMAL and NUMERIC — see defect HJ-002.
        $driver = DB::connection()->getDriverName();
        $cols = $this->columns('hajj_umra_payments');
        $type = strtoupper($cols['amount']['type']);
        $this->assertTrue(
            str_contains($type, 'DECIMAL') || str_contains($type, 'NUMERIC'),
            "amount should be DECIMAL or NUMERIC, got {$type}"
        );
        if ($driver === 'mysql') {
            $this->assertStringContainsString('15', $cols['amount']['type']);
            $this->assertStringContainsString('2', $cols['amount']['type']);
        }
    }

    /* =========================================================
     * INDEXES
     * ========================================================= */

    public function test_bookings_has_status_index(): void
    {
        $found = false;
        foreach ($this->indexes('hajj_umra_bookings') as $idx) {
            if (in_array('status', $idx['columns'], true)) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Missing index on hajj_umra_bookings.status');
    }

    public function test_bookings_has_module_index(): void
    {
        $found = false;
        foreach ($this->indexes('hajj_umra_bookings') as $idx) {
            if (in_array('module', $idx['columns'], true)) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Missing index on hajj_umra_bookings.module');
    }

    public function test_payments_has_payment_method_index(): void
    {
        $found = false;
        foreach ($this->indexes('hajj_umra_payments') as $idx) {
            if (in_array('payment_method', $idx['columns'], true)) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /* =========================================================
     * STATUS COLUMN accepts every enum value
     * ========================================================= */

    public function test_booking_status_accepts_all_enum_values(): void
    {
        $user = $this->makeUser('db-status');
        $cashbox = $this->makeCashbox($user, 'EGP', 100000);
        $customer = $this->makeCustomer('CUST-001', '01000000088');
        $program = $this->makeProgram($user, 'umra', 10000, 8000);

        foreach (HajjUmraStatus::cases() as $case) {
            $booking = HajjUmraBooking::query()->create([
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'module' => 'HAJJ_UMRA',
                'selling_price' => 10000,
                'purchase_price' => 8000,
                'profit' => 2000,
                'currency' => 'EGP',
                'per_person' => true,
                'status' => $case->value,
                'agent_name' => 'audit',
                'account_id' => $cashbox->id,
                'created_by' => $user->id,
            ]);
            $this->assertDatabaseHas('hajj_umra_bookings', [
                'id' => $booking->id,
                'status' => $case->value,
            ]);
        }
    }

    /* =========================================================
     * CURRENCY FIELD accepts EGP / USD / SAR
     * ========================================================= */

    public function test_currency_accepts_egp_usd_sar(): void
    {
        $user = $this->makeUser('db-cur');
        $cashbox = $this->makeCashbox($user, 'EGP', 100000);
        $customer = $this->makeCustomer('CUST-CUR', '01000000099');
        $program = $this->makeProgram($user, 'umra', 5000, 4000);

        foreach (['EGP', 'USD', 'SAR'] as $cur) {
            $b = HajjUmraBooking::query()->create([
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'selling_price' => 5000,
                'purchase_price' => 4000,
                'profit' => 1000,
                'currency' => $cur,
                'per_person' => true,
                'status' => 'confirmed',
                'agent_name' => 'audit',
                'account_id' => $cashbox->id,
                'created_by' => $user->id,
            ]);
            $this->assertSame($cur, $b->fresh()->currency);
        }
    }

    /* =========================================================
     * NO ORPHAN passengers after update
     * ========================================================= */

    public function test_no_orphan_umrah_passengers(): void
    {
        $all = UmrahTransactionPassenger::query()
            ->whereNotNull('transaction_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hajj_umra_bookings')
                    ->whereColumn('hajj_umra_bookings.id', 'umrah_transaction_passengers.transaction_id');
            })
            ->count();
        $this->assertSame(0, $all, 'Found orphan umrah_transaction_passengers');
    }

    /* =========================================================
     * COMPOSITE UNIQUE — customers.phone + national_id
     * ========================================================= */

    public function test_customers_composite_unique_on_phone_national_id(): void
    {
        $found = false;
        foreach ($this->indexes('customers') as $idx) {
            if (in_array('phone', $idx['columns'], true) && in_array('national_id', $idx['columns'], true)) {
                $found = true;
                $this->assertSame(1, $idx['unique'], 'Composite (phone, national_id) must be UNIQUE');
            }
        }
        $this->assertTrue($found, 'Missing composite UNIQUE on customers(phone, national_id)');
    }

    /* =========================================================
     * EXECUTING COMPANY auto-creates supplier account
     * ========================================================= */

    public function test_executing_company_creation_auto_creates_supplier_account(): void
    {
        // The booted observer auto-creates a Supplier Account; the Account's
        // `created_by` requires a valid users.id FK.
        $user = $this->makeUser('exec-co');

        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'Test Co',
            'phone' => '01000000077',
            'is_active' => true,
        ]);
        $this->assertNotNull($company->account_id);
        $account = Account::query()->find($company->account_id);
        $this->assertNotNull($account);
        $this->assertSame('hajj_umra', $account->module_type);
    }

    /* =========================================================
     * UMRAH SUPPLIER auto-creates account via observer
     * ========================================================= */

    public function test_umrah_supplier_observer_auto_creates_account(): void
    {
        $user = $this->makeUser('sup-observer');
        $supplier = UmrahSupplier::query()->create([
            'name' => 'Test Supplier',
            'phone' => '01000000066',
            'default_cost_price' => 5000,
            'is_active' => true,
        ]);
        $supplier->refresh();
        $this->assertNotNull($supplier->account_id, 'UmrahSupplier observer should auto-create account');
        $account = Account::query()->find($supplier->account_id);
        $this->assertNotNull($account);
        $this->assertSame('hajj_umra', $account->module_type);
    }

    /* =========================================================
     * Helper factories
     * ========================================================= */

    private function makeUser(string $tag): User
    {
        return User::query()->create([
            'name' => 'DB Tester '.$tag,
            'email' => 'db-'.$tag.'-'.now()->timestamp.'-'.random_int(1000, 9999).'@test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function makeCashbox(User $user, string $currency, float $balance): Account
    {
        return Account::query()->create([
            'name' => 'Cashbox '.$currency,
            'type' => 'cashbox',
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'created_by' => $user->id,
        ]);
    }

    private function makeCustomer(string $name, string $phone): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => $phone,
        ]);
    }

    private function makeProgram(User $user, string $type, float $selling, float $purchase): Program
    {
        // FIX (defect HJ-003): helper originally omitted required NOT NULL fields.
        //
        // Final `programs` schema (after all migrations through 2026_07_28)
        // — NOT NULL columns without a DEFAULT and without auto-increment:
        //   1) program_name      (string, NOT NULL) — original migration
        //   2) program_type      (string, NOT NULL) — original migration
        //   3) total_nights      (integer, NOT NULL) — original migration
        //   4) mecca_hotel_name  (string, NOT NULL) — original migration
        //   5) mecca_nights      (integer, NOT NULL) — original migration
        //   6) departure_date    (date, NOT NULL) — original migration
        //   7) return_date       (date, NOT NULL) — original migration
        //   8) airline           (string, NOT NULL) — original migration
        //   9) departure_point   (string, NOT NULL) — original migration
        //  10) executing_company (string, NOT NULL in SQLite — see note)
        //
        // NOT NULL columns WITH a DEFAULT (handled automatically, not required):
        //   - booking_status    default 'PENDING'
        //   - is_active         default true   (added in 2026_05_06_080000)
        //
        // Nullable columns (not required):
        //   - season, accommodation_type (made nullable in 2026_05_06_075703),
        //     medina_hotel_name, medina_nights, trip_supervisor,
        //     program_price_tier, default_purchase_price, default_selling_price,
        //     executing_company_id, trip_supervisor_id, accommodation_type_id.
        //
        // Note on `executing_company`:
        //   The migration 2026_06_25_160000_make_programs_executing_company_nullable.php
        //   issues raw SQL `ALTER TABLE programs ALTER COLUMN executing_company
        //   DROP NOT NULL` which is **not supported by SQLite** (only MySQL's
        //   MODIFY branch actually changes the constraint). As a result, on the
        //   test environment (SQLite in-memory) the column remains NOT NULL even
        //   though the migration "completes" without throwing.
        //   The production MySQL database DOES have it as nullable.
        //   For tests we pass an `executing_company` string, which triggers
        //   Program::saving() observer to firstOrCreate a HajjUmraExecutingCompany
        //   — same pattern used by HajjUmraApiTest and HajjUmraControllerTest.
        //
        // Other notes:
        //   - `$selling`/`$purchase` parameters were never written to the schema —
        //     `programs` has `default_purchase_price` / `default_selling_price`
        //     (added later). The original helper silently dropped them via
        //     mass-assignment filtering. We do not invent the wrong columns here.
        //   - `created_by` is also not on the `programs` table (it's on the
        //     bookings table) — left in the call site only for symmetry with
        //     other tests; mass-assignment filter drops it harmlessly.
        return Program::query()->create([
            'program_name' => 'P-'.$type,
            'program_type' => $type,
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 3,
            'airline' => 'Test',
            'departure_point' => 'CAI',
            'accommodation_type' => 'QUAD',
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(17)->toDateString(),
            'executing_company' => 'شركة اختبار',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
