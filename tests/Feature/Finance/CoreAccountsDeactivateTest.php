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
 * CORE ACCOUNTS — Deactivate endpoint coverage.
 *
 * POST /api/v1/finance/accounts/{id}/deactivate
 *
 * Business rule: an account can only be deactivated when its balance is
 * EXACTLY zero. Non-zero balance → ValidationException → HTTP 422.
 */
class CoreAccountsDeactivateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@deactivate.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_AD Account',
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

    public function test_AD_01_deactivate_zero_balance_account_succeeds(): void
    {
        $acc = $this->seedAccount(['balance' => 0.00, 'name' => 'TEST_AD_ZeroBalance']);

        $r = $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate");
        $r->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('accounts', [
            'id' => $acc->id,
            'is_active' => false,
        ]);
        $this->assertFalse($acc->fresh()->is_active);
    }

    public function test_AD_02_deactivate_nonzero_balance_account_rejected_with_422(): void
    {
        $acc = $this->seedAccount(['balance' => 500.00, 'name' => 'TEST_AD_WithBalance']);

        $r = $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate");
        $r->assertStatus(422);

        // Account should still be active after the rejected attempt
        $this->assertTrue((bool) $acc->fresh()->is_active);
        $this->assertDatabaseHas('accounts', [
            'id' => $acc->id,
            'is_active' => true,
        ]);
    }

    public function test_AD_02b_deactivate_negative_balance_account_rejected_with_422(): void
    {
        // Same rule applies for negative balances (overdrew treasury).
        $acc = $this->seedAccount(['balance' => -250.00, 'name' => 'TEST_AD_NegativeBalance']);

        $r = $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate");
        $r->assertStatus(422);

        $this->assertTrue((bool) $acc->fresh()->is_active);
    }

    public function test_AD_03_deactivate_already_inactive_is_idempotent(): void
    {
        $acc = $this->seedAccount(['balance' => 0.00, 'is_active' => false, 'name' => 'TEST_AD_AlreadyInactive']);

        // Calling deactivate on already-inactive account with zero balance
        // returns 200 (no-op success, not a 422).
        $r = $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate");
        $r->assertOk();

        $this->assertFalse((bool) $acc->fresh()->is_active);
    }

    public function test_AD_04_deactivate_missing_account_404(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts/999999/deactivate');
        $r->assertStatus(404);
    }

    public function test_AD_05_non_admin_gets_403_on_deactivate(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp',
            'email' => 'emp@deact.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        $acc = $this->seedAccount();

        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $r = $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate");
        $r->assertStatus(403);
    }
}