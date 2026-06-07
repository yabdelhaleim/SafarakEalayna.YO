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
    $tx = FawryTransaction::find(8);
    if (! $tx) {
        echo "Transaction #8 not found.\n";

        return;
    }

    // 1. Update Fawry transaction amount paid to 0.00
    $tx->amount = 0.00;
    $tx->save();
    echo "Updated FawryTransaction #8 amount to 0.00.\n";

    // 2. Find payment transaction 1323
    $payTx = Transaction::find(1323);
    if ($payTx) {
        $payTx->notes = 'عكس: '.($payTx->notes ?? 'سداد جزء من عملية فوري');
        $payTx->save();
        echo "Renamed transaction 1323 notes to reversed.\n";
    }

    // 3. Adjust balances
    $acc636 = Account::findOrFail(636); // بنك فوري1 (to_account of 1323)
    $acc630 = Account::findOrFail(630); // حساب العميل يوسف عبد الحليم (from_account of 1323)

    // Subtract 200 from bank account 636
    $acc636->balance = (float) $acc636->balance - 200.00;
    $acc636->save();

    // Add 200 to customer account 630 (representing debt!)
    $acc630->balance = (float) $acc630->balance + 200.00;
    $acc630->save();

    // Create AccountEntry for 636
    AccountEntry::create([
        'account_id' => 636,
        'transaction_id' => 1323,
        'debit' => 200.00,
        'credit' => 0.00,
        'balance_after' => $acc636->balance,
        'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #8',
    ]);

    // Create AccountEntry for 630
    AccountEntry::create([
        'account_id' => 630,
        'transaction_id' => 1323,
        'debit' => 0.00,
        'credit' => 200.00,
        'balance_after' => $acc630->balance,
        'notes' => 'عكس قيد التحصيل الخاطئ للمعاملة #8',
    ]);

    echo "Corrected balances:\n";
    echo 'Account 636 (بنك فوري1): '.$acc636->balance."\n";
    echo 'Account 630 (حساب العميل): '.$acc630->balance."\n";
}));
