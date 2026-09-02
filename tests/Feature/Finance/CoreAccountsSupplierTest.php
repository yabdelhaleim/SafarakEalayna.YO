<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Supplier account coverage.
 *
 * Endpoints (admin-only):
 *   POST /api/v1/suppliers/{supplier}/account/recharge
 *   GET  /api/v1/suppliers/{supplier}/account/statement
 *   GET  /api/v1/suppliers/{supplier}/account/balance
 *
 * Endpoint: SupplierAccountController
 * Service:  SupplierAccountService::rechargeSupplierAccount / getSupplierStatement
 *
 * Validation rules (RechargeSupplierAccountRequest):
 *   - from_treasury_id: required, exists:accounts,id
 *   - amount:           required, numeric, min:0.01
 *   - notes:            nullable, string, max:500
 *
 * Business rules (SupplierAccountService):
 *   - supplier must have account_id linked
 *   - supplier account must be active
 *   - treasury must have sufficient balance
 *   - Posts a Transfer (cashbox → supplier AP) with type=Transfer
 */
class CoreAccountsSupplierTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@supplier.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedTreasury(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_ASU Treasury',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 10000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    private function seedSupplier(array $supplierOverrides = [], array $accountOverrides = []): Supplier
    {
        $account = LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_ASU Supplier AP',
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'module_type' => 'flights',
            'module' => 'flights',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $accountOverrides)));

        return Supplier::query()->create(array_merge([
            'name' => 'TEST_ASU Supplier',
            'code' => 'TEST-ASU-001',
            'account_id' => $account->id,
            'currency' => 'EGP',
            'is_active' => true,
            'payment_terms' => 'cash',
        ], $supplierOverrides));
    }

    public function test_ASU_01_supplier_recharge_increases_supplier_balance_and_decreases_treasury(): void
    {
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU01_Treasury', 'balance' => 10000.0]);
        $supplier = $this->seedSupplier();

        $r = $this->postJson("/api/v1/suppliers/{$supplier->id}/account/recharge", [
            'from_treasury_id' => $treasury->id,
            'amount' => 1500.0,
            'notes' => 'TEST supplier recharge',
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['transaction_id', 'amount', 'supplier'],
            ]);

        // Treasury debited, supplier credited (same currency)
        $this->assertEqualsWithDelta(8500.0, (float) $treasury->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $supplier->account->fresh()->balance, 0.001);
    }

    public function test_ASU_02_supplier_recharge_insufficient_source_rejected_with_422(): void
    {
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU02_Treasury', 'balance' => 100.0]);
        $supplier = $this->seedSupplier();

        $r = $this->postJson("/api/v1/suppliers/{$supplier->id}/account/recharge", [
            'from_treasury_id' => $treasury->id,
            'amount' => 5000.0,
            'notes' => 'TEST insufficient',
        ]);

        $r->assertStatus(422);

        // Treasury unchanged
        $this->assertEqualsWithDelta(100.0, (float) $treasury->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $supplier->account->fresh()->balance, 0.001);
    }

    public function test_ASU_03_supplier_statement_returns_paginated_entries(): void
    {
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU03_Treasury', 'balance' => 5000.0]);
        $supplier = $this->seedSupplier();

        // 2 recharges → 2 AccountEntry rows on the supplier's account
        for ($i = 1; $i <= 2; $i++) {
            $r = $this->postJson("/api/v1/suppliers/{$supplier->id}/account/recharge", [
                'from_treasury_id' => $treasury->id,
                'amount' => 100.0 * $i,
                'notes' => "TEST_ASU03 recharge $i",
            ]);
            $r->assertStatus(201);
        }

        $r = $this->getJson("/api/v1/suppliers/{$supplier->id}/account/statement");
        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => ['*' => ['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after']],
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                    'stats' => ['opening_balance', 'period_credit', 'period_debit', 'closing_balance', 'account_balance'],
                    'supplier',
                ],
            ]);

        // 2 recharge credits + 1 opening entry from Account::created observer = 3 entries total
        $this->assertGreaterThanOrEqual(2, (int) $r->json('data.pagination.total'));
    }

    public function test_ASU_04_supplier_balance_endpoint_returns_net_balance(): void
    {
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU04_Treasury', 'balance' => 5000.0]);
        $supplier = $this->seedSupplier();

        $this->postJson("/api/v1/suppliers/{$supplier->id}/account/recharge", [
            'from_treasury_id' => $treasury->id,
            'amount' => 800.0,
            'notes' => 'TEST_ASU04',
        ])->assertStatus(201);

        $r = $this->getJson("/api/v1/suppliers/{$supplier->id}/account/balance");
        $r->assertOk()->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(800.0, (float) $r->json('data.balance'), 0.001);
    }

    public function test_ASU_05_recharge_nonexistent_supplier_404(): void
    {
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU05_Treasury']);

        $r = $this->postJson('/api/v1/suppliers/999999/account/recharge', [
            'from_treasury_id' => $treasury->id,
            'amount' => 100.0,
        ]);

        $r->assertStatus(404);
    }

    public function test_ASU_06_supplier_recharge_posts_balanced_double_entry(): void
    {
        // Verify the double-entry invariant: Σ debit = Σ credit on the
        // single transaction posted by the recharge.
        $treasury = $this->seedTreasury(['name' => 'TEST_ASU06_Treasury', 'balance' => 5000.0]);
        $supplier = $this->seedSupplier();

        $r = $this->postJson("/api/v1/suppliers/{$supplier->id}/account/recharge", [
            'from_treasury_id' => $treasury->id,
            'amount' => 750.0,
        ]);
        $r->assertStatus(201);

        $txId = (int) $r->json('data.transaction_id');

        $entries = \App\Models\AccountEntry::query()
            ->where('transaction_id', $txId)
            ->get();

        $this->assertCount(2, $entries, 'transfer posts exactly 2 AccountEntry rows');
        $this->assertEqualsWithDelta(750.0, (float) $entries->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(750.0, (float) $entries->sum('credit'), 0.001);
    }
}