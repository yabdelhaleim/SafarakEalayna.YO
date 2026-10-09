<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔁 Reapply the default `payment_methods` seed on environments where the
 *    ORIGINAL seed migration (`2026_07_27_122817_seed_default_payment_methods`)
 *    was recorded as "already run" at batch=1 but its `up()` body was a no-op
 *    at the time (commit `ec158ab fix(finance)` briefly disabled the insert).
 *
 *    Laravel's migration tracker does not re-execute a migration just because
 *    its source code changed — the migration that was in the `migrations`
 *    table at version-init time runs once and is then considered done.
 *
 *    Symptom on staging/production:
 *      `\App\Models\Setting\PaymentMethod::count()` returns 0
 *      even though the migration is recorded.
 *
 *    This migration is a new row in `migrations` (its own batch) and uses
 *    `updateOrInsert` against `payment_methods.code` so it is fully
 *    idempotent: safe to run on environments where the rows already exist.
 *
 * Side note: after this migration, an additional follow-up change in the
 * Online module's Create page makes `payment_method` a free-text input
 * (mirrors Fawry's `operation_type`), so even an empty `payment_methods`
 * table no longer blocks the workflow — but other modules (Fawry, Bus,
 * Hajj, Wallet, Visa) still rely on the seeded rows for their dropdowns,
 * so this migration is required regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        $methods = [
            ['code' => 'cash',            'name_ar' => 'نقدي',           'name_en' => 'Cash',            'color' => '#10B981', 'is_active' => 1, 'order' => 1],
            ['code' => 'bank_transfer',   'name_ar' => 'تحويل بنكي',     'name_en' => 'Bank Transfer',   'color' => '#3B82F6', 'is_active' => 1, 'order' => 2],
            ['code' => 'cash_wallet',     'name_ar' => 'محفظة كاش',      'name_en' => 'Cash Wallet',     'color' => '#F59E0B', 'is_active' => 1, 'order' => 3],
            ['code' => 'vodafone_cash',   'name_ar' => 'فودافون كاش',    'name_en' => 'Vodafone Cash',   'color' => '#EF4444', 'is_active' => 1, 'order' => 4],
            ['code' => 'instapay',        'name_ar' => 'إنستاباي',       'name_en' => 'InstaPay',        'color' => '#8B5CF6', 'is_active' => 1, 'order' => 5],
            ['code' => 'credit_card',     'name_ar' => 'بطاقة ائتمان',   'name_en' => 'Credit Card',     'color' => '#0EA5E9', 'is_active' => 1, 'order' => 6],
            ['code' => 'postal_transfer', 'name_ar' => 'حوالة بريدية',   'name_en' => 'Postal Transfer', 'color' => '#6366F1', 'is_active' => 1, 'order' => 7],
            ['code' => 'office_safe',     'name_ar' => 'خزينة المكتب',   'name_en' => 'Office Safe',     'color' => '#06B6D4', 'is_active' => 1, 'order' => 8],
            ['code' => 'debit_card',      'name_ar' => 'بطاقة خصم',      'name_en' => 'Debit Card',      'color' => '#0284C7', 'is_active' => 1, 'order' => 9],
            ['code' => 'mobile_wallet',   'name_ar' => 'محفظة موبايل',   'name_en' => 'Mobile Wallet',   'color' => '#7C3AED', 'is_active' => 1, 'order' => 10],
        ];

        $inserted = 0;
        foreach ($methods as $method) {
            $before = (int) DB::table('payment_methods')->where('code', $method['code'])->count();
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $method['code']],
                array_merge($method, ['created_at' => $now, 'updated_at' => $now])
            );
            $after = (int) DB::table('payment_methods')->where('code', $method['code'])->count();
            if ($before === 0 && $after === 1) {
                $inserted++;
            }
        }

        // Optional audit line — useful in production logs to confirm how many
        // rows were missing before this migration ran.
        \Illuminate\Support\Facades\Log::info('reapply_payment_methods_seed: complete', [
            'inserted' => $inserted,
            'total_after' => DB::table('payment_methods')->count(),
        ]);
    }

    public function down(): void
    {
        // No-op: these are operational reference rows. Rolling them back is
        // risky (Online / Fawry / Bus / Hajj / Wallet / Visa dropdowns all
        // use them) and there is no recovery path short of running this
        // migration forward again.
    }
};
