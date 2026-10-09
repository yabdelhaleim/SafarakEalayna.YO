<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Section 5 — Tourism Account Boundary / Classification.
 *
 * Builds the Tourism Account Registry. Each account is classified as:
 *   TOURISM  — module_type in [tourism, flights, hajj_umra, visas]
 *   OFFICE   — module_type in [office, bus, fawry, online, wallet_transfer]
 *   SHARED   — module_type='general' (legacy) OR module=null with division
 *   UNKNOWN  — anything else (a finding)
 *
 * Also verifies:
 *  - Liquidity accounts MUST have a division-level module_type
 *  - Subject accounts MUST have a specific module_type
 *  - The booted() guard rejects invalid combinations
 */
class TourismAccountClassificationTest extends TourismAuditTestCase
{
    public function test_liquidity_accounts_have_division_level_module_type(): void
    {
        // Tourism division vaults
        $this->assertContains($this->vaultEgp->module_type, ['tourism', 'office']);
        $this->assertSame('tourism', $this->vaultEgp->module_type);
        $this->assertSame('tourism', $this->vaultUsd->module_type);
        $this->assertSame('tourism', $this->vaultSar->module_type);
        $this->assertSame('tourism', $this->bankEgp->module_type);
        $this->assertSame('tourism', $this->walletEgp->module_type);
    }

    public function test_subject_accounts_require_specific_module_type(): void
    {
        // Try creating a customer account with module_type='tourism' (division)
        // Should be rejected by the booted() guard.
        $this->expectException(\InvalidArgumentException::class);
        LedgerBalanceMutationGuard::run(function () {
            Account::query()->create([
                'name' => 'Audit Test Customer Invalid',
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'tourism',  // INVALID — division reserved for liquidity
                'notes' => 'Should fail',
                'created_by' => $this->admin->id,
            ]);
        });
    }

    public function test_customer_account_module_type_flights(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            $acc = Account::query()->create([
                'name' => 'Audit Tourism Customer Flights',
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'flights',
                'notes' => 'Tourism Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);

            $this->assertSame('flights', $acc->module_type);
            $this->assertSame('tourism', AccountModuleContract::divisionFor($acc->module_type));
        });
    }

    public function test_supplier_account_module_type_hajj_umra(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            $acc = Account::query()->create([
                'name' => 'Audit Hajj Supplier',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'notes' => 'Tourism Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);

            $this->assertSame('hajj_umra', $acc->module_type);
            $this->assertSame('tourism', AccountModuleContract::divisionFor($acc->module_type));
        });
    }

    public function test_supplier_account_module_type_visas(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            $acc = Account::query()->create([
                'name' => 'Audit Visa Supplier',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'notes' => 'Tourism Audit 2026-08-17',
                'created_by' => $this->admin->id,
            ]);

            $this->assertSame('visas', $acc->module_type);
            $this->assertSame('tourism', AccountModuleContract::divisionFor($acc->module_type));
        });
    }

    public function test_all_seeded_accounts_classified_as_tourism(): void
    {
        $tourismAccountIds = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->pluck('id')
            ->toArray();

        $this->assertContains($this->vaultEgp->id, $tourismAccountIds);
        $this->assertContains($this->vaultUsd->id, $tourismAccountIds);
        $this->assertContains($this->vaultSar->id, $tourismAccountIds);
        $this->assertContains($this->bankEgp->id, $tourismAccountIds);
        $this->assertContains($this->walletEgp->id, $tourismAccountIds);
    }

    public function test_account_scope_tourism(): void
    {
        $tourismCount = Account::query()->tourism()->count();
        $officeCount = Account::query()->office()->count();

        $this->assertGreaterThanOrEqual(5, $tourismCount, 'Tourism scope should find at least 5 seeded vaults');
        $this->assertSame(0, $officeCount, 'No Office accounts seeded in audit fixtures');
    }
}
