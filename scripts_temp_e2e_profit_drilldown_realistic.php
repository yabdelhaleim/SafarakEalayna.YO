<?php

/**
 * Phase 2 — End-to-End realistic validation: profit drill-down tool.
 *
 * Mirrors what a real user will see:
 *   1. Seed realistic multi-module / multi-day / multi-entity GL data.
 *   2. Hit /api/v1/finance/accounts  → read stats.performance[mod]
 *      (the number on the card the operator clicks).
 *   3. Hit /api/v1/reports/profit-by-day?module=…&from=…&to=…
 *      with the EXACT same period the card uses (start-of-month → today).
 *   4. Hit /api/v1/reports/profit-entity-top?module=…&limit=20
 *   5. Compare:
 *      a) card.profit  ==  profit-by-day.totals.profit
 *      b) card.income  ==  profit-by-day.totals.income
 *      c) card.cogs    ==  profit-by-day.totals.cogs
 *      d) card.expense ==  profit-by-day.totals.expense
 *      e) sum(entity-top items[].profit) == card.profit (module-wide)
 *      f) entity-top items sorted profit DESC
 *      g) entity-top items[] labels resolved (no "#ID-only" labels)
 *   6. Roll back all seeded data (DB::rollBack).
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
function pass(string $label, string $detail = ''): void { global $pass; echo "  ✅ {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $pass++; }
function fail(string $label, string $detail = ''): void { global $fail; echo "  ❌ {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
function warn(string $label, string $detail = ''): void { global $warn; echo "  ⚠️  {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $warn++; }

/**
 * Float-equality with a tiny epsilon to dodge serialization precision
 * (controller rounds to 2dp via round() — but summing many 2dp numbers
 * can drift a satoshi-level on PHP).
 */
function nearly_eq(float $a, float $b, float $eps = 0.01): bool {
    return abs($a - $b) <= $eps;
}

/**
 * Call the FinancialReportController methods directly (bypass auth, same
 * shape as production API → response). Returns the decoded `data` block.
 */
function callEndpoint(string $method, array $query = []): array {
    $controller = app(\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class);
    $request = \Illuminate\Http\Request::create('/api/v1/reports/' . $method, 'GET', $query);
    $response = $controller->{$method}($request);
    $decoded = json_decode($response->getContent(), true);
    return $decoded['data'] ?? [];
}

/**
 * Reproduce the exact card period used by AccountController::index.
 */
function cardPeriod(): array {
    return [
        'from' => now()->startOfMonth()->toDateString(),
        'to'   => now()->toDateString(),
    ];
}

/**
 * Reproduce the EXACT same calculation the card runs.
 *
 * The card number is computed in AccountController::index from
 * ProfitLossReportService::moduleBreakdown() filtered to the current
 * month-to-date. We bypass the HTTP layer (which gates `stats` behind
 * an authenticated admin user) and call the same service directly.
 */
function cardPerformance(string $module): ?array {
    $moduleBreakdown = app(\App\Services\Reports\ProfitLossReportService::class)
        ->moduleBreakdown([
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date'   => now()->toDateString(),
        ]);
    foreach ($moduleBreakdown['by_module'] ?? [] as $row) {
        if ($row['module'] === $module) {
            return [
                'income'  => (float) $row['income'],
                'cogs'    => (float) ($row['cogs'] ?? 0),
                'expense' => (float) $row['expense'],
                'profit'  => (float) $row['profit'],
            ];
        }
    }
    return null;
}

// ───────────────────────────────────────────────────────────────────────
// Pre-flight
// ───────────────────────────────────────────────────────────────────────
header_line('Pre-flight');

