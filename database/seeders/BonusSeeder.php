<?php

namespace Database\Seeders;

use App\Enums\BonusType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BonusSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $employeeIds = cache('seed_employee_ids') ?? range(1, 10);
        $accountMap = cache('seed_account_map');
        $mainCashboxId = $accountMap['main_cashbox'] ?? 1;

        $twelveMonthsAgo = now()->subMonths(12);

        $bonusReasons = [
            'Excellent performance',
            'Customer satisfaction award',
            'Sales target exceeded',
            'Employee of the month',
            'Perfect attendance',
            'Outstanding contribution',
        ];

        $deductionReasons = [
            'Late arrival',
            'Absenteeism',
            'Policy violation',
            'Equipment damage',
            'Customer complaint',
            'Documentation error',
        ];

        $bonuses = [];
        $transactions = [];
        $accountEntries = [];

        foreach ($employeeIds as $employeeId) {
            $employee = DB::table('employees')->where('id', $employeeId)->first();

            // Create 3-6 bonus records
            $bonusCount = rand(3, 6);
            for ($i = 0; $i < $bonusCount; $i++) {
                $amount = rand(20000, 100000) / 100; // 200-1000
                $reason = $bonusReasons[array_rand($bonusReasons)];
                $createdBy = $adminId;
                $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

                $transactionId = count($transactions) + 1;

                $transactions[] = [
                    'id' => $transactionId,
                    'type' => TransactionType::Expense->value,
                    'amount' => $amount,
                    'module' => TransactionModule::General->value,
                    'related_type' => 'App\Models\Employee\EmployeeBonus',
                    'related_id' => null, // Will update after insert
                    'from_account_id' => $mainCashboxId,
                    'to_account_id' => null,
                    'created_by' => $createdBy,
                    'notes' => "Bonus for employee ID: {$employeeId} | Reason: {$reason}",
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $accountEntries[] = [
                    'account_id' => $mainCashboxId,
                    'transaction_id' => $transactionId,
                    'debit' => $amount,
                    'credit' => 0.00,
                    'balance_after' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $bonuses[] = [
                    'employee_id' => $employeeId,
                    'type' => BonusType::Bonus->value,
                    'amount' => $amount,
                    'reason' => $reason,
                    'account_id' => $mainCashboxId,
                    'transaction_id' => $transactionId,
                    'created_by' => $createdBy,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            // Create 1-3 deduction records
            $deductionCount = rand(1, 3);
            for ($i = 0; $i < $deductionCount; $i++) {
                $amount = rand(10000, 50000) / 100; // 100-500
                $reason = $deductionReasons[array_rand($deductionReasons)];
                $createdBy = $adminId;
                $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

                $transactionId = count($transactions) + 1;

                $transactions[] = [
                    'id' => $transactionId,
                    'type' => TransactionType::Income->value,
                    'amount' => $amount,
                    'module' => TransactionModule::General->value,
                    'related_type' => 'App\Models\Employee\EmployeeBonus',
                    'related_id' => null,
                    'from_account_id' => null,
                    'to_account_id' => $mainCashboxId,
                    'created_by' => $createdBy,
                    'notes' => "Deduction from employee ID: {$employeeId} | Reason: {$reason}",
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $accountEntries[] = [
                    'account_id' => $mainCashboxId,
                    'transaction_id' => $transactionId,
                    'debit' => 0.00,
                    'credit' => $amount,
                    'balance_after' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $bonuses[] = [
                    'employee_id' => $employeeId,
                    'type' => BonusType::Deduction->value,
                    'amount' => $amount,
                    'reason' => $reason,
                    'account_id' => $mainCashboxId,
                    'transaction_id' => $transactionId,
                    'created_by' => $createdBy,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        }

        // Insert all data
        DB::transaction(function () use ($bonuses, $transactions, $accountEntries, $mainCashboxId) {
            // Insert bonuses
            DB::table('employee_bonuses')->insert($bonuses);

            // Update transaction related_ids
            foreach ($bonuses as $index => $bonus) {
                $transactionId = $bonus['transaction_id'];
                $bonusId = $index + 1;

                DB::table('transactions')
                    ->where('id', $transactionId)
                    ->update(['related_id' => $bonusId]);
            }

            // Insert transactions
            DB::table('transactions')->insert($transactions);

            // Insert account_entries and update account balance
            $currentBalance = DB::table('accounts')->where('id', $mainCashboxId)->value('balance');

            foreach ($accountEntries as &$entry) {
                $currentBalance += ($entry['credit'] - $entry['debit']);
                $entry['balance_after'] = $currentBalance;
            }
            unset($entry);

            DB::table('account_entries')->insert($accountEntries);
            DB::table('accounts')->where('id', $mainCashboxId)->update(['balance' => $currentBalance]);
        });

        $bonusCount = count(array_filter($bonuses, fn($b) => $b['type'] === BonusType::Bonus->value));
        $deductionCount = count(array_filter($bonuses, fn($b) => $b['type'] === BonusType::Deduction->value));

        $this->command->info("✅ BonusSeeder: {$bonusCount} bonuses + {$deductionCount} deductions across 10 employees");
    }
}
