<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet\WalletType;
use App\Services\Bus\BusBookingService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryService;
use App\Services\Setting\PrintSettingService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeTrialBalanceIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected TreasuryService $treasury;

    protected Account $cashbox;

    protected BusInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Office Auditor',
            'email' => 'office-auditor@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Auth::login($this->user);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = app(TreasuryService::class);

        $this->cashbox = Account::query()->create([
            'name' => 'خزينة المكتب — اختبار',
            'type' => AccountType::Cashbox,
            'balance' => 50000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $company = BusCompany::query()->create([
            'name' => 'شركة باص اختبار',
            'phone' => '01000000001',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->inventory = BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 20,
            'available_tickets' => 20,
            'cost_per_ticket' => 80.0,
            'selling_price' => 120.0,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 1600.0,
            'amount_paid' => 0.0,
            'remaining_debt' => 1600.0,
            'created_by' => $this->user->id,
        ]);

        foreach (['bus', 'fawry', 'online', 'wallet', 'general'] as $module) {
            app(LedgerClearingAccounts::class)->incomeContraIdForModule($module);
            app(LedgerClearingAccounts::class)->expenseContraIdForModule($module);
        }
    }

    public function test_bus_cancel_credit_with_penalties_sets_partially_refunded_on_booking(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل آجل',
            'phone' => '01001112233',
            'created_by' => $this->user->id,
        ]);

        $bookingService = app(BusBookingService::class);
        $booking = $bookingService->createBooking([
            'inventory_id' => $this->inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        $refund = $bookingService->cancelBooking($booking, [
            'company_penalty' => 20.0,
            'office_penalty' => 30.0,
        ]);

        $booking->refresh();

        $this->assertSame(BusBookingStatus::PartiallyRefunded, $booking->status);
        $this->assertSame('processed', $refund->status);
        $this->assertEquals(0.0, (float) $refund->refund_amount);
        $this->assertDatabaseHas('bus_bookings', [
            'id' => $booking->id,
            'status' => 'partially_refunded',
        ]);
    }

    public function test_bus_cancel_paid_booking_sets_refunded_and_refund_amount(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل دافع',
            'phone' => '01004445566',
            'created_by' => $this->user->id,
        ]);

        $bookingService = app(BusBookingService::class);
        $booking = $bookingService->createBooking([
            'inventory_id' => $this->inventory->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
        ]);

        $bookingService->payBooking($booking, [
            'amount' => 240.0,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
        ]);

        $refund = $bookingService->cancelBooking($booking->fresh(), [
            'company_penalty' => 0.0,
            'office_penalty' => 40.0,
            'account_id' => $this->cashbox->id,
        ]);

        $booking->refresh();

        $this->assertSame(BusBookingStatus::Refunded, $booking->status);
        $this->assertSame('processed', $refund->status);
        $this->assertEquals(200.0, (float) $refund->refund_amount);
        $this->assertDatabaseHas('bus_bookings', [
            'id' => $booking->id,
            'status' => 'refunded',
        ]);
    }

    public function test_office_profits_from_all_modules_are_summed(): void
    {
        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventory->id,
            'customer_id' => Customer::query()->create([
                'full_name' => 'عميل باص',
                'phone' => '01007778899',
                'created_by' => $this->user->id,
            ])->id,
            'employee_id' => Employee::query()->value('id'),
            'quantity' => 1,
            'unit_price' => 120.0,
            'total_price' => 120.0,
            'paid_amount' => 120.0,
            'payment_status' => 'paid',
            'profit' => 40.0,
            'status' => 'paid',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fawry_transactions')->insert([
            'client_name' => 'عميل فوري',
            'operation_type' => 'payment',
            'client_amount' => 500.0,
            'fawry_price' => 480.0,
            'selling_price' => 510.0,
            'profit' => 30.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 510.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('online_transactions')->insert([
            'service_type_id' => DB::table('online_service_types')->insertGetId([
                'name_ar' => 'شحن',
                'name_en' => 'Recharge',
                'code' => 'recharge',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'customer_name' => 'عميل أونلاين',
            'purchase_price' => 90.0,
            'selling_price' => 110.0,
            'profit' => 20.0,
            'amount_paid' => 110.0,
            'account_id' => $this->cashbox->id,
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_transactions')->insert([
            'wallet_type_id' => WalletType::query()->create([
                'name' => 'فودافون',
                'code' => 'vodafone',
                'is_active' => true,
                'sort_order' => 1,
            ])->id,
            'customer_name' => 'عميل محفظة',
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 500.0,
            'service_fee' => 15.0,
            'total_amount' => 515.0,
            'amount_paid' => 515.0,
            'wallet_account_id' => $this->cashbox->id,
            'cash_account_id' => $this->cashbox->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profits = $this->treasury->calculateDynamicProfits('office');

        // 40 bus + 30 fawry + 20 online + 15 wallet fees
        $this->assertEquals(105.0, $profits);
    }

    public function test_wallet_receive_total_amount_is_amount_minus_fee(): void
    {
        $walletAccount = Account::query()->create([
            'name' => 'محفظة اختبار',
            'type' => AccountType::Wallet,
            'balance' => 10000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $walletType = WalletType::query()->create([
            'name' => 'إنستاباي',
            'code' => 'instapay',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $tx = app(WalletTransactionService::class)->createTransaction([
            'wallet_type_id' => $walletType->id,
            'customer_name' => 'مرسل خارجي',
            'wallet_number' => '01155552222',
            'type' => WalletTransactionType::Receive->value,
            'amount' => 800.0,
            'service_fee' => 20.0,
            'wallet_account_id' => $walletAccount->id,
            'cash_account_id' => $this->cashbox->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals(780.0, (float) $tx->total_amount);
    }

    public function test_record_transfer_moves_balance_between_office_accounts(): void
    {
        $secondCashbox = Account::query()->create([
            'name' => 'خزينة فرع 2',
            'type' => AccountType::Cashbox,
            'balance' => 10000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $beforeFrom = (float) $this->cashbox->fresh()->balance;
        $beforeTo = (float) $secondCashbox->fresh()->balance;

        app(TransactionService::class)->recordTransfer([
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $secondCashbox->id,
            'amount' => 5000.0,
            'module' => 'office',
            'notes' => 'تحويل اختبار',
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals($beforeFrom - 5000.0, (float) $this->cashbox->fresh()->balance);
        $this->assertEquals($beforeTo + 5000.0, (float) $secondCashbox->fresh()->balance);
    }

    public function test_office_trial_balance_equation_is_internally_consistent(): void
    {
        app(PrintSettingService::class)->update(['office_base_capital' => 80000.0]);

        Account::query()->create([
            'name' => 'بنك المكتب',
            'type' => AccountType::Bank,
            'balance' => 25000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — مدين · 01066667777',
            'type' => AccountType::Customer,
            'balance' => 3500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'مدين مكتب',
            'phone' => '01066667777',
            'created_by' => $this->user->id,
        ]);

        $tb = $this->treasury->getOfficeTrialBalance();

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

    public function test_office_api_trial_balance_endpoint_returns_complete_structure(): void
    {
        $response = $this->getJson('/api/v1/reports/office-trial-balance');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'rates',
                    'details',
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
