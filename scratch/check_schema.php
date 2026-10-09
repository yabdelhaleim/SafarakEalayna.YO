<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Treasury;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

echo 'account_entries columns: '.implode(', ', Schema::getColumnListing('account_entries'))."\n";
echo 'treasuries count: '.Treasury::count()."\n";
if (Treasury::count() > 0) {
    echo 'First Treasury ID: '.Treasury::first()->id."\n";
} else {
    echo "No treasuries found!\n";
}
