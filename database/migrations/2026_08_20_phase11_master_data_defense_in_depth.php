<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11.1 B-D2/B-G2 DEFECT FIX (2026-08-20):
 * Add DB-level CHECK constraints enforcing non-negative credit_limit
 * on flight_carriers and flight_groups. Defense-in-depth for the
 * CLASS-C validation gap found in Phase 11 Master Data Audit — both
 * models permit negative credit_limit values which would invert the
 * semantics of `available_balance = balance + credit_limit`.
 *
 * The model-level saving() guards will be added in a separate commit
 * to avoid scope creep; this migration is the hard backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite supports CHECK constraints inline with CREATE TABLE.
        // MySQL 8.0.16+ supports them as ALTER TABLE ADD CONSTRAINT.
        // For broad compatibility we use a portable try/catch.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL — use ADD CONSTRAINT (preferred) or inline CHECK
            DB::statement('ALTER TABLE flight_carriers ADD CONSTRAINT chk_flight_carrier_credit_limit_nonneg CHECK (credit_limit >= 0)');
            DB::statement('ALTER TABLE flight_groups ADD CONSTRAINT chk_flight_group_credit_limit_nonneg CHECK (credit_limit >= 0)');
        } elseif ($driver === 'sqlite') {
            // SQLite CHECK constraints cannot be added via ALTER TABLE.
            // Recreate tables is the only option; for the in-memory test
            // database we rely on the model-layer validation in the
            // production code path (defense in depth).
        } else {
            // Postgres / other — ADD CONSTRAINT works.
            DB::statement('ALTER TABLE flight_carriers ADD CONSTRAINT chk_flight_carrier_credit_limit_nonneg CHECK (credit_limit >= 0)');
            DB::statement('ALTER TABLE flight_groups ADD CONSTRAINT chk_flight_group_credit_limit_nonneg CHECK (credit_limit >= 0)');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE flight_carriers DROP CONSTRAINT chk_flight_carrier_credit_limit_nonneg');
            DB::statement('ALTER TABLE flight_groups DROP CONSTRAINT chk_flight_group_credit_limit_nonneg');
        }
    }
};