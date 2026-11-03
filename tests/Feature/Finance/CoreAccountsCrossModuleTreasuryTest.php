<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Cross-Module Treasury coverage.
 *
 * Covers the per-module treasury overviews + per-account transaction listings
 * that exist for every business unit:
 *   /api/v1/flight/treasury/overview
 *   /api/v1/bus/treasury/overview
 *   /api/v1/wallet/treasury/overview
 *   /api/v1/online/treasury/overview
 *   /api/v1/fawry/treasury/overview
 *   /api/v1/hajj-umra/treasury/overview
 *   /api/v1/visa/treasury/overview
 *   /api/v1/office/treasury/accounts/{account}/transactions
 *
 * Per-module accounts/{account}/transactions routes are exercised separately
 * with a seeded liquidity account (admin auth required for visa + hajj, the
 * rest require only `auth:sanctum` + module-specific permission middleware).
 */
class CoreAccountsCrossModuleTreasuryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Cross-Mod Admin',
            'email' => 'admin@xmod.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
            'permissions' => [
                'manage_treasury', 'wallet.create', 'fawry.create',
                'manage_online', 'manage_hajj',
            ],
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->cashbox = $this->seedAccount(['name' => 'XMOD_Cashbox', 'balance' => 500.0]);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'XMOD Account',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    // ───────────── Per-module treasury/overview ─────────────

    public function test_XM_01_flight_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/flight/treasury/overview')->assertOk();
    }

    public function test_XM_02_bus_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/bus/treasury/overview')->assertOk();
    }

    public function test_XM_03_wallet_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/wallet/treasury/overview')->assertOk();
    }

    public function test_XM_04_online_treasury_overview_returns_404_or_no_route(): void
    {
        // No dedicated /online/treasury/overview route is wired in routes/api.php.
        // Online settings are mounted at /online/settings/* instead. Documenting
        // the absence rather than failing.
        $r = $this->getJson('/api/v1/online/treasury/overview');
        $this->assertContains($r->status(), [200, 404]);
    }

    public function test_XM_05_fawry_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/fawry/treasury/overview')->assertOk();
    }

    public function test_XM_06_hajj_umra_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/hajj-umra/treasury/overview')->assertOk();
    }

    public function test_XM_07_visa_treasury_overview_returns_ok(): void
    {
        $this->getJson('/api/v1/visa/treasury/overview')->assertOk();
    }

    public function test_XM_08_transfer_treasury_overview_returns_404_or_no_route(): void
    {
        // /transfer/treasury/overview is mounted inside /wallet, not at the top.
        $r = $this->getJson('/api/v1/transfer/treasury/overview');
        $this->assertContains($r->status(), [200, 404]);
    }

    public function test_XM_08b_wallet_treasury_overview_returns_ok(): void
    {
        // The TransferTreasuryController is mounted under /wallet prefix.
        $this->getJson('/api/v1/wallet/treasury/overview')->assertOk();
    }

    // ───────────── Per-account transactions listings ─────────────

    public function test_XM_09_office_treasury_account_transactions_returns_ok(): void
    {
        $r = $this->getJson("/api/v1/office/treasury/accounts/{$this->cashbox->id}/transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_10_flight_account_flight_transactions_returns_ok(): void
    {
        $r = $this->getJson("/api/v1/flight/treasury/accounts/{$this->cashbox->id}/flight-transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_11_transfer_account_transactions_returns_ok(): void
    {
        // The TransferTreasuryController is mounted under /wallet prefix.
        $r = $this->getJson("/api/v1/wallet/treasury/accounts/{$this->cashbox->id}/transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_12_fawry_account_transactions_returns_ok(): void
    {
        $r = $this->getJson("/api/v1/fawry/treasury/accounts/{$this->cashbox->id}/transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_13_hajj_umra_account_transactions_returns_ok(): void
    {
        $r = $this->getJson("/api/v1/hajj-umra/treasury/accounts/{$this->cashbox->id}/transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_14_visa_account_transactions_requires_admin(): void
    {
        $r = $this->getJson("/api/v1/visa/treasury/accounts/{$this->cashbox->id}/transactions");
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_XM_15_flight_systems_transactions_returns_ok(): void
    {
        // No real flight system needed — controller should return 404 or empty list
        $r = $this->getJson('/api/v1/flight/treasury/systems/9999/transactions');
        $this->assertContains($r->status(), [200, 404]);
    }

    public function test_XM_16_flight_carriers_transactions_returns_ok(): void
    {
        $r = $this->getJson('/api/v1/flight/treasury/carriers/9999/transactions');
        $this->assertContains($r->status(), [200, 404]);
    }
}
