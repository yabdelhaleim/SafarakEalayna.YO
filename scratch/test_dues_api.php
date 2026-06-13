<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\AccountEntry;
use App\Enums\TransactionModule;

$companies = HajjUmraExecutingCompany::query()
    ->where('is_active', true)
    ->orderBy('name')
    ->get();

$rows = $companies->map(function (HajjUmraExecutingCompany $c) {
    $totals = AccountEntry::query()
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $c->account_id)
        ->where('transactions.module', TransactionModule::HajjUmra->value)
        ->selectRaw('COALESCE(SUM(account_entries.debit), 0) as total_debit, COALESCE(SUM(account_entries.credit), 0) as total_credit')
        ->first();

    $debit = (float) ($totals?->total_debit ?? 0);
    $credit = (float) ($totals?->total_credit ?? 0);
    $netDue = $debit - $credit;

    return [
        'id' => $c->id,
        'name' => $c->name,
        'phone' => $c->phone,
        'account_id' => (int) $c->account_id,
        'total_withdrawn' => $debit,
        'total_repaid' => $credit,
        'net_due' => $netDue,
    ];
})->values();

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
