<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Fawry\FawryTransaction;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $tx = FawryTransaction::find(4);
    if (! $tx) {
        echo "Transaction #4 not found.\n";

        return;
    }

    $service = app(FawryTransactionService::class);
    $service->deleteTransaction($tx);
    echo "Deleted/Reversed old transaction #4.\n";

    $newTx = $service->createTransaction([
        'client_id' => 602,
        'client_name' => 'يوسف عبد الحليم',
        'operation_type' => 'withdrawal',
        'client_amount' => 199.99,
        'fawry_price' => 102.30,
        'selling_price' => 199.99,
        'employee_id' => 1,
        'account_id' => 636,
        'fawry_machine_id' => 1,
        'payment_method' => 'cash',
        'amount' => 0.00,
        'reference_number' => '',
        'notes' => 'عملية آجلة بالكامل (تم تصحيحها)',
        'currency_id' => null,
        'payment_details' => [],
    ]);

    echo 'Successfully created replacement transaction ID: '.$newTx->id."\n";
});
