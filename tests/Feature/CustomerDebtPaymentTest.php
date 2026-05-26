<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDebtPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $treasuryAccount;
    protected Account $customerAccount;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user for authentication
        $this->user = User::query()->create([
            'name' => 'Finance Controller',
            'email' => 'finance-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // Create Main Treasury Account (receiving account)
        $this->treasuryAccount = Account::query()->create([
            'name' => 'الخزينة الرئيسية',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        // Create Customer Ledger Account (owing account)
        $this->customerAccount = Account::query()->create([
            'name' => 'حساب العميل: شركة النور للخدمات',
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => 5000.00, // Debited balance (outstanding debt)
            'is_active' => true,
            'owner_type' => 'owner',
            'created_by' => $this->user->id,
        ]);

        // Create Customer and link to their ledger account
        $this->customer = Customer::query()->create([
            'full_name' => 'شركة النور للخدمات',
            'phone' => '01012345678',
            'status' => 'active',
            'type' => 'company',
            'account_id' => $this->customerAccount->id,
        ]);
    }

    public function test_customer_can_pay_outstanding_debt_successfully(): void
    {
        $payload = [
            'amount' => 1500.00,
            'account_id' => $this->treasuryAccount->id,
            'notes' => 'سند قبض - دفعة تحت الحساب من شركة النور',
        ];

        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.new_balance', 3500); // 5000 - 1500

        // Assert customer account balance updated in DB
        $this->assertEquals(3500.00, $this->customerAccount->fresh()->balance);

        // Assert treasury account balance increased in DB (1000 + 1500)
        $this->assertEquals(2500.00, $this->treasuryAccount->fresh()->balance);

        // Assert a balanced double-entry accounting transaction was recorded
        $this->assertDatabaseHas('transactions', [
            'from_account_id' => $this->customerAccount->id,
            'to_account_id' => $this->treasuryAccount->id,
            'amount' => 1500.00,
            'module' => 'flight',
        ]);
    }

    public function test_pay_debt_validation_fails_with_invalid_amount(): void
    {
        $payload = [
            'amount' => -100.00, // Invalid amount
            'account_id' => $this->treasuryAccount->id,
        ];

        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", $payload);

        $response->assertStatus(422);
    }

    public function test_pay_debt_validation_fails_with_non_existent_account(): void
    {
        $payload = [
            'amount' => 500.00,
            'account_id' => 99999, // Non-existent account ID
        ];

        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", $payload);

        $response->assertStatus(422);
    }
}
