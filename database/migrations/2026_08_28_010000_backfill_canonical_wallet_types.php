<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill canonical WalletProvider codes into the `wallet_types` table.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Background
 * ─────────────────────────────────────────────────────────────────────────
 * The original seed migration (`2026_06_29_000000_seed_default_wallet_types`)
 * was disabled on 2026-07-29 by user request so `migrate:fresh` produces an
 * EMPTY database. As a result, any environment that wasn't seeded manually
 * ended up with `wallet_types` missing the canonical codes that match the
 * `accounts.wallet_provider` enum values.
 *
 * Symptom: in `WalletCreate.vue`, `accountMatchesWalletType()` does strict
 * equality between `walletType.code` and `account.wallet_provider`. With
 * `wallet_types` empty, the warning "يوجد N محفظة مسجلة فعلاً، لكن نوعها
 * (…) لا يطابق النوع المختار" fires for every selected type — even though
 * the 4 wallets are perfectly valid liquidity accounts.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * This migration
 * ─────────────────────────────────────────────────────────────────────────
 * Idempotent. Re-runnable. Uses `insertOrIgnore` so already-present rows
 * are not touched (preserves any custom `name`/`sort_order` set by the
 * admin via the Filament WalletTypeResource).
 *
 * Adds the 10 canonical `WalletProvider` enum values (see
 * `App\Enums\WalletProvider`). The category-level mapping
 * (`code = cash_wallet`) is kept in `useTreasuryAccountGroups.js` on the
 * frontend — no need to duplicate it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['code' => 'vodafone_cash', 'name' => 'فودافون كاش',       'is_active' => true, 'sort_order' => 1],
            ['code' => 'instapay',      'name' => 'إنستاباي',          'is_active' => true, 'sort_order' => 2],
            ['code' => 'orange_cash',   'name' => 'أورانج كاش',         'is_active' => true, 'sort_order' => 3],
            ['code' => 'etisalat_cash', 'name' => 'اتصالات كاش',        'is_active' => true, 'sort_order' => 4],
            ['code' => 'we_pay',        'name' => 'WE Pay',             'is_active' => true, 'sort_order' => 5],
            ['code' => 'paymob',        'name' => 'Paymob / بوابة دفع', 'is_active' => true, 'sort_order' => 6],
            ['code' => 'cash_wallet',   'name' => 'محفظة كاش (عام)',    'is_active' => true, 'sort_order' => 7],
            ['code' => 'postal',        'name' => 'بريد / مصاري',       'is_active' => true, 'sort_order' => 8],
            ['code' => 'fawry',         'name' => 'فوري',               'is_active' => true, 'sort_order' => 9],
            ['code' => 'other',         'name' => 'أخرى',               'is_active' => true, 'sort_order' => 10],
        ];

        foreach ($rows as $row) {
            DB::table('wallet_types')->insertOrIgnore([
                'code'       => $row['code'],
                'name'       => $row['name'],
                'is_active'  => $row['is_active'],
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // لا نحذف في الـ down — هذه backfill منطقية، والـ admin ممكن يكون عدّل
        // الـ name يدوياً. التراجع يتم يدوياً لو لزم الأمر.
    }
};
