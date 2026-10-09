<?php

namespace Tests\Feature\Online;

use App\Models\Employee;
use App\Models\Online\OnlineTransaction;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * SEC-4 + SEC-3 remediation regression tests.
 *
 * SEC-4 (MEDIUM) — Idempotency on POST /api/v1/online/transactions:
 *   Confirms that the IETF-draft `Idempotency-Key` header (and the
 *   equivalent body field) protects against duplicate financial
 *   transactions on network retry / double-click. The implementation is
 *   the canonical 4-layer pattern used across the project (Hajj/Umra,
 *   Flight, Visa, Wallet, Bus):
 *     1. Pre-check inside DB::transaction (return existing row)
 *     1b. Soft-deleted row key release
 *     2. DB UNIQUE backstop (catch QueryException)
 *   A replay returns HTTP 200 with `idempotent_replay: true`; a fresh
 *   create returns HTTP 201 with `idempotent_replay: false`.
 *
 * SEC-3 (LOW) — Cross-resource IDOR on GET /online/transactions/{id}
 *   (and parallel PATCH / DELETE): an employee with `manage_online`
 *   permission but who is NOT the owning employee must NOT be able to
 *   view / edit / delete another cashier's transaction. The fix is the
 *   `OnlineTransactionPolicy` class — admin/owner OR owning employee.
 */
