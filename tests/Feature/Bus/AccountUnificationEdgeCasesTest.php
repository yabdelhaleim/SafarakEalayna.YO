<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Services\Bus\BusBookingService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Edge-case tests for the Phase-3.5 Account Unification contract on the
 * Bus module. Complements {@see AccountUnificationTest} which covers the
 * happy paths (office cashbox visible to treasury, office cashbox can pay
 * a booking, AccountModuleContract saving-hook contract).
 *
 * This file targets the three gaps flagged in the coverage plan:
 *
 *   1. Per-currency customer AR — a customer who books in one currency
 *      and later books in another currency must get a SEPARATE ledger
 *      account per currency (the EGP ledger must not be polluted by USD
 *      amounts and vice versa).
 *
 *   2. BusVault contract gap — {@see Account::getModuleVault('bus')} queries
 *      `module_type='bus' AND is_module_vault=true`, but
 *      {@see AccountModuleContract} forbids liquidity accounts from having
 *      `module_type='bus'` (must be a division: 'office' / 'tourism').
 *      The vault therefore CANNOT EXIST under the current query, and the
 *      fallback path in {@see \App\Services\Bus\BusBookingService::payBooking()}
 *      is effectively dead code. These tests pin the contract + flag
 *      the architectural gap.
 *
 *   3. Per-currency account RESILIENCE — the createCustomerCurrencyAccount
 *      helper must update `customer.account_id` only when creating a
 *      new account, never overwrite an existing account's primary link
 *      silently. A known gap: USD customer + EGP booking currently reuses
 *      the USD account (mixed currencies).
 */
class AccountUnificationEdgeCasesTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — Per-currency AR: EGP customer, first USD booking → new USD acct
    // ─────────────────────────────────────────────────────────────────────

    public function test_egp_customer_first_usd_booking_creates_separate_usd_ar_account(): void
    {
        // Customer starts with an EGP AR account (the common case for
        // Egyptian customers). When they book their first USD trip, the
        // system must NOT silently re-tag their EGP account as USD —
        // it must open a SECOND ledger account in USD and switch the
        // customer's primary AR link to the new one.
        $customer = $this->makeCustomerWithBusAccount(0, 'EGP');
        $egpAccountId = $customer->account_id;
        $this->assertEquals('EGP', $customer->ledgerAccount->currency);

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'EGP Customer USD Trip',
            'customer_phone' => '01000000500',
            'quantity' => 1,
        ]);

        // Customer's primary AR link now points at a NEW USD account.
        $customer->refresh();
        $this->assertNotEquals($egpAccountId, $customer->account_id, 'Primary AR slot must move to the new USD account');

        $newAccount = Account::findOrFail($customer->account_id);
        $this->assertEquals('USD', $newAccount->currency);
        $this->assertEquals(100.0, (float) $newAccount->balance, 'USD AR holds 1 × $100 = $100 USD (not EGP)');

        // The original EGP account still exists, untouched (NOT deleted).
        $originalEgp = Account::findOrFail($egpAccountId);
        $this->assertEquals('EGP', $originalEgp->currency);
        $this->assertEquals(0.0, (float) $originalEgp->balance);

        // Two separate AR accounts now exist for this customer — one EGP
        // (the original + the CustomerLedgerObserver's auto-create) and
        // one USD (just created). We assert the OBSERVABLE invariants
        // instead of total count because CustomerLedgerObserver may also
        // auto-create an EGP AR row when the customer is first saved.
        $egpAccounts = Account::query()->where('type', \App\Enums\AccountType::Customer->value)
            ->where('currency', 'EGP')->get();
        $usdAccounts = Account::query()->where('type', \App\Enums\AccountType::Customer->value)
            ->where('currency', 'USD')->get();

        $this->assertGreaterThanOrEqual(1, $egpAccounts->count(), 'At least one EGP AR account exists');
        $this->assertEquals(1, $usdAccounts->count(), 'Exactly one USD AR account exists');

        // The USD account is the one used by the booking (it holds the sale).
        $this->assertEquals(100.0, (float) $usdAccounts->first()->balance);

        // The original EGP account is untouched (no sale posted to it).
        $this->assertEquals(0.0, (float) Account::findOrFail($egpAccountId)->balance,
            'Original EGP account balance must be untouched');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — Per-currency AR: USD customer, SAR booking → new SAR acct
    // ─────────────────────────────────────────────────────────────────────

    public function test_usd_customer_sar_booking_creates_separate_sar_ar_account(): void
    {
        // Customer already has a USD AR (from a previous USD booking).
        // A new SAR booking must open a SECOND ledger account in SAR —
        // never mix currencies on the same account.
        $customer = $this->makeCustomerWithBusAccount(0, 'USD');
        $usdAccountId = $customer->account_id;

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 40,
            'selling_price' => 75,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'SAR',
            'exchange_rate_to_egp' => 13.3333,
        ]);

        app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'USD Customer SAR Trip',
            'customer_phone' => '01000000501',
            'quantity' => 1,
        ]);

        $customer->refresh();
        $this->assertNotEquals($usdAccountId, $customer->account_id);

        $newAccount = Account::findOrFail($customer->account_id);
        $this->assertEquals('SAR', $newAccount->currency);
        $this->assertEquals(75.0, (float) $newAccount->balance, 'SAR AR holds 1 × 75 SAR (not USD, not EGP)');

        // Original USD account still exists and untouched.
        $originalUsd = Account::findOrFail($usdAccountId);
        $this->assertEquals('USD', $originalUsd->currency);
        $this->assertEquals(0.0, (float) $originalUsd->balance);

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — Per-currency AR edge case: USD customer, EGP booking (GAP)
    // ─────────────────────────────────────────────────────────────────────

    public function test_usd_customer_egp_booking_opens_separate_egp_account_after_fix(): void
    {
        // Fix #3 (Phase 3.5 follow-up): ensureCustomerAccount used to
        // short-circuit with `customerCurrency !== 'EGP'`, which meant a
        // USD customer booking an EGP trip silently reused the USD account
        // (the EGP sale got posted to a USD ledger, mixing currencies).
        //
        // New contract: the customer account's currency MUST match the
        // booking currency. If it doesn't, a NEW per-currency account is
        // opened (the original stays untouched as audit history).
        $customer = $this->makeCustomerWithBusAccount(0, 'USD');
        $usdAccountId = $customer->account_id;

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);

        app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'USD Customer EGP Trip',
            'customer_phone' => '01000000502',
            'quantity' => 1,
        ]);

        $customer->refresh();

        // Customer's primary AR slot moved to a NEW EGP account.
        $this->assertNotEquals(
            $usdAccountId,
            $customer->account_id,
            'Fix #3: ensureCustomerAccount opens a separate EGP account when the existing AR is USD'
        );

        $newAccount = Account::findOrFail($customer->account_id);
        $this->assertEquals('EGP', $newAccount->currency, 'New AR is in EGP');
        $this->assertEquals(120.0, (float) $newAccount->balance, 'EGP AR holds the 120 EGP sale');

        // The original USD account still exists, untouched.
        $originalUsd = Account::findOrFail($usdAccountId);
        $this->assertEquals('USD', $originalUsd->currency);
        $this->assertEquals(0.0, (float) $originalUsd->balance, 'Original USD account untouched');

        // Per-currency AR accounts: USD (manual) + EGP (CustomerLedgerObserver
        // auto-create on first save) + EGP (new from this EGP booking) = 3.
        $usdCount = Account::query()->where('type', \App\Enums\AccountType::Customer->value)
            ->where('currency', 'USD')->count();
        $egpCount = Account::query()->where('type', \App\Enums\AccountType::Customer->value)
            ->where('currency', 'EGP')->count();

        $this->assertEquals(1, $usdCount, 'Exactly one USD customer AR account exists');
        $this->assertEquals(2, $egpCount, 'Two EGP customer AR accounts exist (observer + new from booking)');

        $this->assertLedgerGloballyBalanced();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 — Liquidity account saving hook enforces division module_type
    // ─────────────────────────────────────────────────────────────────────

    public function test_liquidity_account_with_module_type_bus_is_rejected_by_saving_hook(): void
    {
        // AccountModuleContract forbids liquidity accounts (cashbox / wallet / bank)
        // from having module_type='bus' — it must be a DIVISION ('office' or 'tourism').
        //
        // This is the underlying reason Account::getModuleVault('bus') can never
        // return a result: liquidity + module_type='bus' is a contract violation.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Liquidity accounts');

        LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Bus-typed Vault (should fail)',
            'type' => \App\Enums\AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'bus',  // ← rejected: liquidity needs a DIVISION, not a module
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5 — getModuleVault('bus') architectural gap
    // ─────────────────────────────────────────────────────────────────────

    public function test_get_module_vault_bus_returns_office_division_vault_after_fix(): void
    {
        // Fix #1 (Phase 3.5 follow-up): getModuleVault used to query
        //     module_type='bus' AND is_module_vault=true
        // which could never match (AccountModuleContract forbids liquidity
        // accounts from having module_type='bus' — must be a division).
        //
        // New contract: the function resolves the module's division via
        // AccountModuleDivision::divisionForModuleType() first, then
        // looks for the division-tagged vault.
        //
        // The BusTestCase seeds `$this->cashboxEgp` with module_type='office'
        // + is_module_vault=true — that IS the BusVault (and the Fawry/
        // Online/WalletTransfer vault too). We assert the resolution
        // returns this exact seeded row.
        $resolvedBus = Account::getModuleVault('bus');
        $this->assertNotNull($resolvedBus, 'Fix #1: getModuleVault("bus") now resolves to the office-division vault');
        $this->assertEquals($this->cashboxEgp->id, $resolvedBus->id, 'Returns the seeded office cashbox (the unified BusVault)');
        $this->assertEquals('office', $resolvedBus->module_type);

        // Same resolution for the other office-division modules.
        $this->assertEquals($this->cashboxEgp->id, Account::getModuleVault('fawry')?->id);
        $this->assertEquals($this->cashboxEgp->id, Account::getModuleVault('online')?->id);
        $this->assertEquals($this->cashboxEgp->id, Account::getModuleVault('wallet_transfer')?->id);

        // And for tourism-division modules — they resolve to a 'tourism' vault.
        // We create one explicitly because BusTestCase does not seed a tourism vault.
        $tourismVault = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Tourism Cashbox',
            'type' => \App\Enums\AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]));

        $this->assertEquals($tourismVault->id, Account::getModuleVault('flights')?->id);
        $this->assertEquals($tourismVault->id, Account::getModuleVault('visas')?->id);
        $this->assertEquals($tourismVault->id, Account::getModuleVault('hajj_umra')?->id);

        // Unknown module → null (graceful).
        $this->assertNull(Account::getModuleVault('unknown_xyz'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6 — Renaming module marker away from 'bus' hides the vault
    // ─────────────────────────────────────────────────────────────────────

    public function test_module_type_renamed_away_from_office_makes_vault_invisible_to_get_module_vault(): void
    {
        // Pinned behavior: when an admin renames a vault's module_type away
        // from the queried value, the vault silently disappears from
        // Account::getModuleVault(). Future refactors that introduce stricter
        // rejection (e.g. throw if no vault is found) will surface here.
        //
        // We pick module_type='tourism' for the test vault so it doesn't
        // collide with the seeded office cashbox (BusTestCase seeds
        // module_type='office' + is_module_vault=true on cashboxEgp).
        $vault = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Tourism Vault Pre-Rename',
            'type' => \App\Enums\AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',  // ← unique to this test (not seeded)
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]));

        // Sanity: visible to getModuleVault('tourism') (the query is
        // hardcoded to whatever string we pass, so this returns the vault).
        $this->assertEquals($vault->id, Account::getModuleVault('tourism')?->id);

        // Admin renames the marker away from the queried value.
        LedgerBalanceMutationGuard::run(function () use ($vault) {
            $vault->module_type = 'office';  // rename to a different value
            $vault->save();
        });

        // The renamed vault is no longer discoverable for the queried value.
        $this->assertNull(
            Account::getModuleVault('tourism'),
            'Renaming module_type away from the queried value hides the vault from getModuleVault()'
        );
    }
}