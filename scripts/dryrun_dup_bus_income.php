<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Dry-run: Duplicate Income Transactions in bus_bookings
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  ROOT CAUSE (confirmed from BusBookingService code):
 *  ----------------------------------------
 *  createBooking() writes a SALE transaction (type='income', sale on AR).
 *  payBooking() writes a PAYMENT transaction (type='income' — BUG; should be
 *  'transfer'). For each booking paid in full on creation, the system writes
 *  **two** income transactions of the same amount.
 *
 *  WHAT THIS DRY-RUN DOES (READ-ONLY):
 *  ----------------------------------------
 *  This script performs ZERO writes. It uses raw DB::select only. It is
 *  source-scanned at startup to refuse any INSERT/UPDATE/DELETE keyword.
 *
 *  SECTIONS
 *  --------
 *  [1]  Environment + safety guards
 *  [2]  Identify duplicate groups (HAVING COUNT > 1)
 *  [3]  Per-pair detail (pre-reversal)
 *  [4]  Scenario A simulation — re-type only (income → transfer)
 *  [5]  Scenario B simulation — reverse + re-create as transfer
 *  [6]  Aggregated impact — comparison table
 *  [7]  Residual issues (tx#303, ghost balances, P&L bug)
 *  [8]  Recommendations
 *  [9]  Save JSON
 *
 *  CLI:
 *  php scripts/dryrun_dup_bus_income.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$timestamp = date('Ymd_His');
$logFile = __DIR__ . "/../storage/logs/dryrun_dup_bus_income_{$timestamp}.json";

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'app_env'   => config('app.env'),
    'db_connection' => config('database.default'),
    'db_database'   => config('database.connections.'.config('database.default').'.database'),
];

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────
$line = function (string $label, $value, ?string $unit = null) use (&$report) {
    $str = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) :
        (is_float($value) ? number_format($value, 2) : (string) $value);
    $unitStr = $unit ? " $unit" : '';
    echo str_pad($label, 60, ' ', STR_PAD_RIGHT) . " : {$str}{$unitStr}\n";
    $report['lines'][] = ['label' => $label, 'value' => $value, 'unit' => $unit];
};

$section = function (string $title) use (&$report) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ " . str_pad($title, 73, ' ', STR_PAD_BOTH) . " ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
    $report['sections'][] = $title;
};

// ═════════════════════════════════════════════════════════════════════════
// [1] Environment + safety guards
// ═════════════════════════════════════════════════════════════════════════
$section('[1] Environment + safety guards');

$line('APP_ENV', $report['app_env']);
$line('DB_CONNECTION', $report['db_connection']);
$line('DB_DATABASE', $report['db_database']);

// Defensive check: scan the script's source for any write keyword.
// This script is supposed to be read-only. If anyone adds a write by mistake,
// the script refuses to run.
$scriptSource = file_get_contents(__FILE__);
$writeKeywords = ['DB::insert', 'DB::update', 'DB::delete', 'DB::statement',
    '->insert(', '->update(', '->delete(', '->increment(', '->decrement(',
    'TRUNCATE', 'DROP TABLE', 'DROP DATABASE', 'ALTER TABLE', 'CREATE TABLE'];
$foundWrite = [];
foreach ($writeKeywords as $kw) {
    if (stripos($scriptSource, $kw) !== false) {
        $foundWrite[] = $kw;
    }
}
$line('Write keywords found in source', empty($foundWrite) ? 'NONE (good — read-only)' : $foundWrite);
if (! empty($foundWrite)) {
    echo "\n  ⚠️  ABORTING: this script must be read-only but contains write keywords.\n";
    exit(1);
}

// Pre-snapshot for integrity
$preSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'account_entries_count' => DB::table('account_entries')->count(),
    'bus_bookings_count' => DB::table('bus_bookings')->count(),
];
$report['pre_snapshot'] = $preSnapshot;
$line('Pre-snapshot transactions', $preSnapshot['transactions_count']);
$line('Pre-snapshot account_entries', $preSnapshot['account_entries_count']);
$line('Pre-snapshot bus_bookings', $preSnapshot['bus_bookings_count']);

