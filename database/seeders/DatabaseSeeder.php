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
            FawrySettingsSeeder::class,
            OnlineSettingsSeeder::class,
            HajjUmraSettingsSeeder::class,
            WalletSettingsSeeder::class,
        ]);

        $this->command->info('═══════════════════════════════════════');
        $this->command->info('DATABASE SEEDING COMPLETED');
        $this->command->info('═══════════════════════════════════════');
    }
}
