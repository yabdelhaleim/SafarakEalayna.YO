<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $now = now();
        $twoYearsAgo = now()->subYears(2);

        // Egyptian names database
        $firstNames = [
            'Ahmed', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud', 'Youssef', 'Karim', 'Tarek',
            'Amr', 'Mostafa', 'Ibrahim', 'Abdullah', 'Yaser', 'Hazem', 'Ramy', 'Sherif', 'Hany', 'Ehab',
            'Mona', 'Fatima', 'Aisha', 'Nour', 'Sarah', 'Laila', 'Hana', 'Dina', 'Rana', 'Sama',
        ];

        $lastNames = [
            'Ibrahim', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud', 'Youssef', 'Karim', 'Tarek',
            'Abdelaziz', 'Fathy', 'Rashad', 'Nabil', 'Adel', 'Samir', 'Magdi', 'Helmi', 'Gamal', 'Kamal',
            'El-Sayed', 'El-Shamy', 'El-Banna', 'El-Masry', 'Abdelrahman', 'Abdelrahim', 'Elsayed', 'Amin',
        ];

        $customers = [];
        $chunkSize = 100;

        // Generate 500 individual customers
        for ($i = 1; $i <= 500; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $name = "{$firstName} {$lastName}";

            // Generate balance distribution
            $balanceType = rand(1, 3);
            if ($balanceType === 1) {
                // 200 customers with zero balance
                $balance = 0;
            } elseif ($balanceType === 2) {
                // 300 customers with positive balance (office owes them)
                $balance = rand(100, 50000);
            } else {
                // 100 customers with negative balance (they owe office)
                $balance = -rand(100, 20000);
            }

            $phone = '01' . rand(0, 2) . rand(10000000, 99999999);

            $customers[] = [
                'full_name' => $name,
                'phone' => $phone,
                'customer_tier' => 'STANDARD',
                'notes' => 'Seeded customer',
                'created_at' => $twoYearsAgo->copy()->addDays(rand(0, 730)),
                'updated_at' => $now,
            ];

            // Insert in chunks
            if (count($customers) >= $chunkSize) {
                DB::table('customers')->insert($customers);
                $customers = [];
            }
        }

        // Generate 100 company customers
        for ($i = 1; $i <= 100; $i++) {
            $balanceType = rand(1, 3);
            if ($balanceType === 1) {
                $balance = 0;
            } elseif ($balanceType === 2) {
                $balance = rand(100, 50000);
            } else {
                $balance = -rand(100, 20000);
            }

            $phone = '01' . rand(0, 2) . rand(10000000, 99999999);

            $customers[] = [
                'full_name' => "Company {$i} Trading",
                'phone' => $phone,
                'customer_tier' => 'PREMIUM',
                'notes' => 'Seeded company customer',
                'created_at' => $twoYearsAgo->copy()->addDays(rand(0, 730)),
                'updated_at' => $now,
            ];

            // Insert remaining
            if (count($customers) >= $chunkSize) {
                DB::table('customers')->insert($customers);
                $customers = [];
            }
        }

        // Insert any remaining customers
        if (!empty($customers)) {
            DB::table('customers')->insert($customers);
        }

        $this->command->info('✅ CustomerSeeder: 600 customers created (500 individual + 100 company)');
    }
}
