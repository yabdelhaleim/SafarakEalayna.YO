<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Manual income/expense transaction coverage.
 *
 * POST /api/v1/finance/transactions
 * PUT  /api/v1/finance/transactions/{id}
 * DELETE /api/v1/finance/transactions/{id}
 *
 * Endpoint: TransactionController (note: NOT AccountController)
 * Service: TransactionService::recordIncome / recordExpense
 *
 * Validation rules (from TransactionController::store):
 *   - type: required, in:income,expense
 *   - amount: required, numeric, >= 0.01
 *   - account_id: required, exists:accounts,id
 *   - description: required, string, max:500
 *   - module: optional
 *   - notes/reference/date: optional
 *
 * Business rule: account_id MUST be a liquidity type (cashbox/bank/wallet)
 * — otherwise 422 with Arabic message.
 */
class CoreAccountsTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@tx.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_AX Account',
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

    public function test_AX_01_create_income_increases_liquidity_balance(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX01_Cashbox', 'balance' => 5000.0]);

        $r = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 500.0,
            'account_id' => $acc->id,
            'description' => 'TEST manual income',
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('success', true);
        $this->assertEqualsWithDelta(500.0, (float) $r->json('data.amount'), 0.001);

        $this->assertEqualsWithDelta(5500.0, (float) $acc->fresh()->balance, 0.001);
    }

    public function test_AX_02_create_expense_decreases_liquidity_balance(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX02_Cashbox', 'balance' => 5000.0]);

        $r = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'expense',
            'amount' => 250.0,
            'account_id' => $acc->id,
            'description' => 'TEST manual expense',
        ]);

        $r->assertStatus(201);
        $this->assertSame(4750.0, (float) $acc->fresh()->balance);
    }

    public function test_AX_03_target_account_must_be_liquidity_type(): void
    {
        // Customer AR — NOT a liquidity type → 422
        $acc = $this->seedAccount([
            'name' => 'TEST_AX03_Customer_AR',
            'type' => AccountType::Customer->value,
            'module_type' => 'flights',
            'module' => 'flights',
        ]);

        $r = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 100.0,
            'account_id' => $acc->id,
            'description' => 'TEST should fail',
        ]);

        $r->assertStatus(422);
    }

    public function test_AX_04_missing_required_fields_returns_422(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX04_Cashbox']);

        $r = $this->postJson('/api/v1/finance/transactions', [
            // missing type, amount, description
            'account_id' => $acc->id,
        ]);
        $r->assertStatus(422);

        $errors = $r->json('errors');
        $this->assertArrayHasKey('type', $errors);
        $this->assertArrayHasKey('amount', $errors);
        $this->assertArrayHasKey('description', $errors);
    }

    public function test_AX_05_amount_below_minimum_rejected(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX05_Cashbox']);

        $r = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 0,
            'account_id' => $acc->id,
            'description' => 'TEST zero amount',
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('amount', $r->json('errors'));
    }

    public function test_AX_06_update_transaction_reverses_old_and_creates_new(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX06_Cashbox', 'balance' => 1000.0]);

        // Create initial income of 500
        $r1 = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 500.0,
            'account_id' => $acc->id,
            'description' => 'TEST initial',
        ]);
        $r1->assertStatus(201);
        $oldTxId = (int) $r1->json('data.id');
        $this->assertSame(1500.0, (float) $acc->fresh()->balance);

        // Update — should void the old journal + create new with amount 800
        $r2 = $this->putJson("/api/v1/finance/transactions/{$oldTxId}", [
            'type' => 'income',
            'amount' => 800.0,
            'account_id' => $acc->id,
            'description' => 'TEST updated',
        ]);
        $r2->assertStatus(200);
        $newTxId = (int) $r2->json('data.id');
        $this->assertNotSame($oldTxId, $newTxId);

        // Net effect on balance: +500 (initial) − 500 (void) + 800 (new) = +800 → balance=1800
        $this->assertSame(1800.0, (float) $acc->fresh()->balance);

        // Old transaction row should be deleted (per TransactionController::update)
        $this->assertNull(Transaction::find($oldTxId));
        // New transaction row exists
        $this->assertNotNull(Transaction::find($newTxId));
    }

    public function test_AX_07_delete_transaction_voids_ledger(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AX07_Cashbox', 'balance' => 1000.0]);

        $r1 = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 300.0,
            'account_id' => $acc->id,
            'description' => 'TEST to delete',
        ]);
        $r1->assertStatus(201);
        $txId = (int) $r1->json('data.id');
        $this->assertSame(1300.0, (float) $acc->fresh()->balance);

        $r2 = $this->deleteJson("/api/v1/finance/transactions/{$txId}");
        $r2->assertStatus(200);

        // Balance should revert to 1000 (income was voided)
        $this->assertSame(1000.0, (float) $acc->fresh()->balance);
        $this->assertNull(Transaction::find($txId));
    }

    public function test_AX_08_non_admin_gets_403(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp',
            'email' => 'emp@tx.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        $acc = $this->seedAccount(['name' => 'TEST_AX08_Cashbox']);

        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $r = $this->postJson('/api/v1/finance/transactions', [
            'type' => 'income',
            'amount' => 100.0,
            'account_id' => $acc->id,
            'description' => 'TEST employee blocked',
        ]);
        $r->assertStatus(403);
    }
}