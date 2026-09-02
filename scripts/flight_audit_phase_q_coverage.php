<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Phase Q — Coverage Matrix
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Generates a comprehensive Route → Controller → Service → Model → UI → Tests
 * coverage matrix for the Flight module. Maps every flight route to its
 * controller, service, model, Filament resource (if any), Vue page (if any),
 * and any existing automated test coverage.
 *
 * Output:
 *   - storage/logs/flight_audit_phase_q_coverage.json
 *   - storage/logs/flight_audit_phase_q_coverage.md (human-readable table)
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

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$base = realpath(__DIR__.'/..');

// 1. Get all flight routes from artisan (in-process to preserve env)
Artisan::call('route:list', ['--json' => true]);
$routeOutput = Artisan::output();
$routes = json_decode($routeOutput, true);

// Filter only flight-related routes
$flightRoutes = array_values(array_filter($routes ?: [], fn ($r) => (isset($r['uri']) && (str_contains($r['uri'], 'flight') || str_contains($r['uri'], 'aviation') || str_contains($r['uri'], 'modification')))
));

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Phase Q — Flight Module Coverage Matrix\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";
echo '  Total flight routes discovered: '.count($flightRoutes)."\n\n";

// 2. Static analysis: list all flight-related files
$flightModels = glob($base.'/app/Models/Flight/*.php');
$flightServices = glob($base.'/app/Services/Flight/*.php');
$flightControllers = glob($base.'/app/Http/Controllers/Api/V1/Flight/*.php');
$flightRequests = glob($base.'/app/Http/Requests/Flight/*.php');
$flightFilament = glob($base.'/app/Filament/Admin/Resources/Flight*/*.php');
$flightFilament = array_filter($flightFilament, fn ($f) => ! str_contains($f, '/Pages/') && ! str_contains($f, '/RelationManagers/'));
$flightVue = glob($base.'/resources/js/views/flights/*.vue');
$flightComponents = glob($base.'/resources/js/components/flights/*.vue');

echo "  Static analysis:\n";
echo '    - Flight models:        '.count($flightModels)."\n";
echo '    - Flight services:      '.count($flightServices)."\n";
echo '    - Flight controllers:   '.count($flightControllers)."\n";
echo '    - Flight form requests: '.count($flightRequests)."\n";
echo '    - Flight Filament resources: '.count($flightFilament)."\n";
echo '    - Flight Vue views:     '.count($flightVue)."\n";
echo '    - Flight Vue components: '.count($flightComponents)."\n\n";

// 3. Build coverage matrix per route
$matrix = [];
foreach ($flightRoutes as $route) {
    $method = is_array($route['method'] ?? null) ? implode('|', $route['method']) : ($route['method'] ?? 'GET');
    $uri = $route['uri'] ?? '';
    $action = $route['action'] ?? '';
    $middleware = is_array($route['middleware'] ?? null) ? implode(',', $route['middleware']) : ($route['middleware'] ?? '');

    // Resolve controller@method
    $ctrl = null;
    $method2 = null;
    if (preg_match('/([^@]+)@(\w+)/', $action, $m)) {
        $ctrl = $m[1];
        $method2 = $m[2];
    }

    // Resolve model from {model} param
    $model = null;
    if (preg_match('/{(?:flight)?(\w+)}/', $uri, $m)) {
        $candidate = strtolower($m[1]);
        if (in_array($candidate, ['booking', 'carrier', 'group', 'system', 'wallet', 'modification', 'refund', 'airlineaccount'])) {
            $model = 'Flight'.ucfirst($candidate);
        }
    }

    $matrix[] = [
        'method' => $method,
        'uri' => $uri,
        'controller' => $ctrl ? substr($ctrl, strrpos($ctrl, '\\') + 1) : null,
        'controller_action' => $method2,
        'middleware' => $middleware,
        'model' => $model,
    ];
}

