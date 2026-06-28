<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $walletTypes = [
            [
                'name' => 'فودافون كاش',
                'code' => 'vodafone_cash',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'إنستاباي',
                'code' => 'instapay',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'أورنج كاش',
                'code' => 'orange_cash',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'اتصالات كاش',
                'code' => 'etisalat_cash',
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'WE Pay',
                'code' => 'we_pay',
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($walletTypes as $wt) {
            DB::table('wallet_types')->updateOrInsert(
                ['code' => $wt['code']],
                [
                    'name' => $wt['name'],
                    'is_active' => $wt['is_active'],
                    'sort_order' => $wt['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // No-op
    }
};
