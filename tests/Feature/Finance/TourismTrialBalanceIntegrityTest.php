<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryService;
use App\Services\Setting\PrintSettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TourismTrialBalanceIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected TreasuryService $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Tourism Auditor',
            'email' => 'tourism-auditor@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->treasury = app(TreasuryService::class);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_negative_flight_system_not_double_counted_in_tourism_trial_balance(): void
    {
        DB::table('flight_systems')->insert([
            'name' => 'System Overdrawn',
            'code' => 'SYS-OD',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => -8000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receivables = $this->treasury->calculateReceivablesAndPayables('tourism');
        $trialBalance = $this->treasury->getTrialBalance();

        $this->assertEquals(0.0, $trialBalance['details']['flight_balances']);
        $this->assertEquals(8000.0, $receivables['due_from_us']);
        $this->assertEquals(8000.0, $trialBalance['due_from_us']);
    }

    public function test_positive_flight_system_in_balances_not_in_receivables(): void
    {
        DB::table('flight_systems')->insert([
            'name' => 'System Prepaid',
            'code' => 'SYS-PP',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 12000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receivables = $this->treasury->calculateReceivablesAndPayables('tourism');
        $trialBalance = $this->treasury->getTrialBalance();

        $this->assertEquals(12000.0, $trialBalance['details']['flight_balances']);
        $this->assertEquals(0.0, $receivables['due_to_us']);
    }

    public function test_tourism_customer_receivable_counts_in_due_to_us_only(): void
    {
        $account = Account::query()->create([
            'name' => 'ذممة عميل — سياحة · 01011112222',
            'type' => AccountType::Customer,
            'balance' => 4500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $account->id,
            'full_name' => 'عميل سياحة',
            'phone' => '01011112222',
            'created_by' => $this->user->id,
        ]);

        $trialBalance = $this->treasury->getTrialBalance();

        $this->assertEquals(4500.0, $trialBalance['due_to_us']);
    }

    public function test_tourism_profits_from_all_modules_are_summed(): void
    {
        $customerId = Customer::query()->create([
            'full_name' => 'عميل أرباح',
            'phone' => '01033334444',
            'created_by' => $this->user->id,
        ])->id;

        DB::table('flight_bookings')->insert([
            'booking_reference' => 'FLT-P-1',
            'booking_channel_type' => 'GDS',
            'booking_channel_provider' => 'Amadeus',
            'customer_id' => $customerId,
            'agent_name' => 'Self',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->toDateString(),
            'departure_time' => '08:00',
            'trip_type' => 'one_way',
            'airline' => 'MS',
            'passenger_count' => 1,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'profit' => 500.0,
            'foreign_currency' => 'EGP',
            'currency' => 'EGP',
            'purchase_price_foreign' => 1000.0,
            'purchase_price_egp' => 1000.0,
            'status' => 'CONFIRMED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hajj_umra_bookings')->insert([
            'customer_id' => $customerId,
            'program_id' => DB::table('programs')->insertGetId([
                'program_name' => 'برنامج اختبار',
                'program_type' => 'UMRA',
                'total_nights' => 7,
                'accommodation_type' => 'DOUBLE',
                'mecca_hotel_name' => 'فندق تجريبي',
                'mecca_nights' => 7,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(17)->toDateString(),
                'airline' => 'MS',
                'executing_company' => 'شركة تجريبية',
                'departure_point' => 'CAI',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'status' => 'confirmed',
            'agent_name' => 'موظف تجريبي',
            'purchase_price' => 8000.0,
            'selling_price' => 10000.0,
            'profit' => 2000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visaDetailId = DB::table('visa_details')->insertGetId([
            'country' => 'الإمارات',
            'visa_type' => 'TOURIST',
            'duration' => '30 يوم',
            'entry_type' => 'SINGLE',
            'executing_company' => 'شركة تجريبية',
            'executing_agent' => 'وكيل تجريبي',
            'status' => 'APPROVED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('visa_bookings')->insert([
            'customer_id' => $customerId,
            'visa_detail_id' => $visaDetailId,
            'status' => 'approved',
            'agent_name' => 'موظف تجريبي',
            'purchase_price' => 500.0,
            'selling_price' => 800.0,
            'profit' => 300.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profits = $this->treasury->calculateDynamicProfits('tourism');

        $this->assertEquals(2800.0, $profits);
    }

    public function test_tourism_operating_expense_reduces_liquidity_and_profits_equally(): void
    {
        app(PrintSettingService::class)->update(['base_capital' => 0.0]);

        $cashbox = Account::query()->create([
            'name' => 'خزينة السياحة',
            'type' => AccountType::Cashbox,
            'balance' => 20000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $expense = Account::query()->create([
            'name' => 'مصروفات تشغيل السياحة',
            'type' => 'expense',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        $before = $this->treasury->getTrialBalance();
        $varianceBefore = (float) $before['variance'];

        app(TransactionService::class)->recordTransfer([
            'from_account_id' => $cashbox->id,
            'to_account_id' => $expense->id,
            'amount' => 1200.0,
            'type' => 'expense',
            'module' => 'tourism',
            'notes' => 'مصروف تشغيل سياحة',
            'created_by' => $this->user->id,
        ]);

        $after = $this->treasury->getTrialBalance();

        $this->assertEquals($before['total_liquidity'] - 1200.0, $after['total_liquidity']);
        $this->assertEquals($before['profits'] - 1200.0, $after['profits']);
        $this->assertEqualsWithDelta($varianceBefore, (float) $after['variance'], 0.01);
    }

    public function test_tourism_trial_balance_equation_is_internally_consistent(): void
    {
        app(PrintSettingService::class)->update(['base_capital' => 100000.0]);

        DB::table('flight_systems')->insert([
            'name' => 'Tourism Asset',
            'code' => 'T-AST',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 30000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Account::query()->create([
            'name' => 'بنك السياحة',
            'type' => AccountType::Bank,
            'balance' => 50000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — مدين · 01055556666',
            'type' => AccountType::Customer,
            'balance' => 7000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'visas',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'مدين سياحة',
            'phone' => '01055556666',
            'created_by' => $this->user->id,
        ]);

        $tb = $this->treasury->getTrialBalance();

        $computedCurrent = ($tb['total_balances'] + $tb['total_liquidity'] + $tb['due_to_us']) - $tb['due_from_us'];
        $computedExpected = $tb['base_capital'] + $tb['profits'];

        $this->assertEqualsWithDelta($computedCurrent, (float) $tb['current_capital'], 0.01);
        $this->assertEqualsWithDelta($computedExpected, (float) $tb['expected_capital'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $tb['current_capital'] - (float) $tb['expected_capital'],
            (float) $tb['variance'],
            0.01
        );
    }

    public function test_tourism_api_trial_balance_endpoint_returns_complete_structure(): void
    {
        $response = $this->getJson('/api/v1/reports/trial-balance');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'rates',
                    'details' => ['flight_balances', 'hajj_umra_balances', 'visa_balances'],
                    'total_balances',
                    'total_liquidity',
                    'due_to_us',
                    'due_from_us',
                    'current_capital',
                    'base_capital',
                    'gross_profits',
                    'operating_expenses',
                    'profits',
                    'expected_capital',
                    'variance',
                    'status',
                ],
            ]);
    }
}
