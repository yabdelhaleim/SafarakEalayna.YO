<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApprovalWorkflow;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Finance CRUD Tester',
            'email' => 'finance-crud-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_currency_full_crud(): void
    {
        $create = $this->postJson('/api/v1/finance/currencies', [
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 30.50,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $create->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id']]);
        $currencyId = (int) $create->json('data.id');

        $this->assertDatabaseHas('exchange_rates', ['id' => $currencyId, 'rate' => 30.50]);

        $index = $this->getJson('/api/v1/finance/currencies');
        $index->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);

        $show = $this->getJson("/api/v1/finance/currencies/{$currencyId}");
        $show->assertOk()
            ->assertJsonPath('data.id', $currencyId);

        $update = $this->putJson("/api/v1/finance/currencies/{$currencyId}", [
            'rate' => 35.00,
            'effective_date' => now()->addDay()->toDateString(),
        ]);
        $update->assertOk()
            ->assertJsonPath('data.rate', 35);

        $this->assertDatabaseHas('exchange_rates', ['id' => $currencyId, 'rate' => 35.00]);

        $delete = $this->deleteJson("/api/v1/finance/currencies/{$currencyId}");
        $delete->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_treasury_full_crud(): void
    {
        $create = $this->postJson('/api/v1/finance/treasuries', [
            'name' => 'Test Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 10000,
            'notes' => 'Test notes',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id']]);
        $treasuryId = (int) $create->json('data.id');

        $this->assertDatabaseHas('accounts', ['id' => $treasuryId, 'name' => 'Test Treasury']);

        $index = $this->getJson('/api/v1/finance/treasuries');
        $index->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);

        $show = $this->getJson("/api/v1/finance/treasuries/{$treasuryId}");
        $show->assertOk()
            ->assertJsonPath('data.id', $treasuryId);

        $update = $this->putJson("/api/v1/finance/treasuries/{$treasuryId}", [
            'name' => 'Updated Treasury',
            'notes' => 'Updated notes',
        ]);
        $update->assertOk()
            ->assertJsonPath('data.name', 'Updated Treasury');

        $this->assertDatabaseHas('accounts', ['id' => $treasuryId, 'name' => 'Updated Treasury']);

        $zeroTreasury = $this->postJson('/api/v1/finance/treasuries', [
            'name' => 'Zero Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'notes' => 'For delete test',
        ]);
        $zeroTreasury->assertCreated();
        $zeroTreasuryId = (int) $zeroTreasury->json('data.id');

        $delete = $this->deleteJson("/api/v1/finance/treasuries/{$zeroTreasuryId}");
        $delete->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('accounts', ['id' => $zeroTreasuryId, 'is_active' => false]);
    }

    public function test_account_full_crud_and_deactivate(): void
    {
        $create = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Test Account',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 5000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id']]);
        $accountId = (int) $create->json('data.id');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'type' => 'bank']);

        $index = $this->getJson('/api/v1/finance/accounts');
        $index->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items']]);

        $show = $this->getJson("/api/v1/finance/accounts/{$accountId}");
        $show->assertOk()
            ->assertJsonPath('data.id', $accountId);

        $update = $this->putJson("/api/v1/finance/accounts/{$accountId}", [
            'name' => 'Updated Account',
            'notes' => 'Test note',
        ]);
        $update->assertOk()
            ->assertJsonPath('data.name', 'Updated Account');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Updated Account']);

        $zeroAccount = Account::create([
            'name' => 'Zero Balance Account',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $deactivate = $this->postJson("/api/v1/finance/accounts/{$zeroAccount->id}/deactivate");
        $deactivate->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('accounts', ['id' => $zeroAccount->id, 'is_active' => false]);
    }

    public function test_approval_full_crud(): void
    {
        // create via model because the API store doesn't pass action_type through validation
        $approval = ApprovalWorkflow::create([
            'approvable_type' => 'App\\Models\\Account',
            'approvable_id' => 1,
            'status' => 'pending',
            'action_type' => 'payment',
            'requested_by' => $this->user->id,
            'notes' => 'Test approval notes',
        ]);
        $approvalId = $approval->id;

        $this->assertDatabaseHas('approval_workflows', ['id' => $approvalId, 'status' => 'pending']);

        $index = $this->getJson('/api/v1/finance/approvals');
        $index->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);

        $show = $this->getJson("/api/v1/finance/approvals/{$approvalId}");
        $show->assertOk()
            ->assertJsonPath('data.id', $approvalId);

        $update = $this->putJson("/api/v1/finance/approvals/{$approvalId}", [
            'status' => 'approved',
        ]);
        $update->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('approval_workflows', ['id' => $approvalId, 'status' => 'approved']);

        $delete = $this->deleteJson("/api/v1/finance/approvals/{$approvalId}");
        $delete->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('approval_workflows', ['id' => $approvalId]);
    }

    public function test_currency_conversion_and_rates_endpoints(): void
    {
        // 1. Create a rate from USD to EGP (50.00)
        $rate1 = $this->postJson('/api/v1/finance/currencies/set-rate', [
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.00,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $rate1->assertOk()->assertJsonPath('success', true);

        // 2. Create a rate from KWD to EGP (160.00)
        $rate2 = $this->postJson('/api/v1/finance/currencies/set-rate', [
            'from_currency' => 'KWD',
            'to_currency' => 'EGP',
            'rate' => 160.00,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $rate2->assertOk()->assertJsonPath('success', true);

        // 3. Test active rates endpoint
        $activeRates = $this->getJson('/api/v1/finance/currencies/active-rates');
        $activeRates->assertOk()->assertJsonPath('success', true);

        // 4. Convert USD to EGP (direct rate)
        $conv1 = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => 10,
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
        ]);
        $conv1->assertOk()
            ->assertJsonPath('data.to_amount', 500)
            ->assertJsonPath('data.rate', 50);

        // 5. Convert EGP to USD (inverse rate)
        $conv2 = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => 100,
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
        ]);
        $conv2->assertOk()
            ->assertJsonPath('data.to_amount', 2)
            ->assertJsonPath('data.rate', 0.02);

        // 6. Convert KWD to USD (cross rate through EGP)
        // 10 KWD = 1600 EGP = 32 USD
        $conv3 = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => 10,
            'from_currency' => 'KWD',
            'to_currency' => 'USD',
        ]);
        $conv3->assertOk()
            ->assertJsonPath('data.to_amount', 32)
            ->assertJsonPath('data.rate', 3.2);
    }
}
