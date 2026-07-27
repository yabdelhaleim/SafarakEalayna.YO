<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent migration: seeds baseline cashbox + bank + wallet accounts
 * for the Online Services module (module_type = 'office', per the
 * AccountModuleContract — online is a sub-module of the Office division).
 *
 * ONLY inserts if an account with the same name does not already exist
 * in the online/office division, so running this on production with
 * existing data is 100% safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        $accounts = [
            // ── Cash / Cashbox ────────────────────────────────────────────
            [
                'name'         => 'خزينة الخدمات الإلكترونية',
                'type'         => 'cashbox',
                'module_type'  => 'office',
                'is_active'    => 1,
                'balance'      => 0,
                'currency'     => 'EGP',
            ],
            // ── Bank ─────────────────────────────────────────────────────
            [
                'name'         => 'حساب بنكي - الخدمات الإلكترونية',
                'type'         => 'bank',
                'module_type'  => 'office',
                'is_active'    => 1,
                'balance'      => 0,
                'currency'     => 'EGP',
            ],
            // ── Wallet ────────────────────────────────────────────────────
            [
                'name'         => 'محفظة إلكترونية - الخدمات الإلكترونية',
                'type'         => 'wallet',
                'module_type'  => 'office',
                'is_active'    => 1,
                'balance'      => 0,
                'currency'     => 'EGP',
            ],
        ];

        foreach ($accounts as $account) {
            // Only create if no account with this exact name exists
            $exists = DB::table('accounts')
                ->where('name', $account['name'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                DB::table('accounts')->insert(array_merge(
                    $account,
                    ['created_at' => $now, 'updated_at' => $now]
                ));
            }
        }
    }

    public function down(): void
    {
        // Intentionally left empty — do not delete financial accounts on rollback.
    }
};
