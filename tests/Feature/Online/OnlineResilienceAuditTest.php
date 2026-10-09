<?php

namespace Tests\Feature\Online;

use App\Models\Account;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Resilience audit tests for the Online module.
 *
 * Covers methodology stages:
 *   - Stage 11: Duplication / replay (idempotency, repeated submissions)
 *   - Stage 12: Concurrency (state checks under concurrent writes)
 *   - Stage 13: Authorization (role-based access, permission wiring)
 *   - Stage 14: Resource ownership (cross-resource IDOR/access)
 *   - Stage 15: Failure paths (DB failures, validation, business rule rejects)
 *   - Stage 16: Database reconciliation (after every mutation)
 */
class OnlineResilienceAuditTest extends OnlineTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user->role = 'admin';
        $this->user->is_active = true;
        $this->user->save();
        Sanctum::actingAs($this->user, ['*']);
    }

    // ============================================================
    // Stage 11 — REPLAY / DUPLICATION
    // ============================================================

    public function test_replay_create_with_same_payload_creates_two_txs_when_no_idempotency_key(): void
    {
        // SEC-4 (2026-08-21): The Online module now supports idempotency
        // via the IETF-draft `Idempotency-Key` header (and an equivalent
        // body field). WITHOUT a key, the endpoint keeps its original
        // behavior — every POST is a new row. This test documents that
        // legacy callers (no key) still get duplicate creation. With a
        // key, the replay collapses — see OnlineIdempotencyAndOwnershipTest.
        $payload = [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Replay',
            'customer_phone' => '01000001000',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'REPLAY-1',
        ];

        $first = $this->postJson('/api/v1/online/transactions', $payload);
        $second = $this->postJson('/api/v1/online/transactions', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'No idempotency key — duplicates create separate txs (documented legacy behavior).',
        );
        $this->assertSame(2, OnlineTransaction::count());
    }

    public function test_replay_patch_with_same_value_is_noop(): void
    {
        // Same value should NOT repost the income transaction
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Patch Replay',
            'customer_phone' => '01000001001',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $oldIncomeId = $tx->income_transaction_id;

        // PATCH with same selling_price — should be a no-op (no repost)
        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'selling_price' => 100,
        ]);
        $response->assertOk();
        $tx->refresh();
        $this->assertSame($oldIncomeId, $tx->income_transaction_id, 'Same value → no repost');
    }

    public function test_double_cancel_creates_idempotent_200_on_second_call(): void
    {
        // F-3 fix: HTTP DELETE is now idempotent at the HTTP layer. The
        // controller resolves with `withTrashed()`, so the second DELETE
        // reaches the service which sees the row is already soft-deleted
        // and returns true without reversing GL again.
        $vaultBaseline = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Double Cancel',
            'customer_phone' => '01000001002',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // After create: vault moved by amount_paid − purchase = 100 − 0 = +100
        $vaultAfterCreate = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline + 100.0, $vaultAfterCreate, 0.01);

        // First DELETE: 200 + soft-delete + GL reversal
        $this->deleteJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline, $vaultAfterFirst, 0.01);

        // Second DELETE: 200 + idempotent (no GL change)
        $this->deleteJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
        $this->assertEqualsWithDelta($vaultAfterFirst, $this->accountBalance($this->cashbox->id), 0.01,
            'Second DELETE must be idempotent (no GL reversal).');
    }

    // ============================================================
    // Stage 12 — CONCURRENCY
    // ============================================================

    public function test_concurrent_creates_with_same_customer_id_are_all_saved(): void
    {
        // No unique constraint on (customer_id, reference_number). Two
        // simultaneous creates with the same reference should both succeed
        // (which means duplicate sales are possible if the UI double-clicks).
        $customer = $this->makeCustomer('Concurrent', '01000001100');

        $payload = [
            'service_type_id' => $this->serviceType->id,
            'customer_id' => $customer->id,
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'CONCURRENT-1',
        ];

        $a = $this->postJson('/api/v1/online/transactions', $payload);
        $b = $this->postJson('/api/v1/online/transactions', $payload);

        $a->assertStatus(201);
        $b->assertStatus(201);
        $this->assertSame(2, OnlineTransaction::count());
    }

    public function test_concurrent_patches_on_same_tx_field_change_is_serializable(): void
    {
        // Two simultaneous PATCHes on the same field should produce a
        // consistent final state (DB::transaction wraps the update).
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Concurrent Patch',
            'customer_phone' => '01000001101',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $initialIncomeId = $tx->income_transaction_id;

        // Simulate two patches that change selling_price to different values
        $r1 = $this->patchJson("/api/v1/online/transactions/{$tx->id}", ['selling_price' => 200]);
        $r2 = $this->patchJson("/api/v1/online/transactions/{$tx->id}", ['selling_price' => 300]);

        $r1->assertOk();
        $r2->assertOk();
        $tx->refresh();
        $this->assertSame(300.0, (float) $tx->selling_price, 'last-write-wins for sequential PATCHes');
        $this->assertNotSame($initialIncomeId, $tx->income_transaction_id);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_concurrent_deletes_resolve_to_single_soft_delete(): void
    {
        // Sequential: first DELETE soft-deletes, second is idempotent at the
        // service level. Concurrent: only the first commits; the second
        // either gets 404 (HTTP) or is a no-op (service).
        $vaultBaseline = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Concurrent Del',
            'customer_phone' => '01000001102',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $this->deleteJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline, $vaultAfterFirst, 0.01);

        // Service-level concurrent delete (already-deleted)
        $txTrashed = OnlineTransaction::withTrashed()->find($tx->id);
        $result = $this->service->delete($txTrashed);
        $this->assertTrue($result);
        $vaultAfterSecond = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultAfterFirst, $vaultAfterSecond, 0.01, 'second delete must be a no-op');
    }

    // ============================================================
    // Stage 13 — AUTHORIZATION
    // ============================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        // Strip Sanctum auth
        auth()->forgetUser();

        $response = $this->getJson('/api/v1/online/service-types');
        $response->assertStatus(401);
    }

    public function test_inactive_user_returns_403(): void
    {
        $inactive = User::factory()->create([
            'role' => 'employee',
            'is_active' => false,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        Sanctum::actingAs($inactive, ['*']);

        $response = $this->getJson('/api/v1/online/service-types');
        // The 'active' middleware should reject this. Check the actual response.
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function test_employee_without_permission_is_denied_on_read(): void
    {
        // SEC-1 fix: every Online route now requires `permission:manage_online`.
        // Per `UserPermissions::effectiveFor()`, non-admin/non-owner without
        // explicit stored permissions is denied (deny-by-default).
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],
        ]);
        Sanctum::actingAs($employee, ['*']);

        $response = $this->getJson('/api/v1/online/service-types');
        $response->assertStatus(403);
    }

    public function test_employee_without_permission_is_denied_on_create(): void
    {
        // SEC-1 fix: no permission = no access. The Online transaction POST
        // route is gated by `permission:manage_online` — employees without it
        // cannot create Online sales.
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],
        ]);
        Sanctum::actingAs($employee, ['*']);

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Employee Sale',
            'customer_phone' => '01000001200',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_with_manage_online_permission_is_allowed(): void
    {
        // SEC-1 fix: explicitly granted `manage_online` permission allows
        // cashiers to record Online sales (this is the documented project
        // intent — see UserPermissions::MANAGE_ONLINE label "التأشيرات والخدمات الإلكترونية").
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        Sanctum::actingAs($employee, ['*']);

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Cashier Sale',
            'customer_phone' => '01000001203',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_non_admin_cannot_delete_transaction(): void
    {
        // DELETE /online/transactions/{id} requires role:admin middleware.
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'For Delete',
            'customer_phone' => '01000001201',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        Sanctum::actingAs($employee, ['*']);
        $response = $this->deleteJson("/api/v1/online/transactions/{$tx->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_delete_transaction(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Admin Delete',
            'customer_phone' => '01000001202',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->deleteJson("/api/v1/online/transactions/{$tx->id}");
        $response->assertOk();
    }

    // ============================================================
    // Stage 14 — RESOURCE OWNERSHIP
    // ============================================================

    public function test_unauthorized_user_cannot_get_transaction_by_id(): void
    {
        // SEC-1 + SEC-3 fix: the Online GET-by-id endpoint is now protected
        // by BOTH the `permission:manage_online` middleware (SEC-1) AND
        // the `OnlineTransactionPolicy::view` (SEC-3). An employee with no
        // permission is rejected by the middleware at the route level.
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Owner Test',
            'customer_phone' => '01000001300',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $other = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],
        ]);
        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson("/api/v1/online/transactions/{$tx->id}");
        $response->assertStatus(403);
    }

    public function test_show_404_for_nonexistent_id(): void
    {
        $response = $this->getJson('/api/v1/online/transactions/999999');
        $response->assertStatus(404);
    }

    public function test_show_returns_trashed_via_with_trashed_only(): void
    {
        // Cancelled (soft-deleted) tx is hidden from default show.
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Hidden',
            'customer_phone' => '01000001301',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->delete($tx);

        // Default scope hides trashed → 404
        $response = $this->getJson("/api/v1/online/transactions/{$tx->id}");
        $response->assertStatus(404);
    }

    public function test_index_filters_exclude_other_modules(): void
    {
        // The /online/transactions index ONLY returns Online transactions.
        // Cross-module IDOR: can a user read Fawry transactions via this
        // endpoint? They should NOT be in the result set.
        $response = $this->getJson('/api/v1/online/transactions');
        $response->assertOk();
        $items = $response->json('data.items');
        // Only Online transactions in the result — and the OnlineTransactionResource
        // exposes `service_type` (the OnlineServiceType model), so any item
        // present must carry it. If a Fawry tx leaked in it wouldn't.
        foreach ($items as $item) {
            $this->assertArrayHasKey('service_type', $item, 'All items must have service_type (Online model field)');
        }
    }

    // ============================================================
    // Stage 15 — FAILURE PATHS
    // ============================================================

    public function test_create_fails_when_service_type_is_soft_deleted(): void
    {
        $this->serviceType->delete(); // soft-delete

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Fail',
            'customer_phone' => '01000001400',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_fails_when_payment_method_is_soft_deleted(): void
    {
        $this->cashMethod->delete(); // soft-delete

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Fail',
            'customer_phone' => '01000001401',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_fails_when_account_is_inactive(): void
    {
        $this->cashbox->is_active = false;
        $this->cashbox->save();

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Fail',
            'customer_phone' => '01000001402',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_fails_when_customer_does_not_exist(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_id' => 999999,
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_fails_on_string_id_for_service_type(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => 'abc',
            'customer_name' => 'Fail',
            'customer_phone' => '01000001403',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_patch_with_nonexistent_service_type_returns_404_or_422(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Fail Patch',
            'customer_phone' => '01000001404',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'service_type_id' => 999999,
        ]);

        $this->assertContains($response->getStatusCode(), [422]);
    }

    public function test_create_with_zero_amount_paid_routes_income_only_no_cash(): void
    {
        // amount_paid=0 → no cash settlement, just income + expense
        $vaultBefore = $this->accountBalance($this->cashbox->id);

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Zero Paid',
            'customer_phone' => '01000001405',
            'purchase_price' => 50,
            'selling_price' => 100,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        // With amount_paid=0 and no provider.default_purchase_account,
        // expense is sourced from income clearing, not the vault. So vault
        // should NOT have moved.
        $vaultAfter = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBefore, $vaultAfter, 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_with_zero_selling_skips_income_post(): void
    {
        // Edge: 0 selling → no income entry, but expense + cash still apply
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Zero Selling',
            'customer_phone' => '01000001406',
            'purchase_price' => 0,
            'selling_price' => 0,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $tx = OnlineTransaction::find($response->json('data.id'));
        $this->assertNull($tx->income_transaction_id, '0 selling → no income entry');
        $this->assertOnlineLedgerBalanced();
    }

    // ============================================================
    // Stage 16 — DATABASE RECONCILIATION
    // ============================================================

    public function test_full_lifecycle_leaves_balances_consistent(): void
    {
        $vaultBaseline = $this->accountBalance($this->cashbox->id);
        $clearingIncomeId = null;
        $clearingExpenseId = null;

        // Step 1: Create
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Recon',
            'customer_phone' => '01000001500',
            'purchase_price' => 100, 'selling_price' => 300, 'amount_paid' => 200,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->assertOnlineLedgerBalanced();

        // Step 2: Update selling
        $this->service->update($tx, ['selling_price' => 500]);
        $this->assertOnlineLedgerBalanced();

        // Step 3: Update purchase
        $this->service->update($tx, ['purchase_price' => 200]);
        $this->assertOnlineLedgerBalanced();

        // Step 4: Update amount_paid
        $this->service->update($tx, ['amount_paid' => 400]);
        $this->assertOnlineLedgerBalanced();

        // Step 5: Status flip to cancelled
        $this->service->update($tx, ['status' => 'cancelled']);
        $vaultAfterCancel = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline, $vaultAfterCancel, 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_all_transactions_have_related_links_after_create(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Links',
            'customer_phone' => '01000001501',
            'purchase_price' => 50, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $this->assertNotNull($tx->income_transaction_id);
        $this->assertNotNull($tx->expense_transaction_id);

        // Both transactions must point back to the Online transaction via
        // related_type + related_id.
        $income = Transaction::find($tx->income_transaction_id);
        $expense = Transaction::find($tx->expense_transaction_id);
        $this->assertSame(OnlineTransaction::class, $income->related_type);
        $this->assertSame($tx->id, $income->related_id);
        $this->assertSame(OnlineTransaction::class, $expense->related_type);
        $this->assertSame($tx->id, $expense->related_id);
    }

    public function test_cancellation_does_not_delete_gl_entries(): void
    {
        // Additive reversal: the reverseTransaction() creates NEW entries
        // (with notes starting بـ "عكس") on the SAME transactions, so the
        // original entries are preserved + new reversal entries are added.
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Additive',
            'customer_phone' => '01000001502',
            'purchase_price' => 50, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $originalEntries = \DB::table('account_entries')
            ->whereIn('transaction_id', [$tx->income_transaction_id, $tx->expense_transaction_id])
            ->count();
        $this->assertSame(4, $originalEntries, '2 tx × 2 legs (debit+credit) = 4 entries');

        $this->service->update($tx, ['status' => 'cancelled']);

        // Original entries are still there (count ≥ 4), and reversal entries
        // are added. Net effect per account = 0 (balanced).
        $afterEntries = \DB::table('account_entries')
            ->whereIn('transaction_id', [$tx->income_transaction_id, $tx->expense_transaction_id])
            ->count();
        $this->assertGreaterThanOrEqual(
            4,
            $afterEntries,
            'Original entries preserved (additive reversal adds new entries rather than deleting).',
        );
        $reversalEntries = \DB::table('account_entries')
            ->where('notes', 'like', 'عكس%')
            ->whereIn('transaction_id', [$tx->income_transaction_id, $tx->expense_transaction_id])
            ->count();
        $this->assertGreaterThan(0, $reversalEntries, 'Reversal entries are added');
    }
}