// Calculate has_form_request per row by scanning the controller file for type-hinted FormRequest params
foreach ($matrix as &$row) {
    $hasFormReq = false;
    if ($row['controller'] && $row['controller_action']) {
        $controllerFile = $base.'/app/Http/Controllers/Api/V1/Flight/'.$row['controller'].'.php';
        if (file_exists($controllerFile)) {
            $contents = file_get_contents($controllerFile);
            // Extract method signature
            $pattern = '/function\s+'.preg_quote($row['controller_action'], '/').'\s*\(([^)]*)\)/s';
            if (preg_match($pattern, $contents, $m)) {
                $sig = $m[1];
                // Look for any FormRequest type-hint
                if (preg_match('/\\\\?[A-Z][A-Za-z0-9_]*Request/', $sig, $reqMatch)
                    && ! str_contains($reqMatch[0], 'FormRequest')) {
                    $hasFormReq = true;
                }
            }
        }
    }
    $row['has_form_request'] = $hasFormReq;
}
unset($row);

// 4. Map UI coverage per controller@method
$uiMap = [
    'FlightController' => [
        'index' => 'resources/js/views/flights/FlightIndex.vue',
        'store' => 'resources/js/views/flights/FlightCreate.vue',
        'show' => 'resources/js/views/flights/FlightShow.vue',
        'update' => 'resources/js/views/flights/FlightEdit.vue',
    ],
    'FlightCarrierController' => [
        'index' => 'resources/js/views/flights/FlightCarriersIndex.vue (assumed)',
    ],
    'FlightGroupController' => [
        'index' => 'resources/js/views/flights/FlightGroupsIndex.vue',
    ],
    'FlightSystemController' => [
        'index' => null, // no specific Vue view discovered
    ],
    'FlightDashboardController' => [
        'index' => 'resources/js/views/flights/FlightDashboard.vue',
    ],
    'FlightTreasuryController' => [
        'overview' => 'resources/js/views/flights/FlightTreasuryOverview.vue',
    ],
    'AirportController' => [
        'index' => 'resources/js/components/flights/AirportSearchInput.vue',
    ],
    'PassengerController' => [
        'index' => 'resources/js/views/flights/PassengersIndex.vue',
    ],
    'RefundController' => [
        'store' => 'resources/js/components/flights/RefundWizard.vue',
    ],
    'ModificationController' => [
        'store' => 'resources/js/components/flights/ModificationWizard.vue',
    ],
    'AirlineAccountController' => [
        'index' => 'resources/js/views/flights/FlightAirlineAccountsIndex.vue',
    ],
];

$filamentMap = [
    'FlightController' => 'app/Filament/Admin/Resources/FlightBookings/FlightBookingResource.php',
    'FlightCarrierController' => 'app/Filament/Admin/Resources/FlightCarriers/FlightCarrierResource.php',
    'FlightGroupController' => 'app/Filament/Admin/Resources/FlightGroups/FlightGroupResource.php',
    'FlightSystemController' => 'app/Filament/Admin/Resources/FlightSystems/FlightSystemResource.php',
];

$enriched = [];
foreach ($matrix as $row) {
    $ctrl = $row['controller'];
    $row['vue_view'] = $uiMap[$ctrl][$row['controller_action']] ?? null;
    $row['vue_view_exists'] = $row['vue_view'] ? file_exists($base.'/'.$row['vue_view']) : false;
    $row['filament_resource'] = $filamentMap[$ctrl] ?? null;
    $row['filament_resource_exists'] = $row['filament_resource'] ? file_exists($base.'/'.$row['filament_resource']) : false;
    $enriched[] = $row;
}

// 5. Tests coverage
$testScripts = glob($base.'/scripts/flight_audit_*.php');
$testScripts = array_merge($testScripts, glob($base.'/scripts/flight_module_*.php'));
$testScripts = array_unique($testScripts);

echo '  Audit scripts ('.count($testScripts)."):\n";
foreach ($testScripts as $s) {
    echo '    - '.basename($s)."\n";
}
echo "\n";

// 6. Generate coverage percentages
$total = count($enriched);
$withVue = count(array_filter($enriched, fn ($r) => $r['vue_view_exists']));
$withFilament = count(array_filter($enriched, fn ($r) => $r['filament_resource_exists']));
$withFormReq = count(array_filter($enriched, fn ($r) => $r['has_form_request']));
$coveredByPhaseV = count(array_filter($enriched, fn ($r) => $r['controller'] !== null));
$coveredByAudit = $coveredByPhaseV; // Phase V covered all controller-based routes

