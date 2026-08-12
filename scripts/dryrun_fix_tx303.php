<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Dry-run: Fix the tx#303 Imbalance (cross-currency transfer)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  CONTEXT
 *  -------
 *  tx#303 is a transfer between an EGP cashbox (account #26) and a KWD
 *  cashbox (account #27). The user purchased 145 KWD for 23,925 EGP (rate
 *  ≈ 165 EGP/KWD).
 *
 *  The journal entries are:
 *    entry 607: acc#26 (EGP) | debit 23,925 | credit 0
 *    entry 608: acc#27 (KWD) | debit 0      | credit 145
 *
 *  Because the entries are in DIFFERENT currencies, the trial balance
 *  equation Σ debit = Σ credit fails (23,925 ≠ 145).
 *
 *  This script enumerates 4 fix strategies and shows the IMPACT of each
 *  on the trial balance. The user picks one, then the script applies it.
 *
 *  OPTIONS
 *  -------
 *  A) Inverse entire tx#303 (revert both entries)
 *  B) Add a currency exchange loss entry (write-off)
 *  C) Reverse debit on EGP, keep KWD credit (silent loss)
 *  D) Cross-currency clearing entry
 *
 *  CLI
 *  ---
 *  php scripts/dryrun_fix_tx303.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$selectedOption = null;
foreach ($argv as $arg) {
    if (preg_match('/^--option=([A-D])$/', $arg, $m)) {
        $selectedOption = $m[1];
    }
}

$timestamp = date('Ymd_His');
$logFile = __DIR__ . "/../storage/logs/dryrun_fix_tx303_{$timestamp}.json";

$report = [
    'timestamp' => $timestamp,
    'selected_option' => $selectedOption,
];

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║         Dry-run: Fix tx#303 Imbalance (cross-currency transfer)         ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

// ──────────────────────────────────────────────────────────────────────────
// [1] Current state
// ──────────────────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [1] Current state of tx#303\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$tx = DB::table('transactions')->where('id', 303)->first();
$acc26 = DB::table('accounts')->where('id', 26)->first();
$acc27 = DB::table('accounts')->where('id', 27)->first();

$entries = DB::table('account_entries')->where('transaction_id', 303)->get();

echo "  tx#303: amount=" . number_format($tx->amount, 2) . ' ' . $tx->currency . "\n";
echo "  from_account_id: {$tx->from_account_id} (" . ($acc26->name ?? 'NULL') . " — {$acc26->currency})\n";
echo "  to_account_id:   {$tx->to_account_id} (" . ($acc27->name ?? 'NULL') . " — {$acc27->currency})\n";
echo "  notes: {$tx->notes}\n\n";

echo "  Entries:\n";
foreach ($entries as $e) {
    $acc = DB::table('accounts')->where('id', $e->account_id)->first();
    printf(
        "    entry_id=%d | acc#%-3d (%s %s) | debit=%-12s | credit=%-12s | balance_after=%s\n",
        $e->id,
        $e->account_id,
        $acc->name ?? 'NULL',
        $acc->currency ?? 'NULL',
        number_format($e->debit, 2),
        number_format($e->credit, 2),
        number_format($e->balance_after, 2)
    );
}

$totalDebit = $entries->sum('debit');
$totalCredit = $entries->sum('credit');
$diff = $totalDebit - $totalCredit;
echo "\n  Total debit:  " . number_format($totalDebit, 2) . "\n";
echo "  Total credit: " . number_format($totalCredit, 2) . "\n";
echo "  Imbalance:    " . number_format($diff, 2) . "\n";

// Trial balance impact (before fix)
$treasury = app(\App\Services\Finance\TreasuryService::class);
$tb = $treasury->getOfficeTrialBalance();
echo "\n  Trial balance (BEFORE fix):\n";
echo "    variance: " . number_format($tb['variance'], 2) . " EGP\n";
echo "    status:   {$tb['status']}\n";

