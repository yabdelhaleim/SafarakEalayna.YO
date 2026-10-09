<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$at = DB::select("SELECT * FROM airline_transactions ORDER BY id DESC LIMIT 5");
echo "airline_transactions:\n";
print_r($at);

$tx = DB::select("SELECT * FROM transactions WHERE module = 'flight' ORDER BY id DESC LIMIT 5");
echo "transactions:\n";
print_r($tx);
