<?php

namespace Tests\Feature\Fawry;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FawryMachineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FawryMachineRechargeService $rechargeService;

    protected FawryTransactionService $transactionService;

    protected User $user;

    protected Account $account;

    protected FawryMachine $machine;

    protected FawryOperationType $operationType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rechargeService = app(FawryMachineRechargeService::class);
        $this->transactionService = app(FawryTransactionService::class);
        $this->user = User::factory()->create();
        $this->account = Account::factory()->active()->create([
            'balance' => 5000.00,
            'module_type' => 'fawry',
        ]);
        $this->machine = FawryMachine::create([
            'name' => 'ماكينة فوري المحل',
            'type' => 'fawry',
            'balance' => 1000.00,
            'is_active' => true,
        ]);
        $this->operationType = FawryOperationType::factory()->create([
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
            'is_active' => true,
        ]);

        Auth::login($this->user);
    }

    public function test_can_recharge_machine_from_account()
    {
        $result = $this->rechargeService->rechargeFromAccount(
            $this->machine,
            $this->account,
            500.00,
            'شحن تجريبي'
        );

        $this->assertEquals(1500.00, (float) $result['machine']->balance);
        $this->assertEquals(4500.00, (float) $result['source_account']->balance);

        $this->assertDatabaseHas('fawry_machine_transactions', [
            'fawry_machine_id' => $this->machine->id,
            'type' => 'credit',
            'amount' => 500.00,
            'balance_before' => 1000.00,
            'balance_after' => 1500.00,
        ]);

        $this->assertDatabaseHas('transactions', [
            'from_account_id' => $this->account->id,
            'amount' => 500.00,
        ]);
    }

    public function test_can_create_fawry_transaction_with_machine()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 90.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
            'fawry_machine_id' => $this->machine->id,
        ];

        $tx = $this->transactionService->createTransaction($data);

        $this->assertInstanceOf(FawryTransaction::class, $tx);
        $this->assertEquals($this->machine->id, $tx->fawry_machine_id);

        $this->assertDatabaseHas('fawry_machines', [
            'id' => $this->machine->id,
            'balance' => 910.00, // 1000 - 90 cost
        ]);

        $this->assertDatabaseHas('fawry_machine_transactions', [
            'fawry_machine_id' => $this->machine->id,
            'fawry_transaction_id' => $tx->id,
            'type' => 'debit',
            'amount' => 90.00,
            'balance_before' => 1000.00,
            'balance_after' => 910.00,
        ]);
    }

    public function test_creation_fails_if_machine_balance_is_insufficient()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 2000.00,
            'fawry_price' => 1500.00, // exceeds machine balance of 1000
            'selling_price' => 2000.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 2000.00,
            'account_id' => $this->account->id,
            'fawry_machine_id' => $this->machine->id,
        ];

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('رصيد الماكينة غير كافٍ');

        $this->transactionService->createTransaction($data);
    }
}
