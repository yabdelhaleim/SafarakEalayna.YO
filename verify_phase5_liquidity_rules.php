<?php
/**
 * Phase 5 direct-execution verification — Liquidity Account Rules broadening.
 *
 * Bypasses PHPUnit's RefreshDatabase + SQLite:memory: setup (which hangs in
 * this environment) by invoking each ValidationRule directly against the real
 * DB connection.
 *
 * Mirrors the assertions in
 * tests/Feature/Finance/LiquidityAccountRulesTest.php and reports pass/fail.
 *
 * IMPORTANT: Per the Phase 3.5 saving hook, liquidity accounts (cashbox/wallet/bank)
 * MUST have module_type=office|tourism. The "module-specific" vaults here are
 * therefore stored as module_type=office + module=<own_module> (the bus-narrowed
 * version of the office-unified vault). The Rules' broadened acceptance handles
 * both this narrowed form and the truly-unified form (module_type=office, no label).
 *
 * Coverage: 6 Rules × 5 assertions + 7 cross-cutting invariants = 37 checks.
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

function ruleFails(object $rule, int $accountId): bool
{
    $failed = false;
    $rule->validate('account_id', $accountId, function (string $message) use (&$failed): void {
        $failed = true;
    });
    return $failed;
}

echo "=== Phase 5 — LiquidityAccount Rules (direct execution) ===\n\n";

// ─── Fixtures ────────────────────────────────────────────────────────────────

$user = User::firstOrCreate(
    ['email' => 'phase5-liq-rules@example.com'],
    ['name' => 'Phase 5 Liq Tester', 'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => true]
);
Account::query()->where('name', 'like', '[LIQ-RULES]%')->delete();

/**
 * Build a fixture account using the DUAL-MEANING pattern:
 *  - Liquidity → module_type=office|tourism, module=<label|narrowed>
 *  - Subject   → module_type=<specific module>, type=customer|supplier
 */
function makeAccount(User $user, string $key, AccountType $type, string $moduleType, ?string $module, bool $active = true): Account
{
    return Account::create([
        'name' => "[LIQ-RULES] {$key}",
        'type' => $type,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => $moduleType,
        'module' => $module,
        'is_active' => $active,
        'created_by' => $user->id,
    ]);
}

$fx = [];

// Module-narrowed office vaults (office-division cashboxes with a label)
$fx['bus_cashbox']     = makeAccount($user, 'bus cashbox',     AccountType::Cashbox, 'office', 'bus');
$fx['fawry_cashbox']   = makeAccount($user, 'fawry cashbox',   AccountType::Cashbox, 'office', 'fawry');
$fx['online_cashbox']  = makeAccount($user, 'online cashbox',  AccountType::Cashbox, 'office', 'online');
$fx['transfer_wallet'] = makeAccount($user, 'transfer wallet', AccountType::Wallet,  'office', 'wallet_transfer');

// Module-narrowed tourism vaults
$fx['hajj_cashbox']    = makeAccount($user, 'hajj cashbox',    AccountType::Cashbox, 'tourism', 'hajj_umra');
$fx['visa_cashbox']    = makeAccount($user, 'visa cashbox',    AccountType::Cashbox, 'tourism', 'visas');

// Truly-unified division vaults (module=null → auto-filled by saving hook)
$fx['office_unified']  = makeAccount($user, 'office unified',  AccountType::Bank, 'office', null);
$fx['tourism_unified'] = makeAccount($user, 'tourism unified', AccountType::Bank, 'tourism', null);

// Subject (wrong type) fixture — bus customer
$fx['bus_customer']    = makeAccount($user, 'bus customer',    AccountType::Customer, 'bus', 'bus');

// ─── 1. BusLiquidityAccount ──────────────────────────────────────────────────

echo "1. BusLiquidityAccount (own_module=bus, division=office):\n";
check('accepts bus-narrowed office vault (module_type=office, module=bus)',
    !ruleFails(new BusLiquidityAccount, $fx['bus_cashbox']->id), $results, $failures);
check('accepts truly-unified office vault (module_type=office, module=null)',
    !ruleFails(new BusLiquidityAccount, $fx['office_unified']->id), $results, $failures);
