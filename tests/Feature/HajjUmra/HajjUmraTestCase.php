<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase as BaseTestCase;

/**
 * Base TestCase for the Hajj & Umrah audit suite.
 *
 * Phase 2.5 — extracts the duplicated setUp() boilerplate from the existing
 * HajjUmraControllerTest / HajjUmraProgramControllerTest /
 * HajjUmraFullModuleE2ETest / HajjUmraProductionE2ETest into a single
 * optional base class.
 *
 * IMPORTANT:
 *  - This is opt-in. Existing tests do NOT inherit from it (they remain
 *    unchanged to avoid scope creep / regression risk).
 *  - New audit tests SHOULD inherit from this class to keep setup uniform.
 *  - All factories are explicit (no model factories), so the setup is
 *    portable across MySQL / SQLite / MariaDB without depending on
 *    seeder state.
 */
abstract class HajjUmraTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $cashier;
    protected Account $treasuryEGP;

    /**
     * Boot the framework, then provision the minimum data needed by every
     * Hajj/Umrah test: admin user + EGP cashbox treasury account.
     *
     * Subclasses may call parent::setUp() first then add their own data, or
     * override setUp() entirely (calling parent::setUp() to keep this baseline).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdminUser();
        $this->cashier = $this->makeCashierUser();
        Sanctum::actingAs($this->admin, ['*']);

        // Wrap treasury creation in the LedgerBalanceMutationGuard so that
        // any module-level guardrails about balance writes are honoured.
        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name'     => 'خزينة الحج - EGP',
                'type'     => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance'  => 500_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'     => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  User factories
     * ========================================================= */

    protected function makeAdminUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name'      => 'HajjUmra Admin',
            'email'     => 'hajj-admin-'.uniqid('', true).'@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ], $overrides));
    }

    protected function makeCashierUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name'      => 'HajjUmra Cashier',
            'email'     => 'hajj-cashier-'.uniqid('', true).'@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'cashier',
            'is_active' => true,
        ], $overrides));
    }

    /* =========================================================
     *  Liquidity / vault factories
     * ========================================================= */

    protected function makeTreasuryAccount(string $currency = 'EGP', float $balance = 500_000.00, array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(function () use ($currency, $balance, $overrides) {
            return Account::query()->create(array_merge([
                'name'      => "خزينة - {$currency}",
                'type'      => AccountType::Cashbox->value,
                'currency'  => $currency,
                'balance'   => $balance,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ], $overrides));
        });
    }

    protected function makeBankAccount(string $currency = 'EGP', float $balance = 250_000.00, array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(function () use ($currency, $balance, $overrides) {
            return Account::query()->create(array_merge([
                'name'      => "بنك - {$currency}",
                'type'      => AccountType::Bank->value,
                'currency'  => $currency,
                'balance'   => $balance,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ], $overrides));
        });
    }

    protected function makeWalletAccount(string $provider = 'vodafone_cash', string $currency = 'EGP', float $balance = 50_000.00, array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(function () use ($provider, $currency, $balance, $overrides) {
            return Account::query()->create(array_merge([
                'name'      => "محفظة - {$provider}",
                'type'      => AccountType::Wallet->value,
                'wallet_provider' => $provider,
                'wallet_number'   => '01000000000',
                'currency'  => $currency,
                'balance'   => $balance,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ], $overrides));
        });
    }

    /* =========================================================
     *  Master-data factories
     * ========================================================= */

    protected function makeCustomer(array $overrides = []): Customer
    {
        // customers.full_name is NOT NULL per the original create migration.
        return Customer::query()->create(array_merge([
            'full_name' => 'عميل تجريبي',
            'phone'     => '01000000000',
            'email'     => 'customer-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ], $overrides));
    }

    protected function makeProgram(array $overrides = []): Program
    {
        // Note: column names are `default_selling_price` / `default_purchase_price`
        // (per migration 2026_05_06_080000_setup_hajj_umra_and_visa_accounting).
        // Fixed in HJ-005 — do NOT regress to `selling_price` / `purchase_price`.
        // Note: programs.mecca_nights is NOT NULL per create_programs_table.
        return Program::query()->create(array_merge([
            'program_name'            => 'برنامج حج تجريبي',
            'program_type'            => 'hajj',
            'total_nights'            => 14,
            'mecca_nights'            => 8,
            'medina_nights'           => 6,
            'accommodation_type'      => 'DOUBLE',
            'mecca_hotel_name'        => 'فندق مكة',
            'medina_hotel_name'       => 'فندق المدينة',
            'departure_date'          => now()->addDays(60)->toDateString(),
            'return_date'             => now()->addDays(74)->toDateString(),
            'airline'                 => 'Test Air',
            'executing_company'       => 'شركة تنفيذ',
            'departure_point'         => 'CAI',
            'default_selling_price'   => 50000.00,
            'default_purchase_price'  => 42000.00,
            'is_active'               => true,
            'created_by'              => $this->admin->id,
        ], $overrides));
    }

    protected function makeSupplier(array $overrides = []): UmrahSupplier
    {
        $account = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'حساب مورّد - USD',
                'type'      => AccountType::Supplier->value,
                'currency'  => 'USD',
                'balance'   => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        return UmrahSupplier::query()->create(array_merge([
            'name'      => 'مورّد تجريبي',
            'phone'     => '+966555000000',
            'account_id' => $account->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeExecutingCompany(array $overrides = []): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create(array_merge([
            'name'           => 'شركة تنفيذ تجريبية',
            'license_number' => 'TEST-EXC-'.uniqid(),
            'phone'          => '+20100000000',
            'is_active'      => true,
        ], $overrides));
    }

    /**
     * Seed an ExchangeRate row for the given from→to pair.
     *
     * Tests that create a cross-currency booking (e.g., EGP treasury + USD
     * supplier, or USD treasury + EGP customer payment) trigger
     * CurrencyService::convert() in HajjUmraBookingService::create() and
     * addPayment(). Without a current-date rate row, convert() throws
     * "لا يوجد سعر صرف متاح من X إلى Y" and the booking/payment fails
     * with a 422.
     *
     * Common usage in setUp():
     *   $this->seedExchangeRate('USD', 'EGP', 50.0);
     *   $this->seedExchangeRate('EGP', 'USD', 1/50.0);
     *   $this->seedExchangeRate('SAR', 'EGP', 13.5);
     */
    protected function seedExchangeRate(string $from, string $to, float $rate, ?string $date = null): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'from_currency'  => strtoupper($from),
            'to_currency'    => strtoupper($to),
            'rate'           => $rate,
            'effective_date' => $date ?? now()->toDateString(),
            'is_active'      => true,
            'created_by'     => $this->admin->id,
        ]);
    }
}
