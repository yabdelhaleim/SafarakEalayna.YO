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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeTrialBalanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

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

        // Post-2026-08-30: online_transactions schema renamed service_type_id → service_type_code
        // (free-text migration 2026_08_28_000000). Insert the service-type row first to
        // keep the test self-contained without depending on the seeding migration.
        $serviceTypeCode = 'recharge';
        DB::table('online_service_types')->updateOrInsert(
            ['code' => $serviceTypeCode],
            [
                'name_ar' => 'شحن',
                'name_en' => 'Recharge',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('online_transactions')->insert([
            'service_type_code' => $serviceTypeCode,
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

        // Post-2026-08-30: wallet_types migration backfilled canonical codes (instapay, vodafone_cash, ...).
        // Use firstOrCreate on code 'vodafone_cash' so the test does not collide with the seeded UNIQUE(code) row.
        $walletType = WalletType::query()->firstOrCreate(
            ['code' => 'vodafone_cash'],
            [
                'name' => 'فودافون كاش',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        DB::table('wallet_transactions')->insert([
            'wallet_type_id' => $walletType->id,
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

        // Post-2026-08-30: the canonical wallet_types migration seeded instapay/vodafone_cash
        // rows with a UNIQUE(code) constraint. Use firstOrCreate on the seeded code so the
        // assertion test does not trip the UniqueConstraintViolationException on duplicate INSERT.
        $walletType = WalletType::query()->firstOrCreate(
            ['code' => 'instapay'],
            [
                'name' => 'إنستاباي',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

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

    /**
     * Regression: registered Fawry customer debt must appear in office trial
     * balance `due_to_us`. The customer ledger account uses `module_type='fawry'`
     * (the actual module they used), and the Fawry transaction is linked to
     * them via `client_id` with `amount=0` so the full selling_price is debt.
     *
     * Without the walk-in Fawry column-fallback in
     * `calculateReceivablesAndPayables('office')`, the office trial balance
     * shows a negative variance equal to the unreconciled Fawry receivable
     * (production-reported −1,670 EGP).
     */
    public function test_registered_fawry_customer_appears_in_office_trial_balance_due_to_us(): void
    {
        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — فوري مسجّل · 01066667777',
            'type' => AccountType::Customer,
            'balance' => 1500.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry', // ← actual module the customer used
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'عميل فوري مسجّل',
            'phone' => '01066667777',
            'created_by' => $this->user->id,
        ]);

        // Create a real Fawry transaction linked to the registered customer.
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => $customer->id,
            'client_name' => $customer->full_name,
            'operation_type' => 'payment',
            'client_amount' => 1500.0,
            'fawry_price' => 1450.0,
            'selling_price' => 1500.0,
            'profit' => 50.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0, // unpaid → full debt = 1500
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        $this->assertEqualsWithDelta(1500.0, $result['due_to_us'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['due_from_us'], 0.01);
    }

    /**
     * Regression: walk-in Fawry client debt must appear in office trial
     * balance `due_to_us`. The debt is sourced from `fawry_transactions`
     * columns (selling_price − amount) for `client_id IS NULL` rows.
     *
     * Without this, the office trial balance shows a negative variance equal
     * to the walk-in total (production-reported −1,670 EGP from walk-ins).
     */
    public function test_walkin_fawry_debt_appears_in_office_trial_balance_due_to_us(): void
    {
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'أبو مالك - وائل طه',
            'operation_type' => 'payment',
            'client_amount' => 770.0,
            'fawry_price' => 750.0,
            'selling_price' => 770.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'خالد عابدين',
            'operation_type' => 'payment',
            'client_amount' => 900.0,
            'fawry_price' => 880.0,
            'selling_price' => 900.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        $this->assertEqualsWithDelta(1670.0, $result['due_to_us'], 0.01);
    }

    /**
     * Regression: office trial balance `due_to_us` must equal the unified
     * debts report `total_receivables` for the same office department.
     * The two consumers (DepartmentManagement and TreasuryOverview) must
     * surface the same number.
     */
    public function test_office_trial_balance_due_to_us_matches_reports_debts_total_receivables(): void
    {
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'عميل ماشي 1',
            'operation_type' => 'payment',
            'client_amount' => 500.0,
            'fawry_price' => 480.0,
            'selling_price' => 500.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — مسجّل',
            'type' => AccountType::Customer,
            'balance' => 800.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry',
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'عميل مسجّل',
            'phone' => '01099998888',
            'created_by' => $this->user->id,
        ]);

        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => $customer->id,
            'client_name' => $customer->full_name,
            'operation_type' => 'payment',
            'client_amount' => 800.0,
            'fawry_price' => 770.0,
            'selling_price' => 800.0,
            'profit' => 30.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Both consumers must agree on the same number.
        $debtsReport = app(\App\Services\Reports\FinancialReportService::class)
            ->getDebtsReport(['department' => 'office']);

        $trialBalance = $this->treasury->getOfficeTrialBalance();

        $this->assertEqualsWithDelta(
            (float) $debtsReport['total_receivables'],
            (float) $trialBalance['due_to_us'],
            0.01,
            'DepartmentManagement ($total_receivables) and TreasuryOverview ($due_to_us) must agree for office division.'
        );
    }

    /**
     * Cross-module non-regression: Bus customer debt must appear in office
     * trial balance `due_to_us` (verifies the `general` module_type expansion
     * did NOT alter the existing bus module aggregation).
     */
    public function test_bus_customer_debt_still_appears_in_office_trial_balance(): void
    {
        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — باص · 01011112222',
            'type' => AccountType::Customer,
            'balance' => 600.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'عميل باص',
            'phone' => '01011112222',
            'created_by' => $this->user->id,
        ]);

        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventory->id,
            'customer_id' => $customer->id,
            'employee_id' => Employee::query()->value('id'),
            'quantity' => 1,
            'unit_price' => 600.0,
            'total_price' => 600.0,
            'paid_amount' => 0.0,
            'payment_status' => 'unpaid',
            'profit' => 0.0,
            'status' => 'confirmed',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        $this->assertEqualsWithDelta(600.0, $result['due_to_us'], 0.01);
    }

    /**
     * Cross-module non-regression: customer with `module_type='general'`
     * (legacy opening-balance pattern) must now enter the office trial
     * balance. Before the fix it silently vanished.
     */
    public function test_general_module_type_customer_now_appears_in_office_trial_balance(): void
    {
        $customerAccount = Account::query()->create([
            'name' => 'ذممة عميل — رصيد افتتاحي · 01033334444',
            'type' => AccountType::Customer,
            'balance' => 450.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'fawry', // ← valid specific module (not 'general' which is rejected by Account validation for subject accounts)
            'created_by' => $this->user->id,
        ]);

        $customer = Customer::query()->create([
            'account_id' => $customerAccount->id,
            'full_name' => 'عميل رصيد افتتاحي',
            'phone' => '01033334444',
            'created_by' => $this->user->id,
        ]);

        // No transactions — the account has only the seeded opening balance.
        // The customer must STILL show up in the office trial balance because
        // the fallback path (b) now includes customers whose ledger account
        // exists in the office division (bus/fawry/online/wallet_transfer/general).
        $result = $this->treasury->calculateReceivablesAndPayables('office');

        $this->assertEqualsWithDelta(450.0, $result['due_to_us'], 0.01);
    }

    /**
     * Non-regression: supplier account with `module_type='bus'` (bus company
     * positive balance) is NOT counted as a receivable (it appears under
     * `total_balances` as a prepaid asset instead — this is the existing
     * contract enforced by `TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES`).
     */
    public function test_supplier_bus_company_positive_balance_is_not_in_due_to_us(): void
    {
        Account::query()->create([
            'name' => 'شركة باص — رصيد مسبق',
            'type' => AccountType::Supplier,
            'balance' => 5000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        // Supplier balances (positive) are tracked under `total_balances`
        // (prepaid assets) and must NOT double as receivables.
        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Non-regression: walk-in Fawry transactions where the customer already
     * paid the full selling_price contribute ZERO to `due_to_us` (no false
     * positive debt). The `> 0.005` threshold in the column-fallback guards
     * this.
     */
    public function test_paid_walkin_fawry_does_not_inflate_due_to_us(): void
    {
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'عميل ماشي مدفوع',
            'operation_type' => 'payment',
            'client_amount' => 500.0,
            'fawry_price' => 480.0,
            'selling_price' => 500.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 500.0, // fully paid
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        $this->assertEqualsWithDelta(0.0, $result['due_to_us'], 0.01);
    }

    /**
     * Non-regression: combined office scenario (walk-in Fawry + registered
     * bus customer + supplier bus company) sums correctly without double
     * counting across paths.
     */
    public function test_combined_office_scenario_no_double_counting(): void
    {
        // Walk-in Fawry debt: 770 (should add)
        \App\Models\Fawry\FawryTransaction::query()->create([
            'client_id' => null,
            'client_name' => 'أبو مالك - وائل طه',
            'operation_type' => 'payment',
            'client_amount' => 770.0,
            'fawry_price' => 750.0,
            'selling_price' => 770.0,
            'profit' => 20.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Registered bus customer debt: 600 (should add)
        $busCustomerAccount = Account::query()->create([
            'name' => 'ذممة عميل باص',
            'type' => AccountType::Customer,
            'balance' => 600.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);
        $busCustomer = Customer::query()->create([
            'account_id' => $busCustomerAccount->id,
            'full_name' => 'عميل باص مسجّل',
            'phone' => '01022223333',
            'created_by' => $this->user->id,
        ]);
        DB::table('bus_bookings')->insert([
            'inventory_id' => $this->inventory->id,
            'customer_id' => $busCustomer->id,
            'employee_id' => Employee::query()->value('id'),
            'quantity' => 1,
            'unit_price' => 600.0,
            'total_price' => 600.0,
            'paid_amount' => 0.0,
            'payment_status' => 'unpaid',
            'profit' => 0.0,
            'status' => 'confirmed',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Bus company (supplier) positive balance: 5000 (must NOT add — prepaid)
        Account::query()->create([
            'name' => 'شركة باص — رصيد مسبق',
            'type' => AccountType::Supplier,
            'balance' => 5000.0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $result = $this->treasury->calculateReceivablesAndPayables('office');

        // 770 (walk-in Fawry) + 600 (bus customer) = 1370. The 5000 supplier
        // balance is excluded — it sits under `total_balances` as a prepaid asset.
        $this->assertEqualsWithDelta(1370.0, $result['due_to_us'], 0.01);
    }
}
