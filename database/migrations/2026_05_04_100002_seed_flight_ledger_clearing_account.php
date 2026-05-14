<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'إقفال مبيعات الطيران (نظام)';

        if (DB::table('accounts')->where('name', $name)->exists()) {
            return;
        }

        $userId = DB::table('users')->orderBy('id')->value('id');

        DB::table('accounts')->insert([
            'name' => $name,
            'type' => 'treasury',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'notes' => 'حساب نظامي لمطابقة قيود مبيعات الطيران (يسمح بالرصيد السلبي عند التسجيل)',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('accounts')->where('name', 'إقفال مبيعات الطيران (نظام)')->delete();
    }
};
