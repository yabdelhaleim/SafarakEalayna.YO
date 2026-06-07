<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTransferHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $fromAccount;

    protected Account $toAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Transfer History Tester',
            'email' => 'transfer-history@example.com',
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
            'name' => 'History From',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->toAccount = Account::create([
            'name' => 'History To',
            'type' => 'bank',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_transfer_history_returns_items_with_account_names(): void
    {
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_id' => $this->toAccount->id,
            'amount' => 2500,
            'notes' => 'تمويل خزينة',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/finance/transfers?per_page=100');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        [
                            'id',
                            'amount',
                            'date',
                            'from_account' => ['id', 'name'],
                            'to_account' => ['id', 'name'],
                            'description',
                        ],
                    ],
                    'pagination' => ['total', 'current_page', 'last_page', 'per_page'],
                    'summary' => ['total_amount', 'today_count'],
                ],
            ]);

        $this->assertSame(2500.0, (float) $response->json('data.items.0.amount'));
        $response->assertJsonPath('data.items.0.from_account.name', 'History From');
        $response->assertJsonPath('data.items.0.to_account.name', 'History To');
        $response->assertJsonPath('data.items.0.description', 'تمويل خزينة');
        $response->assertJsonPath('data.pagination.total', 1);
        $this->assertSame(2500.0, (float) $response->json('data.summary.total_amount'));
        $this->assertSame(1, (int) $response->json('data.summary.today_count'));
    }

    public function test_transfer_history_supports_pagination(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/finance/transfers', [
                'from_account_id' => $this->fromAccount->id,
                'to_account_id' => $this->toAccount->id,
                'amount' => 100 * $i,
                'notes' => "تحويل {$i}",
            ])->assertCreated();
        }

        $page1 = $this->getJson('/api/v1/finance/transfers?per_page=2&page=1');
        $page1->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonCount(2, 'data.items');

        $page2 = $this->getJson('/api/v1/finance/transfers?per_page=2&page=2');
        $page2->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_transfer_rejects_non_liquidity_account(): void
    {
        $customer = Account::create([
            'name' => 'عميل تجريبي',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_id' => $customer->id,
            'amount' => 100,
        ]);

        $response->assertStatus(422);
    }
}
