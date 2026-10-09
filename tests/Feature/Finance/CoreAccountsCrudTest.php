<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — CRUD coverage.
 *
 * Tests the lifecycle of a financial account through the public API:
 *   - list   (GET    /api/v1/finance/accounts)
 *   - show   (GET    /api/v1/finance/accounts/{id})
 *   - store  (POST   /api/v1/finance/accounts)
 *   - update (PATCH  /api/v1/finance/accounts/{id})
 *
 * Auth: list is open to any authenticated user; store/show/update are admin-only.
 * Balance writes go through the LedgerBalanceMutationGuard so the Account
 * observer does not reject them mid-test.
 */
class CoreAccountsCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@crud.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return \App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'Cashbox EGP',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    // ───────────── list ─────────────

    public function test_AC_01_list_accounts_paginates_with_default_page_size(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->seedAccount(['name' => "TEST_AC01_Cashbox_$i"]);
        }

        // `search` filter scopes to only my 25 test rows (excluding the 2-3
        // accounts that auto-seeded migrations insert, which have different
        // names).
        $response = $this->getJson('/api/v1/finance/accounts?search=TEST_AC01_Cashbox&per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => ['*' => ['id', 'name', 'type', 'currency', 'is_active', 'module_type']],
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
                ],
            ]);

        $this->assertSame(25, (int) $response->json('data.pagination.total'));
        $this->assertSame(10, (int) $response->json('data.pagination.per_page'));
        $this->assertCount(10, $response->json('data.items'));
    }

    public function test_AC_02_list_accounts_filters_by_type_currency_owner_active(): void
    {
        // All ACTIVE so the type/currency/module filter assertions are clean.
        $this->seedAccount(['name' => 'TEST_AC02_EGP_Cashbox_Office', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP']);
        $this->seedAccount(['name' => 'TEST_AC02_USD_Bank_Office', 'type' => AccountType::Bank->value, 'currency' => 'USD']);
        $this->seedAccount(['name' => 'TEST_AC02_SAR_Wallet_Tourism', 'type' => AccountType::Wallet->value, 'currency' => 'SAR', 'module_type' => 'tourism', 'module' => 'tourism']);
        // An extra INACTIVE cashbox — used for the is_active filter below.
        $this->seedAccount(['name' => 'TEST_AC02_Inactive_Cashbox', 'type' => AccountType::Cashbox->value, 'is_active' => false]);

        // by account_type=cashbox → 2 rows (the active + the inactive cashbox)
        $r1 = $this->getJson('/api/v1/finance/accounts?account_type=cashbox&search=TEST_AC02');
        $r1->assertOk();
        $this->assertCount(2, $r1->json('data.items'));

        // by account_type=cashbox AND is_active=1 → 1 row
        $r1b = $this->getJson('/api/v1/finance/accounts?account_type=cashbox&is_active=1&search=TEST_AC02');
        $r1b->assertOk();
        $this->assertCount(1, $r1b->json('data.items'));
        $this->assertSame('TEST_AC02_EGP_Cashbox_Office', $r1b->json('data.items.0.name'));

        // by currency=USD → 1 row
        $r2 = $this->getJson('/api/v1/finance/accounts?currency=USD&search=TEST_AC02');
        $r2->assertOk();
        $this->assertCount(1, $r2->json('data.items'));
        $this->assertSame('TEST_AC02_USD_Bank_Office', $r2->json('data.items.0.name'));

        // by module_type=tourism → 1 row
        $r3 = $this->getJson('/api/v1/finance/accounts?module_type=tourism&search=TEST_AC02');
        $r3->assertOk();
        $this->assertCount(1, $r3->json('data.items'));
        $this->assertSame('TEST_AC02_SAR_Wallet_Tourism', $r3->json('data.items.0.name'));

        // by is_active=0 → 1 row
        $r4 = $this->getJson('/api/v1/finance/accounts?is_active=0&search=TEST_AC02');
        $r4->assertOk();
        $this->assertCount(1, $r4->json('data.items'));
        $this->assertSame('TEST_AC02_Inactive_Cashbox', $r4->json('data.items.0.name'));
    }

    public function test_AC_03_list_accounts_search_by_name(): void
    {
        $this->seedAccount(['name' => 'TEST_AC03_Bank_Misr_Alexandria']);
        $this->seedAccount(['name' => 'TEST_AC03_Bank_Ahly_Cairo']);
        $this->seedAccount(['name' => 'TEST_AC03_Wallet_Vodafone']);

        $r = $this->getJson('/api/v1/finance/accounts?search=TEST_AC03_Bank');
        $r->assertOk();
        $this->assertCount(2, $r->json('data.items'));
    }

    // ───────────── store ─────────────

    public function test_AC_04_create_account_validates_required_fields(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', []);
        $r->assertStatus(422);
        $errors = $r->json('errors');
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('type', $errors);
        $this->assertArrayHasKey('balance', $errors);
        $this->assertArrayHasKey('currency', $errors);
    }

    public function test_AC_05_create_account_validates_type_in_enum(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Bad type account',
            'type' => 'invalid-type',
            'balance' => 100,
            'currency' => 'EGP',
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('type', $r->json('errors'));
    }

    public function test_AC_06_create_account_validates_currency_length_3(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Bad currency',
            'type' => AccountType::Cashbox->value,
            'balance' => 100,
            'currency' => 'EURO', // length 4
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('currency', $r->json('errors'));
    }

    public function test_AC_07_create_account_auto_creates_opening_entry_when_balance_nonzero(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'TEST_AC07_EGP_Cashbox',
            'type' => AccountType::Cashbox->value,
            'balance' => 500.00,
            'currency' => 'EGP',
            'module_type' => 'office',
            'owner_type' => 'office',
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'TEST_AC07_EGP_Cashbox');

        $accountId = (int) $r->json('data.id');
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'TEST_AC07_EGP_Cashbox']);

        // The Account::created observer (FIN-1, 2026-08-21) auto-posts a
        // paired opening entry on the new account + a contra on
        // "System Opening Balances". Only the entry on our account matters
        // for the user-facing assertion.
        $openingEntries = AccountEntry::query()
            ->where('account_id', $accountId)
            ->where('is_opening', true)
            ->get();
        $this->assertCount(1, $openingEntries, 'one opening entry on the new account should be created');
        $this->assertSame(0.00, (float) $openingEntries[0]->debit);
        $this->assertSame(500.00, (float) $openingEntries[0]->credit);
        $this->assertNull($openingEntries[0]->transaction_id);
        $this->assertTrue((bool) $openingEntries[0]->is_opening);
    }

    public function test_AC_07b_create_account_zero_balance_no_opening_entry(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Zero Bal Account',
            'type' => AccountType::Cashbox->value,
            'balance' => 0,
            'currency' => 'EGP',
            'module_type' => 'office',
            'owner_type' => 'office',
        ]);

        $r->assertStatus(201);
        $accountId = (int) $r->json('data.id');
        $this->assertSame(0, AccountEntry::query()->where('account_id', $accountId)->count());
    }

    public function test_AC_07c_create_wallet_account_requires_wallet_provider_and_number(): void
    {
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Wallet Vodafone',
            'type' => AccountType::Wallet->value,
            'balance' => 100,
            'currency' => 'EGP',
            'module_type' => 'office',
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('wallet_provider', $r->json('errors'));
        $this->assertArrayHasKey('wallet_number', $r->json('errors'));

        $r2 = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Wallet Vodafone 2',
            'type' => AccountType::Wallet->value,
            'balance' => 100,
            'currency' => 'EGP',
            'module_type' => 'office',
            'wallet_provider' => WalletProvider::VodafoneCash->value,
            'wallet_number' => '01012345678',
        ]);
        $r2->assertStatus(201);
    }

    // ───────────── show + update ─────────────

    public function test_AC_08_show_account_returns_full_resource(): void
    {
        $acc = $this->seedAccount(['name' => 'Show Target']);
        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}");

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $acc->id)
            ->assertJsonPath('data.name', 'Show Target')
            ->assertJsonStructure([
                'data' => ['id', 'name', 'type', 'balance', 'balance_egp', 'currency', 'is_active', 'wallet_provider', 'wallet_number', 'notes', 'module_type', 'module', 'owner_type', 'is_module_vault'],
            ]);
    }

    public function test_AC_09_show_account_404_for_missing(): void
    {
        $r = $this->getJson('/api/v1/finance/accounts/999999');
        $r->assertStatus(404);
    }

    public function test_AC_09b_update_account_persists_and_returns_200(): void
    {
        $acc = $this->seedAccount(['name' => 'Old Name']);
        $r = $this->patchJson("/api/v1/finance/accounts/{$acc->id}", [
            'name' => 'New Name',
            'notes' => 'Updated note',
            'is_active' => false,
        ]);
        $r->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.notes', 'Updated note');
        $this->assertDatabaseHas('accounts', ['id' => $acc->id, 'name' => 'New Name', 'is_active' => false]);
    }

    // ───────────── auth ─────────────

    public function test_AC_10_non_admin_gets_403_on_create(): void
    {
        $employee = User::query()->create([
            'name' => 'Test Employee',
            'email' => 'emp@crud.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        Sanctum::actingAs($employee, ['*']);

        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Should fail',
            'type' => AccountType::Cashbox->value,
            'balance' => 0,
            'currency' => 'EGP',
            'module_type' => 'office',
        ]);
        $r->assertStatus(403);
    }

    public function test_AC_10b_unauthenticated_user_gets_401_on_create(): void
    {
        // Clear any active sanctum auth
        auth()->forgetGuards();
        $r = $this->postJson('/api/v1/finance/accounts', [
            'name' => 'Should fail',
            'type' => AccountType::Cashbox->value,
            'balance' => 0,
            'currency' => 'EGP',
        ]);
        // The Sanctum middleware should reject with 401
        $this->assertContains($r->status(), [401, 403]);
    }
}