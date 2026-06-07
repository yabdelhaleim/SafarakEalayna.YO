<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$tolerance = 0.05;
$report = [
    'generated_at' => now()->toIso8601String(),
    'sections' => [],
];

// ── 1. Global debit/credit totals ──
$sums = AccountEntry::query()->selectRaw('SUM(debit) AS td, SUM(credit) AS tc, COUNT(*) AS entry_count')->first();
$totalDebit = round((float) ($sums->td ?? 0), 2);
$totalCredit = round((float) ($sums->tc ?? 0), 2);
$report['sections']['global_totals'] = [
    'total_debit' => $totalDebit,
    'total_credit' => $totalCredit,
    'delta' => round(abs($totalDebit - $totalCredit), 2),
    'entry_lines' => (int) $sums->entry_count,
    'ok' => abs($totalDebit - $totalCredit) <= 0.02,
];

// ── 2. Transactions without entries ──
$missingEntries = Transaction::query()->doesntHave('entries')->count();
$report['sections']['missing_entries'] = ['count' => $missingEntries];

// ── 3. Imbalanced journals ──
$imbalanced = DB::select('
    SELECT transaction_id, SUM(debit) as d, SUM(credit) as c, COUNT(*) as line_count
    FROM account_entries
    WHERE transaction_id IS NOT NULL
    GROUP BY transaction_id
    HAVING ABS(SUM(debit) - SUM(credit)) > 0.02
');
$nullTxEntries = AccountEntry::whereNull('transaction_id')->count();
$report['sections']['null_transaction_entries'] = ['count' => $nullTxEntries];
$report['sections']['imbalanced_journals'] = ['count' => count($imbalanced)];

// Analyze imbalanced pattern: single-leg vs multi-leg
$singleLeg = 0;
$multiLeg = 0;
$singleLegTypes = [];
foreach ($imbalanced as $row) {
    if ((int) $row->line_count === 1) {
        $singleLeg++;
        $tx = Transaction::find($row->transaction_id);
        $type = $tx ? ($tx->type instanceof BackedEnum ? $tx->type->value : (string) $tx->type) : 'unknown';
        $singleLegTypes[$type] = ($singleLegTypes[$type] ?? 0) + 1;
    } else {
        $multiLeg++;
    }
}
$report['sections']['imbalanced_journals']['single_leg'] = $singleLeg;
$report['sections']['imbalanced_journals']['multi_leg'] = $multiLeg;
$report['sections']['imbalanced_journals']['single_leg_by_type'] = $singleLegTypes;

// ── 4. Balance drift (ALL accounts) ──
$ledgerNetByAccount = AccountEntry::query()
    ->selectRaw('account_id, SUM(COALESCE(credit,0) - COALESCE(debit,0)) AS net')
    ->groupBy('account_id')
    ->pluck('net', 'account_id')
    ->map(fn ($v) => round((float) $v, 2));

$drifts = [];
foreach (Account::query()->get(['id', 'name', 'type', 'balance', 'module_type', 'owner_type', 'is_active']) as $acc) {
    $ledger = round((float) ($ledgerNetByAccount[$acc->id] ?? 0), 2);
    $stored = round((float) $acc->balance, 2);
    $diff = round($stored - $ledger, 2);
    if (abs($diff) > $tolerance) {
        $drifts[] = [
            'id' => $acc->id,
            'name' => $acc->name,
            'type' => $acc->type instanceof BackedEnum ? $acc->type->value : $acc->type,
            'module_type' => $acc->module_type,
            'owner_type' => $acc->owner_type,
            'is_active' => $acc->is_active,
            'stored' => $stored,
            'ledger' => $ledger,
            'diff' => $diff,
            'entry_count' => AccountEntry::where('account_id', $acc->id)->count(),
        ];
    }
}

// Categorize drifts
$cats = [
    'clearing_system' => fn ($d) => str_contains($d['name'], 'إقفال') || str_contains($d['name'], '(نظام)'),
    'customer' => fn ($d) => str_contains($d['name'], 'عميل') || str_contains($d['name'], 'ذممة'),
    'supplier_company' => fn ($d) => str_contains($d['name'], 'شركة') || str_contains($d['name'], 'مورد'),
    'treasury_liquidity' => fn ($d) => in_array($d['type'], AccountModuleDivision::LIQUIDITY_TYPES, true),
    'other' => fn ($d) => true,
];

$categorized = [];
foreach ($drifts as $d) {
    $cat = 'other';
    foreach ($cats as $key => $fn) {
        if ($key === 'other') {
            continue;
        }
        if ($fn($d)) {
            $cat = $key;
            break;
        }
    }
    $categorized[$cat][] = $d;
}

$report['sections']['balance_drift'] = [
    'total' => count($drifts),
    'by_category' => array_map(fn ($items) => [
        'count' => count($items),
        'total_diff' => round(array_sum(array_column($items, 'diff')), 2),
        'accounts' => $items,
    ], $categorized),
];

// Pattern: stored but no ledger entries
$noEntriesButBalance = array_filter($drifts, fn ($d) => $d['entry_count'] === 0 && abs($d['stored']) > $tolerance);
$report['sections']['balance_drift']['no_entries_but_balance'] = count($noEntriesButBalance);

// Pattern: has entries but mismatch
$hasEntriesMismatch = array_filter($drifts, fn ($d) => $d['entry_count'] > 0);
$report['sections']['balance_drift']['has_entries_mismatch'] = count($hasEntriesMismatch);

// ── 5. Treasury liquidity specific check ──
$treasuryQuery = Account::query();
AccountModuleDivision::applyLiquidityTreasuryScope($treasuryQuery);
$treasuryAccounts = $treasuryQuery->active()->get();
$treasuryDrift = array_filter($drifts, fn ($d) => $treasuryAccounts->contains('id', $d['id']));
$report['sections']['treasury_liquidity_drift'] = [
    'total_accounts' => $treasuryAccounts->count(),
    'drift_count' => count($treasuryDrift),
    'accounts' => array_values($treasuryDrift),
];

// ── 6. Duplicate transaction entries check ──
$dupTxAccounts = DB::select('
    SELECT transaction_id, account_id, COUNT(*) as cnt
    FROM account_entries
    GROUP BY transaction_id, account_id, debit, credit
    HAVING COUNT(*) > 1
    LIMIT 20
');
$report['sections']['duplicate_entry_lines'] = ['count' => count($dupTxAccounts)];

// ── 7. Orphan account_entries (no transaction) ──
$orphanEntries = AccountEntry::whereNotIn('transaction_id', Transaction::pluck('id'))->count();
$report['sections']['orphan_entries'] = ['count' => $orphanEntries];

// ── 8. Accounts with negative treasury balances ──
$negTreasury = $treasuryAccounts->filter(fn ($a) => $a->balance < 0)->map(fn ($a) => [
    'id' => $a->id, 'name' => $a->name, 'balance' => (float) $a->balance, 'module_type' => $a->module_type,
])->values()->all();
$report['sections']['negative_treasury'] = $negTreasury;

// ── 9. Customer debt accounts sanity ──
$customerAccounts = Account::where('name', 'like', '%عميل%')->orWhere('name', 'like', '%ذممة%')->get();
$customerDrift = array_filter($drifts, fn ($d) => $customerAccounts->contains('id', $d['id']));
$report['sections']['customer_drift'] = [
    'total_customers' => $customerAccounts->count(),
    'drift_count' => count($customerDrift),
];

// ── 10. Sum check: if we fix all drifts by setting balance = ledger, would global still be wrong?
$totalStoredAll = round((float) Account::sum('balance'), 2);
$totalLedgerAll = round((float) $ledgerNetByAccount->sum(), 2);
$report['sections']['aggregate_balance'] = [
    'sum_all_stored_balances' => $totalStoredAll,
    'sum_all_ledger_nets' => $totalLedgerAll,
    'difference' => round($totalStoredAll - $totalLedgerAll, 2),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
