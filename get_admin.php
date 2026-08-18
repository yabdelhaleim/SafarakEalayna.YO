<?php
$dbPath = realpath('storage/app/local_flight_audit.sqlite');
putenv('DB_CONNECTION=sqlite'); putenv("DB_DATABASE=$dbPath");
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$userId = DB::table('users')->where('email', 'admin@tx-flight-audit.local')->value('id');
$plain = bin2hex(random_bytes(20));
$hashed = hash('sha256', $plain);
DB::table('personal_access_tokens')->insert([
    'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $userId,
    'name' => 'audit-cli-' . date('YmdHis'), 'token' => $hashed,
    'abilities' => json_encode(['*']),
    'created_at' => now(), 'updated_at' => now(),
]);
echo "$userId|$plain";
