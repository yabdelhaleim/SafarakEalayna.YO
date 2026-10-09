<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINDING FIN-1 (HIGH) REMEDIATION (2026-08-21):
 * Add an `is_opening` BOOLEAN flag to `account_entries`. When an Account
 * is created with `balance > 0`, the Account::created boot hook (see
 * app/Models/Account.php) auto-creates a paired opening-balance
 * AccountEntry. The `is_opening` flag marks that entry so reconciliation
 * queries can recognize it as a founding seed (not a normal transaction).
 *
 * Without this flag, the project's invariant
 *   `Account.balance = SUM(credit) − SUM(debit)` on `account_entries`
 * was mathematically unsatisfiable for any account created with a
 * non-zero opening balance (the missing opening entry left a phantom
 * delta equal to the opening balance in every reconciliation).
 *
 * Reversible: down() drops the column. The existing entries are
 * unaffected (default 0 = not opening).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_entries', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('notes');
            $table->index(['account_id', 'is_opening']);
        });
    }

    public function down(): void
    {
        Schema::table('account_entries', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'is_opening']);
            $table->dropColumn('is_opening');
        });
    }
};