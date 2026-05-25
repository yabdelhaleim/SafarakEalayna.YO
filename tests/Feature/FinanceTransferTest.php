<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $fromAccount;

    protected Account $toAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Finance Transfer Tester',
            'email' => 'transfer-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->fromAccount = Account::create([
            'name' => 'Test Account A (from)',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->toAccount = Account::create([
            'name' => 'Test Account B (to)',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_successful_transfer(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_id' => $this->toAccount->id,
            'amount' => 5000,
            'currency' => 'EGP',
            'notes' => 'Test transfer',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'transaction_id',
                    'from_account_id',
                    'to_account_id',
                ],
            ]);

        $this->fromAccount->refresh();
        $this->toAccount->refresh();

        $this->assertSame(45000.0, (float) $this->fromAccount->balance);
        $this->assertSame(15000.0, (float) $this->toAccount->balance);
    }

    public function test_transfer_fails_without_balance(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_id' => $this->toAccount->id,
            'amount' => 999999,
            'currency' => 'EGP',
            'notes' => 'Overdraft attempt',
        ]);

        $response->assertStatus(422);
    }

}
