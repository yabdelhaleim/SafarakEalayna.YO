<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use Illuminate\Contracts\Console\Kernel;

echo "--- Hotels ---\n";
foreach (Hotel::with('account')->get() as $h) {
    $bal = $h->account ? $h->account->balance : 'no account';
    echo "Hotel: {$h->name}, Balance: {$bal}\n";
}

echo "\n--- Umrah Suppliers ---\n";
foreach (UmrahSupplier::with('account')->get() as $us) {
    $bal = $us->account ? $us->account->balance : 'no account';
    echo "UmrahSupplier: {$us->name}, Balance: {$bal}\n";
}

echo "\n--- Executing Companies ---\n";
foreach (HajjUmraExecutingCompany::with('account')->get() as $ec) {
    $bal = $ec->account ? $ec->account->balance : 'no account';
    echo "ExecutingCompany: {$ec->name}, Balance: {$bal}\n";
}

echo "\n--- Visa Agents ---\n";
foreach (VisaAgent::with('account')->get() as $va) {
    $bal = $va->account ? $va->account->balance : 'no account';
    echo "VisaAgent: {$va->company_name}, Balance: {$bal}\n";
}