check('rejects tourism-division vault',
    ruleFails(new BusLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
check('rejects subject (customer) account',
    ruleFails(new BusLiquidityAccount, $fx['bus_customer']->id), $results, $failures);
$busInactive = makeAccount($user, 'bus inactive', AccountType::Cashbox, 'office', 'bus', active: false);
check('rejects inactive account',
    ruleFails(new BusLiquidityAccount, $busInactive->id), $results, $failures);

// ─── 2. HajjUmraLiquidityAccount ─────────────────────────────────────────────

echo "\n2. HajjUmraLiquidityAccount (own_module=hajj_umra, division=tourism):\n";
check('accepts hajj-narrowed tourism vault (module_type=tourism, module=hajj_umra)',
    !ruleFails(new HajjUmraLiquidityAccount, $fx['hajj_cashbox']->id), $results, $failures);
check('accepts truly-unified tourism vault (module_type=tourism, module=null)',
    !ruleFails(new HajjUmraLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
check('rejects office-division vault',
    ruleFails(new HajjUmraLiquidityAccount, $fx['office_unified']->id), $results, $failures);
$hajjCustomer = makeAccount($user, 'hajj customer', AccountType::Customer, 'hajj_umra', 'hajj_umra');
check('rejects subject (customer) account',
    ruleFails(new HajjUmraLiquidityAccount, $hajjCustomer->id), $results, $failures);
$hajjInactive = makeAccount($user, 'hajj inactive', AccountType::Cashbox, 'tourism', 'hajj_umra', active: false);
check('rejects inactive account',
    ruleFails(new HajjUmraLiquidityAccount, $hajjInactive->id), $results, $failures);

// ─── 3. FawryLiquidityAccount ────────────────────────────────────────────────

echo "\n3. FawryLiquidityAccount (own_module=fawry, division=office):\n";
check('accepts fawry-narrowed office vault (module_type=office, module=fawry)',
    !ruleFails(new FawryLiquidityAccount, $fx['fawry_cashbox']->id), $results, $failures);
check('accepts truly-unified office vault',
    !ruleFails(new FawryLiquidityAccount, $fx['office_unified']->id), $results, $failures);
check('rejects tourism-division vault',
    ruleFails(new FawryLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
$fawrySupplier = makeAccount($user, 'fawry supplier', AccountType::Supplier, 'fawry', 'fawry');
check('rejects subject (supplier) account',
    ruleFails(new FawryLiquidityAccount, $fawrySupplier->id), $results, $failures);
$fawryInactive = makeAccount($user, 'fawry inactive', AccountType::Cashbox, 'office', 'fawry', active: false);
check('rejects inactive account',
    ruleFails(new FawryLiquidityAccount, $fawryInactive->id), $results, $failures);

// ─── 4. OnlineLiquidityAccount ───────────────────────────────────────────────

echo "\n4. OnlineLiquidityAccount (own_module=online, division=office):\n";
check('accepts online-narrowed office vault (module_type=office, module=online)',
    !ruleFails(new OnlineLiquidityAccount, $fx['online_cashbox']->id), $results, $failures);
check('accepts truly-unified office vault',
    !ruleFails(new OnlineLiquidityAccount, $fx['office_unified']->id), $results, $failures);
check('rejects tourism-division vault',
    ruleFails(new OnlineLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
$onlineCustomer = makeAccount($user, 'online customer', AccountType::Customer, 'online', 'online');
check('rejects subject (customer) account',
    ruleFails(new OnlineLiquidityAccount, $onlineCustomer->id), $results, $failures);
$onlineInactive = makeAccount($user, 'online inactive', AccountType::Cashbox, 'office', 'online', active: false);
check('rejects inactive account',
    ruleFails(new OnlineLiquidityAccount, $onlineInactive->id), $results, $failures);

// ─── 5. VisaLiquidityAccount ─────────────────────────────────────────────────

echo "\n5. VisaLiquidityAccount (own_module=visas, division=tourism):\n";
check('accepts visa-narrowed tourism vault (module_type=tourism, module=visas)',
    !ruleFails(new VisaLiquidityAccount, $fx['visa_cashbox']->id), $results, $failures);
check('accepts truly-unified tourism vault',
    !ruleFails(new VisaLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
check('rejects office-division vault',
    ruleFails(new VisaLiquidityAccount, $fx['office_unified']->id), $results, $failures);
$visaCustomer = makeAccount($user, 'visa customer', AccountType::Customer, 'visas', 'visas');
check('rejects subject (customer) account',
    ruleFails(new VisaLiquidityAccount, $visaCustomer->id), $results, $failures);
$visaInactive = makeAccount($user, 'visa inactive', AccountType::Cashbox, 'tourism', 'visas', active: false);
check('rejects inactive account',
    ruleFails(new VisaLiquidityAccount, $visaInactive->id), $results, $failures);

// ─── 6. TransferLiquidityAccount ─────────────────────────────────────────────

echo "\n6. TransferLiquidityAccount (own_module=wallet_transfer, division=office):\n";
check('accepts wallet_transfer-narrowed office vault (module_type=office, module=wallet_transfer)',
    !ruleFails(new TransferLiquidityAccount, $fx['transfer_wallet']->id), $results, $failures);
check('accepts truly-unified office vault',
    !ruleFails(new TransferLiquidityAccount, $fx['office_unified']->id), $results, $failures);
check('rejects tourism-division vault',
    ruleFails(new TransferLiquidityAccount, $fx['tourism_unified']->id), $results, $failures);
$transferCustomer = makeAccount($user, 'transfer customer', AccountType::Customer, 'wallet_transfer', 'wallet_transfer');
check('rejects subject (customer) account',
    ruleFails(new TransferLiquidityAccount, $transferCustomer->id), $results, $failures);
$transferInactive = makeAccount($user, 'transfer inactive', AccountType::Wallet, 'office', 'wallet_transfer', active: false);
check('rejects inactive account',
    ruleFails(new TransferLiquidityAccount, $transferInactive->id), $results, $failures);

// ─── 7. Cross-cutting invariants (AccountModuleContract + helpers) ───────────

echo "\n7. Cross-cutting invariants:\n";
check('AccountModuleContract::LIQUIDITY_TYPES is exactly [cashbox, wallet, bank]',
    AccountModuleContract::LIQUIDITY_TYPES === ['cashbox', 'wallet', 'bank'], $results, $failures);

// Bus rule accepts office+bus alias on module column
check('BusLiquidityAccount accepts module_type=office + module=bus (alias)',
    BusLiquidityAccount::belongsToBusModule($fx['bus_cashbox']), $results, $failures);

// HajjUmra rule accepts legacy aliases 'hajj' and 'umrah' (raw insert to simulate legacy data)
// These bypass the saving hook because the saving hook would reject them — they only exist
// in pre-Phase 3.5 legacy data. The Rules accept them defensively for backward compat.
function makeLegacyAccount(User $user, string $key, AccountType $type, string $moduleType, ?string $module): Account
{
    $now = now();
    DB::table('accounts')->insert([
        'name' => "[LIQ-RULES] {$key}",
        'type' => $type->value,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => 'office',
        'module_type' => $moduleType,
        'module' => $module,
        'is_active' => 1,
        'is_module_vault' => 0,
        'created_by' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return Account::where('name', "[LIQ-RULES] {$key}")->firstOrFail();
}

$legacyHajj = makeLegacyAccount($user, 'legacy hajj alias', AccountType::Cashbox, 'hajj', 'hajj');
$legacyUmrah = makeLegacyAccount($user, 'legacy umrah alias', AccountType::Cashbox, 'umrah', 'umrah');
check('HajjUmraLiquidityAccount accepts legacy alias "hajj"',
    HajjUmraLiquidityAccount::belongsToHajjUmraModule($legacyHajj), $results, $failures);
check('HajjUmraLiquidityAccount accepts legacy alias "umrah"',
    HajjUmraLiquidityAccount::belongsToHajjUmraModule($legacyUmrah), $results, $failures);

// Visa rule accepts legacy singular 'visa' (raw insert to simulate legacy data)
$legacyVisa = makeLegacyAccount($user, 'legacy visa singular', AccountType::Cashbox, 'visa', 'visa');
check('VisaLiquidityAccount accepts legacy singular "visa" alias',
    VisaLiquidityAccount::belongsToVisaModule($legacyVisa), $results, $failures);

// Cross-Rule: office-division vault narrowed to fawry should be REJECTED by BusLiquidityAccount's
// strict alias check (which only accepts 'bus' as a module alias) BUT ACCEPTED by the broadened
// office-division check. The narrowed label is NOT used as a filter per the contract.
check('BusLiquidityAccount accepts office vault narrowed to fawry (label is just a label)',
    BusLiquidityAccount::belongsToBusModule($fx['fawry_cashbox']), $results, $failures);

// applyLiquidityScope (HajjUmra) — used by Filament Resource — must include every
// tourism-division liquidity vault, since the broadened contract says all such
// vaults are valid for hajj_umra transactions. Expected: hajj + visa + unified
// (plus legacy hajj/umrah fixtures that were raw-inserted earlier).
$scoped = HajjUmraLiquidityAccount::applyLiquidityScope(Account::query())
    ->where('name', 'like', '[LIQ-RULES]%')
    ->pluck('name')->all();
sort($scoped);
$expected = [
    '[LIQ-RULES] hajj cashbox',
    '[LIQ-RULES] legacy hajj alias',
    '[LIQ-RULES] legacy umrah alias',
    '[LIQ-RULES] tourism unified',
    '[LIQ-RULES] visa cashbox',
];
sort($expected);
check('HajjUmraLiquidityAccount::applyLiquidityScope returns all tourism-division vaults (hajj + visa + unified + legacy hajj/umrah)',
    $scoped === $expected, $results, $failures);

// Cleanup
Account::query()->where('name', 'like', '[LIQ-RULES]%')->delete();

echo "\n═══════════════════════════════════════════\n";
echo "  Phase 5 results: {$results['pass']} PASS, {$results['fail']} FAIL\n";
echo "═══════════════════════════════════════════\n";
if ($results['fail'] > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($results['fail'] > 0 ? 1 : 0);