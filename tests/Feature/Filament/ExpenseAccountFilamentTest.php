<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\ExpenseAccounts\ExpenseAccountResource;
use App\Filament\Admin\Resources\ExpenseAccounts\Pages\CreateExpenseAccount;
use App\Filament\Admin\Resources\ExpenseAccounts\Pages\ListExpenseAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseAccountFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
    }

    public function test_expense_accounts_create_page_is_registered(): void
    {
        $this->actingAs($this->user)
            ->get('/admin/expense-accounts/create')
            ->assertOk();
    }

    public function test_can_create_expense_account_from_filament(): void
    {
        Livewire::actingAs($this->user)
            ->test(CreateExpenseAccount::class)
            ->fillForm([
                'name' => 'إيجار مكتب',
                'owner_type' => 'office',
                'module_type' => 'general',
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'إيجار مكتب',
            'type' => AccountType::Expense->value,
            'module_type' => 'general',
            'is_active' => true,
        ]);
    }

    public function test_expense_account_resource_lists_only_expense_type_accounts(): void
    {
        Account::query()->create([
            'name' => 'بند مصروف',
            'type' => AccountType::Expense->value,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'general',
            'created_by' => $this->user->id,
        ]);

        Account::query()->create([
            'name' => 'خزينة عامة',
            'type' => AccountType::Bank->value,
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(ListExpenseAccounts::class)
            ->assertCanSeeTableRecords(
                Account::query()->where('type', AccountType::Expense->value)->get()
            )
            ->assertCanNotSeeTableRecords(
                Account::query()->where('type', AccountType::Bank->value)->get()
            );
    }

    public function test_expense_account_resource_slug_matches_vue_link(): void
    {
        $this->assertSame('expense-accounts', ExpenseAccountResource::getSlug());
        $this->assertStringContainsString(
            '/admin/expense-accounts/create',
            ExpenseAccountResource::getUrl('create')
        );
    }
}
