<?php

/**
 * QA — Section 2 of the brief: are the branch migrations additive AND genuinely
 * reversible? Runs down() then up() again for every branch migration on a
 * throw-away SQLite database and reports the schema state before / after.
 *
 * Usage:  DB_CONNECTION=sqlite DB_DATABASE=<abs path> php tests/Audit/migration_reversibility.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$files = [
    '2026_11_02_141000_add_cases_and_variance_to_refund_requests_table',
    '2026_11_02_140000_create_customer_credit_ledger_table',
    '2026_11_02_130000_enhance_refund_requests_and_add_credit_disbursements',
    '2026_11_02_120000_create_flight_payment_allocations_table',
    '2026_11_01_200000_fix_customer_unique_indexes',
];

function schemaState(): array
{
    $state = [];
    foreach (['customer_credit_ledger', 'customer_credit_disbursements', 'flight_payment_allocations'] as $t) {
        $state['table:'.$t] = Schema::hasTable($t) ? 'YES' : 'NO';
    }
    foreach ([
        'refund_requests' => ['refund_case', 'airline_penalty', 'agency_commission', 'apply_commission',
            'non_refundable_taxes_on_customer', 'non_refundable_taxes_amount', 'company_id',
            'credit_ledger_entry_id', 'actual_airline_refund_amount', 'refund_variance_amount'],
        'customers' => ['company_id'],
    ] as $t => $cols) {
        foreach ($cols as $c) {
            $state['col:'.$t.'.'.$c] = Schema::hasColumn($t, $c) ? 'YES' : 'NO';
        }
    }
    $state['index:customers.active_phone'] = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='customers' AND name LIKE '%active_phone%'") ? 'YES' : 'NO';
    $state['index:customers.active_national_id'] = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='customers' AND name LIKE '%active_national%'") ? 'YES' : 'NO';

    return $state;
}

$migrator = app('migrator');
$before = schemaState();
echo "=== SCHEMA BEFORE (all branch migrations applied) ===\n";
foreach ($before as $k => $v) {
    echo "  {$k} = {$v}\n";
}

$ok = true;
foreach ($files as $f) {
    $path = database_path('migrations/'.$f.'.php');
    echo "\n=== {$f} ===\n";
    try {
        $migration = require $path;
        $migration->down();
        $afterDown = schemaState();
        echo "  down() OK\n";
    } catch (\Throwable $e) {
        $ok = false;
        echo '  down() FAILED: '.$e->getMessage()."\n";

        continue;
    }
    try {
        $migration = require $path;
        $migration->up();
        echo "  up() OK\n";
    } catch (\Throwable $e) {
        $ok = false;
        echo '  up() FAILED: '.$e->getMessage()."\n";

        continue;
    }
    $afterUp = schemaState();
    $diff = [];
    foreach ($afterDown as $k => $v) {
        if (($afterUp[$k] ?? null) !== $before[$k]) {
            $diff[] = "{$k}: down={$v} up={$afterUp[$k]} expected={$before[$k]}";
        }
    }
    foreach ($before as $k => $v) {
        if (! array_key_exists($k, $afterDown)) {
            $diff[] = "{$k}: missing after down()";
        }
    }
    if ($diff === []) {
        echo "  SCHEMA RESTORED IDENTICALLY ✓\n";
    } else {
        $ok = false;
        echo '  SCHEMA DIFFERS ✗ : '.implode(' | ', $diff)."\n";
    }
}

echo "\n=== FINAL SCHEMA vs BEFORE ===\n";
$final = schemaState();
$diff = [];
foreach ($before as $k => $v) {
    if (($final[$k] ?? null) !== $v) {
        $fv = $final[$k] ?? 'MISSING';
        $diff[] = "{$k}: expected={$v} final={$fv}";
    }
}
echo $diff === [] ? "  IDENTICAL ✓\n" : ('  DIFFERS ✗ : '.implode(' | ', $diff)."\n");

echo "\nREVERSIBLE=".($ok && $diff === [] ? 'YES' : 'NO')."\n";
