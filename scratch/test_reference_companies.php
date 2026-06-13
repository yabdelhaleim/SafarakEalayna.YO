<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\HajjUmra\HajjUmraExecutingCompany;

$items = HajjUmraExecutingCompany::active()->orderBy('name')->get()->map(fn ($v) => [
    'id' => $v->id,
    'name' => $v->name,
    'license_number' => $v->license_number,
    'phone' => $v->phone,
]);

echo json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
