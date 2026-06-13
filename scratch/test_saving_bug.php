<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Program;

$program = Program::find(5);
$program->executing_company_id = 1;
$program->save();

echo "Database restored. Program ID 5 executing_company_id = " . Program::find(5)->executing_company_id . "\n";