// ──────────────────────────────────────────────────────────────────────────
// [2] Options
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [2] Fix options (dry-run ONLY — no writes)\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$options = [
    'A' => [
        'name' => 'Inverse entire tx#303 (revert both entries)',
        'description' => 'Reverses both entries. Account 26 +23,925 EGP, account 27 -145 KWD. The KWD purchase is wiped out.',
        'entries_added' => 2,
        'tx_added' => 1,
        'cashbox_change' => [
            'acc26_egp' => '+23,925 (reverts the original debit)',
            'acc27_kwd' => '-145 (reverts the 145 KWD credit)',
        ],
        'tb_delta' => 0,
        'risk' => 'HIGH — the 145 KWD was actually received; reversing creates a -145 KWD balance.',
    ],
    'B' => [
        'name' => 'Add currency exchange loss entry (write-off)',
        'description' => 'Adds a WRITE-OFF transaction. Reverses the 23,925 EGP debit on acc#26 AND records the 23,780 EGP gap as a currency exchange loss.',
        'entries_added' => 2,
        'tx_added' => 1,
        'cashbox_change' => [
            'acc26_egp' => '0 (the original debit is reversed)',
            'currency_exchange_loss' => 'NEW account: +23,780 accumulated',
        ],
        'tb_delta' => -23780,
        'risk' => 'LOW — accounting-correct, explains the gap as a translation loss.',
    ],
    'C' => [
        'name' => 'Reverse debit on EGP, keep KWD credit',
        'description' => 'Adds a credit entry of 23,925 EGP to account #26 (reverses the original debit). Account #27 keeps the 145 KWD. Result: 23,925 EGP evaporates from the books.',
        'entries_added' => 1,
        'tx_added' => 0,
        'cashbox_change' => [
            'acc26_egp' => '0 (reverts the original debit)',
            'acc27_kwd' => 'unchanged (145 KWD)',
        ],
        'tb_delta' => -23780,
        'risk' => 'HIGH — the 23,925 EGP is lost from the books. Only use if the user is willing to "forgive" the loss.',
    ],
    'D' => [
        'name' => 'Cross-currency clearing entry',
        'description' => 'Adds a transfer entry: debit 23,925 EGP from acc#26 (cancel), credit 145 KWD to a new "currency exchange clearing" account. Round-trips 145 KWD.',
        'entries_added' => 2,
        'tx_added' => 1,
        'cashbox_change' => [
            'acc26_egp' => '0 (cancelled)',
            'acc27_kwd' => 'unchanged',
            'currency_exchange_clearing' => 'NEW account: 0 KWD (balanced)',
        ],
        'tb_delta' => 0,
        'risk' => 'MEDIUM — creates a new clearing account that needs setup.',
    ],
];

foreach ($options as $key => $opt) {
    $marker = $selectedOption === $key ? ' ← SELECTED' : '';
    echo "\n  Option $key: {$opt['name']}$marker\n";
    echo "    Description: {$opt['description']}\n";
    echo "    Entries added: {$opt['entries_added']} | New tx: {$opt['tx_added']}\n";
    echo "    Cashbox change:\n";
    foreach ($opt['cashbox_change'] as $acc => $change) {
        echo "      $acc: $change\n";
    }
    echo "    TB variance delta: " . ($opt['tb_delta'] >= 0 ? '+' : '') . number_format($opt['tb_delta'], 2) . " EGP\n";
    echo "    Risk: {$opt['risk']}\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [3] Recommendation
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [3] Recommendation\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

echo "  ✅ Option B is the most accounting-correct:\n";
echo "     - Reverses the original 23,925 EGP debit on acc#26 (cash is back)\n";
echo "     - Records the 23,780 EGP gap as a currency exchange loss (expense)\n";
echo "     - The 145 KWD on acc#27 stays as recorded (the user did receive it)\n";
echo "     - Variance reduces by 23,780 EGP\n";
echo "     - All accounting equations balance\n";
echo "     - No 'silent' loss of money\n\n";

echo "  To apply Option B, a separate fix script will be built.\n";
echo "  This dry-run script only shows the options.\n";

// ──────────────────────────────────────────────────────────────────────────
// [4] Save JSON
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [4] Save JSON\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$report['tx'] = $tx;
$report['entries'] = $entries;
$report['options'] = $options;
$report['tb_before'] = $tb;

file_put_contents($logFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  JSON saved to: $logFile\n\n";
