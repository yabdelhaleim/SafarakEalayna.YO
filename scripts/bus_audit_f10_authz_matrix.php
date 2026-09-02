<?php

/**
 * F-10 investigation — Bus API authorization matrix live probe.
 *
 * Read-only probe — does NOT mutate data. Hits each route once per role
 * with a fresh Sanctum token, captures the HTTP status code, and prints
 * a per-resource × role × verb matrix.
 *
 * Run from the project root:
 *   DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/app/local_bus_test.sqlite \
 *   DB_FOREIGN_KEYS=true \
 *   php scripts/bus_audit_f10_authz_matrix.php
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$baseUrl = env('F10_BASE_URL', 'http://127.0.0.1:8000/api/v1');

// Pre-seed: ensure admin/manager/employee users exist
$roles = [
    'admin' => 'admin@f10.local',
    'manager' => 'manager@f10.local',
    'employee' => 'employee@f10.local',
    'owner' => 'owner@f10.local',
];

$tokens = [];
foreach ($roles as $role => $email) {
    $u = User::firstOrCreate(['email' => $email], [
        'name' => ucfirst($role).' F10',
        'password' => bcrypt('password'),
        'role' => $role,
        'is_active' => true,
    ]);
    $tokens[$role] = $u->createToken('f10-probe-'.$role)->plainTextToken;
}

// Probe matrix: each row = (role, method, route-template-with-placeholder, body)
$probes = [
    // ----- READ-ONLY (sanctum-protected, open to all authenticated users) -----
    ['GET',  '/bus/companies',                        null, 'list companies'],
    ['GET',  '/bus/companies/1',                      null, 'show company 1'],
    ['GET',  '/bus/companies/1/statement',            null, 'company statement'],
    ['GET',  '/bus/inventories',                      null, 'list inventories'],
    ['GET',  '/bus/inventories/available',            null, 'available inventories'],
    ['GET',  '/bus/inventories/1',                    null, 'show inventory 1'],
    ['GET',  '/bus/bookings',                         null, 'list bookings'],
    ['GET',  '/bus/bookings/stats',                   null, 'booking stats'],
    ['GET',  '/bus/bookings/1',                       null, 'show booking 1'],
    ['GET',  '/bus/dashboard',                        null, 'dashboard'],
    ['GET',  '/bus/treasury/overview',                null, 'treasury overview'],
    ['GET',  '/bus/treasury/accounts/1/bus-transactions', null, 'treasury account txns'],
    ['GET',  '/bus/customers',                        null, 'list customers'],
    ['GET',  '/bus/refunds/treasuries',               null, 'refund treasuries'],
    ['GET',  '/bus/refunds/1',                        null, 'show refund 1'],

    // ----- WRITE — non-money (sanctum only) -----
    ['POST', '/bus/companies',                        ['name' => 'F10 Probe Co', 'phone' => '01000000999', 'is_active' => true], 'create company'],
    ['PUT',  '/bus/companies/1',                      ['name' => 'F10 Probe Co X', 'phone' => '01000000999', 'is_active' => true], 'update company'],

    // ----- WRITE — non-money (sanctum only) -----
    ['POST', '/bus/inventories',                      ['company_id' => 1, 'route' => 'X → Y', 'travel_date' => '2026-12-31', 'departure_time' => '10:00', 'total_tickets' => 1, 'available_tickets' => 1, 'cost_per_ticket' => 1, 'selling_price' => 1, 'currency' => 'EGP', 'exchange_rate_to_egp' => 1, 'payment_type' => 'deferred', 'notes' => 'f10 probe'], 'create inventory'],

    // ----- WRITE — non-money (sanctum only) -----
    ['POST', '/bus/bookings',                         ['inventory_id' => 1, 'customer_id' => 1, 'quantity' => 1, 'unit_price' => 1, 'currency' => 'EGP', 'exchange_rate_to_egp' => 1], 'create booking'],

    // ----- WRITE — MONEY (admin only) -----
    ['POST', '/bus/bookings/1/pay',                   ['amount' => 100, 'payment_method' => 'cash', 'account_id' => 1], 'pay booking (moves money)'],
    ['POST', '/bus/companies/1/pay-debt',             ['amount' => 100, 'payment_method' => 'cash', 'account_id' => 1], 'pay company debt (moves money)'],
    ['POST', '/bus/inventories/1/pay-debt',           ['amount' => 100, 'payment_method' => 'cash', 'account_id' => 1], 'pay inventory debt (moves money)'],
    ['POST', '/bus/bookings/1/cancel',                ['reason' => 'f10 probe'], 'cancel booking (moves money)'],
    ['POST', '/bus/refunds',                          ['amount' => 100, 'reason' => 'f10 probe'], 'create refund (moves money)'],
    ['POST', '/bus/refunds/1/process',                [], 'process refund (moves money)'],

    // ----- DELETE (admin only, post F-7) -----
    ['DELETE', '/bus/companies/1',                   null, 'soft-delete company + reverse'],
    ['DELETE', '/bus/inventories/1',                  null, 'soft-delete inventory + reverse'],
    ['DELETE', '/bus/bookings/1',                     null, 'soft-delete booking + reverse'],
];

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  F-10 — Bus API Authorization Matrix (live HTTP probe)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Base URL: {$baseUrl}\n\n";

$results = [];
foreach ($probes as [$method, $path, $body, $label]) {
    $row = ['label' => $label, 'method' => $method, 'path' => $path];
    foreach (['admin', 'manager', 'employee', 'owner'] as $role) {
        $token = $tokens[$role];
        $client = Http::withToken($token)->acceptJson();
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $resp = $client->{$method === 'POST' ? 'post' : ($method === 'PUT' ? 'put' : 'patch')}($baseUrl.$path, $body);
        } else {
            $resp = $client->{$method === 'GET' ? 'get' : 'delete'}($baseUrl.$path);
        }
        $code = $resp->status();
        $row[$role] = $code;
    }
    $results[] = $row;
}

echo str_pad('#', 4).str_pad('Method', 10).str_pad('Path', 50).str_pad('Admin', 8).str_pad('Manager', 10).str_pad('Employee', 10).str_pad('Owner', 8)."Label\n";
echo str_repeat('─', 130)."\n";
foreach ($results as $i => $r) {
    echo str_pad($i + 1, 4)
       .str_pad($r['method'], 10)
       .str_pad(substr($r['path'], 0, 48), 50)
       .str_pad((string) $r['admin'], 8)
       .str_pad((string) $r['manager'], 10)
       .str_pad((string) $r['employee'], 10)
       .str_pad((string) $r['owner'], 8)
       .$r['label']
       ."\n";
}

echo "\n";
echo "Legend: 200/201 = success, 401 = unauthenticated, 403 = forbidden, 404 = not found, 422 = validation, 5xx = server error\n";
echo "\n";
