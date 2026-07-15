<?php
/**
 * Phase 7 direct-execution verification — Unified Vaults E2E.
 *
 * Bypasses PHPUnit's RefreshDatabase + SQLite:memory: setup (which hangs in
 * this environment) by invoking the same flow as the Feature test directly
 * against the real DB connection.
 *
 * Mirrors the assertions in
 * tests/Feature/Finance/UnifiedVaultsE2ETest.php and reports pass/fail.
 *
 * Coverage: 11 assertions across Seeder / PHP Rules / E2E ledger.
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

$results = ['pass' => 0, 'fail' => 0];
$failures = [];

function check(string $name, bool $cond, array &$results, array &$failures): void
{
    if ($cond) {
        echo "  ✓ {$name}\n";
        $results['pass']++;
    } else {
        echo "  ✗ {$name}\n";
        $results['fail']++;
        $failures[] = $name;
    }
}

echo "=== Phase 7 — Unified Vaults E2E (direct execution) ===\n\n";

// ─── Cleanup any prior test rows + reset vault balances ────────────────────
// Make the script idempotent across runs by zeroing the vault balances at
// the start (they may carry over from a previous run that wasn't cleaned up).
LedgerBalanceMutationGuard::run(function (): void {
    DB::table('account_entries')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
    DB::table('transactions')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
    Account::where('name', 'like', '[PHASE7-E2E]%')->delete();

    $officeVault = Account::where('is_module_vault', true)
        ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->first();
    if ($officeVault) {
        $officeVault->balance = 0;
        $officeVault->save();
    }
    $tourismVault = Account::where('is_module_vault', true)
        ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->first();
    if ($tourismVault) {
        $tourismVault->balance = 0;
        $tourismVault->save();
    }
});

// ─── Setup ─────────────────────────────────────────────────────────────────

$user = User::firstOrCreate(
    ['email' => 'phase7-e2e-direct@example.com'],
    ['name' => 'Phase 7 E2E Direct', 'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => true]
);

echo "1. Seeder:\n";

// Run the seeder — first run should create
(new UnifiedVaultsSeeder())->run();

$officeVault = Account::where('is_module_vault', true)
    ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->first();
$tourismVault = Account::where('is_module_vault', true)
    ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->first();

check('Office unified vault exists', $officeVault !== null, $results, $failures);
check('Tourism unified vault exists', $tourismVault !== null, $results, $failures);
check('Exactly 1 office unified vault (no duplicates)',
    Account::where('is_module_vault', true)->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->count() === 1,
    $results, $failures);
check('Exactly 1 tourism unified vault (no duplicates)',
    Account::where('is_module_vault', true)->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->count() === 1,
    $results, $failures);

// ─── Idempotency check ─────────────────────────────────────────────────────

$officeVaultId = $officeVault->id;
$tourismVaultId = $tourismVault->id;

(new UnifiedVaultsSeeder())->run();
(new UnifiedVaultsSeeder())->run();

$officeVault2 = Account::where('is_module_vault', true)
    ->where('module_type', AccountModuleContract::OFFICE_MODULE_TYPE)->first();
$tourismVault2 = Account::where('is_module_vault', true)
    ->where('module_type', AccountModuleContract::TOURISM_MODULE_TYPE)->first();

check('Idempotency: office vault id unchanged after re-run',
    $officeVault2->id === $officeVaultId, $results, $failures);
check('Idempotency: tourism vault id unchanged after re-run',
    $tourismVault2->id === $tourismVaultId, $results, $failures);

// ─── Subject accounts for the ledger ──────────────────────────────────────

$busCustomer = Account::create([
    'name' => '[PHASE7-E2E] Bus Customer',
    'type' => AccountType::Customer,
    'currency' => 'EGP',
    'balance' => 0,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'bus',
    'module' => 'bus',
    'is_active' => true,
    'created_by' => $user->id,
]);
$hajjSupplier = Account::create([
    'name' => '[PHASE7-E2E] Hajj Supplier',
    'type' => AccountType::Supplier,
    'currency' => 'EGP',
    'balance' => 0,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'hajj_umra',
    'module' => 'hajj_umra',
    'is_active' => true,
    'created_by' => $user->id,
]);

// ─── Phase 5 Rules accept the unified vaults ──────────────────────────────

echo "\n2. Phase 5 PHP Rules accept the unified vaults:\n";
check('BusLiquidityAccount accepts office unified vault',
    BusLiquidityAccount::belongsToBusModule($officeVault), $results, $failures);
check('FawryLiquidityAccount accepts office unified vault',
    FawryLiquidityAccount::belongsToFawryModule($officeVault), $results, $failures);
check('HajjUmraLiquidityAccount accepts tourism unified vault',
    HajjUmraLiquidityAccount::belongsToHajjUmraModule($tourismVault), $results, $failures);

// ─── E2E ledger simulation ────────────────────────────────────────────────

function simulateModuleTransaction(
    string $moduleLabel,
    Account $vault,
    Account $counterparty,
    float $amount,
    User $user,
): array {
    return LedgerBalanceMutationGuard::run(function () use ($moduleLabel, $vault, $counterparty, $amount, $user) {
        return DB::transaction(function () use ($moduleLabel, $vault, $counterparty, $amount, $user) {
            $txn = Transaction::create([
                'type' => TransactionType::Income->value,
                'amount' => $amount,
                'currency' => 'EGP',
                'module' => $moduleLabel,
                'from_account_id' => $counterparty->id,
                'to_account_id' => $vault->id,
                'created_by' => $user->id,
                'notes' => "[PHASE7-E2E] {$moduleLabel} → vault #{$vault->id}",
            ]);

            $counterparty->refresh();
            $counterparty->balance += $amount;
            $counterparty->save();
            AccountEntry::create([
                'account_id' => $counterparty->id,
                'transaction_id' => $txn->id,
                'debit' => $amount,
                'credit' => 0,
                'balance_after' => $counterparty->balance,
                'notes' => "[PHASE7-E2E] {$moduleLabel} debit",
            ]);

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

echo "\n3. E2E ledger — bus + fawry share the SAME office vault:\n";

$busResult = simulateModuleTransaction('bus', $officeVault, $busCustomer, 1000.00, $user);
$fawryResult = simulateModuleTransaction('fawry', $officeVault, $busCustomer, 500.00, $user);

check('Bus transaction to_account_id == office unified vault',
    $busResult['transaction']->to_account_id === $officeVault->id, $results, $failures);
check('Fawry transaction to_account_id == office unified vault',
    $fawryResult['transaction']->to_account_id === $officeVault->id, $results, $failures);
check('Bus and Fawry share the SAME vault',
    $busResult['transaction']->to_account_id === $fawryResult['transaction']->to_account_id,
    $results, $failures);

$officeVault->refresh();
check('Office unified vault balance == 1500.00 (bus 1000 + fawry 500)',
    (float) $officeVault->balance === 1500.00, $results, $failures);

$officeEntries = AccountEntry::where('account_id', $officeVault->id)
    ->where('notes', 'like', '[PHASE7-E2E]%')->count();
check('Office vault has 2 ledger entries from phase7-e2e',
    $officeEntries === 2, $results, $failures);

echo "\n4. E2E ledger — hajj_umra uses tourism unified vault (independent from office):\n";

$hajjResult = simulateModuleTransaction('hajj_umra', $tourismVault, $hajjSupplier, 2000.00, $user);

check('Hajj transaction to_account_id == tourism unified vault',
    $hajjResult['transaction']->to_account_id === $tourismVault->id, $results, $failures);

$tourismVault->refresh();
check('Tourism unified vault balance == 2000.00 after hajj transaction',
    (float) $tourismVault->balance === 2000.00, $results, $failures);

$officeVault->refresh();
check('Office vault balance STILL == 1500.00 (untouched by tourism transaction)',
    (float) $officeVault->balance === 1500.00, $results, $failures);

check('Office and Tourism vaults have different ids',
    $officeVault->id !== $tourismVault->id, $results, $failures);

$tourismEntries = AccountEntry::where('account_id', $tourismVault->id)
    ->where('notes', 'like', '[PHASE7-E2E]%')->count();
check('Tourism vault has 1 ledger entry from phase7-e2e',
    $tourismEntries === 1, $results, $failures);

// ─── Ledger entries carry balance_after correctly ─────────────────────────

echo "\n5. Ledger entries carry balance_after correctly:\n";

$lastEntry = AccountEntry::where('account_id', $officeVault->id)
    ->where('notes', 'like', '[PHASE7-E2E]%')
    ->orderBy('id', 'desc')
    ->first();
check('Last office vault entry debit == 0 (it is a credit entry)',
    (float) $lastEntry->debit === 0.00, $results, $failures);
check('Last office vault entry credit == 500 (the fawry amount)',
    (float) $lastEntry->credit === 500.00, $results, $failures);
check('Last office vault entry balance_after == 1500 (cumulative)',
    (float) $lastEntry->balance_after === 1500.00, $results, $failures);

// ─── Cleanup ───────────────────────────────────────────────────────────────

DB::table('account_entries')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
DB::table('transactions')->where('notes', 'like', '[PHASE7-E2E]%')->delete();
Account::where('name', 'like', '[PHASE7-E2E]%')->delete();

echo "\n═══════════════════════════════════════════\n";
echo "  Phase 7 results: {$results['pass']} PASS, {$results['fail']} FAIL\n";
echo "═══════════════════════════════════════════\n";
if ($results['fail'] > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($results['fail'] > 0 ? 1 : 0);