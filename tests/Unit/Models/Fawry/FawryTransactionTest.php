<?php

namespace Tests\Unit\Models\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryTransaction;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected Account $account;
    protected Currency $currency;
    protected Customer $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->account = Account::factory()->create();
        $this->currency = Currency::factory()->create();
        $this->client = Customer::factory()->create();
    }

    public function test_fawry_transaction_can_be_created()
    {
        $transaction = FawryTransaction::create([
            'client_id' => $this->client->id,
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'profit' => 5.00,
            'employee_id' => $this->employee->id,
            'account_id' => $this->account->id,
            'currency_id' => $this->currency->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'reference_number' => 'REF123',
        ]);

        $this->assertDatabaseHas('fawry_transactions', [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'selling_price' => 100.00,
        ]);

        $this->assertEquals(5.00, $transaction->profit);
    }

    public function test_fawry_transaction_profit_is_calculated_automatically()
    {
        $transaction = FawryTransaction::create([
            'client_id' => $this->client->id,
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->employee->id,
            'account_id' => $this->account->id,
            'currency_id' => $this->currency->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
        ]);

        $this->assertEquals(5.00, $transaction->profit);
    }

    public function test_fawry_transaction_belongs_to_client()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_id' => $this->client->id,
        ]);

        $this->assertInstanceOf(Customer::class, $transaction->client);
        $this->assertEquals($this->client->id, $transaction->client->id);
    }

    public function test_fawry_transaction_belongs_to_employee()
    {
        $transaction = FawryTransaction::factory()->create([
            'employee_id' => $this->employee->id,
        ]);

        $this->assertInstanceOf(User::class, $transaction->employee);
        $this->assertEquals($this->employee->id, $transaction->employee->id);
    }

    public function test_fawry_transaction_belongs_to_account()
    {
        $transaction = FawryTransaction::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $this->assertInstanceOf(Account::class, $transaction->account);
        $this->assertEquals($this->account->id, $transaction->account->id);
    }

    public function test_fawry_transaction_belongs_to_currency()
    {
        $transaction = FawryTransaction::factory()->create([
            'currency_id' => $this->currency->id,
        ]);

        $this->assertInstanceOf(Currency::class, $transaction->currency);
        $this->assertEquals($this->currency->id, $transaction->currency->id);
    }

    public function test_fawry_transaction_belongs_to_expense_transaction()
    {
        $expenseTransaction = Transaction::factory()->create();

        $fawryTransaction = FawryTransaction::factory()->create([
            'expense_transaction_id' => $expenseTransaction->id,
        ]);

        $this->assertInstanceOf(Transaction::class, $fawryTransaction->expenseTransaction);
        $this->assertEquals($expenseTransaction->id, $fawryTransaction->expenseTransaction->id);
    }

    public function test_fawry_transaction_belongs_to_income_transaction()
    {
        $incomeTransaction = Transaction::factory()->create();

        $fawryTransaction = FawryTransaction::factory()->create([
            'income_transaction_id' => $incomeTransaction->id,
        ]);

        $this->assertInstanceOf(Transaction::class, $fawryTransaction->incomeTransaction);
        $this->assertEquals($incomeTransaction->id, $fawryTransaction->incomeTransaction->id);
    }

    public function test_scope_by_operation_type()
    {
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);
        FawryTransaction::factory()->create(['operation_type' => 'mobile_recharge']);
        FawryTransaction::factory()->create(['operation_type' => 'bill_payment']);

        $billPayments = FawryTransaction::byOperationType('bill_payment')->get();
        $mobileRecharges = FawryTransaction::byOperationType('mobile_recharge')->get();

        $this->assertCount(2, $billPayments);
        $this->assertCount(1, $mobileRecharges);
    }

    public function test_scope_by_payment_method()
    {
        FawryTransaction::factory()->create(['payment_method' => 'cash']);
        FawryTransaction::factory()->create(['payment_method' => 'bank_transfer']);
        FawryTransaction::factory()->create(['payment_method' => 'cash']);

        $cashPayments = FawryTransaction::byPaymentMethod('cash')->get();
        $bankPayments = FawryTransaction::byPaymentMethod('bank_transfer')->get();

        $this->assertCount(2, $cashPayments);
        $this->assertCount(1, $bankPayments);
    }

    public function test_scope_by_employee()
    {
        $employee1 = User::factory()->create();
        $employee2 = User::factory()->create();

        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee2->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);

        $employee1Transactions = FawryTransaction::byEmployee($employee1->id)->get();
        $employee2Transactions = FawryTransaction::byEmployee($employee2->id)->get();

        $this->assertCount(2, $employee1Transactions);
        $this->assertCount(1, $employee2Transactions);
    }

    public function test_scope_by_date_range()
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

        $transactions = FawryTransaction::byDateRange('2024-01-18', '2024-01-22')->get();

        $this->assertCount(1, $transactions);
    }

    public function test_fawry_transaction_uses_soft_deletes()
    {
        $transaction = FawryTransaction::factory()->create();

        $transaction->delete();

        $this->assertSoftDeleted('fawry_transactions', [
            'id' => $transaction->id,
        ]);

        $this->assertNotNull(FawryTransaction::withTrashed()->find($transaction->id));
    }

    public function test_payment_details_are_cast_to_array()
    {
        $paymentDetails = [
            'bank_name' => 'Bank Misr',
            'account_number' => '1234567890',
        ];

        $transaction = FawryTransaction::factory()->create([
            'payment_details' => $paymentDetails,
        ]);

        $this->assertIsArray($transaction->payment_details);
        $this->assertEquals('Bank Misr', $transaction->payment_details['bank_name']);
    }

    public function test_decimal_fields_are_properly_cast()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_amount' => 100.50,
            'fawry_price' => 95.25,
            'selling_price' => 100.75,
            'profit' => 5.50,
            'amount' => 100.75,
        ]);

        $this->assertEquals(100.50, $transaction->client_amount);
        $this->assertEquals(95.25, $transaction->fawry_price);
        $this->assertEquals(100.75, $transaction->selling_price);
        $this->assertEquals(5.50, $transaction->profit);
        $this->assertEquals(100.75, $transaction->amount);
    }
}
