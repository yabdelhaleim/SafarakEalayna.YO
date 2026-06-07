<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $tx = FawryTransaction::find(7);
    if (! $tx) {
        echo "Transaction #7 not found.\n";

        return;
    }

    $financeService = app(TransactionService::class);

    if ($tx->expense_transaction_id) {
        $exp = Transaction::find($tx->expense_transaction_id);
        if ($exp) {
            $financeService->reverseTransaction($exp);
            echo 'Reversed/Deleted expense transaction ID: '.$exp->id."\n";
        }
        $tx->expense_transaction_id = null;
        $tx->save();
        echo "Cleared expense_transaction_id on FawryTransaction #7.\n";
    } else {
        echo "No expense transaction linked to #7.\n";
    }
});