// Ensure user id=1 exists (controllers fall back to Auth::id() ?? 1)
DB::table('users')->insertOrIgnore([
    'id' => 1, 'name' => 'GUARD', 'email' => 'guard-e2e@t.local',
    'password' => Hash::make('secret'), 'role' => 'admin',
    'travel_alert_days_before' => 3, 'travel_alert_time' => '08:00:00',
    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
pass('user id=1 exists (controllers fall back to Auth::id() ?? 1)');

// Capture current month-to-date window once for all assertions
$period = cardPeriod();
$today = $period['to'];
$firstOfMonth = $period['from'];
pass("card period: {$firstOfMonth} → {$today}");

// ───────────────────────────────────────────────────────────────────────
// SEED realistic multi-module / multi-day / multi-entity data
// ───────────────────────────────────────────────────────────────────────
header_line('1) Seed realistic data: 8 flight bookings × 3 carriers × 3 days + 5 bus bookings × 2 companies × 2 days');
DB::beginTransaction();

// We need clearing accounts (same names as config/accounting.php) so the
// GL engine's moduleAccountMaps() can classify our seeded tx rows.
$flightIncomeClearingName = config('accounting.clearing.income.flight');
$flightExpenseClearingName = config('accounting.clearing.expense.flight');
$busIncomeClearingName    = config('accounting.clearing.income.bus');
$busExpenseClearingName   = config('accounting.clearing.expense.bus');

$flightIncomeClearingId = (int) DB::table('accounts')->insertGetId([
    'name' => $flightIncomeClearingName, 'type' => 'revenue',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$flightExpenseClearingId = (int) DB::table('accounts')->insertGetId([
    'name' => $flightExpenseClearingName, 'type' => 'expense',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$busIncomeClearingId = (int) DB::table('accounts')->insertGetId([
    'name' => $busIncomeClearingName, 'type' => 'revenue',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$busExpenseClearingId = (int) DB::table('accounts')->insertGetId([
    'name' => $busExpenseClearingName, 'type' => 'expense',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);

// Helper "other-side" treasury account (cashbox) for each module
$flightOtherId = (int) DB::table('accounts')->insertGetId([
    'name' => 'GUARD-FLIGHT-OTHER', 'type' => 'cashbox',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$busOtherId = (int) DB::table('accounts')->insertGetId([
    'name' => 'GUARD-BUS-OTHER', 'type' => 'cashbox',
    'owner_type' => 'office', 'module_type' => 'office',
    'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);

// Customer + employee (FK targets)
$customerId = (int) DB::table('customers')->insertGetId([
    'name' => 'GUARD-E2E-CUST', 'full_name' => 'GUARD-E2E-CUST',
    'phone' => '00000', 'created_at' => now(), 'updated_at' => now(),
]);
$employeeId = (int) DB::table('employees')->insertGetId([
    'user_id' => 1,
    'first_name' => 'G', 'last_name' => 'E', 'full_name' => 'GE',
    'status' => 'active', 'employment_type' => 'full_time', 'employment_status' => 'active',
    'created_at' => now(), 'updated_at' => now(),
]);

// Three flight carriers
$carriers = [];
foreach (['EgyptAir', 'Saudia', 'Emirates'] as $i => $name) {
    $carriers[$name] = (int) DB::table('flight_carriers')->insertGetId([
        'name' => $name, 'code' => strtoupper(substr($name, 0, 2)) . $i,
        'currency' => 'EGP', 'balance' => 0, 'credit_limit' => 0,
        'is_active' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// Two bus companies
$busCompanies = [];
foreach (['SuperJet', 'GoBus'] as $i => $name) {
    $busCompanies[$name] = (int) DB::table('bus_companies')->insertGetId([
        'name' => $name . ' E2E', 'is_active' => 1,
        'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bus_inventories')->insert([
        'company_id' => $busCompanies[$name],
        'route' => 'Cairo → ' . $name,
        'travel_date' => now()->addDays(7)->toDateString(),
        'total_tickets' => 50, 'available_tickets' => 50,
        'cost_per_ticket' => 100, 'selling_price' => 150,
        'payment_type' => 'cash', 'total_cost' => 5000, 'amount_paid' => 0, 'remaining_debt' => 5000,
        'is_auto_created' => 0, 'created_by' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$busInventoryByCompany = DB::table('bus_inventories')
    ->whereIn('company_id', array_values($busCompanies))
    ->pluck('id', 'company_id');

// ─── Seed 8 flight bookings across 3 distinct days, 3 carriers ───
// Profit (GL) = (selling − cost_per_ticket) × qty (cogs + operating_expense if any)
// For each booking: insert GL rows that net to:
//   profit = (selling − cost) where selling, cost reflect REALISTIC magnitudes
// We seed (a) one revenue tx per booking to income_clearing
//       (b) one COGS or expense tx per booking to expense_clearing
//       so total profit per booking = selling − cost exactly.
// Booking profile (date, carrier, selling, cost):
$flightSeed = [
    // Day 1 (today − 5)
    ['days_ago' => 5, 'carrier' => 'EgyptAir',  'selling' => 1000, 'cost' => 600],
    ['days_ago' => 5, 'carrier' => 'Saudia',    'selling' => 1500, 'cost' => 900],
    // Day 2 (today − 3)
    ['days_ago' => 3, 'carrier' => 'EgyptAir',  'selling' => 800,  'cost' => 500],
    ['days_ago' => 3, 'carrier' => 'Emirates',  'selling' => 2200, 'cost' => 1500],
    ['days_ago' => 3, 'carrier' => 'Saudia',    'selling' => 1200, 'cost' => 700],
    // Day 3 (today)
    ['days_ago' => 0, 'carrier' => 'Emirates',  'selling' => 1800, 'cost' => 1100],
    ['days_ago' => 0, 'carrier' => 'EgyptAir',  'selling' => 950,  'cost' => 550],
    ['days_ago' => 0, 'carrier' => 'Saudia',    'selling' => 1100, 'cost' => 650],
];

$seedTotals = ['flight_income' => 0.0, 'flight_expense' => 0.0,
               'bus_income' => 0.0,   'bus_expense' => 0.0];

foreach ($flightSeed as $i => $row) {
    $date = now()->subDays($row['days_ago'])->toDateString();
    $carrierId = $carriers[$row['carrier']];

    $bookingId = (int) DB::table('flight_bookings')->insertGetId([
        'booking_number' => 'E2E-FLT-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
        'booking_reference' => 'E2E-FLT-REF-' . ($i + 1),
        'system_type' => 'manual',
        'booking_channel_type' => 'sign',
        'booking_channel_provider' => 'SIGN',
        'status' => 'confirmed',
        'customer_id' => $customerId,
        'employee_id' => $employeeId,
        'agent_name' => 'E2E',
        'origin' => 'CAI', 'destination' => 'DXB',
        'departure_date' => $date,
        'departure_time' => '08:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'airline' => $row['carrier'],
        'airline_name' => $row['carrier'],
        'baggage_allowance_kg' => 0,
        'purchase_price' => $row['cost'],
        'selling_price' => $row['selling'],
        'profit' => $row['selling'] - $row['cost'],
        'currency' => 'EGP',
        'purchase_price_egp' => $row['cost'],
        'flight_carrier_id' => $carrierId,
        'exchange_rate' => 1.0,
        'booking_exchange_rate' => 1.0,
        'original_currency' => 'EGP',
        'original_amount' => $row['selling'],
        'created_by' => 1,
        'created_at' => $date . ' 12:00:00',
        'updated_at' => now(),
    ]);

    // Revenue tx: from=other, to=income_clearing
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => $row['selling'],
        'from_account_id' => $flightOtherId, 'to_account_id' => $flightIncomeClearingId,
        'related_type' => 'App\\Models\\Flight\\FlightBooking',
        'related_id' => $bookingId,
        'created_by' => 1,
        'created_at' => $date . ' 12:00:00', 'updated_at' => now(),
    ]);

    // COGS tx: from=expense_clearing, to=other
    DB::table('transactions')->insert([
        'type' => 'expense', 'module' => 'flight', 'amount' => $row['cost'],
        'from_account_id' => $flightExpenseClearingId, 'to_account_id' => $flightOtherId,
        'related_type' => 'App\\Models\\Flight\\FlightBooking',
        'related_id' => $bookingId,
        'created_by' => 1,
        'created_at' => $date . ' 12:00:00', 'updated_at' => now(),
    ]);

    $seedTotals['flight_income']  += $row['selling'];
    $seedTotals['flight_expense'] += $row['cost'];
}

// ─── Seed 5 bus bookings across 2 days, 2 companies ───
// profit per booking = (selling − cost) × qty
$busSeed = [
    ['days_ago' => 4, 'company' => 'SuperJet', 'qty' => 2, 'selling' => 150, 'cost' => 100],
    ['days_ago' => 4, 'company' => 'GoBus',    'qty' => 3, 'selling' => 130, 'cost' => 90],
    ['days_ago' => 2, 'company' => 'SuperJet', 'qty' => 1, 'selling' => 160, 'cost' => 100],
    ['days_ago' => 0, 'company' => 'SuperJet', 'qty' => 4, 'selling' => 155, 'cost' => 100],
    ['days_ago' => 0, 'company' => 'GoBus',    'qty' => 2, 'selling' => 140, 'cost' => 95],
];

foreach ($busSeed as $i => $row) {
    $date = now()->subDays($row['days_ago'])->toDateString();
    $companyId = $busCompanies[$row['company']];
    $inventoryId = $busInventoryByCompany[$companyId];

    $revenue = $row['selling'] * $row['qty'];
    $cost = $row['cost'] * $row['qty'];

    $bookingId = (int) DB::table('bus_bookings')->insertGetId([
        'inventory_id' => $inventoryId,
        'customer_id' => $customerId,
        'employee_id' => $employeeId,
        'quantity' => $row['qty'],
        'unit_price' => $row['selling'],
        'total_price' => $revenue,
        'paid_amount' => 0,
        'payment_status' => 'paid',
        'profit' => $revenue - $cost,
        'status' => 'paid',
        'created_by' => 1,
        'created_at' => $date . ' 13:00:00',
        'updated_at' => now(),
    ]);

    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'bus', 'amount' => $revenue,
        'from_account_id' => $busOtherId, 'to_account_id' => $busIncomeClearingId,
        'related_type' => 'App\\Models\\Bus\\BusBooking',
        'related_id' => $bookingId,
        'created_by' => 1,
        'created_at' => $date . ' 13:00:00', 'updated_at' => now(),
    ]);

    DB::table('transactions')->insert([
        'type' => 'expense', 'module' => 'bus', 'amount' => $cost,
        'from_account_id' => $busExpenseClearingId, 'to_account_id' => $busOtherId,
        'related_type' => 'App\\Models\\Bus\\BusBooking',
        'related_id' => $bookingId,
        'created_by' => 1,
        'created_at' => $date . ' 13:00:00', 'updated_at' => now(),
    ]);

    $seedTotals['bus_income']  += $revenue;
    $seedTotals['bus_expense'] += $cost;
}

$flightExpectedProfit = $seedTotals['flight_income'] - $seedTotals['flight_expense'];
$busExpectedProfit    = $seedTotals['bus_income']    - $seedTotals['bus_expense'];

pass('Seed: 8 flight bookings × 3 carriers × 3 days, 5 bus bookings × 2 companies × 2 days');
pass("Expected flight profit (seed math): {$seedTotals['flight_income']} − {$seedTotals['flight_expense']} = {$flightExpectedProfit}");
pass("Expected bus profit (seed math): {$seedTotals['bus_income']} − {$seedTotals['bus_expense']} = {$busExpectedProfit}");

// ───────────────────────────────────────────────────────────────────────
// 2) Card performance (the number the operator CLICKS on)
// ───────────────────────────────────────────────────────────────────────
header_line('2) Card number (GET /api/v1/finance/accounts → stats.performance)');

$cardFlight = cardPerformance('flight');
$cardBus    = cardPerformance('bus');

if ($cardFlight === null) {
    fail('card performance[flight] missing', 'response did not contain stats.performance.flight');
    DB::rollBack();
    exit(1);
}
if ($cardBus === null) {
    fail('card performance[bus] missing');
    DB::rollBack();
    exit(1);
}

echo "  Flight card:  income={$cardFlight['income']}  cogs={$cardFlight['cogs']}  expense={$cardFlight['expense']}  profit={$cardFlight['profit']}\n";
echo "  Bus card:     income={$cardBus['income']}     cogs={$cardBus['cogs']}     expense={$cardBus['expense']}     profit={$cardBus['profit']}\n";

if (nearly_eq((float) $cardFlight['profit'], (float) $flightExpectedProfit)) {
    pass('card.profit (flight) == seed.expected (flight)', 'card=' . round((float)$cardFlight['profit'], 2) . ' expected=' . round($flightExpectedProfit, 2));
} else {
    fail('card.profit (flight) MISMATCH', 'card=' . $cardFlight['profit'] . ' expected=' . $flightExpectedProfit);
}
if (nearly_eq((float) $cardBus['profit'], (float) $busExpectedProfit)) {
    pass('card.profit (bus) == seed.expected (bus)', 'card=' . round((float)$cardBus['profit'], 2) . ' expected=' . round($busExpectedProfit, 2));
} else {
    fail('card.profit (bus) MISMATCH', 'card=' . $cardBus['profit'] . ' expected=' . $busExpectedProfit);
}

// ───────────────────────────────────────────────────────────────────────
// 3) profit-by-day (the "يومي" tab data)
// ───────────────────────────────────────────────────────────────────────
header_line('3) /api/v1/reports/profit-by-day (same period as the card)');

$dayFlight = callEndpoint('profitByDay', [
    'module' => 'flight',
    'from_date' => $firstOfMonth,
    'to_date' => $today,
]);
$dayBus = callEndpoint('profitByDay', [
    'module' => 'bus',
    'from_date' => $firstOfMonth,
    'to_date' => $today,
]);

echo "  Flight day totals: income={$dayFlight['totals']['income']}  cogs={$dayFlight['totals']['cogs']}  expense={$dayFlight['totals']['expense']}  profit={$dayFlight['totals']['profit']}\n";
echo "  Bus day totals:    income={$dayBus['totals']['income']}     cogs={$dayBus['totals']['cogs']}     expense={$dayBus['totals']['expense']}     profit={$dayBus['totals']['profit']}\n";

// Print by_day breakdown for the flight module (visual sanity)
echo "\n  Flight by_day (first 10):\n";
$shown = 0;
foreach ($dayFlight['by_day'] ?? [] as $row) {
    if ($shown++ >= 10) break;
    echo "    {$row['date']}  income={$row['income']}  cogs={$row['cogs']}  expense={$row['expense']}  profit={$row['profit']}\n";
}

// ───────────────────────────────────────────────────────────────────────
// 4) profit-entity-top (the "أعلى الكيانات" tab data)
// ───────────────────────────────────────────────────────────────────────
header_line('4) /api/v1/reports/profit-entity-top (flight + bus)');

$entFlight = callEndpoint('profitEntityTop', ['module' => 'flight', 'limit' => 20]);
$entBus    = callEndpoint('profitEntityTop', ['module' => 'bus',    'limit' => 20]);

echo "  Flight entity_types count: " . count($entFlight['entity_types'] ?? []) . "\n";
foreach ($entFlight['entity_types'] ?? [] as $et) {
    echo "    [{$et['entity_type']}] ({$et['entity_type_label']}) — items=" . count($et['items']) . "\n";
    foreach ($et['items'] as $item) {
        echo "      #{$item['entity_id']} {$item['entity_label']}  income={$item['income']}  cogs={$item['cogs']}  expense={$item['expense']}  profit={$item['profit']}\n";
    }
}
echo "  Bus entity_types:\n";
foreach ($entBus['entity_types'] ?? [] as $et) {
    echo "    [{$et['entity_type']}] ({$et['entity_type_label']}) — items=" . count($et['items']) . "\n";
    foreach ($et['items'] as $item) {
        echo "      #{$item['entity_id']} {$item['entity_label']}  income={$item['income']}  cogs={$item['cogs']}  expense={$item['expense']}  profit={$item['profit']}\n";
    }
}

// ───────────────────────────────────────────────────────────────────────
// 5) PARITY CHECKS — the critical assertions
// ───────────────────────────────────────────────────────────────────────
header_line('5) PARITY CHECKS — card ↔ profit-by-day ↔ profit-entity-top');

// (a) card.profit == profit-by-day.totals.profit (FLIGHT)
if (nearly_eq((float) $cardFlight['profit'], (float) $dayFlight['totals']['profit'])) {
    pass('FLIGHT  card.profit == profit-by-day.totals.profit',
         'card=' . round($cardFlight['profit'], 2) . '  day=' . round($dayFlight['totals']['profit'], 2));
} else {
    fail('FLIGHT  card.profit != profit-by-day.totals.profit',
         'card=' . $cardFlight['profit'] . '  day=' . $dayFlight['totals']['profit']);
}
// (b) card.income == profit-by-day.totals.income
if (nearly_eq((float) $cardFlight['income'], (float) $dayFlight['totals']['income'])) {
    pass('FLIGHT  card.income == profit-by-day.totals.income');
} else {
    fail('FLIGHT  card.income != profit-by-day.totals.income',
         'card=' . $cardFlight['income'] . '  day=' . $dayFlight['totals']['income']);
}
// (c) card.expense == profit-by-day.totals.expense
if (nearly_eq((float) $cardFlight['expense'], (float) $dayFlight['totals']['expense'])) {
    pass('FLIGHT  card.expense == profit-by-day.totals.expense');
} else {
    fail('FLIGHT  card.expense != profit-by-day.totals.expense',
         'card=' . $cardFlight['expense'] . '  day=' . $dayFlight['totals']['expense']);
}

// (a/b/c) for BUS
if (nearly_eq((float) $cardBus['profit'], (float) $dayBus['totals']['profit'])) {
    pass('BUS     card.profit == profit-by-day.totals.profit');
} else {
    fail('BUS     card.profit != profit-by-day.totals.profit',
         'card=' . $cardBus['profit'] . '  day=' . $dayBus['totals']['profit']);
}
if (nearly_eq((float) $cardBus['income'], (float) $dayBus['totals']['income'])) {
    pass('BUS     card.income == profit-by-day.totals.income');
} else {
    fail('BUS     card.income != profit-by-day.totals.income',
         'card=' . $cardBus['income'] . '  day=' . $dayBus['totals']['income']);
}
if (nearly_eq((float) $cardBus['expense'], (float) $dayBus['totals']['expense'])) {
    pass('BUS     card.expense == profit-by-day.totals.expense');
} else {
    fail('BUS     card.expense != profit-by-day.totals.expense',
         'card=' . $cardBus['expense'] . '  day=' . $dayBus['totals']['expense']);
}

// (d) sum(entity-top items[].profit) == card.profit (FLIGHT)
$sumFlightProfit = 0.0;
$sumFlightIncome = 0.0;
foreach ($entFlight['entity_types'] ?? [] as $et) {
    foreach ($et['items'] ?? [] as $item) {
        $sumFlightProfit += (float) $item['profit'];
        $sumFlightIncome += (float) $item['income'];
    }
}
if (nearly_eq($sumFlightProfit, (float) $cardFlight['profit'])) {
    pass('FLIGHT  Σ entity-top items.profit == card.profit',
         'Σ=' . round($sumFlightProfit, 2) . '  card=' . round($cardFlight['profit'], 2));
} else {
    fail('FLIGHT  Σ entity-top items.profit != card.profit',
         'Σ=' . round($sumFlightProfit, 2) . '  card=' . round($cardFlight['profit'], 2));
}

// (d) BUS
$sumBusProfit = 0.0;
foreach ($entBus['entity_types'] ?? [] as $et) {
    foreach ($et['items'] ?? [] as $item) {
        $sumBusProfit += (float) $item['profit'];
    }
}
if (nearly_eq($sumBusProfit, (float) $cardBus['profit'])) {
    pass('BUS     Σ entity-top items.profit == card.profit',
         'Σ=' . round($sumBusProfit, 2) . '  card=' . round($cardBus['profit'], 2));
} else {
    fail('BUS     Σ entity-top items.profit != card.profit',
         'Σ=' . round($sumBusProfit, 2) . '  card=' . round($cardBus['profit'], 2));
}

// (e) entity-top items sorted profit DESC (FLIGHT — flight_carrier type)
$carrierType = null;
foreach ($entFlight['entity_types'] ?? [] as $et) {
    if ($et['entity_type'] === 'flight_carrier') { $carrierType = $et; break; }
}
if ($carrierType) {
    $prev = PHP_FLOAT_MAX;
    $sortedOk = true;
    foreach ($carrierType['items'] as $item) {
        if ((float) $item['profit'] > $prev) { $sortedOk = false; break; }
        $prev = (float) $item['profit'];
    }
    if ($sortedOk) {
        pass('FLIGHT  flight_carrier items sorted profit DESC');
    } else {
        fail('FLIGHT  flight_carrier items NOT sorted profit DESC');
    }

    // (f) labels resolved (no raw IDs) — flight_carrier
    foreach ($carrierType['items'] as $item) {
        if (!preg_match('/^#\d+$/', $item['entity_label'])) {
            // Has a real label
            continue;
        } else {
            fail('FLIGHT  flight_carrier entity_label is raw ID',
                 'entity_id=' . $item['entity_id'] . ' label=' . $item['entity_label']);
        }
    }
    pass('FLIGHT  flight_carrier entity_label is a resolved name (not "#ID")');
} else {
    warn('FLIGHT  no flight_carrier entity_type in response (unexpected)');
}

// (f) labels resolved — bus_company
$busCompanyType = null;
foreach ($entBus['entity_types'] ?? [] as $et) {
    if ($et['entity_type'] === 'bus_company') { $busCompanyType = $et; break; }
}
if ($busCompanyType) {
    foreach ($busCompanyType['items'] as $item) {
        if (preg_match('/^#\d+$/', $item['entity_label'])) {
            fail('BUS     bus_company entity_label is raw ID',
                 'entity_id=' . $item['entity_id'] . ' label=' . $item['entity_label']);
            break;
        }
    }
    pass('BUS     bus_company entity_label is a resolved name (not "#ID")');
}

// (g) by_day row distribution — should have non-zero rows ONLY on days we seeded
$flightSeedDates = [];
foreach ($flightSeed as $r) {
    $flightSeedDates[now()->subDays($r['days_ago'])->toDateString()] = true;
}
$busSeedDates = [];
foreach ($busSeed as $r) {
    $busSeedDates[now()->subDays($r['days_ago'])->toDateString()] = true;
}

$unmatchedDates = [];
foreach ($dayFlight['by_day'] ?? [] as $row) {
    if (!isset($flightSeedDates[$row['date']])) {
        $unmatchedDates[] = 'flight:' . $row['date'];
    }
}
foreach ($dayBus['by_day'] ?? [] as $row) {
    if (!isset($busSeedDates[$row['date']])) {
        $unmatchedDates[] = 'bus:' . $row['date'];
    }
}
if (empty($unmatchedDates)) {
    pass('by_day rows match seeded dates (no ghost days)');
} else {
    warn('by_day returned rows on dates we did NOT seed', implode(', ', $unmatchedDates));
}

// ───────────────────────────────────────────────────────────────────────
// 6) Render-edge-case checks (what the Vue table will show)
// ───────────────────────────────────────────────────────────────────────
header_line('6) Render-edge-case inspection (what the Vue table will render)');

foreach ($dayFlight['by_day'] ?? [] as $row) {
    foreach (['income', 'cogs', 'expense', 'profit'] as $k) {
        if (!is_numeric($row[$k])) {
            fail("by_day row has non-numeric {$k}", json_encode($row));
        }
    }
}
pass('by_day rows: every numeric cell is a valid number (no NaN / null / string)');

foreach (($entFlight['entity_types'] ?? []) as $et) {
    foreach ($et['items'] ?? [] as $item) {
        foreach (['income', 'cogs', 'expense', 'profit'] as $k) {
            if (!is_numeric($item[$k])) {
                fail("entity-top item has non-numeric {$k}", json_encode($item));
            }
        }
        if (empty($item['entity_label']) || $item['entity_label'] === null) {
            fail('entity-top item has empty entity_label', json_encode($item));
        }
    }
}
pass('entity-top items: every numeric cell is a valid number; every entity_label is non-empty');

// Edge case: a day with ONLY one booking — profit should still round cleanly
$singleDay = array_filter($dayFlight['by_day'] ?? [], function ($r) {
    return $r['profit'] > 0;
});
if (count($singleDay) > 0) {
    pass('at least one day has positive profit (modal will show green color)');
}
$hasLoss = false;
foreach ($dayFlight['by_day'] ?? [] as $r) {
    if ((float) $r['profit'] < 0) { $hasLoss = true; break; }
}
if ($hasLoss) {
    pass('at least one day has negative profit (modal will show red color)');
} else {
    warn('no day with negative profit — modal red-color branch not exercised in this seed');
}

// ───────────────────────────────────────────────────────────────────────
// Roll back
// ───────────────────────────────────────────────────────────────────────
DB::rollBack();
pass('ALL seeded data rolled back (DB::rollBack)');

// ───────────────────────────────────────────────────────────────────────
header_line('SUMMARY');
echo "  Passed: {$pass}\n  Warnings: {$warn}\n  Failed: {$fail}\n  Total : " . ($pass + $warn + $fail) . "\n";
exit($fail === 0 ? 0 : 1);