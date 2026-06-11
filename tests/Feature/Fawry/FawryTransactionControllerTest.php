<?php

namespace Tests\Feature\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Customer $client;

    protected FawryOperationType $operationType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->active()->create();
        $this->client = Customer::factory()->create();
        $this->operationType = FawryOperationType::factory()->create([
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
            'is_active' => true,
        ]);

        \App\Models\Fawry\FawryPaymentMethod::factory()->create([
            'code' => 'cash',
            'name_ar' => 'نقدي',
            'is_active' => true,
        ]);
    }

    public function actingAs($user, $driver = null)
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        return $this;
    }

    public function test_can_list_fawry_transactions()
    {
        FawryTransaction::factory()->count(5)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'client_name',
                            'operation_type',
                            'selling_price',
                            'profit',
                            'created_at',
                        ],
                    ],
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                    ],
                ],
            ]);
    }

    public function test_can_filter_transactions_by_operation_type()
    {
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);
        FawryTransaction::factory()->create(['operation_type' => 'mobile_recharge']);
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?operation_type=bill_payment');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_filter_transactions_by_payment_method()
    {
        FawryTransaction::factory()->create(['payment_method' => 'cash']);
        FawryTransaction::factory()->create(['payment_method' => 'bank_transfer']);
        FawryTransaction::factory()->create(['payment_method' => 'cash']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?payment_method=cash');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_filter_transactions_by_employee()
    {
        $employee1 = User::factory()->create();
        $employee2 = User::factory()->create();

        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee2->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/fawry/transactions?employee_id={$employee1->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_filter_transactions_by_date_range()
    {
        FawryTransaction::factory()->create([
            'created_at' => '2024-01-15 10:00:00',
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-20 10:00:00',
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-25 10:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?from_date=2024-01-18&to_date=2024-01-22');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_can_search_transactions_by_client_name()
    {
        FawryTransaction::factory()->create(['client_name' => 'Ahmed Ali']);
        FawryTransaction::factory()->create(['client_name' => 'Mohamed Hassan']);
        FawryTransaction::factory()->create(['client_name' => 'Ahmed Mohamed']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?search=Ahmed');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_search_transactions_by_reference_number()
    {
        FawryTransaction::factory()->create(['reference_number' => 'REF123']);
        FawryTransaction::factory()->create(['reference_number' => 'REF456']);
        FawryTransaction::factory()->create(['reference_number' => 'REF789']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?search=REF456');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_can_create_fawry_transaction()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
            'reference_number' => 'REF123',
            'notes' => 'Test transaction',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/fawry/transactions', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'client_name',
                    'operation_type',
                    'selling_price',
                    'profit',
                ],
            ]);

        $this->assertDatabaseHas('fawry_transactions', [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'selling_price' => 100.00,
        ]);
    }

    public function test_create_transaction_creates_accounting_entries()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/fawry/transactions', $data);

        $response->assertStatus(201);

        $transaction = FawryTransaction::where('client_name', 'Test Client')->first();

        $this->assertNotNull($transaction->expense_transaction_id);
        $this->assertNotNull($transaction->income_transaction_id);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->expense_transaction_id,
            'type' => 'transfer',
            'amount' => 95.00,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->income_transaction_id,
            'type' => 'transfer',
            'amount' => 100.00,
        ]);
    }

    public function test_can_view_single_fawry_transaction()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/fawry/transactions/{$transaction->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'client_name',
                    'operation_type',
                    'selling_price',
                    'profit',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $transaction->id,
                    'client_name' => 'Test Client',
                    'operation_type' => 'bill_payment',
                ],
            ]);
    }

    public function test_can_update_fawry_transaction()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_name' => 'Old Name',
            'selling_price' => 100.00,
            'fawry_price' => 95.00,
            'profit' => 5.00,
        ]);

        $data = [
            'client_name' => 'New Name',
            'selling_price' => 110.00,
            'fawry_price' => 95.00,
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/fawry/transactions/{$transaction->id}", $data);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'client_name' => 'New Name',
                    'selling_price' => 110.00,
                    'profit' => 15.00,
                ],
            ]);

        $this->assertDatabaseHas('fawry_transactions', [
            'id' => $transaction->id,
            'client_name' => 'New Name',
            'selling_price' => 110.00,
            'profit' => 15.00,
        ]);
    }

    public function test_can_delete_fawry_transaction()
    {
        $transaction = FawryTransaction::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/fawry/transactions/{$transaction->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Fawry transaction deleted successfully.',
            ]);

        $this->assertSoftDeleted('fawry_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_delete_transaction_reverses_accounting_entries()
    {
        $transaction = FawryTransaction::factory()->create();

        // Mock the transaction service to reverse transactions
        $this->mock(TransactionService::class, function ($mock) {
            $mock->shouldReceive('reverseTransaction')->twice();
        });

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/fawry/transactions/{$transaction->id}");

        $response->assertStatus(200);
    }

    public function test_can_get_daily_summary()
    {
        FawryTransaction::factory()->create([
            'created_at' => '2024-01-15 10:00:00',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'profit' => 5.00,
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-15 14:00:00',
            'client_amount' => 200.00,
            'fawry_price' => 190.00,
            'selling_price' => 200.00,
            'profit' => 10.00,
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-16 10:00:00',
            'client_amount' => 50.00,
            'fawry_price' => 45.00,
            'selling_price' => 50.00,
            'profit' => 5.00,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions/daily-summary?date=2024-01-15');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_transactions' => 2,
                    'total_client_amount' => 300.00,
                    'total_fawry_price' => 285.00,
                    'total_selling_price' => 300.00,
                    'total_profit' => 15.00,
                ],
            ]);
    }

    public function test_daily_summary_requires_valid_date_format()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions/daily-summary?date=invalid-date');

        $response->assertStatus(422);
    }

    public function test_pagination_works_correctly()
    {
        FawryTransaction::factory()->count(25)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/fawry/transactions?per_page=10');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'pagination' => [
                        'per_page' => 10,
                        'total' => 25,
                    ],
                ],
            ]);

        $this->assertCount(10, $response->json('data.items'));
    }

    public function test_unauthorized_user_cannot_access_transactions()
    {
        $response = $this->getJson('/api/v1/fawry/transactions');

        $response->assertStatus(401);
    }

    public function test_validation_on_create_transaction()
    {
        $data = [
            'client_name' => '', // Invalid: required
            'operation_type' => 'invalid_type', // Invalid: must exist
            'selling_price' => 'not_a_number', // Invalid: must be numeric
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/fawry/transactions', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_name', 'selling_price']);
    }

    public function test_profit_is_calculated_automatically_on_create()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 90.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/fawry/transactions', $data);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'profit' => 10.00,
                ],
            ]);
    }
}
