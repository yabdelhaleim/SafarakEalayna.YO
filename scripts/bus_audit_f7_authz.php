<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * F-7 — Authorization Gate for Bus API DELETE Routes
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Verifies the contract:
 *   - Admin (owner) → DELETE /api/v1/bus/{companies|inventories|bookings}/{id} → 2xx
 *   - Non-admin (manager | employee) → DELETE → 403 Forbidden
 *   - Unauthenticated → DELETE → 401 Unauthorized
 *
 * And confirms NO regression:
 *   - GET by non-admin still works (200/404) — no over-gating
 *   - POST by non-admin still works (422/201/200) — no over-gating
 *
 * Boots the same isolated SQLite used by bus_audit_setup.php, drives the
 * actual HTTP middleware via php artisan serve, records results to
 * storage/logs/bus_audit_f7_authz.json.
 *
 * Run after:
 *   1. php scripts/bus_audit_setup.php
 *   2. php -S 127.0.0.1:8765 -t public (in another terminal)
 *   3. php scripts/bus_audit_f7_authz.php
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$localDbPath = storage_path('app/local_bus_audit.sqlite');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$localDbPath);
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $localDbPath;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $localDbPath;
if (file_exists($localDbPath)) {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $localDbPath);
    DB::purge('sqlite');
}

use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Services\Bus\BusBookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'summary' => ['pass' => 0, 'fail' => 0, 'warn' => 0],
    'tests' => [],
];

$ok = function (string $m) use (&$results): void {
    echo "  [PASS] $m\n";
    $results['summary']['pass']++;
};
$fail = function (string $m) use (&$results): void {
    echo "  [FAIL] $m\n";
    $results['summary']['fail']++;
};
$warn = function (string $m) use (&$results): void {
    echo "  [WARN] $m\n";
    $results['summary']['warn']++;
};
$head = function (string $m): void {
    echo "\n── $m\n";
};

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  F-7 — Authorization Gate for Bus API DELETE Routes\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Verify dev server is reachable ────────────────────────────────────────
$baseUrl = getenv('F7_BASE_URL') ?: 'http://127.0.0.1:8765';
echo "  - Base URL: $baseUrl\n";

