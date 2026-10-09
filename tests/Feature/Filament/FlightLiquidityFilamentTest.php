<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Admin\Resources\FlightWallets\FlightWalletResource;
use App\Filament\Admin\Resources\FlightWallets\Pages\CreateFlightWallet;
use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GAP 1 — Flight Liquidity Filament Write Path
 *
 * Verifies that both Flight liquidity create pages correctly lock:
 *   module_type = 'tourism'
 *   module      = 'flights'
 *
 * and persist the correct account type (wallet / bank).
 */
class FlightLiquidityFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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
    }

    // -----------------------------------------------------------------------
    // CreateFlightWallet
    // -----------------------------------------------------------------------

    public function test_flight_wallet_create_page_loads(): void
    {
        $this->get(FlightWalletResource::getUrl('create'))->assertOk();
    }

    public function test_can_create_flight_wallet_with_correct_canonical_classification(): void
    {
        Livewire::test(CreateFlightWallet::class)
            ->fillForm([
                'name'            => 'محفظة طيران — فودافون كاش',
                'wallet_provider' => WalletProvider::VodafoneCash->value,
                'wallet_number'   => '01011112222',
                'currency'        => 'EGP',
                'balance'         => 0,
                'is_active'       => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name'        => 'محفظة طيران — فودافون كاش',
            'type'        => AccountType::Wallet->value,
            'module_type' => 'tourism',   // canonical: TOURISM division
            'module'      => 'flights',   // canonical: granular flights sub-module
        ]);
    }

    public function test_created_flight_wallet_is_not_classified_as_office(): void
    {
        Livewire::test(CreateFlightWallet::class)
            ->fillForm([
                'name'            => 'محفظة طيران — إنستاباي',
                'wallet_provider' => WalletProvider::Instapay->value,
                'wallet_number'   => '01033334444',
                'currency'        => 'EGP',
                'balance'         => 0,
                'is_active'       => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        // Must NOT appear with office module_type
        $this->assertDatabaseMissing('accounts', [
            'name'        => 'محفظة طيران — إنستاباي',
            'module_type' => 'office',
        ]);

        // Must NOT appear with legacy granular module_type 'flights'
        $this->assertDatabaseMissing('accounts', [
            'name'        => 'محفظة طيران — إنستاباي',
            'module_type' => 'flights',
        ]);
    }

    // -----------------------------------------------------------------------
    // CreateBankAccount (Flight Bank)
    // -----------------------------------------------------------------------

    public function test_flight_bank_account_create_page_loads(): void
    {
        $this->get(BankAccountResource::getUrl('create'))->assertOk();
    }

    public function test_can_create_flight_bank_account_with_correct_canonical_classification(): void
    {
        Livewire::test(CreateBankAccount::class)
            ->fillForm([
                'name'      => 'البنك الأهلي — حساب الطيران',
                'type'      => AccountType::Bank->value,   // required: type select rendered for BankAccountResource
                'currency'  => 'EGP',
                'balance'   => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name'        => 'البنك الأهلي — حساب الطيران',
            'type'        => AccountType::Bank->value,
            'module_type' => 'tourism',   // canonical: TOURISM division
            'module'      => 'flights',   // canonical: granular flights sub-module
        ]);
    }

    public function test_created_flight_bank_is_not_classified_as_office_or_granular_flights(): void
    {
        Livewire::test(CreateBankAccount::class)
            ->fillForm([
                'name'      => 'بنك CIB — حساب الطيران',
                'type'      => AccountType::Bank->value,
                'currency'  => 'USD',
                'balance'   => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        // Must NOT be stored with the old granular module_type 'flights'
        $this->assertDatabaseMissing('accounts', [
            'name'        => 'بنك CIB — حساب الطيران',
            'module_type' => 'flights',
        ]);

        // Must NOT be stored as office division
        $this->assertDatabaseMissing('accounts', [
            'name'        => 'بنك CIB — حساب الطيران',
            'module_type' => 'office',
        ]);
    }

    public function test_flight_bank_account_saving_rules_reject_missing_name(): void
    {
        // BankAccountResource renders a type select (array fixedType), so we supply type
        // but leave name blank — the form must fire a required error on the name field.
        Livewire::test(CreateBankAccount::class)
            ->fillForm([
                'name'      => '',
                'type'      => AccountType::Bank->value,
                'currency'  => 'EGP',
                'balance'   => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasErrors(['data.name']);
    }
}
