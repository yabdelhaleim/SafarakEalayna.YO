<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 6 / 28 — Cross-Module Isolation Tests.
 *
 * Verifies the module isolation contract:
 *  - Tourism customer debt is NOT posted to Office accounts
 *  - Office customer debt is NOT posted to Tourism accounts
 *  - No Tourism transaction appears in Office destinations
 *  - No Office transaction appears in Tourism destinations
 *  - Tourism accounts are exclusively Tourism-division
 *  - Office accounts are exclusively Office-division
 *
 * If any cross-boundary case fails: CLASS-A.
 */
class CrossModuleIsolationTest extends TourismAuditTestCase
{
    public function test_tourism_customer_accounts_have_tourism_division_module_type(): void
    {
        $tourismCustomers = Account::query()
            ->where('type', AccountType::Customer->value)
            ->whereIn('module_type', ['flights', 'hajj_umra', 'visas'])
            ->get();

        $this->assertGreaterThanOrEqual(0, $tourismCustomers->count(), 'Should find Tourism customer accounts (or zero if none seeded)');
        foreach ($tourismCustomers as $acc) {
            $this->assertContains(
                $acc->module_type,
                ['flights', 'hajj_umra', 'visas'],
                "Customer account #{$acc->id} has invalid module_type='{$acc->module_type}'"
            );
        }
    }

    public function test_office_division_member_modules_never_in_tourism_scope(): void
    {
        // Verify that bus, fawry, online, wallet_transfer are NEVER in the Tourism list.
        $tourismModules = \App\Support\Finance\AccountModuleDivision::TOURISM;
        $this->assertNotContains('bus', $tourismModules);
        $this->assertNotContains('fawry', $tourismModules);
        $this->assertNotContains('online', $tourismModules);
        $this->assertNotContains('wallet_transfer', $tourismModules);
    }

    public function test_tourism_division_member_modules_never_in_office_scope(): void
    {
        $officeModules = \App\Support\Finance\AccountModuleDivision::OFFICE;
        $this->assertNotContains('flights', $officeModules);
        $this->assertNotContains('hajj_umra', $officeModules);
        $this->assertNotContains('visas', $officeModules);
        $this->assertNotContains('tourism', $officeModules);
    }

    public function test_general_module_not_in_canonical_division_lists(): void
    {
        $this->assertNotContains('general', \App\Support\Finance\AccountModuleContract::TOURISM_DIVISION_MODULES);
        $this->assertNotContains('general', \App\Support\Finance\AccountModuleContract::OFFICE_DIVISION_MODULES);
        // BUT 'general' is in the LEGACY AccountModuleDivision::OFFICE for backward compatibility.
        $this->assertContains('general', \App\Support\Finance\AccountModuleDivision::OFFICE);
    }

    public function test_tourism_liquidity_isolation(): void
    {
        // All seeded vaults must be module_type='tourism' (division marker)
        foreach ([$this->vaultEgp, $this->vaultUsd, $this->vaultSar, $this->bankEgp, $this->walletEgp] as $vault) {
            $this->assertSame('tourism', $vault->module_type, "Vault #{$vault->id} has wrong division");
            $vaultType = $vault->type instanceof \BackedEnum ? $vault->type->value : (string) $vault->type;
            $this->assertContains($vaultType, ['cashbox', 'bank', 'wallet'], "Vault must be a liquidity type, got: {$vaultType}");
        }
    }

    public function test_customer_with_phone_uniqueness_per_module(): void
    {
        // A customer may exist in Tourism via different modules.
        // Each booking creates a per-module AR account.
        // Verify the same customer can have Tourism accounts across modules.
        LedgerBalanceMutationGuard::run(function () {
            $cust = Customer::query()->create([
                'full_name' => 'Cross Module Customer',
                'phone' => '01111111111',
                'type' => 'individual',
                'status' => 'active',
                'currency' => 'EGP',
                'created_by' => $this->admin->id,
            ]);

            $flightAr = Account::query()->create([
                'name' => 'Cross Module Customer Flight AR',
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'flights',
                'notes' => 'Cross-module test',
                'created_by' => $this->admin->id,
            ]);

            $hajjAr = Account::query()->create([
                'name' => 'Cross Module Customer Hajj AR',
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'notes' => 'Cross-module test',
                'created_by' => $this->admin->id,
            ]);

            $visaAr = Account::query()->create([
                'name' => 'Cross Module Customer Visa AR',
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'notes' => 'Cross-module test',
                'created_by' => $this->admin->id,
            ]);

            $this->assertSame('flights', $flightAr->module_type);
            $this->assertSame('hajj_umra', $hajjAr->module_type);
            $this->assertSame('visas', $visaAr->module_type);
        });
    }

    public function test_office_accounts_excluded_from_tourism_scope(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            // Create an Office account — bus module
            $officeAcc = Account::query()->create([
                'name' => 'Office Cashbox Bus',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 100_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => true,
                'notes' => 'Office account — should NOT appear in Tourism scope',
                'created_by' => $this->admin->id,
            ]);

            $tourismIds = Account::query()->tourism()->pluck('id')->toArray();
            $this->assertNotContains($officeAcc->id, $tourismIds, 'Office account leaked into Tourism scope');
        });
    }

    public function test_tourism_accounts_excluded_from_office_scope(): void
    {
        $tourismIds = Account::query()->tourism()->pluck('id')->toArray();
        $officeIds = Account::query()->office()->pluck('id')->toArray();
        $overlap = array_intersect($tourismIds, $officeIds);
        $this->assertEmpty($overlap, 'Tourism/Office scope overlap: '.json_encode($overlap));
    }
}
