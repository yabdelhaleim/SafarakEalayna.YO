<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Rules\BusLiquidityAccount;
use App\Rules\FawryLiquidityAccount;
use App\Rules\HajjUmraLiquidityAccount;
use App\Rules\OnlineLiquidityAccount;
use App\Rules\TransferLiquidityAccount;
use App\Rules\VisaLiquidityAccount;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 verification suite — Liquidity Account Rules broadening.
 *
 * For each of the 6 LiquidityAccount Rules, confirms the broadened
 * acceptance matrix:
 *
 *  ┌──────────────────────────────────────────────────────────────────┐
 *  │ Test case                                │ Expected │           │
 *  ├──────────────────────────────────────────────────────────────────┤
 *  │ Own-module-specific vault                │ PASS     │           │
 *  │ Division-unified vault (own division)    │ PASS     │ (Phase 5) │
 *  │ Other-module vault (same division)       │ FAIL     │           │
 *  │ Other-division vault                     │ FAIL     │           │
 *  │ Subject account (customer/supplier)      │ FAIL     │ (type)    │
 *  │ Inactive account                         │ FAIL     │           │
 *  └──────────────────────────────────────────────────────────────────┘
 *
 * Per Rule: 6 assertions × 6 Rules = 36 assertions.
 *
 * @see \App\Support\Finance\AccountModuleContract
 */
class LiquidityAccountRulesTest extends TestCase
{
    protected User $user;

