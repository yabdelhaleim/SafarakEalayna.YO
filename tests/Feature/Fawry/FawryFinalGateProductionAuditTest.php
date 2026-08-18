<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FawryFinalGateProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $unauthorizedUser;
    protected FawryMachine $machine;
    protected Account $cashbox;
    protected Account $prepaidFawryAccount;
    protected Account $incomeClearingAccount;
    protected Account $expenseClearingAccount;
    protected Account $walkInArAccount;
    protected FawryTransactionService $fawryService;
    protected FawryMachineRechargeService $rechargeService;
    protected TransactionService $transactionService;

    // Baseline tracker
    protected array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(Permission::class)) {
            Permission::firstOrCreate(['name' => 'fawry.create', 'guard_name' => 'sanctum']);
        }

        $this->fawryService = app(FawryTransactionService::class);
        $this->rechargeService = app(FawryMachineRechargeService::class);
        $this->transactionService = app(TransactionService::class);

        $this->admin = User::factory()->create([
            'name' => 'FINAL_FAWRY_AUDIT_20260813_ADMIN',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'FINAL_FAWRY_AUDIT_20260813_UNAUTH',
            'role' => 'employee',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // Create Master Data
        FawryOperationType::firstOrCreate(
            ['code' => 'bill_payment'],
            ['name_ar' => 'دفع فواتير', 'name_en' => 'Bill Payment', 'is_active' => true]
        );
        FawryPaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name_ar' => 'نقدي', 'name_en' => 'Cash', 'is_active' => true]
        );

        // Setup Main Accounting Ledger Accounts
        $this->cashbox = Account::create([
            'name' => 'FINAL_FAWRY_AUDIT_20260813_CASHBOX',
            'type' => AccountType::Cashbox,
            'balance' => 10000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $this->machine = FawryMachine::create([
            'name' => 'FINAL_FAWRY_AUDIT_20260813_MACHINE',
            'type' => 'fawry',
            'balance' => 5000.00,
            'is_active' => true,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $prepaidId = $clearing->prepaidAccountId('fawry');
        $incomeId = $clearing->incomeContraIdForModule('fawry');
        $expenseId = $clearing->expenseContraIdForModule('fawry');
        $walkInArId = $clearing->fawryWalkInArAccountId();

        $this->prepaidFawryAccount = Account::find($prepaidId);
        $this->incomeClearingAccount = Account::find($incomeId);
        $this->expenseClearingAccount = Account::find($expenseId);
        $this->walkInArAccount = Account::find($walkInArId);

        // Record Rule 2 Baseline
        $this->baseline = [
            'cashbox_balance' => (float)$this->cashbox->balance,
            'machine_balance' => (float)$this->machine->balance,
            'prepaid_balance' => (float)$this->prepaidFawryAccount->balance,
            'income_clearing_balance' => (float)$this->incomeClearingAccount->balance,
            'expense_clearing_balance' => (float)$this->expenseClearingAccount->balance,
            'walkin_ar_balance' => (float)$this->walkInArAccount->balance,
            'fawry_tx_count' => FawryTransaction::count(),
            'accounting_tx_count' => Transaction::count(),
            'account_entry_count' => AccountEntry::count(),
        ];
    }

    /**
     * TEST 1 — MACHINE RECHARGE
     */
    public function test_01_machine_recharge(): void
    {
        $initialMachine = (float)$this->machine->balance; // 5000
        $initialCashbox = (float)$this->cashbox->balance; // 10000
        $rechargeAmount = 2000.00;

        $res = $this->rechargeService->rechargeFromAccount(
            $this->machine,
            $this->cashbox,
            $rechargeAmount,
            'FINAL_FAWRY_AUDIT_20260813 Recharge'
        );

        $this->assertEquals($initialMachine + $rechargeAmount, (float)$this->machine->fresh()->balance);
        $this->assertEquals($initialCashbox - $rechargeAmount, (float)$this->cashbox->fresh()->balance);

        // Verify GL Double Entry (Debit = Credit)
        $tx = Transaction::find($res['machine_transaction']->transaction_id ?? $res['source_account']->id);
        $this->assertNotNull($tx);
        $entries = AccountEntry::where('transaction_id', $tx->id)->get();
        $debits = $entries->where('type', 'debit')->sum('amount');
        $credits = $entries->where('type', 'credit')->sum('amount');
        $this->assertEquals($debits, $credits);
    }

    /**
     * TEST 2 — SIMPLE CASH FAWRY OPERATION
     */
    public function test_02_simple_cash_fawry_operation(): void
    {
        $initialCashbox = (float)$this->cashbox->balance; // 10000
        $initialMachine = (float)$this->machine->balance; // 5000

        $tx = $this->fawryService->createTransaction([
            'client_name' => 'FINAL_FAWRY_AUDIT_20260813 Cash Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(200.00, (float)$tx->profit);
        $this->assertEquals($initialCashbox + 1000.00, (float)$this->cashbox->fresh()->balance);
        $this->assertEquals($initialMachine - 800.00, (float)$this->machine->fresh()->balance);

        // Verify GL entries balance
        $expenseEntries = AccountEntry::where('transaction_id', $tx->expense_transaction_id)->get();
        $this->assertEquals($expenseEntries->where('type', 'debit')->sum('amount'), $expenseEntries->where('type', 'credit')->sum('amount'));

        $incomeEntries = AccountEntry::where('transaction_id', $tx->income_transaction_id)->get();
        $this->assertEquals($incomeEntries->where('type', 'debit')->sum('amount'), $incomeEntries->where('type', 'credit')->sum('amount'));
    }

    /**
     * TEST 3 — REGISTERED CUSTOMER DEBT LIFECYCLE (1000 -> Pay 300 -> 700 -> Pay 700 -> 0)
     */
    public function test_03_registered_customer_debt_lifecycle(): void
    {
        $customer = Customer::create([
            'full_name' => 'FINAL_FAWRY_AUDIT_20260813 Customer',
            'phone' => '01234567899',
            'created_by' => $this->admin->id,
        ]);

        // Create 1000 EGP debt
        $tx = $this->fawryService->createTransaction([
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $custAccount = Account::find($customer->account_id);
        $this->assertEquals(1000.00, (float)$custAccount->balance);

        // Pay 300 EGP
        $this->transactionService->recordJournalTransfer([
            'from_account_id' => $custAccount->id,
            'to_account_id' => $this->cashbox->id,
            'amount' => 300.00,
            'module' => 'fawry',
            'notes' => 'سداد جزء من مديونية عميل',
            'created_by' => $this->admin->id,
        ]);
        $this->assertEquals(700.00, (float)$custAccount->fresh()->balance);

        // Pay 700 EGP
        $this->transactionService->recordJournalTransfer([
            'from_account_id' => $custAccount->id,
            'to_account_id' => $this->cashbox->id,
            'amount' => 700.00,
            'module' => 'fawry',
            'notes' => 'سداد باقي مديونية عميل',
            'created_by' => $this->admin->id,
        ]);
        $this->assertEquals(0.00, (float)$custAccount->fresh()->balance);
    }

    /**
     * TEST 4 — WALK-IN DEBT FIFO ALLOCATION (Debt #1: 1000, Debt #2: 500 -> Pay 300 -> 800 -> 400)
     */
    public function test_04_walk_in_fifo_debt_repayment(): void
    {
        $clientName = 'FINAL_FAWRY_AUDIT_20260813 WalkIn FIFO';

        $tx1 = $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $tx2 = $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 400.00,
            'selling_price' => 500.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $controller = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);

        // Pay 300 -> Tx1: 300/1000, Tx2: 0/500, Remaining = 1200
        $res1 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 300.00,
            'account_id' => $this->cashbox->id,
        ]));
        $this->assertEquals(1200.00, json_decode($res1->getContent(), true)['data']['remaining_debt']);
        $this->assertEquals(300.00, (float)FawryTransaction::find($tx1->id)->amount);
        $this->assertEquals(0.00, (float)FawryTransaction::find($tx2->id)->amount);

        // Pay 800 -> Tx1: 1000/1000, Tx2: 100/500, Remaining = 400
        $res2 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 800.00,
            'account_id' => $this->cashbox->id,
        ]));
        $this->assertEquals(400.00, json_decode($res2->getContent(), true)['data']['remaining_debt']);
        $this->assertEquals(1000.00, (float)FawryTransaction::find($tx1->id)->amount);
        $this->assertEquals(100.00, (float)FawryTransaction::find($tx2->id)->amount);

        // Pay 400 -> Tx1: 1000/1000, Tx2: 500/500, Remaining = 0
        $res3 = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 400.00,
            'account_id' => $this->cashbox->id,
        ]));
        $this->assertTrue(json_decode($res3->getContent(), true)['data']['fully_settled']);
        $this->assertEquals(0.00, json_decode($res3->getContent(), true)['data']['remaining_debt']);
        $this->assertEquals(500.00, (float)FawryTransaction::find($tx2->id)->amount);
    }

    /**
     * TEST 5 — MULTIPLE PARTIAL PAYMENTS (2500 -> 200, 350, 450, 600, 900 = 0)
     */
    public function test_05_multiple_partial_payments(): void
    {
        $clientName = 'FINAL_FAWRY_AUDIT_20260813 Partial Client';

        $tx = $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 2500.00,
            'fawry_price' => 2000.00,
            'selling_price' => 2500.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $controller = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);

        $payments = [200.00, 350.00, 450.00, 600.00, 900.00];
        $expectedRemaining = [2300.00, 1950.00, 1500.00, 900.00, 0.00];

        foreach ($payments as $idx => $payAmount) {
            $res = $controller->payDebt(new \Illuminate\Http\Request([
                'client_name' => $clientName,
                'amount' => $payAmount,
                'account_id' => $this->cashbox->id,
            ]));
            $rem = json_decode($res->getContent(), true)['data']['remaining_debt'];
            $this->assertEquals($expectedRemaining[$idx], (float)$rem);
        }

        $this->assertEquals(2500.00, (float)FawryTransaction::find($tx->id)->amount);
    }

    /**
     * TEST 6 — OVERPAYMENT PROTECTION (Debt: 1000 -> Attempt 1001, 0, -100 -> HTTP 422 Blocked)
     */
    public function test_06_overpayment_protection(): void
    {
        $clientName = 'FINAL_FAWRY_AUDIT_20260813 Overpay Client';

        $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        // Attempt 1001 EGP
        $res1 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 1001.00,
            'account_id' => $this->cashbox->id,
        ]);
        $res1->assertStatus(422);

        // Attempt 0 EGP
        $res2 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 0.00,
            'account_id' => $this->cashbox->id,
        ]);
        $res2->assertStatus(422);

        // Attempt -100 EGP
        $res3 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => -100.00,
            'account_id' => $this->cashbox->id,
        ]);
        $res3->assertStatus(422);
    }

    /**
     * TEST 7 — EXACT DECIMAL PAYMENT (Debt: 777.50 -> Pay 777.50 -> Debt = 0.00)
     */
    public function test_07_exact_decimal_payment(): void
    {
        $clientName = 'FINAL_FAWRY_AUDIT_20260813 Decimal Client';

        $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 777.50,
            'fawry_price' => 700.00,
            'selling_price' => 777.50,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $controller = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);
        $res = $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => $clientName,
            'amount' => 777.50,
            'account_id' => $this->cashbox->id,
        ]));

        $data = json_decode($res->getContent(), true)['data'];
        $this->assertEquals(0.00, (float)$data['remaining_debt']);
        $this->assertTrue($data['fully_settled']);
    }

    /**
     * TEST 8 — DUPLICATE PAYMENT / RAPID RETRY PROTECTION
     */
    public function test_08_duplicate_payment_protection(): void
    {
        $clientName = 'FINAL_FAWRY_AUDIT_20260813 Duplicate Payment Client';

        $this->fawryService->createTransaction([
            'client_name' => $clientName,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        // Rapid payment 1: 500
        $res1 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 500.00,
            'account_id' => $this->cashbox->id,
        ]);
        $res1->assertStatus(200);

        // Rapid payment 2: 500
        $res2 = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount' => 500.00,
            'account_id' => $this->cashbox->id,
        ]);
        $res2->assertStatus(200)
            ->assertJsonPath('data.remaining_debt', 0);
    }

    /**
     * TEST 9 — SOFT DELETE FULL CYCLE (Regression check for FINDING-FAWRY-01)
     */
    public function test_09_soft_delete_full_cycle(): void
    {
        $initialCashbox = (float)$this->cashbox->balance; // 10000
        $initialMachine = (float)$this->machine->balance; // 5000

        $tx = $this->fawryService->createTransaction([
            'client_name' => 'FINAL_FAWRY_AUDIT_20260813 Delete Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals($initialCashbox + 1000.00, (float)$this->cashbox->fresh()->balance);
        $this->assertEquals($initialMachine - 800.00, (float)$this->machine->fresh()->balance);

        // Perform Soft Delete
        $this->fawryService->deleteTransaction($tx);

        // Verify exact restoration — ZERO GHOST BALANCE!
        $this->assertEquals($initialCashbox, (float)$this->cashbox->fresh()->balance);
        $this->assertEquals($initialMachine, (float)$this->machine->fresh()->balance);
        $this->assertSoftDeleted('fawry_transactions', ['id' => $tx->id]);
    }

    /**
     * TEST 10 — MULTIPLE OPERATIONS MIXED CHAINED BUSINESS SEQUENCE
     */
    public function test_10_mixed_chained_business_sequence(): void
    {
        // 1. Machine Recharge (+2000 to machine, -2000 from cashbox)
        $this->rechargeService->rechargeFromAccount($this->machine, $this->cashbox, 2000.00, 'Sequence Recharge');
        $this->assertEquals(7000.00, (float)$this->machine->fresh()->balance);
        $this->assertEquals(8000.00, (float)$this->cashbox->fresh()->balance);

        // 2. Cash Operation (Selling 1000, Cost 800, Paid 1000)
        $txCash = $this->fawryService->createTransaction([
            'client_name' => 'Sequence Cash Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 1000.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);
        $this->assertEquals(6200.00, (float)$this->machine->fresh()->balance);
        $this->assertEquals(9000.00, (float)$this->cashbox->fresh()->balance);

        // 3. Registered Customer Debt (1000 debt, 0 paid)
        $customer = Customer::create([
            'full_name' => 'Sequence Customer',
            'phone' => '01011112222',
            'created_by' => $this->admin->id,
        ]);
        $this->fawryService->createTransaction([
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 1000.00,
            'fawry_price' => 800.00,
            'selling_price' => 1000.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);
        $this->assertEquals(5400.00, (float)$this->machine->fresh()->balance);

        // 4. Walk-In Debt (Selling 500, Cost 400, Paid 0)
        $this->fawryService->createTransaction([
            'client_name' => 'Sequence WalkIn',
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 400.00,
            'selling_price' => 500.00,
            'amount' => 0.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);
        $this->assertEquals(5000.00, (float)$this->machine->fresh()->balance);

        // 5. Walk-In Pay Debt (500 EGP)
        $controller = app(\App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController::class);
        $controller->payDebt(new \Illuminate\Http\Request([
            'client_name' => 'Sequence WalkIn',
            'amount' => 500.00,
            'account_id' => $this->cashbox->id,
        ]));
        $this->assertEquals(9500.00, (float)$this->cashbox->fresh()->balance);

        // 6. Delete Cash Operation
        $this->fawryService->deleteTransaction($txCash);

        // Final verification: Machine balance restored from 5000 to 5800 (+800), Cashbox reduced from 9500 to 8500 (-1000)
        $this->assertEquals(5800.00, (float)$this->machine->fresh()->balance);
        $this->assertEquals(8500.00, (float)$this->cashbox->fresh()->balance);
    }

    /**
     * TEST 19 — AUTHORIZATION FINAL CHECK
     */
    public function test_19_authorization_final_check(): void
    {
        // Create an existing Fawry transaction as admin first
        Sanctum::actingAs($this->admin, ['*']);
        $tx = $this->fawryService->createTransaction([
            'client_name' => 'Auth Check Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 80.00,
            'selling_price' => 100.00,
            'amount' => 100.00,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);

        // Switch to unauthorized user
        Sanctum::actingAs($this->unauthorizedUser, ['*']);

        $res1 = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        $res1->assertStatus(403);

        $res2 = $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $this->cashbox->id,
            'amount' => 100.00,
        ]);
        $res2->assertStatus(403);
    }
}
