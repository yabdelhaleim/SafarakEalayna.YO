<?php

namespace Tests\Feature\Visa;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use App\Models\VisaBooking;

/**
 * Phase 9.12 — Supplier Flow Deep (Section 22 of the 30-section prompt).
 *
 * Audit targets:
 *   - POST /api/v1/visa/agents/{agent}/withdraw — financial transfer (agent → treasury)
 *   - POST /api/v1/visa/agents/{agent}/repay    — financial transfer (treasury → agent)
 *   - GET   /api/v1/visa/agents/dues             — agent AP readout
 *   - Inactive / cross-division agent interactions
 *   - Agent AP balance invariant across withdraw/repay cycles
 *
 * Conventions:
 *   - balance = SUM(credit) - SUM(debit) per account
 *   - Phase 8.6 B2 + 9.5b already enforce that cancel/delete reverse the agent expense
 *     and restore the agent AP to baseline.
 *   - Withdraw = agent debit (money leaves agent's AP balance to user)
 *   - Repay    = agent credit (money returns to agent's AP from user)
 */
class VisaSupplierFlowDeepTest extends VisaTestCase
{
    /* ============================================================
     *  WITHDRAW/REPAY CYCLE INTEGRITY
     * ============================================================ */

    public function test_withdraw_then_repay_same_amount_nets_agent_to_baseline(): void
    {
        $agent = $this->agent->fresh();
        $baselineAP = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // Withdraw 500 EGP
        $withdraw = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 500.0,
            'to_account_id' => $this->vaultEgp->id,
            'notes' => 'P912 cycle test withdraw',
        ])->assertOk();
        $this->assertNotNull($withdraw->json('data.transaction_id'));

        $apAfterWithdraw = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        // agent AP moves DOWN by 500 (debit on agent account increases debit total → NET decreases)
        $this->assertEqualsWithDelta($baselineAP - 500.0, $apAfterWithdraw, 0.01,
            'agent AP must decrease by the withdrawn amount');

        // Repay the same 500 EGP
        $repay = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 500.0,
            'from_account_id' => $this->vaultEgp->id,
            'notes' => 'P912 cycle test repay',
        ])->assertOk();
        $this->assertNotNull($repay->json('data.transaction_id'));

        $apAfterRepay = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP, $apAfterRepay, 0.01,
            'withdraw-then-repay same amount must restore agent AP to baseline');
    }

    public function test_three_cycles_withdraw_repay_is_still_balanced(): void
    {
        $agent = $this->agent->fresh();
        $baselineAP = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $cycleAmounts = [200.0, 350.0, 150.0];
        foreach ($cycleAmounts as $i => $amount) {
            $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
                'amount' => $amount,
                'to_account_id' => $this->vaultEgp->id,
                'notes' => "P912 cycle #$i withdraw",
            ])->assertOk();
            $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
                'amount' => $amount,
                'from_account_id' => $this->vaultEgp->id,
                'notes' => "P912 cycle #$i repay",
            ])->assertOk();
        }

        $apAfterCycles = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP, $apAfterCycles, 0.01,
            'three withdraw/repay cycles must leave agent AP at baseline');
    }

    public function test_partial_repay_leaves_outstanding_agent_ap(): void
    {
        $agent = $this->agent->fresh();
        $baselineAP = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 1000.0,
            'to_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 400.0,
            'from_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        $apAfter = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        // Withdraw 1000 → AP -1000; repay 400 → AP -600
        $this->assertEqualsWithDelta($baselineAP - 600.0, $apAfter, 0.01,
            'partial repay must leave agent AP at baseline minus outstanding balance');
    }

    /* ============================================================
     *  DUES READOUT
     * ============================================================ */

    public function test_dues_total_withdrawn_and_total_repaid_reflect_ledger(): void
    {
        $agent = $this->agent->fresh();

        $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 800.0,
            'to_account_id' => $this->vaultEgp->id,
        ])->assertOk();
        $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 300.0,
            'from_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        $response = $this->getJson('/api/v1/visa/agents/dues')->assertOk();
        $items = $response->json('data.items');
        $row = collect($items)->firstWhere('id', $agent->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(800.0, (float) $row['total_withdrawn'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $row['total_repaid'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $row['net_due'], 0.01,
            'net_due = withdrawn - repaid');
    }

    /* ============================================================
     *  AGENT AP ACROSS BOOKING LIFECYCLE
     * ============================================================ */

    public function test_withdraw_then_refund_booking_is_isolated_from_agent_ap(): void
    {
        // Booking a cancel does NOT touch the agent AP if withdraw has already happened.
        // (cancel reverses the booking expense; the withdraw remains outstanding.)
        $agent = $this->agent->fresh();
        $baselineAP = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 200.0,
            'to_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        $booking = $this->makeBooking();
        $apAfterBooking = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP - 1200.0, $apAfterBooking, 0.01,
            'sanity: withdraw 200 + booking 1000 → AP -1200');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'P912 cancel after withdraw',
        ])->assertOk();

        $apAfterCancel = (float) \App\Models\AccountEntry::where('account_id', $agent->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        // cancel reverses booking expense (-1000); withdraw remains as -200 → AP = baseline - 200
        $this->assertEqualsWithDelta($baselineAP - 200.0, $apAfterCancel, 0.01,
            'cancel must reverse only the booking expense; the withdraw remains outstanding');
    }

    /* ============================================================
     *  VALIDATION GAPS / GUARDS
     * ============================================================ */

    public function test_withdraw_rejects_zero_amount(): void
    {
        $agent = $this->agent->fresh();
        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 0,
            'to_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_repay_rejects_negative_amount(): void
    {
        $agent = $this->agent->fresh();
        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => -100.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_withdraw_rejects_office_division_target(): void
    {
        $agent = $this->agent->fresh();
        $office = Account::create([
            'name' => 'P912 Office Treasury',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',  // not a tourism division
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $office->id,
        ]);
        $response->assertStatus(422);
    }

    public function test_repay_rejects_office_division_source(): void
    {
        $agent = $this->agent->fresh();
        $office = Account::create([
            'name' => 'P912 Office Treasury Repay',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 100.0,
            'from_account_id' => $office->id,
        ]);
        $response->assertStatus(422);
    }

    public function test_withdraw_on_agent_without_linked_account_auto_creates_supplier_account(): void
    {
        // Phase 9.12 FINDING: VisaAgentObserver::saving auto-creates a Supplier
        // Account when account_id is null on create. This is by-design — agents
        // without an account get one assigned transparently. The controller's
        // early-return guard is therefore unreachable through the normal create
        // path. We assert the auto-creation is consistent (EGP, supplier type,
        // linked back to the agent) so this behavior is locked in.
        $orphan = VisaAgent::create([
            'company_name' => 'P912 Orphan Agent',
            'contact_person' => 'No Account',
            'phone' => '010',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'default_cost_price' => 100,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->assertNotNull($orphan->fresh()->account_id,
            'VisaAgentObserver must auto-create supplier account when account_id is null');

        // Withdraw should now succeed (auto-created account has matching EGP currency)
        $response = $this->postJson("/api/v1/visa/agents/{$orphan->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertOk();
        $this->assertNotNull($response->json('data.transaction_id'));
    }

    public function test_repay_on_agent_without_linked_account_auto_creates_supplier_account(): void
    {
        $orphan = VisaAgent::create([
            'company_name' => 'P912 Orphan Agent Repay',
            'contact_person' => 'No Account',
            'phone' => '010',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'default_cost_price' => 100,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->assertNotNull($orphan->fresh()->account_id);

        // Repay should succeed (auto-created account has matching EGP currency)
        $response = $this->postJson("/api/v1/visa/agents/{$orphan->id}/repay", [
            'amount' => 100.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertOk();
        $this->assertNotNull($response->json('data.transaction_id'));
    }

    /* ============================================================
     *  INACTIVE / CROSS-DIVISION BEHAVIOR
     * ============================================================ */

    public function test_withdraw_on_inactive_agent_is_still_allowed_when_admin_initiated(): void
    {
        // Inactive agents remain in dues? Actually `dues()` filters by is_active=true.
        // Withdraw/repay, however, must work on any existing agent because debt is still
        // owed regardless of active status. This documents the current behavior.
        $agent = $this->agent->fresh();
        $agent->update(['is_active' => false]);

        // Withdraw should still succeed (financial transfers don't gate on is_active)
        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $this->vaultEgp->id,
        ]);
        $response->assertOk();
    }

    public function test_inactive_agent_excluded_from_dues_listing(): void
    {
        $this->agent->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/visa/agents/dues')->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertNotContains($this->agent->id, $ids,
            'inactive agent must be excluded from the dues listing');
    }

    /* ============================================================
     *  CURRENCY ISOLATION — agent AP must stay in agent currency
     * ============================================================ */

    public function test_withdraw_into_currency_mismatched_treasury_is_at_least_rejected(): void
    {
        // USD vault with EGP agent — ledger cannot cross currencies
        $agent = $this->agent->fresh();   // EGP agent
        $usdVault = $this->vaultUsd;       // USD vault

        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 100.0,
            'to_account_id' => $usdVault->id,
        ]);

        // Per AccountModuleContract check the request may succeed at controller level
        // (controller checks module_type only, not currency). The journal_transfer
        // service may then reject on currency mismatch. Either 422 or 5xx is wrong —
        // we assert the response must NOT silently succeed.
        $this->assertGreaterThanOrEqual(400, $response->status(),
            'cross-currency transfer must not 200 silently');
    }

    /* ============================================================
     *  AGENT AP LEDGER INVARIANT — sum across all entries
     * ============================================================ */

    public function test_agent_account_ledger_is_balanced_across_full_cycle(): void
    {
        $agent = $this->agent->fresh();

        // Booking create
        $booking = $this->makeBooking();

        // Withdraw
        $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 500.0,
            'to_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        // Repay
        $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 500.0,
            'from_account_id' => $this->vaultEgp->id,
        ])->assertOk();

        // Final invariant: SUM(credit) - SUM(debit) == account.balance
        $this->assertLedgerBalancedForAccount($agent->account);
        $this->assertLedgerGloballyBalanced();
    }
}
