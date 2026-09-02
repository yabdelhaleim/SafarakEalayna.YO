<?php

/**
 * READ-ONLY: WHO CREATED entries in accounts #66 (قثص) and #80 (محفظة الكاش)?
 * ───────────────────────────────────────────────────────────────────────────
 * Usage on server:
 *   cd /var/www/safarakealayna
 *   php _diag_accounts_66_80.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$targets = [66, 80];

foreach ($targets as $accountId) {
    $account = DB::table('accounts')->where('id', $accountId)->first(['id', 'name', 'type', 'currency', 'balance']);

    echo PHP_EOL.str_repeat('█', 100).PHP_EOL;
    echo "█  ACCOUNT #{$account->id} ({$account->name}) — {$account->type} / {$account->currency}".str_repeat(' ', 100 - strlen("  ACCOUNT #{$account->id} ({$account->name}) — {$account->type} / {$account->currency}") - 2).'█'.PHP_EOL;
    echo "█  Displayed: {$account->balance}".str_repeat(' ', 100 - strlen("  Displayed: {$account->balance}") - 2).'█'.PHP_EOL;
    echo str_repeat('█', 100).PHP_EOL.PHP_EOL;

    $rows = DB::table('account_entries as e')
        ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
        ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
        ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
        ->leftJoin('users as u', 'u.id', '=', 't.created_by')
        ->where('e.account_id', $accountId)
        ->orderBy('e.id')
        ->select(
            'e.id as entry_id', 'e.created_at as entry_at',
            'e.debit', 'e.credit', 'e.balance_after', 'e.notes as entry_notes',
            't.id as txn_id', 't.type as txn_type', 't.amount as txn_amount',
            't.module as txn_module', 't.notes as txn_notes',
            't.from_account_id', 't.to_account_id',
            'fa.name as from_account_name', 'fa.type as from_account_type',
            'ta.name as to_account_name', 'ta.type as to_account_type',
            'u.id as user_id', 'u.name as user_name', 'u.email as user_email',
            't.client_ip', 't.posting_channel', 't.correlation_id', 't.created_at as txn_at'
        )->get();

    if ($rows->isEmpty()) {
        echo "  ℹ️  No entries found for account #$accountId.".PHP_EOL;
        echo '  This account has NEVER been touched in the ledger.'.PHP_EOL;

        // Check when account was created
        $created = DB::table('accounts')->where('id', $accountId)->value('created_at');
        echo "  Account was created at: $created".PHP_EOL;

        continue;
    }

    echo '  Found '.count($rows).' entries:'.PHP_EOL.PHP_EOL;

    foreach ($rows as $r) {
        echo str_repeat('─', 100).PHP_EOL;
        echo "  ENTRY #{$r->entry_id}".PHP_EOL;
        echo str_repeat('─', 100).PHP_EOL;
        printf("    Date:                %s\n", $r->entry_at);
        printf("    Debit / Credit:      %s / %s   (Balance After: %s)\n",
            number_format((float) $r->debit, 2),
            number_format((float) $r->credit, 2),
            number_format((float) $r->balance_after, 2));
        printf("    Entry notes:         %s\n", $r->entry_notes ?: '(empty)');
        echo PHP_EOL;
        printf("    Transaction ID:      %d   Type: %s   Amount: %s\n",
            $r->txn_id, $r->txn_type, number_format((float) $r->txn_amount, 2));
        printf("    Module:              %s\n", $r->txn_module ?? '-');
        printf("    Txn notes:           %s\n", $r->txn_notes ?: '(empty)');
        printf("    From:                #%d  %s  (type=%s)\n",
            $r->from_account_id ?? 0, $r->from_account_name ?? '(none)', $r->from_account_type ?? '-');
        printf("    To:                  #%d  %s  (type=%s)\n",
            $r->to_account_id ?? 0, $r->to_account_name ?? '(none)', $r->to_account_type ?? '-');
        echo PHP_EOL;
        printf("    👤 Created By:       #%d  %s  <%s>\n",
            $r->user_id ?? 0, $r->user_name ?? '(unknown)', $r->user_email ?? '-');
        printf("    🌐 Client IP:        %s\n", $r->client_ip ?? '-');
        printf("    📡 Posting Channel:  %s\n", $r->posting_channel ?? '-');
        printf("    🔗 Correlation ID:   %s\n", $r->correlation_id ?: '-');
        printf("    🕐 Txn At:           %s\n", $r->txn_at);
    }

    // Summary
    echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
    echo '  SUMMARY:'.PHP_EOL;

    $userCounts = DB::table('account_entries as e')
        ->join('transactions as t', 't.id', '=', 'e.transaction_id')
        ->join('users as u', 'u.id', '=', 't.created_by')
        ->where('e.account_id', $accountId)
        ->groupBy('u.id', 'u.name', 'u.email')
        ->select('u.id', 'u.name', 'u.email', DB::raw('COUNT(*) as cnt'))
        ->orderByDesc('cnt')
        ->get();

    foreach ($userCounts as $u) {
        echo "    👤 User #{$u->id} {$u->name} <{$u->email}>: {$u->cnt} entries".PHP_EOL;
    }
}

echo PHP_EOL.str_repeat('═', 100).PHP_EOL;
echo ' DONE.'.PHP_EOL;
echo str_repeat('═', 100).PHP_EOL;
