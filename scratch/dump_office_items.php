<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(\App\Services\Reports\FinancialReportService::class);
$r = $service->getDebtsReport(['department' => 'office']);
echo json_encode($r['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