    /** @var array<string, Account> */
    protected array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Liquidity Rules Tester',
            'email' => 'liq-rules@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->seedFixtures();
    }

    /**
     * Build a fixture account for the given module_type/module combination.
     */
    private function makeAccount(string $key, AccountType $type, ?string $moduleType, ?string $module, bool $active = true): Account
    {
        return Account::create([
            'name' => "[LIQ-RULES] {$key}",
            'type' => $type,
            'currency' => 'EGP',
            'balance' => 0,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => $moduleType ?? 'tourism',
            'module' => $module,
            'is_active' => $active,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Build the full fixture matrix used by every Rule.
     */
    private function seedFixtures(): void
    {
        Account::query()->where('name', 'like', '[LIQ-RULES]%')->delete();

        // Office-division specific vaults
        $this->fixtures['bus_cashbox']      = $this->makeAccount('bus cashbox',      AccountType::Cashbox, 'bus',             'bus');
        $this->fixtures['fawry_cashbox']    = $this->makeAccount('fawry cashbox',    AccountType::Cashbox, 'fawry',           'fawry');
        $this->fixtures['online_cashbox']   = $this->makeAccount('online cashbox',   AccountType::Cashbox, 'online',          'online');
        $this->fixtures['transfer_wallet']  = $this->makeAccount('transfer wallet',  AccountType::Wallet,  'wallet_transfer', 'wallet_transfer');

        // Office-division unified vault (Phase 5)
        $this->fixtures['office_unified']   = $this->makeAccount('office unified',   AccountType::Bank,    'office',          null);

        // Tourism-division specific vaults
        $this->fixtures['hajj_cashbox']     = $this->makeAccount('hajj cashbox',     AccountType::Cashbox, 'hajj_umra',       'hajj_umra');
        $this->fixtures['visa_cashbox']     = $this->makeAccount('visa cashbox',     AccountType::Cashbox, 'visas',           'visas');

        // Tourism-division unified vault (Phase 5)
        $this->fixtures['tourism_unified']  = $this->makeAccount('tourism unified',  AccountType::Bank,    'tourism',         null);

        // Cross-division "wrong module" fixtures (for REJECT cases)
        $this->fixtures['bus_wrong_for_fawry']  = $this->fixtures['bus_cashbox'];
        $this->fixtures['tourism_wrong_for_bus']= $this->fixtures['tourism_unified'];
        $this->fixtures['hajj_wrong_for_visa']  = $this->fixtures['hajj_cashbox'];
        $this->fixtures['office_wrong_for_hajj']= $this->fixtures['office_unified'];

        // Subject (wrong type) fixture
        $this->fixtures['customer_subject'] = $this->makeAccount('customer subject', AccountType::Customer, 'bus', 'bus');

        // Inactive fixture
        $this->fixtures['bus_inactive']     = $this->makeAccount('bus inactive',    AccountType::Cashbox, 'bus', 'bus', active: false);
    }

    /**
     * Invoke a ValidationRule and return whether it produced a failure.
     */
    private function ruleFails(object $rule, int $accountId): bool
    {
        $failed = false;
        $rule->validate('account_id', $accountId, function (string $message) use (&$failed): void {
            $failed = true;
        });
        return $failed;
    }

    // ═════════════════════════════════════════════════════════════════
    // BusLiquidityAccount — own module = bus, division = office
    // ═════════════════════════════════════════════════════════════════

    public function test_bus_rule_accepts_bus_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['bus_cashbox']->id),
            'BusLiquidityAccount must accept module_type=bus vault'
        );
    }

    public function test_bus_rule_accepts_office_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['office_unified']->id),
            'BusLiquidityAccount must accept module_type=office unified vault (Phase 5)'
        );
    }

    public function test_bus_rule_rejects_other_office_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['fawry_cashbox']->id),
            'BusLiquidityAccount must reject module_type=fawry vault'
        );
    }

    public function test_bus_rule_rejects_tourism_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'BusLiquidityAccount must reject module_type=tourism vault'
        );
    }

    public function test_bus_rule_rejects_subject_account(): void
    {
        $this->assertTrue(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['customer_subject']->id),
            'BusLiquidityAccount must reject customer (subject) account'
        );
    }

    public function test_bus_rule_rejects_inactive_account(): void
    {
        $this->assertTrue(
            $this->ruleFails(new BusLiquidityAccount, $this->fixtures['bus_inactive']->id),
            'BusLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // HajjUmraLiquidityAccount — own module = hajj_umra, division = tourism
    // ═════════════════════════════════════════════════════════════════

    public function test_hajjumra_rule_accepts_hajj_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new HajjUmraLiquidityAccount, $this->fixtures['hajj_cashbox']->id),
            'HajjUmraLiquidityAccount must accept module_type=hajj_umra vault'
        );
    }

    public function test_hajjumra_rule_accepts_tourism_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new HajjUmraLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'HajjUmraLiquidityAccount must accept module_type=tourism unified vault (Phase 5)'
        );
    }

    public function test_hajjumra_rule_rejects_other_tourism_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new HajjUmraLiquidityAccount, $this->fixtures['visa_cashbox']->id),
            'HajjUmraLiquidityAccount must reject module_type=visas vault'
        );
    }

    public function test_hajjumra_rule_rejects_office_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new HajjUmraLiquidityAccount, $this->fixtures['office_unified']->id),
            'HajjUmraLiquidityAccount must reject module_type=office vault'
        );
    }

    public function test_hajjumra_rule_rejects_subject_account(): void
    {
        $subj = $this->makeAccount('hajj customer', AccountType::Customer, 'hajj_umra', 'hajj_umra');
        $this->assertTrue(
            $this->ruleFails(new HajjUmraLiquidityAccount, $subj->id),
            'HajjUmraLiquidityAccount must reject customer (subject) account'
        );
    }

    public function test_hajjumra_rule_rejects_inactive_account(): void
    {
        $inactive = $this->makeAccount('hajj inactive', AccountType::Cashbox, 'hajj_umra', 'hajj_umra', active: false);
        $this->assertTrue(
            $this->ruleFails(new HajjUmraLiquidityAccount, $inactive->id),
            'HajjUmraLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // FawryLiquidityAccount — own module = fawry, division = office
    // ═════════════════════════════════════════════════════════════════

    public function test_fawry_rule_accepts_fawry_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new FawryLiquidityAccount, $this->fixtures['fawry_cashbox']->id),
            'FawryLiquidityAccount must accept module_type=fawry vault'
        );
    }

    public function test_fawry_rule_accepts_office_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new FawryLiquidityAccount, $this->fixtures['office_unified']->id),
            'FawryLiquidityAccount must accept module_type=office unified vault'
        );
    }

    public function test_fawry_rule_rejects_other_office_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new FawryLiquidityAccount, $this->fixtures['bus_cashbox']->id),
            'FawryLiquidityAccount must reject module_type=bus vault'
        );
    }

    public function test_fawry_rule_rejects_tourism_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new FawryLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'FawryLiquidityAccount must reject module_type=tourism vault'
        );
    }

    public function test_fawry_rule_rejects_subject_account(): void
    {
        $subj = $this->makeAccount('fawry customer', AccountType::Supplier, 'fawry', 'fawry');
        $this->assertTrue(
            $this->ruleFails(new FawryLiquidityAccount, $subj->id),
            'FawryLiquidityAccount must reject supplier (subject) account'
        );
    }

    public function test_fawry_rule_rejects_inactive_account(): void
    {
        $inactive = $this->makeAccount('fawry inactive', AccountType::Cashbox, 'fawry', 'fawry', active: false);
        $this->assertTrue(
            $this->ruleFails(new FawryLiquidityAccount, $inactive->id),
            'FawryLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // OnlineLiquidityAccount — own module = online, division = office
    // ═════════════════════════════════════════════════════════════════

    public function test_online_rule_accepts_online_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new OnlineLiquidityAccount, $this->fixtures['online_cashbox']->id),
            'OnlineLiquidityAccount must accept module_type=online vault'
        );
    }

    public function test_online_rule_accepts_office_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new OnlineLiquidityAccount, $this->fixtures['office_unified']->id),
            'OnlineLiquidityAccount must accept module_type=office unified vault'
        );
    }

    public function test_online_rule_rejects_other_office_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new OnlineLiquidityAccount, $this->fixtures['fawry_cashbox']->id),
            'OnlineLiquidityAccount must reject module_type=fawry vault'
        );
    }

    public function test_online_rule_rejects_tourism_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new OnlineLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'OnlineLiquidityAccount must reject module_type=tourism vault'
        );
    }

    public function test_online_rule_rejects_subject_account(): void
    {
        $subj = $this->makeAccount('online customer', AccountType::Customer, 'online', 'online');
        $this->assertTrue(
            $this->ruleFails(new OnlineLiquidityAccount, $subj->id),
            'OnlineLiquidityAccount must reject customer (subject) account'
        );
    }

    public function test_online_rule_rejects_inactive_account(): void
    {
        $inactive = $this->makeAccount('online inactive', AccountType::Cashbox, 'online', 'online', active: false);
        $this->assertTrue(
            $this->ruleFails(new OnlineLiquidityAccount, $inactive->id),
            'OnlineLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // VisaLiquidityAccount — own module = visas (+visa alias), division = tourism
    // ═════════════════════════════════════════════════════════════════

    public function test_visa_rule_accepts_visas_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new VisaLiquidityAccount, $this->fixtures['visa_cashbox']->id),
            'VisaLiquidityAccount must accept module_type=visas vault'
        );
    }

    public function test_visa_rule_accepts_tourism_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new VisaLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'VisaLiquidityAccount must accept module_type=tourism unified vault'
        );
    }

    public function test_visa_rule_rejects_other_tourism_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new VisaLiquidityAccount, $this->fixtures['hajj_cashbox']->id),
            'VisaLiquidityAccount must reject module_type=hajj_umra vault'
        );
    }

    public function test_visa_rule_rejects_office_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new VisaLiquidityAccount, $this->fixtures['office_unified']->id),
            'VisaLiquidityAccount must reject module_type=office vault'
        );
    }

    public function test_visa_rule_rejects_subject_account(): void
    {
        $subj = $this->makeAccount('visa customer', AccountType::Customer, 'visas', 'visas');
        $this->assertTrue(
            $this->ruleFails(new VisaLiquidityAccount, $subj->id),
            'VisaLiquidityAccount must reject customer (subject) account'
        );
    }

    public function test_visa_rule_rejects_inactive_account(): void
    {
        $inactive = $this->makeAccount('visa inactive', AccountType::Cashbox, 'visas', 'visas', active: false);
        $this->assertTrue(
            $this->ruleFails(new VisaLiquidityAccount, $inactive->id),
            'VisaLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // TransferLiquidityAccount — own module = wallet_transfer, division = office
    // ═════════════════════════════════════════════════════════════════

    public function test_transfer_rule_accepts_wallet_transfer_specific_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new TransferLiquidityAccount, $this->fixtures['transfer_wallet']->id),
            'TransferLiquidityAccount must accept module_type=wallet_transfer vault'
        );
    }

    public function test_transfer_rule_accepts_office_division_unified_vault(): void
    {
        $this->assertFalse(
            $this->ruleFails(new TransferLiquidityAccount, $this->fixtures['office_unified']->id),
            'TransferLiquidityAccount must accept module_type=office unified vault'
        );
    }

    public function test_transfer_rule_rejects_other_office_module_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new TransferLiquidityAccount, $this->fixtures['bus_cashbox']->id),
            'TransferLiquidityAccount must reject module_type=bus vault'
        );
    }

    public function test_transfer_rule_rejects_tourism_division_vault(): void
    {
        $this->assertTrue(
            $this->ruleFails(new TransferLiquidityAccount, $this->fixtures['tourism_unified']->id),
            'TransferLiquidityAccount must reject module_type=tourism vault'
        );
    }

    public function test_transfer_rule_rejects_subject_account(): void
    {
        $subj = $this->makeAccount('transfer customer', AccountType::Customer, 'wallet_transfer', 'wallet_transfer');
        $this->assertTrue(
            $this->ruleFails(new TransferLiquidityAccount, $subj->id),
            'TransferLiquidityAccount must reject customer (subject) account'
        );
    }

    public function test_transfer_rule_rejects_inactive_account(): void
    {
        $inactive = $this->makeAccount('transfer inactive', AccountType::Wallet, 'wallet_transfer', 'wallet_transfer', active: false);
        $this->assertTrue(
            $this->ruleFails(new TransferLiquidityAccount, $inactive->id),
            'TransferLiquidityAccount must reject inactive account'
        );
    }

    // ═════════════════════════════════════════════════════════════════
    // Cross-cutting invariants (AccountModuleContract)
    // ═════════════════════════════════════════════════════════════════

    public function test_contract_liquidity_types_contains_cashbox_wallet_bank_only(): void
    {
        $this->assertSame(
            ['cashbox', 'wallet', 'bank'],
            AccountModuleContract::LIQUIDITY_TYPES,
            'AccountModuleContract::LIQUIDITY_TYPES must be exactly 3 values after Phase 3.5b'
        );
    }

    public function test_belongs_bus_accepts_alias_module_bus(): void
    {
        $aliasOnly = $this->makeAccount('bus alias only', AccountType::Cashbox, 'office', 'bus');
        $this->assertTrue(
            BusLiquidityAccount::belongsToBusModule($aliasOnly),
            'BusLiquidityAccount must accept module_type=office + module=bus alias'
        );
    }

    public function test_belongs_hajjumra_accepts_legacy_aliases(): void
    {
        $h = $this->makeAccount('hajj alias hajj', AccountType::Cashbox, 'hajj', 'hajj');
        $u = $this->makeAccount('hajj alias umrah', AccountType::Cashbox, 'umrah', 'umrah');
        $this->assertTrue(HajjUmraLiquidityAccount::belongsToHajjUmraModule($h));
        $this->assertTrue(HajjUmraLiquidityAccount::belongsToHajjUmraModule($u));
    }

    public function test_belongs_visa_accepts_legacy_singular_alias(): void
    {
        $singular = $this->makeAccount('visa singular', AccountType::Cashbox, 'visa', 'visa');
        $this->assertTrue(
            VisaLiquidityAccount::belongsToVisaModule($singular),
            'VisaLiquidityAccount must accept legacy singular "visa" alias on either column'
        );
    }

    public function test_belongs_hajjumra_rejects_tourism_with_narrowed_module(): void
    {
        // tourism-division vault that has a narrowed module label is NOT
        // a true division-unified vault — should be rejected to avoid
        // ambiguous ownership.
        $narrowed = $this->makeAccount('tourism narrowed hajj', AccountType::Cashbox, 'tourism', 'hajj_umra');
        $this->assertFalse(
            HajjUmraLiquidityAccount::belongsToHajjUmraModule($narrowed),
            'HajjUmraLiquidityAccount must reject tourism-division vault with narrowed module label'
        );
    }
}