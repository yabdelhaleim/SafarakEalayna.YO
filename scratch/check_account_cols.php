<?php

use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$ref = new ReflectionClass(Account::class);
foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if (str_contains($m->getName(), 'Vault') || str_contains($m->getName(), 'bus') || str_contains($m->getName(), 'Module')) {
        echo 'Account method: '.$m->getName()."\n";
    }
}
echo 'Account columns: '.implode(', ', Schema::getColumnListing('accounts'))."\n";
