<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () {
    // 1. Get accounts
    $acc636 = Account::findOrFail(636); // بنك فوري1
    $acc12 = Account::findOrFail(12);   // حساب المصروفات

    // Adjust balance of account 636 (from_account_id, increase by 50)
    $acc636->balance = (float) $acc636->balance + 50.00;
    $acc636->save();

    // Adjust balance of account 12 (to_account_id, decrease by 50)
    $acc12->balance = (float) $acc12->balance - 50.00;
    $acc12->save();

    // Create AccountEntry for 636
    AccountEntry::create([
        'account_id' => 636,
        'transaction_id' => 1321,
        'debit' => 0.00,
        'credit' => 50.00,
        'balance_after' => $acc636->balance,
        'notes' => 'عكس قيد المصروف الخاطئ للمعاملة #7',
    ]);

    // Create AccountEntry for 12
    AccountEntry::create([
        'account_id' => 12,
        'transaction_id' => 1321,
        'debit' => 50.00,
        'credit' => 0.00,
        'balance_after' => $acc12->balance,
        'notes' => 'عكس قيد المصروف الخاطئ للمعاملة #7',
    ]);

    echo "Corrected balances:\n";
    echo 'Account 636: '.$acc636->balance."\n";
    echo 'Account 12: '.$acc12->balance."\n";
}));