class OnlineIdempotencyAndOwnershipTest extends OnlineTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default acting user is the admin created by OnlineTestCase.
        // Per-test, we'll switch to the role under examination.
        $this->user->role = 'admin';
        $this->user->is_active = true;
        $this->user->save();
        Sanctum::actingAs($this->user, ['*']);
    }

    // ============================================================
    // SEC-4 — IDEMPOTENCY ON POST /online/transactions
    // ============================================================

    public function test_first_create_with_idempotency_key_returns_201_replay_false(): void
    {
        // A fresh create with an Idempotency-Key returns HTTP 201 +
        // idempotent_replay=false.
        $payload = $this->validPayload();
        $payload['idempotency_key'] = 'idem-fresh-001';

        $response = $this->postJson('/api/v1/online/transactions', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.idempotent_replay', false);
        $this->assertSame(1, OnlineTransaction::count());
    }

    public function test_replay_with_same_idempotency_key_returns_200_replay_true_and_no_duplicate(): void
    {
        // Re-sending the SAME payload + same Idempotency-Key returns HTTP
        // 200 + idempotent_replay=true and does NOT create a second row.
        $payload = $this->validPayload('01000002001');
        $payload['idempotency_key'] = 'idem-replay-001';

        $first = $this->postJson('/api/v1/online/transactions', $payload);
        $second = $this->postJson('/api/v1/online/transactions', $payload);

        $first->assertStatus(201);
        $first->assertJsonPath('data.idempotent_replay', false);

        $second->assertStatus(200);
        $second->assertJsonPath('data.idempotent_replay', true);

        // Body is the SAME row.
        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'Replay must return the original transaction id.',
        );
        $this->assertSame(1, OnlineTransaction::count(), 'No duplicate row created.');
    }

    public function test_replay_with_idempotency_header_returns_same_row(): void
    {
        // The IETF-draft HTTP header `Idempotency-Key` is the conventional
        // transport for the key. The header must work even if the body
        // does not include the key.
        $payload = $this->validPayload('01000002002');

        $first = $this->postJson('/api/v1/online/transactions', $payload, [
            'Idempotency-Key' => 'idem-header-001',
        ])->assertStatus(201);

        $second = $this->postJson('/api/v1/online/transactions', $payload, [
            'Idempotency-Key' => 'idem-header-001',
        ])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertTrue($second->json('data.idempotent_replay'));
        $this->assertSame(1, OnlineTransaction::count());
    }

    public function test_replay_does_not_post_duplicate_ledger_entries(): void
    {
        // Critical financial invariant: a replay must NOT post a second
        // set of GL entries (income + cash settlement + expense). The
        // vault balance after the replay must equal the balance after
        // the first create.
        $vaultBefore = $this->accountBalance($this->cashbox->id);
        $payload = $this->validPayload('01000002003');
        $payload['idempotency_key'] = 'idem-ledger-001';

        $this->postJson('/api/v1/online/transactions', $payload)->assertStatus(201);
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $this->assertNotEqualsWithDelta($vaultBefore, $vaultAfterFirst, 0.01);

        $this->postJson('/api/v1/online/transactions', $payload)->assertStatus(200);
        $vaultAfterReplay = $this->accountBalance($this->cashbox->id);

        // The replay must NOT have moved the vault again.
        $this->assertEqualsWithDelta(
            $vaultAfterFirst,
            $vaultAfterReplay,
            0.01,
            'Replay must not post duplicate ledger entries.',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_replay_with_same_key_different_payload_still_returns_original_row(): void
    {
        // The idempotency key identifies the OPERATION, not the payload.
        // A retry that mutates the payload (e.g. selling price) but
        // reuses the key must return the ORIGINAL row, not the mutated
        // one. This matches the IETF draft semantics: the key commits
        // the operation; subsequent payloads with the same key are
        // ignored.
        $payload = $this->validPayload('01000002004');
        $payload['idempotency_key'] = 'idem-stable-001';
        $payload['selling_price'] = 100;

        $first = $this->postJson('/api/v1/online/transactions', $payload)
            ->assertStatus(201)
            ->json('data');

        // Retry with the same key but DIFFERENT selling_price.
        $payload['selling_price'] = 999;
        $second = $this->postJson('/api/v1/online/transactions', $payload)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(100.0, (float) $second['selling_price'], 'Replay returns original row.');
        $this->assertSame(1, OnlineTransaction::count());
    }

    public function test_different_idempotency_keys_create_distinct_txs(): void
    {
        // Sanity: the key is what makes a replay a replay. Two POSTs
        // with DIFFERENT keys create two distinct rows.
        $payloadA = $this->validPayload('01000002005');
        $payloadA['idempotency_key'] = 'idem-A';
        $payloadB = $this->validPayload('01000002006');
        $payloadB['idempotency_key'] = 'idem-B';

        $a = $this->postJson('/api/v1/online/transactions', $payloadA);
        $b = $this->postJson('/api/v1/online/transactions', $payloadB);

        $a->assertStatus(201);
        $b->assertStatus(201);
        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(2, OnlineTransaction::count());
    }

    public function test_no_idempotency_key_preserves_legacy_behavior(): void
    {
        // Backward compat: when NO Idempotency-Key is supplied, the
        // endpoint keeps the original behavior (every POST = new row).
        // Legacy callers don't break.
        $payloadA = $this->validPayload('01000002007');
        $payloadB = $this->validPayload('01000002008');

        $a = $this->postJson('/api/v1/online/transactions', $payloadA);
        $b = $this->postJson('/api/v1/online/transactions', $payloadB);

        $a->assertStatus(201)->assertJsonPath('data.idempotent_replay', false);
        $b->assertStatus(201)->assertJsonPath('data.idempotent_replay', false);
        $this->assertSame(2, OnlineTransaction::count());
    }

    public function test_replay_after_soft_delete_releases_key_for_new_create(): void
    {
        // Edge case: if the original row was soft-deleted (cancelled),
        // the soft-deleted row's idempotency_key is released so a new
        // POST with the same key succeeds (creates a fresh row).
        $payload = $this->validPayload('01000002009');
        $payload['idempotency_key'] = 'idem-after-cancel';

        $first = $this->postJson('/api/v1/online/transactions', $payload)
            ->assertStatus(201)
            ->json('data');
        $this->service->delete(OnlineTransaction::find($first['id']));

        // Same key on a fresh POST → must create a NEW row, not replay
        // the soft-deleted one.
        $second = $this->postJson('/api/v1/online/transactions', $payload)
            ->assertStatus(201)
            ->json('data');
        $this->assertNotSame($first['id'], $second['id'], 'Soft-deleted row key must be released.');
        $this->assertFalse($second['idempotent_replay']);
    }

    public function test_replay_scoped_per_actor_prevents_cross_user_collision(): void
    {
        // The (created_by, idempotency_key) scope means user A's key
        // does NOT collide with user B's same key. (Each cashier uses
        // their own key namespace.)
        $payloadA = $this->validPayload('01000002010');
        $payloadA['idempotency_key'] = 'shared-key';
        $this->postJson('/api/v1/online/transactions', $payloadA)
            ->assertStatus(201);

        // Switch to a different admin user.
        $otherAdmin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($otherAdmin, ['*']);

        $payloadB = $this->validPayload('01000002011');
        $payloadB['idempotency_key'] = 'shared-key';
        $this->postJson('/api/v1/online/transactions', $payloadB)
            ->assertStatus(201)
            ->assertJsonPath('data.idempotent_replay', false);

        $this->assertSame(2, OnlineTransaction::count(), 'Per-actor scoping: no cross-user collision.');
    }

    // ============================================================
    // SEC-3 — OWNERSHIP-BASED ACCESS (Policy)
    // ============================================================

    public function test_show_returns_403_for_non_owner_employee_with_permission(): void
    {
        // The owning employee is the one who created the row. A
        // DIFFERENT employee with `manage_online` permission must NOT
        // be able to read it — that was the IDOR.
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        $employee = Employee::create([
            'user_id' => $cashier->id,
            'name' => 'موظف كاشير',
            'is_active' => true,
        ]);
        $cashier->setRelation('employee', $employee);
        $cashier->refresh();

        // The owning cashier creates the row.
        Sanctum::actingAs($cashier, ['*']);
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'employee_id' => $employee->id,
            'customer_name' => 'Owners Sale',
            'customer_phone' => '01000002100',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // A different employee with `manage_online` tries to view it.
        $otherEmployee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        $otherEmp = Employee::create([
            'user_id' => $otherEmployee->id,
            'name' => 'موظف آخر',
            'is_active' => true,
        ]);
        $otherEmployee->setRelation('employee', $otherEmp);
        $otherEmployee->refresh();

        Sanctum::actingAs($otherEmployee, ['*']);
        $response = $this->getJson("/api/v1/online/transactions/{$tx->id}");

        $response->assertStatus(403);
    }

    public function test_update_returns_403_for_non_owner_employee_with_permission(): void
    {
        // Same ownership rule for PATCH.
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        $employee = Employee::create([
            'user_id' => $cashier->id,
            'name' => 'موظف كاشير',
            'is_active' => true,
        ]);
        $cashier->setRelation('employee', $employee);
        $cashier->refresh();

        Sanctum::actingAs($cashier, ['*']);
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'employee_id' => $employee->id,
            'customer_name' => 'Owner Patch',
            'customer_phone' => '01000002101',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $otherEmployee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        $otherEmp = Employee::create([
            'user_id' => $otherEmployee->id,
            'name' => 'موظف ثانٍ',
            'is_active' => true,
        ]);
        $otherEmployee->setRelation('employee', $otherEmp);
        $otherEmployee->refresh();

        Sanctum::actingAs($otherEmployee, ['*']);
        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'selling_price' => 999,
        ]);
        $response->assertStatus(403);

        // And the row was NOT mutated.
        $tx->refresh();
        $this->assertSame(100.0, (float) $tx->selling_price, 'Forbidden PATCH must not mutate the row.');
    }

    public function test_owner_employee_can_view_and_patch_own_transaction(): void
    {
        // Counter-test: the legitimate cashier flow works — the
        // OWNING employee can view + edit their own transaction.
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_ONLINE],
        ]);
        $employee = Employee::create([
            'user_id' => $cashier->id,
            'name' => 'صاحب الصفقة',
            'is_active' => true,
        ]);
        $cashier->setRelation('employee', $employee);
        $cashier->refresh();

        Sanctum::actingAs($cashier, ['*']);
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'employee_id' => $employee->id,
            'customer_name' => 'My Sale',
            'customer_phone' => '01000002102',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $this->getJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
        $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'selling_price' => 150,
        ])->assertOk();
        $tx->refresh();
        $this->assertSame(150.0, (float) $tx->selling_price);
    }

    public function test_admin_can_view_any_transaction(): void
    {
        // Counter-test: admin oversight path works regardless of
        // ownership. (Default acting user is the admin created by
        // OnlineTestCase.)
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Admin View',
            'customer_phone' => '01000002103',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $this->getJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
    }

    public function test_admin_can_delete_any_transaction(): void
    {
        // DELETE defense-in-depth: the route already gates DELETE
        // behind `role:admin`. The policy is wired here too, and
        // admin should always pass.
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Admin Del Policy',
            'customer_phone' => '01000002104',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $this->deleteJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
    }

    /**
     * Standard valid payload factory for idempotency tests.
     */
    protected function validPayload(string $phone = '01000002000'): array
    {
        return [
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'Idem Client',
            'customer_phone' => $phone,
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ];
    }
}
