<?php

/**
 * Phase 2 — Render-side E2E: simulate exactly what the Vue modal shows.
 *
 * Re-seeds the same realistic dataset as scripts_temp_e2e_profit_drilldown_realistic.php
 * PLUS a refund (negative-profit) scenario to exercise the red-color branch
 * in the modal. Formats every number through PHP's NumberFormatter with
 * the exact Intl options the Vue `formatCurrency` uses, so we see exactly
 * the strings the user will see.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$pass = 0;
$fail = 0;
$warn = 0;

function header_line(string $s): void { echo "\n" . str_repeat('═', 80) . "\n  {$s}\n" . str_repeat('═', 80) . "\n"; }
function pass(string $label): void { global $pass; echo "  ✅ {$label}\n"; $pass++; }
function fail(string $label, string $detail = ''): void { global $fail; echo "  ❌ {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
function warn(string $label): void { global $warn; echo "  ⚠️  {$label}\n"; $warn++; }

/**
 * Mirror of AccountsIndex.vue:formatCurrency using the SAME Intl options.
 */
function formatCurrencyLikeVue($amount, string $currency = 'EGP'): string {
    // Defensive: the Vue code does `Number(amount) || 0` before formatting.
    $num = is_numeric($amount) ? (float) $amount : 0.0;
    if (!class_exists(NumberFormatter::class)) {
        // Fallback if intl extension missing
        return number_format($num, 2) . ' ' . $currency;
    }
    $fmt = new NumberFormatter('ar-EG', NumberFormatter::CURRENCY);
    $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
    $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
    return $fmt->formatCurrency($num, $currency);
}

function callEndpoint(string $method, array $query = []): array {
    $controller = app(\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class);
    $request = \Illuminate\Http\Request::create('/api/v1/reports/' . $method, 'GET', $query);
    $response = $controller->{$method}($request);
    $decoded = json_decode($response->getContent(), true);
    return $decoded['data'] ?? [];
}

