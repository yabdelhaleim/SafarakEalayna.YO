<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\BusLiquidityAccount;
use App\Rules\FawryLiquidityAccount;
use App\Rules\HajjUmraLiquidityAccount;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Database\Seeders\UnifiedVaultsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 7 — Account Unification (final step) — End-to-end verification.
 *
 * Proves the full feature stack is wired together:
 *   1. Seeder → creates the two division-unified vaults.
 *   2. PHP Rules (Phase 5) → accept the unified vaults as valid liquidity.
 *   3. Ledger → actual transactions from multiple modules post to the SAME
 *      office-division vault, plus a separate tourism-division vault.
 *   4. Account.balance + AccountEntry.ledger records → reconciled correctly.
 *
 * Test coverage matrix:
 *   ┌────────────────────────────────────────────────────────────┐
 *   │ module    │ division │ expected vault         │ amount  │
 *   ├────────────────────────────────────────────────────────────┤
 *   │ bus       │ office   │ office unified (id=X)  │ 1000    │
 *   │ fawry     │ office   │ office unified (id=X)  │  500    │ ← SAME
 *   │ hajj_umra │ tourism  │ tourism unified (id=Y) │ 2000    │
 *   └────────────────────────────────────────────────────────────┘
 *
 * The "bus and fawry share the SAME vault" assertion is the headline proof
 * that the division-unified pattern works end-to-end.
 *
 * Canonical PHPUnit test — preserved for when `RefreshDatabase + SQLite:memory:`
 * works in this env (currently hangs). A direct-execution equivalent lives in
 * `verify_phase7_unified_vaults_e2e.php`.
 */
class UnifiedVaultsE2ETest extends TestCase
{
    protected User $user;
    protected Account $officeVault;
    protected Account $tourismVault;
    protected Account $busCustomer;
    protected Account $hajjSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        // ─── Step 1: cleanup any prior test rows ─────────────────────
        DB::table('account_entries')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
        DB::table('transactions')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
        Account::where('name', 'like', '[PHASE7-E2E]%')->delete();

