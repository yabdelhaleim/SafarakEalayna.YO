<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTransactionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $cashbox;

    protected Account $clearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Finance Tx Tester',
            'email' => 'finance-tx-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->clearing = Account::query()->create([
            'name' => 'إقفال مبيعات الطيران (نظام)',
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        config(['accounting.clearing.income.flight' => $this->clearing->name]);

        $this->cashbox = Account::query()->create([
            'name' => 'خزينة اختبار',
            'type' => AccountType::Cashbox,
            'balance' => 1000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'module' => 'flight',
        ]);
    }

    public function test_create_income_posts_balanced_double_entry(): void
    {
        $response = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 250,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'description' => 'إيراد تجريبي',
            'reference' => 'REF-001',
            'date' => '2026-06-06',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $txId = (int) $response->json('data.id');
        $this->assertSame(2, AccountEntry::where('transaction_id', $txId)->count());

        $sums = AccountEntry::query()
            ->where('transaction_id', $txId)
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();

        $this->assertEqualsWithDelta(250.0, (float) $sums->d, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $sums->c, 0.01);
        $this->assertSame(1250.0, (float) $this->cashbox->fresh()->balance);
    }

    public function test_create_expense_requires_sufficient_balance(): void
    {
        $expenseClearing = Account::query()->create([
            'name' => 'إقفال تكاليف الباصات',
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
        ]);
        config(['accounting.clearing.expense.bus' => $expenseClearing->name]);

        $response = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'expense',
            'amount' => 5000,
            'account_id' => $this->cashbox->id,
            'module' => 'bus',
            'description' => 'مصروف تجريبي',
        ]);

        $response->assertStatus(422);

        $ok = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'expense',
            'amount' => 200,
            'account_id' => $this->cashbox->id,
            'module' => 'bus',
            'description' => 'مصروف تجريبي',
        ]);

        $ok->assertCreated();
        $this->assertSame(800.0, (float) $this->cashbox->fresh()->balance);
    }

    public function test_destroy_reverses_all_ledger_lines(): void
    {
        $create = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 100,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'description' => 'للحذف',
        ]);
        $create->assertCreated();
        $txId = (int) $create->json('data.id');
        $this->assertSame(1100.0, (float) $this->cashbox->fresh()->balance);

        $delete = $this->deleteJson("/api/v1/finance/transactions/{$txId}");
        $delete->assertOk();

        $this->assertSame(1000.0, (float) $this->cashbox->fresh()->balance);
        $this->assertDatabaseMissing('transactions', ['id' => $txId]);
        $this->assertSame(0, AccountEntry::where('transaction_id', $txId)->count());
    }

    public function test_rejects_non_liquidity_account(): void
    {
        $customer = Account::query()->create([
            'name' => 'حساب العميل: Test',
            'type' => AccountType::Customer,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'owner',
        ]);

        $response = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 50,
            'account_id' => $customer->id,
            'module' => 'flight',
            'description' => 'خطأ',
        ]);

        $response->assertStatus(422);
    }
}
