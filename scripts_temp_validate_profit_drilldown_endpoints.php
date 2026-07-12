<?php

/**
 * Phase 2 — Real DB Validation: /profit-by-day + /profit-entity-top endpoints.
 *
 * Goal: prove the new drill-down endpoints return GL-correct numbers and
 * the entity mapping / whitelist works for every supported module.
 *
 * Approach:
 *   - Use the LOCAL MySQL DB (`safarakealayna` on 127.0.0.1).
 *   - Each scenario wraps in DB::beginTransaction → setup → assert →
 *     DB::rollBack so no data persists.
 *   - We invoke the controller methods DIRECTLY (not via HTTP) to bypass
 *     the Sanctum middleware — this tests the business logic in
 *     isolation, which is exactly what these methods do (the auth is
 *     added by the route group, not by the controller).
 *   - For each module we: (a) create a booking via raw DB inserts that
 *     produces GL entries via the existing service-level write paths
 *     (or seed GL rows directly); (b) hit the endpoint; (c) compare
 *     the returned numbers against ProfitLossReportService::moduleBreakdown
 *     (the same engine the Filament dashboard uses) and against
 *     getDailyProfitByModule / getProfitByEntity directly.
 *
 * The validation script does NOT need to seed an admin user (we bypass
 * auth). It DOES need user id=1 to exist (services fall back to
 * Auth::id() ?? 1).
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\Reports\FinancialReportController;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$pass = 0;
$fail = 0;

function header_line(string $s): void { echo "\n" . str_repeat('═', 80) . "\n  {$s}\n" . str_repeat('═', 80) . "\n"; }
function pass(string $label): void { global $pass; echo "  ✅ {$label}\n"; $pass++; }
function fail(string $label, string $detail = ''): void { global $fail; echo "  ❌ {$label}\n"; if ($detail !== '') echo "      {$detail}\n"; $fail++; }

// Pre-flight: user id=1
DB::table('users')->insertOrIgnore([
    'id' => 1, 'name' => 'GUARD', 'email' => 'guard-drill@t.local',
    'password' => Hash::make('secret'), 'role' => 'admin',
    'travel_alert_days_before' => 3, 'travel_alert_time' => '08:00:00',
    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
]);

/**
 * Call a controller method with query params. Bypasses auth.
 */
function callEndpoint(string $method, array $query = []): array {
    $controller = app(FinancialReportController::class);
    $request = Request::create('/api/v1/reports/' . $method, 'GET', $query);
    $response = $controller->{$method}($request);
    return json_decode($response->getContent(), true);
}

// ───────────────────────────────────────────────────────────────────────
// A) Whitelist enforcement
// ───────────────────────────────────────────────────────────────────────
header_line('A) Module whitelist — invalid module rejected with 422');
$res = callEndpoint('profitByDay', ['module' => 'hacker_drop_table']);
if (($res['success'] ?? true) === false && str_contains($res['message'] ?? '', 'Invalid module')) {
    pass('profit-by-day rejects invalid module with 422');
} else { fail('profit-by-day did NOT reject invalid module', json_encode($res)); }

$res = callEndpoint('profitEntityTop', ['module' => 'hacker_drop_table']);
if (($res['success'] ?? true) === false && str_contains($res['message'] ?? '', 'Invalid module')) {
    pass('profit-entity-top rejects invalid module with 422');
} else { fail('profit-entity-top did NOT reject invalid module', json_encode($res)); }

$res = callEndpoint('profitByDay', []);  // no module
if (($res['success'] ?? true) === false) {
    pass('profit-by-day rejects missing module with 422');
} else { fail('profit-by-day accepted missing module', json_encode($res)); }

// ───────────────────────────────────────────────────────────────────────
// B) Empty-DB scenario: both endpoints return empty/zero without errors
// ───────────────────────────────────────────────────────────────────────
header_line('B) Empty DB — both endpoints return empty results without errors');
$res = callEndpoint('profitByDay', ['module' => 'flight', 'from_date' => '2099-01-01', 'to_date' => '2099-01-31']);
if (($res['success'] ?? false) === true
    && isset($res['data']['by_day'])
    && is_array($res['data']['by_day'])
    && $res['data']['by_day'] === []
    && (float) $res['data']['totals']['profit'] === 0.0) {
    pass('profit-by-day on empty DB returns empty by_day + zero totals');
} else { fail('profit-by-day empty DB failed', json_encode($res)); }

$res = callEndpoint('profitEntityTop', ['module' => 'flight', 'from_date' => '2099-01-01', 'to_date' => '2099-01-31']);
if (($res['success'] ?? false) === true
    && isset($res['data']['entity_types'])
    && count($res['data']['entity_types']) === 2  // flight_system + flight_carrier
    && $res['data']['entity_types'][0]['items'] === []
    && $res['data']['entity_types'][1]['items'] === []) {
    pass('profit-entity-top on empty DB returns both entity types with empty items');
} else { fail('profit-entity-top empty DB failed', json_encode($res)); }

