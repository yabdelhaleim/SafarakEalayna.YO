<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;

$types = Customer::select('type', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('type')
    ->get();

foreach ($types as $t) {
    $typeVal = is_object($t->type) ? $t->type->value : $t->type;
    echo "Type: '{$typeVal}' | Count: {$t->count}\n";
}
