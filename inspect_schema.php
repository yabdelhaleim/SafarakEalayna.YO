<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;

$acc = DB::table('accounts')->where('id', 12)->first();
echo "\n=== Account #12 ===\n";
print_r($acc);

$entries = DB::table('account_entries')->where('account_id', 12)->get();
echo "\n=== Entries for #12 ===\n";
print_r($entries->toArray());
