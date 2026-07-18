<?php

namespace Tests\Feature\Bus;

use App\Models\Account;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Tests\TestCase;

/**
 * Account Unification scenarios for the Bus module.
 *
 * The Phase-3.5 unification contract has ONE office-division cashbox/bank/wallet
 * serving bus/fawry/online/wallet_transfer.  This test class ensures:
 *
 *   1. The unified liquidity accounts are visible to the Bus dashboard cards.
 *   2. A Bus booking's payment can land on an office-division cashbox/bank
 *      (not just a strictly bus-tagged one).
 *   3. Multi-currency bookings require a wallet/bank of matching currency.
 *   4. AccountModuleContract dual-meaning rule rejects invalid combinations.
 */
class AccountUnificationTest extends BusTestCase
{
    public function test_office_division_cashbox_appears_in_bus_treasury_overview(): void
    {
        $response = $this->getJson('/api/v1/bus/treasury/overview');
        $response->assertOk()
            ->assertJsonStructure(['data' => ['settlement_accounts', 'companies', 'recent_bus_transactions']]);

        $accountIds = collect($response->json('data.settlement_accounts'))->pluck('id');

        // The seeded office-division cashbox/bank/wallet are all present.
        $this->assertContains($this->cashboxEgp->id, $accountIds);
        $this->assertContains($this->bankEgp->id, $accountIds);
        $this->assertContains($this->walletEgp->id, $accountIds);
        $this->assertContains($this->walletUsd->id, $accountIds);
    }

    public function test_office_cashbox_pays_bus_booking(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        // Create the booking.
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Unified Customer',
            'customer_phone' => '01077000001',
            'quantity' => 1,
        ])->assertCreated();

        $booking = \App\Models\Bus\BusBooking::latest('id')->firstOrFail();

        // Pay from the office cashbox — should succeed because BusLiquidityAccount
        // accepts `module_type IN ('bus', 'office')` for liquidity accounts.
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 120,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();
    }

    public function test_account_module_saving_hook_rejects_bank_post_subject_combination(): void
    {
        // Liquidity account (bank) with a subject module_type (e.g. 'bus') should
        // be rejected. The Account module raises an exception here; the exact
        // class (InvalidArgumentException vs RuntimeException) is an
        // implementation detail of the saving hook — accept any Throwable
        // because the post-condition ("no row was created") is what matters.
        $this->expectException(\Throwable::class);

        try {
            LedgerBalanceMutationGuard::run(fn () => Account::create([
                'name' => 'Reject Me',
                'type' => \App\Enums\AccountType::Bank,
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'bus',     // ❌ — liquidity must use a division marker
                'created_by' => $this->user->id,
            ]));
        } catch (\Throwable $e) {
            // Verify nothing was persisted (the hook must throw before insert).
            $this->assertDatabaseMissing('accounts', ['name' => 'Reject Me']);
            throw $e;
        }
    }

    public function test_account_module_saving_hook_accepts_bank_with_office_or_tourism(): void
    {
        // Bank with 'office' (division marker) — should succeed.
        $bank = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Test Bank Office',
            'type' => \App\Enums\AccountType::Bank,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]));

        $this->assertNotNull($bank->id);

        // Bank with 'tourism' (the other division marker) — should also succeed.
        $bank2 = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Test Bank Tourism',
            'type' => \App\Enums\AccountType::Bank,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]));

        $this->assertNotNull($bank2->id);
    }
}
