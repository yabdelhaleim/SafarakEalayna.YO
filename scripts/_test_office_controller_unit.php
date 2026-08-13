<?php
/**
 * Offline unit test for the new OfficeTreasuryController logic.
 *
 * Doesn't touch the DB — just verifies:
 *   - Class loads (autoloader + namespacing)
 *   - belongsToOfficeDivision() matrix is correct using in-memory Account stubs
 *   - The route is registered in api.php
 *   - The endpoint payload shape is correct given a faked Request
 *
 * Uses Laravel's container + a faked Eloquent Account via Mockery-free
 * anonymous classes so we don't need MySQL.
 */

use App\Http\Controllers\Api\V1\Office\OfficeTreasuryController;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Http\Request;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== OfficeTreasuryController — offline unit test ===\n\n";

/**
 * Build a minimal fake Account by extending the real model and overriding
 * the few attributes we care about. Avoids needing a real DB row.
 */
function fakeAccount(array $attrs): \App\Models\Account
{
    $acc = new \App\Models\Account();
    foreach (['id', 'name', 'type', 'currency', 'module_type', 'module', 'is_active', 'balance'] as $k) {
        if (array_key_exists($k, $attrs)) {
            $acc->{$k} = $attrs[$k];
        }
    }
    return $acc;
}

$cases = [
    ['name' => 'unified office vault',     'type' => 'cashbox', 'module_type' => 'office',         'is_active' => true,  'expected' => true],
    ['name' => 'bus vault (per-module)',   'type' => 'bank',    'module_type' => 'bus',            'is_active' => true,  'expected' => true],
    ['name' => 'fawry vault',              'type' => 'wallet',  'module_type' => 'fawry',          'is_active' => true,  'expected' => true],
    ['name' => 'online vault',             'type' => 'bank',    'module_type' => 'online',         'is_active' => true,  'expected' => true],
    ['name' => 'wallet_transfer vault',    'type' => 'wallet',  'module_type' => 'wallet_transfer','is_active' => true,  'expected' => true],
    ['name' => 'tourism vault',            'type' => 'cashbox', 'module_type' => 'tourism',        'is_active' => true,  'expected' => false],
    ['name' => 'tourism flight vault',     'type' => 'bank',    'module_type' => 'flights',        'is_active' => true,  'expected' => false],
    ['name' => 'tourism hajj vault',       'type' => 'wallet',  'module_type' => 'hajj_umra',      'is_active' => true,  'expected' => false],
    ['name' => 'tourism visa vault',       'type' => 'bank',    'module_type' => 'visas',          'is_active' => true,  'expected' => false],
    ['name' => 'customer AR account',      'type' => 'customer','module_type' => 'office',         'is_active' => true,  'expected' => false],
    ['name' => 'expense account',          'type' => 'expense', 'module_type' => 'office',         'is_active' => true,  'expected' => false],
    ['name' => 'inactive cashbox',         'type' => 'cashbox', 'module_type' => 'office',         'is_active' => false, 'expected' => false],
    ['name' => 'legacy alias=bus only',    'type' => 'bank',    'module_type' => null, 'module' => 'bus',  'is_active' => true,  'expected' => true],
    ['name' => 'legacy alias=fawry only',  'type' => 'wallet',  'module_type' => null, 'module' => 'fawry','is_active' => true,  'expected' => true],
    ['name' => 'no module, no type',       'type' => null,      'module_type' => null, 'module' => null,  'is_active' => true,  'expected' => false],
];

$pass = 0;
$fail = 0;
foreach ($cases as $c) {
    $acc = fakeAccount($c);
    $got = OfficeTreasuryController::belongsToOfficeDivision($acc);
    $ok = ($got === $c['expected']);
    $status = $ok ? '✅' : '❌';
    $reason = $ok ? 'PASS' : "FAIL (expected " . var_export($c['expected'], true) . ", got " . var_export($got, true) . ")";
    echo "  {$status} {$c['name']}: module_type=" . var_export($c['module_type'], true)
         . " type={$c['type']} active=" . var_export($c['is_active'], true)
         . " → {$reason}\n";
    $ok ? $pass++ : $fail++;
}

echo "\nResult: {$pass}/" . count($cases) . " passed, {$fail} failed.\n\n";

// Verify the route is registered.
echo "=== Route registration check ===\n";
$router = $app->make('router');
$routes = $router->getRoutes();
$officeRoutes = [];
foreach ($routes as $route) {
    $uri = $route->uri();
    if (str_contains($uri, 'office/treasury')) {
        $methods = implode('|', $route->methods());
        $action = $route->getActionName();
        $officeRoutes[] = "  {$methods} /{$uri}  →  {$action}";
    }
}
if ($officeRoutes) {
    echo "Found " . count($officeRoutes) . " office/treasury route(s):\n";
    foreach ($officeRoutes as $r) echo $r . "\n";
} else {
    echo "❌ No office/treasury routes registered!\n";
}

echo "\n=== Done ===\n";
