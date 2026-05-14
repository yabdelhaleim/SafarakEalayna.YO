<?php

namespace Database\Seeders;

use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $employeeIds = cache('seed_employee_ids') ?? range(1, 10);
        $accountMap = cache('seed_account_map');
        $busCashboxId = $accountMap['bus_cashbox'] ?? 3;

        $companies = [
            ['name' => 'Cairo Fast Lines', 'phone' => '0100000001', 'address' => 'Cairo', 'is_active' => true],
            ['name' => 'Delta Transport', 'phone' => '0100000002', 'address' => 'Alexandria', 'is_active' => true],
            ['name' => 'Upper Egypt Bus', 'phone' => '0100000003', 'address' => 'Assiut', 'is_active' => true],
            ['name' => 'Sinai Express', 'phone' => '0100000004', 'address' => 'Sharm', 'is_active' => true],
            ['name' => 'Coast Transport', 'phone' => '0100000005', 'address' => 'Marsa Alam', 'is_active' => true],
        ];

        $routes = [
            'Cairo → Alexandria',
            'Cairo → Hurghada',
            'Cairo → Aswan',
            'Cairo → Sharm El Sheikh',
            'Cairo → Luxor',
            'Alexandria → Aswan',
        ];

        $customerIds = DB::table('customers')->pluck('id')->toArray();
        $threeMonthsAhead = now()->addMonths(3);
        $twelveMonthsAgo = now()->subMonths(12);

        // Step 1: Create companies
        $companyData = [];
        foreach ($companies as $company) {
            $companyData[] = [
                'name' => $company['name'],
                'phone' => $company['phone'],
                'address' => $company['address'],
                'is_active' => $company['is_active'],
                'notes' => 'Seeded company',
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('bus_companies')->insert($companyData);
        $companyIds = DB::table('bus_companies')->pluck('id')->toArray();

        // Step 2: Create 20 inventories (12 cash, 8 deferred)
        $inventories = [];
        $transactions = [];
        $accountEntries = [];
        $transactionIdCounter = DB::table('transactions')->max('id') ?? 0;

        for ($i = 1; $i <= 20; $i++) {
            $companyId = $companyIds[array_rand($companyIds)];
            $route = $routes[array_rand($routes)];
            $travelDate = $threeMonthsAhead->copy()->addDays(rand(0, 90));

            $totalTickets = rand(30, 100);
            $costPerTicket = rand(6000, 20000) / 100; // 60-200
            $sellingPrice = $costPerTicket + rand(2000, 8000) / 100; // +20-80
            $totalCost = $totalTickets * $costPerTicket;

            $isCash = $i <= 12; // First 12 are cash payments
            $paymentType = $isCash ? BusInventoryPaymentType::Cash : BusInventoryPaymentType::Deferred;

            $inventoryData = [
                'id' => $i,
                'company_id' => $companyId,
                'route' => $route,
                'travel_date' => $travelDate,
                'departure_time' => sprintf('%02d:%02d', rand(6, 22), rand(0, 59)),
                'total_tickets' => $totalTickets,
                'available_tickets' => $totalTickets, // Will update after bookings
                'cost_per_ticket' => $costPerTicket,
                'selling_price' => $sellingPrice,
                'payment_type' => $paymentType->value,
                'total_cost' => $totalCost,
                'amount_paid' => $isCash ? $totalCost : 0,
                'remaining_debt' => $isCash ? 0 : $totalCost,
                'account_id' => $isCash ? $busCashboxId : null,
                'transaction_id' => null,
                'notes' => 'Seeded inventory',
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($isCash) {
                $transactionId = ++$transactionIdCounter;

                $transactions[] = [
                    'id' => $transactionId,
                    'type' => TransactionType::Expense->value,
                    'amount' => $totalCost,
                    'module' => TransactionModule::Bus->value,
                    'related_type' => 'App\Models\Bus\BusInventory',
                    'related_id' => $i,
                    'from_account_id' => $busCashboxId,
                    'to_account_id' => null,
                    'created_by' => $adminId,
                    'notes' => "Bus inventory purchase - {$route}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $accountEntries[] = [
                    'account_id' => $busCashboxId,
                    'transaction_id' => $transactionId,
                    'debit' => $totalCost,
                    'credit' => 0.00,
                    'balance_after' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $inventoryData['transaction_id'] = $transactionId;
            }

            $inventories[] = $inventoryData;
        }

        DB::transaction(function () use ($inventories, $transactions, $accountEntries, $busCashboxId) {
            if (!empty($transactions)) {
                DB::table('transactions')->insert($transactions);
            }

            DB::table('bus_inventories')->insert($inventories);

            if (!empty($accountEntries)) {
                $currentBalance = DB::table('accounts')->where('id', $busCashboxId)->value('balance');

                foreach ($accountEntries as &$entry) {
                    $currentBalance -= $entry['debit'];
                    $currentBalance += $entry['credit'];
                    $entry['balance_after'] = $currentBalance;
                }
                unset($entry);

                DB::table('account_entries')->insert($accountEntries);
                DB::table('accounts')->where('id', $busCashboxId)->update(['balance' => $currentBalance]);
            }
        });

        // Step 3: Create 300 bookings
        $bookings = [];
        $bookingTransactions = [];
        $bookingAccountEntries = [];

        $inventoryIds = DB::table('bus_inventories')->pluck('id', 'id')->toArray();

        for ($i = 1; $i <= 300; $i++) {
            $inventoryId = $inventoryIds[array_rand($inventoryIds)];
            $inventory = DB::table('bus_inventories')->where('id', $inventoryId)->first();

            $customerId = $customerIds[array_rand($customerIds)];
            $employeeId = $employeeIds[array_rand($employeeIds)];
            $createdBy = $employeeIds[array_rand($employeeIds)];
            $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

            // Determine status
            $statusRoll = rand(1, 10);
            if ($statusRoll <= 7) {
                $status = BusBookingStatus::Paid; // 70%
            } elseif ($statusRoll <= 9) {
                $status = BusBookingStatus::Pending; // 20%
            } else {
                $status = BusBookingStatus::Cancelled; // 10%
            }

            $quantity = rand(1, 4);
            $unitPrice = $inventory->selling_price;
            $totalPrice = $quantity * $unitPrice;
            $profit = ($unitPrice - $inventory->cost_per_ticket) * $quantity;

            $bookings[] = [
                'id' => $i,
                'inventory_id' => $inventoryId,
                'customer_id' => $customerId,
                'employee_id' => $employeeId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'profit' => $profit,
                'status' => $status->value,
                'account_id' => $status === BusBookingStatus::Paid ? $busCashboxId : null,
                'transaction_id' => null,
                'notes' => 'Seeded booking',
                'created_by' => $createdBy,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === BusBookingStatus::Paid) {
                $transactionId = ++$transactionIdCounter;

                $bookingTransactions[] = [
                    'id' => $transactionId,
                    'type' => TransactionType::Income->value,
                    'amount' => $totalPrice,
                    'module' => TransactionModule::Bus->value,
                    'related_type' => 'App\Models\Bus\BusBooking',
                    'related_id' => $i,
                    'from_account_id' => null,
                    'to_account_id' => $busCashboxId,
                    'created_by' => $createdBy,
                    'notes' => "Bus booking payment",
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $bookingAccountEntries[] = [
                    'account_id' => $busCashboxId,
                    'transaction_id' => $transactionId,
                    'debit' => 0.00,
                    'credit' => $totalPrice,
                    'balance_after' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $bookings[count($bookings) - 1]['transaction_id'] = $transactionId;
            }
        }

        // Insert bookings and update available_tickets
        DB::transaction(function () use ($bookings, $bookingTransactions, $bookingAccountEntries, $busCashboxId) {
            if (!empty($bookingTransactions)) {
                DB::table('transactions')->insert($bookingTransactions);
            }

            DB::table('bus_bookings')->insert($bookings);

            if (!empty($bookingAccountEntries)) {
                $currentBalance = DB::table('accounts')->where('id', $busCashboxId)->value('balance');

                foreach ($bookingAccountEntries as &$entry) {
                    $currentBalance += $entry['credit'] - $entry['debit'];
                    $entry['balance_after'] = $currentBalance;
                }
                unset($entry);

                DB::table('account_entries')->insert($bookingAccountEntries);
                DB::table('accounts')->where('id', $busCashboxId)->update(['balance' => $currentBalance]);
            }

            // Recalculate available_tickets for each inventory
            $inventories = DB::table('bus_inventories')->get();

            foreach ($inventories as $inventory) {
                $sold = DB::table('bus_bookings')
                    ->where('inventory_id', $inventory->id)
                    ->where('status', '!=', BusBookingStatus::Cancelled->value)
                    ->sum('quantity');

                $availableTickets = max(0, $inventory->total_tickets - $sold);

                DB::table('bus_inventories')
                    ->where('id', $inventory->id)
                    ->update(['available_tickets' => $availableTickets]);
            }
        });

        $this->command->info('✅ BusSeeder: 5 companies + 20 inventories + 300 bookings');
    }
}
