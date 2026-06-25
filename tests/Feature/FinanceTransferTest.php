<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
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

    public function test_expense_transfer_to_expense_account(): void
    {
        $expenseAccount = Account::create([
            'name' => 'رواتب ومكافآت',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_id' => $expenseAccount->id,
            'amount' => 1500,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'صرف رواتب موظفين',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->fromAccount->refresh();
        $expenseAccount->refresh();

        $this->assertSame(48500.0, (float) $this->fromAccount->balance);
        $this->assertSame(1500.0, (float) $expenseAccount->balance);
    }

    public function test_multi_currency_expense_transfer_to_expense_account(): void
    {
        $usdAccount = Account::create([
            'name' => 'خزينة دولار',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 1000.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        $expenseAccount = Account::create([
            'name' => 'إيجار المقر الرئيسي',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $usdAccount->id,
            'to_account_id' => $expenseAccount->id,
            'amount' => 100.0,
            'converted_amount' => 5000.0,
            'exchange_rate' => 50.0,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'دفع إيجار بالدولار',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $usdAccount->refresh();
        $expenseAccount->refresh();

        $this->assertSame(900.0, (float) $usdAccount->balance);
        $this->assertSame(5000.0, (float) $expenseAccount->balance);
    }

    public function test_successful_expense_transfer_with_dynamically_created_expense_account(): void
    {
        $customName = 'مصروف نظافة وصيانة طارئ';

        // 1. Submit transfer with custom account name
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_name' => $customName,
            'amount' => 1200.0,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'تنظيف المكاتب وصيانة التكييف',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // Verify account creation in DB
        $createdAccount = Account::where('name', $customName)->first();
        $this->assertNotNull($createdAccount);
        $this->assertEquals('expense', $createdAccount->type->value);
        $this->assertEquals('EGP', $createdAccount->currency);
        $this->assertEquals(1200.0, (float) $createdAccount->balance);

        // Verify source balance deduction
        $this->fromAccount->refresh();
        $this->assertEquals(48800.0, (float) $this->fromAccount->balance);

        // 2. Submit another transfer to the same custom name to verify reuse
        $response2 = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->fromAccount->id,
            'to_account_name' => $customName,
            'amount' => 800.0,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'صيانة إضافية',
        ]);

        $response2->assertStatus(201);

        $createdAccount->refresh();
        $this->assertEquals(2000.0, (float) $createdAccount->balance); // 1200 + 800

        // Ensure only one account exists with this name
        $count = Account::where('name', $customName)->count();
        $this->assertEquals(1, $count);
    }

    public function test_tourism_expense_transfer_records_module_and_balances(): void
    {
        $tourismTreasury = Account::create([
            'name' => 'خزينة السياحة',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 20000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $expenseAccount = Account::create([
            'name' => 'مصروفات تسويق سياحي',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $tourismTreasury->id,
            'to_account_id' => $expenseAccount->id,
            'amount' => 2500,
            'type' => 'expense',
            'module' => 'tourism',
            'notes' => 'حملة إعلانية للسياحة',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $tourismTreasury->refresh();
        $expenseAccount->refresh();

        $this->assertSame(17500.0, (float) $tourismTreasury->balance);
        $this->assertSame(2500.0, (float) $expenseAccount->balance);

        $transaction = Transaction::query()->latest('id')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('expense', $transaction->type->value);
        $this->assertSame('tourism', $transaction->module->value);
        $this->assertSame($tourismTreasury->id, $transaction->from_account_id);
        $this->assertSame($expenseAccount->id, $transaction->to_account_id);
    }

    public function test_office_expense_transfer_records_module_and_balances(): void
    {
        $officeTreasury = Account::create([
            'name' => 'خزينة فوري',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 15000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);

        $expenseAccount = Account::create([
            'name' => 'مصروفات تشغيل فوري',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $officeTreasury->id,
            'to_account_id' => $expenseAccount->id,
            'amount' => 900,
            'type' => 'expense',
            'module' => 'office',
            'notes' => 'مصروفات تشغيل المكتب',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $officeTreasury->refresh();
        $expenseAccount->refresh();

        $this->assertSame(14100.0, (float) $officeTreasury->balance);
        $this->assertSame(900.0, (float) $expenseAccount->balance);

        $transaction = Transaction::query()->latest('id')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('expense', $transaction->type->value);
        $this->assertSame('office', $transaction->module->value);
    }

    public function test_flight_expense_transfer_records_correct_module(): void
    {
        $flightTreasury = Account::create([
            'name' => 'خزينة الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 30000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $expenseAccount = Account::create([
            'name' => 'مصروفات طيران',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $flightTreasury->id,
            'to_account_id' => $expenseAccount->id,
            'amount' => 1200,
            'type' => 'expense',
            'module' => 'flight',
            'notes' => 'مصروفات تشغيل طيران',
        ]);

        $response->assertStatus(201);

        $transaction = Transaction::query()->latest('id')->first();
        $this->assertSame('flight', $transaction->module->value);
        $this->assertSame(28800.0, (float) $flightTreasury->fresh()->balance);
        $this->assertSame(1200.0, (float) $expenseAccount->fresh()->balance);
    }
}
