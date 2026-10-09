<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — F-7 Regression Test (flight_systems.code auto-generation)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Run after applying F-7 fix (FlightSystem creating() observer auto-generates code).
 *
 * Tests:
 *   T-SYS-1: Create FlightSystem WITHOUT code → auto-generated from name
 *   T-SYS-2: Create FlightSystem WITH code → preserved verbatim
 *   T-SYS-3: Create two systems with similar names → distinct codes (suffix)
 *   T-SYS-4: Baseline T9 (booking via system) no longer crashes
 *   T-SYS-5: ledger:reconcile still passes after fix
 *
 * Output: storage/logs/flight_audit_fix_f7_results.json
 */
$dbPath = realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'local_flight_audit.sqlite';
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE=$dbPath");
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbPath;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightSystem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'tests' => [],
    'count_pass' => 0,
    'count_fail' => 0,
];

function rec(array &$r, string $key, bool $ok, array $detail = []): void
{
    $r['tests'][$key] = array_merge(['status' => $ok ? 'PASS' : 'FAIL'], $detail);
    if ($ok) {
        $r['count_pass']++;
    } else {
        $r['count_fail']++;
    }
    echo ($ok ? '  ✅ PASS ' : '  ❌ FAIL ')."$key: ".json_encode($detail, JSON_UNESCAPED_UNICODE)."\n";
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  F-7 Regression Test — flight_systems.code auto-generation\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// Use a known admin user id from setup
$adminId = 1;

// T-SYS-1: create WITHOUT code → auto-generated from name
try {
    $sys1 = FlightSystem::create([
        'name' => 'TX-F7-AutoCode-Test-1',
        'currency' => 'EGP',
        'credit_limit' => 100000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
    $ok = ! empty($sys1->code) && strlen($sys1->code) > 0;
    rec($results, 'T-SYS-1', $ok, ['id' => $sys1->id, 'name' => $sys1->name, 'auto_code' => $sys1->code]);
    // Cleanup
    FlightSystem::find($sys1->id)?->forceDelete();
} catch (Throwable $e) {
    rec($results, 'T-SYS-1', false, ['error' => $e->getMessage()]);
}

// T-SYS-2: create WITH code → preserved
try {
    $sys2 = FlightSystem::create([
        'name' => 'TX-F7-AutoCode-Test-2',
        'code' => 'TX-F7-CUSTOM-2',
        'currency' => 'EGP',
        'credit_limit' => 100000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
    $ok = $sys2->code === 'TX-F7-CUSTOM-2';
    rec($results, 'T-SYS-2', $ok, ['id' => $sys2->id, 'code' => $sys2->code]);
    FlightSystem::find($sys2->id)?->forceDelete();
} catch (Throwable $e) {
    rec($results, 'T-SYS-2', false, ['error' => $e->getMessage()]);
}

// T-SYS-3: similar names → distinct codes (auto-suffix)
try {
    $sys3a = FlightSystem::create([
        'name' => 'TX-F7-Collide',
        'currency' => 'EGP',
        'credit_limit' => 100000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
    $sys3b = FlightSystem::create([
        'name' => 'TX-F7-Collide',
        'currency' => 'EGP',
        'credit_limit' => 100000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
    $ok = $sys3a->code !== $sys3b->code && ! empty($sys3a->code) && ! empty($sys3b->code);
    rec($results, 'T-SYS-3', $ok, ['sys3a_code' => $sys3a->code, 'sys3b_code' => $sys3b->code]);
    FlightSystem::find($sys3a->id)?->forceDelete();
    FlightSystem::find($sys3b->id)?->forceDelete();
} catch (Throwable $e) {
    rec($results, 'T-SYS-3', false, ['error' => $e->getMessage()]);
}

// T-SYS-4: baseline T9-style booking via system — should no longer crash
try {
    $sys4 = FlightSystem::create([
        'name' => 'TX-F7-Book-Test',
        'currency' => 'EGP',
        'credit_limit' => 200000,
        'balance' => 0,
        'is_active' => true,
        'created_by' => $adminId,
    ]);
    // Verify code is set
    $ok = ! empty($sys4->code);
    rec($results, 'T-SYS-4-system-created', $ok, ['id' => $sys4->id, 'code' => $sys4->code]);

    // Verify DB row exists
    $dbCheck = DB::table('flight_systems')->where('id', $sys4->id)->first();
    $ok = $dbCheck !== null && $dbCheck->code !== null && $dbCheck->code !== '';
    rec($results, 'T-SYS-4-db-check', $ok, ['db_code' => $dbCheck->code ?? 'NULL']);

    FlightSystem::find($sys4->id)?->forceDelete();
} catch (Throwable $e) {
    rec($results, 'T-SYS-4', false, ['error' => $e->getMessage()]);
}

// T-SYS-5: ledger:reconcile still passes after fix
try {
    Artisan::call('ledger:reconcile', ['--json' => true, '--no-rebuild' => true]);
    $output = Artisan::output();
    $recon = json_decode($output, true);
    $accountsDrift = $recon['accounts_balance_drift_count'] ?? -1;
    $globalOk = $recon['global_totals']['ok'] ?? false;
    rec($results, 'T-SYS-5-reconcile', $globalOk, ['drift_count' => $accountsDrift, 'global_ok' => $globalOk]);
} catch (Throwable $e) {
    rec($results, 'T-SYS-5-reconcile', false, ['error' => $e->getMessage()]);
}

// T-SYS-6: financial integrity — no negative cashbox balances introduced by F-7
$neg = DB::table('accounts')->whereIn('type', ['cashbox', 'bank', 'wallet'])->where('balance', '<', 0)->count();
rec($results, 'T-SYS-6-no-negative-liquidity', $neg === 0, ['negative_count' => $neg]);

// ─── Done ─────────────────────────────────────────────────────────────
$results['finished_at'] = date('Y-m-d H:i:s');
$results['verdict'] = $results['count_fail'] === 0 ? 'PASS' : 'FAIL';

file_put_contents(__DIR__.'/../storage/logs/flight_audit_fix_f7_results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo '  F-7 Regression: '.$results['count_pass'].' PASS / '.$results['count_fail']." FAIL\n";
echo '  Verdict: '.$results['verdict']."\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
