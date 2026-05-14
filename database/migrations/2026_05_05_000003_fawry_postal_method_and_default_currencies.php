<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fawry_payment_methods') && ! DB::table('fawry_payment_methods')->where('code', 'postal_transfer')->exists()) {
            DB::table('fawry_payment_methods')->insert([
                'code' => 'postal_transfer',
                'name_ar' => 'تحويل بريدي',
                'name_en' => 'Postal transfer',
                'color' => '#0EA5E9',
                'icon' => 'envelope',
                'description_ar' => 'استلام أو دفع عبر مكتب البريد',
                'description_en' => 'Postal office payment or receipt',
                'provider_name' => null,
                'account_number' => null,
                'phone_number' => null,
                'bank_name' => null,
                'branch_name' => null,
                'metadata' => null,
                'default_account_id' => null,
                'is_active' => true,
                'order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('fawry_currencies') || ! Schema::hasTable('currencies')) {
            return;
        }

        $currencyIds = DB::table('currencies')->where('is_active', true)->pluck('id');
        foreach ($currencyIds as $currencyId) {
            if (DB::table('fawry_currencies')->where('currency_id', $currencyId)->exists()) {
                continue;
            }
            $row = DB::table('currencies')->where('id', $currencyId)->first();
            if (! $row) {
                continue;
            }
            DB::table('fawry_currencies')->insert([
                'currency_id' => $currencyId,
                'exchange_rate' => $row->exchange_rate ?? 1.0,
                'min_amount' => null,
                'max_amount' => null,
                'fee_percent' => 0,
                'fixed_fee' => 0,
                'is_active' => true,
                'order' => (int) ($row->order ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fawry_payment_methods')) {
            DB::table('fawry_payment_methods')->where('code', 'postal_transfer')->delete();
        }
    }
};
