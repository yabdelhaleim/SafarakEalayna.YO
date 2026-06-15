<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\User;
use App\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $treasuryAccount;
    protected Account $supplierAccount;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Create active admin user
        $this->user = User::query()->create([
            'name' => 'Finance Admin',
            'email' => 'finance-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // Create a treasury account for financing the recharge
        $this->treasuryAccount = Account::query()->create([
            'name' => 'الخزينة التجريبية',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 20000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        // Create a supplier account
        $this->supplierAccount = Account::query()->create([
            'name' => 'حساب المورد التجريبي',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -5000.00, // Supplier owing balance (payable)
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        // Create a supplier linked to the account
        $this->supplier = Supplier::query()->create([
            'name' => 'شركة المورد التجريبي',
            'code' => 'SUP-TEST',
            'email' => 'supplier-test@example.com',
            'phone' => '0123456789',
            'address' => 'القاهرة',
            'is_active' => true,
            'account_id' => $this->supplierAccount->id,
        ]);
    }

    public function test_can_retrieve_supplier_balance_successfully(): void
    {
        $response = $this->getJson("/api/v1/suppliers/{$this->supplier->id}/account/balance");

        $response->assertOk()
            ->assertJsonPath('success', true);
        
        $this->assertEquals(-5000.00, (float) $response->json('data.balance'));
    }

    public function test_can_recharge_supplier_account_successfully(): void
    {
        $payload = [
            'amount' => 3000.00,
            'from_treasury_id' => $this->treasuryAccount->id,
            'notes' => 'سداد جزء من حساب المورد',
        ];

        $response = $this->postJson("/api/v1/suppliers/{$this->supplier->id}/account/recharge", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
        
        $this->assertEquals(3000.00, (float) $response->json('data.amount'));

        // Supplier owing balance should change from -5000 to -2000
        $this->assertEquals(-2000.00, $this->supplierAccount->fresh()->balance);

        // Treasury balance should decrease from 20000 to 17000
        $this->assertEquals(17000.00, $this->treasuryAccount->fresh()->balance);
    }

    public function test_can_retrieve_supplier_statement_successfully(): void
    {
        // 1. Perform a recharge to create some transactions
        $payload = [
            'amount' => 1500.00,
            'from_treasury_id' => $this->treasuryAccount->id,
            'notes' => 'حركة سداد كشف الحساب',
        ];
        $this->postJson("/api/v1/suppliers/{$this->supplier->id}/account/recharge", $payload)->assertStatus(201);

        // 2. Fetch the statement
        $response = $this->getJson("/api/v1/suppliers/{$this->supplier->id}/account/statement");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                    'stats' => ['opening_balance', 'period_credit', 'period_debit', 'closing_balance'],
                    'supplier',
                ]
            ]);

        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals('حركة سداد كشف الحساب', $response->json('data.items.0.notes'));
    }
}