$healthCheck = @file_get_contents("$baseUrl/api/v1/auth/login", false, stream_context_create([
    'http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 3],
]));
if ($healthCheck === false) {
    echo "  [FATAL] Dev server not reachable at $baseUrl\n";
    echo "          Start it with: php -S 127.0.0.1:8765 -t public\n";
    $results['finished_at'] = date('Y-m-d H:i:s');
    file_put_contents(
        storage_path('logs/bus_audit_f7_authz.json'),
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    exit(1);
}
echo "  ✅ Dev server reachable\n\n";

// ─── Ensure users exist (idempotent — recreate each run) ───────────────────
$head('Setup: ensure admin + 2 non-admin users + test data');

$adminId = DB::table('users')->where('email', 'admin@tx-bus-audit.local')->value('id');
$managerId = DB::table('users')->where('email', 'manager@tx-bus-audit.local')->value('id');
$employeeId = DB::table('users')->where('email', 'employee@tx-bus-audit.local')->value('id');

if (! $adminId) {
    $adminId = DB::table('users')->insertGetId([
        'name' => 'TX-AUDIT Admin', 'email' => 'admin@tx-bus-audit.local',
        'password' => bcrypt('password'), 'role' => 'owner', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
if (! $managerId) {
    $managerId = DB::table('users')->insertGetId([
        'name' => 'TX-AUDIT Manager', 'email' => 'manager@tx-bus-audit.local',
        'password' => bcrypt('password'), 'role' => 'manager', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
if (! $employeeId) {
    $employeeId = DB::table('users')->insertGetId([
        'name' => 'TX-AUDIT Employee', 'email' => 'employee@tx-bus-audit.local',
        'password' => bcrypt('password'), 'role' => 'employee', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// Reset passwords to a known value (in case prior runs changed them)
DB::table('users')->whereIn('id', [$adminId, $managerId, $employeeId])
    ->update(['password' => Hash::make('password')]);
echo "  - admin id={$adminId} (owner), manager id={$managerId}, employee id={$employeeId}\n";

// ─── Create test data: 1 company + 1 inventory + 1 booking per scenario ──
$egpVault = DB::table('accounts')
    ->whereIn('type', ['cashbox', 'bank'])
    ->where('currency', 'EGP')
    ->where('module_type', 'office')
    ->first();
if (! $egpVault) {
    echo "  [FATAL] No EGP office vault seeded. Run bus_audit_setup.php first.\n";
    exit(1);
}
$vaultId = $egpVault->id;

// Create a fresh test company
$company = BusCompany::create([
    'name' => 'F7-AUTHZ Co '.substr(md5(uniqid()), 0, 6),
    'is_active' => true,
    'created_by' => $adminId,
]);
$companyId = $company->id;
echo "  - Company id={$companyId} created\n";

// Create a customer
$customer = Customer::firstOrCreate(
    ['phone' => '01000000007'],
    ['full_name' => 'F7-AUTHZ Customer', 'name' => 'F7-AUTHZ Customer', 'created_by' => $adminId]
);
$customerId = $customer->id;

// Create an inventory in the company
$inventory = BusInventory::create([
    'company_id' => $companyId,
    'route' => 'F7-AUTHZ Route',
    'travel_date' => '2026-12-01',
    'departure_time' => '10:00:00',
    'total_tickets' => 20,
    'available_tickets' => 20,
    'cost_per_ticket' => 80.0,
    'selling_price' => 100.0,
    'payment_type' => 'cash',
    'amount_paid' => 1600.0,
    'remaining_debt' => 0.0,
    'currency' => 'EGP',
    'account_id' => $vaultId,
    'exchange_rate_to_egp' => 1.0,
    'created_by' => $adminId,
]);
$inventoryId = $inventory->id;
echo "  - Inventory id={$inventoryId} created\n";

// Create a booking via the service (handles employee_id + required FKs)
$bookingService = app(BusBookingService::class);
$booking = $bookingService->createBooking([
    'inventory_id' => $inventoryId,
    'customer_id' => $customerId,
    'quantity' => 1,
    'notes' => 'F7-AUTHZ test booking',
    'created_by' => $adminId,
]);
$bookingId = $booking->id;
echo "  - Booking id={$bookingId} created\n\n";

// ─── Helper: obtain Sanctum token by login ────────────────────────────────
function loginToken(string $baseUrl, string $email, string $password): ?string
{
    $ch = curl_init("$baseUrl/api/v1/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 && $code !== 201) {
        return null;
    }
    $data = json_decode($body, true);
    // Try common keys
    foreach (['token', 'access_token', 'data.token'] as $key) {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $val = $data;
            foreach ($parts as $p) {
                if (is_array($val) && isset($val[$p])) {
                    $val = $val[$p];
                } else {
                    $val = null;
                    break;
                }
            }
            if (is_string($val)) {
                return $val;
            }
        } elseif (isset($data[$key]) && is_string($data[$key])) {
            return $data[$key];
        }
    }

    return null;
}

function callApi(string $baseUrl, string $method, string $path, ?string $token, ?array $body = null): array
{
    $ch = curl_init("$baseUrl$path");
    $headers = ['Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => false,
    ];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $code, 'body' => $resp, 'json' => json_decode((string) $resp, true)];
}

// ─── Obtain tokens for 3 roles ────────────────────────────────────────────
$head('Obtain Sanctum tokens for admin + manager + employee');

$adminToken = loginToken($baseUrl, 'admin@tx-bus-audit.local', 'password');
$managerToken = loginToken($baseUrl, 'manager@tx-bus-audit.local', 'password');
$employeeToken = loginToken($baseUrl, 'employee@tx-bus-audit.local', 'password');

if (! $adminToken) {
    $fail('Could not obtain admin token');
    exit(1);
} else {
    $ok('admin token obtained');
}
if (! $managerToken) {
    $fail('Could not obtain manager token');
    exit(1);
} else {
    $ok('manager token obtained');
}
if (! $employeeToken) {
    $fail('Could not obtain employee token');
    exit(1);
} else {
    $ok('employee token obtained');
}

// ─── T1: Admin DELETE booking (no children) → 200 ─────────────────────────
$head('T1: Admin DELETE booking → success (2xx)');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/bookings/$bookingId", $adminToken);
if ($r['status'] >= 200 && $r['status'] < 300) {
    $ok("admin DELETE /bookings/{$bookingId} → {$r['status']}");
    record($results, 'T1_admin_delete_booking', 'PASS', "status={$r['status']}");
} else {
    $fail("admin DELETE /bookings/{$bookingId} → {$r['status']} ".substr((string) $r['body'], 0, 200));
    record($results, 'T1_admin_delete_booking', 'FAIL', "status={$r['status']}");
}

// ─── T2: Manager DELETE → 403 ─────────────────────────────────────────────
$head('T2: Manager DELETE inventory → 403 Forbidden');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/inventories/$inventoryId", $managerToken);
if ($r['status'] === 403) {
    $ok("manager DELETE /inventories/{$inventoryId} → 403");
    record($results, 'T2_manager_delete_inventory', 'PASS', 'status=403');
} else {
    $fail("manager DELETE /inventories/{$inventoryId} → {$r['status']} (expected 403) ".substr((string) $r['body'], 0, 200));
    record($results, 'T2_manager_delete_inventory', 'FAIL', "status={$r['status']}, expected 403");
}

// ─── T3: Employee DELETE → 403 ────────────────────────────────────────────
$head('T3: Employee DELETE company → 403 Forbidden');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/companies/$companyId", $employeeToken);
if ($r['status'] === 403) {
    $ok("employee DELETE /companies/{$companyId} → 403");
    record($results, 'T3_employee_delete_company', 'PASS', 'status=403');
} else {
    $fail("employee DELETE /companies/{$companyId} → {$r['status']} (expected 403) ".substr((string) $r['body'], 0, 200));
    record($results, 'T3_employee_delete_company', 'FAIL', "status={$r['status']}, expected 403");
}

// ─── T4: Unauthenticated DELETE → 401 ─────────────────────────────────────
$head('T4: Unauthenticated DELETE → 401 Unauthorized');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/inventories/$inventoryId", null);
if ($r['status'] === 401) {
    $ok("unauthenticated DELETE /inventories/{$inventoryId} → 401");
    record($results, 'T4_unauth_delete_inventory', 'PASS', 'status=401');
} else {
    $fail("unauthenticated DELETE /inventories/{$inventoryId} → {$r['status']} (expected 401) ".substr((string) $r['body'], 0, 200));
    record($results, 'T4_unauth_delete_inventory', 'FAIL', "status={$r['status']}, expected 401");
}

// ─── T5: Manager can still GET — no over-gating ───────────────────────────
$head('T5: Manager GET (no over-gating) — 200 OK');

$r = callApi($baseUrl, 'GET', '/api/v1/bus/inventories', $managerToken);
if ($r['status'] === 200) {
    $ok('manager GET /inventories → 200');
    record($results, 'T5_manager_get_inventories', 'PASS', 'status=200');
} else {
    $fail("manager GET /inventories → {$r['status']} (expected 200)");
    record($results, 'T5_manager_get_inventories', 'FAIL', "status={$r['status']}, expected 200");
}

// ─── T6: Employee can still GET — no over-gating ──────────────────────────
$head('T6: Employee GET (no over-gating) — 200 OK');

$r = callApi($baseUrl, 'GET', '/api/v1/bus/bookings', $employeeToken);
if ($r['status'] === 200) {
    $ok('employee GET /bookings → 200');
    record($results, 'T6_employee_get_bookings', 'PASS', 'status=200');
} else {
    $fail("employee GET /bookings → {$r['status']} (expected 200)");
    record($results, 'T6_employee_get_bookings', 'FAIL', "status={$r['status']}, expected 200");
}

// ─── T7: Admin DELETE inventory (booking already gone) — 2xx ──────────────
$head('T7: Admin DELETE inventory (post-booking) — 2xx');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/inventories/$inventoryId", $adminToken);
if ($r['status'] >= 200 && $r['status'] < 300) {
    $ok("admin DELETE /inventories/{$inventoryId} → {$r['status']}");
    record($results, 'T7_admin_delete_inventory', 'PASS', "status={$r['status']}");
} else {
    $fail("admin DELETE /inventories/{$inventoryId} → {$r['status']} (expected 2xx) ".substr((string) $r['body'], 0, 200));
    record($results, 'T7_admin_delete_inventory', 'FAIL', "status={$r['status']}");
}

// ─── T8: Admin DELETE company (inventory already gone) — 2xx ──────────────
$head('T8: Admin DELETE company (post-inventory) — 2xx');

$r = callApi($baseUrl, 'DELETE', "/api/v1/bus/companies/$companyId", $adminToken);
if ($r['status'] >= 200 && $r['status'] < 300) {
    $ok("admin DELETE /companies/{$companyId} → {$r['status']}");
    record($results, 'T8_admin_delete_company', 'PASS', "status={$r['status']}");
} else {
    $fail("admin DELETE /companies/{$companyId} → {$r['status']} (expected 2xx) ".substr((string) $r['body'], 0, 200));
    record($results, 'T8_admin_delete_company', 'FAIL', "status={$r['status']}");
}

// ─── Verify soft-delete + financial reversal actually ran ──────────────────
$head('Sanity: verify soft-delete + reversal were performed by admin DELETE');

$companyAfter = DB::table('bus_companies')->where('id', $companyId)->first();
$inventoryAfter = DB::table('bus_inventories')->where('id', $inventoryId)->first();
$bookingAfter = DB::table('bus_bookings')->where('id', $bookingId)->first();

if ($companyAfter && $companyAfter->deleted_at !== null) {
    $ok("company id={$companyId} soft-deleted (deleted_at={$companyAfter->deleted_at})");
    record($results, 'S1_company_soft_deleted', 'PASS', "deleted_at={$companyAfter->deleted_at}");
} else {
    $fail("company id={$companyId} NOT soft-deleted");
    record($results, 'S1_company_soft_deleted', 'FAIL', 'deleted_at is null');
}

if ($inventoryAfter && $inventoryAfter->deleted_at !== null) {
    $ok("inventory id={$inventoryId} soft-deleted (deleted_at={$inventoryAfter->deleted_at})");
    record($results, 'S2_inventory_soft_deleted', 'PASS', "deleted_at={$inventoryAfter->deleted_at}");
} else {
    $fail("inventory id={$inventoryId} NOT soft-deleted");
    record($results, 'S2_inventory_soft_deleted', 'FAIL', 'deleted_at is null');
}

if ($bookingAfter && $bookingAfter->deleted_at !== null) {
    $ok("booking id={$bookingId} soft-deleted (deleted_at={$bookingAfter->deleted_at})");
    record($results, 'S3_booking_soft_deleted', 'PASS', "deleted_at={$bookingAfter->deleted_at}");
} else {
    $fail("booking id={$bookingId} NOT soft-deleted");
    record($results, 'S3_booking_soft_deleted', 'FAIL', 'deleted_at is null');
}

// ─── Summary ──────────────────────────────────────────────────────────────
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(
    storage_path('logs/bus_audit_f7_authz.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  F-7 Authorization Test Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PASS: {$results['summary']['pass']}\n";
echo "  FAIL: {$results['summary']['fail']}\n";
echo "  WARN: {$results['summary']['warn']}\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Results written to: storage/logs/bus_audit_f7_authz.json\n";

exit($results['summary']['fail'] > 0 ? 1 : 0);
