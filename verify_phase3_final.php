<?php
/**
 * Phase 3 final verification — v2 reflects actual DB contract.
 *
 * Important findings from live verification:
 *   - accounts.type enum: cashbox, wallet, bank, customer, supplier, expense,
 *     revenue, liability, owner (treasury/post dropped by migrations)
 *   - accounts.module_type: NOT NULL with default 'tourism'
 *
 * Tests are updated accordingly:
 *   - T2 uses 'bank' instead of 'treasury'
 *   - T6, T7, T8 provide module_type explicitly (DB requires it)
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\User;
use App\Enums\AccountType;
use App\Support\Finance\AccountModuleContract;

echo "=== Phase 3 final verification v2 (live MySQL DB) ===\n\n";

$user = User::firstOrCreate(
    ['email' => 'phase3-final@example.com'],
    [
        'name' => 'Phase 3 Final Verifier',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'is_active' => true,
    ]
);
echo "Test user id={$user->id}\n\n";

$results = [];

// T1 — Office division cashbox persists
try {
    $acc = Account::create([
        'name' => '[T1] Office Cashbox',
        'type' => AccountType::Cashbox,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'module' => null,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T1_office_cashbox_persists'] = ($acc->module_type === 'office') ? 'PASS' : "FAIL — got {$acc->module_type}";
    $acc->delete();
} catch (\Throwable $e) { $results['T1_office_cashbox_persists'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// T2 — Tourism division bank persists (bank instead of treasury)
try {
    $acc = Account::create([
        'name' => '[T2] Tourism Bank',
        'type' => AccountType::Bank,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
        'module' => null,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T2_tourism_bank_persists'] = ($acc->module_type === 'tourism') ? 'PASS' : "FAIL — got {$acc->module_type}";
    $acc->delete();
} catch (\Throwable $e) { $results['T2_tourism_bank_persists'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// T3 — Specific-module bank auto-fills module
try {
    $acc = Account::create([
        'name' => '[T3] Bank fawry',
        'type' => AccountType::Bank,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'fawry',
        'module' => null,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T3_auto_fill_module'] = ($acc->module === 'fawry') ? 'PASS' : "FAIL — got {$acc->module}";
    $acc->delete();
} catch (\Throwable $e) { $results['T3_auto_fill_module'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// T4 — Cashbox with null module_type is rejected (by hook)
try {
    Account::create([
        'name' => '[T4] Bad null cashbox',
        'type' => AccountType::Cashbox,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => null,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T4_null_module_rejected'] = 'FAIL — accepted null (BUG)';
} catch (\InvalidArgumentException $e) {
    $results['T4_null_module_rejected'] = 'PASS — InvalidArgumentException thrown';
} catch (\Throwable $e) { $results['T4_null_module_rejected'] = 'FAIL — wrong exception: '.get_class($e); }

// T5 — Wallet with null module_type is rejected (by hook)
try {
    Account::create([
        'name' => '[T5] Bad null wallet',
        'type' => AccountType::Wallet,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => null,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T5_wallet_null_rejected'] = 'FAIL — accepted null (BUG)';
} catch (\InvalidArgumentException $e) {
    $results['T5_wallet_null_rejected'] = 'PASS — exception thrown';
} catch (\Throwable $e) { $results['T5_wallet_null_rejected'] = 'FAIL — wrong exception: '.get_class($e); }

// T6 — Customer (subject) with module_type=office persists
// Updated: must provide module_type (DB NOT NULL)
try {
    $acc = Account::create([
        'name' => '[T6] Customer AR',
        'type' => AccountType::Customer,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T6_customer_with_module_persists'] = ($acc->id) ? 'PASS' : 'FAIL';
    $acc->delete();
} catch (\Throwable $e) { $results['T6_customer_with_module_persists'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// T7 — Supplier (subject) with module_type=office persists
try {
    $acc = Account::create([
        'name' => '[T7] Supplier AP',
        'type' => AccountType::Supplier,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T7_supplier_with_module_persists'] = ($acc->id) ? 'PASS' : 'FAIL';
    $acc->delete();
} catch (\Throwable $e) { $results['T7_supplier_with_module_persists'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// T8 — Internal Expense with module_type=office persists
try {
    $acc = Account::create([
        'name' => '[T8] Expense GL',
        'type' => AccountType::Expense,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => AccountModuleContract::OFFICE_MODULE_TYPE,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $results['T8_expense_with_module_persists'] = ($acc->id) ? 'PASS' : 'FAIL';
    $acc->delete();
} catch (\Throwable $e) { $results['T8_expense_with_module_persists'] = 'FAIL — '.get_class($e).': '.substr($e->getMessage(),0,80); }

// Print
echo str_repeat('─', 80) . "\n";
echo str_pad('Test', 50) . "Result\n";
echo str_repeat('─', 80) . "\n";
foreach ($results as $name => $r) {
    echo str_pad($name, 50) . $r . "\n";
}
echo str_repeat('─', 80) . "\n";

$pass = count(array_filter($results, fn($r) => str_starts_with($r, 'PASS')));
$fail = count($results) - $pass;
echo "TOTAL: $pass PASS, $fail FAIL out of " . count($results) . " tests\n";

exit($fail > 0 ? 1 : 0);