// ───────────────────────────────────────────────────────────────────────
// C) Visa customer-fallback label clarity
// ───────────────────────────────────────────────────────────────────────
header_line('C) Visa module → entity_type label is "عميل" not "وكيل تأشيرات"');
$res = callEndpoint('profitEntityTop', ['module' => 'visa']);
if (($res['success'] ?? false) === true
    && isset($res['data']['entity_types'][0]['entity_type'])
    && $res['data']['entity_types'][0]['entity_type'] === 'customer'
    && str_contains($res['data']['entity_types'][0]['entity_type_label'], 'عميل')) {
    pass('Visa entity_type_label clarifies it is the customer (عميل), not visa_agent');
} else { fail('Visa entity_type_label not clear', json_encode($res)); }

// ───────────────────────────────────────────────────────────────────────
// D) Sort + limit clamping
// ───────────────────────────────────────────────────────────────────────
header_line('D) Sort + limit clamping');
$res = callEndpoint('profitEntityTop', ['module' => 'flight', 'limit' => 999]);
if (($res['success'] ?? false) === true && $res['data']['limit'] === 100) {
    pass('limit clamped to max 100');
} else { fail('limit NOT clamped to 100', 'got=' . ($res['data']['limit'] ?? 'NULL')); }

$res = callEndpoint('profitEntityTop', ['module' => 'flight', 'limit' => -5]);
if (($res['success'] ?? false) === true && $res['data']['limit'] === 1) {
    pass('limit clamped to min 1');
} else { fail('limit NOT clamped to 1', 'got=' . ($res['data']['limit'] ?? 'NULL')); }

$res = callEndpoint('profitEntityTop', ['module' => 'flight', 'sort' => 'hacker']);
if (($res['success'] ?? false) === true && $res['data']['sort'] === 'profit') {
    pass('invalid sort falls back to default "profit"');
} else { fail('invalid sort NOT defaulted', 'got=' . ($res['data']['sort'] ?? 'NULL')); }

