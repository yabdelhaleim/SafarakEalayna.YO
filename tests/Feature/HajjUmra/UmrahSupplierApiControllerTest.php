<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\HajjUmra\UmrahSupplierApiController.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\UmrahSupplierApiController
 */
class UmrahSupplierApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Supplier Tester',
            'email' => 'umrah-supplier@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);
    }

    protected function makeSupplier(array $overrides = []): UmrahSupplier
    {
        $account = Account::query()->create([
            'name' => 'Supplier Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        return UmrahSupplier::query()->create(array_merge([
            'name' => 'مورد اختبار',
            'phone' => '+966500000000',
            'default_cost_price' => 1000.00,
            'account_id' => $account->id,
            'is_active' => true,
        ], $overrides));
    }

    /* =========================================================
     * INDEX
     * ========================================================= */

    public function test_index_returns_list_of_suppliers(): void
    {
        $this->makeSupplier(['name' => 'مورد 1']);
        $this->makeSupplier(['name' => 'مورد 2']);

        $response = $this->getJson('/api/v1/umrah-suppliers');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($items));
        $this->assertArrayHasKey('id', $items[0]);
        $this->assertArrayHasKey('name', $items[0]);
        $this->assertArrayHasKey('supplier_cost_price', $items[0]);
        $this->assertArrayHasKey('account_id', $items[0]);
    }

    public function test_index_includes_account_name_when_linked(): void
    {
        $supplier = $this->makeSupplier();

        $response = $this->getJson('/api/v1/umrah-suppliers');

        $items = $response->json('data');
        $first = collect($items)->firstWhere('id', $supplier->id);
        $this->assertNotNull($first);
        $this->assertSame('Supplier Account', $first['account_name']);
    }

    public function test_index_orders_suppliers_by_name(): void
    {
        $this->makeSupplier(['name' => 'Z Supplier']);
        $this->makeSupplier(['name' => 'A Supplier']);
        $this->makeSupplier(['name' => 'M Supplier']);

        $response = $this->getJson('/api/v1/umrah-suppliers');

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    /* =========================================================
     * STORE
     * ========================================================= */

    public function test_store_creates_supplier_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'مورد جديد',
            'phone' => '+966500000111',
            'default_cost_price' => 1500.00,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'phone', 'supplier_cost_price', 'account_id'],
            ]);

        $this->assertDatabaseHas('umrah_suppliers', [
            'name' => 'مورد جديد',
            'phone' => '+966500000111',
            'default_cost_price' => 1500.00,
            'is_active' => 1,
        ]);
    }

    public function test_store_auto_creates_supplier_account(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'مورد بحساب تلقائي',
            'default_cost_price' => 500.00,
        ]);

        $response->assertCreated();
        $accountId = $response->json('data.account_id');
        $this->assertNotNull($accountId);

        $this->assertDatabaseHas('accounts', [
            'id' => $accountId,
            'type' => 'supplier',
            'module_type' => 'hajj_umra',
        ]);
    }

    public function test_store_uses_supplied_account_when_provided(): void
    {
        $existingAccount = Account::query()->create([
            'name' => 'Existing Account',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'مورد بحساب موجود',
            'account_id' => $existingAccount->id,
        ]);

        $response->assertCreated();
        $this->assertSame($existingAccount->id, $response->json('data.account_id'));
    }

    public function test_store_validates_name_required(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'phone' => '+966500000111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_account_id_must_exist(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'مورد بحساب غير موجود',
            'account_id' => 999999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_store_validates_default_cost_price_non_negative(): void
    {
        $response = $this->postJson('/api/v1/umrah-suppliers', [
            'name' => 'مورد بسعر سالب',
            'default_cost_price' => -100,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['default_cost_price']);
    }
}