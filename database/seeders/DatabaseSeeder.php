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
            // NOTE: 5 seeder references that previously broke `php artisan db:seed`
            // were removed on 2026-07-15:
            //   - FawrySettingsSeeder
            //   - OnlineSettingsSeeder
            //   - HajjUmraSettingsSeeder
            //   - WalletSettingsSeeder
            //   - TourismAccountsSeeder
            // The data these would have seeded is entered manually through the
            // Filament admin UI (or the corresponding API endpoints), per the
            // user's explicit decision (Option د).
            // For Phase 7's account-unification seeder, see UnifiedVaultsSeeder.
        ]);

        $this->command->info('═══════════════════════════════════════');
        $this->command->info('DATABASE SEEDING COMPLETED');
        $this->command->info('═══════════════════════════════════════');
    }
}