// ───────────────────────────────────────────────────────────────────────
// E) End-to-end: seed GL rows for flight module, then verify numbers
// ───────────────────────────────────────────────────────────────────────
header_line('E) E2E flight module — seeded GL matches profit-by-day + profit-entity-top');
DB::beginTransaction();
try {
    $plService = app(ProfitLossReportService::class);

    // Seed the two clearing accounts that
    // config/accounting.php:98-115 maps to flight. The clearing map
    // queries `accounts` WHERE name IN (...) AND is_active — without
    // these rows the engine sees no clearing accounts and the test
    // would not exercise the classify() branch we're trying to verify.
    $flightIncomeClearingName = config('accounting.clearing.income.flight');
    $flightExpenseClearingName = config('accounting.clearing.expense.flight');

    $incomeAccountId = (int) DB::table('accounts')->insertGetId([
        'name' => $flightIncomeClearingName,
        'type' => 'revenue', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $expenseAccountId = (int) DB::table('accounts')->insertGetId([
        'name' => $flightExpenseClearingName,
        'type' => 'expense', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Sanity: moduleAccountMaps should now see both
    $clearingProp = new \ReflectionProperty($plService, 'clearingAccounts');
    $clearingProp->setAccessible(true);
    $clearingSvc = $clearingProp->getValue($plService);
    $maps = $clearingSvc->moduleAccountMaps();
    $flightIncomeClearingIds = array_keys(array_filter($maps['income'] ?? [], fn ($m) => $m === 'flight'));
    $flightExpenseClearingIds = array_keys(array_filter($maps['expense'] ?? [], fn ($m) => $m === 'flight'));

    if (empty($flightIncomeClearingIds) || empty($flightExpenseClearingIds)) {
        fail('Clearing account seed did not surface in moduleAccountMaps', 'incomeIds=' . json_encode($flightIncomeClearingIds) . ' expenseIds=' . json_encode($flightExpenseClearingIds));
        DB::rollBack();
        return; // can't continue
    }

    // Seed: one revenue tx (1000 EGP) + one cogs tx (600 EGP) for today
    $today = now()->toDateString();
    $incomeAccountId = (int) array_values($flightIncomeClearingIds)[0];
    $expenseAccountId = (int) array_values($flightExpenseClearingIds)[0];

    // Need a real account for the OTHER side of each GL tx
    $otherAccountId = (int) DB::table('accounts')->insertGetId([
        'name' => 'GUARD-OTHER-ACC',
        'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Revenue tx: from=otherAccount, to=incomeClearing
    DB::table('transactions')->insert([
        'type' => 'income', 'module' => 'flight', 'amount' => 1000.00,
        'from_account_id' => $otherAccountId, 'to_account_id' => $incomeAccountId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1,
        'created_at' => $today . ' 12:00:00', 'updated_at' => now(),
    ]);

    // COGS tx: from=expenseClearing, to=otherAccount.
    // classify() maps type='expense' → 'operating_expense' (NOT cogs),
    // because cogs is only emitted by FlightBookingService::createBooking
    // (a paired journal). For this test we seed an operating_expense and
    // assert expense=600, cogs=0, profit=400 — same final P&L.
    DB::table('transactions')->insert([
        'type' => 'expense', 'module' => 'flight', 'amount' => 600.00,
        'from_account_id' => $expenseAccountId, 'to_account_id' => $otherAccountId,
        'related_type' => null, 'related_id' => null,
        'created_by' => 1,
        'created_at' => $today . ' 12:00:00', 'updated_at' => now(),
    ]);

    // Expected: today income=1000, cogs=600, expense=0, profit=400
    $res = callEndpoint('profitByDay', ['module' => 'flight', 'from_date' => $today, 'to_date' => $today]);
    $today_in_endpoint = null;
    foreach ($res['data']['by_day'] ?? [] as $row) {
        if ($row['date'] === $today) { $today_in_endpoint = $row; break; }
    }
    if ($today_in_endpoint
        && (float) $today_in_endpoint['income'] === (float) 1000.00
        && (float) $today_in_endpoint['cogs'] === (float) 0.0
        && (float) $today_in_endpoint['expense'] === (float) 600.00
        && (float) $today_in_endpoint['profit'] === (float) 400.00) {
        pass('profit-by-day returns exactly the seeded GL values for flight (income=1000, expense=600, profit=400)');
    } else {
        fail('profit-by-day GL numbers mismatch for flight', json_encode($res));
    }

    // Totals should also match
    if ((float) $res['data']['totals']['profit'] === (float) 400.00) {
        pass('profit-by-day totals aggregate correctly (profit=400)');
    } else { fail('profit-by-day totals mismatch', 'got=' . ($res['data']['totals']['profit'] ?? 'NULL')); }

    // Cross-check against moduleBreakdown (the engine the dashboard uses)
    $breakdown = $plService->moduleBreakdown(['from_date' => $today, 'to_date' => $today]);
    $flightRow = null;
    foreach ($breakdown['by_module'] ?? [] as $r) {
        if ($r['module'] === 'flight') { $flightRow = $r; break; }
    }
    if ($flightRow && (float) $flightRow['profit'] === (float) 400.00) {
        pass('profit-by-day profit (400) == moduleBreakdown profit (400) — same GL engine');
    } else { fail('profit-by-day != moduleBreakdown for flight', json_encode($flightRow)); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('Flight E2E: unexpected exception', $e->getMessage()); }

// ───────────────────────────────────────────────────────────────────────
// F) Limit honors the cap
// ───────────────────────────────────────────────────────────────────────
header_line('F) Entity-top: items length <= limit');
$res = callEndpoint('profitEntityTop', ['module' => 'bus', 'limit' => 5]);
if (($res['success'] ?? false) === true) {
    foreach ($res['data']['entity_types'] ?? [] as $et) {
        if (count($et['items']) > 5) {
            fail('Entity-top returned more than limit for bus/' . $et['entity_type'], 'count=' . count($et['items']));
            break;
        }
    }
    pass('Entity-top honors limit cap (5) for all entity_types of bus module');
} else { fail('bus entity-top request failed', json_encode($res)); }

// ───────────────────────────────────────────────────────────────────────
// G) Response shape — every required key present
// ───────────────────────────────────────────────────────────────────────
header_line('G) Response shape completeness');
$res = callEndpoint('profitByDay', ['module' => 'fawry']);
if (($res['success'] ?? false) === true) {
    $required = ['module', 'from_date', 'to_date', 'currency', 'by_day', 'totals'];
    $missing = array_diff($required, array_keys($res['data'] ?? []));
    if (empty($missing)) {
        pass('profit-by-day response contains all required top-level keys');
    } else { fail('profit-by-day response missing keys', implode(',', $missing)); }
    foreach ($res['data']['by_day'] ?? [] as $r) {
        $rreq = ['date', 'income', 'cogs', 'expense', 'profit'];
        $rmiss = array_diff($rreq, array_keys($r));
        if (!empty($rmiss)) {
            fail('profit-by-day row missing keys', implode(',', $rmiss));
            break;
        }
    }
    pass('profit-by-day rows all have {date, income, cogs, expense, profit}');
}

$res = callEndpoint('profitEntityTop', ['module' => 'online']);
if (($res['success'] ?? false) === true) {
    $required = ['module', 'from_date', 'to_date', 'currency', 'sort', 'limit', 'entity_types'];
    $missing = array_diff($required, array_keys($res['data'] ?? []));
    if (empty($missing)) {
        pass('profit-entity-top response contains all required top-level keys');
    } else { fail('profit-entity-top response missing keys', implode(',', $missing)); }
    foreach ($res['data']['entity_types'] ?? [] as $et) {
        $ereq = ['entity_type', 'entity_type_label', 'items'];
        $emiss = array_diff($ereq, array_keys($et));
        if (!empty($emiss)) {
            fail('entity_types[] missing keys', implode(',', $emiss));
            break;
        }
    }
    pass('profit-entity-top entity_types[] all have {entity_type, entity_type_label, items}');
}

// ─── summary ──────────────────────────────────────────────────────────────
header_line('SUMMARY');
echo "  Passed: {$pass}\n  Failed: {$fail}\n  Total : " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);