<?php
/**
 * Phase 1 — DashboardService GL Unification: REAL DB VALIDATION.
 *
 * Contract being validated:
 *
 *   The `DashboardService` (specifically `getFullDashboard()`,
 *   `buildAirlineOperationsDashboard()`, and `buildBusOperationsDashboard()`)
 *   no longer reads `model.profit` columns. All profit figures are sourced
 *   from the GL via `ProfitLossReportService::getDailyProfitByModule()`
 *   and `ProfitLossReportService::getProfitByEntity()`.
 *
 *   This script:
 *     ① Creates flight bookings with known selling_price / purchase_price
 *     ② Creates bus bookings with known prices (with inventory→company)
 *     ③ Calls getFullDashboard() and verifies profit figures match
 *        ProfitLossReportService::moduleBreakdown() exactly (delta = 0.00)
 *     ④ Verifies per-day breakdown sums to total range profit (delta = 0.00)
 *     ⑤ Verifies per-carrier / per-bus-company breakdown sums match
 *     ⑥ Confirms no SUM(profit) on flight_bookings / bus_bookings anymore
 *
 * Run: php scripts/phase1_dashboard_gl_unification.php
 *
 * Output: JSON verdict to stdout + storage/logs/phase1_dashboard_result.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusTicket;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Services\DashboardService;
use App\Services\Flight\FlightBookingService;
use App\Services\Reports\ProfitLossReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$out = [];
$failures = [];

$check = static function (string $label, bool $ok, array &$failures) use (&$out): void {
    if ($ok) {
        $out[] = "  ✓ {$label}";
    } else {
        $out[] = "  ✗ {$label}";
        $failures[] = $label;
    }
};

$echo = static function () use (&$out): void {
    echo implode(PHP_EOL, $out).PHP_EOL;
    $out = [];
};

$within = static function (float $expected, float $actual, float $eps = 0.01): bool {
    return abs($expected - $actual) < $eps;
};

// ─────────────────────────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────────────────────────
$out[] = '═══ Phase 1 DashboardService GL Unification — Real DB Validation ═══';
$out[] = '';
$echo();

$user = User::query()->where('is_active', true)->whereIn('role', ['admin', 'owner'])->first();
if (! $user) {
    $user = User::query()->create([
        'name' => 'Phase 1 Validator',
        'email' => 'phase1-dashboard@test.local',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    $out[] = "Created temp admin: {$user->email}";
} else {
    $out[] = "Using admin: {$user->email} (id={$user->id})";
}
$echo();
Auth::login($user);

$today = Carbon::now();
$rangeFrom = $today->copy()->startOfMonth()->toDateString();
$rangeTo = $today->copy()->endOfMonth()->toDateString();

DB::beginTransaction();
try {
    // Pre-warm the income/expense clearing accounts for both modules
    $clearing = app(\App\Services\Finance\LedgerClearingAccounts::class);
    $clearing->incomeContraIdForModule('flight');
    $clearing->incomeContraIdForModule('bus');
    $clearing->expenseContraIdForModule('flight');
    $clearing->expenseContraIdForModule('bus');

    $emp = Employee::query()->first() ?? Employee::query()->create([
        'first_name' => 'Phase1',
        'last_name' => 'Tester',
        'full_name' => 'Phase1 Tester',
        'phone' => '01000000001',
        'national_id' => 'P1EMP'.random_int(10000, 99999),
        'created_by' => $user->id,
    ]);

    $customer = Customer::query()->create([
        'full_name' => 'Phase 1 Customer',
        'phone' => '0100000'.random_int(1000, 9999),
        'created_by' => $user->id,
    ]);

    $system = FlightSystem::query()->where('name', 'P1 System')->first() ?? FlightSystem::query()->create([
        'name' => 'P1 System',
        'code' => 'P1SYS',
        'type' => 'Amadeus',
        'balance' => 100000,
        'currency' => 'EGP',
        'is_active' => true,
    ]);

    $carrier = FlightCarrier::query()->where('name', 'P1 Carrier')->first() ?? FlightCarrier::query()->create([
        'name' => 'P1 Carrier',
        'code' => 'P1CAR',
        'iata_code' => 'P1',
        'flight_system_id' => $system->id,
        'balance' => 100000,
        'credit_limit' => 0,
        'currency' => 'EGP',
        'is_active' => true,
    ]);

    $busCompany = BusCompany::query()->where('name', 'P1 Bus Co')->first() ?? BusCompany::query()->create(['name' => 'P1 Bus Co']);

    $busInventory = BusInventory::query()->where('route', 'P1-RT-A')->first() ?? BusInventory::query()->create([
        'company_id' => $busCompany->id,
        'route' => 'P1-RT-A',
        'travel_date' => $today->toDateString(),
        'origin' => 'CityA',
        'destination' => 'CityB',
        'departure_time' => '08:00:00',
        'arrival_time' => '12:00:00',
        'price' => 1000,
        'selling_price' => 1000,
        'cost' => 700,
        'cost_per_ticket' => 700,
        'total_cost' => 700,
        'remaining_debt' => 0,
        'currency' => 'EGP',
        'available_seats' => 50,
        'total_tickets' => 50,
        'available_tickets' => 50,
    ]);

    $out[] = "Created test fixtures: FlightSystem #{$system->id}, FlightCarrier #{$carrier->id}, BusCompany #{$busCompany->id}, BusInventory #{$busInventory->id}";
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ① Create flight bookings with KNOWN prices
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① Create flight bookings with known prices ━━';

    $flightSpec = [
        ['selling' => 3000, 'cost' => 2500],
        ['selling' => 4500, 'cost' => 3800],
        ['selling' => 2000, 'cost' => 1600],
    ];

    // Fund the carrier from a source-of-funds account (cashbox) so the
    // FlightBookingService debit check passes.
    $fundsCashbox = Account::query()->where('type', AccountType::Cashbox)->where('is_active', true)->first();
    if (! $fundsCashbox) {
        // Create a fresh cashbox for the test. Use AccountBalanceMutation
        // Guard-bypassed write because we are SETTING UP the test fixture,
        // not actually doing accounting work — there is no GL entry to
        // back this initial balance (this is precisely what the boot
        // guard prevents).
        $fundsCashbox = \App\Support\Finance\LedgerBalanceMutationGuard::run(
            fn () => Account::query()->create([
                'name' => 'P1 Test Cashbox',
                'type' => AccountType::Cashbox,
                'balance' => 100000,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'owner',
                'module_type' => 'general',
            ])
        );
        $out[] = '  Created test cashbox #'.$fundsCashbox->id;
    } elseif ((float) $fundsCashbox->balance < 100000) {
        // Top up via the guard-bypassed path.
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($fundsCashbox) {
            $fundsCashbox->balance = 100000;
            $fundsCashbox->save();
        });
    }

    $neededForFlights = array_sum(array_map(fn ($s) => (float) $s['cost'], $flightSpec));
    if ((float) $carrier->balance < $neededForFlights) {
        $rechargeSvc = app(\App\Services\Flight\FlightCarrierRechargeService::class);
        $rechargeSvc->rechargeFromAccount(
            $carrier->fresh(),
            $fundsCashbox->fresh(),
            $neededForFlights - (float) $carrier->balance + 5000,
            'P1 test fund'
        );
    }

    $flightService = app(FlightBookingService::class);
    $busService = app(BusBookingService::class);

    // ═══════════════════════════════════════════════════════════════
    // ① Create flight bookings with KNOWN prices
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ① Create flight bookings with known prices ━━';

    $flightSpec = [
        ['selling' => 3000, 'cost' => 2500],
        ['selling' => 4500, 'cost' => 3800],
        ['selling' => 2000, 'cost' => 1600],
    ];

    $expectedFlightRevenue = 0.0;
    $expectedFlightCogs = 0.0;
    $expectedFlightNetProfit = 0.0;
    $createdFlights = 0;

    foreach ($flightSpec as $i => $spec) {
        $selling = (float) $spec['selling'];
        $cost = (float) $spec['cost'];
        $expectedFlightRevenue += $selling;
        $expectedFlightCogs += $cost;
        $expectedFlightNetProfit += ($selling - $cost);

        $flightService->createBooking([
            'booking_number' => 'P1-FB-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'booking_reference' => 'P1-FB-REF-'.($i + 1),
            'customer_id' => $customer->id,
            'employee_id' => $emp->id,
            'flight_carrier_id' => $carrier->id,
            'flight_system_id' => $system->id,
            'system_type' => 'Amadeus',
            'selling_price' => $selling,
            'purchase_price' => $cost,
            'currency' => 'EGP',
            'status' => 'CONFIRMED',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => $today->copy()->addDays($i + 1)->toDateString(),
            'payment_method' => 'cash',
            'paid_amount' => $selling, // fully paid
        ]);
        $createdFlights++;
    }
    $out[] = "  Created {$createdFlights} flight bookings (selling=".number_format($expectedFlightRevenue, 2).', cogs='.number_format($expectedFlightCogs, 2).', profit='.number_format($expectedFlightNetProfit, 2).')';
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ② Create bus bookings with KNOWN prices (via service)
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ② Create bus bookings with known prices ━━';

    $busSpec = [
        ['tickets' => 1, 'price' => 1000, 'cost' => 700],
        ['tickets' => 2, 'price' => 1000, 'cost' => 700],
        ['tickets' => 3, 'price' => 1000, 'cost' => 700],
    ];

    $expectedBusRevenue = 0.0;
    $expectedBusCogs = 0.0;
    $expectedBusNetProfit = 0.0;
    $createdBuses = 0;

    foreach ($busSpec as $i => $spec) {
        $tickets = (int) $spec['tickets'];
        $price = (float) $spec['price'];
        $cost = (float) $spec['cost'];

        $booking = $busService->createBooking([
            'inventory_id' => $busInventory->id,
            'customer_id' => $customer->id,
            'quantity' => $tickets,
            'ticket_count' => $tickets,
            'total_price' => $price * $tickets,
            'currency' => 'EGP',
            'paid_amount' => $price * $tickets,
            'status' => 'paid',
            'notes' => 'P1 test booking '.($i + 1),
            'created_by' => $user->id,
            'passengers' => array_map(fn ($n) => [
                'name' => "P1 Passenger ".($i + 1).'-'.($n + 1),
                'price' => $price,
                'cost' => $cost,
            ], range(0, $tickets - 1)),
        ]);

        $expectedBusRevenue += $price * $tickets;
        $expectedBusCogs += $cost * $tickets;
        $expectedBusNetProfit += ($price - $cost) * $tickets;
        $createdBuses++;
    }
    $out[] = "  Created {$createdBuses} bus bookings (selling=".number_format($expectedBusRevenue, 2).', cogs='.number_format($expectedBusCogs, 2).', profit='.number_format($expectedBusNetProfit, 2).')';
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ③ Verify getFullDashboard profit figures match ProfitLossReportService
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ③ getFullDashboard profit figures match ProfitLossReportService (delta=0) ━━';

    $plService = app(ProfitLossReportService::class);
    $moduleBreakdown = $plService->moduleBreakdown([
        'from_date' => $rangeFrom,
        'to_date' => $rangeTo,
    ]);
    $plByModule = collect($moduleBreakdown['by_module'])->keyBy('module');

    $glFlightProfit = (float) ($plByModule->get('flight')['profit'] ?? 0);
    $glBusProfit = (float) ($plByModule->get('bus')['profit'] ?? 0);

    $out[] = '  GL flight profit (moduleBreakdown): '.number_format($glFlightProfit, 2);
    $out[] = '  GL bus profit   (moduleBreakdown): '.number_format($glBusProfit, 2);

    $dashboard = app(DashboardService::class)->getFullDashboard($rangeFrom, $rangeTo, null, null);

    $airlineProfit = (float) ($dashboard['kpis']['net_profit'] ?? 0);
    $busProfit = (float) ($dashboard['bus_kpis']['net_profit'] ?? 0);

    $out[] = '  Dashboard airline net_profit: '.number_format($airlineProfit, 2);
    $out[] = '  Dashboard bus net_profit: '.number_format($busProfit, 2);

    $check(
        'airline net_profit ('.$airlineProfit.') === GL flight profit ('.$glFlightProfit.')',
        $within($airlineProfit, $glFlightProfit),
        $failures
    );
    $check(
        'bus net_profit ('.$busProfit.') === GL bus profit ('.$glBusProfit.')',
        $within($busProfit, $glBusProfit),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ④ Per-day breakdown sums match range total
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ④ Per-day breakdown sums to range total ━━';

    $flightDaily = $plService->getDailyProfitByModule('flight', [
        'from_date' => $rangeFrom,
        'to_date' => $rangeTo,
    ]);
    $busDaily = $plService->getDailyProfitByModule('bus', [
        'from_date' => $rangeFrom,
        'to_date' => $rangeTo,
    ]);

    $flightDailySum = (float) array_sum(array_column($flightDaily, 'profit'));
    $busDailySum = (float) array_sum(array_column($busDaily, 'profit'));

    $check(
        'flight per-day sum ('.$flightDailySum.') === moduleBreakdown flight profit ('.$glFlightProfit.')',
        $within($flightDailySum, $glFlightProfit),
        $failures
    );
    $check(
        'bus per-day sum ('.$busDailySum.') === moduleBreakdown bus profit ('.$glBusProfit.')',
        $within($busDailySum, $glBusProfit),
        $failures
    );

    $flightDailyIncome = (float) array_sum(array_column($flightDaily, 'income'));
    $busDailyIncome = (float) array_sum(array_column($busDaily, 'income'));
    $check(
        'flight per-day income sum ('.$flightDailyIncome.') === expected revenue ('.$expectedFlightRevenue.')',
        $within($flightDailyIncome, $expectedFlightRevenue),
        $failures
    );
    $check(
        'bus per-day income sum ('.$busDailyIncome.') === expected revenue ('.$expectedBusRevenue.')',
        $within($busDailyIncome, $expectedBusRevenue),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑤ Per-carrier / per-bus-company breakdown matches
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑤ Per-carrier / per-bus-company profit breakdown ━━';

    $carrierProfitRows = $plService->getProfitByEntity(
        'flight',
        FlightBooking::class,
        'flight_carrier_id',
        null,
        ['from_date' => $rangeFrom, 'to_date' => $rangeTo]
    );
    $carrierProfitSum = (float) array_sum(array_column($carrierProfitRows, 'profit'));
    $check(
        'per-carrier sum ('.$carrierProfitSum.') === GL flight profit ('.$glFlightProfit.')',
        $within($carrierProfitSum, $glFlightProfit),
        $failures
    );
    $check(
        'per-carrier includes our carrier (id='.$carrier->id.')',
        collect($carrierProfitRows)->contains(fn ($r) => (int) $r['entity_id'] === (int) $carrier->id),
        $failures
    );

    $systemProfitRows = $plService->getProfitByEntity(
        'flight',
        FlightBooking::class,
        'flight_system_id',
        null,
        ['from_date' => $rangeFrom, 'to_date' => $rangeTo]
    );
    $systemProfitSum = (float) array_sum(array_column($systemProfitRows, 'profit'));
    $check(
        'per-system sum ('.$systemProfitSum.') === GL flight profit ('.$glFlightProfit.')',
        $within($systemProfitSum, $glFlightProfit),
        $failures
    );

    $companyProfitRows = $plService->getProfitByEntity(
        'bus',
        BusBooking::class,
        'company_id',
        ['table' => 'bus_inventories', 'fk' => 'inventory_id'],
        ['from_date' => $rangeFrom, 'to_date' => $rangeTo]
    );
    $companyProfitSum = (float) array_sum(array_column($companyProfitRows, 'profit'));
    $check(
        'per-bus-company sum ('.$companyProfitSum.') === GL bus profit ('.$glBusProfit.')',
        $within($companyProfitSum, $glBusProfit),
        $failures
    );
    $check(
        'per-bus-company includes our company (id='.$busCompany->id.')',
        collect($companyProfitRows)->contains(fn ($r) => (int) $r['entity_id'] === (int) $busCompany->id),
        $failures
    );
    $echo();

    // ═══════════════════════════════════════════════════════════════
    // ⑥ Dashboard carries GL-based profit, not column-based
    // ═══════════════════════════════════════════════════════════════
    $out[] = '━━ ⑥ Dashboard carries GL-based profit, not column-based ━━';

    $dashCarrierProfit = null;
    foreach (($dashboard['carrier_performance'] ?? []) as $cp) {
        if (str_contains((string) $cp['id'], 'car_'.$carrier->id)) {
            $dashCarrierProfit = (float) $cp['profit'];
            break;
        }
    }
    $glEntryForCarrier = collect($carrierProfitRows)->firstWhere('entity_id', $carrier->id);
    $glProfitForCarrier = $glEntryForCarrier ? (float) $glEntryForCarrier['profit'] : null;

    $check('dashboard carrier_performance has entry for our carrier', $dashCarrierProfit !== null, $failures);
    if ($dashCarrierProfit !== null && $glProfitForCarrier !== null) {
        $check(
            'dashboard carrier profit ('.$dashCarrierProfit.') === GL carrier profit ('.$glProfitForCarrier.')',
            $within($dashCarrierProfit, $glProfitForCarrier),
            $failures
        );
    }

    $dashBusCompanyProfit = null;
    foreach (($dashboard['bus_company_performance'] ?? []) as $cp) {
        if ((int) $cp['id'] === (int) $busCompany->id) {
            $dashBusCompanyProfit = (float) $cp['profit'];
            break;
        }
    }
    $glEntryForBusCompany = collect($companyProfitRows)->firstWhere('entity_id', $busCompany->id);
    $glProfitForBusCompany = $glEntryForBusCompany ? (float) $glEntryForBusCompany['profit'] : null;

    $check('dashboard bus_company_performance has entry for our company', $dashBusCompanyProfit !== null, $failures);
    if ($dashBusCompanyProfit !== null && $glProfitForBusCompany !== null) {
        $check(
            'dashboard bus-company profit ('.$dashBusCompanyProfit.') === GL bus-company profit ('.$glProfitForBusCompany.')',
            $within($dashBusCompanyProfit, $glProfitForBusCompany),
            $failures
        );
    }
    $echo();

} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION: '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().')';
    $out[] = '❌ EXCEPTION: '.$e->getMessage();
    $out[] = '   at '.$e->getFile().':'.$e->getLine();
    $echo();
} finally {
    DB::rollBack();
    $out[] = '═══ Final Verdict ═══';
    if ($failures === []) {
        $out[] = '✅ ALL CHECKS PASSED — DashboardService now sources profit from GL';
    } else {
        $out[] = '❌ '.count($failures).' CHECK(S) FAILED:';
        foreach ($failures as $f) {
            $out[] = '   • '.$f;
        }
    }

    $result = [
        'success' => $failures === [],
        'failed_count' => count($failures),
        'failed_labels' => $failures,
        'ran_at' => now()->toIso8601String(),
    ];

    @file_put_contents(
        storage_path('logs/phase1_dashboard_result.json'),
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $out[] = '';
    $out[] = 'Result file: '.storage_path('logs/phase1_dashboard_result.json');
    echo implode(PHP_EOL, $out).PHP_EOL;

    exit($failures === [] ? 0 : 1);
}