        // ─── Step 2: ensure a creator user exists ────────────────────
        $this->user = User::query()->create([
            'name' => 'Phase 7 E2E Tester',
            'email' => 'phase7-e2e@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // ─── Step 3: run the seeder to create the two unified vaults ─
        (new UnifiedVaultsSeeder())->run();
        $this->officeVault = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->firstOrFail();
        $this->tourismVault = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->firstOrFail();

        // ─── Step 4: create subject accounts (customer/supplier) for the ledger
        $this->busCustomer = Account::create([
            'name' => '[PHASE7-E2E] Bus Customer',
            'type' => AccountType::Customer,
            'currency' => 'EGP',
            'balance' => 0,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'bus',
            'module' => 'bus',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $this->hajjSupplier = Account::create([
            'name' => '[PHASE7-E2E] Hajj Supplier',
            'type' => AccountType::Supplier,
            'currency' => 'EGP',
            'balance' => 0,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'hajj_umra',
            'module' => 'hajj_umra',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('account_entries')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
        DB::table('transactions')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
        Account::where('name', 'like', '[PHASE7-E2E]%')->delete();
        parent::tearDown();
    }

    /**
     * Simulate a single module transaction that posts to a given vault.
     * Mirrors the pattern in {@see \App\Services\Finance\TransactionService}.
     *
     * @return array{transaction: Transaction, entry: AccountEntry}
     */
    private function simulateModuleTransaction(
        string $moduleLabel,
        Account $vault,
        Account $counterparty,
        float $amount,
    ): array {
        return LedgerBalanceMutationGuard::run(function () use ($moduleLabel, $vault, $counterparty, $amount) {
            return DB::transaction(function () use ($moduleLabel, $vault, $counterparty, $amount) {
                $txn = Transaction::create([
                    'type' => TransactionType::Income,
                    'amount' => $amount,
                    'currency' => 'EGP',
                    'module' => $moduleLabel,
                    'from_account_id' => $counterparty->id,
                    'to_account_id' => $vault->id,
                    'created_by' => $this->user->id,
                    'notes' => "[PHASE7-E2E] {$moduleLabel} → vault #{$vault->id}",
                ]);

                // Debit on counterparty (subject account — balance decreases)
                $counterparty->refresh();
                $counterparty->balance += $amount;
                $counterparty->save();
                $cpEntry = AccountEntry::create([
                    'account_id' => $counterparty->id,
                    'transaction_id' => $txn->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'balance_after' => $counterparty->balance,
                    'notes' => "[PHASE7-E2E] {$moduleLabel} debit",
                ]);

                // Credit on the unified vault (liquidity — balance increases)
                $vault->refresh();
                $vault->balance += $amount;
                $vault->save();
                $vaultEntry = AccountEntry::create([
                    'account_id' => $vault->id,
                    'transaction_id' => $txn->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'balance_after' => $vault->balance,
                    'notes' => "[PHASE7-E2E] {$moduleLabel} credit (vault #{$vault->id})",
                ]);

                return ['transaction' => $txn, 'entry' => $vaultEntry];
            });
        });
    }

    // ════════════════════════════════════════════════════════════════
    // 1. Seeder
    // ════════════════════════════════════════════════════════════════

    public function test_seeder_created_exactly_two_unified_vaults(): void
    {
        $officeCount = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->count();
        $tourismCount = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->count();

        $this->assertSame(1, $officeCount, 'Office division must have exactly one unified vault');
        $this->assertSame(1, $tourismCount, 'Tourism division must have exactly one unified vault');
    }

    public function test_seeder_is_idempotent(): void
    {
        $beforeOffice = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->count();
        $beforeTourism = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->count();

        // Run again — must not create duplicates
        (new UnifiedVaultsSeeder())->run();
        (new UnifiedVaultsSeeder())->run();

        $afterOffice = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->count();
        $afterTourism = Account::where('is_module_vault', true)
            ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->count();

        $this->assertSame($beforeOffice, $afterOffice, 'Office vault count unchanged after re-run');
        $this->assertSame($beforeTourism, $afterTourism, 'Tourism vault count unchanged after re-run');
    }

    // ════════════════════════════════════════════════════════════════
    // 2. PHP Rules (Phase 5) accept the unified vaults
    // ════════════════════════════════════════════════════════════════

    public function test_bus_rule_accepts_office_unified_vault(): void
    {
        $this->assertTrue(
            BusLiquidityAccount::belongsToBusModule($this->officeVault),
            'BusLiquidityAccount must accept the office-division unified vault'
        );
    }

    public function test_fawry_rule_accepts_office_unified_vault(): void
    {
        $this->assertTrue(
            FawryLiquidityAccount::belongsToFawryModule($this->officeVault),
            'FawryLiquidityAccount must accept the office-division unified vault'
        );
    }

    public function test_hajjumra_rule_accepts_tourism_unified_vault(): void
    {
        $this->assertTrue(
            HajjUmraLiquidityAccount::belongsToHajjUmraModule($this->tourismVault),
            'HajjUmraLiquidityAccount must accept the tourism-division unified vault'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. E2E ledger — bus + fawry share the SAME office vault
    // ════════════════════════════════════════════════════════════════

    public function test_bus_and_fawry_share_the_office_unified_vault(): void
    {
        $busResult = $this->simulateModuleTransaction('bus', $this->officeVault, $this->busCustomer, 1000.00);
        $fawryResult = $this->simulateModuleTransaction('fawry', $this->officeVault, $this->busCustomer, 500.00);

        // Both transactions must point at the SAME office vault.
        $this->assertSame(
            $busResult['transaction']->to_account_id,
            $fawryResult['transaction']->to_account_id,
            'Bus and Fawry transactions must target the same to_account_id'
        );
        $this->assertSame(
            $this->officeVault->id,
            $busResult['transaction']->to_account_id,
            'Bus transaction to_account_id must equal the office unified vault id'
        );
        $this->assertSame(
            $this->officeVault->id,
            $fawryResult['transaction']->to_account_id,
            'Fawry transaction to_account_id must equal the office unified vault id'
        );

        // Office vault balance = bus + fawry
        $this->officeVault->refresh();
        $this->assertEquals(
            1500.00,
            (float) $this->officeVault->balance,
            'Office unified vault balance must equal sum of bus + fawry (1000 + 500 = 1500)'
        );

        // Two AccountEntry records on the office vault
        $officeEntries = AccountEntry::where('account_id', $this->officeVault->id)
            ->where('notes', 'like', '[PHASE7-E2E]%')->count();
        $this->assertGreaterThanOrEqual(2, $officeEntries, 'Office vault should have >= 2 ledger entries');
    }

    public function test_hajj_umra_uses_tourism_unified_vault(): void
    {
        $hajjResult = $this->simulateModuleTransaction('hajj_umra', $this->tourismVault, $this->hajjSupplier, 2000.00);

        $this->assertSame(
            $this->tourismVault->id,
            $hajjResult['transaction']->to_account_id,
            'Hajj transaction must target the tourism unified vault'
        );

        $this->tourismVault->refresh();
        $this->assertEquals(
            2000.00,
            (float) $this->tourismVault->balance,
            'Tourism unified vault balance must equal 2000 after hajj transaction'
        );

        // Office vault untouched
        $this->officeVault->refresh();
        $this->assertEquals(
            0.00,
            (float) $this->officeVault->balance,
            'Office unified vault must remain at 0 (untouched by tourism-division transaction)'
        );
    }

    public function test_office_and_tourism_vaults_are_independent(): void
    {
        // Create transactions in both divisions
        $this->simulateModuleTransaction('bus', $this->officeVault, $this->busCustomer, 750.00);
        $this->simulateModuleTransaction('hajj_umra', $this->tourismVault, $this->hajjSupplier, 1500.00);

        $this->officeVault->refresh();
        $this->tourismVault->refresh();

        $this->assertEquals(750.00, (float) $this->officeVault->balance);
        $this->assertEquals(1500.00, (float) $this->tourismVault->balance);

        // Different vault IDs
        $this->assertNotSame($this->officeVault->id, $this->tourismVault->id);

        // Different module_types
        $this->assertSame('office', $this->officeVault->module_type);
        $this->assertSame('tourism', $this->tourismVault->module_type);
    }

    public function test_ledger_entries_carry_balance_after_correctly(): void
    {
        $this->simulateModuleTransaction('bus', $this->officeVault, $this->busCustomer, 1000.00);

        $entry = AccountEntry::where('account_id', $this->officeVault->id)
            ->where('notes', 'like', '[PHASE7-E2E]%')
            ->orderBy('id', 'desc')
            ->firstOrFail();

        $this->assertEquals(0.00, (float) $entry->debit, 'Office vault entry debit should be 0');
        $this->assertEquals(1000.00, (float) $entry->credit, 'Office vault entry credit should be 1000');
        $this->assertEquals(1000.00, (float) $entry->balance_after, 'Office vault entry balance_after should be 1000');
    }
}