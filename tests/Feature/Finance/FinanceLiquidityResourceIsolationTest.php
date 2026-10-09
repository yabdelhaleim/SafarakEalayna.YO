<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\BusBanks\BusBankResource;
use App\Filament\Admin\Resources\BusWallets\BusWalletResource;
use App\Filament\Admin\Resources\FlightWallets\FlightWalletResource;
use App\Filament\Admin\Resources\HajjUmraBankAccounts\HajjUmraBankAccountResource;
use App\Filament\Admin\Resources\HajjUmraWallets\HajjUmraWalletResource;
use App\Filament\Admin\Resources\OnlineBankAccounts\OnlineBankAccountResource;
use App\Filament\Admin\Resources\OnlineWallets\OnlineWalletResource;
use App\Filament\Admin\Resources\VisaBanks\VisaBankAccountResource;
use App\Filament\Admin\Resources\VisaWallets\VisaWalletResource;
use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP 2 — Cross-Resource Liquidity Isolation
 *
 * Seeds one canonical account per liquidity module and verifies:
 *   - Each Resource query includes ONLY its own module accounts.
 *   - No cross-module leakage occurs between any two liquidity modules.
 *
 * NOTE on legacy compat:
 * The Account model now enforces the canonical contract at saving time —
 * legacy granular module_type values ('flights', 'hajj', 'visa', 'bus') are
 * REJECTED for liquidity account types. This is working-as-intended.
 * The resource queries still include OR-clauses for backward compat with
 * existing DB rows, but those rows cannot be created through the app anymore.
 * Only canonical accounts are seeded here.
 *
 * Canonical rules under test:
 *   Bus      → type=wallet/bank, module_type=office,  module=bus
 *   Flight   → type=wallet/bank, module_type=tourism, module=flights
 *   HajjUmra → type=wallet/bank, module_type=tourism, module=hajj_umra
 *   Visa     → type=wallet/bank, module_type=tourism, module=visas
 *   Online   → type=wallet/bank, module_type=office,  module=online
 *   Fawry    → tested in FawryWalletFilamentTest — not repeated here
 */
class FinanceLiquidityResourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var array<string, Account> */
    protected array $acct = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'      => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status'  => 'active',
        ]);

        $this->actingAs($this->admin);

        $this->seedCanonicalAccounts();
    }

    // -----------------------------------------------------------------------
    // Seed helpers
    // -----------------------------------------------------------------------

    private function makeAccount(array $attrs): Account
    {
        /** @var Account $account */
        $account = Account::query()->create(array_merge([
            'balance'    => 0,
            'currency'   => 'EGP',
            'is_active'  => true,
            'created_by' => $this->admin->id,
        ], $attrs));

        return $account;
    }

    private function seedCanonicalAccounts(): void
    {
        // ── BUS (office / bus) ─────────────────────────────────────────────
        $this->acct['busWallet'] = $this->makeAccount([
            'name'        => 'Canonical Bus Wallet',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'office',
            'module'      => 'bus',
        ]);
        $this->acct['busBank'] = $this->makeAccount([
            'name'        => 'Canonical Bus Bank',
            'type'        => AccountType::Bank->value,
            'module_type' => 'office',
            'module'      => 'bus',
        ]);

        // ── FLIGHT (tourism / flights) ─────────────────────────────────────
        $this->acct['flightWallet'] = $this->makeAccount([
            'name'        => 'Canonical Flight Wallet',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'tourism',
            'module'      => 'flights',
        ]);
        $this->acct['flightBank'] = $this->makeAccount([
            'name'        => 'Canonical Flight Bank',
            'type'        => AccountType::Bank->value,
            'module_type' => 'tourism',
            'module'      => 'flights',
        ]);

        // ── HAJJ / UMRA (tourism / hajj_umra) ─────────────────────────────
        $this->acct['hajjWallet'] = $this->makeAccount([
            'name'        => 'Canonical HajjUmra Wallet',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'tourism',
            'module'      => 'hajj_umra',
        ]);
        $this->acct['hajjBank'] = $this->makeAccount([
            'name'        => 'Canonical HajjUmra Bank',
            'type'        => AccountType::Bank->value,
            'module_type' => 'tourism',
            'module'      => 'hajj_umra',
        ]);

        // ── VISA (tourism / visas) ─────────────────────────────────────────
        $this->acct['visaWallet'] = $this->makeAccount([
            'name'        => 'Canonical Visa Wallet',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'tourism',
            'module'      => 'visas',
        ]);
        $this->acct['visaBank'] = $this->makeAccount([
            'name'        => 'Canonical Visa Bank',
            'type'        => AccountType::Bank->value,
            'module_type' => 'tourism',
            'module'      => 'visas',
        ]);

        // ── ONLINE (office / online) ───────────────────────────────────────
        $this->acct['onlineWallet'] = $this->makeAccount([
            'name'        => 'Canonical Online Wallet',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'office',
            'module'      => 'online',
        ]);
        $this->acct['onlineBank'] = $this->makeAccount([
            'name'        => 'Canonical Online Bank',
            'type'        => AccountType::Bank->value,
            'module_type' => 'office',
            'module'      => 'online',
        ]);
    }

    // -----------------------------------------------------------------------
    // Helper: collect IDs from a resource query
    // -----------------------------------------------------------------------

    private function resourceIds(string $resourceClass): array
    {
        return $resourceClass::getEloquentQuery()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function id(string $key): int
    {
        return (int) $this->acct[$key]->id;
    }

    // -----------------------------------------------------------------------
    // Bus Wallet Resource
    // -----------------------------------------------------------------------

    public function test_bus_wallet_resource_includes_canonical_bus_wallet(): void
    {
        $ids = $this->resourceIds(BusWalletResource::class);
        $this->assertContains($this->id('busWallet'), $ids, 'BusWalletResource must include canonical bus wallet');
    }

    public function test_bus_wallet_resource_excludes_flight_accounts(): void
    {
        $ids = $this->resourceIds(BusWalletResource::class);
        $this->assertNotContains($this->id('flightWallet'), $ids, 'BusWalletResource must NOT include flight wallet');
    }

    public function test_bus_wallet_resource_excludes_hajj_visa_online_accounts(): void
    {
        $ids = $this->resourceIds(BusWalletResource::class);
        $this->assertNotContains($this->id('hajjWallet'), $ids);
        $this->assertNotContains($this->id('visaWallet'), $ids);
        $this->assertNotContains($this->id('onlineWallet'), $ids);
    }

    // -----------------------------------------------------------------------
    // Bus Bank Resource
    // -----------------------------------------------------------------------

    public function test_bus_bank_resource_includes_canonical_bus_bank(): void
    {
        $ids = $this->resourceIds(BusBankResource::class);
        $this->assertContains($this->id('busBank'), $ids);
    }

    public function test_bus_bank_resource_excludes_flight_and_other_banks(): void
    {
        $ids = $this->resourceIds(BusBankResource::class);
        $this->assertNotContains($this->id('flightBank'), $ids);
        $this->assertNotContains($this->id('hajjBank'), $ids);
        $this->assertNotContains($this->id('visaBank'), $ids);
        $this->assertNotContains($this->id('onlineBank'), $ids);
    }

    // -----------------------------------------------------------------------
    // Flight Wallet Resource
    // -----------------------------------------------------------------------

    public function test_flight_wallet_resource_includes_canonical_flight_wallet(): void
    {
        $ids = $this->resourceIds(FlightWalletResource::class);
        $this->assertContains($this->id('flightWallet'), $ids);
    }

    public function test_flight_wallet_resource_excludes_bus_and_other_wallets(): void
    {
        $ids = $this->resourceIds(FlightWalletResource::class);
        $this->assertNotContains($this->id('busWallet'), $ids);
        $this->assertNotContains($this->id('hajjWallet'), $ids);
        $this->assertNotContains($this->id('visaWallet'), $ids);
        $this->assertNotContains($this->id('onlineWallet'), $ids);
    }

    // -----------------------------------------------------------------------
    // Flight Bank Resource (BankAccountResource)
    // -----------------------------------------------------------------------

    public function test_bank_account_resource_includes_canonical_flight_bank(): void
    {
        $ids = $this->resourceIds(BankAccountResource::class);
        $this->assertContains($this->id('flightBank'), $ids);
    }

    public function test_bank_account_resource_excludes_bus_hajj_visa_online_banks(): void
    {
        $ids = $this->resourceIds(BankAccountResource::class);
        $this->assertNotContains($this->id('busBank'), $ids);
        $this->assertNotContains($this->id('hajjBank'), $ids);
        $this->assertNotContains($this->id('visaBank'), $ids);
        $this->assertNotContains($this->id('onlineBank'), $ids);
    }

    // -----------------------------------------------------------------------
    // HajjUmra Wallet Resource
    // -----------------------------------------------------------------------

    public function test_hajj_wallet_resource_includes_canonical_hajj_wallet(): void
    {
        $ids = $this->resourceIds(HajjUmraWalletResource::class);
        $this->assertContains($this->id('hajjWallet'), $ids);
    }

    public function test_hajj_wallet_resource_excludes_flight_bus_visa_online(): void
    {
        $ids = $this->resourceIds(HajjUmraWalletResource::class);
        $this->assertNotContains($this->id('flightWallet'), $ids);
        $this->assertNotContains($this->id('busWallet'), $ids);
        $this->assertNotContains($this->id('visaWallet'), $ids);
        $this->assertNotContains($this->id('onlineWallet'), $ids);
    }

    // -----------------------------------------------------------------------
    // HajjUmra Bank Resource
    // -----------------------------------------------------------------------

    public function test_hajj_bank_resource_includes_canonical_hajj_bank(): void
    {
        $ids = $this->resourceIds(HajjUmraBankAccountResource::class);
        $this->assertContains($this->id('hajjBank'), $ids);
    }

    public function test_hajj_bank_resource_excludes_flight_bus_visa_online_banks(): void
    {
        $ids = $this->resourceIds(HajjUmraBankAccountResource::class);
        $this->assertNotContains($this->id('flightBank'), $ids);
        $this->assertNotContains($this->id('busBank'), $ids);
        $this->assertNotContains($this->id('visaBank'), $ids);
        $this->assertNotContains($this->id('onlineBank'), $ids);
    }

    // -----------------------------------------------------------------------
    // Visa Wallet Resource
    // -----------------------------------------------------------------------

    public function test_visa_wallet_resource_includes_canonical_visa_wallet(): void
    {
        $ids = $this->resourceIds(VisaWalletResource::class);
        $this->assertContains($this->id('visaWallet'), $ids);
    }

    public function test_visa_wallet_resource_excludes_flight_bus_hajj_online(): void
    {
        $ids = $this->resourceIds(VisaWalletResource::class);
        $this->assertNotContains($this->id('flightWallet'), $ids);
        $this->assertNotContains($this->id('busWallet'), $ids);
        $this->assertNotContains($this->id('hajjWallet'), $ids);
        $this->assertNotContains($this->id('onlineWallet'), $ids);
    }

    // -----------------------------------------------------------------------
    // Visa Bank Resource
    // -----------------------------------------------------------------------

    public function test_visa_bank_resource_includes_canonical_visa_bank(): void
    {
        $ids = $this->resourceIds(VisaBankAccountResource::class);
        $this->assertContains($this->id('visaBank'), $ids);
    }

    public function test_visa_bank_resource_excludes_flight_bus_hajj_online_banks(): void
    {
        $ids = $this->resourceIds(VisaBankAccountResource::class);
        $this->assertNotContains($this->id('flightBank'), $ids);
        $this->assertNotContains($this->id('busBank'), $ids);
        $this->assertNotContains($this->id('hajjBank'), $ids);
        $this->assertNotContains($this->id('onlineBank'), $ids);
    }

    // -----------------------------------------------------------------------
    // Online Wallet Resource
    // -----------------------------------------------------------------------

    public function test_online_wallet_resource_includes_canonical_online_wallet(): void
    {
        $ids = $this->resourceIds(OnlineWalletResource::class);
        $this->assertContains($this->id('onlineWallet'), $ids);
    }

    public function test_online_wallet_resource_excludes_all_other_module_wallets(): void
    {
        $ids = $this->resourceIds(OnlineWalletResource::class);
        $this->assertNotContains($this->id('flightWallet'), $ids);
        $this->assertNotContains($this->id('busWallet'), $ids);
        $this->assertNotContains($this->id('hajjWallet'), $ids);
        $this->assertNotContains($this->id('visaWallet'), $ids);
    }

    // -----------------------------------------------------------------------
    // Online Bank Resource
    // -----------------------------------------------------------------------

    public function test_online_bank_resource_includes_canonical_online_bank(): void
    {
        $ids = $this->resourceIds(OnlineBankAccountResource::class);
        $this->assertContains($this->id('onlineBank'), $ids);
    }

    public function test_online_bank_resource_excludes_all_other_module_banks(): void
    {
        $ids = $this->resourceIds(OnlineBankAccountResource::class);
        $this->assertNotContains($this->id('flightBank'), $ids);
        $this->assertNotContains($this->id('busBank'), $ids);
        $this->assertNotContains($this->id('hajjBank'), $ids);
        $this->assertNotContains($this->id('visaBank'), $ids);
    }

    // -----------------------------------------------------------------------
    // Cross-module symmetry: no account appears in two different resources
    // -----------------------------------------------------------------------

    public function test_no_flight_account_leaks_into_bus_resources(): void
    {
        $busWalletIds = $this->resourceIds(BusWalletResource::class);
        $busBankIds   = $this->resourceIds(BusBankResource::class);

        $flightAll = [$this->id('flightWallet'), $this->id('flightBank')];

        foreach ($flightAll as $id) {
            $this->assertNotContains($id, $busWalletIds, "Flight account #{$id} leaked into BusWalletResource");
            $this->assertNotContains($id, $busBankIds, "Flight account #{$id} leaked into BusBankResource");
        }
    }

    public function test_no_bus_account_leaks_into_flight_resources(): void
    {
        $flightWalletIds = $this->resourceIds(FlightWalletResource::class);
        $flightBankIds   = $this->resourceIds(BankAccountResource::class);

        $busAll = [$this->id('busWallet'), $this->id('busBank')];

        foreach ($busAll as $id) {
            $this->assertNotContains($id, $flightWalletIds, "Bus account #{$id} leaked into FlightWalletResource");
            $this->assertNotContains($id, $flightBankIds, "Bus account #{$id} leaked into BankAccountResource");
        }
    }

    public function test_no_tourism_account_leaks_into_office_resources(): void
    {
        $busWalletIds    = $this->resourceIds(BusWalletResource::class);
        $busBankIds      = $this->resourceIds(BusBankResource::class);
        $onlineWalletIds = $this->resourceIds(OnlineWalletResource::class);
        $onlineBankIds   = $this->resourceIds(OnlineBankAccountResource::class);

        $tourismAll = [
            $this->id('flightWallet'),
            $this->id('flightBank'),
            $this->id('hajjWallet'),
            $this->id('hajjBank'),
            $this->id('visaWallet'),
            $this->id('visaBank'),
        ];

        foreach ($tourismAll as $id) {
            $this->assertNotContains($id, $busWalletIds, "Tourism account #{$id} leaked into BusWalletResource");
            $this->assertNotContains($id, $busBankIds, "Tourism account #{$id} leaked into BusBankResource");
            $this->assertNotContains($id, $onlineWalletIds, "Tourism account #{$id} leaked into OnlineWalletResource");
            $this->assertNotContains($id, $onlineBankIds, "Tourism account #{$id} leaked into OnlineBankAccountResource");
        }
    }

    public function test_no_office_account_leaks_into_tourism_resources(): void
    {
        $flightWalletIds = $this->resourceIds(FlightWalletResource::class);
        $flightBankIds   = $this->resourceIds(BankAccountResource::class);
        $hajjWalletIds   = $this->resourceIds(HajjUmraWalletResource::class);
        $hajjBankIds     = $this->resourceIds(HajjUmraBankAccountResource::class);
        $visaWalletIds   = $this->resourceIds(VisaWalletResource::class);
        $visaBankIds     = $this->resourceIds(VisaBankAccountResource::class);

        $officeAll = [
            $this->id('busWallet'),
            $this->id('busBank'),
            $this->id('onlineWallet'),
            $this->id('onlineBank'),
        ];

        foreach ($officeAll as $id) {
            $this->assertNotContains($id, $flightWalletIds, "Office account #{$id} leaked into FlightWalletResource");
            $this->assertNotContains($id, $flightBankIds, "Office account #{$id} leaked into BankAccountResource");
            $this->assertNotContains($id, $hajjWalletIds, "Office account #{$id} leaked into HajjUmraWalletResource");
            $this->assertNotContains($id, $hajjBankIds, "Office account #{$id} leaked into HajjUmraBankAccountResource");
            $this->assertNotContains($id, $visaWalletIds, "Office account #{$id} leaked into VisaWalletResource");
            $this->assertNotContains($id, $visaBankIds, "Office account #{$id} leaked into VisaBankAccountResource");
        }
    }
}
