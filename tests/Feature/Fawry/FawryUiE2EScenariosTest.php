<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FawryUiE2EScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $authorizedEmployee;
    protected User $unauthorizedEmployee;
    protected FawryMachine $machine;
    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->authorizedEmployee = User::factory()->create([
            'name' => 'Authorized Employee',
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->unauthorizedEmployee = User::factory()->create([
            'name' => 'Unauthorized Employee',
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->machine = FawryMachine::create([
            'name' => 'UI Test Machine',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $this->cashbox = Account::create([
            'name' => 'UI Main EGP Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->adminUser->id,
        ]);
    }

    /**
     * UI Scenario 1: Create Fawry Operation from Frontend API
     */
    public function test_ui_scenario_01_create_fawry_operation(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $payload = [
            'client_name' => 'عميل الواجهة البحرية',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
            'notes' => 'عملية تجريبية من واجهة المستخدم',
        ];

        $response = $this->postJson('/api/v1/fawry/transactions', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.client_name', 'عميل الواجهة البحرية')
            ->assertJsonPath('data.selling_price', 1000)
            ->assertJsonPath('data.profit', 200);

        $transactionId = $response->json('data.id');
        $this->assertDatabaseHas('fawry_transactions', [
            'id' => $transactionId,
            'selling_price' => 1000.00,
            'fawry_price' => 800.00,
            'profit' => 200.00,
        ]);

        // Verify balances
        $this->assertEquals(4200.00, (float)$this->machine->fresh()->balance);
        $this->assertEquals(11000.00, (float)$this->cashbox->fresh()->balance);
    }

    /**
     * UI Scenario 2: Registered Customer Debt Creation and Gradual Repayment (1000 -> Pay 300 -> 700 -> Pay 700 -> 0)
     */
    public function test_ui_scenario_02_registered_customer_debt_lifecycle(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $customer = Customer::create([
            'full_name' => 'عميل آجل مسجل UI',
            'phone' => '01000000001',
            'created_by' => $this->adminUser->id,
        ]);

        // Step 1: Create 1000 EGP debt with partial payment of 300 EGP
        $payload1 = [
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 300.00, // 300 paid on the spot -> Debt remaining is 700
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ];

        $res1 = $this->postJson('/api/v1/fawry/transactions', $payload1);
        $res1->assertStatus(201);

        $customerAccount = Account::find(Customer::find($customer->id)->account_id);
        
        // Verify remaining debt displays 700 EGP
        $this->assertEquals(700.00, (float)$customerAccount->fresh()->balance);

        // Step 2: Pay remaining 700 EGP via customer payment transfer
        $txService = app(\App\Services\Finance\TransactionService::class);
        $txService->recordJournalTransfer([
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $this->cashbox->id,
            'amount' => 700.00,
            'module' => 'fawry',
            'notes' => 'سداد مديونية عميل مسجل',
            'created_by' => $this->adminUser->id,
        ]);

        // Verify remaining debt becomes 0.00 EGP
        $this->assertEquals(0.00, (float)$customerAccount->fresh()->balance);
    }

    /**
     * UI Scenario 3: Walk-in Client FIFO Sequential Debt Repayment
     */
    public function test_ui_scenario_03_walk_in_fifo_debt_repayment(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $clientName = 'عميل كاش غير مسجل FIFO';

        // Debt Tx 1: 1000 EGP
        $tx1Res = $this->postJson('/api/v1/fawry/transactions', [
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ]);
        $tx1Id = $tx1Res->json('data.id');

        // Debt Tx 2: 500 EGP
        $tx2Res = $this->postJson('/api/v1/fawry/transactions', [
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 400.00,
            'selling_price' => 500.00,
            'amount' => 0.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ]);
        $tx2Id = $tx2Res->json('data.id');

        // Step A: Pay 300 EGP (FIFO applies to Tx 1)
        $pay1 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 300.00,
            'account_id' => $this->cashbox->id,
        ]);
        $pay1->assertStatus(200)
            ->assertJsonPath('data.remaining_debt', 1200);

        $this->assertEquals(300.00, (float)FawryTransaction::find($tx1Id)->amount);
        $this->assertEquals(0.00, (float)FawryTransaction::find($tx2Id)->amount);

        // Step B: Pay 800 EGP (Tx 1 receives 700 to complete 1000, Tx 2 receives 100)
        $pay2 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 800.00,
            'account_id' => $this->cashbox->id,
        ]);
        $pay2->assertStatus(200)
            ->assertJsonPath('data.remaining_debt', 400);

        $this->assertEquals(1000.00, (float)FawryTransaction::find($tx1Id)->amount);
        $this->assertEquals(100.00, (float)FawryTransaction::find($tx2Id)->amount);

        // Step C: Pay 400 EGP (Full settlement)
        $pay3 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 400.00,
            'account_id' => $this->cashbox->id,
        ]);
        $pay3->assertStatus(200)
            ->assertJsonPath('data.remaining_debt', 0)
            ->assertJsonPath('data.fully_settled', true);

        $this->assertEquals(500.00, (float)FawryTransaction::find($tx2Id)->amount);
    }

    /**
     * UI Scenario 4: Soft Delete Atomicity and Verification (FINDING-FAWRY-01 Fix Verification)
     */
    public function test_ui_scenario_04_soft_delete_reversal(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Create transaction: selling = 1000, cost = 800, paid = 1000
        $res = $this->postJson('/api/v1/fawry/transactions', [
            'client_name' => 'عميل تجربة الحذف',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ]);
        $txId = $res->json('data.id');

        $this->assertEquals(4200.00, (float)$this->machine->fresh()->balance);
        $this->assertEquals(11000.00, (float)$this->cashbox->fresh()->balance);

        // Delete from UI / API
        $delRes = $this->deleteJson("/api/v1/fawry/transactions/{$txId}");
        $delRes->assertStatus(200)
            ->assertJsonPath('status', true);

        // Verification:
        // 1. Cashbox returned to exact opening balance (10,000 EGP) — NOT 11,000!
        $this->assertEquals(10000.00, (float)$this->cashbox->fresh()->balance);
        // 2. Machine balance restored to opening balance (5,000 EGP)
        $this->assertEquals(5000.00, (float)$this->machine->fresh()->balance);
        // 3. Transaction marked soft deleted
        $this->assertSoftDeleted('fawry_transactions', ['id' => $txId]);
    }

    /**
     * UI Scenario 5: Duplicate Protection (Concurrency & Idempotency)
     */
    public function test_ui_scenario_05_duplicate_request_protection(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $payload = [
            'client_name' => 'Duplicate Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 400.00,
            'selling_price' => 500.00,
            'amount' => 500.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ];

        // Send first request
        $res1 = $this->postJson('/api/v1/fawry/transactions', $payload);
        $res1->assertStatus(201);

        $txCount = FawryTransaction::where('client_name', 'Duplicate Test Client')->count();
        $this->assertEquals(1, $txCount);
    }

    /**
     * UI Scenario 6: Unauthorized User Backend Security Rejection
     */
    public function test_ui_scenario_06_unauthorized_user_rejection(): void
    {
        // Create an existing transaction as Admin
        Sanctum::actingAs($this->adminUser, ['*']);
        $txRes = $this->postJson('/api/v1/fawry/transactions', [
            'client_name' => 'Admin Created Tx',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 80.00,
            'selling_price' => 100.00,
            'amount' => 100.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'employee_id' => $this->adminUser->id,
        ]);
        $txId = $txRes->json('data.id');

        // Authenticate as unauthorized non-admin employee
        Sanctum::actingAs($this->unauthorizedEmployee, ['*']);

        // Attempt Delete on admin-only route
        $deleteRes = $this->deleteJson("/api/v1/fawry/transactions/{$txId}");
        $deleteRes->assertStatus(403);

        // Attempt Machine Recharge on admin-only route
        $rechargeRes = $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $this->cashbox->id,
            'amount' => 500.00,
        ]);
        $rechargeRes->assertStatus(403);
    }
}
