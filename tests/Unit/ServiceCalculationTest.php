<?php

namespace Tests\Unit;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bus\BusInventoryService;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\FinanceOperationsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServiceCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Calc Tester',
            'email' => 'calc-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Auth::login($this->user);
    }

    public function test_bus_inventory_deferred_total_cost_and_remaining_debt(): void
    {
        $company = BusCompany::query()->create([
            'name' => 'شركة حسابية',
            'is_active' => true,
        ]);

        $inventory = app(BusInventoryService::class)->createInventory([
            'company_id' => $company->id,
            'route' => 'Cairo - Alex',
            'travel_date' => now()->addWeek()->toDateString(),
            'departure_time' => '08:00',
            'total_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 120,
            'payment_type' => 'deferred',
        ]);

        $this->assertSame(500.0, (float) $inventory->total_cost);
        $this->assertSame(500.0, (float) $inventory->remaining_debt);
        $this->assertSame(0.0, (float) $inventory->amount_paid);
    }

    public function test_bus_inventory_pay_debt_reduces_remaining_and_increases_paid(): void
    {
        $company = BusCompany::query()->create([
            'name' => 'شركة سداد',
            'is_active' => true,
        ]);

        $vault = Account::query()->create([
            'name' => 'خزينة باص',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'module_type' => TransactionModule::Bus->value,
        ]);

        $inventory = app(BusInventoryService::class)->createInventory([
            'company_id' => $company->id,
            'route' => 'Cairo - Tanta',
            'travel_date' => now()->addWeek()->toDateString(),
            'total_tickets' => 5,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => 'deferred',
        ]);

        app(BusInventoryService::class)->payInventoryDebt($inventory, [
            'amount' => 200,
            'account_id' => $vault->id,
            'notes' => 'سداد جزئي',
        ]);

        $inventory->refresh();

        $this->assertSame(200.0, (float) $inventory->amount_paid);
        $this->assertSame(300.0, (float) $inventory->remaining_debt);
    }

    public function test_financial_summary_profit_and_margin(): void
    {
        $today = now()->toDateString();

        Transaction::query()->create([
            'type' => TransactionType::Income->value,
            'module' => TransactionModule::General->value,
            'amount' => 1000,
            'date' => $today,
            'created_at' => now(),
            'created_by' => $this->user->id,
        ]);

        Transaction::query()->create([
            'type' => TransactionType::Expense->value,
            'module' => TransactionModule::General->value,
            'amount' => 400,
            'date' => $today,
            'created_at' => now(),
            'created_by' => $this->user->id,
        ]);

        $summary = app(FinancialReportService::class)->getFinancialSummary([
            'from_date' => $today,
            'to_date' => now()->endOfDay()->toDateTimeString(),
        ]);

        $this->assertSame(600.0, (float) $summary['profit']);
        $this->assertEqualsWithDelta(60.0, (float) $summary['profit_margin'], 0.01);
    }

    public function test_finance_operations_report_summary_totals(): void
    {
        $machine = \App\Models\Fawry\FawryMachine::query()->create([
            'name' => 'ماكينة اختبار',
            'type' => 'fawry',
            'balance' => 100,
            'is_active' => true,
        ]);

        $vault = Account::query()->create([
            'name' => 'خزينة فوري',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 2000,
            'is_active' => true,
            'module_type' => 'fawry',
        ]);

        Transaction::query()->create([
            'type' => TransactionType::Transfer->value,
            'module' => TransactionModule::Fawry->value,
            'amount' => 250,
            'from_account_id' => $vault->id,
            'to_account_id' => null,
            'related_type' => \App\Models\Fawry\FawryMachine::class,
            'related_id' => $machine->id,
            'date' => now()->toDateString(),
            'created_at' => now(),
            'created_by' => $this->user->id,
            'notes' => 'شحن ماكينة',
        ]);

        $report = app(FinanceOperationsReportService::class)->report([
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(250.0, (float) $report['summary']['total_recharges']);
        $this->assertSame(1, (int) $report['summary']['count_recharges']);
    }
}
