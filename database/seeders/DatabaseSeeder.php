<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->command->info('═══════════════════════════════════════');
        $this->command->info('DATABASE SEEDING STARTED');
        $this->command->info('═══════════════════════════════════════');

        $this->call([
            UserSeeder::class,
            SettingSeeder::class,
            AccountSeeder::class,
            CustomerSeeder::class,
            FlightSeeder::class,
            BusSeeder::class,
            // ServiceSeeder::class, // tables were dropped in 2026_05_06 migration
            OnlineSeeder::class,
            BonusSeeder::class,
            ProgramSeeder::class,
        ]);

        $this->command->info('═══════════════════════════════════════');
        $this->command->info('DATABASE SEEDING COMPLETED');
        $this->command->info('═══════════════════════════════════════');
    }
}
