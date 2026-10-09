<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Fawry\FawryCurrency;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FawryFullProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $employee;
    protected FawryTransactionService $fawryService;
    protected FawryMachineRechargeService $rechargeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fawryService = app(FawryTransactionService::class);
        $this->rechargeService = app(FawryMachineRechargeService::class);

        $this->admin = User::factory()->create([
            'name' => 'Admin Auditor',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::factory()->create([
            'name' => 'Employee Auditor',
            'role' => 'employee',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    /**
     * Test Master Data & System Settings Existence
     */
    public function test_01_master_data_and_settings(): void
    {
        FawryOperationType::create(['code' => 'bill_payment', 'name_ar' => 'دفع فواتير', 'name_en' => 'Bill Payment', 'is_active' => true]);
        FawryPaymentMethod::create(['code' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'is_active' => true]);

        $this->assertGreaterThan(0, FawryOperationType::active()->count());
        $this->assertGreaterThan(0, FawryPaymentMethod::active()->count());

        $machine = FawryMachine::create([
            'name' => 'Audit Machine 1',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('fawry_machines', [
            'id' => $machine->id,
            'balance' => 5000.00,
            'is_active' => 1
        ]);
    }

    /**
     * Test Machine Recharge, Valid Funding & Ineligible Account Rejection
     */
    public function test_02_machine_recharge_and_funding_validation(): void
    {
        $machine = FawryMachine::create([
            'name' => 'Recharge Target Machine',
            'type' => 'fawry',
            'balance' => 1000.00,
            'is_active' => true,
        ]);

        $egpCashbox = Account::create([
            'name' => 'Main EGP Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $inactiveMachine = FawryMachine::create([
            'name' => 'Inactive Target Machine',
            'type' => 'fawry',
            'balance' => 1000.00,
            'is_active' => false,
        ]);

        // Valid Recharge
        $res = $this->rechargeService->rechargeFromAccount($machine, $egpCashbox, 2000.00, 'Recharge test');
        $this->assertEquals(3000.00, (float)$machine->fresh()->balance);
        $this->assertEquals(8000.00, (float)$egpCashbox->fresh()->balance);

        // Inactive machine rejection
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->rechargeService->rechargeFromAccount($inactiveMachine, $egpCashbox, 500.00, 'Inactive machine');
    }

    /**
     * Test Registered Customer Full Cash vs Partial Debt Transactions
     */
    public function test_03_registered_customer_full_and_partial_payment_transactions(): void
    {
        $customer = Customer::create([
            'full_name' => 'Registered Customer Test',
            'phone' => '01234567890',
            'created_by' => $this->admin->id,
        ]);

        $machine = FawryMachine::create([
            'name' => 'Machine Tx Test',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $cashbox = Account::create([
            'name' => 'Cashbox Tx Test',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // Full Payment (Selling: 1000, Cost: 800, Paid: 1000)
        $tx1 = $this->fawryService->createTransaction([
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(200.00, (float)$tx1->profit);
        $this->assertEquals(4200.00, (float)$machine->fresh()->balance);
        $this->assertEquals(11000.00, (float)$cashbox->fresh()->balance);

        $customerAccount = Account::find(Customer::find($customer->id)->account_id);
        $this->assertEquals(0.00, (float)$customerAccount->balance);

        // Partial Payment (Selling: 1000, Cost: 800, Paid: 300 -> Debt 700)
        $tx2 = $this->fawryService->createTransaction([
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 300.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(700.00, (float)$customerAccount->fresh()->balance);
    }

    /**
     * Test Walk-in Client FIFO Debt Allocation and Overpayment Guard
     */
    public function test_04_walk_in_fifo_debt_repayment_sequence(): void
    {
        $machine = FawryMachine::create([
            'name' => 'WalkIn Machine',
            'type' => 'fawry',
            'balance' => 10000.00,
            'is_active' => true,
        ]);

        $cashbox = Account::create([
            'name' => 'WalkIn Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $clientName = 'WalkIn Audit Client Test';

        // Tx1: 1000 debt
        $tx1 = $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        // Tx2: 500 debt (Total = 1500)
        $tx2 = $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 400.00,
            'selling_price' => 500.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        $controller = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);

        // Pay 300 -> Remaining 1200
        $res1 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 300.00,
            'account_id' => $cashbox->id,
        ]));
        $data1 = json_decode($res1->getContent(), true)['data'];
        $this->assertEquals(1200.00, $data1['remaining_debt']);

        // Pay 800 -> Remaining 400 (Tx1 fully paid 1000, Tx2 has 100 paid)
        $res2 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 800.00,
            'account_id' => $cashbox->id,
        ]));
        $data2 = json_decode($res2->getContent(), true)['data'];
        $this->assertEquals(400.00, $data2['remaining_debt']);

        $this->assertEquals(1000.00, (float)FawryTransaction::find($tx1->id)->amount);
        $this->assertEquals(100.00, (float)FawryTransaction::find($tx2->id)->amount);

        // Pay 400 -> Fully settled
        $res3 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 400.00,
            'account_id' => $cashbox->id,
        ]));
        $data3 = json_decode($res3->getContent(), true)['data'];
        $this->assertTrue($data3['fully_settled']);
        $this->assertEquals(0.00, $data3['remaining_debt']);
    }

    /**
     * Test Soft Delete Atomicity and Idempotency
     */
    public function test_05_soft_delete_atomicity_and_machine_restoration(): void
    {
        $machine = FawryMachine::create([
            'name' => 'Delete Atomicity Machine',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $cashbox = Account::create([
            'name' => 'Delete Atomicity Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $tx = $this->fawryService->createTransaction([
            'client_name' => 'Delete Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(4200.00, (float)$machine->fresh()->balance);
        $this->assertEquals(11000.00, (float)$cashbox->fresh()->balance);

        // Delete transaction
        $this->fawryService->deleteTransaction($tx);

        $this->assertEquals(5000.00, (float)$machine->fresh()->balance);
        $this->assertEquals(10000.00, (float)$cashbox->fresh()->balance);
        $this->assertSoftDeleted('fawry_transactions', ['id' => $tx->id]);

        // Double delete attempt
        $secondDelete = $this->fawryService->deleteTransaction($tx);
        $this->assertTrue($secondDelete);
        $this->assertEquals(5000.00, (float)$machine->fresh()->balance);
    }

    /**
     * Test Transaction Update with Price Change and Reposting
     */
    public function test_06_update_transaction_recalculates_profit_and_adjusts_machine(): void
    {
        $machine = FawryMachine::create([
            'name' => 'Update Machine',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $cashbox = Account::create([
            'name' => 'Update Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $tx = $this->fawryService->createTransaction([
            'client_name' => 'Update Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $cashbox->id,
            'fawry_machine_id' => $machine->id,
            'payment_method' => 'cash',
        ]);

        // Update: selling_price 1200, fawry_price 900, paid 1200
        $updated = $this->fawryService->updateTransaction($tx, [
            'selling_price' => 1200.00,
            'fawry_price' => 900.00,
            'amount' => 1200.00,
        ]);

        $this->assertEquals(300.00, (float)$updated->profit);
        $this->assertEquals(4100.00, (float)$machine->fresh()->balance); // 5000 - 900
        $this->assertEquals(11200.00, (float)$cashbox->fresh()->balance); // 10000 + 1200
    }
}
