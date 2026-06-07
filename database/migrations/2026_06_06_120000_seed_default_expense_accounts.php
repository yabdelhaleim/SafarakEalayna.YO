<?php

use App\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('accounts')->where('type', AccountType::Expense->value)->exists()) {
            return;
        }

        $now = now();
        $adminId = DB::table('users')->orderBy('id')->value('id');

        $rows = [
            ['name' => 'رواتب', 'module_type' => 'general', 'module' => 'general', 'notes' => 'مصروفات الرواتب والأجور'],
            ['name' => 'إيجار', 'module_type' => 'general', 'module' => 'general', 'notes' => 'إيجار المكتب والفروع'],
            ['name' => 'تسويق', 'module_type' => 'general', 'module' => 'general', 'notes' => 'مصروفات التسويق والإعلان'],
            ['name' => 'كهرباء ومياه', 'module_type' => 'general', 'module' => 'general', 'notes' => 'فواتير المرافق'],
            ['name' => 'مصاريف طيران', 'module_type' => 'flights', 'module' => 'flight', 'notes' => 'مصروفات تشغيل قسم الطيران'],
            ['name' => 'مصاريف باصات', 'module_type' => 'bus', 'module' => 'bus', 'notes' => 'مصروفات تشغيل قسم الباصات'],
        ];

        foreach ($rows as $row) {
            DB::table('accounts')->insert([
                'name' => $row['name'],
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => $row['module_type'],
                'module' => $row['module'],
                'notes' => $row['notes'],
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally left empty — do not delete user-managed expense accounts.
    }
};
