<?php

/**
 * Fix STG staging data:
 *  - Set module_type='tourism' for cashbox/bank/wallet (flights = tourism division)
 *  - Increase carrier balance to 20000 EGP (enough for all tests)
 *  - Drop any orphan or improperly seeded data
 */
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (config('app.env') !== 'staging') {
    exit("❌ REFUSED: must run on staging\n");
}

echo "═══ Fix STG accounts: module_type → 'tourism' or 'office' ═══\n";
$updated = DB::table('accounts')
    ->where('name', 'like', 'STG %')
    ->whereIn('id', function ($q) {
        $q->select('id')->from('accounts')->where('name', 'like', 'STG %');
    })
    ->update([
        'updated_at' => now(),
    ]);

// Manual case-by-case
$cases = [
    'STG Cashbox Tourism' => 'tourism',
    'STG Cashbox Office' => 'office',
    'STG Bank Egypt' => 'tourism',
    'STG Wallet Vodafone' => 'tourism',
];

foreach ($cases as $name => $module) {
    $count = DB::table('accounts')
        ->where('name', $name)
        ->update([
            'module_type' => $module,
            'module' => $module,
            'is_active' => 1,
            'updated_at' => now(),
        ]);
    echo "  $name → module_type='$module' (rows=$count)\n";
}

echo "\n═══ Fix STG carriers: balance → 50000 EGP, credit_limit → 100000 ═══\n";
$count = DB::table('flight_carriers')
    ->where('name', 'like', 'STG %')
    ->update([
        'balance' => 50000,
        'credit_limit' => 100000,
        'updated_at' => now(),
    ]);
echo "  Updated $count carriers (balance=50000, credit_limit=100000)\n";

$count = DB::table('flight_systems')
    ->where('name', 'like', 'STG Test System%')
    ->update([
        'balance' => 50000,
        'credit_limit' => 100000,
        'updated_at' => now(),
    ]);
echo "  Updated $count systems (balance=50000, credit_limit=100000)\n";

echo "\n═══ Verify STG data ═══\n";
echo "Accounts:\n";
foreach (DB::table('accounts')->where('name', 'like', 'STG %')->get() as $a) {
    echo "  id=$a->id name=\"$a->name\" type=$a->type module_type=".($a->module_type ?? 'NULL')." balance=$a->balance\n";
}
echo "\nCarriers:\n";
foreach (DB::table('flight_carriers')->where('name', 'like', 'STG %')->get() as $c) {
    echo "  id=$c->id name=\"$c->name\" balance=$c->balance credit_limit=$c->credit_limit\n";
}
echo "\nSystems:\n";
foreach (DB::table('flight_systems')->where('name', 'like', 'STG Test System%')->get() as $s) {
    echo "  id=$s->id name=\"$s->name\" balance=$s->balance credit_limit=$s->credit_limit\n";
}

echo "\n✅ Fix complete\n";
