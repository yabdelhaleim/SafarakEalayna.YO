<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Cache Invalidation coverage.
 *
 * The AccountController caches the listing endpoint under tag `accounts` for
 * 30 seconds. Every write path (create, update, deactivate, transfer) must
 * flush the `accounts` cache tag so the next listing reflects fresh state.
 *
 * Strategy: warm the cache by hitting the listing, perform a mutation, then
 * verify the listing returns fresh state (different data than the cached
 * response). This works on any cache backend (array, file, redis).
 */
class CoreAccountsCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Cache Admin',
            'email' => 'admin@cache.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'CACHE Account',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    private function totalRows(string $nameFilter): int
    {
        $r = $this->getJson("/api/v1/finance/accounts?search={$nameFilter}&per_page=50");
        $r->assertOk();
        return $r->json('data.pagination.total') ?? count($r->json('data.items') ?? []);
    }

    // ───────────── Cache-flush hooks ─────────────

    public function test_CI_01_create_account_invalidates_listing_cache(): void
    {
        $before = $this->totalRows('CI_01');

        $this->postJson('/api/v1/finance/accounts', [
            'name' => 'CI_01_After',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 100.0,
            'is_active' => true,
        ])->assertStatus(201);

        // Listing must reflect the new row
        $after = $this->totalRows('CI_01');
        $this->assertSame($before + 1, $after);
    }

    public function test_CI_02_update_account_invalidates_listing_cache(): void
    {
        $acc = $this->seedAccount(['name' => 'CI_02_Before']);

        // Warm cache
        $this->getJson('/api/v1/finance/accounts?search=CI_02_Before&per_page=50')->assertOk();

        $this->patchJson("/api/v1/finance/accounts/{$acc->id}", [
            'notes' => 'cache-busting note',
        ])->assertOk();

        $r = $this->getJson('/api/v1/finance/accounts?search=CI_02_Before&per_page=50');
        $r->assertOk();
        $this->assertSame('cache-busting note', $r->json('data.items.0.notes'));
    }

    public function test_CI_03_deactivate_account_invalidates_listing_cache(): void
    {
        $acc = LedgerBalanceMutationGuard::run(fn () => $this->seedAccount(['balance' => 0.0]));

        // Warm cache (the listing includes the new active account)
        $this->getJson('/api/v1/finance/accounts?per_page=50')->assertOk();

        $this->postJson("/api/v1/finance/accounts/{$acc->id}/deactivate")
            ->assertOk();

        // Re-list — the account should now be inactive (cache flushed)
        $r = $this->getJson('/api/v1/finance/accounts?per_page=50');
        $r->assertOk();
        $rows = collect($r->json('data.items') ?? [])->where('id', $acc->id);
        $this->assertCount(1, $rows);
        $this->assertFalse((bool) ($rows->first()['is_active'] ?? true));
    }

    public function test_CI_04_transfer_invalidates_listing_cache(): void
    {
        $from = $this->seedAccount(['name' => 'CI_04_From', 'balance' => 500.0]);
        $to = $this->seedAccount(['name' => 'CI_04_To', 'type' => AccountType::Bank->value]);

        $this->getJson('/api/v1/finance/accounts?per_page=50')->assertOk();

        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
            'module' => TransactionModule::General->value,
        ])->assertStatus(201);

        // Listing reflects new balances
        $r = $this->getJson('/api/v1/finance/accounts?per_page=50');
        $r->assertOk();
        $rows = collect($r->json('data.items') ?? []);
        $this->assertSame(400.0, (float) $rows->where('id', $from->id)->first()['balance']);
        $this->assertSame(100.0, (float) $rows->where('id', $to->id)->first()['balance']);
    }

    public function test_CI_05_repeated_listing_returns_cached_response_when_no_mutation(): void
    {
        $this->seedAccount(['name' => 'CI_05_Stable']);

        // First call caches
        $r1 = $this->getJson('/api/v1/finance/accounts?per_page=50');
        $r1->assertOk();

        // Second call without mutation — should return same data
        $r2 = $this->getJson('/api/v1/finance/accounts?per_page=50');
        $r2->assertOk();

        $this->assertSame(
            $r1->json('data.pagination.total'),
            $r2->json('data.pagination.total')
        );
    }

    public function test_CI_06_transfer_with_cross_currency_invalidates_cache(): void
    {
        $egp = $this->seedAccount(['name' => 'CI_06_EGP', 'balance' => 5000.0, 'currency' => 'EGP']);
        $usd = $this->seedAccount(['name' => 'CI_06_USD', 'balance' => 0.0,
            'currency' => 'USD', 'type' => AccountType::Bank->value]);

        $this->getJson('/api/v1/finance/accounts?per_page=50')->assertOk();

        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $egp->id,
            'to_account_id' => $usd->id,
            'amount' => 1000.0,
            'converted_amount' => 20.41,
        ])->assertStatus(201);

        $r = $this->getJson('/api/v1/finance/accounts?per_page=50');
        $rows = collect($r->json('data.items') ?? []);
        $this->assertSame(4000.0, (float) $rows->where('id', $egp->id)->first()['balance']);
    }
}
