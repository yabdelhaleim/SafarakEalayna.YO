<?php

use App\Models\Bus\BusCompany;
use App\Models\Account;
use App\Enums\AccountType;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$companies = BusCompany::whereNull('account_id')->get();

echo "Found " . $companies->count() . " companies without accounts.\n";

foreach ($companies as $company) {
    $account = Account::create([
        'name' => 'حساب شركة: ' . $company->name,
        'type' => AccountType::Treasury,
        'module_type' => 'bus',
        'currency' => 'EGP',
        'is_active' => true,
    ]);
    
    $company->account_id = $account->id;
    $company->save();
    
    echo "Created account for: " . $company->name . "\n";
}

echo "Sync complete.\n";