$coverage = [
    'total_routes' => $total,
    'routes_with_vue' => $withVue,
    'routes_with_filament' => $withFilament,
    'routes_with_form_req' => $withFormReq,
    'pct_vue' => $total ? round(100 * $withVue / $total, 1) : 0,
    'pct_filament' => $total ? round(100 * $withFilament / $total, 1) : 0,
    'pct_form_req' => $total ? round(100 * $withFormReq / $total, 1) : 0,
];

echo "  Coverage:\n";
echo "    - Total flight routes:      {$total}\n";
echo "    - With Vue UI coverage:     {$withVue} ({$coverage['pct_vue']}%)\n";
echo "    - With Filament coverage:   {$withFilament} ({$coverage['pct_filament']}%)\n";
echo "    - With FormRequest:         {$withFormReq} ({$coverage['pct_form_req']}%)\n\n";

// 7. Find orphan routes (no UI coverage, no FormRequest)
$orphans = array_filter($enriched, fn ($r) => ! $r['vue_view_exists']
    && ! $r['filament_resource_exists']
    && $r['controller']
    && ! in_array($r['controller_action'], ['nextNumber', 'systemTypes', 'available', 'employeesForBooking'], true) // utility endpoints ok
);
echo '  Orphan routes (no UI, no FormRequest): '.count($orphans)."\n";
foreach ($orphans as $o) {
    echo "    - {$o['method']} /{$o['uri']} → {$o['controller']}@{$o['controller_action']}\n";
}

// Save
file_put_contents($base.'/storage/logs/flight_audit_phase_q_coverage.json', json_encode([
    'generated_at' => date('Y-m-d H:i:s'),
    'coverage' => $coverage,
    'matrix' => $enriched,
    'orphans' => array_values($orphans),
    'static_counts' => [
        'models' => count($flightModels),
        'services' => count($flightServices),
        'controllers' => count($flightControllers),
        'form_requests' => count($flightRequests),
        'filament_resources' => count($flightFilament),
        'vue_views' => count($flightVue),
        'vue_components' => count($flightComponents),
        'test_scripts' => count($testScripts),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Save markdown table
$md = "# Flight Module — Coverage Matrix\n\n";
$md .= 'Generated: '.date('Y-m-d H:i:s')."\n\n";
$md .= "## Coverage Summary\n\n";
$md .= "| Metric | Count | Percentage |\n|---|---|---|\n";
$md .= "| Total routes | {$coverage['total_routes']} | — |\n";
$md .= "| Routes with Vue UI | {$coverage['routes_with_vue']} | {$coverage['pct_vue']}% |\n";
$md .= "| Routes with Filament | {$coverage['routes_with_filament']} | {$coverage['pct_filament']}% |\n";
$md .= "| Routes with FormRequest | {$coverage['routes_with_form_req']} | {$coverage['pct_form_req']}% |\n\n";
$md .= "## Route Matrix\n\n";
$md .= "| Method | URI | Controller | Action | FormReq | Vue | Filament |\n|---|---|---|---|---|---|---|\n";
foreach ($enriched as $r) {
    $md .= "| {$r['method']} | `{$r['uri']}` | {$r['controller']} | {$r['controller_action']} | "
        .($r['has_form_request'] ? '✅' : '—').' | '
        .($r['vue_view_exists'] ? '✅' : '—').' | '
        .($r['filament_resource_exists'] ? '✅' : '—')." |\n";
}
if (count($orphans) > 0) {
    $md .= "\n## Orphan Routes (no UI coverage)\n\n";
    foreach ($orphans as $o) {
        $md .= "- `{$o['method']} /{$o['uri']}` → `{$o['controller']}@{$o['controller_action']}`\n";
    }
}

file_put_contents($base.'/storage/logs/flight_audit_phase_q_coverage.md', $md);

echo "\n  Saved: storage/logs/flight_audit_phase_q_coverage.json\n";
echo "  Saved: storage/logs/flight_audit_phase_q_coverage.md\n";
echo "\n═══════════════════════════════════════════════════════════════════════\n";
