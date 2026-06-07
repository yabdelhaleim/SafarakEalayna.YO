<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Resources\FawryBanks\FawryBankResource;
use App\Filament\Admin\Resources\FawryBanks\Pages\CreateFawryBank;
use App\Filament\Admin\Resources\FawryBanks\Pages\ListFawryBanks;
use App\Filament\Admin\Resources\FawryCashboxes\FawryCashboxResource;
use App\Filament\Admin\Resources\FawryCashboxes\Pages\CreateFawryCashbox;
use App\Filament\Admin\Resources\FawryCashboxes\Pages\ListFawryCashboxes;
use App\Filament\Admin\Resources\FawryTreasuries\FawryTreasuryResource;
use App\Filament\Admin\Resources\FawryTreasuries\Pages\CreateFawryTreasury;
use App\Filament\Admin\Resources\FawryTreasuries\Pages\ListFawryTreasuries;
use App\Filament\Admin\Resources\FawryWallets\FawryWalletResource;
use App\Filament\Admin\Resources\FawryWallets\Pages\CreateFawryWallet;
use App\Filament\Admin\Resources\FawryWallets\Pages\ListFawryWallets;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FawryWalletFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_fawry_wallets_index_page_loads(): void
    {
        $this->get(FawryWalletResource::getUrl('index'))->assertOk();
    }

    public function test_can_create_fawry_wallet_via_filament(): void
    {
        Livewire::test(CreateFawryWallet::class)
            ->fillForm([
                'name' => 'فودافون كاش — فرع المعادي',
                'owner_type' => 'office',
                'wallet_provider' => WalletProvider::VodafoneCash->value,
                'wallet_number' => '01012345678',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'فودافون كاش — فرع المعادي',
            'type' => AccountType::Wallet->value,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'wallet_number' => '01012345678',
        ]);
    }

    public function test_list_shows_only_fawry_wallets(): void
    {
        $fawryWallet = Account::query()->create([
            'name' => 'محفظة فوري',
            'type' => AccountType::Wallet,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'wallet_provider' => WalletProvider::VodafoneCash,
            'wallet_number' => '01099998888',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Account::query()->create([
            'name' => 'محفظة طيران',
            'type' => AccountType::Wallet,
            'module_type' => 'flights',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        FawryTransaction::factory()->create([
            'account_id' => $fawryWallet->id,
        ]);

        Livewire::test(ListFawryWallets::class)
            ->assertCanSeeTableRecords([$fawryWallet])
            ->assertCountTableRecords(1);
    }

    public function test_fawry_cashboxes_index_page_loads(): void
    {
        $this->get(FawryCashboxResource::getUrl('index'))->assertOk();
    }

    public function test_can_create_fawry_cashbox_via_filament(): void
    {
        Livewire::test(CreateFawryCashbox::class)
            ->fillForm([
                'name' => 'خزينة فوري — الرئيسية',
                'owner_type' => 'office',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'خزينة فوري — الرئيسية',
            'type' => AccountType::Cashbox->value,
            'module_type' => 'fawry',
            'module' => 'fawry',
        ]);
    }

    public function test_cashbox_list_shows_only_fawry_cashboxes(): void
    {
        $cashbox = Account::query()->create([
            'name' => 'خزينة فوري',
            'type' => AccountType::Cashbox,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Account::query()->create([
            'name' => 'خزينة باصات',
            'type' => AccountType::Cashbox,
            'module_type' => 'bus',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ListFawryCashboxes::class)
            ->assertCanSeeTableRecords([$cashbox])
            ->assertCountTableRecords(1);
    }

    public function test_fawry_banks_index_page_loads(): void
    {
        $this->get(FawryBankResource::getUrl('index'))->assertOk();
    }

    public function test_can_create_fawry_bank_via_filament(): void
    {
        Livewire::test(CreateFawryBank::class)
            ->fillForm([
                'name' => 'البنك الأهلي — فوري',
                'owner_type' => 'office',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'البنك الأهلي — فوري',
            'type' => AccountType::Bank->value,
            'module_type' => 'fawry',
            'module' => 'fawry',
        ]);
    }

    public function test_bank_list_shows_only_fawry_banks(): void
    {
        $bank = Account::query()->create([
            'name' => 'بنك فوري',
            'type' => AccountType::Bank,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Account::query()->create([
            'name' => 'بنك طيران',
            'type' => AccountType::Bank,
            'module_type' => 'flights',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ListFawryBanks::class)
            ->assertCanSeeTableRecords([$bank])
            ->assertCountTableRecords(1);
    }

    public function test_fawry_treasuries_index_page_loads(): void
    {
        $this->get(FawryTreasuryResource::getUrl('index'))->assertOk();
    }

    public function test_can_create_fawry_treasury_via_filament(): void
    {
        Livewire::test(CreateFawryTreasury::class)
            ->fillForm([
                'name' => 'خزينة فوري الرسمية',
                'owner_type' => 'office',
                'currency' => 'EGP',
                'balance' => 0,
                'is_module_vault' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'خزينة فوري الرسمية',
            'type' => AccountType::Treasury->value,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'is_module_vault' => true,
        ]);
    }

    public function test_treasury_list_shows_only_fawry_treasuries(): void
    {
        $treasury = Account::query()->create([
            'name' => 'خزينة عامة فوري',
            'type' => AccountType::Treasury,
            'module_type' => 'fawry',
            'module' => 'fawry',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'is_module_vault' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Account::query()->create([
            'name' => 'خزينة عامة طيران',
            'type' => AccountType::Treasury,
            'module_type' => 'flights',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ListFawryTreasuries::class)
            ->assertCanSeeTableRecords([$treasury])
            ->assertCountTableRecords(1);
    }
}
