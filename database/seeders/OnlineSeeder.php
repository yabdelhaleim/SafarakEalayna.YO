<?php

namespace Database\Seeders;

use App\Enums\OnlineTransactionStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OnlineSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $employeeIds = cache('seed_employee_ids') ?? range(1, 10);
        $accountMap = cache('seed_account_map');
        $instapayWalletId = $accountMap['instapay_wallet'] ?? 4;
        $vodafoneCashId = $accountMap['vodafone_cash'] ?? 5;

        $customerIds = DB::table('customers')->pluck('id')->toArray();
        $twelveMonthsAgo = now()->subMonths(12);

        // Step 1: Create 4 online service types
        $serviceTypes = [
            [
                'code' => 'fawry',
                'name_ar' => 'فوري',
                'name_en' => 'Fawry',
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'instapay',
                'name_ar' => 'إنستاباي',
                'name_en' => 'InstaPay',
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'vodafone_cash',
                'name_ar' => 'فودافون كاش',
                'name_en' => 'Vodafone Cash',
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'bill_payment',
                'name_ar' => 'دفع الفواتير',
                'name_en' => 'Bill Payment',
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($serviceTypes as $type) {
            DB::table('online_service_types')->updateOrInsert(['code' => $type['code']], $type);
        }
        $serviceTypeIds = DB::table('online_service_types')->pluck('id')->toArray();
        $providerIds = DB::table('online_service_providers')->pluck('id')->toArray();
        $customers = DB::table('customers')->get()->toArray();

        // Step 2: Create 500 online transactions
        $transactions = [];
        $onlineTransactions = [];
        $transactionIdCounter = DB::table('transactions')->max('id') ?? 0;

        for ($i = 1; $i <= 500; $i++) {
            $typeId = $serviceTypeIds[array_rand($serviceTypeIds)];
            $serviceType = DB::table('online_service_types')->where('id', $typeId)->first();
            $providerId = $providerIds[array_rand($providerIds)];

            $customerObj = $customers[array_rand($customers)];
            $customerId = $customerObj->id;
            $customerName = $customerObj->full_name;
            $customerPhone = $customerObj->phone;

            $employeeId = $employeeIds[array_rand($employeeIds)];
            $createdBy = $employeeIds[array_rand($employeeIds)];
            $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

            // Determine status
            $statusRoll = rand(1, 20);
            if ($statusRoll <= 18) {
                $status = OnlineTransactionStatus::Completed; // 90%
            } elseif ($statusRoll <= 19) {
                $status = OnlineTransactionStatus::Failed; // 5%
            } else {
                $status = OnlineTransactionStatus::Pending; // 5%
            }

            $amount = rand(5000, 500000) / 100; // 50-5000

            // Compute random fee
            $isFixed = rand(0, 1);
            if ($isFixed) {
                $fee = rand(2, 10);
            } else {
                $fee = round($amount * (rand(1, 3) / 100), 2);
            }

            $totalCollected = $amount + $fee;

            // Alternate between wallets
            $walletAccountId = ($i % 2 === 0) ? $instapayWalletId : $vodafoneCashId;

            $onlineTransactions[] = [
                'id' => $i,
                'service_type_id' => $typeId,
                'provider_id' => $providerId,
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_country' => 'Egypt',
                'employee_id' => $employeeId,
                'purchase_price' => $amount,
                'selling_price' => $totalCollected,
                'profit' => $fee,
                'payment_method' => 'wallet',
                'account_id' => $walletAccountId,
                'reference_number' => 'REF-' . rand(100000, 999999),
                'expense_transaction_id' => null,
                'income_transaction_id' => null,
                'status' => $status->value,
                'failure_reason' => $status === OnlineTransactionStatus::Failed ? 'Seeded failure' : null,
                'notes' => 'Seeded transaction',
                'created_by' => $createdBy,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === OnlineTransactionStatus::Completed) {
                // Step A: Expense transaction (office pays out amount)
                $expenseTransactionId = ++$transactionIdCounter;

                $transactions[] = [
                    'id' => $expenseTransactionId,
                    'type' => TransactionType::Expense->value,
                    'amount' => $amount,
                    'module' => TransactionModule::Online->value,
                    'related_type' => 'App\Models\Online\OnlineTransaction',
                    'related_id' => $i,
                    'from_account_id' => $walletAccountId,
                    'to_account_id' => null,
                    'created_by' => $createdBy,
                    'notes' => "Online operation payout: " . ($serviceType->name_ar ?? 'Service'),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                // Step B: Income transaction (office collects total from customer)
                $incomeTransactionId = ++$transactionIdCounter;

                $transactions[] = [
                    'id' => $incomeTransactionId,
                    'type' => TransactionType::Income->value,
                    'amount' => $totalCollected,
                    'module' => TransactionModule::Online->value,
                    'related_type' => 'App\Models\Online\OnlineTransaction',
                    'related_id' => $i,
                    'from_account_id' => null,
                    'to_account_id' => $walletAccountId,
                    'created_by' => $createdBy,
                    'notes' => "Online operation collection: " . ($serviceType->name_ar ?? 'Service'),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                // Update online transaction with IDs
                $onlineTransactions[count($onlineTransactions) - 1]['expense_transaction_id'] = $expenseTransactionId;
                $onlineTransactions[count($onlineTransactions) - 1]['income_transaction_id'] = $incomeTransactionId;
            }
        }

        // Insert all transactions and update wallet balances
        DB::transaction(function () use ($onlineTransactions, $transactions) {
            if (!empty($transactions)) {
                foreach ($transactions as $transaction) {
                    DB::table('transactions')->updateOrInsert(['id' => $transaction['id']], $transaction);
                }
            }

            foreach ($onlineTransactions as $ot) {
                DB::table('online_transactions')->updateOrInsert(['id' => $ot['id']], $ot);
            }

                // Group by account and calculate net balance changes
                $accountChanges = [];

                foreach ($transactions as $transaction) {
                    $accountId = $transaction['to_account_id'] ?? $transaction['from_account_id'];

                    if (!isset($accountChanges[$accountId])) {
                        $accountChanges[$accountId] = [
                            'debit' => 0,
                            'credit' => 0,
                            'entries' => [],
                        ];
                    }

                    if ($transaction['type'] === TransactionType::Expense->value) {
                        $accountChanges[$accountId]['debit'] += $transaction['amount'];
                        $accountChanges[$accountId]['entries'][] = [
                            'account_id' => $accountId,
                            'transaction_id' => $transaction['id'],
                            'debit' => $transaction['amount'],
                            'credit' => 0.00,
                            'created_at' => $transaction['created_at'],
                            'updated_at' => $transaction['updated_at'],
                        ];
                    } else {
                        $accountChanges[$accountId]['credit'] += $transaction['amount'];
                        $accountChanges[$accountId]['entries'][] = [
                            'account_id' => $accountId,
                            'transaction_id' => $transaction['id'],
                            'debit' => 0.00,
                            'credit' => $transaction['amount'],
                            'created_at' => $transaction['created_at'],
                            'updated_at' => $transaction['updated_at'],
                        ];
                    }
                }

                // Update account balances and create entries
                foreach ($accountChanges as $accountId => $changes) {
                    $currentBalance = DB::table('accounts')->where('id', $accountId)->value('balance');

                    foreach ($changes['entries'] as &$entry) {
                        $currentBalance += ($entry['credit'] - $entry['debit']);
                        $entry['balance_after'] = $currentBalance;
                    }
                    unset($entry);

                    foreach ($changes['entries'] as $entry) {
                        DB::table('account_entries')->updateOrInsert([
                            'account_id' => $entry['account_id'],
                            'transaction_id' => $entry['transaction_id'],
                        ], $entry);
                    }
                    DB::table('accounts')->where('id', $accountId)->update(['balance' => $currentBalance]);
                }
        });

        $completedCount = count(array_filter($onlineTransactions, fn($t) => $t['status'] === OnlineTransactionStatus::Completed->value));

        $this->command->info("✅ OnlineSeeder: 4 service types + 500 online transactions ({$completedCount} completed)");
    }
}
