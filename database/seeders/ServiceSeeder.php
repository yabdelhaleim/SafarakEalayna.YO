<?php

namespace Database\Seeders;

use App\Enums\ServiceCategory;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $employeeIds = cache('seed_employee_ids') ?? range(1, 10);
        $accountMap = cache('seed_account_map');
        $mainCashboxId = $accountMap['main_cashbox'] ?? 1;

        $twelveMonthsAgo = now()->subMonths(12);

        // Step 1: Create 15 services
        $services = [
            // Hajj (3)
            ['name' => 'Hajj Package - Economy', 'category' => 'hajj', 'cost' => 120000, 'selling' => 150000],
            ['name' => 'Hajj Package - Business', 'category' => 'hajj', 'cost' => 180000, 'selling' => 220000],
            ['name' => 'Hajj Package - VIP', 'category' => 'hajj', 'cost' => 200000, 'selling' => 250000],

            // Umrah (4)
            ['name' => 'Umrah Package - Basic', 'category' => 'umrah', 'cost' => 60000, 'selling' => 80000],
            ['name' => 'Umrah Package - Standard', 'category' => 'umrah', 'cost' => 90000, 'selling' => 120000],
            ['name' => 'Umrah Package - Premium', 'category' => 'umrah', 'cost' => 110000, 'selling' => 150000],
            ['name' => 'Umrah Package - Luxury', 'category' => 'umrah', 'cost' => 120000, 'selling' => 150000],

            // Visa (3)
            ['name' => 'Schengen Visa', 'category' => 'visa', 'cost' => 3000, 'selling' => 5000],
            ['name' => 'UK Visa', 'category' => 'visa', 'cost' => 10000, 'selling' => 15000],
            ['name' => 'USA Visa', 'category' => 'visa', 'cost' => 15000, 'selling' => 20000],

            // Passport (3)
            ['name' => 'Passport Renewal', 'category' => 'passport', 'cost' => 2000, 'selling' => 3000],
            ['name' => 'Passport New', 'category' => 'passport', 'cost' => 2500, 'selling' => 4000],
            ['name' => 'Passport Express', 'category' => 'passport', 'cost' => 4000, 'selling' => 8000],

            // Other (2)
            ['name' => 'Travel Insurance', 'category' => 'other', 'cost' => 1500, 'selling' => 20000],
            ['name' => 'Hotel Booking', 'category' => 'other', 'cost' => 40000, 'selling' => 50000],
        ];

        $serviceData = [];
        foreach ($services as $service) {
            $serviceData[] = [
                'name' => $service['name'],
                'category' => $service['category'],
                'description' => 'Seeded service',
                'cost_price' => $service['cost'],
                'selling_price' => $service['selling'],
                'is_active' => true,
                'notes' => 'Seeded service',
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('services')->insert($serviceData);
        $serviceIds = DB::table('services')->pluck('id', 'id')->toArray();
        $customerIds = DB::table('customers')->pluck('id')->toArray();

        // Step 2: Create 150 orders
        $orders = [];
        for ($i = 1; $i <= 150; $i++) {
            $serviceId = $serviceIds[array_rand($serviceIds)];
            $service = DB::table('services')->where('id', $serviceId)->first();

            $customerId = $customerIds[array_rand($customerIds)];
            $employeeId = $employeeIds[array_rand($employeeIds)];
            $createdBy = $employeeIds[array_rand($employeeIds)];
            $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

            // Determine status
            $statusRoll = rand(1, 10);
            if ($statusRoll <= 4) {
                $status = ServiceOrderStatus::Completed; // 40%
            } elseif ($statusRoll <= 7) {
                $status = ServiceOrderStatus::InProgress; // 30%
            } elseif ($statusRoll <= 9) {
                $status = ServiceOrderStatus::Pending; // 20%
            } else {
                $status = ServiceOrderStatus::Cancelled; // 10%
            }

            $sellingPrice = $service->selling_price;
            $costPrice = $service->cost_price;
            $profit = $sellingPrice - $costPrice;

            $orders[] = [
                'id' => $i,
                'service_id' => $serviceId,
                'customer_id' => $customerId,
                'employee_id' => $employeeId,
                'selling_price' => $sellingPrice,
                'cost_price' => $costPrice,
                'profit' => $profit,
                'status' => $status->value,
                'notes' => 'Seeded order',
                'created_by' => $createdBy,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('service_orders')->insert($orders);

        // Step 3: Create payments for completed + in_progress orders
        $payments = [];
        $transactions = [];
        $accountEntries = [];
        $transactionIdCounter = DB::table('transactions')->max('id') ?? 0;


        $completedOrders = array_filter($orders, fn($o) => $o['status'] === ServiceOrderStatus::Completed->value);
        $inProgressOrders = array_filter($orders, fn($o) => $o['status'] === ServiceOrderStatus::InProgress->value);

        foreach ($completedOrders as $order) {
            $transactionId = ++$transactionIdCounter;

            $transactions[] = [
                'id' => $transactionId,
                'type' => TransactionType::Income->value,
                'amount' => $order['selling_price'],
                'module' => TransactionModule::Service->value,
                'related_type' => 'App\Models\Service\ServiceOrder',
                'related_id' => $order['id'],
                'from_account_id' => null,
                'to_account_id' => $mainCashboxId,
                'created_by' => $order['created_by'],
                'notes' => 'Service order payment',
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];

            $accountEntries[] = [
                'account_id' => $mainCashboxId,
                'transaction_id' => $transactionId,
                'debit' => 0.00,
                'credit' => $order['selling_price'],
                'balance_after' => null,
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];

            $payments[] = [
                'order_id' => $order['id'],
                'amount' => $order['selling_price'],
                'account_id' => $mainCashboxId,
                'transaction_id' => $transactionId,
                'notes' => 'Seeded payment',
                'created_by' => $order['created_by'],
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];
        }

        foreach ($inProgressOrders as $order) {
            $paymentPercentage = rand(30, 70) / 100;
            $paymentAmount = round($order['selling_price'] * $paymentPercentage, 2);

            $transactionId = ++$transactionIdCounter;

            $transactions[] = [
                'id' => $transactionId,
                'type' => TransactionType::Income->value,
                'amount' => $paymentAmount,
                'module' => TransactionModule::Service->value,
                'related_type' => 'App\Models\Service\ServiceOrder',
                'related_id' => $order['id'],
                'from_account_id' => null,
                'to_account_id' => $mainCashboxId,
                'created_by' => $order['created_by'],
                'notes' => 'Service order partial payment',
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];

            $accountEntries[] = [
                'account_id' => $mainCashboxId,
                'transaction_id' => $transactionId,
                'debit' => 0.00,
                'credit' => $paymentAmount,
                'balance_after' => null,
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];

            $payments[] = [
                'order_id' => $order['id'],
                'amount' => $paymentAmount,
                'account_id' => $mainCashboxId,
                'transaction_id' => $transactionId,
                'notes' => 'Seeded partial payment',
                'created_by' => $order['created_by'],
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ];
        }

        DB::transaction(function () use ($payments, $transactions, $accountEntries, $mainCashboxId) {
            if (!empty($transactions)) {
                DB::table('transactions')->insert($transactions);
            }

            if (!empty($accountEntries)) {
                $currentBalance = DB::table('accounts')->where('id', $mainCashboxId)->value('balance');

                foreach ($accountEntries as &$entry) {
                    $currentBalance += $entry['credit'] - $entry['debit'];
                    $entry['balance_after'] = $currentBalance;
                }
                unset($entry);

                DB::table('account_entries')->insert($accountEntries);
                DB::table('accounts')->where('id', $mainCashboxId)->update(['balance' => $currentBalance]);
            }

            if (!empty($payments)) {
                DB::table('service_payments')->insert($payments);
            }
        });

        $this->command->info('✅ ServiceSeeder: 15 services + 150 orders + ' . count($payments) . ' payments');
    }
}
