<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Database\Seeder;

/**
 * Phase 7 — Account Unification (final step).
 *
 * Creates exactly ONE default unified vault per division:
 *   - Office-division unified vault (module_type='office') — serves all office
 *     modules (bus, fawry, online, wallet_transfer) per the broadened
 *     LiquidityAccount Rules from Phase 5.
 *   - Tourism-division unified vault (module_type='tourism') — serves all
 *     tourism modules (flights, hajj_umra, visas).
 *
 * Idempotency:
 *   - Before creating, the seeder checks for an existing liquidity account
 *     with `is_module_vault=true` in the same division. If found, that
 *     existing vault is reused (no duplicate row, no UPDATE of unrelated
 *     fields).
 *   - The actual insert uses `Account::firstOrCreate()` keyed on the
 *     unique triple (name, module_type, type) as a second safety net so
 *     re-running `php artisan db:seed --class=UnifiedVaultsSeeder` is a
 *     complete no-op on the second run.
 *
 * Booking flow expectations:
 *   - Filament forms (HajjUmraResource, FawryBank, etc.) consume the
 *     broadened `*LiquidityAccount` Rules from Phase 5 and the Vue
 *     composable `accountBelongsToModule` from Phase 6 — both accept the
 *     unified vault.
 *   - A booking from any office-division module (bus, fawry, online,
 *     wallet_transfer) → ledger entries route to the office unified vault.
 *   - A booking from any tourism-division module → ledger entries route
 *     to the tourism unified vault.
 *
 * Notes:
 *   - The pre-existing `database/seeders/DatabaseSeeder.php` references
 *     several seeders that do not exist in this codebase (FawrySettingsSeeder,
 *     OnlineSettingsSeeder, HajjUmraSettingsSeeder, WalletSettingsSeeder,
 *     TourismAccountsSeeder). Running `php artisan db:seed` without
 *     `--class` will fail with a class-not-found error. This pre-existing
 *     issue is OUT OF SCOPE for Phase 7 and has been flagged in the
 *     Phase 7 report for user awareness.
 *   - The fix is to either create the missing seeders or remove their
 *     references from DatabaseSeeder — both are separate tasks.
 */
class UnifiedVaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->log('═══════════════════════════════════════════');
        $this->log('  Phase 7 — UnifiedVaultsSeeder');
        $this->log('═══════════════════════════════════════════');

        // Ensure there's a creator user (FK constraint on accounts.created_by).
        $creator = User::firstOrCreate(
            ['email' => 'system+safarakealayna.local'],
            [
                'name' => 'System',
                'password' => bcrypt('phase7-unified-vault-seeder'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $officeVault = $this->ensureDivisionVault(
            division: AccountModuleContract::OFFICE_MODULE_TYPE,
            name: 'الخزينة الموحّدة لقسم المكتب',
            notes: 'Phase 7 — خزينة القسم الموحّدة (تخدم bus/fawry/online/wallet_transfer)',
            creator: $creator,
        );

        $tourismVault = $this->ensureDivisionVault(
            division: AccountModuleContract::TOURISM_MODULE_TYPE,
            name: 'الخزينة الموحّدة لقسم السياحة',
            notes: 'Phase 7 — خزينة القسم الموحّدة (تخدم flights/hajj_umra/visas)',
            creator: $creator,
        );

        $this->log('');
        $this->log(sprintf(
            '  Office unified vault:  id=%d  name="%s"',
            $officeVault->id,
            $officeVault->name
        ));
        $this->log(sprintf(
            '  Tourism unified vault: id=%d  name="%s"',
            $tourismVault->id,
            $tourismVault->name
        ));
        $this->log('═══════════════════════════════════════════');
    }

    /**
     * Output a log line. Uses the artisan command console when available
     * (via `php artisan db:seed`); falls back to stdout for direct calls
     * (e.g., from a verify script or a test).
     */
    private function log(string $message): void
    {
        if ($this->command !== null) {
            $this->command->info($message);
        } else {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Ensure exactly one `is_module_vault=true` liquidity account exists
     * for the given division. Reuses the existing row if found, otherwise
     * creates a new one.
     */
    private function ensureDivisionVault(
        string $division,
        string $name,
        string $notes,
        User $creator,
    ): Account {
        // First-line check: is there already an is_module_vault=true row?
        $existing = Account::query()
            ->where('module_type', $division)
            ->where('is_module_vault', true)
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->first();

        if ($existing) {
            $this->log(sprintf(
                '  → %s division: reusing existing vault id=%d ("%s")',
                $division, $existing->id, $existing->name
            ));
            return $existing;
        }

        // Second-line check (race-safe): firstOrCreate on the unique triple.
        $vault = Account::firstOrCreate(
            [
                'name' => $name,
                'module_type' => $division,
                'type' => AccountType::Bank,
            ],
            [
                'currency' => 'EGP',
                'balance' => 0,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                // module value mirrors module_type so the saving-hook
                // auto-fill is a no-op and the row reads cleanly.
                'module' => $division,
                'is_active' => true,
                'is_module_vault' => true,
                'created_by' => $creator->id,
                'notes' => $notes,
            ]
        );

        $action = $vault->wasRecentlyCreated ? 'created' : 'reused (race)';
        $this->log(sprintf(
            '  → %s division: %s vault id=%d ("%s")',
            $division, $action, $vault->id, $vault->name
        ));

        return $vault;
    }
}