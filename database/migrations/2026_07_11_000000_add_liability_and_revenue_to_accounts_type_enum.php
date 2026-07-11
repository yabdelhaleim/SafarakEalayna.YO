<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'liability' and 'revenue' to accounts.type enum.
 *
 * Background:
 *   `ProcessTicketModificationAccounting` listener uses
 *   `Account::firstOrCreate([...], ['type' => 'liability'])` and
 *   `Account::firstOrCreate([...], ['type' => 'revenue'])`.
 *
 *   The migration `2026_07_09_010000_add_expense_to_accounts_type_enum.php`
 *   removed 'treasury' and 'post' from the enum but did not add 'liability'
 *   or 'revenue' even though both are present in the PHP `AccountType` enum.
 *
 *   Result: in production (outside the unit-test bypass) the listener's
 *   firstOrCreate throws a SQLSTATE "Data truncated for column 'type'"
 *   error — meaning TicketModification confirm flow silently fails for
 *   any account that doesn't already exist.
 *
 * Resolution:
 *   Re-add 'liability' and 'revenue' to the DB enum so the listener can
 *   create its payables/revenue accounts. PHP `AccountType` enum already
 *   has both cases — no PHP-side change needed for `Revenue`, but
 *   `Liability` is added in a separate file edit.
 *
 * Idempotent: re-running is a no-op (the new ENUM definition already
 * includes both values after the first run).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('bank','cashbox','customer','owner','supplier','wallet','expense','liability','revenue')
            NOT NULL
        ");
    }

    public function down(): void
    {
        // Note: rollback will fail if any account rows currently have type='liability' or 'revenue'.
        // Production-safe rollback requires manual cleanup first:
        //     UPDATE accounts SET type='expense' WHERE type IN ('liability', 'revenue');
        DB::statement("
            ALTER TABLE accounts
            MODIFY COLUMN type
            ENUM('bank','cashbox','customer','owner','supplier','wallet','expense')
            NOT NULL
        ");
    }
};