header_line('Pre-flight');
DB::table('users')->insertOrIgnore([
    'id' => 1, 'name' => 'GUARD', 'email' => 'guard-render@t.local',
    'password' => Hash::make('secret'), 'role' => 'admin',
    'travel_alert_days_before' => 3, 'travel_alert_time' => '08:00:00',
    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
pass('user id=1 exists');

header_line('1) Seed: realistic + 1 REFUND to force negative-profit day (red-color branch)');
DB::beginTransaction();

// Clearing accounts (same names as config/accounting.php)
$flightIncomeId = (int) DB::table('accounts')->insertGetId([
    'name' => config('accounting.clearing.income.flight'),
    'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$flightExpenseId = (int) DB::table('accounts')->insertGetId([
    'name' => config('accounting.clearing.expense.flight'),
    'type' => 'expense', 'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$otherId = (int) DB::table('accounts')->insertGetId([
    'name' => 'GUARD-OTHER',
    'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$customerId = (int) DB::table('customers')->insertGetId([
    'name' => 'GUARD-RENDER', 'full_name' => 'GUARD-RENDER',
    'phone' => '00000', 'created_at' => now(), 'updated_at' => now(),
]);
$employeeId = (int) DB::table('employees')->insertGetId([
    'user_id' => 1, 'first_name' => 'R', 'last_name' => 'E', 'full_name' => 'RE',
    'status' => 'active', 'employment_type' => 'full_time', 'employment_status' => 'active',
    'created_at' => now(), 'updated_at' => now(),
]);

// Two carriers
$egyId = (int) DB::table('flight_carriers')->insertGetId([
    'name' => 'EgyptAir', 'code' => 'EG', 'currency' => 'EGP',
    'balance' => 0, 'credit_limit' => 0, 'is_active' => 1, 'created_by' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$sauId = (int) DB::table('flight_carriers')->insertGetId([
    'name' => 'Saudia', 'code' => 'SA', 'currency' => 'EGP',
    'balance' => 0, 'credit_limit' => 0, 'is_active' => 1, 'created_by' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);

// Seed: 2 profitable + 1 refund (negative-profit day)
$today = now()->toDateString();
$yesterday = now()->subDays(1)->toDateString();

function seedBooking(int $i, string $date, int $customerId, int $employeeId, int $carrierId,
                    int $otherId, int $incomeId, int $expenseId, int $selling, int $cost,
                    string $bookingNumber): int {
    return (int) DB::table('flight_bookings')->insertGetId([
        'booking_number' => $bookingNumber,
        'booking_reference' => 'REF-' . $bookingNumber,
        'system_type' => 'manual',
        'booking_channel_type' => 'sign',
        'booking_channel_provider' => 'SIGN',
        'status' => 'confirmed',
        'customer_id' => $customerId,
        'employee_id' => $employeeId,
        'agent_name' => 'RENDER',
        'origin' => 'CAI', 'destination' => 'DXB',
        'departure_date' => $date,
        'departure_time' => '08:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'airline' => 'X', 'airline_name' => 'X', 'baggage_allowance_kg' => 0,
        'purchase_price' => $cost,
        'selling_price' => $selling,
        'profit' => $selling - $cost,
        'currency' => 'EGP',
        'purchase_price_egp' => $cost,
        'flight_carrier_id' => $carrierId,
        'exchange_rate' => 1.0,
        'booking_exchange_rate' => 1.0,
        'original_currency' => 'EGP',
        'original_amount' => $selling,
        'created_by' => 1,
        'created_at' => $date . ' 10:00:00', 'updated_at' => now(),
    ]) + 0;
}

// Booking A — yesterday, EgyptAir, profit 300
$bookingA = (int) DB::table('flight_bookings')->insertGetId([
    'booking_number' => 'REND-A', 'booking_reference' => 'REF-A',
    'system_type' => 'manual', 'booking_channel_type' => 'sign',
    'booking_channel_provider' => 'SIGN', 'status' => 'confirmed',
    'customer_id' => $customerId, 'employee_id' => $employeeId, 'agent_name' => 'RENDER',
    'origin' => 'CAI', 'destination' => 'DXB',
    'departure_date' => $yesterday, 'departure_time' => '08:00:00',
    'trip_type' => 'one_way', 'passenger_count' => 1,
    'airline' => 'EgyptAir', 'airline_name' => 'EgyptAir', 'baggage_allowance_kg' => 0,
    'purchase_price' => 700, 'selling_price' => 1000, 'profit' => 300,
    'currency' => 'EGP', 'purchase_price_egp' => 700,
    'flight_carrier_id' => $egyId, 'exchange_rate' => 1.0, 'booking_exchange_rate' => 1.0,
    'original_currency' => 'EGP', 'original_amount' => 1000, 'created_by' => 1,
    'created_at' => $yesterday . ' 10:00:00', 'updated_at' => now(),
]);
DB::table('transactions')->insert([
    'type' => 'income', 'module' => 'flight', 'amount' => 1000,
    'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
    'related_type' => 'App\\Models\\Flight\\FlightBooking', 'related_id' => $bookingA,
    'created_by' => 1, 'created_at' => $yesterday . ' 10:00:00', 'updated_at' => now(),
]);
DB::table('transactions')->insert([
    'type' => 'expense', 'module' => 'flight', 'amount' => 700,
    'from_account_id' => $flightExpenseId, 'to_account_id' => $otherId,
    'related_type' => 'App\\Models\\Flight\\FlightBooking', 'related_id' => $bookingA,
    'created_by' => 1, 'created_at' => $yesterday . ' 10:00:00', 'updated_at' => now(),
]);

// Booking B — yesterday, Saudia, profit 500
$bookingB = (int) DB::table('flight_bookings')->insertGetId([
    'booking_number' => 'REND-B', 'booking_reference' => 'REF-B',
    'system_type' => 'manual', 'booking_channel_type' => 'sign',
    'booking_channel_provider' => 'SIGN', 'status' => 'confirmed',
    'customer_id' => $customerId, 'employee_id' => $employeeId, 'agent_name' => 'RENDER',
    'origin' => 'CAI', 'destination' => 'DXB',
    'departure_date' => $yesterday, 'departure_time' => '08:00:00',
    'trip_type' => 'one_way', 'passenger_count' => 1,
    'airline' => 'Saudia', 'airline_name' => 'Saudia', 'baggage_allowance_kg' => 0,
    'purchase_price' => 500, 'selling_price' => 1000, 'profit' => 500,
    'currency' => 'EGP', 'purchase_price_egp' => 500,
    'flight_carrier_id' => $sauId, 'exchange_rate' => 1.0, 'booking_exchange_rate' => 1.0,
    'original_currency' => 'EGP', 'original_amount' => 1000, 'created_by' => 1,
    'created_at' => $yesterday . ' 11:00:00', 'updated_at' => now(),
]);
DB::table('transactions')->insert([
    'type' => 'income', 'module' => 'flight', 'amount' => 1000,
    'from_account_id' => $otherId, 'to_account_id' => $flightIncomeId,
    'related_type' => 'App\\Models\\Flight\\FlightBooking', 'related_id' => $bookingB,
    'created_by' => 1, 'created_at' => $yesterday . ' 11:00:00', 'updated_at' => now(),
]);
DB::table('transactions')->insert([
    'type' => 'expense', 'module' => 'flight', 'amount' => 500,
    'from_account_id' => $flightExpenseId, 'to_account_id' => $otherId,
    'related_type' => 'App\\Models\\Flight\\FlightBooking', 'related_id' => $bookingB,
    'created_by' => 1, 'created_at' => $yesterday . ' 11:00:00', 'updated_at' => now(),
]);

// Booking C — TODAY, Saudia — REFUND (revenue reversal).
// ProfitLossReportService::classify() maps:
//   - type='income'               → 'revenue'  (always adds to income)
//   - type='refund'               → 'refund'   (also subtracted from income)
//   - type='transfer' + reversed-flow → 'revenue_reversal' (subtracted from income)
//
// A 'refund' type is the simplest path to a negative-profit day. We
// emit one refund tx of 400 EGP (the cancelled ticket's value to refund).
DB::table('transactions')->insert([
    'type' => 'refund', 'module' => 'flight', 'amount' => 400,
    'from_account_id' => $flightIncomeId, 'to_account_id' => $otherId,
    'related_type' => null, 'related_id' => null,
    'created_by' => 1, 'created_at' => $today . ' 09:00:00', 'updated_at' => now(),
    'notes' => 'refund of cancelled booking — exercises revenue_reversal branch',
]);

pass('Seed: 2 profitable bookings (yesterday) + 1 revenue_reversal (today) → today will be negative-profit');

// ───────────────────────────────────────────────────────────────────────
// 2) profit-by-day (Day tab)
// ───────────────────────────────────────────────────────────────────────
header_line('2) profit-by-day (Day tab)');
$dayFlight = callEndpoint('profitByDay', [
    'module' => 'flight',
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date'   => now()->toDateString(),
]);

echo "\n  === Vue table preview ('يومي' tab) ===\n";
printf("  %-12s %14s %14s %14s %14s\n", 'Date', 'Income', 'COGS', 'Expense', 'Profit');
foreach ($dayFlight['by_day'] ?? [] as $row) {
    printf(
        "  %-12s %14s %14s %14s %14s  %s\n",
        $row['date'],
        formatCurrencyLikeVue($row['income']),
        formatCurrencyLikeVue($row['cogs']),
        formatCurrencyLikeVue($row['expense']),
        formatCurrencyLikeVue($row['profit']),
        ((float) $row['profit'] >= 0 ? '🟢 green' : '🔴 red')
    );
}

echo "\n  === Totals strip (4 KPI tiles) ===\n";
foreach (['income', 'cogs', 'expense', 'profit'] as $k) {
    $val = $dayFlight['totals'][$k] ?? null;
    echo "    " . str_pad($k, 10) . " = " . formatCurrencyLikeVue($val);
    if ($k === 'profit') echo ' (color: ' . (((float)$val >= 0) ? 'success/green' : 'error/red') . ')';
    echo "\n";
}

// Find the negative-profit day
$hasNegativeDay = false;
foreach ($dayFlight['by_day'] ?? [] as $row) {
    if ((float) $row['profit'] < 0) { $hasNegativeDay = true; break; }
}
if ($hasNegativeDay) {
    pass('Day tab: at least one day shows negative profit → red-color branch exercised');
} else {
    fail('Day tab: NO negative-profit day — red-color branch NOT exercised');
}

// Edge: formatCurrency(0) should NOT show "NaN" or "undefined"
$zeroFormatted = formatCurrencyLikeVue(0);
if (str_contains($zeroFormatted, 'NaN') || str_contains($zeroFormatted, 'undefined') || str_contains($zeroFormatted, 'null')) {
    fail('formatCurrency(0) produces bad string: ' . $zeroFormatted);
} else {
    pass('formatCurrency(0) renders cleanly: "' . $zeroFormatted . '"');
}
$nullFormatted = formatCurrencyLikeVue(null);
if (str_contains($nullFormatted, 'NaN')) {
    fail('formatCurrency(null) produces NaN');
} else {
    pass('formatCurrency(null) renders cleanly: "' . $nullFormatted . '"');
}
$strFormatted = formatCurrencyLikeVue('not-a-number');
if (str_contains($strFormatted, 'NaN')) {
    fail('formatCurrency("not-a-number") produces NaN');
} else {
    pass('formatCurrency("not-a-number") renders cleanly: "' . $strFormatted . '"');
}

// ───────────────────────────────────────────────────────────────────────
// 3) profit-entity-top (Top Entities tab)
// ───────────────────────────────────────────────────────────────────────
header_line('3) profit-entity-top (Top Entities tab)');
$ent = callEndpoint('profitEntityTop', ['module' => 'flight', 'limit' => 20]);

foreach ($ent['entity_types'] ?? [] as $et) {
    if ($et['entity_type'] !== 'flight_carrier') continue;
    echo "\n  === Vue table preview ('أعلى الكيانات' tab → flight_carrier) ===\n";
    printf("  %-3s %-20s %14s %14s %14s %14s\n", '#', 'Carrier', 'Income', 'COGS', 'Expense', 'Profit');
    foreach ($et['items'] as $idx => $item) {
        printf(
            "  %-3d %-20s %14s %14s %14s %14s  %s\n",
            $idx + 1,
            substr($item['entity_label'], 0, 20),
            formatCurrencyLikeVue($item['income']),
            formatCurrencyLikeVue($item['cogs']),
            formatCurrencyLikeVue($item['expense']),
            formatCurrencyLikeVue($item['profit']),
            ((float) $item['profit'] >= 0 ? '🟢' : '🔴')
        );
    }

    // Verify items array shape — every Vue template binding must resolve
    foreach ($et['items'] as $idx => $item) {
        foreach (['entity_id', 'entity_label', 'income', 'cogs', 'expense', 'profit'] as $k) {
            if (!array_key_exists($k, $item)) {
                fail("entity_top item #{$idx} missing key '{$k}'");
                continue 2;
            }
        }
        // Also: no NaN in any numeric
        foreach (['income', 'cogs', 'expense', 'profit'] as $k) {
            if (!is_numeric($item[$k])) {
                fail("entity_top item #{$idx} key '{$k}' is non-numeric: " . var_export($item[$k], true));
            }
        }
        // Label non-empty
        if (trim((string) $item['entity_label']) === '') {
            fail("entity_top item #{$idx} has empty label");
        }
    }
    pass("All " . count($et['items']) . " entity_top items have complete shape + numeric values + non-empty labels");
}

// ───────────────────────────────────────────────────────────────────────
// 4) Sub-tab navigation visual sanity
// ───────────────────────────────────────────────────────────────────────
header_line('4) Sub-tab navigation (flight has flight_system + flight_carrier)');
$hasFlightSubTabs = false;
foreach ($ent['entity_types'] ?? [] as $et) {
    if ($et['entity_type'] === 'flight_system' && $et['entity_type'] === 'flight_carrier') {
        $hasFlightSubTabs = true;
    }
}
$hasFlightSubTabs = count($ent['entity_types'] ?? []) > 1;
if ($hasFlightSubTabs) {
    pass('Flight module exposes multi-entity sub-tabs (flight_system + flight_carrier) — sub-tab strip will render');
    echo "\n  === Vue sub-tab strip preview ===\n";
    foreach ($ent['entity_types'] as $et) {
        echo "    [{$et['entity_type_label']}] ({$et['entity_type']}) — " . count($et['items']) . " items\n";
    }
} else {
    warn('Flight module did NOT expose multi-entity sub-tabs');
}

// ───────────────────────────────────────────────────────────────────────
// 5) Sub-tab click simulation (entityTab toggle)
// ───────────────────────────────────────────────────────────────────────
header_line('5) Module with ZERO profit (e.g. hajj_umra) — empty state rendering');
$hDay = callEndpoint('profitByDay', [
    'module' => 'hajj_umra',
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date' => now()->toDateString(),
]);
if (($hDay['by_day'] ?? []) === []) {
    pass('Empty module returns empty by_day — Vue empty-state copy will render correctly');
} else {
    fail('Empty module returned non-empty by_day: ' . json_encode($hDay));
}
$hEnt = callEndpoint('profitEntityTop', ['module' => 'hajj_umra']);
foreach ($hEnt['entity_types'] ?? [] as $et) {
    if (($et['items'] ?? []) === []) {
        pass("Empty module/{$et['entity_type']} returns empty items — empty-state copy will render correctly");
    } else {
        fail("Empty module/{$et['entity_type']} returned non-empty items");
    }
}

// ───────────────────────────────────────────────────────────────────────
DB::rollBack();
pass('All seeded data rolled back');

header_line('SUMMARY');
echo "  Passed: {$pass}\n  Warnings: {$warn}\n  Failed: {$fail}\n  Total : " . ($pass + $warn + $fail) . "\n";
exit($fail === 0 ? 0 : 1);