<?php

namespace Tests\Feature\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FawryTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FawryTransactionService $service;
    protected User $user;
    protected Account $account;
    protected Customer $client;
    protected FawryOperationType $operationType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FawryTransactionService(app(TransactionService::class));
        $this->user = User::factory()->create();
        $this->account = Account::factory()->create();
        $this->client = Customer::factory()->create();
        $this->operationType = FawryOperationType::factory()->create([
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
            'is_active' => true,
        ]);

        Auth::login($this->user);
    }

    public function test_get_all_transactions_without_filters()
    {
        FawryTransaction::factory()->count(5)->create();

        $result = $this->service->getAllTransactions([]);

        $this->assertCount(5, $result->items());
        $this->assertEquals(5, $result->total());
    }

    public function test_get_all_transactions_with_operation_type_filter()
    {
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);
        FawryTransaction::factory()->create(['operation_type' => 'mobile_recharge']);
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);

        $result = $this->service->getAllTransactions(['operation_type' => 'bill_payment']);

        $this->assertCount(2, $result->items());
    }

    public function test_get_all_transactions_with_payment_method_filter()
    {
        FawryTransaction::factory()->create(['payment_method' => 'cash']);
        FawryTransaction::factory()->create(['payment_method' => 'bank_transfer']);
        FawryTransaction::factory()->create(['payment_method' => 'cash']);

        $result = $this->service->getAllTransactions(['payment_method' => 'cash']);

        $this->assertCount(2, $result->items());
    }

    public function test_get_all_transactions_with_employee_filter()
    {
        $employee1 = User::factory()->create();
        $employee2 = User::factory()->create();

        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee2->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);

        $result = $this->service->getAllTransactions(['employee_id' => $employee1->id]);

        $this->assertCount(2, $result->items());
    }

    public function test_get_all_transactions_with_date_range_filter()
    {
        FawryTransaction::factory()->create(['created_at' => '2024-01-15 10:00:00']);
        FawryTransaction::factory()->create(['created_at' => '2024-01-20 10:00:00']);
        FawryTransaction::factory()->create(['created_at' => '2024-01-25 10:00:00']);

        $result = $this->service->getAllTransactions([
            'from_date' => '2024-01-18',
            'to_date' => '2024-01-22',
        ]);

        $this->assertCount(1, $result->items());
    }

    public function test_get_all_transactions_with_search_filter()
    {
        FawryTransaction::factory()->create(['client_name' => 'Ahmed Ali', 'reference_number' => 'REF123']);
        FawryTransaction::factory()->create(['client_name' => 'Mohamed Hassan', 'reference_number' => 'REF456']);
        FawryTransaction::factory()->create(['client_name' => 'Ahmed Mohamed', 'reference_number' => 'REF789']);

        $result = $this->service->getAllTransactions(['search' => 'Ahmed']);

        $this->assertCount(2, $result->items());
    }

    public function test_get_all_transactions_with_custom_per_page()
    {
        FawryTransaction::factory()->count(25)->create();

        $result = $this->service->getAllTransactions(['per_page' => 10]);

        $this->assertCount(10, $result->items());
        $this->assertEquals(10, $result->perPage());
    }

    public function test_create_transaction_successfully()
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

        $transaction = $this->service->createTransaction($data);

        $this->assertInstanceOf(FawryTransaction::class, $transaction);
        $this->assertDatabaseHas('fawry_transactions', [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'selling_price' => 100.00,
            'profit' => 5.00,
        ]);

        $this->assertNotNull($transaction->expense_transaction_id);
        $this->assertNotNull($transaction->income_transaction_id);
    }

    public function test_create_transaction_calculates_profit_correctly()
    {
        $data = [
            'client_id' => $this->client->id,
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

        $transaction = $this->service->createTransaction($data);

        $this->assertEquals(10.00, $transaction->profit);
    }

    public function test_create_transaction_creates_expense_transaction()
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

        $transaction = $this->service->createTransaction($data);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->expense_transaction_id,
            'type' => 'transfer',
            'amount' => 95.00,
        ]);
    }

    public function test_create_transaction_creates_income_transaction()
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

        $transaction = $this->service->createTransaction($data);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->income_transaction_id,
            'type' => 'transfer',
            'amount' => 100.00,
        ]);
    }

    public function test_create_transaction_uses_client_name_from_customer()
    {
        $client = Customer::factory()->create(['full_name' => 'Customer From Database']);

        $data = [
            'client_id' => $client->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
        ];

        $transaction = $this->service->createTransaction($data);

        $this->assertEquals('Customer From Database', $transaction->client_name);
    }

    public function test_create_transaction_uses_provided_client_name()
    {
        $data = [
            'client_name' => 'Custom Client Name',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
        ];

        $transaction = $this->service->createTransaction($data);

        $this->assertEquals('Custom Client Name', $transaction->client_name);
    }

    public function test_update_transaction_successfully()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_name' => 'Old Name',
            'selling_price' => 100.00,
            'fawry_price' => 95.00,
            'profit' => 5.00,
        ]);

        $updated = $this->service->updateTransaction($transaction, [
            'client_name' => 'New Name',
            'selling_price' => 110.00,
            'fawry_price' => 95.00,
        ]);

        $this->assertEquals('New Name', $updated->client_name);
        $this->assertEquals(110.00, $updated->selling_price);
        $this->assertEquals(15.00, $updated->profit);
    }

    public function test_update_transaction_recalculates_profit_when_prices_change()
    {
        $transaction = FawryTransaction::factory()->create([
            'selling_price' => 100.00,
            'fawry_price' => 90.00,
            'profit' => 10.00,
        ]);

        $updated = $this->service->updateTransaction($transaction, [
            'selling_price' => 120.00,
            'fawry_price' => 100.00,
        ]);

        $this->assertEquals(20.00, $updated->profit);
    }

    public function test_delete_transaction_successfully()
    {
        $transaction = FawryTransaction::factory()->create();

        $result = $this->service->deleteTransaction($transaction);

        $this->assertTrue($result);
        $this->assertSoftDeleted('fawry_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_delete_transaction_reverses_accounting_transactions()
    {
        $expenseTransaction = Transaction::factory()->create();
        $incomeTransaction = Transaction::factory()->create();

        $transaction = FawryTransaction::factory()->create([
            'expense_transaction_id' => $expenseTransaction->id,
            'income_transaction_id' => $incomeTransaction->id,
        ]);

        // Mock the transaction service to verify reversal
        $mockService = $this->mock(TransactionService::class);
        $mockService->shouldReceive('reverseTransaction')
            ->once();
        $mockService->shouldReceive('reverseTransaction')
            ->once();

        $service = new FawryTransactionService($mockService);
        $service->deleteTransaction($transaction);
    }

    public function test_get_transaction_by_id()
    {
        $transaction = FawryTransaction::factory()->create();

        $found = $this->service->getTransactionById($transaction->id);

        $this->assertEquals($transaction->id, $found->id);
        $this->assertEquals($transaction->client_name, $found->client_name);
    }

    public function test_get_transaction_by_id_throws_exception_if_not_found()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->getTransactionById(999);
    }

    public function test_get_daily_summary_returns_correct_data()
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

        $summary = $this->service->getDailySummary('2024-01-15');

        $this->assertEquals(2, $summary['total_transactions']);
        $this->assertEquals(300.00, $summary['total_client_amount']);
        $this->assertEquals(285.00, $summary['total_fawry_price']);
        $this->assertEquals(300.00, $summary['total_selling_price']);
        $this->assertEquals(15.00, $summary['total_profit']);
    }

    public function test_get_daily_summary_returns_zero_for_date_with_no_transactions()
    {
        $summary = $this->service->getDailySummary('2024-01-01');

        $this->assertEquals(0, $summary['total_transactions']);
        $this->assertEquals(0.00, $summary['total_client_amount']);
        $this->assertEquals(0.00, $summary['total_fawry_price']);
        $this->assertEquals(0.00, $summary['total_selling_price']);
        $this->assertEquals(0.00, $summary['total_profit']);
    }

    public function test_transactions_are_ordered_by_created_at_desc()
    {
        FawryTransaction::factory()->create(['created_at' => '2024-01-15 10:00:00']);
        FawryTransaction::factory()->create(['created_at' => '2024-01-20 10:00:00']);
        FawryTransaction::factory()->create(['created_at' => '2024-01-10 10:00:00']);

        $result = $this->service->getAllTransactions([]);

        $this->assertEquals('2024-01-20', $result->items()[0]->created_at->format('Y-m-d'));
        $this->assertEquals('2024-01-15', $result->items()[1]->created_at->format('Y-m-d'));
        $this->assertEquals('2024-01-10', $result->items()[2]->created_at->format('Y-m-d'));
    }
}
