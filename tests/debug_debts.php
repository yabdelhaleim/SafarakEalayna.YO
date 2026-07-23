<?php
chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$result = app(\App\Services\Reports\FinancialReportService::class)->getDebtsReport(['module' => 'wallet']);
echo 'Wallet filter items: ' . count($result['items']) . "\n";
foreach ($result['items'] as $item) {
    echo '  - ' . $item['name'] . ': ' . $item['balance'] . ' (entity=' . $item['entity_type'] . ")\n";
}

echo "\nCustomers with walletTransactions:\n";
foreach (\App\Models\Customer::has('walletTransactions')->get() as $c) {
    $acc = \App\Models\Account::find($c->account_id);
    $balance = $acc?->balance ?? 'no_account';
    echo "  - id={$c->id}, name='{$c->full_name}', account_id=" . ($c->account_id ?? 'null') . ", balance=$balance\n";
}
