<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$svc = app(\App\Services\Finance\AccountService::class);
$q = $svc->buildAccountsQuery(['currency' => 'KWD', 'owner_type' => 'office']);
echo "SQL: " . $q->toSql() . PHP_EOL;
echo "Bindings: " . json_encode($q->getBindings()) . PHP_EOL;
echo "Count: " . $q->count() . PHP_EOL;
$rows = $q->get();
foreach ($rows as $r) {
    echo "  id=" . $r->id . ", name=" . $r->name . ", currency=" . $r->currency . ", owner_type=" . $r->owner_type . ", type=" . $r->type . ", module_type=" . $r->module_type . PHP_EOL;
}
