<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX (2026-08-12): Prevent duplicate income transactions on the same related entity.
 *
 * Each booking (or any morph entity) can have AT MOST ONE income transaction — the
 * sale. Any subsequent collection must be a Transfer (cash → AR), not a new Income.
 *
 * MySQL doesn't support partial unique indexes directly, so we use a generated
 * stored column that's NULL when type != 'income' and equal to related_id when
 * type='income'. The unique index is on (related_type, income_unique_key).
 *
 * Combined with the service-level guard in TransactionService::recordJournalTransfer,
 * this defends the invariant at both the application and database layers.
 *
 * IMPORTANT: existing duplicates (46 records — see dryrun_dup_bus_income.php)
 * must be cleaned BEFORE this migration runs, otherwise the unique index creation
 * will fail. The cleanup script is scripts/fix_dup_bus_income.php.
 */
return new class extends Migration {
    public function up(): void
    {
        // Step 1: add the generated column
        // IFNULL returns NULL when type != 'income', preventing duplicates from
        // non-income rows. MySQL allows multiple NULLs in a unique index.
        DB::statement("
            ALTER TABLE transactions
            ADD COLUMN income_unique_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                IF(type = 'income', related_id, NULL)
            ) STORED
        ");

        // Step 2: add the unique index on (related_type, income_unique_key)
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(
                ['related_type', 'income_unique_key'],
                'transactions_income_unique_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_income_unique_key_unique');
        });

        DB::statement('ALTER TABLE transactions DROP COLUMN income_unique_key');
    }
};
