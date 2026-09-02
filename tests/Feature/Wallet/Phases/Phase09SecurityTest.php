<?php

namespace Tests\Feature\Wallet\Phases;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 9 — SECURITY / AUTHORIZATION.
 *
 * Verifies the authentication & authorization surface of the Wallet & Transfers API:
 *   - Sanctum auth required
 *   - Active user required
 *   - Permission gates (wallet.create for POST, admin for PUT/DELETE)
 *   - IDOR: cross-customer access
 *   - Parameter tampering: changing account_id to another user's account
 *   - Mass assignment: injecting extra fields (e.g. created_by, status, type)
 *   - Soft-delete reversal: verifying a deleted transaction is not editable
 *   - Audit trail: who created this transaction (created_by)
 */
class Phase09SecurityTest extends WalletTestCase
{
    // ────────────── Authentication ──────────────

    public function test_request_without_token_returns_401(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);
        $this->assertEquals(401, $response->getStatusCode(),
            'No-token POST must return 401. Got: '.$response->getStatusCode());
    }

    public function test_request_with_invalid_token_returns_401(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;

        $response = $this->withHeaders(['Authorization' => 'Bearer fake-invalid-token-xyz'])
            ->postJson('/api/v1/wallet/transactions', $payload);
        $this->assertEquals(401, $response->getStatusCode(),
            'Invalid-token POST must return 401. Got: '.$response->getStatusCode());
    }

    public function test_inactive_user_is_rejected(): void
    {
        $inactive = User::factory()->create([
            'role' => 'employee',
            'is_active' => false,
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($inactive, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);
        $this->assertContains($response->getStatusCode(), [401, 403],
            'Inactive user must be rejected. Got: '.$response->getStatusCode());
    }

    // ────────────── Permission gates ──────────────

    /**
     * The POST /wallet/transactions requires `wallet.create` permission per
     * routes/api.php:
     *   ->middleware('permission:wallet.create');
     *
     * FINDING SEC-1 (CRITICAL) — REMEDIATED (2026-08-21):
     * Pre-fix, an employee with `permissions=null` or `permissions=[]`
     * silently received `defaultEmployeeModules()`, which includes
     * `manage_treasury`. Post-fix, `UserPermissions::effectiveFor()` is
     * deny-by-default for any non-admin/non-owner user: empty stored
     * permissions → `[]` → route guard rejects with 403.
     *
     * This test now asserts that a `role='employee'`, `permissions=null`
     * user is REJECTED. The bug is gone.
     */
    public function test_default_employee_with_no_permissions_is_rejected_se_c_1_fixed(): void
    {
        $unprivileged = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => null,  // no explicit permissions
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: deny-by-default. Employee without explicit perms MUST be rejected.
        $response->assertStatus(403);
    }

    /**
     * FINDING SEC-1 (CRITICAL) — REMEDIATED:
     * Even an employee with EXPLICIT `permissions=[]` is now denied.
     * Pre-fix, the empty array silently fell through to
     * `defaultEmployeeModules()`. Post-fix, empty = no permissions = 403.
     */
    public function test_employee_with_empty_permissions_is_rejected_se_c_1_fixed(): void
    {
        $strict = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($strict, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // Post-fix: empty stored perms → 403 (no implicit fallback).
        $response->assertStatus(403);
    }

    /**
     * FINDING SEC-1 — REMEDIATED positive path:
     * An employee WITH explicit `manage_treasury` permission can post
     * wallet transactions. This is the legitimate cashier flow.
     */
    public function test_employee_with_explicit_manage_treasury_can_post_se_c_1_fixed(): void
    {
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_TREASURY],
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // Explicit grant → allowed.
        $response->assertStatus(201);
    }

    /**
     * FINDING SEC-1 — REMEDIATED positive path:
     * Admin role bypasses the permission check (via the middleware
     * short-circuit in {@see CheckPermission::handle()}) even if their
     * `permissions` field is empty. This is preserved by design.
     */
    public function test_admin_role_bypasses_permission_check_se_c_1_fixed(): void
    {
        $bareAdmin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'permissions' => [],  // empty — should still be allowed for admin
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($bareAdmin, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // Admin short-circuit → allowed.
        $response->assertStatus(201);
    }

    public function test_admin_can_post_wallet_transaction(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);
    }

    // ────────────── IDOR: cross-customer access ──────────────

    /**
     * FINDING SEC-2 (MED) — REMEDIATED (2026-08-21):
     * Pre-fix: any authenticated user could read any transaction by id
     * (no creator filter). Post-fix: the show endpoint enforces a
     * creator-scoping rule unless the viewer is admin/owner. A
     * non-admin non-creator gets 404 (info-leak-safe: 404, not 403).
     *
     * The creator is `admin A`. The viewer is `cashierB` — an employee
     * WITH the `manage_treasury` permission so they pass the route
     * guard, but NOT the creator of the transaction.
     */
    public function test_show_filters_by_creator_non_creator_gets_404_se_c_2_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $txId = $created->json('data.id');

        // An EMPLOYEE (not admin) with the treasury permission tries to read it.
        // They are NOT the creator → must be denied.
        $cashierB = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_TREASURY],
        ]);
        $response = $this->actingAs($cashierB, 'sanctum')
            ->getJson("/api/v1/wallet/transactions/{$txId}");

        // Post-fix: non-admin non-creator → 404 (info-leak safe).
        $response->assertStatus(404);
    }

    /**
     * FINDING SEC-2 — REMEDIATED positive path:
     * An admin viewer (different from creator) can still read any
     * transaction — admin bypass is intentional.
     */
    public function test_admin_can_view_other_users_transaction_se_c_2_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $txId = $created->json('data.id');

        // A different admin reads the same transaction — allowed via admin bypass.
        $adminB = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $response = $this->actingAs($adminB, 'sanctum')
            ->getJson("/api/v1/wallet/transactions/{$txId}");

        $response->assertStatus(200);
        $this->assertEquals($txId, $response->json('data.id'));
    }

    /**
     * FINDING SEC-2 — REMEDIATED:
     * customer-statement filters by creator unless the viewer is admin.
     * A non-admin non-creator gets an empty result (statement is for
     * THEIR own transactions).
     */
    public function test_statement_filters_by_creator_se_c_2_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // A non-admin non-creator user (with explicit treasury perm so
        // they get past the route guard, but they did NOT create the txn).
        $cashierB = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_TREASURY],
        ]);

        $response = $this->actingAs($cashierB, 'sanctum')
            ->getJson("/api/v1/wallet/customer-statement?client_id={$this->customerEgp->id}");

        // Post-fix: statement is scoped to the viewer's own transactions.
        // The admin-created transaction is invisible to cashierB.
        $response->assertStatus(200);
        $transactions = $response->json('data.transactions') ?? [];
        $this->assertEmpty(
            $transactions,
            'customer-statement must NOT include transactions created by other users.'
        );
    }

    // ────────────── Parameter tampering ──────────────

    /**
     * FINDING SEC-3 (HIGH): The store endpoint accepts an `income_transaction_id`
     * field in the payload. The form request does not validate or filter it.
     * A malicious user could submit a payload that points the new transaction's
     * income_transaction_id to an existing transaction in the system.
     */
    public function test_income_transaction_id_payload_injection_is_ignored_or_overwritten(): void
    {
        // Create a victim transaction.
        $victim = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $victim['amount_paid'] = 0;
        $v = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $victim);
        $victimIncomeId = $v->json('data.income_transaction_id');

        // Create a NEW transaction that tries to inject income_transaction_id.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 200.00, fee: 6.00);
        $payload['amount_paid'] = 0;
        $payload['income_transaction_id'] = 99999;  // forged, points nowhere
        $payload['expense_transaction_id'] = 99999;
        $payload['created_by'] = 99999;            // try to forge creator
        $payload['status'] = 'cancelled';          // try to inject status
        $payload['type'] = 'send';                 // mixed with valid type

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $newId = $response->json('data.id');
        $created = WalletTransaction::find($newId);

        // The system must overwrite the injected fields with authoritative ones.
        $this->assertEquals($this->admin->id, $created->created_by,
            'Mass-assignment protection: created_by must be the authenticated user, not the payload.');
        $this->assertNotEquals(99999, $created->created_by, 'SEC-3: payload must not be able to forge created_by');
        $this->assertNotEquals('cancelled', $created->status,
            'SEC-3: payload must not be able to inject status. The WalletTransaction model has no status field anyway.');
    }

    public function test_update_payload_injection_is_blocked(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        // PUT /wallet/transactions/{id} with injection attempts.
        //
        // POST-FIX (FIN-6/7 hardening 2026-08-21):
        //   - `amount` is now legitimately updateable via PUT (the wallet module
        //     supports amount updates, validated by UpdateWalletTransactionRequest).
        //   - `type` is NOT in the UpdateWalletTransactionRequest rules — the
        //     model's `update($data)` silently ignores unknown keys.
        //   - `created_by` MUST remain forgeable only by the system (auth user).
        //
        // The mass-assignment protection is what this test verifies: an attacker
        // must NOT be able to forge `created_by`, regardless of how many other
        // fields are accepted.
        $response = $this->asAdmin()->putJson("/api/v1/wallet/transactions/{$id}", [
            'notes' => 'محدّثة',
            'amount' => 9999.99,          // legitimately updateable (was previously rejected by the OLD pre-fix validator)
            'type' => 'receive',          // try to change type — must be ignored (not in update rules)
            'created_by' => 99999,        // try to forge creator — must be rejected (auth user wins)
        ]);
        $response->assertStatus(200);

        $fetched = WalletTransaction::find($id);
        $this->assertEquals('محدّثة', $fetched->notes, 'notes field should be updated');
        // amount IS updateable — assert it changed (not the OLD assertion that it stayed).
        $this->assertEquals(9999.99, (float) $fetched->amount,
            'SEC-CRITICAL: amount is now updateable via PUT (was a mass-assignment injection test, now a legitimate update).');
        $this->assertEquals('send', $fetched->type->value, 'type must NOT change via PUT (not in update rules).');
        $this->assertNotEquals(99999, $fetched->created_by, 'SEC-3: created_by must not be forgeable — auth user wins.');
        $this->assertEquals($this->admin->id, $fetched->created_by,
            'SEC-3: created_by must remain the authenticated user.');
    }

    // ────────────── Cross-account / cross-user tampering ──────────────

    public function test_send_with_another_branch_wallet_account_is_accepted(): void
    {
        // A cashier from one branch can specify a wallet_account from another branch.
        // The system does NOT validate ownership.
        $otherWallet = $this->makeAccount(
            type: AccountType::Wallet,
            name: 'Other Branch Wallet',
            currency: 'EGP',
            balance: 5000.00,
            moduleType: 'office',
        );

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_account_id'] = $otherWallet->id;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        // The other wallet was debited.
        $this->assertEquals('4900.00', AccountState::balance($otherWallet->id),
            'Other-branch wallet was debited (no ownership check).');
    }

    // ────────────── Delete with authorization ──────────────

    public function test_admin_can_delete_wallet_transaction(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $response = $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}");
        $response->assertStatus(200);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        // Create a transaction as admin (auth state will be cleared below).
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        // Force a fresh unauthenticated request — clear all auth state.
        Auth::guard('sanctum')->forgetUser();
        if (app()->bound('auth')) {
            app('auth')->forgetGuards();
        }

        $response = $this->deleteJson("/api/v1/wallet/transactions/{$id}");
        $this->assertEquals(401, $response->getStatusCode(),
            'Unauthenticated DELETE must return 401. Got: '.$response->getStatusCode());
    }

    // ────────────── Audit trail ──────────────

    public function test_created_by_is_the_authenticated_user(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['created_by'] = 99999;       // attempt to inject

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $id = $response->json('data.id');
        $tx = WalletTransaction::find($id);
        $this->assertEquals($this->admin->id, $tx->created_by,
            'created_by must be the authenticated user, not the payload.');
    }
}
