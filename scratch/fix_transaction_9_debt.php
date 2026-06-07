<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () {
    $tx = FawryTransaction::find(9);
    if (! $tx) {
        echo "Transaction #9 not found.\n";

        return;
    }

    // 1. Update Fawry transaction amount paid to 0.00
    $tx->amount = 0.00;
    $tx->save();
    echo "Updated FawryTransaction #9 amount to 0.00.\n";

    // 2. Find payment transaction (should be 1323 + 1, which is 1324 or 1325)
    // Let's find any transfer transaction from 630 to 636 associated with FawryTransaction 9
    $payTx = Transaction::where('related_type', FawryTransaction::class)
        ->where('related_id', 9)
        ->where('type', 'transfer')
        ->where('from_account_id', 630)
        ->where('to_account_id', 636)
        ->first();

    if ($payTx) {
        $payTx->notes = 'عكس: '.($payTx->notes ?? 'سداد جزء من عملية فوري');
        $payTx->save();
        echo 'Renamed payment transaction ID '.$payTx->id." notes to reversed.\n";

        // Adjust balances
        $acc636 = Account::findOrFail(636); // بنك فوري1 (to_account of payTx)
        $acc630 = Account::findOrFail(630); // حساب العميل يوسف عبد الحليم (from_account of payTx)

        // Subtract 30 from bank account 636
        $acc636->balance = (float) $acc636->balance - 30.00;
        $acc636->save();

        // Add 30 to customer account 630 (representing debt!)
        $acc630->balance = (float) $acc630->balance + 30.00;
        $acc630->save();

        // Create AccountEntry for 636
        AccountEntry::create([
            'account_id' => 636,
            'transaction_id' => $payTx->id,
            'debit' => 30.00,
            'credit' => 0.00,
            'balance_after' => $acc636->balance,
            'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #9',
        ]);

        // Create AccountEntry for 630
        AccountEntry::create([
            'account_id' => 630,
            'transaction_id' => $payTx->id,
            'debit' => 0.00,
            'credit' => 30.00,
            'balance_after' => $acc630->balance,
            'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #9',
        ]);

        echo "Corrected balances:\n";
        echo 'Account 636 (بنك فوري1): '.$acc636->balance."\n";
        echo 'Account 630 (حساب العميل): '.$acc630->balance."\n";
    } else {
        echo "No payment transaction found for transaction #9.\n";
    }
}));
