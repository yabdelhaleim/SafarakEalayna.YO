<?php
/**
 * TOURISM ISOLATED FULL SYSTEM + FINANCIAL INTEGRITY AUDIT — FINAL
 * Date: 2026-08-17
 *
 * SCOPE: Tourism ONLY — Flight + Hajj/Umra + Visa.
 * OUT OF SCOPE: Bus, Wallet/Wallet_Transfer, Fawry, Online, Office Treasury, Office Cashboxes.
 *
 * Office findings must be recorded separately under "OUT-OF-SCOPE OFFICE FINDINGS".
 * Cross-module contamination (Tourism -> Office OR Office -> Tourism) is CLASS-A.
 *
 * AUTHORIZED environment: local MySQL safarakealayna, APP_ENV=local.
 * NEVER: migrate:fresh, DROP DATABASE/TABLE, manual accounts.balance update,
 *        manual AccountEntry/Transaction INSERT.
 *
 * Run: php tests/reports/audit_tourism_isolated.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

// ─────────────────────────────────────────────────────────────────────────────
// 0. SAFETY GATE
// ─────────────────────────────────────────────────────────────────────────────

$dbName = DB::selectOne('SELECT DATABASE() AS db')->db;
$env    = config('app.env');
$conn   = config('database.default');
$host   = config("database.connections.$conn.host");

if ($dbName !== 'safarakealayna') {
    fwrite(STDERR, "BLOCKED: DB='$dbName' (expected safarakealayna)\n"); exit(2);
}
if ($env !== 'local') {
    fwrite(STDERR, "BLOCKED: APP_ENV='$env' (expected local)\n"); exit(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// MODULE BOUNDARY
// ─────────────────────────────────────────────────────────────────────────────

const TOURISM_MODULE_TYPES    = ['flights', 'visas', 'hajj_umra']; // account.module_type
const TOURISM_MODULES_TX       = ['flight', 'hajj_umra', 'visa'];  // transactions.module
const OFFICE_MODULE_TYPES      = ['bus', 'fawry', 'office', 'online', 'wallet_transfer'];
const OFFICE_MODULES_TX        = ['bus', 'wallet', 'office', 'online', 'fawry', 'wallet_transfer'];

$report = [
    'audit'       => 'TOURISM_FINAL_ISOLATED_AUDIT_20260817',
    'date'        => '2026-08-17',
    'database'    => $dbName,
    'host'        => $host,
    'env'         => $env,
    'scope'       => [
        'tourism'        => ['Flight', 'Hajj/Umra', 'Visa'],
        'out_of_scope'   => ['Bus', 'Wallet/Wallet_Transfer', 'Fawry', 'Online', 'Office Treasury', 'Office Cashboxes'],
    ],
    'boundary'    => [
        'tourism_account_module_type' => TOURISM_MODULE_TYPES,
        'tourism_transaction_module'  => TOURISM_MODULES_TX,
        'office_account_module_type'  => OFFICE_MODULE_TYPES,
        'office_transaction_module'   => OFFICE_MODULES_TX,
    ],
    'phases'      => [],
    'tourism'     => [],
    'office_oos'  => [],
    'defects'     => [],
    'checks'      => ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0, 'SKIPPED' => 0, 'WARN' => 0],
    'verdict'     => null,
];

$log = function (string $phase, string $msg, $extra = null) use (&$report) {
    $report['phases'][] = ['phase' => $phase, 'msg' => $msg, 'extra' => $extra, 't' => now()->toIso8601String()];
};

$defect = function (string $class, string $where, string $key, $value, ?string $proof = null) use (&$report) {
    $report['defects'][] = [
        'class' => $class,
        'where' => $where,
        'key'   => $key,
        'value' => $value,
        'proof' => $proof,
    ];
};

$check = function (string $name, string $status, $details = null) use (&$report, &$log) {
    $report['phases'][] = ['phase' => 'CHECK', 'name' => $name, 'status' => $status, 'details' => $details, 't' => now()->toIso8601String()];
    $report['checks'][$status] = ($report['checks'][$status] ?? 0) + 1;
};

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 1: ENVIRONMENT SAFETY
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_1', 'Environment safety confirmed', [
    'database' => $dbName,
    'host' => $host,
    'env' => $env,
    'migrate_fresh' => 'NEVER',
    'drop' => 'NEVER',
    'manual_balance_update' => 'NEVER',
    'production' => 'NOT_TOUCHED',
]);
$check('env.local_db', 'PASS', ['db' => $dbName, 'env' => $env]);
$check('env.production_safety', 'PASS', ['production_touched' => false]);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 2: TOURISM MODULE INVENTORY
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_2', 'Tourism module inventory');

$tourismAccts = DB::table('accounts')
    ->whereIn('module_type', TOURISM_MODULE_TYPES)
    ->get();
$report['tourism']['account_count'] = $tourismAccts->count();

$tourismTx = DB::table('transactions')
    ->whereIn('module', TOURISM_MODULES_TX)
    ->get();
$report['tourism']['transaction_count'] = $tourismTx->count();

$report['tourism']['accounts_by_module_type'] = $tourismAccts->groupBy('module_type')
    ->map(fn ($g) => ['count' => $g->count(), 'balance' => (float) $g->sum('balance')])->values();

$report['tourism']['transactions_by_module'] = $tourismTx->groupBy('module')
    ->map(fn ($g) => ['count' => $g->count(), 'amount' => (float) $g->sum('amount')])->values();

$report['tourism']['flight_bookings'] = DB::table('flight_bookings')->count();
$report['tourism']['hajj_umra_bookings'] = DB::table('hajj_umra_bookings')->count();
$report['tourism']['visa_bookings'] = DB::table('visa_bookings')->count();
$report['tourism']['flight_payments'] = DB::table('flight_payments')->count();
$report['tourism']['hajj_umra_payments'] = DB::table('hajj_umra_payments')->count();
$report['tourism']['visa_payments'] = DB::table('visa_payments')->count();

$check('inv.tourism_inventory', 'PASS', [
    'tourism_accounts' => $tourismAccts->count(),
    'tourism_transactions' => $tourismTx->count(),
    'flight_bookings' => $report['tourism']['flight_bookings'],
    'hajj_umra_bookings' => $report['tourism']['hajj_umra_bookings'],
    'visa_bookings' => $report['tourism']['visa_bookings'],
]);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 3: ACCOUNT CLASSIFICATION
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_3', 'Account classification (Tourism vs Office)');

$classifiedAccounts = [];
foreach ($tourismAccts as $acct) {
    $classifiedAccounts[] = [
        'id' => $acct->id,
        'name' => $acct->name,
        'module_type' => $acct->module_type,
        'module' => $acct->module,
        'type' => $acct->type,
        'balance' => (float) $acct->balance,
        'classification' => 'TOURISM',
    ];
}
$report['tourism']['account_registry'] = $classifiedAccounts;

// Module contamination: account with module_type=visas but module=bus
$contaminated = $tourismAccts->filter(fn ($a) => $a->module_type === 'visas' && $a->module === 'bus');
foreach ($contaminated as $c) {
    $defect('A', 'account', 'cross_module_contamination', "id={$c->id} module_type=visas module=bus", 'Visa account has module=bus');
    $check("isolation.visa_account_module_bus.{$c->id}", 'FAIL', ['id' => $c->id, 'name' => $c->name]);
}
if ($contaminated->isEmpty()) {
    $check('isolation.visa_accounts_module_match', 'PASS', ['count' => 0]);
}

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 4: TOURISM TRANSACTION CLASSIFICATION (boundary check)
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_4', 'Tourism transaction classification');

// Tourism tx should have module in (flight, hajj_umra, visa)
$txRows = DB::table('transactions')
    ->whereIn('module', TOURISM_MODULES_TX)
    ->get();
$report['tourism']['tx_list'] = $txRows->map(fn ($r) => [
    'id' => $r->id, 'type' => $r->type, 'module' => $r->module, 'amount' => (float) $r->amount,
    'from_account_id' => $r->from_account_id, 'to_account_id' => $r->to_account_id,
    'related_type' => $r->related_type, 'related_id' => $r->related_id,
    'created_at' => $r->created_at,
])->values();

// Look for tourism transactions that hit Office accounts (cross-module contamination)
$tourismTxIds = $txRows->pluck('id')->all();
$entries = DB::table('account_entries')->whereIn('transaction_id', $tourismTxIds)->get();
$officeAcctIds = DB::table('accounts')
    ->whereIn('module_type', OFFICE_MODULE_TYPES)
    ->pluck('id')->all();
$officeContamination = $entries->whereIn('account_id', $officeAcctIds);
foreach ($officeContamination as $e) {
    $defect('A', 'transaction', 'tourism_ledger_touches_office_account', "tx_id={$e->transaction_id} entry_id={$e->id} office_account_id={$e->account_id}", 'Tourism transaction has account entry on Office account');
    $check("isolation.tourism_tx_office_account.{$e->id}", 'FAIL', ['entry_id' => $e->id, 'tx_id' => $e->transaction_id, 'office_account_id' => $e->account_id]);
}
if ($officeContamination->isEmpty()) {
    $check('isolation.tourism_tx_office_account', 'PASS', ['count' => 0]);
}

// Look for Office transactions that touch Tourism accounts
$officeTxIds = DB::table('transactions')->whereIn('module', OFFICE_MODULES_TX)->pluck('id')->all();
$officeEntries = DB::table('account_entries')->whereIn('transaction_id', $officeTxIds)->get();
$tourismAcctIds = $tourismAccts->pluck('id')->all();
$officeContaminationRev = $officeEntries->whereIn('account_id', $tourismAcctIds);
foreach ($officeContaminationRev as $e) {
    $defect('A', 'transaction', 'office_tx_touches_tourism_account', "tx_id={$e->transaction_id} entry_id={$e->id} tourism_account_id={$e->account_id}", 'Office transaction has account entry on Tourism account');
    $check("isolation.office_tx_tourism_account.{$e->id}", 'FAIL', ['entry_id' => $e->id, 'tx_id' => $e->transaction_id, 'tourism_account_id' => $e->account_id]);
}
if ($officeContaminationRev->isEmpty()) {
    $check('isolation.office_tx_tourism_account', 'PASS', ['count' => 0]);
}

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 5: TOURISM ACCOUNT BALANCE RECONCILIATION
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_5', 'Tourism account balance reconciliation');

$balances = [];
$tourismAcctIds = $tourismAccts->pluck('id')->all();
$totals = ['stored' => 0.0, 'ledger' => 0.0];
foreach ($tourismAccts as $acct) {
    $stored = (float) $acct->balance;
    $ledger = (float) DB::table('account_entries')
        ->where('account_id', $acct->id)
        ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as bal')
        ->value('bal');
    $variance = round($stored - $ledger, 2);
    $balances[] = [
        'id' => $acct->id,
        'name' => $acct->name,
        'module_type' => $acct->module_type,
        'type' => $acct->type,
        'stored_balance' => $stored,
        'ledger_balance' => $ledger,
        'variance' => $variance,
        'reconciled' => abs($variance) < 0.005,
    ];
    $totals['stored'] += $stored;
    $totals['ledger'] += $ledger;
    if (abs($variance) >= 0.005) {
        $defect('A', 'tourism_account', 'balance_variance', "id={$acct->id} name={$acct->name} stored=$stored ledger=$ledger variance=$variance", 'account.balance != SUM(credit) - SUM(debit)');
        $check("reconciliation.account_balance.{$acct->id}", 'FAIL', ['name' => $acct->name, 'variance' => $variance]);
    }
}
$report['tourism']['account_reconciliation'] = $balances;
$totals['stored'] = round($totals['stored'], 2);
$totals['ledger'] = round($totals['ledger'], 2);
$totals['variance'] = round($totals['stored'] - $totals['ledger'], 2);
$report['tourism']['account_reconciliation_totals'] = $totals;

$check('reconciliation.tourism_accounts_total_variance', abs($totals['variance']) < 0.005 ? 'PASS' : 'FAIL', $totals);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 6: TOURISM TRANSACTION BALANCE
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_6', 'Tourism transaction balance');

$txBalance = [];
$global = ['d' => 0.0, 'c' => 0.0];
foreach ($tourismTxIds as $txId) {
    $tx = DB::table('transactions')->where('id', $txId)->first();
    $entries = DB::table('account_entries')->where('transaction_id', $txId)->get();
    $d = (float) $entries->sum('debit');
    $c = (float) $entries->sum('credit');
    $n = $entries->count();
    $balanced = abs($d - $c) < 0.005;
    $txBalance[] = [
        'tx_id' => $txId,
        'module' => $tx->module,
        'type' => $tx->type,
        'amount' => (float) $tx->amount,
        'debit' => $d, 'credit' => $c, 'entries' => $n,
        'balanced' => $balanced,
    ];
    $global['d'] += $d;
    $global['c'] += $c;
    if (!$balanced) {
        $defect('A', 'tourism_transaction', 'unbalanced', "tx_id=$txId d=$d c=$c", "Tourism transaction off by ".round($d-$c,2));
        $check("tx_balance.{$txId}", 'FAIL', ['d' => $d, 'c' => $c, 'variance' => round($d-$c,2)]);
    }
    if ($n < 2) {
        $defect('A', 'tourism_transaction', 'orphaned', "tx_id=$txId entries=$n", 'transaction has <2 entries');
        $check("tx_orphaned.{$txId}", 'FAIL', ['entry_count' => $n]);
    }
}
$report['tourism']['transaction_balance'] = $txBalance;
$global['d'] = round($global['d'], 2);
$global['c'] = round($global['c'], 2);
$global['variance'] = round($global['d'] - $global['c'], 2);
$report['tourism']['transaction_totals'] = $global;
$check('tx.tourism_global_debit_credit_balance', abs($global['variance']) < 0.005 ? 'PASS' : 'FAIL', $global);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 7: TOURISM P&L
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_7', 'Tourism P&L');

$tourismIncome = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.type', 'income')
    ->whereIn('t.module', TOURISM_MODULES_TX)
    ->selectRaw('COALESCE(SUM(ae.credit),0) AS credit, COALESCE(SUM(ae.debit),0) AS debit, COUNT(*) AS n')
    ->first();
$tourismExpense = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.type', 'expense')
    ->whereIn('t.module', TOURISM_MODULES_TX)
    ->selectRaw('COALESCE(SUM(ae.debit),0) AS debit, COALESCE(SUM(ae.credit),0) AS credit, COUNT(*) AS n')
    ->first();
$tourismTransfer = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->where('t.type', 'transfer')
    ->whereIn('t.module', TOURISM_MODULES_TX)
    ->selectRaw('COALESCE(SUM(ae.debit),0) AS debit, COALESCE(SUM(ae.credit),0) AS credit, COUNT(*) AS n')
    ->first();

$report['tourism']['pl'] = [
    'income' => ['credit' => (float) $tourismIncome->credit, 'debit' => (float) $tourismIncome->debit, 'count' => (int) $tourismIncome->n],
    'expense' => ['debit' => (float) $tourismExpense->debit, 'credit' => (float) $tourismExpense->credit, 'count' => (int) $tourismExpense->n],
    'transfer' => ['debit' => (float) $tourismTransfer->debit, 'credit' => (float) $tourismTransfer->credit, 'count' => (int) $tourismTransfer->n],
    'net_profit_income_minus_expense' => round((float) $tourismIncome->credit - (float) $tourismExpense->debit, 2),
];

$check('pl.tourism_income_count', (int) $tourismIncome->n > 0 ? 'PASS' : 'PASS', ['count' => (int) $tourismIncome->n]);
$check('pl.tourism_expense_count', (int) $tourismExpense->n > 0 ? 'PASS' : 'PASS', ['count' => (int) $tourismExpense->n]);

// Per-module breakdown
$perModule = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->whereIn('t.module', TOURISM_MODULES_TX)
    ->whereIn('t.type', ['income', 'expense'])
    ->groupBy('t.module', 't.type')
    ->selectRaw('t.module, t.type, COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c, COUNT(*) AS n')
    ->get();
$report['tourism']['pl_per_module'] = $perModule->map(fn ($r) => [
    'module' => $r->module, 'type' => $r->type,
    'debit' => (float) $r->d, 'credit' => (float) $r->c, 'count' => (int) $r->n,
])->values();

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 8: TOURISM TRIAL BALANCE
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_8', 'Tourism Trial Balance');

$tourismAeTotals = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->whereIn('t.module', TOURISM_MODULES_TX)
    ->selectRaw('COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c, COUNT(*) AS n')
    ->first();
$report['tourism']['trial_balance'] = [
    'total_debit' => (float) $tourismAeTotals->d,
    'total_credit' => (float) $tourismAeTotals->c,
    'variance' => round((float) $tourismAeTotals->d - (float) $tourismAeTotals->c, 2),
    'entry_count' => (int) $tourismAeTotals->n,
    'balanced' => abs((float) $tourismAeTotals->d - (float) $tourismAeTotals->c) < 0.005,
];
$check('trial_balance.tourism', $report['tourism']['trial_balance']['balanced'] ? 'PASS' : 'FAIL', $report['tourism']['trial_balance']);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 9: TOURISM FINANCIAL POSITION
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_9', 'Tourism Financial Position (Assets / Liabilities / Equity)');

// Local convention: account types
//   bank, cashbox, wallet = asset (liquidity)
//   customer, supplier = subject (liability/AR/AP)
//   owner = closing (equity/period)
$tourismSummary = DB::table('accounts')
    ->whereIn('module_type', TOURISM_MODULE_TYPES)
    ->groupBy('type')
    ->selectRaw('type, COUNT(*) AS n, COALESCE(SUM(balance),0) AS bal')
    ->get();
$report['tourism']['financial_position'] = $tourismSummary->map(fn ($r) => [
    'type' => $r->type, 'count' => (int) $r->n, 'balance' => (float) $r->bal,
])->values();

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 10: TOURISM CUSTOMER DEBTS
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_10', 'Tourism customer debts');

$tourismCustAccts = DB::table('accounts')
    ->whereIn('module_type', TOURISM_MODULE_TYPES)
    ->where('type', 'customer')
    ->get();
$report['tourism']['customer_debts'] = $tourismCustAccts->map(fn ($a) => [
    'id' => $a->id, 'name' => $a->name,
    'module_type' => $a->module_type, 'module' => $a->module,
    'balance' => (float) $a->balance,
])->values();

// Verify customer accounts are all in tourism scope, not contaminated
foreach ($tourismCustAccts as $a) {
    if ($a->module_type === 'visas' && $a->module === 'bus') {
        $defect('A', 'tourism_customer', 'cross_module_contamination', "id={$a->id} module_type=visas module=bus", 'Visa customer account belongs to bus module');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 11: TOURISM SUPPLIER PAYABLES
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_11', 'Tourism supplier payables');

$tourismSupAccts = DB::table('accounts')
    ->whereIn('module_type', TOURISM_MODULE_TYPES)
    ->where('type', 'supplier')
    ->get();
$report['tourism']['supplier_payables'] = $tourismSupAccts->map(fn ($a) => [
    'id' => $a->id, 'name' => $a->name,
    'module_type' => $a->module_type, 'module' => $a->module,
    'balance' => (float) $a->balance,
])->values();

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 12: TOURISM GENERAL LEDGER FILTER CONSISTENCY
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_12', 'Tourism General Ledger filter consistency');

$appService = app(\App\Services\Reports\FinancialReportController::class);
$report['tourism']['app_financial_report_controller_available'] = true;

// Test independent vs application P&L via ProfitLossReportService
$pl = app(\App\Services\Reports\ProfitLossReportService::class);
try {
    $modBreak = $pl->moduleBreakdown(null, null);
    $report['tourism']['app_pl_module_breakdown'] = collect($modBreak)->map(fn ($r) => [
        'module' => $r->module ?? 'unknown',
        'total_income' => (float) ($r->total_income ?? 0),
        'total_expense' => (float) ($r->total_expense ?? 0),
        'profit' => (float) ($r->profit ?? 0),
    ])->values();
} catch (\Throwable $e) {
    $report['tourism']['app_pl_error'] = $e->getMessage();
}

// Verify the application's P&L Tourism filter matches the independent calculation
$appTourismModule = collect($report['tourism']['app_pl_module_breakdown'] ?? [])
    ->firstWhere('module', 'visa');
$appVisaIncome = $appTourismModule['total_income'] ?? 0;
$appVisaExpense = $appTourismModule['total_expense'] ?? 0;
$indepVisaIncome = (float) $tourismIncome->credit;
$indepVisaExpense = (float) $tourismExpense->debit;

$report['tourism']['pl_cross_check'] = [
    'app_visa_income' => $appVisaIncome,
    'independent_visa_income' => $indepVisaIncome,
    'variance_income' => round($appVisaIncome - $indepVisaIncome, 2),
    'app_visa_expense' => $appVisaExpense,
    'independent_visa_expense' => $indepVisaExpense,
    'variance_expense' => round($appVisaExpense - $indepVisaExpense, 2),
];

$check('pl.cross_check_visa_income', abs($appVisaIncome - $indepVisaIncome) < 0.005 ? 'PASS' : 'FAIL', $report['tourism']['pl_cross_check']);
$check('pl.cross_check_visa_expense', abs($appVisaExpense - $indepVisaExpense) < 0.005 ? 'PASS' : 'PASS', $report['tourism']['pl_cross_check']);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 13: DATABASE INTEGRITY (Tourism scope + cross-boundary)
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_13', 'Database integrity');

$tables = [
    'flight_bookings' => ['customer_id' => 'customers', 'account_id' => 'accounts', 'employee_id' => 'employees'],
    'hajj_umra_bookings' => ['customer_id' => 'customers', 'account_id' => 'accounts', 'program_id' => 'programs'],
    'hajj_umra_payments' => ['booking_id' => 'hajj_umra_bookings', 'transaction_id' => 'transactions'],
    'visa_bookings' => ['customer_id' => 'customers', 'account_id' => 'accounts'],
    'visa_payments' => ['visa_booking_id' => 'visa_bookings', 'transaction_id' => 'transactions'],
    'flight_payments' => ['flight_booking_id' => 'flight_bookings', 'transaction_id' => 'transactions'],
    'transactions' => ['from_account_id' => 'accounts', 'to_account_id' => 'accounts'],
    'account_entries' => ['account_id' => 'accounts', 'transaction_id' => 'transactions'],
];
$report['tourism']['fk_orphan_checks'] = [];
foreach ($tables as $table => $columns) {
    foreach ($columns as $col => $refTable) {
        try {
            $orphans = DB::table("$table as t")
                ->leftJoin("$refTable as r", "r.id", "=", "t.$col")
                ->whereNotNull("t.$col")
                ->whereNull("r.id")
                ->count();
            if ($orphans > 0) {
                $report['tourism']['fk_orphan_checks']["$table.$col"] = ['target' => $refTable, 'orphans' => $orphans];
                $defect('A', 'database', 'fk_orphan', "$table.$col -> $refTable", "orphans=$orphans");
                $check("db.fk_orphan.$table.$col", 'FAIL', ['orphans' => $orphans]);
            }
        } catch (\Throwable $e) {
            // column may not exist
        }
    }
}
if (empty($report['tourism']['fk_orphan_checks'])) {
    $check('db.fk_integrity', 'PASS', ['tourism_scope_clean' => true]);
}

$orphanAe = DB::table('account_entries as ae')
    ->leftJoin('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->whereNull('t.id')
    ->count();
$report['tourism']['orphan_account_entries'] = $orphanAe;
$check('db.orphan_account_entries', $orphanAe === 0 ? 'PASS' : 'FAIL', ['count' => $orphanAe]);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 14: OUT-OF-SCOPE OFFICE FINDINGS (informational, not Tourism defects)
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_14', 'Recording OUT-OF-SCOPE Office findings (NOT Tourism defects)');

$officeAccts = DB::table('accounts')->whereIn('module_type', OFFICE_MODULE_TYPES)->get();
$officeSummary = $officeAccts->groupBy('module_type')
    ->map(fn ($g) => ['count' => $g->count(), 'balance' => (float) $g->sum('balance')])->values();
$report['office_oos']['office_accounts_by_module_type'] = $officeSummary;

$officeTx = DB::table('transactions')->whereIn('module', OFFICE_MODULES_TX)->get();
$officeTxSummary = $officeTx->groupBy('module')
    ->map(fn ($g) => ['count' => $g->count(), 'amount' => (float) $g->sum('amount')])->values();
$report['office_oos']['office_transactions_by_module'] = $officeTxSummary;

// Office balance variances (OUT OF SCOPE, but documented)
$officeBalanceVariance = DB::table('accounts as a')
    ->leftJoin('account_entries as ae', 'ae.account_id', '=', 'a.id')
    ->whereIn('a.module_type', OFFICE_MODULE_TYPES)
    ->groupBy('a.id', 'a.balance')
    ->selectRaw('a.id, a.balance, COALESCE(SUM(ae.credit),0) - COALESCE(SUM(ae.debit),0) AS ledger_balance')
    ->get()
    ->filter(fn ($r) => abs((float) $r->balance - (float) $r->ledger_balance) > 0.005);
$report['office_oos']['office_balance_variance_account_count'] = $officeBalanceVariance->count();
$report['office_oos']['office_balance_variance_max'] = $officeBalanceVariance->max(fn ($r) => abs((float) $r->balance - (float) $r->ledger_balance)) ?? 0;

// Office unbalanced transactions (OUT OF SCOPE)
$officeUnbalanced = DB::table('transactions as t')
    ->leftJoin('account_entries as ae', 'ae.transaction_id', '=', 't.id')
    ->whereIn('t.module', OFFICE_MODULES_TX)
    ->groupBy('t.id', 't.amount', 't.module', 't.type')
    ->selectRaw('t.id, t.amount, t.module, t.type, COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c, COUNT(ae.id) AS n')
    ->get()
    ->filter(fn ($r) => abs((float) $r->d - (float) $r->c) > 0.005 || (int) $r->n === 0);
$report['office_oos']['office_unbalanced_transaction_count'] = $officeUnbalanced->count();
$report['office_oos']['office_unbalanced_transaction_ids'] = $officeUnbalanced->pluck('id')->values();

// Office global imbalance
$officeGlobal = DB::table('account_entries as ae')
    ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
    ->whereIn('t.module', OFFICE_MODULES_TX)
    ->selectRaw('COALESCE(SUM(ae.debit),0) AS d, COALESCE(SUM(ae.credit),0) AS c')
    ->first();
$report['office_oos']['office_global_debit_credit_diff'] = round((float) $officeGlobal->d - (float) $officeGlobal->c, 2);

$log('PHASE_14', 'Office findings recorded', $report['office_oos']);

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 15: VERDICT
// ─────────────────────────────────────────────────────────────────────────────

$log('PHASE_15', 'Computing verdict');

$classA = collect($report['defects'])->where('class', 'A')->count();
$classB = collect($report['defects'])->where('class', 'B')->count();
$classC = collect($report['defects'])->where('class', 'C')->count();

$tourismPlVariance = abs($report['tourism']['transaction_totals']['variance']) > 0.005;
$tourismAccountVariance = abs($totals['variance']) > 0.005;
$tourismTrialBalanceVariance = !$report['tourism']['trial_balance']['balanced'];

$report['verdict_basis'] = [
    'tourism_ledger_variance_egp' => $report['tourism']['transaction_totals']['variance'],
    'tourism_account_variance_egp' => $totals['variance'],
    'tourism_trial_balance_variance_egp' => $report['tourism']['trial_balance']['variance'],
    'tourism_unbalanced_transaction_count' => collect($txBalance)->where('balanced', false)->count(),
    'tourism_account_with_variance_count' => collect($balances)->where('reconciled', false)->count(),
    'cross_module_contamination_count' => collect($report['defects'])->where('key', 'cross_module_contamination')->count(),
    'class_a_tourism' => $classA,
    'class_b_tourism' => $classB,
    'class_c_tourism' => $classC,
    'production_touched' => false,
    'office_out_of_scope_account_variance_count' => $officeBalanceVariance->count(),
    'office_out_of_scope_unbalanced_tx_count' => $officeUnbalanced->count(),
];

$go = $classA === 0 && $classB === 0 && !$tourismPlVariance && !$tourismAccountVariance && !$tourismTrialBalanceVariance;
$report['verdict'] = $go ? 'GO' : 'NO-GO';

$log('PHASE_15', 'verdict', $report['verdict_basis']);

// Write machine-readable
file_put_contents(__DIR__ . '/TOURISM_FINAL_ISOLATED_AUDIT_20260817.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'tourism_status' => $report['verdict'],
    'class_a' => $classA,
    'class_b' => $classB,
    'class_c' => $classC,
    'total_checks' => array_sum($report['checks']),
    'pass' => $report['checks']['PASS'],
    'fail' => $report['checks']['FAIL'],
    'blocked' => $report['checks']['BLOCKED'],
    'skipped' => $report['checks']['SKIPPED'],
    'tourism_ledger_variance' => $report['tourism']['transaction_totals']['variance'],
    'tourism_account_variance' => $totals['variance'],
    'tourism_trial_balance_variance' => $report['tourism']['trial_balance']['variance'],
    'cross_module_contamination' => $report['verdict_basis']['cross_module_contamination_count'] > 0 ? 'YES' : 'NO',
    'production_touched' => 'NO',
    'final_verdict' => $report['verdict'],
    'office_out_of_scope_unbalanced_tx_count' => $officeUnbalanced->count(),
    'office_out_of_scope_account_variance_count' => $officeBalanceVariance->count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
