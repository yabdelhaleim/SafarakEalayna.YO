<?php

namespace Tests\Unit\Finance;

use App\Models\User;
use App\Models\Employee;
use App\Services\DashboardService;
use App\Services\Finance\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OfficeProfitAndTrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Employee $employee;
    protected TreasuryService $treasuryService;
    protected DashboardService $dashboardService;

    protected int $customerId;
    protected int $inventoryId;
    protected int $walletTypeId;
    protected int $cashAccountId;
    protected int $walletAccountId;
    protected int $serviceTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Finance Tester',
            'email' => 'finance-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Employee Tester',
            'status' => 'active',
        ]);

        Auth::login($this->user);

        $this->customerId = DB::table('customers')->insertGetId([
            'full_name' => 'Customer 1',
            'phone' => '01000000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyId = DB::table('bus_companies')->insertGetId([
            'name' => 'Company 1',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inventoryId = DB::table('bus_inventories')->insertGetId([
            'company_id' => $companyId,
            'route' => 'Route 1',
            'travel_date' => now()->toDateString(),
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 350,
            'selling_price' => 500,
            'payment_type' => 'cash',
            'total_cost' => 3500,
            'amount_paid' => 3500,
            'remaining_debt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existingWalletType = DB::table('wallet_types')->where('code', 'vodafone_cash')->first();
        $this->walletTypeId = $existingWalletType ? $existingWalletType->id : DB::table('wallet_types')->insertGetId([
            'code' => 'vodafone_cash',
            'name' => 'Vodafone Cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cashAccountId = DB::table('accounts')->insertGetId([
            'name' => 'Main Cashbox',
            'type' => 'cashbox',
            'module_type' => 'office',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->walletAccountId = DB::table('accounts')->insertGetId([
            'name' => 'VF Wallet Account',
            'type' => 'wallet',
            'module_type' => 'office',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->serviceTypeId = DB::table('online_service_types')->insertGetId([
            'code' => 'TEST_SERVICE',
            'name_ar' => 'خدمة تجريبية',
            'name_en' => 'Test Service',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->treasuryService = app(TreasuryService::class);
        $this->dashboardService = app(DashboardService::class);
    }

    public function test_office_dynamic_profits_calculates_sum_of_modules_accurately(): void
    {
        // 1. Bus booking: profit = 150
        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventoryId,
            'customer_id' => $this->customerId,
            'employee_id' => $this->employee->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
            'profit' => 150,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Fawry transaction: profit = 410
        DB::table('fawry_transactions')->insert([
            'client_name' => 'Test Client',
            'operation_type' => 'payment',
            'client_amount' => 1000,
            'fawry_price' => 590,
            'selling_price' => 1000,
            'amount' => 1000,
            'profit' => 410,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Wallet transaction: transfer amount = 350, service fee (profit) = 5
        DB::table('wallet_transactions')->insert([
            'wallet_type_id' => $this->walletTypeId,
            'customer_name' => 'Wallet Client',
            'wallet_number' => '01000000000',
            'type' => 'send',
            'amount' => 350,
            'service_fee' => 5,
            'total_amount' => 355,
            'amount_paid' => 355,
            'wallet_account_id' => $this->walletAccountId,
            'cash_account_id' => $this->cashAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Online transaction: profit = 0
        DB::table('online_transactions')->insert([
            'service_type_code' => 'TEST_SERVICE',
            'purchase_price' => 200,
            'selling_price' => 200,
            'amount_paid' => 200,
            'profit' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashAccountId,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Gross profits should be 150 (bus) + 410 (fawry) + 5 (wallet) + 0 (online) = 565.00
        $grossProfits = $this->treasuryService->calculateDynamicProfits('office');
        $this->assertEquals(565.00, $grossProfits);
    }

    public function test_office_division_net_profits_deducts_operating_expenses(): void
    {
        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventoryId,
            'customer_id' => $this->customerId,
            'employee_id' => $this->employee->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
            'profit' => 150,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fawry_transactions')->insert([
            'client_name' => 'Test Client',
            'operation_type' => 'payment',
            'client_amount' => 1000,
            'fawry_price' => 590,
            'selling_price' => 1000,
            'amount' => 1000,
            'profit' => 410,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_transactions')->insert([
            'wallet_type_id' => $this->walletTypeId,
            'customer_name' => 'Wallet Client',
            'wallet_number' => '01000000000',
            'type' => 'send',
            'amount' => 350,
            'service_fee' => 5,
            'total_amount' => 355,
            'amount_paid' => 355,
            'wallet_account_id' => $this->walletAccountId,
            'cash_account_id' => $this->cashAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Net profit with 0 expenses = 565
        $netProfits = $this->treasuryService->calculateDivisionNetProfits('office');
        $this->assertEquals(565.00, $netProfits);
    }

    public function test_dashboard_service_returns_net_profit_and_gross_breakdown(): void
    {
        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventoryId,
            'customer_id' => $this->customerId,
            'employee_id' => $this->employee->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
            'profit' => 150,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fawry_transactions')->insert([
            'client_name' => 'Test Client',
            'operation_type' => 'payment',
            'client_amount' => 1000,
            'fawry_price' => 590,
            'selling_price' => 1000,
            'amount' => 1000,
            'profit' => 410,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_transactions')->insert([
            'wallet_type_id' => $this->walletTypeId,
            'customer_name' => 'Wallet Client',
            'wallet_number' => '01000000000',
            'type' => 'send',
            'amount' => 350,
            'service_fee' => 5,
            'total_amount' => 355,
            'amount_paid' => 355,
            'wallet_account_id' => $this->walletAccountId,
            'cash_account_id' => $this->cashAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->dashboardService->getFullDashboard();
        $this->assertArrayHasKey('office_summary', $summary);

        $office = $summary['office_summary'];

        // Wallet revenue is commission (5), profit is (5), and volume is transfer (350)
        $this->assertEquals(5.00, $office['wallet']['revenue']);
        $this->assertEquals(5.00, $office['wallet']['profit']);
        $this->assertEquals(350.00, $office['wallet']['volume']);

        // Check office summary contains gross_profit, operating_expenses, and total_profit (net)
        $this->assertEquals(565.00, $office['gross_profit']);
        $this->assertEquals(0.00, $office['operating_expenses']);
        $this->assertEquals(565.00, $office['total_profit']);
    }
}
