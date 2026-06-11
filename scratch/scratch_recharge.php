<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightSystemRechargeService;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Sanctum\Sanctum;

try {
    $user = User::first() ?: User::factory()->create();
    Sanctum::actingAs($user);

    $sys = FlightSystem::first() ?: FlightSystem::create([
        'name' => 'Test System',
        'code' => 'TESTSYS',
        'currency' => 'EGP',
        'balance' => 0,
        'credit_limit' => 0,
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    $acc = Account::where('module_type', 'flights')->first() ?: Account::create([
        'name' => 'Flight Test Cashbox',
        'type' => 'cashbox',
        'currency' => 'EGP',
        'balance' => 5000.00,
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'flights',
        'created_by' => $user->id,
    ]);

    echo "Running recharge system...\n";
    $result = app(FlightSystemRechargeService::class)->rechargeFromAccount($sys, $acc, 1000.00);
    echo 'Success! New system balance: '.$result['system']->balance."\n";
} catch (Throwable $e) {
    echo 'Error: '.$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n";
}
