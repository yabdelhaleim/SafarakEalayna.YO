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
 * Phase 3.5 — division contract enforcement tests.
 *
 * Verifies the saving-hook rule (dual-meaning for module_type):
 *   1. Liquidity accounts (cashbox / wallet / bank) MUST have
 *      module_type = 'office' or 'tourism' (division-level only).
 *      They MAY also pin 'module' to a specific module alias.
 *   2. Subject accounts (customer / supplier) MUST have module_type =
 *      a SPECIFIC module in the same division (bus, fawry, …), NOT the
 *      division name itself.
 *   3. Internal accounts (expense / revenue / liability / owner) are NOT
 *      constrained by the hook — config-level clearing rules apply.
 *
 * @see \App\Models\Account::booted()
 * @see \App\Support\Finance\AccountModuleContract
 */
class AccountModuleDivisionRuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Division Rule Tester',
            'email' => 'division-rule@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Division Rule Test',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'created_by' => $this->user->id,
        ], $overrides);
    }

    // ────────────────────────────────────────────────────────────────
    // LIQUIDITY: module_type must be a DIVISION ('office' | 'tourism')
    // ────────────────────────────────────────────────────────────────

    public function test_liquidity_cashbox_with_office_module_type_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Cashbox,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        ]));
        $this->assertSame('office', (string) $acc->module_type);
    }

    public function test_liquidity_bank_with_tourism_module_type_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Bank,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
        ]));
        $this->assertSame('tourism', (string) $acc->module_type);
    }

    public function test_liquidity_wallet_with_specific_module_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DIVISION/');

        Account::create($this->basePayload([
            'type'        => AccountType::Wallet,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'fawry', // specific module — WRONG for liquidity
        ]));
    }

    public function test_liquidity_cashbox_with_null_module_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->basePayload([
            'type'        => AccountType::Cashbox,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => null,
        ]));
    }

    public function test_liquidity_bank_with_empty_string_module_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->basePayload([
            'type'        => AccountType::Bank,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => '',
        ]));
    }

    public function test_liquidity_with_module_alias_is_accepted_when_module_type_is_division(): void
    {
        // Liquidity account with module_type=office + module='bus' alias
        // is allowed; the alias narrows the display label but the pool
        // remains division-wide.
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Wallet,
            'owner_type'  => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
            'module'      => 'bus',
        ]));
        $this->assertSame('office', (string) $acc->module_type);
        $this->assertSame('bus', (string) $acc->module);
    }

    // ────────────────────────────────────────────────────────────────
    // SUBJECT: module_type must be a SPECIFIC module (NOT a division)
    // ────────────────────────────────────────────────────────────────

    public function test_subject_customer_with_office_module_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SPECIFIC module/');

        Account::create($this->basePayload([
            'type'        => AccountType::Customer,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE, // WRONG for subject
        ]));
    }

    public function test_subject_supplier_with_tourism_module_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Account::create($this->basePayload([
            'type'        => AccountType::Supplier,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE, // WRONG for subject
        ]));
    }

    public function test_subject_customer_with_specific_module_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Customer,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => 'bus', // SPECIFIC module — OK
        ]));
        $this->assertSame('bus', (string) $acc->module_type);
    }

    public function test_subject_supplier_with_fawry_module_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Supplier,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => 'fawry',
        ]));
        $this->assertSame('fawry', (string) $acc->module_type);
    }

    public function test_subject_customer_with_hajj_umra_module_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Customer,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
        ]));
        $this->assertSame('hajj_umra', (string) $acc->module_type);
    }

    // ────────────────────────────────────────────────────────────────
    // INTERNAL: hook does NOT enforce a rule — config layer is responsible
    // ────────────────────────────────────────────────────────────────

    public function test_internal_expense_with_office_module_type_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Expense,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        ]));
        $this->assertNotNull($acc->id);
    }

    public function test_internal_revenue_with_specific_module_passes(): void
    {
        $acc = Account::create($this->basePayload([
            'type'        => AccountType::Revenue,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => 'bus',
        ]));
        $this->assertNotNull($acc->id);
    }
}
