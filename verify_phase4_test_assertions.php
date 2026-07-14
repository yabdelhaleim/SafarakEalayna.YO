<?php
/**
 * Run the AccountResourceCategoryTabsTest assertions directly (bypassing
 * PHPUnit's broken RefreshDatabase+SQLite:memory: setup).
 *
 * This script mirrors the assertions in
 * tests/Feature/Filament/AccountResourceCategoryTabsTest.php and reports
 * pass/fail. The actual test file is preserved for when the test
 * infrastructure is repaired.
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Filament\Resources\Finance\AccountResource\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;

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

echo "=== Phase 4 Feature test: AccountResourceCategoryTabsTest (direct execution) ===\n\n";

// Set up: same as the Feature test setUp()
$user = User::firstOrCreate(
    ['email' => 'tabs-tester@example.com'],
    ['name' => 'Tabs Tester', 'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => true]
);

// Cleanup any prior test rows
Account::where('name', 'like', '[TABS-TEST]%')->delete();

$seed = [
    ['liquidity', AccountType::Cashbox, 'office'],
    ['liquidity', AccountType::Bank,    'office'],
    ['subject',   AccountType::Customer, 'bus'],
    ['subject',   AccountType::Supplier, 'fawry'],
    ['subject',   AccountType::Customer, 'flights'],
    ['internal',  AccountType::Expense,  'office'],
];
foreach ($seed as [$cat, $type, $moduleType]) {
    Account::create([
        'name' => "[TABS-TEST] {$cat} {$type->value}",
        'type' => $type,
        'currency' => 'EGP',
        'balance' => 0,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => $moduleType,
        'is_active' => true,
        'created_by' => $user->id,
    ]);
}

// Helper to invoke the private getTableQuery on a ListAccounts instance.
// We inject a custom Request with the desired ?category= query parameter
// because resolveCategoryFromRequest() reads from app(Request::class).
function getQueryFor(string $category): \Illuminate\Database\Eloquent\Builder
{
    $url = '/admin/finance/accounts' . ($category !== '' ? '?category=' . $category : '');
    $fakeRequest = \Illuminate\Http\Request::create($url, 'GET');
    app()->instance(\Illuminate\Http\Request::class, $fakeRequest);

    $page = new ListAccounts;
    $reflection = new \ReflectionMethod($page, 'getTableQuery');
    $reflection->setAccessible(true);
    return $reflection->invoke($page);
}

// 1. No category returns all
$count = getQueryFor('')->count();
check('test_no_category_returns_all_seeded_accounts (6 rows)', $count === 6, $results, $failures);

// 2. Liquidity
$rows = getQueryFor('liquidity')->get();
check('test_liquidity_filters_to_2_rows', $rows->count() === 2, $results, $failures);
// type is cast to BackedEnum (AccountType) on the model; compare via ->value.
$allLiquidity = $rows->every(fn($r) => in_array($r->type->value, AccountModuleContract::LIQUIDITY_TYPES, true));
check('test_liquidity_every_row_in_LIQUIDITY_TYPES', $allLiquidity, $results, $failures);

// 3. Subject
$rows = getQueryFor('subject')->get();
check('test_subject_filters_to_3_rows', $rows->count() === 3, $results, $failures);
$allSubject = $rows->every(fn($r) => in_array($r->type->value, AccountModuleContract::SUBJECT_TYPES, true));
check('test_subject_every_row_in_SUBJECT_TYPES', $allSubject, $results, $failures);

// 4. Internal
$rows = getQueryFor('internal')->get();
check('test_internal_filters_to_1_row', $rows->count() === 1, $results, $failures);
$allInternal = $rows->every(fn($r) => in_array($r->type->value, AccountModuleContract::INTERNAL_TYPES, true));
check('test_internal_every_row_in_INTERNAL_TYPES', $allInternal, $results, $failures);

// 5. Unknown falls back to all
$count = getQueryFor('unknown-category-xyz')->count();
check('test_unknown_falls_back_to_all (6 rows)', $count === 6, $results, $failures);

// 6. Empty string is treated as all
$count = getQueryFor('')->count();
check('test_empty_string_treated_as_all (6 rows)', $count === 6, $results, $failures);

// 7. Sum equals all
$all = getQueryFor('')->count();
$sum = getQueryFor('liquidity')->count() + getQueryFor('subject')->count() + getQueryFor('internal')->count();
check('test_filtered_sums_equal_all', $sum === $all, $results, $failures);

// 8. resolveCategoryFromRequest helper
echo "\n8. resolveCategoryFromRequest() helper:\n";
$page = new ListAccounts;
$method = new \ReflectionMethod($page, 'resolveCategoryFromRequest');
$method->setAccessible(true);
$cases = [
    'liquidity' => 'liquidity',
    'subject'   => 'subject',
    'internal'  => 'internal',
    ''          => '',
    'bogus'     => '',
    '../etc'    => '',
];
foreach ($cases as $input => $expected) {
    $request = \Illuminate\Http\Request::create('/', 'GET', $input === '' ? [] : ['category' => $input]);
    // resolveCategoryFromRequest() uses app(Request::class) — bind BOTH aliases
    // (some Laravel versions route 'request' and Request::class separately).
    app()->instance('request', $request);
    app()->instance(\Illuminate\Http\Request::class, $request);
    $actual = $method->invoke($page);
    check("input='{$input}' resolves to '{$expected}' (got '{$actual}')", $actual === $expected, $results, $failures);
}

// 9. Blade view
echo "\n9. Blade view smoke:\n";
// Inject a clean request with no ?category= so the default 'All' tab is active.
$cleanRequest = \Illuminate\Http\Request::create('http://localhost/admin/finance/accounts', 'GET');
app()->instance('request', $cleanRequest);
app()->instance(\Illuminate\Http\Request::class, $cleanRequest);
$html = view('filament.finance.account-tabs')->render();
check('contains "الكل" label', str_contains($html, 'الكل'), $results, $failures);
check('contains "تشغيلي" label', str_contains($html, 'تشغيلي'), $results, $failures);
check('contains "موضوعي" label', str_contains($html, 'موضوعي'), $results, $failures);
check('contains "إقفال" label', str_contains($html, 'إقفال'), $results, $failures);
check('has aria-label="Account category"', str_contains($html, 'aria-label="Account category"'), $results, $failures);
check('has aria-current="page" (default active tab)', str_contains($html, 'aria-current="page"'), $results, $failures);
preg_match_all('/<a\s[^>]*>/', $html, $matches);
check('emits exactly 4 anchor tags', count($matches[0]) === 4, $results, $failures);

// Cleanup
Account::where('name', 'like', '[TABS-TEST]%')->delete();

echo "\n═══════════════════════════════════════════\n";
echo "  Phase 4 STEP 3 results: {$results['pass']} PASS, {$results['fail']} FAIL\n";
echo "═══════════════════════════════════════════\n";
if ($results['fail'] > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($results['fail'] > 0 ? 1 : 0);
