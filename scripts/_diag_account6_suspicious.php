<?php

/**
 * READ-ONLY DEEP DIVE: 5 SUSPICIOUS GENERAL_NO_NOTE entries from Account #6
 * ───────────────────────────────────────────────────────────────────────────
 * For each entry shows:
 *   - Entry info (id, created_at, debit/credit, balance_after, notes)
 *   - Transaction info (id, type, amount, module, from/to accounts with NAMES, created_by, notes, related_id)
 *   - User who created it (with name + email)
 *   - Any REVERSAL entry on the same transaction_id (cancelled?)
 *   - Any DUPLICATE on the same (from, to, amount, day) — possible double-entry
 *   - Audit log entries (using correct schema: model_type/model_id + action/user_id)
 *
 * Usage on server:
 *   cd /var/www/safarakealayna
 *   php _diag_account6_suspicious.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$suspiciousEntryIds = [36, 235, 311, 578, 611];

echo str_repeat('═', 100).PHP_EOL;
echo " DEEP DIVE: 5 SUSPICIOUS 'GENERAL_NO_NOTE' ENTRIES (Account #6 = نقدي درج المكتب)".PHP_EOL;
echo str_repeat('═', 100).PHP_EOL.PHP_EOL;

foreach ($suspiciousEntryIds as $entryId) {
    echo str_repeat('─', 100).PHP_EOL;
    echo " ENTRY #$entryId".PHP_EOL;
    echo str_repeat('─', 100).PHP_EOL;

    try {
        // 1) Get the entry + its transaction + from/to account names + user
        $row = DB::table('account_entries as e')
            ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('e.id', $entryId)
            ->select(
                'e.id as entry_id',
                'e.created_at as entry_at',
                'e.debit',
                'e.credit',
                'e.balance_after',
                'e.notes as entry_notes',
                't.id as txn_id',
                't.created_at as txn_at',
                't.type as txn_type',
                't.amount as txn_amount',
                't.currency as txn_currency',
                't.module as txn_module',
                't.notes as txn_notes',
                't.from_account_id',
                't.to_account_id',
                't.created_by',
                't.related_id',
                't.related_type',
                't.correlation_id',
                't.posting_channel',
                'fa.name as from_account_name',
                'fa.type as from_account_type',
                'ta.name as to_account_name',
                'ta.type as to_account_type',
                'u.name as user_name',
                'u.email as user_email'
            )
            ->first();

        if (! $row) {
            echo "  ❌ Entry #$entryId NOT FOUND!".PHP_EOL.PHP_EOL;

            continue;
        }

        printf("  Entry ID:           %d\n", $row->entry_id);
        printf("  Entry at:           %s\n", $row->entry_at);
        printf("  Debit / Credit:     %s / %s   (Balance After: %s)\n",
            number_format((float) $row->debit, 2),
            number_format((float) $row->credit, 2),
            number_format((float) $row->balance_after, 2));
        printf("  Entry notes:        %s\n", $row->entry_notes ?: '(empty)');

        echo PHP_EOL.'  ── Transaction ─────────────────────────────────────────────'.PHP_EOL;
        printf("  Transaction ID:     %d\n", $row->txn_id);
        printf("  Transaction at:     %s\n", $row->txn_at);
        printf("  Type / Amount:      %s / %s %s\n", $row->txn_type ?? '-',
            number_format((float) $row->txn_amount, 2), $row->txn_currency ?? '');
        printf("  Module:             %s\n", $row->txn_module ?? '-');
        printf("  From Account:       #%d  %s  (type=%s)\n",
            $row->from_account_id ?? 0, $row->from_account_name ?? '(none)',
            $row->from_account_type ?? '-');
        printf("  To Account:         #%d  %s  (type=%s)\n",
            $row->to_account_id ?? 0, $row->to_account_name ?? '(none)',
            $row->to_account_type ?? '-');
        printf("  Transaction notes:  %s\n", $row->txn_notes ?: '(empty)');
        printf("  Related:            type=%s  id=%s\n",
            $row->related_type ?? '-', $row->related_id ?? '-');
        printf("  Correlation ID:     %s\n", $row->correlation_id ?: '-');
        printf("  Posting Channel:    %s\n", $row->posting_channel ?: '-');
        printf("  Created By:         #%d  %s  <%s>\n",
            $row->created_by ?? 0, $row->user_name ?? '(unknown)',
            $row->user_email ?? '-');

        // 2) Check for REVERSAL on the same transaction_id
        echo PHP_EOL.'  ── Reversal / Cancellation Check ──────────────────────────'.PHP_EOL;
        $reversal = DB::table('account_entries')
            ->where('transaction_id', $row->txn_id)
            ->where('id', '!=', $row->entry_id)
            ->get(['id', 'debit', 'credit', 'balance_after', 'notes', 'created_at']);
        if ($reversal->isEmpty()) {
            echo '  ℹ️  No other entries on this transaction_id (no reversal found)'.PHP_EOL;
        } else {
            foreach ($reversal as $rev) {
                printf("  🔁 Entry #%d  Dr=%s  Cr=%s  BalAfter=%s  Notes=%s  At=%s\n",
                    $rev->id,
                    number_format((float) $rev->debit, 2),
                    number_format((float) $rev->credit, 2),
                    number_format((float) $rev->balance_after, 2),
                    $rev->notes ?: '(empty)',
                    $rev->created_at);
            }
        }

        // 3) Check for possible DUPLICATES — same (from, to, amount, module) within +/- 5 minutes
        echo PHP_EOL.'  ── Possible DUPLICATE Check (±5 min) ───────────────────────'.PHP_EOL;
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
                echo '  ℹ️  No similar transactions within ±5 min'.PHP_EOL;
            } else {
                foreach ($dupes as $d) {
                    printf("  ⚠️  Txn #%d  %s  %s/%s  Amt=%s  Notes=%s\n",
                        $d->id, $d->created_at, $d->type ?? '-',
                        number_format((float) $d->amount, 2),
                        $d->notes ?: '(empty)');
                }
            }
        } else {
            echo '  ℹ️  Cannot check duplicates (missing from/to/amount)'.PHP_EOL;
        }

        // 4) Audit Log — uses model_type/model_id + action (NOT event)
        echo PHP_EOL.'  ── Audit Log ───────────────────────────────────────────────'.PHP_EOL;
        if (Schema::hasTable('audit_logs')) {
            try {
                $logs = DB::table('audit_logs')
                    ->where(function ($q) use ($row) {
                        $q->where('model_type', 'transaction')
                            ->where('model_id', $row->txn_id);
                    })
                    ->orWhere(function ($q) use ($row) {
                        $q->where('model_type', 'App\\Models\\Transaction')
                            ->where('model_id', $row->txn_id);
                    })
                    ->orderBy('created_at')
                    ->get(['id', 'action', 'user_id', 'created_at', 'old_values', 'new_values', 'ip_address']);
                if ($logs->isEmpty()) {
                    echo '  ℹ️  No audit_logs entries for this transaction'.PHP_EOL;
                } else {
                    foreach ($logs as $log) {
                        printf("  📋 Audit #%d  Action=%s  User=%d  At=%s  IP=%s\n",
                            $log->id, $log->action ?? '-', $log->user_id ?? 0,
                            $log->created_at, $log->ip_address ?? '-');
                        if ($log->old_values) {
                            $ov = is_string($log->old_values) ? json_decode($log->old_values, true) : $log->old_values;
                            echo '      old: '.json_encode($ov, JSON_UNESCAPED_UNICODE).PHP_EOL;
                        }
                        if ($log->new_values) {
                            $nv = is_string($log->new_values) ? json_decode($log->new_values, true) : $log->new_values;
                            echo '      new: '.json_encode($nv, JSON_UNESCAPED_UNICODE).PHP_EOL;
                        }
                    }
                }
            } catch (Throwable $e) {
                echo '  ⚠️  audit_logs query failed: '.$e->getMessage().PHP_EOL;
            }
        } else {
            echo '  ℹ️  audit_logs table does not exist'.PHP_EOL;
        }

    } catch (Throwable $e) {
        echo "  ❌ ERROR processing entry #$entryId: ".$e->getMessage().PHP_EOL;
    }

    echo PHP_EOL;
}

echo str_repeat('═', 100).PHP_EOL;
echo ' DONE — analyze the output above for each entry.'.PHP_EOL;
echo ' Look for: empty notes, suspicious amounts, missing from/to, reversals, duplicates.'.PHP_EOL;
echo str_repeat('═', 100).PHP_EOL;