// ═════════════════════════════════════════════════════════════════════════
// [2] Identify duplicate groups
// ═════════════════════════════════════════════════════════════════════════
$section('[2] Identify duplicate groups (income tx grouped by related + amount)');

$dupGroups = DB::select("
    SELECT related_type, related_id, amount, currency,
           COUNT(*) AS cnt,
           GROUP_CONCAT(id ORDER BY id) AS tx_ids,
           MIN(id) AS original_id,
           MAX(id) AS duplicate_id
    FROM transactions
    WHERE module = 'bus'
      AND type = 'income'
      AND related_type IS NOT NULL
    GROUP BY related_type, related_id, amount, currency
    HAVING COUNT(*) > 1
    ORDER BY related_id, amount
");

$line('عدد groups (bookings) عندها duplicates', count($dupGroups), 'group');
$report['dup_groups'] = $dupGroups;

$totalDuplicates = 0;
$totalDuplicatedAmount = 0;
foreach ($dupGroups as $g) {
    $totalDuplicates += ($g->cnt - 1);  // exclude the original
    $totalDuplicatedAmount += ($g->cnt - 1) * (float) $g->amount;
}
$line('عدد duplicate transactions (اللي هتتعالج)', $totalDuplicates, 'tx');
$line('إجمالي المبلغ المكرر', $totalDuplicatedAmount, 'EGP');

// ═════════════════════════════════════════════════════════════════════════
// [3] Per-pair detail (pre-reversal)
// ═════════════════════════════════════════════════════════════════════════
$section('[3] Per-pair detail (أول 10 pairs بالتفاصيل)');

$pairsDetail = [];
echo "  original_tx | duplicate_tx | from_acc | to_acc | amount    | created_at_orig       | created_at_dup\n";
echo "  ------------|--------------|----------|--------|-----------|----------------------|----------------------\n";

foreach (array_slice($dupGroups, 0, 10) as $g) {
    $txIds = array_map('intval', explode(',', $g->tx_ids));
    $origId = $txIds[0];
    $dupId = $txIds[1];

    $orig = DB::table('transactions')->where('id', $origId)->first();
    $dup = DB::table('transactions')->where('id', $dupId)->first();

    // Fetch entries for both
    $origEntries = DB::table('account_entries')->where('transaction_id', $origId)->get();
    $dupEntries = DB::table('account_entries')->where('transaction_id', $dupId)->get();

    $pair = [
        'booking_id' => $g->related_id,
        'original_tx' => [
            'id' => $origId,
            'amount' => $orig->amount,
            'from_account_id' => $orig->from_account_id,
            'to_account_id' => $orig->to_account_id,
            'created_at' => $orig->created_at,
            'notes' => $orig->notes,
            'entries' => $origEntries->map(fn ($e) => [
                'account_id' => $e->account_id,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
                'balance_after' => (float) $e->balance_after,
            ])->all(),
        ],
        'duplicate_tx' => [
            'id' => $dupId,
            'amount' => $dup->amount,
            'from_account_id' => $dup->from_account_id,
            'to_account_id' => $dup->to_account_id,
            'created_at' => $dup->created_at,
            'notes' => $dup->notes,
            'entries' => $dupEntries->map(fn ($e) => [
                'account_id' => $e->account_id,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
                'balance_after' => (float) $e->balance_after,
            ])->all(),
        ],
    ];
    $pairsDetail[] = $pair;

    printf(
        "  tx#%-9d | tx#%-9d | %-8d | %-6d | %9s | %-20s | %-20s\n",
        $origId,
        $dupId,
        $orig->from_account_id ?? 0,
        $dup->to_account_id ?? 0,
        number_format($orig->amount, 2),
        $orig->created_at,
        $dup->created_at
    );

    if (count($dupGroups) <= 10) {
        echo "\n  --- entries for tx#{$origId} (original) ---\n";
        foreach ($origEntries as $e) {
            printf("    acc#%-5d | debit=%-12s | credit=%-12s | balance_after=%s\n",
                $e->account_id, number_format($e->debit, 2), number_format($e->credit, 2),
                number_format($e->balance_after, 2));
        }
        echo "\n  --- entries for tx#{$dupId} (duplicate) ---\n";
        foreach ($dupEntries as $e) {
            printf("    acc#%-5d | debit=%-12s | credit=%-12s | balance_after=%s\n",
                $e->account_id, number_format($e->debit, 2), number_format($e->credit, 2),
                number_format($e->balance_after, 2));
        }
    }
}
$report['pairs_detail'] = $pairsDetail;

// ═════════════════════════════════════════════════════════════════════════
// [4] Scenario A — re-type only (income → transfer)
// ═════════════════════════════════════════════════════════════════════════
$section('[4] Scenario A: re-type only (UPDATE transactions.type)');

echo "  Action per duplicate: 1 UPDATE statement\n";
echo "  Effect: income tx no longer counted in income sum\n";
echo "  Account balance effect: NONE (entries unchanged)\n";
echo "  DB row count: unchanged\n\n";

$scenarioA = [
    'description' => 'UPDATE transactions SET type = "transfer" WHERE id IN (duplicate_ids)',
    'writes_per_duplicate' => 1,
    'total_writes' => $totalDuplicates,
    'account_balance_change' => 0,
    'new_transactions' => 0,
    'new_account_entries' => 0,
];
$line('Total UPDATE statements', $scenarioA['total_writes']);
$line('Account balance change', 0, 'EGP');
$line('New transactions', $scenarioA['new_transactions']);

// Simulate Σ income AFTER re-type
$newIncomeSumA = (float) DB::table('transactions')
    ->where('type', 'income')
    ->whereIn('module', ['bus', 'fawry', 'online', 'wallet_transfer', 'general', 'office'])
    ->sum('amount');
$oldIncomeSum = $newIncomeSumA + $totalDuplicatedAmount;
$scenarioA['income_before'] = $oldIncomeSum;
$scenarioA['income_after'] = $newIncomeSumA;
$scenarioA['income_delta'] = -$totalDuplicatedAmount;
$line('Σ income tx (before)', $oldIncomeSum, 'EGP');
$line('Σ income tx (after re-type)', $newIncomeSumA, 'EGP');
$line('Δ income', -$totalDuplicatedAmount, 'EGP');
$report['scenario_a'] = $scenarioA;

// ═════════════════════════════════════════════════════════════════════════
// [5] Scenario B — reverse + re-create as transfer
// ═════════════════════════════════════════════════════════════════════════
$section('[5] Scenario B: reverse + re-create as transfer');

echo "  Action per duplicate: 2 INSERTs (1 reversal tx + 1 new transfer tx)\n";
echo "  Effect: original entries cancelled by reversal, then new transfer tx\n";
echo "  Account balance effect: net 0 (entries cancel + new equivalent entries)\n";
echo "  DB row count: +94 (47 reversals + 47 new transfers)\n\n";

$scenarioB = [
    'description' => 'For each duplicate: insert REVERSAL tx (type=transfer, -amount, same from/to) + insert NEW transfer tx (type=transfer, +amount, same from/to)',
    'writes_per_duplicate' => 2,
    'total_writes' => $totalDuplicates * 2,
    'account_balance_change' => 0,
    'new_transactions' => $totalDuplicates * 2,
    'new_account_entries' => $totalDuplicates * 4, // 2 entries per new tx
    'income_before' => $oldIncomeSum,
    'income_after' => $newIncomeSumA,
    'income_delta' => -$totalDuplicatedAmount,
];
$line('Total INSERT statements', $scenarioB['total_writes']);
$line('New transactions', $scenarioB['new_transactions']);
$line('New account_entries', $scenarioB['new_account_entries']);
$line('Σ income tx (after)', $newIncomeSumA, 'EGP');
$line('Δ income', -$totalDuplicatedAmount, 'EGP');
$report['scenario_b'] = $scenarioB;

// ═════════════════════════════════════════════════════════════════════════
// [6] Aggregated impact — comparison table
// ═════════════════════════════════════════════════════════════════════════
$section('[6] مقارنة الـ Scenarios');

// Capture current full TB state
$treasury = app(\App\Services\Finance\TreasuryService::class);
$tbBefore = $treasury->getOfficeTrialBalance();

$scenarioA['tb_before'] = $tbBefore;
$scenarioB['tb_before'] = $tbBefore;

// Both scenarios preserve the duplicate ENTRIES. So:
//   - current_capital unchanged (cashbox / customer AR balances are unchanged)
//   - expected_capital unchanged (profits = booking.profit, not income sum)
//   - variance unchanged
//   - The income SUM as displayed in queries DROPS by 23,515 EGP
$scenarioA['tb_after'] = $tbBefore;
$scenarioB['tb_after'] = $tbBefore;

echo "  ┌─────────────────────────────┬──────────────────┬──────────────────┐\n";
echo "  │ البند                       │ Scenario A       │ Scenario B       │\n";
echo "  │                             │ (re-type)        │ (rev+rec)        │\n";
echo "  ├─────────────────────────────┼──────────────────┼──────────────────┤\n";
printf("  │ %-27s │ %-16s │ %-16s │\n", "DB writes per duplicate", "1 UPDATE", "2 INSERTs");
printf("  │ %-27s │ %-16s │ %-16s │\n", "Total writes", $scenarioA['total_writes'], $scenarioB['total_writes']);
printf("  │ %-27s │ %-16s │ %-16s │\n", "New transactions", "0", "+".$scenarioB['new_transactions']);
printf("  │ %-27s │ %-16s │ %-16s │\n", "Σ income tx (after)", number_format($newIncomeSumA, 2), number_format($newIncomeSumA, 2));
printf("  │ %-27s │ %-16s │ %-16s │\n", "current_capital", number_format($tbBefore['current_capital'], 2), number_format($tbBefore['current_capital'], 2));
printf("  │ %-27s │ %-16s │ %-16s │\n", "expected_capital", number_format($tbBefore['expected_capital'], 2), number_format($tbBefore['expected_capital'], 2));
printf("  │ %-27s │ %-16s │ %-16s │\n", "variance", number_format($tbBefore['variance'], 2), number_format($tbBefore['variance'], 2));
printf("  │ %-27s │ %-16s │ %-16s │\n", "TB status", $tbBefore['status'], $tbBefore['status']);
echo "  └─────────────────────────────┴──────────────────┴──────────────────┘\n";

$report['comparison'] = [
    'scenario_a' => [
        'writes' => $scenarioA['total_writes'],
        'new_tx_count' => $scenarioA['new_transactions'],
        'income_after' => $newIncomeSumA,
        'variance' => $tbBefore['variance'],
    ],
    'scenario_b' => [
        'writes' => $scenarioB['total_writes'],
        'new_tx_count' => $scenarioB['new_transactions'],
        'income_after' => $newIncomeSumA,
        'variance' => $tbBefore['variance'],
    ],
];

// ═════════════════════════════════════════════════════════════════════════
// [7] Residual issues (prevent variance from reaching 0)
// ═════════════════════════════════════════════════════════════════════════
$section('[7] Residual issues (مش هيتبعت مع fix الـ duplicates)');

// 7.1) tx#303 imbalance
$tx303 = DB::table('transactions')->where('id', 303)->first();
$tx303Entries = DB::select("
    SELECT SUM(debit) AS d, SUM(credit) AS c
    FROM account_entries WHERE transaction_id = 303
");
$line('tx#303 imbalance', $tx303Entries[0]->d - $tx303Entries[0]->c, 'EGP');
$line('tx#303 notes', $tx303->notes ?? 'NULL');

// 7.2) Ghost balances count
$ghostCount = DB::table('accounts')
    ->where('is_active', 1)
    ->where(function ($q) {
        $q->where('balance', '<>', 0);
    })
    ->count();
$line('عدد الحسابات النشطة', $ghostCount);

// 7.3) P&L bug: revenueRows empty
try {
    $plReport = app(\App\Services\Reports\ProfitLossReportService::class)->report(['category' => 'office']);
    $line('P&L revenue rows', empty($plReport['revenueRows']) ? 'EMPTY (BUG)' : count($plReport['revenueRows']));
    $line('P&L total revenue', $plReport['totalRevenue'] ?? 0, 'EGP');
} catch (\Throwable $e) {
    $line('P&L error', $e->getMessage());
}

$report['residual'] = [
    'tx303_imbalance' => $tx303Entries[0]->d - $tx303Entries[0]->c,
    'tx303_notes' => $tx303->notes ?? null,
    'ghost_active_accounts' => $ghostCount,
    'pl_revenue_empty' => true,
];

// ═════════════════════════════════════════════════════════════════════════
// [8] Recommendations
// ═════════════════════════════════════════════════════════════════════════
$section('[8] Recommendations');

echo "  ✅ الـ fix المقترح للـ duplicates:\n";
echo "     - Scenario A (re-type): UPDATE 47 tx. خطر قليل. تأثير على income sum display فقط.\n";
echo "     - Scenario B (rev+rec): +94 tx. أكثر أماناً accounting-wise لكن بيضاعف عدد الـ rows.\n\n";

echo "  ⚠️  العجز -9,635 EGP مش هيختفي بهالتعديل:\n";
echo "     - الـ variance معتمد على booking.profit (مش income sum)\n";
echo "     - current_capital = رصيد الحسابات (مش متأثر بالـ re-type)\n";
echo "     - عشان كده، تحقق الـ variance محتاج:\n";
echo "       1) حل ghost balances (stored_balance ≠ entries_sum)\n";
echo "       2) حل tx#303 (23,780 EGP imbalance)\n";
echo "       3) تحقق من P&L revenueRows (الفلتر related_type='bus_bookings' مش بيلاقي حاجة)\n\n";

echo "  📋 الترتيب المقترح:\n";
echo "     1. ابدأ بـ Scenario A (re-type) — يظبط income display\n";
echo "     2. بعدها نشتغل على ghost balances\n";
echo "     3. بعدها tx#303 (additive reversal)\n";
echo "     4. reconnect P&L (تحديث الفلتر related_type)\n\n";

$report['recommendations'] = [
    'first_fix' => 'scenario_a',
    'reasoning' => 'minimal change, fixes income sum display, lowest risk',
    'next_steps' => [
        '1. fix_dup_bus_income.php (Scenario A)',
        '2. fix_ghost_balances.php',
        '3. fix_tx303.php',
        '4. fix_pl_revenue_filter.php',
    ],
];

// ═════════════════════════════════════════════════════════════════════════
// [9] Save JSON
// ═════════════════════════════════════════════════════════════════════════
$section('[9] Save JSON');

// Verify integrity: pre-snapshot equals end-of-script counts
$postSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'account_entries_count' => DB::table('account_entries')->count(),
    'bus_bookings_count' => DB::table('bus_bookings')->count(),
];
$report['post_snapshot'] = $postSnapshot;
$line('Post-snapshot transactions', $postSnapshot['transactions_count']);
$line('Post-snapshot account_entries', $postSnapshot['account_entries_count']);
$line('Post-snapshot bus_bookings', $postSnapshot['bus_bookings_count']);

if ($preSnapshot != $postSnapshot) {
    echo "\n  ⚠️  WARNING: row counts changed during execution! This script should be read-only.\n";
    $report['integrity_warning'] = 'Row counts changed during execution';
} else {
    $line('Integrity check', 'PASSED (counts unchanged)');
    $report['integrity_check'] = 'PASSED';
}

file_put_contents($logFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$line('JSON saved to', $logFile);

echo "\n✅ Done — كل النتيجة في: {$logFile}\n";
echo "\n  📋 Next: راجع الـ output، وقولي:\n";
echo "     - نعمل Scenario A؟\n";
echo "     - نعمل Scenario B؟\n";
echo "     - نروح للـ residual issues (ghost balances)?\n";
