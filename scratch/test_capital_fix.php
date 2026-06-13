<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightGroup;

$currency = 'EGP';
$payables = 0.0;

$groups = FlightGroup::with('carrier')->get();
echo "Total flight groups found: " . $groups->count() . "\n";
foreach ($groups as $g) {
    $gCurrency = $g->carrier?->currency ?: 'EGP';
    if (strtoupper($gCurrency) !== strtoupper($currency)) {
        continue;
    }
    
    $totalDebt = (float) $g->groupTransactions()->where('type', 'debt')->sum('amount');
    $totalPayment = (float) $g->groupTransactions()->where('type', 'payment')->sum('amount');
    $netBalance = $totalDebt - $totalPayment;
    echo "Group: {$g->name} | Currency: {$gCurrency} | Debt: {$totalDebt} | Payment: {$totalPayment} | Net: {$netBalance}\n";
    if ($netBalance > 0) {
        $payables += $netBalance;
    }
}

echo "Sum of Flight Groups payables for currency {$currency}: {$payables}\n";
