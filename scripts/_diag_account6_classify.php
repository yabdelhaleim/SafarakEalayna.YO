<?php

/**
 * READ-ONLY DIAGNOSTIC: Account #6 (نقدي درج المكتب) — Categorized Summary
 * ──────────────────────────────────────────────────────────────────────────
 * Usage (run on the server):
 *   cd /var/www/safarakealayna
 *   php _diag_account6_classify.php
 *
 * NO WRITES — purely SELECT queries.
 */

// Laravel bootstrap (so DB facade and models are available)
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$accountId = 6;
$account = DB::table('accounts')->where('id', $accountId)->first(['id', 'name', 'type', 'currency', 'balance']);
if (! $account) {
    echo "Account #$accountId not found!".PHP_EOL;
    exit(1);
}

echo str_repeat('=', 95).PHP_EOL;
echo " Account #{$account->id} ({$account->name}) — {$account->type} / {$account->currency}".PHP_EOL;
echo " Displayed balance: {$account->balance}".PHP_EOL;
echo str_repeat('=', 95).PHP_EOL;

$rows = DB::table('account_entries as e')
    ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
    ->where('e.account_id', $accountId)
    ->orderBy('e.id')
    ->select(
        'e.id', 'e.created_at', 'e.debit', 'e.credit', 'e.balance_after',
        'e.notes', 't.type', 't.module', 't.amount', 't.notes as txn_notes'
    )
    ->get();

$summary = [];
foreach ($rows as $r) {
    $t = $r->type ?? 'OPEN';
    $m = $r->module ?? '-';
    $note = $r->txn_notes ?? $r->notes ?? '';

    if ($t === 'OPEN') {
        $cat = 'OPENING';
    } elseif (str_contains($note, 'تحصيل دفعة حجز باص') || str_contains($note, 'تذكرة باص')) {
        $cat = 'BUS_BOOKING';
    } elseif (str_contains($note, 'سداد جزء من فوري')
           || str_contains($note, 'تسديد مديونية فوري')
           || str_contains($note, 'سداد جزء من عملية فوري')) {
        $cat = 'FAWRY_IN';
    } elseif (str_contains($note, 'شحن ماكينة')) {
        $cat = 'FAWRY_MACHINE_CHARGE';
    } elseif ($t === 'expense') {
        $cat = 'EXPENSE';
    } elseif (str_contains($note, 'فودافون كاش')) {
        $cat = 'WALLET_VODAFONE_IN';
    } elseif ($m === 'general') {
        $cat = 'GENERAL_NO_NOTE';
    } else {
        $cat = 'OTHER_'.$t;
    }

    if (! isset($summary[$cat])) {
        $summary[$cat] = [
            'count' => 0, 'debit' => 0.0, 'credit' => 0.0,
            'count_debit' => 0, 'count_credit' => 0,
            'examples' => [],
        ];
    }
    $summary[$cat]['count']++;
    $summary[$cat]['debit'] += (float) $r->debit;
    $summary[$cat]['credit'] += (float) $r->credit;
    if ((float) $r->debit > 0) {
        $summary[$cat]['count_debit']++;
    }
    if ((float) $r->credit > 0) {
        $summary[$cat]['count_credit']++;
    }

    if (count($summary[$cat]['examples']) < 3) {
        $summary[$cat]['examples'][] = sprintf(
            '#%d %s | %s%s',
            $r->id,
            substr($r->created_at, 0, 10),
            number_format((float) ($r->debit + $r->credit), 2),
            $note ? ' | '.mb_substr($note, 0, 60) : ''
        );
    }
}

echo PHP_EOL;
echo str_repeat('=', 95).PHP_EOL;
printf("%-30s | %5s | %5s | %5s | %12s | %12s | %12s\n",
    'Category', 'Total', 'Dr#', 'Cr#', 'Debit', 'Credit', 'Net');
echo str_repeat('=', 95).PHP_EOL;
ksort($summary);
foreach ($summary as $cat => $s) {
    printf("%-30s | %5d | %5d | %5d | %12s | %12s | %12s\n",
        $cat, $s['count'], $s['count_debit'], $s['count_credit'],
        number_format($s['debit'], 2),
        number_format($s['credit'], 2),
        number_format($s['credit'] - $s['debit'], 2));
}
echo str_repeat('=', 95).PHP_EOL;
$totalDr = array_sum(array_column($summary, 'debit'));
$totalCr = array_sum(array_column($summary, 'credit'));
echo 'TOTAL:  debit='.number_format($totalDr, 2)
   .' | credit='.number_format($totalCr, 2)
   .' | NET(credit-debit)='.number_format($totalCr - $totalDr, 2).PHP_EOL;
echo 'DISPLAYED: '.$account->balance.PHP_EOL;
echo 'DRIFT:     '.number_format((float) $account->balance - ($totalCr - $totalDr), 2).PHP_EOL;

echo PHP_EOL.str_repeat('=', 95).PHP_EOL;
echo ' EXAMPLES PER CATEGORY (max 3 each)'.PHP_EOL;
echo str_repeat('=', 95).PHP_EOL;
foreach ($summary as $cat => $s) {
    echo "[$cat]".PHP_EOL;
    foreach ($s['examples'] as $ex) {
        echo "   $ex".PHP_EOL;
    }
}
echo str_repeat('=', 95).PHP_EOL;
