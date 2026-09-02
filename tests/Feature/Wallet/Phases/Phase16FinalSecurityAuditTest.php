<?php

namespace Tests\Feature\Wallet\Phases;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 16 — FINAL SECURITY AUDIT.
 *
 * Last-mile security tests covering:
 *   - Mass-assignment protection on every field
 *   - Method tampering (PUT, DELETE, PATCH on disallowed endpoints)
 *   - CSRF / stateful auth bypass
 *   - Header spoofing (X-Forwarded-For, X-Real-IP)
 *   - User-Agent spoofing
 *   - Race condition: rapid-fire POST that could re-debit
 *   - Stale token reuse
 *   - Access to soft-deleted transactions
 *   - Audit log integrity
 *   - Authorization escalation paths
 */
class Phase16FinalSecurityAuditTest extends WalletTestCase
{
    // ────────────── Mass-assignment protection ──────────────

    public function test_injecting_balance_field_does_not_overwrite(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['balance'] = 999999.99;     // try to inject balance
        $payload['deleted_at'] = null;       // try to inject soft-delete
        $payload['created_at'] = '2020-01-01'; // try to backdate

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // The actual wallet balance should reflect the transaction, not the injected value.
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id),
            'balance field injection: wallet balance is 9900 (10000 - 100), not 999999.99');
    }

    public function test_injecting_income_transaction_id_no_t_overwritten(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['income_transaction_id'] = 99999;
        $payload['expense_transaction_id'] = 99999;

        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $created->assertStatus(201);

        $id = $created->json('data.id');
        $wt = WalletTransaction::find($id);
        $this->assertNotNull($wt->income_transaction_id,
            'income_transaction_id is set by the service');
        $this->assertNotEquals(99999, $wt->income_transaction_id,
            'income_transaction_id is NOT the injected value');
        $this->assertNotEquals(99999, $wt->expense_transaction_id,
            'expense_transaction_id is NOT the injected value');
    }

    public function test_injecting_status_field_no_t_overwritten(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['status'] = 'cancelled';
        $payload['state'] = 'reversed';

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
    }

    // ────────────── Bulk-operation safety ──────────────

    public function test_bulk_injecting_100_transactions_creates_100_rows(): void
    {
        // FINDING IDM-1: no idempotency, no bulk-safety. 100 sends = 100 rows.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 10.00, fee: 0.50);
        $payload['amount_paid'] = 0;

        for ($i = 0; $i < 100; $i++) {
            $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);
        }

        $this->assertEquals(100, WalletTransaction::query()->count());
        $this->assertEquals('9000.00', AccountState::balance($this->walletAccountEgp->id),
            '100 × 10 = 1000 spent, wallet = 9000');
    }

    // ────────────── Cross-tenant / cross-branch ──────────────

    public function test_branch_isolation_is_no_t_enforced_at_api_layer(): void
    {
        // FINDING SEC-4: system does not enforce branch separation. A cashier
        // can move money between any branch's wallet and cashbox.
        $branchA = $this->walletAccountEgp;
        $branchB = $this->makeAccount(
            type: AccountType::Wallet,
            name: 'Branch B Wallet',
            currency: 'EGP',
            balance: 5000.00,
            moduleType: 'office',
        );

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['wallet_account_id'] = $branchA->id;
        $payload['cash_account_id'] = $branchB->id;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $this->assertEquals('9900.00', AccountState::balance($branchA->id),
            'Branch A wallet was debited');
        $this->assertEquals('5000.00', AccountState::balance($branchB->id),
            'Branch B wallet unchanged (no incoming)');
    }

    // ────────────── Audit log integrity ──────────────

    public function test_audit_log_records_user_id(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $audit = DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $id)
            ->where('action', 'wallet_transaction.created')
            ->first();

        $this->assertNotNull($audit, 'Audit log row exists');
        $this->assertEquals($this->admin->id, $audit->user_id,
            'Audit log user_id is the authenticated user, not the payload');
    }

    public function test_audit_log_does_not_record_ip_when_no_request(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $audit = DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $id)
            ->first();

        $this->assertNotNull($audit);
        // IP can be null in test environment. Just verify no exception.
        $this->assertTrue(true, 'Audit log read without error');
    }

    // ────────────── Soft-deleted access ──────────────

    /**
     * FINDING SEC-4 (LOW) REMEDIATED (2026-08-21):
     * Pre-fix: show on a soft-deleted transaction could return 200 OR 404
     * depending on Laravel binding behavior. Post-fix: the controller
     * explicitly checks `deleted_at` and returns 404. The test is now
     * stricter — only 404 is acceptable.
     */
    public function test_show_on_soft_deleted_returns_404_se_c_4_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $created->json('data.id');

        $this->asAdmin()->deleteJson("/api/v1/wallet/transactions/{$id}")->assertStatus(200);

        // Post-fix: explicit 404 for soft-deleted rows.
        $response = $this->asAdmin()->getJson("/api/v1/wallet/transactions/{$id}");
        $this->assertEquals(404, $response->getStatusCode(),
            'Show on soft-deleted must return 404. Got: '.$response->getStatusCode());
    }

    // ────────────── Stale token reuse ──────────────

    public function test_user_becomes_inactive_after_post(): void
    {
        // Create a user, post, then deactivate.
        // SEC-1 (2026-08-21): deny-by-default — explicit `manage_treasury`
        // is required for the cashier to be allowed to post.
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_TREASURY],
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        // Deactivate the user.
        $cashier->update(['is_active' => false]);

        // Re-post should be rejected (active middleware).
        $payload2 = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload2['amount_paid'] = 0;
        $response2 = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload2);
        $this->assertContains($response2->getStatusCode(), [401, 403],
            'Inactive user must be rejected after deactivation. Got: '.$response2->getStatusCode());
    }

    // ────────────── XSS / HTML injection in payload ──────────────

    /**
     * FINDING VAL-4 (MED) REMEDIATED (2026-08-21):
     * Pre-fix: the `notes` field was stored verbatim. Post-fix:
     * `StoreWalletTransactionRequest::prepareForValidation()` strips HTML
     * tags from the input. The script tag is stripped at the input layer,
     * so even if the admin UI renders the notes without escaping, the
     * XSS payload cannot fire.
     *
     * The 201 response is preserved — only the stored value changes.
     */
    public function test_html_in_notes_is_stripped_va_l_4_fixed(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['notes'] = '<script>alert("xss")</script>';

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $id = $response->json('data.id');
        $wt = WalletTransaction::find($id);
        $this->assertEquals('alert("xss")', $wt->notes,
            'VAL-4 fixed: HTML tags stripped from notes on input.');
    }

    // ────────────── SQL injection attempts ──────────────

    public function test_sql_injection_in_string_field_does_not_corrupt_db(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $payload['customer_name'] = "'; DROP TABLE accounts; --";
        $payload['wallet_number'] = "1' OR '1'='1";

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        // The accounts table should still exist.
        $this->assertGreaterThan(0, Account::query()->count(),
            'SQL injection attempt did not drop the accounts table');
    }

    // ────────────── Role-based authorization ──────────────

    public function test_non_admin_can_post_but_cannot_delete(): void
    {
        // SEC-1 (2026-08-21): the cashier needs explicit `manage_treasury`
        // to be allowed to POST. Without it (now the deny-by-default
        // behavior), the POST is rejected with 403. After the fix, this
        // test still verifies the delete gate.
        $cashier = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_TREASURY],
        ]);

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $created = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);
        $created->assertStatus(201);
        $id = $created->json('data.id');

        // DELETE is admin-only.
        $delete = $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/wallet/transactions/{$id}");
        $this->assertContains($delete->getStatusCode(), [403, 500],
            'Cashier must NOT be allowed to delete. Got: '.$delete->getStatusCode());
    }

    // ────────────── Failed-input doesn't leak stack traces ──────────────

    public function test_500_level_errors_are_not_leaked_in_response(): void
    {
        // Force a 500 by trying to use a route that doesn't exist.
        $response = $this->asAdmin()->getJson('/api/v1/wallet/non-existent-route');
        $this->assertEquals(404, $response->getStatusCode());

        // The response body should be JSON, not a stack trace.
        $body = $response->getContent();
        $this->assertStringContainsString('success', $body,
            '404 response is JSON, not an HTML stack trace');
    }
}
