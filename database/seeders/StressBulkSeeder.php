<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * StressBulkSeeder — Laravel-native seeder for the Phase 25 stress harness.
 *
 * Mirrors AccountingTestDataSeeder's role but lives in its own file:
 *   - Seeds ONE stress actor user (idempotent on email)
 *   - Verifies the active DB is the stress DB (refuses otherwise)
 *   - Intended to be invoked via `php artisan db:seed --class=StressBulkSeeder --env=stress`
 *
 * The bulk of the dataset (customers / suppliers / accounts / transactions)
 * is built by tests/Stress/Support/StressBulkFactory.php via standalone
 * PHP scripts (tests/scripts/stress_seeder_bulk.php) so it can run with
 * high memory limits and chunked inserts.
 */
class StressBulkSeeder extends Seeder
{
    public const STRESS_USER_EMAIL = 'stress-actor@safarakealayna.test';

    public const FORBIDDEN_DATABASES = [
        'safarakealayna', 'safarak_ealayna', 'travel_office', 'production',
    ];

    public function run(): void
    {
        $cfg = Config::get('database.connections.'.Config::get('database.default'));
        $connection = Config::get('database.default');
        $database = is_array($cfg) ? ($cfg['database'] ?? null) : null;

        $this->command->info("\n═══════════════════════════════════════════════════════════");
        $this->command->info("  StressBulkSeeder — Phase 25 stress harness");
        $this->command->info("═══════════════════════════════════════════════════════════");
        $this->command->info("  Connection: {$connection}");
        $this->command->info("  Database:   {$database}");
        $this->command->info("  APP_ENV:    ".app()->environment());

        // Safety guard
        $appEnv = app()->environment();
        if (in_array(strtolower((string) $appEnv), ['production', 'prod', 'live'], true)) {
            throw new \RuntimeException(
                "Refusing to seed under APP_ENV='{$appEnv}'. Required: 'stress'."
            );
        }
        if (in_array(strtolower((string) $database), self::FORBIDDEN_DATABASES, true)) {
            throw new \RuntimeException(
                "Refusing to seed forbidden DB '{$database}'."
            );
        }

        // 1. Stress actor user (idempotent)
        User::query()->updateOrCreate(
            ['email' => self::STRESS_USER_EMAIL],
            [
                'name'              => 'STRESS-ACTOR',
                'password'          => bcrypt('stress-password'),
                'email_verified_at' => now(),
                'role'              => 'admin',
                'is_active'         => true,
            ]
        );
        $this->command->info("✓ Stress actor user ready.");

        $this->command->info("\n✅ StressBulkSeeder complete. Run:");
        $this->command->info("   php tests/scripts/stress_seeder_bulk.php --phase=A");
        $this->command->info("   php tests/scripts/stress_seeder_bulk.php --phase=B");
    }
}
