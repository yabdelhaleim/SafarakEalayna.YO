<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Services\Bus\BusCompanyService;
use App\Services\Bus\BusInventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "=== PHASE 5: OPERATIONAL DATA GENERATION ===\n";

$manifest = [
    'generated_at' => date('Y-m-d H:i:s'),
    'environment' => config('app.env'),
    'database' => DB::getDatabaseName(),
    'entities' => [],
];

DB::transaction(function () use (&$manifest) {
    // 1. Get or create test user (admin)
    $user = User::first() ?? User::create(['name' => 'Audit Admin', 'email' => 'audit_bus@example.com', 'password' => bcrypt('password')]);

    // 2. Create Master Data: Bus Companies
    $companyService = app(BusCompanyService::class);
    $companiesData = [
        ['name' => 'AUDIT_GoBus_Lines_'.time(), 'phone' => '01011112222', 'address' => 'Cairo Station'],
        ['name' => 'AUDIT_SuperJet_Express_'.time(), 'phone' => '01022223333', 'address' => 'Alexandria Terminal'],
        ['name' => 'AUDIT_UpperEgypt_Transport_'.time(), 'phone' => '01033334444', 'address' => 'Asyut Terminal'],
    ];

    $createdCompanies = [];
    foreach ($companiesData as $c) {
        $comp = $companyService->createCompany($c);
        $createdCompanies[] = $comp;
        $manifest['entities'][] = [
            'entity_type' => 'BusCompany',
            'table' => 'bus_companies',
            'id' => $comp->id,
            'account_id' => $comp->account_id,
            'name' => $comp->name,
            'purpose' => 'Master operator test record',
        ];
    }

    // 3. Create Inventories
    $createdInventories = [];
    $routes = [
        ['Cairo - Alexandria', 50, 150.00, 200.00, 'cash'],
        ['Cairo - Sharm El Sheikh', 40, 300.00, 450.00, 'deferred'],
        ['Cairo - Hurghada', 30, 250.00, 380.00, 'deferred'],
        ['Alexandria - Luxor', 20, 400.00, 600.00, 'cash'],
    ];

    $vault = Account::getModuleVault('bus') ?? Account::where('is_module_vault', true)->first();
    if (! $vault) {
        $vault = Account::create([
            'name' => 'Bus Test Vault',
            'type' => 'cashbox',
            'module_type' => 'office',
            'currency' => 'EGP',
            'balance' => 1000000.00,
            'is_active' => true,
            'module' => 'bus',
            'is_module_vault' => true,
        ]);
    } else {
        $vault->update(['balance' => max($vault->balance, 1000000.00)]);
    }

    foreach ($createdCompanies as $idx => $comp) {
        foreach ($routes as $rIdx => $r) {
            $invData = [
                'company_id' => $comp->id,
                'route' => $r[0].' (Ref '.($idx * 4 + $rIdx + 1).')',
                'travel_date' => date('Y-m-d', strtotime('+'.($rIdx + 1).' days')),
                'departure_time' => '08:00',
                'total_tickets' => $r[1],
                'cost_per_ticket' => $r[2],
                'selling_price' => $r[3],
                'payment_type' => $r[4],
                'account_id' => $r[4] === 'cash' ? $vault->id : null,
                'notes' => 'Generated for E2E audit',
            ];

            $invService = app(BusInventoryService::class);
            $inv = $invService->createInventory($invData);
            $createdInventories[] = $inv;
            $manifest['entities'][] = [
                'entity_type' => 'BusInventory',
                'table' => 'bus_inventories',
                'id' => $inv->id,
                'company_id' => $comp->id,
                'route' => $inv->route,
                'total_tickets' => $inv->total_tickets,
                'available_tickets' => $inv->available_tickets,
                'payment_type' => is_object($inv->payment_type) ? $inv->payment_type->value : $inv->payment_type,
                'purpose' => 'Trip ticket inventory test allocation',
            ];
        }
    }

    // 4. Create Customers
    $createdCustomers = [];
    for ($i = 1; $i <= 5; $i++) {
        $cust = Customer::create([
            'full_name' => "Audit Passenger {$i} ".time(),
            'phone' => "0120000000{$i}",
            'type' => 'individual',
            'is_active' => true,
        ]);
        $createdCustomers[] = $cust;
        $manifest['entities'][] = [
            'entity_type' => 'Customer',
            'table' => 'customers',
            'id' => $cust->id,
            'full_name' => $cust->full_name,
            'purpose' => 'Passenger / purchaser customer record',
        ];
    }
});

file_put_contents('C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897\BUS_TEST_DATA_MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT));
file_put_contents(__DIR__.'/../BUS_TEST_DATA_MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT));

echo 'Generated Manifest with '.count($manifest['entities'])." entities successfully.\n";
