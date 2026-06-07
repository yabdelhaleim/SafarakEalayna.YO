<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * حسابات دفترية داخلية للـ seed فقط (module_type = office) — لا تظهر في «خزينة الطيران» (سياحة).
 * خزينة السياحة في الواجهة تعرض فقط الحسابات التي تُضاف بـ module_type = tourism.
 */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $now = now();

        $accounts = [
            [
                'name' => 'Main Cashbox',
                'type' => AccountType::Cashbox->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Flight Cashbox',
                'type' => AccountType::Cashbox->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Bus Cashbox',
                'type' => AccountType::Cashbox->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'InstaPay Wallet',
                'type' => AccountType::Wallet->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'wallet_provider' => WalletProvider::Instapay->value,
                'wallet_number' => '01000000001',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Vodafone Cash',
                'type' => AccountType::Wallet->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'wallet_provider' => WalletProvider::VodafoneCash->value,
                'wallet_number' => '01000000002',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Main Bank Account',
                'type' => AccountType::Bank->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'office',
                'notes' => null,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Expense categories (بنود المصروفات) — تظهر في Vue /finance/expenses
            [
                'name' => 'رواتب',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'general',
                'module' => 'general',
                'notes' => 'مصروفات الرواتب والأجور',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'إيجار',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'general',
                'module' => 'general',
                'notes' => 'إيجار المكتب والفروع',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'تسويق',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'general',
                'module' => 'general',
                'notes' => 'مصروفات التسويق والإعلان',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'كهرباء ومياه',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'general',
                'module' => 'general',
                'notes' => 'فواتير المرافق',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'مصاريف طيران',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'flights',
                'module' => 'flight',
                'notes' => 'مصروفات تشغيل قسم الطيران',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'مصاريف باصات',
                'type' => AccountType::Expense->value,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'module_type' => 'bus',
                'module' => 'bus',
                'notes' => 'مصروفات تشغيل قسم الباصات',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($accounts as $account) {
            DB::table('accounts')->insert($account);
        }

        $accounts = DB::table('accounts')->get();
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[str_replace(' ', '_', strtolower($account->name))] = $account->id;
        }
        cache(['seed_account_map' => $accountMap], now()->addHour());

        $this->command->info('✅ AccountSeeder: '.count($accounts).' حسابات داخلية (office) للـ seed — خزينة السياحة تبدأ فارغة حتى تضيف حسابات tourism.');
    }
}
