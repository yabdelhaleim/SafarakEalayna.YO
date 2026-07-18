<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Reports\ReportFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected Account $incomeClearing;

    protected Account $expenseClearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Operations Tester',
            'email' => 'ops-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::create([
            'name' => 'Ops Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100_000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $incomeId = $clearing->incomeContraIdForModule('fawry');
        $expenseId = $clearing->expenseContraIdForModule('fawry');
        $this->assertNotNull($incomeId);
        $this->assertNotNull($expenseId);

        $this->incomeClearing = Account::query()->findOrFail($incomeId);
        $this->expenseClearing = Account::query()->findOrFail($expenseId);
    }

    public function test_financial_summary_uses_clearing_logic_for_office_modules(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 3000, 'fawry', 'بيع فوري');
        $this->createTransfer($this->treasury->id, $this->expenseClearing->id, 1200, 'fawry', 'تكلفة فوري');

        $summary = app(ReportFinanceService::class)->getFinancialSummary([
            'module' => ['bus', 'fawry', 'online', 'wallet', 'general'],
        ]);

        $this->assertEquals(3000.0, $summary['total_income']);
        $this->assertEquals(1200.0, $summary['total_expense']);
        $this->assertEquals(1800.0, $summary['net_profit']);
    }

    public function test_transaction_report_tags_flow_kind_and_filters_modules(): void
    {
        $flightClearingId = app(LedgerClearingAccounts::class)->incomeContraIdForModule('flight');
        $this->assertNotNull($flightClearingId);

        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 2500, 'fawry', 'فوري');
        $this->createTransfer($flightClearingId, $this->treasury->id, 9000, 'flight', 'طيران');

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'module' => ['fawry', 'online', 'bus', 'wallet', 'general'],
            'per_page' => 20,
        ]);

        $this->assertCount(1, $paginator->items());
        $this->assertSame('inflow', $paginator->items()[0]->flow_kind);
        $this->assertEquals('fawry', $paginator->items()[0]->module);
    }

    public function test_expenses_only_filter_includes_transfers_to_expense_accounts(): void
    {
        $expenseAccount = Account::create([
            'name' => 'مصروف إيجار',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        $this->createTransfer($this->treasury->id, $expenseAccount->id, 750, 'general', 'إيجار مكتب');

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'expenses_only' => true,
            'per_page' => 20,
        ]);

        $this->assertCount(1, $paginator->items());
        $this->assertSame('transfer', $paginator->items()[0]->type);
        $this->assertSame('outflow', $paginator->items()[0]->flow_kind);
        $this->assertEquals(750.0, (float) $paginator->items()[0]->amount);
    }

    public function test_operating_expense_summary_scope(): void
    {
        $expenseAccount = Account::create([
            'name' => 'مصروف كهرباء',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        $this->createTransfer($this->treasury->id, $expenseAccount->id, 400, 'general', 'فاتورة كهرباء');
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 2000, 'fawry', 'بيع');
        $this->createTransfer($this->treasury->id, $this->expenseClearing->id, 600, 'fawry', 'تكلفة');

        $summary = app(ReportFinanceService::class)->getFinancialSummary([
            'expense_scope' => 'operating',
        ]);

        $this->assertEquals(400.0, $summary['total_expense']);
    }

    public function test_expenses_only_report_includes_tourism_module_and_treasury_names(): void
    {
        $tourismTreasury = Account::create([
            'name' => 'خزينة سياحة للتقرير',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $expenseAccount = Account::create([
            'name' => 'مصروف تسويق سياحي',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $this->createTransfer(
            $tourismTreasury->id,
            $expenseAccount->id,
            850,
            'tourism',
            'حملة سياحية',
            'expense'
        );

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'expenses_only' => true,
            'per_page' => 20,
        ]);

        $this->assertCount(1, $paginator->items());
        $row = $paginator->items()[0];
        $this->assertSame('expense', $row->type);
        $this->assertSame('tourism', $row->module);
        $this->assertSame('خزينة سياحة للتقرير', $row->from_account_name);
        $this->assertSame('مصروف تسويق سياحي', $row->to_account_name);
        $this->assertSame('outflow', $row->flow_kind);
    }

    public function test_operations_office_api_endpoints_return_live_data(): void
    {
        $this->createTransfer($this->incomeClearing->id, $this->treasury->id, 1100, 'fawry', 'API فوري');

        $summaryResponse = $this->getJson('/api/v1/reports/financial/summary?'.http_build_query([
            'module' => ['fawry', 'bus', 'online', 'wallet', 'general'],
        ]));
        $summaryResponse->assertOk()
            ->assertJsonPath('data.total_income', 1100);

        $txResponse = $this->getJson('/api/v1/reports/transactions?'.http_build_query([
            'module' => ['fawry', 'bus', 'online', 'wallet', 'general'],
            'per_page' => 10,
        ]));
        $txResponse->assertOk()
            ->assertJsonPath('data.items.0.flow_kind', 'inflow');
    }

    public function test_transaction_report_returns_multi_currency_details(): void
    {
        $usdAccount = Account::create([
            'name' => 'USD Flight Vault',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flight',
            'created_by' => $this->user->id,
        ]);

        $egpAccount = Account::create([
            'name' => 'EGP Fawry Vault',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $tx = Transaction::query()->create([
            'type' => 'transfer',
            'amount' => 100.0,
            'module' => 'flight',
            'from_account_id' => $usdAccount->id,
            'to_account_id' => $egpAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'تحويل عملة طيران',
        ]);

        Transfer::create([
            'from_account_id' => $usdAccount->id,
            'to_account_id' => $egpAccount->id,
            'amount' => 100.0,
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'exchange_rate' => 50.0,
            'converted_amount' => 5000.0,
            'transaction_id' => $tx->id,
            'created_by' => $this->user->id,
        ]);

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'module' => 'flight',
        ]);

        $items = $paginator->items();
        $this->assertCount(1, $items);
        $item = $items[0];

        $this->assertEquals(100.0, (float) $item->amount);
        $this->assertEquals('USD', $item->from_account_currency);
        $this->assertEquals('EGP', $item->to_account_currency);
        $this->assertEquals('USD', $item->transfer_from_currency);
        $this->assertEquals('EGP', $item->transfer_to_currency);
        $this->assertEquals(50.0, (float) $item->transfer_exchange_rate);
        $this->assertEquals(5000.0, (float) $item->transfer_converted_amount);
    }

    public function test_transaction_report_dynamically_translates_non_transfer_foreign_currency_transactions(): void
    {
        $usdAccount = Account::create([
            'name' => 'USD Flight Vault',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flight',
            'created_by' => $this->user->id,
        ]);

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $tx = Transaction::query()->create([
            'type' => 'income',
            'amount' => 10.0, // 10 USD
            'currency' => 'USD',
            'module' => 'flight',
            'to_account_id' => $usdAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'إيداع دولاري بدون تحويل',
        ]);

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'module' => 'flight',
        ]);

        $items = $paginator->items();
        $item = collect($items)->firstWhere('id', $tx->id);
        $this->assertNotNull($item);
        $this->assertEquals(10.0, (float) $item->amount);
        $this->assertEquals('USD', $item->transaction_currency);
        $this->assertEquals('USD', $item->transfer_from_currency);
        $this->assertEquals('EGP', $item->transfer_to_currency);
        $this->assertEquals(50.0, (float) $item->transfer_exchange_rate);
        $this->assertEquals(500.0, (float) $item->transfer_converted_amount);
    }

    public function test_transaction_report_office_dynamically_translates_non_transfer_foreign_currency_transactions(): void
    {
        $usdAccount = Account::create([
            'name' => 'USD Fawry Vault',
            'type' => 'cashbox',
            'currency' => 'USD',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.0,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $tx = Transaction::query()->create([
            'type' => 'expense',
            'amount' => 20.0, // 20 USD
            'currency' => 'USD',
            'module' => 'fawry',
            'from_account_id' => $usdAccount->id,
            'created_by' => $this->user->id,
            'notes' => 'مصروف دولاري بدون تحويل من فوري',
        ]);

        $paginator = app(ReportFinanceService::class)->getTransactionReport([
            'module' => 'fawry',
        ]);

        $items = $paginator->items();
        $item = collect($items)->firstWhere('id', $tx->id);
        $this->assertNotNull($item);
        $this->assertEquals(20.0, (float) $item->amount);
        $this->assertEquals('USD', $item->transaction_currency);
        $this->assertEquals('USD', $item->transfer_from_currency);
        $this->assertEquals('EGP', $item->transfer_to_currency);
        $this->assertEquals(50.0, (float) $item->transfer_exchange_rate);
        $this->assertEquals(1000.0, (float) $item->transfer_converted_amount);
    }

    private function createTransfer(
        int $fromId,
        int $toId,
        float $amount,
        string $module,
        string $notes,
        string $type = 'transfer'
    ): Transaction {
        return Transaction::query()->create([
            'type' => $type,
            'amount' => $amount,
            'module' => $module,
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'created_by' => $this->user->id,
            'notes' => $notes,
        ]);
    }
}
