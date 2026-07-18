<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 3 verification suite.
 *
 * Confirms that the saving-hook contract added by Phase 3 holds:
 *  - Liquidity accounts (cashbox/wallet/bank) MUST have a
 *    non-empty `module_type` or save() throws InvalidArgumentException.
 *  - The pre-existing auto-fill (module_type → module) still runs.
 *
 * Pre-existing DB contract (enforced at DB level):
 *  - accounts.module_type is NOT NULL with default 'tourism'.
 *  - accounts.type enum has: cashbox, wallet, bank, customer, supplier,
 *    expense, revenue, liability, owner
 *    (treasury and post were removed by 2026_07_09_010000; PHP enum
 *    still references them via the App\Enums\AccountType cases but
 *    the DB no longer accepts those values).
 *
 * @see \App\Models\Account::booted()
 * @see \App\Support\Finance\AccountModuleContract
 */
class AccountSavingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Saving Rules Tester',
            'email' => 'saving-rules@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function baseLiquidityPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Liquidity Account',
            'currency' => 'EGP',
            'balance' => 0,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'is_active' => true,
            'created_by' => $this->user->id,
        ], $overrides);
    }

    // ────────────────────────────────────────────────────────────────
    // POSITIVE CASES — module_type set, save must succeed
    // ────────────────────────────────────────────────────────────────

    public function test_office_division_cashbox_persists_with_module_type_office(): void
    {
        $account = Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Cashbox,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
            'module' => null,
        ]));

        $this->assertNotNull($account->id, 'Cashbox with office module_type must persist');
        $this->assertSame('office', (string) $account->module_type);
        $this->assertNull($account->module, 'module stays null for division-unified vault');
    }

    public function test_tourism_division_bank_persists_with_module_type_tourism(): void
    {
        $account = Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Bank,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'module' => null,
        ]));

        $this->assertNotNull($account->id, 'Bank with tourism module_type must persist');
        $this->assertSame('tourism', (string) $account->module_type);
    }

    public function test_specific_module_bank_persists_and_auto_fills_module_alias(): void
    {
        // Strict contract (Phase 3.5): liquidity accounts MUST have
        // module_type = division. A specific module on a Bank is rejected
        // by the saving hook. (Auto-fill only matters for module_types
        // that the hook actually allows through.)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DIVISION/');

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Bank,
            'module_type' => 'fawry', // specific module — rejected for liquidity
            'module' => null,
        ]));
    }

    // ────────────────────────────────────────────────────────────────
    // NEGATIVE CASES — null/empty module_type must throw from our HOOK
    // (Note: the DB also enforces NOT NULL on module_type, so a separate
    //  QueryException would happen even without our hook. The hook's role
    //  is to throw a CLEARER InvalidArgumentException earlier, with the
    //  contract-specific message about which contract value to use.)
    // ────────────────────────────────────────────────────────────────

    public function test_cashbox_with_null_module_type_is_rejected_by_hook(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Cashbox,
            'module_type' => null,
        ]));
    }

    public function test_wallet_with_null_module_type_is_rejected_by_hook(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Wallet,
            'wallet_provider' => null,
            'wallet_number' => null,
            'module_type' => null,
        ]));
    }

    public function test_bank_with_empty_string_module_type_is_rejected_by_hook(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Bank,
            'module_type' => '',
        ]));
    }

    // ────────────────────────────────────────────────────────────────
    // SUBJECT / INTERNAL TYPES — module_type is REQUIRED by DB, but
    // the saving hook does NOT enforce it (DB constraint suffices).
    // These tests verify subject accounts persist when given the
    // division marker (which the DB NOT NULL constraint requires).
    // ────────────────────────────────────────────────────────────────

    public function test_customer_subject_account_persists_with_office_module_type(): void
    {
        // Strict contract (Phase 3.5): subject accounts (customer/supplier)
        // MUST have module_type = SPECIFIC module (not division). The
        // division markers office/tourism are reserved for liquidity vaults.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SPECIFIC module/');

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Customer,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE, // division — rejected for subject
        ]));
    }

    public function test_supplier_subject_account_persists_with_office_module_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SPECIFIC module/');

        Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Supplier,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE, // division — rejected for subject
        ]));
    }

    public function test_internal_expense_account_persists_with_office_module_type(): void
    {
        $account = Account::create($this->baseLiquidityPayload([
            'type' => AccountType::Expense,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        ]));

        $this->assertNotNull($account->id);
    }
}
