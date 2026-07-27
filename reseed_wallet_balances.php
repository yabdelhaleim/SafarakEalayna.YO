<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$balances = [
    'WL_EGP_Vodafone' => 50000.00,
    'WL_EGP_InstaPay' => 30000.00,
    'WL_USD_Vodafone' => 2000.00,
    'WL_USD_InstaPay' => 1500.00,
    'WL_SAR_Vodafone' => 5000.00,
    'WL_SAR_InstaPay' => 3000.00,
    'WL_CASH_EGP' => 100000.00,
    'WL_CASH_USD' => 5000.00,
    'WL_CASH_SAR' => 10000.00,
    'WL_BANK_EGP' => 50000.00,
    'WL_BANK_USD' => 3000.00,
    'WL_BANK_SAR' => 8000.00,
];

foreach ($balances as $name => $bal) {
    $acc = Account::where('name', $name)->first();
    if ($acc) {
        LedgerBalanceMutationGuard::run(function () use ($acc, $bal) {
            $acc->balance = $bal;
            $acc->save();
        });
        echo "  $name: balance=" . Account::find($acc->id)->balance . PHP_EOL;
    } else {
        echo "  $name: NOT FOUND" . PHP_EOL;
    }
}
