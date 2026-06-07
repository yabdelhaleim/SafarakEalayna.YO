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
    $tx = FawryTransaction::find(7);
    if (! $tx) {
        echo "Transaction #7 not found.\n";

        return;
    }

    // 1. Update Fawry transaction amount paid to 0.00
    $tx->amount = 0.00;
    $tx->save();
    echo "Updated FawryTransaction #7 amount to 0.00.\n";

    // 2. Find payment transaction 1320
    $payTx = Transaction::find(1320);
    if ($payTx) {
        $payTx->notes = 'عكس: '.($payTx->notes ?? 'سداد جزء من عملية فوري');
        $payTx->save();
        echo "Renamed transaction 1320 notes to reversed.\n";
    }

    // 3. Adjust balances
    $acc636 = Account::findOrFail(636); // بنك فوري1 (to_account of 1320)
    $acc151 = Account::findOrFail(151); // حساب العميل (from_account of 1320)

    // Subtract 100 from bank account 636 (it was wrongly paid)
    $acc636->balance = (float) $acc636->balance - 100.00;
    $acc636->save();

    // Add 100 to customer account 151 (it was wrongly paid, so debt increases to 100)
    $acc151->balance = (float) $acc151->balance + 100.00;
    $acc151->save();

    // Create AccountEntry for 636
    AccountEntry::create([
        'account_id' => 636,
        'transaction_id' => 1320,
        'debit' => 100.00,
        'credit' => 0.00,
        'balance_after' => $acc636->balance,
        'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #7',
    ]);

    // Create AccountEntry for 151
    AccountEntry::create([
        'account_id' => 151,
        'transaction_id' => 1320,
        'debit' => 0.00,
        'credit' => 100.00,
        'balance_after' => $acc151->balance,
        'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #7',
    ]);

    echo "Corrected balances:\n";
    echo 'Account 636 (بنك فوري1): '.$acc636->balance."\n";
    echo 'Account 151 (حساب العميل): '.$acc151->balance."\n";
}));
