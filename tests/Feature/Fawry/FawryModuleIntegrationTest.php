<?php

namespace Tests\Feature\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryCurrency;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Setting\Currency;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FawryModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected FawryTransactionService $service;
    protected User $user;
    protected Account $account;
    protected Customer $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FawryTransactionService::class);
        $this->user = User::factory()->create();
        $this->account = Account::factory()->create();
        $this->client = Customer::factory()->create();

        Auth::login($this->user);
    }

    public function test_complete_fawry_transaction_workflow()
    {
        // Step 1: Create operation type
        $operationType = FawryOperationType::factory()->billPayment()->active()->create();

        $this->assertDatabaseHas('fawry_operation_types', [
            'code' => 'bill_payment',
            'is_active' => true,
        ]);

        // Step 2: Create payment method
        $paymentMethod = FawryPaymentMethod::factory()->cash()->active()->create([
            'default_account_id' => $this->account->id,
        ]);

        $this->assertDatabaseHas('fawry_payment_methods', [
            'code' => 'cash',
            'is_active' => true,
        ]);

        // Step 3: Create currency configuration
        $baseCurrency = Currency::factory()->create(['code' => 'USD']);
        $fawryCurrency = FawryCurrency::factory()
            ->active()
            ->withExchangeRate(48.50)
            ->withFees(2.5, 5.0)
            ->create(['currency_id' => $baseCurrency->id]);

        $this->assertDatabaseHas('fawry_currencies', [
            'currency_id' => $baseCurrency->id,
            'is_active' => true,
        ]);

        // Step 4: Calculate fees for an amount
        $amount = 1000.00;
        $expectedFee = ($amount * 0.025) + 5.0; // 25 + 5 = 30

        $calculatedFee = $fawryCurrency->calculateFee($amount);
        $this->assertEquals(30.0, $calculatedFee);

        // Step 5: Validate amount is within limits
        $fawryCurrency->min_amount = 100.00;
        $fawryCurrency->max_amount = 10000.00;
        $fawryCurrency->save();

        $this->assertTrue($fawryCurrency->isAmountValid($amount));
        $this->assertFalse($fawryCurrency->isAmountValid(50.00));
        $this->assertFalse($fawryCurrency->isAmountValid(15000.00));

        // Step 6: Create a transaction
        $transactionData = [
            'client_id' => $this->client->id,
            'client_name' => 'Integration Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => $amount,
            'fawry_price' => 950.00,
            'selling_price' => 1000.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 1000.00,
            'account_id' => $this->account->id,
            'reference_number' => 'INT-TEST-001',
        ];

        $transaction = $this->service->createTransaction($transactionData);

        $this->assertInstanceOf(FawryTransaction::class, $transaction);
        $this->assertEquals('Integration Test Client', $transaction->client_name);
        $this->assertEquals(50.00, $transaction->profit);

        // Step 7: Verify accounting entries were created
        $this->assertNotNull($transaction->expense_transaction_id);
        $this->assertNotNull($transaction->income_transaction_id);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->expense_transaction_id,
            'type' => 'expense',
            'amount' => 950.00,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->income_transaction_id,
            'type' => 'income',
            'amount' => 1000.00,
        ]);

        // Step 8: Retrieve transaction
        $retrieved = $this->service->getTransactionById($transaction->id);
        $this->assertEquals($transaction->id, $retrieved->id);

        // Step 9: Update transaction
        $updated = $this->service->updateTransaction($transaction, [
            'selling_price' => 1050.00,
        ]);

        $this->assertEquals(100.00, $updated->profit);

        // Step 10: Filter transactions
        $results = $this->service->getAllTransactions([
            'operation_type' => 'bill_payment',
            'payment_method' => 'cash',
        ]);

        $this->assertGreaterThan(0, $results->total());

        // Step 11: Get daily summary
        $summary = $this->service->getDailySummary(now()->format('Y-m-d'));

        $this->assertEquals(1, $summary['total_transactions']);
        $this->assertEquals(1000.00, $summary['total_selling_price']);

        // Step 12: Delete transaction (cleanup)
        $deleted = $this->service->deleteTransaction($updated);
        $this->assertTrue($deleted);

        $this->assertSoftDeleted('fawry_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_fawry_transaction_with_multiple_payment_methods()
    {
        // Create different payment methods
        $cashMethod = FawryPaymentMethod::factory()->cash()->active()->create();
        $bankMethod = FawryPaymentMethod::factory()->bankTransfer()->active()->create();

        // Create transactions with different methods
        $cashTransaction = FawryTransaction::factory()->create([
            'payment_method' => 'cash',
            'employee_id' => $this->user->id,
        ]);

        $bankTransaction = FawryTransaction::factory()->create([
            'payment_method' => 'bank_transfer',
            'employee_id' => $this->user->id,
        ]);

        // Test filtering by payment method
        $cashResults = $this->service->getAllTransactions([
            'payment_method' => 'cash',
        ]);

        $bankResults = $this->service->getAllTransactions([
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertGreaterThan(0, $cashResults->total());
        $this->assertGreaterThan(0, $bankResults->total());
    }

    public function test_fawry_transaction_with_multiple_operation_types()
    {
        // Create different operation types
        $billPayment = FawryOperationType::factory()->billPayment()->active()->create();
        $mobileRecharge = FawryOperationType::factory()->mobileRecharge()->active()->create();

        // Create transactions with different operation types
        $billTransaction = FawryTransaction::factory()->billPayment()->create([
            'employee_id' => $this->user->id,
        ]);

        $mobileTransaction = FawryTransaction::factory()->mobileRecharge()->create([
            'employee_id' => $this->user->id,
        ]);

        // Test filtering by operation type
        $billResults = $this->service->getAllTransactions([
            'operation_type' => 'bill_payment',
        ]);

        $mobileResults = $this->service->getAllTransactions([
            'operation_type' => 'mobile_recharge',
        ]);

        $this->assertGreaterThan(0, $billResults->total());
        $this->assertGreaterThan(0, $mobileResults->total());
    }

    public function test_fawry_transaction_search_functionality()
    {
        // Create transactions with searchable names
        FawryTransaction::factory()->create([
            'client_name' => 'Ahmed Mohamed Ali',
            'reference_number' => 'REF001',
        ]);

        FawryTransaction::factory()->create([
            'client_name' => 'Ahmed Hassan Mohamed',
            'reference_number' => 'REF002',
        ]);

        FawryTransaction::factory()->create([
            'client_name' => 'Sara Ibrahim',
            'reference_number' => 'REF003',
        ]);

        // Search by client name
        $ahmedResults = $this->service->getAllTransactions([
            'search' => 'Ahmed',
        ]);

        $this->assertEquals(2, $ahmedResults->total());

        // Search by reference number
        $refResults = $this->service->getAllTransactions([
            'search' => 'REF002',
        ]);

        $this->assertEquals(1, $refResults->total());
    }

    public function test_fawry_transaction_date_range_filtering()
    {
        // Create transactions across different dates
        FawryTransaction::factory()->create([
            'created_at' => '2024-01-10 10:00:00',
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-15 14:00:00',
        ]);

        FawryTransaction::factory()->create([
            'created_at' => '2024-01-20 09:00:00',
        ]);

        // Test date range filtering
        $results = $this->service->getAllTransactions([
            'from_date' => '2024-01-12',
            'to_date' => '2024-01-18',
        ]);

        $this->assertEquals(1, $results->total());
    }

    public function test_fawry_transaction_employee_filtering()
    {
        // Create different employees
        $employee1 = User::factory()->create();
        $employee2 = User::factory()->create();

        // Create transactions for different employees
        FawryTransaction::factory()->count(3)->create([
            'employee_id' => $employee1->id,
        ]);

        FawryTransaction::factory()->count(2)->create([
            'employee_id' => $employee2->id,
        ]);

        // Test filtering by employee
        $employee1Results = $this->service->getAllTransactions([
            'employee_id' => $employee1->id,
        ]);

        $employee2Results = $this->service->getAllTransactions([
            'employee_id' => $employee2->id,
        ]);

        $this->assertEquals(3, $employee1Results->total());
        $this->assertEquals(2, $employee2Results->total());
    }

    public function test_fawry_payment_method_full_details()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'name_ar' => 'تحويل بنكي',
            'provider_name' => 'Bank Misr',
            'bank_name' => 'البنك الأهلي',
            'account_number' => '123456789012345',
            'phone_number' => '01012345678',
        ]);

        $fullDetails = $paymentMethod->full_details;

        $this->assertStringContainsString('تحويل بنكي', $fullDetails);
        $this->assertStringContainsString('Bank Misr', $fullDetails);
        $this->assertStringContainsString('البنك الأهلي', $fullDetails);
        $this->assertStringContainsString('حساب: 123456789012345', $fullDetails);
        $this->assertStringContainsString('رقم: 01012345678', $fullDetails);
    }

    public function test_fawry_transaction_profit_calculation_edge_cases()
    {
        // Test zero profit
        $transaction1 = FawryTransaction::factory()->create([
            'fawry_price' => 100.00,
            'selling_price' => 100.00,
        ]);

        $this->assertEquals(0.00, $transaction1->profit);

        // Test high profit
        $transaction2 = FawryTransaction::factory()->create([
            'fawry_price' => 50.00,
            'selling_price' => 100.00,
        ]);

        $this->assertEquals(50.00, $transaction2->profit);

        // Test loss (negative profit)
        $transaction3 = FawryTransaction::factory()->create([
            'fawry_price' => 120.00,
            'selling_price' => 100.00,
        ]);

        $this->assertEquals(-20.00, $transaction3->profit);
    }

    public function test_fawry_currency_fee_calculation_edge_cases()
    {
        $currency = FawryCurrency::factory()->create([
            'fee_percent' => 2.5,
            'fixed_fee' => 5.0,
        ]);

        // Test with small amount
        $fee1 = $currency->calculateFee(100.00);
        $this->assertEquals(7.5, $fee1); // 2.5 + 5 = 7.5

        // Test with large amount
        $fee2 = $currency->calculateFee(10000.00);
        $this->assertEquals(255.0, $fee2); // 250 + 5 = 255

        // Test with only percent fee
        $currency2 = FawryCurrency::factory()->create([
            'fee_percent' => 3.0,
            'fixed_fee' => 0.0,
        ]);

        $fee3 = $currency2->calculateFee(1000.00);
        $this->assertEquals(30.0, $fee3);

        // Test with only fixed fee
        $currency3 = FawryCurrency::factory()->create([
            'fee_percent' => 0.0,
            'fixed_fee' => 10.0,
        ]);

        $fee4 = $currency3->calculateFee(1000.00);
        $this->assertEquals(10.0, $fee4);
    }
}
