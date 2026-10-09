<?php

/**
 * PARAMETERIZED FULL DIAGNOSTIC for any account
 * ──────────────────────────────────────────────
 * Usage on server:
 *   cd /var/www/safarakealayna
 *   php _diag_account.php 8           # for account #8
 *   php _diag_account.php 5           # for account #5
 *   php _diag_account.php 66          # for account #66
 *   php _diag_account.php 80          # for account #80
 *   php _diag_account.php             # (no arg) → run on ALL office liquidity accounts
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Resolve which accounts to process
$requested = $argv[1] ?? null;

if ($requested === null) {
    // No arg: run on all office liquidity accounts
    $accounts = DB::table('accounts')
        ->whereIn('type', ['cashbox', 'bank', 'wallet'])
        ->where('module_type', 'office')
        ->whereNull('deleted_at')
        ->orderByRaw("FIELD(type,'cashbox','bank','wallet'), id")
        ->get(['id', 'name', 'type', 'currency', 'balance']);
} else {
    $accountId = (int) $requested;
    $row = DB::table('accounts')->where('id', $accountId)->first(['id', 'name', 'type', 'currency', 'balance']);
    if (! $row) {
        echo "Account #$accountId not found!".PHP_EOL;
        exit(1);
    }
    $accounts = collect([$row]);
}

foreach ($accounts as $account) {
    $accountId = $account->id;
    echo PHP_EOL.PHP_EOL;
    echo str_repeat('█', 100).PHP_EOL;
    echo '█'.str_pad("  ACCOUNT #{$account->id} ({$account->name}) — {$account->type} / {$account->currency}  ", 98, ' ', STR_PAD_RIGHT).'█'.PHP_EOL;
    echo '█'.str_pad("  Displayed: {$account->balance}", 98, ' ', STR_PAD_RIGHT).'█'.PHP_EOL;
    echo str_repeat('█', 100).PHP_EOL.PHP_EOL;

    // ============== PHASE 1: CATEGORIZATION ==============
    echo str_repeat('═', 100).PHP_EOL;
    echo ' PHASE 1 — CATEGORIZED SUMMARY'.PHP_EOL;
    echo str_repeat('═', 100).PHP_EOL;

    $rows = DB::table('account_entries as e')
        ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
        ->where('e.account_id', $accountId)
        ->orderBy('e.id')
        ->select(
            'e.id', 'e.created_at', 'e.debit', 'e.credit', 'e.balance_after',
            'e.notes', 't.type', 't.module', 't.amount', 't.notes as txn_notes'
        )->get();

    $summary = [];
    $suspicious = [];
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
        } elseif ($m === 'general' && empty($note)) {
            $cat = 'GENERAL_NO_NOTE';
            $suspicious[] = $r->id;
        } elseif ($m === 'general') {
            $cat = 'GENERAL_WITH_NOTE';
        } else {
            $cat = 'OTHER_'.$t;
        }

        if (! isset($summary[$cat])) {
            $summary[$cat] = ['count' => 0, 'debit' => 0.0, 'credit' => 0.0, 'count_debit' => 0, 'count_credit' => 0, 'examples' => []];
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
                $r->id, substr($r->created_at, 0, 10),
                number_format((float) ($r->debit + $r->credit), 2),
                $note ? ' | '.mb_substr($note, 0, 60) : ''
            );
        }
    }

    printf("%-30s | %5s | %5s | %5s | %12s | %12s | %12s\n",
        'Category', 'Total', 'Dr#', 'Cr#', 'Debit', 'Credit', 'Net');
    echo str_repeat('─', 100).PHP_EOL;
    ksort($summary);
    foreach ($summary as $cat => $s) {
        printf("%-30s | %5d | %5d | %5d | %12s | %12s | %12s\n",
            $cat, $s['count'], $s['count_debit'], $s['count_credit'],
            number_format($s['debit'], 2), number_format($s['credit'], 2),
            number_format($s['credit'] - $s['debit'], 2));
    }
    echo str_repeat('─', 100).PHP_EOL;
    $totalDr = array_sum(array_column($summary, 'debit'));
    $totalCr = array_sum(array_column($summary, 'credit'));
    echo 'TOTAL: debit='.number_format($totalDr, 2)
       .' | credit='.number_format($totalCr, 2)
       .' | NET(credit-debit)='.number_format($totalCr - $totalDr, 2).PHP_EOL;
    echo 'DISPLAYED: '.$account->balance.PHP_EOL;
    echo 'DRIFT:     '.number_format((float) $account->balance - ($totalCr - $totalDr), 2).PHP_EOL;

    echo PHP_EOL.str_repeat('═', 100).PHP_EOL;
    echo ' EXAMPLES PER CATEGORY (max 3 each)'.PHP_EOL;
    echo str_repeat('═', 100).PHP_EOL;
    foreach ($summary as $cat => $s) {
        echo "[$cat]".PHP_EOL;
        foreach ($s['examples'] as $ex) {
            echo "   $ex".PHP_EOL;
        }
    }

    // ============== PHASE 2: DEEP DIVE ==============
    if (empty($suspicious)) {
        echo PHP_EOL.str_repeat('═', 100).PHP_EOL;
        echo ' ✅ NO SUSPICIOUS ENTRIES (no entries with module=general + empty notes)'.PHP_EOL;
        echo str_repeat('═', 100).PHP_EOL;

        continue;
    }

    echo PHP_EOL.PHP_EOL;
    echo str_repeat('═', 100).PHP_EOL;
    echo ' PHASE 2 — DEEP DIVE ON '.count($suspicious).' SUSPICIOUS ENTRIES'.PHP_EOL;
    echo str_repeat('═', 100).PHP_EOL.PHP_EOL;

    foreach ($suspicious as $entryId) {
        echo str_repeat('─', 100).PHP_EOL;
        echo " ENTRY #$entryId".PHP_EOL;
        echo str_repeat('─', 100).PHP_EOL;

        try {
            $row = DB::table('account_entries as e')
                ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
                ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
                ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
                ->leftJoin('users as u', 'u.id', '=', 't.created_by')
                ->where('e.id', $entryId)
                ->select(
                    'e.id as entry_id', 'e.created_at as entry_at',
                    'e.debit', 'e.credit', 'e.balance_after', 'e.notes as entry_notes',
                    't.id as txn_id', 't.created_at as txn_at',
                    't.type as txn_type', 't.amount as txn_amount', 't.currency as txn_currency',
                    't.module as txn_module', 't.notes as txn_notes',
                    't.from_account_id', 't.to_account_id', 't.created_by',
                    't.related_id', 't.related_type', 't.correlation_id', 't.posting_channel',
                    'fa.name as from_account_name', 'fa.type as from_account_type',
                    'ta.name as to_account_name', 'ta.type as to_account_type',
                    'u.name as user_name', 'u.email as user_email'
                )->first();

            if (! $row) {
                continue;
            }

            printf("  Entry ID:           %d\n", $row->entry_id);
            printf("  Entry at:           %s\n", $row->entry_at);
            printf("  Debit / Credit:     %s / %s   (Balance After: %s)\n",
                number_format((float) $row->debit, 2), number_format((float) $row->credit, 2),
                number_format((float) $row->balance_after, 2));
            printf("  Entry notes:        %s\n", $row->entry_notes ?: '(empty)');

            echo PHP_EOL.'  ── Transaction ─────────────────────────────────────────────'.PHP_EOL;
            printf("  Transaction ID:     %d\n", $row->txn_id);
            printf("  Transaction at:     %s\n", $row->txn_at);
            printf("  Type / Amount:      %s / %s %s\n", $row->txn_type ?? '-',
                number_format((float) $row->txn_amount, 2), $row->txn_currency ?? '');
            printf("  Module:             %s\n", $row->txn_module ?? '-');
            printf("  From Account:       #%d  %s  (type=%s)\n",
                $row->from_account_id ?? 0, $row->from_account_name ?? '(none)', $row->from_account_type ?? '-');
            printf("  To Account:         #%d  %s  (type=%s)\n",
                $row->to_account_id ?? 0, $row->to_account_name ?? '(none)', $row->to_account_type ?? '-');
            printf("  Transaction notes:  %s\n", $row->txn_notes ?: '(empty)');
            printf("  Related:            type=%s  id=%s\n", $row->related_type ?? '-', $row->related_id ?? '-');
            printf("  Correlation ID:     %s\n", $row->correlation_id ?: '-');
            printf("  Posting Channel:    %s\n", $row->posting_channel ?: '-');
            printf("  Created By:         #%d  %s  <%s>\n",
                $row->created_by ?? 0, $row->user_name ?? '(unknown)', $row->user_email ?? '-');

            echo PHP_EOL.'  ── Reversal Check ──────────────────────────────────────────'.PHP_EOL;
            $rev = DB::table('account_entries')
                ->where('transaction_id', $row->txn_id)
                ->where('id', '!=', $row->entry_id)
                ->get(['id', 'debit', 'credit', 'balance_after', 'notes', 'created_at']);
            if ($rev->isEmpty()) {
                echo '  ℹ️  No reversal'.PHP_EOL;
            } else {
                foreach ($rev as $r) {
                    printf("  🔁 Entry #%d  Dr=%s  Cr=%s  BalAfter=%s  Notes=%s  At=%s\n",
                        $r->id, number_format((float) $r->debit, 2), number_format((float) $r->credit, 2),
                        number_format((float) $r->balance_after, 2), $r->notes ?: '(empty)', $r->created_at);
                }
            }

            echo PHP_EOL.'  ── Duplicate Check (±5 min) ─────────────────────────────────'.PHP_EOL;
            if ($row->from_account_id && $row->to_account_id && (float) $row->txn_amount > 0) {
                $dupes = DB::table('transactions')
                    ->where('id', '!=', $row->txn_id)
                    ->where('from_account_id', $row->from_account_id)
                    ->where('to_account_id', $row->to_account_id)
                    ->where('amount', $row->txn_amount)
                    ->where('module', $row->txn_module)
                    ->whereBetween('created_at', [
                        date('Y-m-d H:i:s', strtotime($row->txn_at.' -5 minutes')),
                        date('Y-m-d H:i:s', strtotime($row->txn_at.' +5 minutes')),
                    ])
                    ->get(['id', 'created_at', 'type', 'amount', 'notes']);
                if ($dupes->isEmpty()) {
                    echo '  ℹ️  No duplicates within ±5 min'.PHP_EOL;
                } else {
                    foreach ($dupes as $d) {
                        printf("  ⚠️  Txn #%d  %s  %s/%s  Amt=%s  Notes=%s\n",
                            $d->id, $d->created_at, $d->type ?? '-',
                            number_format((float) $d->amount, 2), $d->notes ?: '(empty)');
                    }
                }
            } else {
                echo '  ℹ️  Cannot check duplicates'.PHP_EOL;
            }

            echo PHP_EOL.'  ── Audit Log ───────────────────────────────────────────────'.PHP_EOL;
            if (Schema::hasTable('audit_logs')) {
                try {
                    $logs = DB::table('audit_logs')
                        ->where(function ($q) use ($row) {
                            $q->where('model_type', 'transaction')->where('model_id', $row->txn_id);
                        })
                        ->orWhere(function ($q) use ($row) {
                            $q->where('model_type', 'App\\Models\\Transaction')->where('model_id', $row->txn_id);
                        })
                        ->orderBy('created_at')
                        ->get(['id', 'action', 'user_id', 'created_at', 'ip_address']);
                    if ($logs->isEmpty()) {
                        echo '  ℹ️  No audit_logs entries'.PHP_EOL;
                    } else {
                        foreach ($logs as $log) {
                            printf("  📋 Audit #%d  Action=%s  User=%d  At=%s  IP=%s\n",
                                $log->id, $log->action ?? '-', $log->user_id ?? 0,
                                $log->created_at, $log->ip_address ?? '-');
                        }
                    }
                } catch (Throwable $e) {
                    echo '  ⚠️  audit_logs query failed: '.$e->getMessage().PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            echo '  ❌ ERROR: '.$e->getMessage().PHP_EOL;
        }

        echo PHP_EOL;
    }

    echo str_repeat('═', 100).PHP_EOL;
    echo " DONE for Account #{$account->id} ({$account->name})".PHP_EOL;
    echo str_repeat('═', 100).PHP_EOL;
}

if ($accounts->count() > 1) {
    echo PHP_EOL.PHP_EOL;
    echo " ALL ACCOUNTS PROCESSED: {$accounts->count()}".PHP_EOL;
}
