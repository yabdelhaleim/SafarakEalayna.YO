<?php

namespace Tests\Feature\Fawry;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FawryMachineApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected FawryMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->machine = FawryMachine::query()->create([
            'name' => 'ماكينة فوري لاختبار API',
            'type' => 'fawry',
            'balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_accounts_endpoint_returns_all_active_office_liquidity_types_including_zero_balance(): void
    {
        $cashbox = $this->createAccount('الخزينة الرئيسية', 'cashbox', 0);
        $wallet = $this->createAccount('محفظة التحصيل', 'wallet', 250);
        $bank = $this->createAccount('الحساب البنكي', 'bank', 500);

        $inactive = $this->createAccount('خزينة غير نشطة', 'cashbox', 100, 'office', false);
        $tourism = $this->createAccount('بنك السياحة', 'bank', 100, 'tourism');
        $internal = $this->createAccount('إقفال إيرادات فوري', 'revenue', 0, 'fawry');

        $response = $this->getJson('/api/v1/fawry/accounts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'accounts' => [
                        '*' => ['id', 'name', 'type', 'balance', 'currency'],
                    ],
                ],
            ]);

        $accounts = collect($response->json('data.accounts'));

        $this->assertEqualsCanonicalizing(
            [$cashbox->id, $wallet->id, $bank->id],
            $accounts->pluck('id')->all(),
        );
        $this->assertSame(['bank', 'cashbox', 'wallet'], $accounts->pluck('type')->sort()->values()->all());
        $this->assertSame(0.0, (float) $accounts->firstWhere('id', $cashbox->id)['balance']);
        $this->assertSame('EGP', $accounts->firstWhere('id', $cashbox->id)['currency']);
        $this->assertFalse($accounts->contains('id', $inactive->id));
        $this->assertFalse($accounts->contains('id', $tourism->id));
        $this->assertFalse($accounts->contains('id', $internal->id));
    }

    public function test_admin_can_recharge_from_cashbox_wallet_and_bank_with_balanced_entries(): void
    {
        foreach (['cashbox', 'wallet', 'bank'] as $type) {
            $source = $this->createAccount("مصدر {$type}", $type, 1000);
            $machineBefore = (float) $this->machine->fresh()->balance;

            $response = $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
                'from_account_id' => $source->id,
                'amount' => 100,
                'notes' => "شحن من {$type}",
            ])->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.source_account.id', $source->id);

            $this->assertSame(900.0, (float) $source->fresh()->balance);
            $this->assertSame($machineBefore + 100, (float) $this->machine->fresh()->balance);
            $this->assertSame($machineBefore + 100, (float) $response->json('data.machine.balance'));

            $ledgerTransaction = Transaction::query()
                ->where('related_type', FawryMachine::class)
                ->where('related_id', $this->machine->id)
                ->latest('id')
                ->firstOrFail();
            $entries = AccountEntry::query()
                ->where('transaction_id', $ledgerTransaction->id)
                ->get();

            $this->assertCount(2, $entries);
            $this->assertSame(
                round((float) $entries->sum('debit'), 2),
                round((float) $entries->sum('credit'), 2),
            );
        }

        $this->assertSame(3, FawryMachineTransaction::query()
            ->where('fawry_machine_id', $this->machine->id)
            ->where('type', 'credit')
            ->count());
    }

    public function test_zero_balance_account_is_listed_but_insufficient_recharge_rolls_back_completely(): void
    {
        $source = $this->createAccount('خزينة ظاهرة برصيد صفر', 'cashbox', 0);

        $this->getJson('/api/v1/fawry/accounts')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $source->id,
                'name' => 'خزينة ظاهرة برصيد صفر',
                'balance' => 0,
            ]);

        $transactionsBefore = Transaction::query()->count();
        $entriesBefore = AccountEntry::query()->count();
        $machineTransactionsBefore = FawryMachineTransaction::query()->count();

        $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $source->id,
            'amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.amount.0', 'رصيد الحساب غير كافٍ: '.$source->name);

        $this->assertSame(0.0, (float) $source->fresh()->balance);
        $this->assertSame(0.0, (float) $this->machine->fresh()->balance);
        $this->assertSame($transactionsBefore, Transaction::query()->count());
        $this->assertSame($entriesBefore, AccountEntry::query()->count());
        $this->assertSame($machineTransactionsBefore, FawryMachineTransaction::query()->count());
    }

    public function test_recharge_validates_amount_and_rejects_ineligible_accounts(): void
    {
        $source = $this->createAccount('خزينة سليمة', 'cashbox', 1000);

        foreach ([0, -1] as $amount) {
            $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
                'from_account_id' => $source->id,
                'amount' => $amount,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'amount' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('from_account_id');

        $inactiveMachine = FawryMachine::query()->create([
            'name' => 'ماكينة متوقفة',
            'type' => 'fawry',
            'balance' => 0,
            'is_active' => false,
        ]);
        $this->postJson("/api/v1/fawry/machines/{$inactiveMachine->id}/recharge", [
            'from_account_id' => $source->id,
            'amount' => 10,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.machine.0', 'لا يمكن شحن ماكينة فوري غير مفعّلة.');

        $this->postJson('/api/v1/fawry/machines/999999/recharge', [
            'from_account_id' => $source->id,
            'amount' => 10,
        ])->assertNotFound()
            ->assertJsonPath('success', false);

        $ineligibleAccounts = [
            $this->createAccount('خزينة متوقفة', 'cashbox', 100, 'office', false),
            $this->createAccount('خزينة السياحة', 'cashbox', 100, 'tourism'),
            $this->createAccount('حساب داخلي', 'revenue', 100, 'fawry'),
        ];

        foreach ($ineligibleAccounts as $account) {
            $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
                'from_account_id' => $account->id,
                'amount' => 10,
            ])->assertNotFound()
                ->assertJsonPath('success', false);
        }

        $this->assertSame(0.0, (float) $this->machine->fresh()->balance);
    }

    public function test_machine_recharge_requires_admin_but_accounts_list_is_available_to_employee(): void
    {
        $source = $this->createAccount('خزينة الصلاحيات', 'cashbox', 1000);
        Sanctum::actingAs($this->employee, ['*']);

        $this->getJson('/api/v1/fawry/accounts')->assertOk();

        $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $source->id,
            'amount' => 100,
        ])->assertForbidden();

        $this->assertSame(1000.0, (float) $source->fresh()->balance);
        $this->assertSame(0.0, (float) $this->machine->fresh()->balance);
    }

    public function test_foreign_currency_recharge_credits_machine_with_converted_egp_amount(): void
    {
        ExchangeRate::query()->create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50,
            'effective_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $source = $this->createAccount('بنك الدولار', 'bank', 1000, 'office', true, 'USD');

        $response = $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $source->id,
            'amount' => 10,
        ])->assertOk();

        $this->assertSame(990.0, (float) $source->fresh()->balance);
        $this->assertSame(500.0, (float) $this->machine->fresh()->balance);
        $this->assertSame(500.0, (float) $response->json('data.transaction.amount'));

        $ledgerTransaction = Transaction::query()
            ->where('related_type', FawryMachine::class)
            ->where('related_id', $this->machine->id)
            ->firstOrFail();
        $sourceEntry = AccountEntry::query()
            ->where('transaction_id', $ledgerTransaction->id)
            ->where('account_id', $source->id)
            ->firstOrFail();
        $prepaidEntry = AccountEntry::query()
            ->where('transaction_id', $ledgerTransaction->id)
            ->where('account_id', $ledgerTransaction->to_account_id)
            ->firstOrFail();

        $this->assertSame(10.0, (float) $sourceEntry->debit);
        $this->assertSame(500.0, (float) $prepaidEntry->credit);
    }

    public function test_treasury_overview_and_account_history_accept_office_liquidity_account(): void
    {
        $source = $this->createAccount('خزينة قسم المكتب', 'cashbox', 1000);
        $internal = $this->createAccount('حساب إقفال داخلي', 'revenue', 0, 'fawry');

        $this->postJson("/api/v1/fawry/machines/{$this->machine->id}/recharge", [
            'from_account_id' => $source->id,
            'amount' => 100,
        ])->assertOk();

        $overview = $this->getJson('/api/v1/fawry/treasury/overview')
            ->assertOk();
        $overviewIds = collect($overview->json('data.accounts'))->pluck('id');
        $this->assertTrue($overviewIds->contains($source->id));
        $this->assertFalse($overviewIds->contains($internal->id));

        $this->getJson("/api/v1/fawry/treasury/accounts/{$source->id}/transactions")
            ->assertOk()
            ->assertJsonFragment([
                'from_account_id' => $source->id,
                'module' => 'fawry',
            ]);
    }

    private function createAccount(
        string $name,
        string $type,
        float $balance,
        string $moduleType = 'office',
        bool $isActive = true,
        string $currency = 'EGP',
    ): Account {
        return Account::query()->create([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => $isActive,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => $moduleType,
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]);
    }
}
