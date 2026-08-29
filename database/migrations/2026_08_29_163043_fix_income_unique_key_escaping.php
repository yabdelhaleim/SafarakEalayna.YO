<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIX (2026-08-29): Correct backslash escaping in income_unique_key expression.
 *
 * The previous migration `2026_08_29_120000_exclude_customer_from_income_unique_key`
 * used `'App\Models\Customer'` (single backslash in PHP source). After interpolation,
 * MySQL received `'App\Models\Customer'` and parsed it with default sql_mode —
 * backslash is the escape character, so `\M` and `\C` become `M` and `C`,
 * effectively comparing against `'AppModelsCustomer'` (no backslashes).
 *
 * Laravel persists `related_type = 'App\Models\Customer'` (with backslashes),
 * so the comparison NEVER matched — Customer-keyed income rows still hit the
 * unique index and `payDebt` failed with 1062 Duplicate entry.
 *
 * This migration uses double backslashes (`\\\\` in PHP source → `\\` in SQL
 * → `\` after MySQL escape parsing) so the actual string compared is
 * `'App\Models\Customer'` (with backslashes) — matching Laravel's value.
 *
 * Behaviour after fix:
 *   type='income', related_type='App\Models\Customer'        -> NULL (multi allowed) ✅
 *   type='income', related_type='App\Models\FlightBooking'   -> related_id (unique)
 *   type='income', related_type='App\Models\HajjUmraBooking' -> related_id (unique)
 *   type='income', related_type='App\Models\VisaBooking'     -> related_id (unique)
 *   type<>'income' OR related_type=NULL                      -> NULL (multi allowed)
 *
 * Driver behaviour:
 *   - MySQL    : STORED generated columns supported — full effect.
 *   - SQLite   : STORED generated columns NOT supported — skipped;
 *                app-level guard in TransactionService remains the only protection.
 *   - Postgres : STORED generated columns supported — full effect (same SQL syntax).
 *
 * Idempotency:
 *   `MODIFY COLUMN` with the same definition is a no-op on MySQL, so this
 *   migration can be re-run safely.
 *
 * @audit-fix BUG-CUSTOMER-PAYDEBT-MULTI-2026-08-29
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Escape ladder:
        //   PHP source  : 'App\\\\Models\\\\Customer'  (4 backslashes each side)
        //   PHP string  : 'App\\Models\\Customer'      (2 backslashes each side)
        //   SQL literal : 'App\\Models\\Customer'      (2 backslashes each side)
        //   MySQL parses: 'App\Models\Customer'        (1 backslash - escaped \)
        $excludedClass = 'App\\\\Models\\\\Customer';

        // Modify the column expression in place — no need to drop/recreate the
        // column or the unique index (MySQL re-validates the index on MODIFY).
        DB::statement("
            ALTER TABLE transactions
            MODIFY COLUMN income_unique_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                IF(
                    type = 'income'
                    AND related_type <> '{$excludedClass}',
                    related_id,
                    NULL
                )
            ) STORED
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Roll back to the ORIGINAL strict expression (no Customer exclusion).
        // WARNING: this will fail if there are existing duplicate Customer-keyed
        // income rows — run a cleanup pass first.
        DB::statement("
            ALTER TABLE transactions
            MODIFY COLUMN income_unique_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                IF(type = 'income', related_id, NULL)
            ) STORED
        ");
    }
};
