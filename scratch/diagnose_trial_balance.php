<?php

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$treasuryService = app(\App\Services\Finance\TreasuryService::class);

echo "================ TOURISM TRIAL BALANCE ================\n";
$tourism = $treasuryService->getTrialBalance();
print_r($tourism);

echo "\n================ OFFICE TRIAL BALANCE ================\n";
$office = $treasuryService->getOfficeTrialBalance();
print_r($office);
