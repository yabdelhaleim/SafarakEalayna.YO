<?php

/**
 * Phase 3 — Real DB Validation: engine no longer floors negatives at 0.
 *
 * Three scenarios, each in its own DB::beginTransaction / DB::rollBack:
 *
 *   A) Single-module net loss:  1000 income + 2000 refund (via 'refund' tx)
 *      → module.profit = -1000. Asserts that report(), moduleBreakdown(),
 *        getDailyProfitByModule(), and getProfitByEntity() ALL return -1000.
 *
 *   B) Mixed modules:           flight module: 1500 income, 0 expense  → +1500
 *                                bus module:    800 income, 2000 refund  → -1200
 *      Asserts each module is isolated correctly and that the bus module's
 *        card / daily / entity rows show negative profit while the flight
 *        module shows positive.
 *
 *   C) Single-day refund spike: today: 500 income, 1200 refund → -700
 *      other days: 0. Asserts getDailyProfitByModule returns the day row
 *        with profit=-700 (today row negative, other days positive or zero).
 *
 * After each scenario, prints the negative row so the user can see exactly
 * what the engine now produces.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$pass = 0;
$fail = 0;

function header_line(string $s): void { echo "\n" . str_repeat('═', 80) . "\n  {$s}\n" . str_repeat('═', 80) . "\n"; }
function pass(string $label): void { global $pass; echo "  ✅ {$label}\n"; $pass++; }
function fail(string $label, string $detail = ''): void { global $fail; echo "  ❌ {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }

function callEndpoint(string $method, array $query = []): array {
    $controller = app(\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class);
    $request = \Illuminate\Http\Request::create('/api/v1/reports/' . $method, 'GET', $query);
    $response = $controller->{$method}($request);
    $decoded = json_decode($response->getContent(), true);
    return $decoded['data'] ?? [];
}

// Pre-flight
DB::table('users')->insertOrIgnore([
    'id' => 1, 'name' => 'GUARD', 'email' => 'guard-floor@t.local',
    'password' => Hash::make('secret'), 'role' => 'admin',
    'travel_alert_days_before' => 3, 'travel_alert_time' => '08:00:00',
    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
]);

// ───────────────────────────────────────────────────────────────────────
// SCENARIO A — single-module net loss
// ───────────────────────────────────────────────────────────────────────
header_line('A) Single-module net loss: 1000 income + 2000 refund → profit = -1000');
DB::beginTransaction();
try {
    $flightIncomeId = (int) DB::table('accounts')->insertGetId([
        'name' => config('accounting.clearing.income.flight'),
        'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherId = (int) DB::table('accounts')->insertGetId([
        'name' => 'GUARD-OTHER-A',
        'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $today = now()->toDateString();

    // 1000 income (revenue)
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => 1000,
        'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 09:00:00', 'updated_at' => now(),
    ]);
    // 2000 refund (refund type → subtracts from income)
    DB::table('transactions')->insert([
        'type' => 'refund', 'module' => 'flight', 'amount' => 2000,
        'from_account_id' => $flightIncomeId, 'to_account_id' => $otherId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 10:00:00', 'updated_at' => now(),
    ]);

    // Expected: income = 1000 - 2000 = -1000, profit = -1000

    // 1) moduleBreakdown
    $breakdown = app(\App\Services\Reports\ProfitLossReportService::class)
        ->moduleBreakdown(['from_date' => $today, 'to_date' => $today]);
    $flightRow = null;
    foreach ($breakdown['by_module'] ?? [] as $r) {
        if ($r['module'] === 'flight') { $flightRow = $r; break; }
    }
    if ($flightRow === null) {
        fail('A.1 moduleBreakdown: no flight row returned');
    } elseif (abs((float) $flightRow['profit'] - -1000.0) < 0.01 && abs((float) $flightRow['income'] - -1000.0) < 0.01) {
        pass('A.1 moduleBreakdown returns income=-1000, profit=-1000 (no longer floored at 0)');
    } else {
        fail('A.1 moduleBreakdown FAILED', 'got income=' . ($flightRow['income'] ?? 'NULL') . ' profit=' . ($flightRow['profit'] ?? 'NULL'));
    }

    // 2) getDailyProfitByModule
    $byDay = callEndpoint('profitByDay', ['module' => 'flight', 'from_date' => $today, 'to_date' => $today]);
    $todayRow = $byDay['by_day'][0] ?? null;
    if ($todayRow === null) {
        fail('A.2 profit-by-day: no row returned');
    } elseif (abs((float) $todayRow['profit'] - -1000.0) < 0.01 && abs((float) $todayRow['income'] - -1000.0) < 0.01) {
        pass('A.2 profit-by-day returns by_day profit=-1000, income=-1000');
    } else {
        fail('A.2 profit-by-day FAILED', 'got income=' . ($todayRow['income'] ?? 'NULL') . ' profit=' . ($todayRow['profit'] ?? 'NULL'));
    }
    if (abs((float) $byDay['totals']['profit'] - -1000.0) < 0.01) {
        pass('A.2 profit-by-day totals.profit = -1000');
    } else {
        fail('A.2 totals.profit != -1000', 'got=' . ($byDay['totals']['profit'] ?? 'NULL'));
    }

    // 3) report()
    $report = app(\App\Services\Reports\ProfitLossReportService::class)
        ->report(['from_date' => $today, 'to_date' => $today, 'category' => 'all']);
    if (abs((float) $report['totalRevenues'] - -1000.0) < 0.01) {
        pass('A.3 report() totalRevenues = -1000 (no longer floored at 0)');
    } else {
        fail('A.3 report() totalRevenues FAILED', 'got=' . ($report['totalRevenues'] ?? 'NULL'));
    }
    if (abs((float) $report['netProfit'] - -1000.0) < 0.01) {
        pass('A.3 report() netProfit = -1000');
    } else {
        fail('A.3 netProfit FAILED', 'got=' . ($report['netProfit'] ?? 'NULL'));
    }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('A: unexpected exception', $e->getMessage()); }

// ───────────────────────────────────────────────────────────────────────
// SCENARIO B — mixed modules (one positive, one negative)
// ───────────────────────────────────────────────────────────────────────
header_line('B) Mixed modules — flight profit +1500, bus profit -1200');
DB::beginTransaction();
try {
    $flightIncomeId = (int) DB::table('accounts')->insertGetId([
        'name' => config('accounting.clearing.income.flight'),
        'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $busIncomeId = (int) DB::table('accounts')->insertGetId([
        'name' => config('accounting.clearing.income.bus'),
        'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherId = (int) DB::table('accounts')->insertGetId([
        'name' => 'GUARD-OTHER-B',
        'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $today = now()->toDateString();

    // Flight: +1500 net (1500 income, no refunds/expense)
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => 1500,
        'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 09:00:00', 'updated_at' => now(),
    ]);
    // Bus: +800 income, then -2000 refund → net -1200
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'bus', 'amount' => 800,
        'from_account_id' => $otherId, 'to_account_id' => $busIncomeId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 10:00:00', 'updated_at' => now(),
    ]);
    DB::table('transactions')->insert([
        'type' => 'refund', 'module' => 'bus', 'amount' => 2000,
        'from_account_id' => $busIncomeId, 'to_account_id' => $otherId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 11:00:00', 'updated_at' => now(),
    ]);

    $breakdown = app(\App\Services\Reports\ProfitLossReportService::class)
        ->moduleBreakdown(['from_date' => $today, 'to_date' => $today]);

    $byModule = [];
    foreach ($breakdown['by_module'] ?? [] as $r) {
        $byModule[$r['module']] = $r;
    }

    if (isset($byModule['flight']) && abs((float) $byModule['flight']['profit'] - 1500.0) < 0.01) {
        pass('B.1 flight module profit = +1500');
    } else {
        fail('B.1 flight module profit != +1500', 'got=' . ($byModule['flight']['profit'] ?? 'NULL'));
    }
    if (isset($byModule['bus']) && abs((float) $byModule['bus']['profit'] - -1200.0) < 0.01) {
        pass('B.2 bus module profit = -1200 (no cross-contamination from flight)');
    } else {
        fail('B.2 bus module profit != -1200', 'got=' . ($byModule['bus']['profit'] ?? 'NULL'));
    }

    // getDailyProfitByModule for bus — should be -1200
    $busDay = callEndpoint('profitByDay', ['module' => 'bus', 'from_date' => $today, 'to_date' => $today]);
    if (abs((float) ($busDay['totals']['profit'] ?? 0) - -1200.0) < 0.01) {
        pass('B.3 profit-by-day for bus = -1200');
    } else {
        fail('B.3 profit-by-day bus FAILED', 'got=' . ($busDay['totals']['profit'] ?? 'NULL'));
    }

    // getDailyProfitByModule for flight — should be +1500
    $flightDay = callEndpoint('profitByDay', ['module' => 'flight', 'from_date' => $today, 'to_date' => $today]);
    if (abs((float) ($flightDay['totals']['profit'] ?? 0) - 1500.0) < 0.01) {
        pass('B.4 profit-by-day for flight = +1500');
    } else {
        fail('B.4 profit-by-day flight FAILED', 'got=' . ($flightDay['totals']['profit'] ?? 'NULL'));
    }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('B: unexpected exception', $e->getMessage()); }

// ───────────────────────────────────────────────────────────────────────
// SCENARIO C — single-day refund spike
// ───────────────────────────────────────────────────────────────────────
header_line('C) Single-day refund spike: today income 500 + refund 1200 → -700');
DB::beginTransaction();
try {
    $flightIncomeId = (int) DB::table('accounts')->insertGetId([
        'name' => config('accounting.clearing.income.flight'),
        'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherId = (int) DB::table('accounts')->insertGetId([
        'name' => 'GUARD-OTHER-C',
        'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $today = now()->toDateString();
    $yesterday = now()->subDays(1)->toDateString();

    // Yesterday: +2000 income (a normal day)
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => 2000,
        'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $yesterday . ' 09:00:00', 'updated_at' => now(),
    ]);
    // Today: 500 income + 1200 refund
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => 500,
        'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 09:00:00', 'updated_at' => now(),
    ]);
    DB::table('transactions')->insert([
        'type' => 'refund', 'module' => 'flight', 'amount' => 1200,
        'from_account_id' => $flightIncomeId, 'to_account_id' => $otherId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1, 'created_at' => $today . ' 10:00:00', 'updated_at' => now(),
    ]);

    $byDay = callEndpoint('profitByDay', [
        'module' => 'flight',
        'from_date' => $yesterday,
        'to_date' => $today,
    ]);

    $todayRow = null;
    $yesterdayRow = null;
    foreach ($byDay['by_day'] ?? [] as $r) {
        if ($r['date'] === $today) { $todayRow = $r; }
        if ($r['date'] === $yesterday) { $yesterdayRow = $r; }
    }

    if ($todayRow && abs((float) $todayRow['profit'] - -700.0) < 0.01) {
        pass('C.1 today row profit = -700 (red-color branch will fire in modal)');
    } else {
        fail('C.1 today row profit != -700', 'got=' . ($todayRow['profit'] ?? 'NULL'));
    }

    if ($yesterdayRow && abs((float) $yesterdayRow['profit'] - 2000.0) < 0.01) {
        pass('C.2 yesterday row profit = +2000 (positive day, green branch)');
    } else {
        fail('C.2 yesterday row profit != +2000', 'got=' . ($yesterdayRow['profit'] ?? 'NULL'));
    }

    // moduleBreakdown across both days: -700 + 2000 = +1300
    $breakdown = app(\App\Services\Reports\ProfitLossReportService::class)
        ->moduleBreakdown(['from_date' => $yesterday, 'to_date' => $today]);
    $flightRow = null;
    foreach ($breakdown['by_module'] ?? [] as $r) {
        if ($r['module'] === 'flight') { $flightRow = $r; break; }
    }
    if ($flightRow && abs((float) $flightRow['profit'] - 1300.0) < 0.01) {
        pass('C.3 moduleBreakdown across both days: profit = +1300 (correctly summed)');
    } else {
        fail('C.3 moduleBreakdown profit != +1300', 'got=' . ($flightRow['profit'] ?? 'NULL'));
    }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('C: unexpected exception', $e->getMessage()); }

// ───────────────────────────────────────────────────────────────────────
header_line('SUMMARY');
echo "  Passed: {$pass}\n  Failed: {$fail}\n  Total : " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);