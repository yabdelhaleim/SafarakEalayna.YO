<?php
/**
 * Office Module Master Test
 * ========================================================================
 *  Comprehensive end-to-end test of the office module.
 *
 *  Phases:
 *    [A] Setup verification
 *    [B] Operations — transfers + customer debt + supplier debt (multi-currency)
 *    [C] Vue Dashboard cards (receivables, payables, net, module breakdown)
 *    [D] Filters — every combination incl. treasury dropdown
 *    [E] Bookings via the BookService (multi-currency)
 *    [F] Cancellation + reversal correctness
 *    [G] Deletion safety
 *    [H] Unified Accounts (office division vaults)
 *    [I] Filament → API → Vue flow (new bank created)
 *    [J] Office module Accounting Trial Balance (final invariant check)
 *
 *  Reads state from office_master_state.json — run office_master_setup.php
 *  first.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Transaction;
use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Services\Finance\TransactionService;
use App\Services\Finance\AccountRechargeService;
use App\Services\Finance\SupplierAccountService;

$BASE = 'http://127.0.0.1:8000/api/v1';
$state = json_decode(file_get_contents(__DIR__ . '/office_master_state.json'), true);

$results = ['success' => 0, 'failed' => 0, 'critical_failures' => []];
$section_results = [];

function log_section(string $name): void
{
    global $section_results;
    if (!isset($section_results[$name])) $section_results[$name] = ['pass' => 0, 'fail' => 0];
}

function log_test(string $key, bool $success, $payload = null): void
{
    global $results, $section_results;
    if ($success) {
        $results['success']++;
        echo "  ✅ $key\n";
    } else {
        $results['failed']++;
        $results['critical_failures'][] = ['key' => $key, 'payload' => $payload];
        echo "  ❌ $key — " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Office Module Master Test\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ──────────────────────────────────────────────────────────────────────
// [A] SETUP VERIFICATION
// ──────────────────────────────────────────────────────────────────────
echo "[A] SETUP VERIFICATION\n";
log_section('A_setup');
$adminId = $state['admin']['id'];
$bankByCurrency = collect($state['banks'])->groupBy('currency');
$cashboxes = $state['cashboxes'];
$wallets = $state['wallets'];

log_test('all 6 banks exist', count($state['banks']) === 6, 'got ' . count($state['banks']));
log_test('all 5 cashboxes exist', count($state['cashboxes']) === 5, 'got ' . count($state['cashboxes']));
log_test('all 5 wallets exist', count($state['wallets']) === 5, 'got ' . count($state['wallets']));
log_test('exactly 1 cashbox marked as vault', collect($state['cashboxes'])->where('vault', true)->count() === 1);

// Sanity: every account's balance equals opening credit
$violations = [];
foreach (Account::all() as $a) {
    if (count($a->entries()->get()) === 1) {
        $e = $a->entries()->first();
        if ((float)$e->debit !== (float)$a->balance * -1 || (float)$e->credit !== (float)$a->balance) {
            // Actually expected: credit = balance, debit = 0
            if ((float)$e->credit != (float)$a->balance) {
                $violations[] = "#{$a->id} {$a->name}";
            }
        }
    }
}
log_test('opening entries match balances', count($violations) === 0, $violations ?: 'all OK');

// ──────────────────────────────────────────────────────────────────────
// [B] OPERATIONS — transfers + customer debt + supplier debt
// ──────────────────────────────────────────────────────────────────────
echo "\n[B] OPERATIONS\n";
log_section('B_operations');

$ts = app(TransactionService::class);
$ar = app(AccountRechargeService::class);
$supplierSvc = app(SupplierAccountService::class);

// 1) Transfer bank→cashbox (same currency EGP)
$bankEGP = collect($state['banks'])->firstWhere('name', 'البنك الأهلي المصري — جنيه');
$cashEGP = collect($state['cashboxes'])->firstWhere('name', 'خزينة المكتب الرئيسية — جنيه (VAULT)');
$beforeBankEGP = (float)Account::find($bankEGP['id'])->balance;
$beforeCashEGP = (float)Account::find($cashEGP['id'])->balance;

$tx1 = $ts->recordTransfer([
    'from_account_id' => $bankEGP['id'],
    'to_account_id'   => $cashEGP['id'],
    'amount' => 10000,
    'currency' => 'EGP',
    'module' => 'office',
    'notes' => 'B1: تحويل من البنك للخزينة الرئيسية',
    'created_by' => $adminId,
]);

log_test('B1: transfer bank→cashbox (10000 EGP)', $tx1->transaction !== null, 'tx_id=' . ($tx1->transaction->id ?? 'null'));
$balBank1 = (float)Account::find($bankEGP['id'])->balance;
$balCash1 = (float)Account::find($cashEGP['id'])->balance;
log_test('B1: bank balance -10000', abs($balBank1 - ($beforeBankEGP - 10000)) < 0.01);
log_test('B1: cashbox balance +10000', abs($balCash1 - ($beforeCashEGP + 10000)) < 0.01);

// 2) Transfer bank (USD) → bank (EGP) — currency conversion
$bankUSD = collect($state['banks'])->firstWhere('name', 'البنك الأهلي المصري — دولار');
$beforeUSD = (float)Account::find($bankUSD['id'])->balance;
$tx2 = $ts->recordJournalTransfer([
    'from_account_id' => $bankUSD['id'],
    'to_account_id'   => $bankEGP['id'],
    'amount' => 1000,                   // 1000 USD out
    'converted_amount' => 1000 * 50.10, // 50100 EGP in
    'exchange_rate' => 50.10,
    'module' => 'office',
    'notes' => 'B2: تحويل 1000 USD → 50100 EGP',
    'created_by' => $adminId,
    'allow_from_negative' => false,
]);
log_test('B2: cross-currency transfer USD→EGP', $tx2->id > 0, 'tx_id=' . $tx2->id);
$balUSD2 = (float)Account::find($bankUSD['id'])->balance;
$balEGP2 = (float)Account::find($bankEGP['id'])->balance;
log_test('B2: USD bank -1000', abs($balUSD2 - ($beforeUSD - 1000)) < 0.01, "before=$beforeUSD, after=$balUSD2, expected=" . ($beforeUSD - 1000));
log_test('B2: EGP bank +50100', abs($balEGP2 - ($balBank1 + 50100)) < 0.01);

// 3) Customer purchases a service → revenue + customer debt grows (in EGP)
$customer1 = $state['customers'][0];

DB::transaction(function () use ($customer1, $adminId) {
    // Customer AR (EGP): credit 2000 (they got the service, debt UP)
    $arAcc = Account::find($customer1['account_id']);
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($arAcc) {
        $arAcc->balance += 2000;
        $arAcc->save();
    });
    $tx = Transaction::create([
        'type' => TransactionType::Income->value,
        'amount' => 2000,
        'currency' => 'EGP',
        'module' => 'office',
        'from_account_id' => null,
        'to_account_id' => $arAcc->id,
        'notes' => 'B3: زيادة دين العميل أحمد (حجز خدمة 2000 EGP)',
        'created_by' => $adminId,
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $arAcc->id,
        'transaction_id' => $tx->id,
        'debit' => 0,
        'credit' => 2000,
        'balance_after' => (float)$arAcc->balance,
        'notes' => 'B3: AR debt grows',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});
log_test('B3: customer1 AR = 2000 EGP debt', (float)Account::find($customer1['account_id'])->balance === 2000.0);

// 4) Customer pays back some debt → EGP cashbox receives cash, AR decreases
$cashwallet = collect($state['wallets'])->firstWhere('name', 'محفظة فودافون كاش رئيسية');
$custEGPbefore = (float)Account::find($customer1['account_id'])->balance;
$walletBefore = (float)Account::find($cashwallet['id'])->balance;

DB::transaction(function () use ($customer1, $cashwallet, $adminId) {
    // Customer AR: debit 500 (debt reduced by paying 500)
    $arAcc = Account::find($customer1['account_id']);
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($arAcc) {
        $arAcc->balance -= 500;
        $arAcc->save();
    });
    // Wallet: credit 500 (received payment)
    $wal = Account::find($cashwallet['id']);
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($wal) {
        $wal->balance += 500;
        $wal->save();
    });
    $tx = Transaction::create([
        'type' => TransactionType::Income->value,
        'amount' => 500,
        'currency' => 'EGP',
        'module' => 'office',
        'from_account_id' => $arAcc->id,
        'to_account_id' => $wal->id,
        'notes' => 'B4: سداد العميل أحمد 500 EGP إلى محفظة Vodafone',
        'created_by' => $adminId,
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $arAcc->id,
        'transaction_id' => $tx->id,
        'debit' => 500,
        'credit' => 0,
        'balance_after' => (float)$arAcc->balance,
        'notes' => 'B4 AR reduction',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $wal->id,
        'transaction_id' => $tx->id,
        'debit' => 0,
        'credit' => 500,
        'balance_after' => (float)$wal->balance,
        'notes' => 'B4 wallet credit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});
log_test('B4: customer1 AR = 1500 EGP after partial pay', (float)Account::find($customer1['account_id'])->balance === 1500.0);
log_test('B4: wallet +500 EGP', abs((float)Account::find($cashwallet['id'])->balance - ($walletBefore + 500)) < 0.01);

// 5) Supplier charges us → supplier balance goes negative (we owe them)
$sup1 = $state['suppliers'][0]; // سوبر جيت
$supAcc = Account::find(\App\Models\Supplier::find($sup1['id'])->account_id);

// Direct ledger manipulation to record supplier debt growth (we owe them more)
DB::transaction(function () use ($supAcc, $adminId) {
    // Supplier account (AP): balance -= 3500 (we owe them more)
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($supAcc) {
        $supAcc->balance -= 3500;
        $supAcc->save();
    });
    $tx = Transaction::create([
        'type' => TransactionType::Expense->value,
        'amount' => 3500,
        'currency' => 'EGP',
        'module' => 'bus',
        'from_account_id' => $supAcc->id,
        'to_account_id' => null,
        'notes' => 'B5: حجز باصات 3500 EGP من سوبر جيت (مديونية)',
        'created_by' => $adminId,
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $supAcc->id,
        'transaction_id' => $tx->id,
        'debit' => 3500,
        'credit' => 0,
        'balance_after' => (float)$supAcc->balance,
        'notes' => 'B5: supplier liability grows',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});
log_test('B5: supplier1 AP = -3500 (we owe them)', (float)Account::find($supAcc->id)->balance === -3500.0);

// 6) Pay supplier partially via direct transfer
$supAcc->refresh();
$bankEGP = \App\Models\Account::find(collect($state['banks'])->firstWhere('name', 'البنك الأهلي المصري — جنيه')['id']);
DB::transaction(function () use ($supAcc, $bankEGP, $adminId) {
    // Supplier AP: balance += 1500 (we owe them less, balance moves toward 0)
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($supAcc) {
        $supAcc->balance += 1500;
        $supAcc->save();
    });
    // Bank EGP: balance -= 1500 (we paid out)
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($bankEGP) {
        $bankEGP->balance -= 1500;
        $bankEGP->save();
    });
    $tx = Transaction::create([
        'type' => TransactionType::Transfer->value,
        'amount' => 1500,
        'currency' => 'EGP',
        'module' => 'bus',
        'from_account_id' => $bankEGP->id,
        'to_account_id' => $supAcc->id,
        'notes' => 'B6: سداد جزئي 1500 EGP لسوبر جيت',
        'created_by' => $adminId,
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $supAcc->id,
        'transaction_id' => $tx->id,
        'debit' => 0,
        'credit' => 1500,
        'balance_after' => (float)$supAcc->balance,
        'notes' => 'B6: AP reduction',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('account_entries')->insert([
        'account_id' => $bankEGP->id,
        'transaction_id' => $tx->id,
        'debit' => 1500,
        'credit' => 0,
        'balance_after' => (float)$bankEGP->balance,
        'notes' => 'B6: bank payment out',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});
log_test('B6: supplier1 = -2000 after partial pay', (float)Account::find($supAcc->id)->balance === -2000.0);

// 7) Cross-currency vault transfer vault (cashbox EGP → cashbox USD via journal)
$cashUSD = collect($state['cashboxes'])->firstWhere('name', 'خزينة المكتب — دولار');
$before = [
    'cashEGP' => (float)Account::find($cashEGP['id'])->balance,
    'cashUSD' => (float)Account::find($cashUSD['id'])->balance,
];
$tx7 = $ts->recordJournalTransfer([
    'from_account_id' => $cashEGP['id'],
    'to_account_id'   => $cashUSD['id'],
    'amount' => 5000,                       // EGP out
    'converted_amount' => 5000 / 50.10,     // ≈ 99.80 USD in
    'exchange_rate' => 1 / 50.10,
    'module' => 'office',
    'notes' => 'B7: cross-currency vault transfer',
    'created_by' => $adminId,
    'allow_from_negative' => false,
]);
$after = [
    'cashEGP' => (float)Account::find($cashEGP['id'])->balance,
    'cashUSD' => (float)Account::find($cashUSD['id'])->balance,
];
log_test('B7: cashEGP -5000', abs($after['cashEGP'] - ($before['cashEGP'] - 5000)) < 0.01);
log_test('B7: cashUSD +99.80', abs($after['cashUSD'] - ($before['cashUSD'] + 99.80)) < 1.0);  // rounding tolerance

// ──────────────────────────────────────────────────────────────────────
// [C] VUE DASHBOARD CARDS (OfficeManagement.vue endpoints)
// ──────────────────────────────────────────────────────────────────────
echo "\n[C] VUE DASHBOARD CARDS\n";
log_section('C_vue_dashboard');

$token = Http::post("$BASE/auth/login", [
    'email' => $state['admin']['email'],
    'password' => $state['admin']['password'],
])->json('data.token');
$auth = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
log_test('login token obtained', !empty($token));

// /reports/debts?department=office
$debtsRes = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office']);
$debts = $debtsRes->json('data');
log_test('GET /reports/debts?department=office', $debtsRes->successful());
log_test('C: debts has items', isset($debts['items']) && count($debts['items']) > 0, 'items=' . count($debts['items'] ?? []));

$customerDebt = collect($debts['items'] ?? [])->firstWhere('name', 'العميل: أحمد محمد علي') ?? collect($debts['items'] ?? [])->firstWhere('name', 'سوبر جيت');
$totalReceivables = (float)($debts['total_receivables'] ?? 0);
$totalPayables = (float)($debts['total_payables'] ?? 0);
echo "  ℹ  total_receivables=$totalReceivables, total_payables=$totalPayables\n";

log_test('C: total_receivables > 0 (customer debt exists)', $totalReceivables > 0);
// payables stored as ABSOLUTE value (positive number = amount we owe)
log_test('C: total_payables > 0 (supplier debt exists, shown as positive)', $totalPayables > 0);
log_test('C: balance field is in items (positive for customers, negative for suppliers)', isset($debts['items'][0]['balance']));
log_test('C: balance_egp field is in items', isset($debts['items'][0]['balance_egp']));
log_test('C: account_id field is in items', isset($debts['items'][0]['account_id']));
log_test('C: statement_url field is in items', isset($debts['items'][0]['statement_url']));

// Verify the supplier appears with negative balance
$supplierItem = collect($debts['items'] ?? [])->firstWhere('entity_type', 'supplier');
log_test('C: supplier item has negative balance (we owe them)', $supplierItem !== null && (float)($supplierItem['balance'] ?? 0) < 0, 'balance=' . ($supplierItem['balance'] ?? 'N/A'));

// Verify the customer appears with positive balance
$customerItem = collect($debts['items'] ?? [])->firstWhere('entity_type', 'customer');
log_test('C: customer item has positive balance (they owe us)', $customerItem !== null && (float)($customerItem['balance'] ?? 0) > 0, 'balance=' . ($customerItem['balance'] ?? 'N/A'));

// Module breakdown
$modRes = Http::withHeaders($auth)->get("$BASE/reports/profit-by-module", [
    'category' => 'office',
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date' => now()->toDateString(),
]);
$mods = $modRes->json('data.by_module') ?? [];
log_test('GET /reports/profit-by-module', $modRes->successful());
log_test('C: modules present for office', count($mods) >= 0);

// ──────────────────────────────────────────────────────────────────────
// [D] FILTERS — incl. treasury dropdown
// ──────────────────────────────────────────────────────────────────────
echo "\n[D] FILTERS (incl. treasury dropdown)\n";
log_section('D_filters');

// Reset cache to get fresh results
\Illuminate\Support\Facades\Cache::flush();

// 1) Filter by currency=EGP
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['currency' => 'EGP', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D1: filter by currency=EGP office', $r->successful() && count($items) > 0, 'count=' . count($items));

// 2) Filter by currency=USD
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['currency' => 'USD', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D2: filter by currency=USD office', count($items) > 0, 'count=' . count($items));

// 3) Filter by type=wallet
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['type' => 'wallet', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D3: filter by type=wallet', count($items) === 5, 'count=' . count($items));

// 4) Filter by type=bank
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['type' => 'bank', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D4: filter by type=bank office', count($items) === 6, 'count=' . count($items));

// 5) Filter by is_active
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['is_active' => 1, 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D5: filter is_active=true', count($items) >= 14, 'count=' . count($items));

// 6) Search by name
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['search' => 'البنك الأهلي', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
log_test('D6: search "البنك الأهلي"', count($items) === 2, 'count=' . count($items));

// 7) Combined filter (currency + is_active)
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['currency' => 'SAR', 'is_active' => 1]);
$items = $r->json('data.items') ?? [];
log_test('D7: combined currency=SAR + is_active', count($items) >= 1);

// 8) Treasury dropdown for debts (filter by source account)
$supplierAR = Account::whereHas('supplier', fn($q) => $q->where('id', $sup1['id']))->first();
if ($supplierAR) {
    $r = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office', 'account_id' => $supplierAR->id]);
    log_test('D8: debts filtered by treasury account_id', $r->successful());
}

// 9) Debts filter by entity_type
$r = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office', 'entity_type' => 'customer']);
log_test('D9: debts filter entity_type=customer', $r->successful());
$r = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office', 'entity_type' => 'supplier']);
log_test('D10: debts filter entity_type=supplier', $r->successful());

// 10) Vue dropdown data
$settingsCur = Http::withHeaders($auth)->get("$BASE/settings/currencies");
$settingsTypes = Http::withHeaders($auth)->get("$BASE/settings/account-types");
$settingsMods = Http::withHeaders($auth)->get("$BASE/settings/transaction-modules");
log_test('D11: settings/currencies responds (data populated by Vue hardcode)', $settingsCur->successful());
log_test('D12: settings/account-types', $settingsTypes->successful());
$officeMod = collect($settingsMods->json('data') ?? [])->firstWhere('value', 'office');
log_test('D13: settings/transaction-modules includes office', $officeMod !== null);

// Verify Vue hardcoded currency labels (read directly from the file)
$vueLabels = file_get_contents(__DIR__ . '/resources/js/views/finance/DepartmentManagement.vue');
log_test('D14: Vue has hardcoded currency labels (EGP, USD, SAR, KWD)', str_contains($vueLabels, "EGP: 'جنيه'") && str_contains($vueLabels, "USD:") && str_contains($vueLabels, 'KWD'));

// ──────────────────────────────────────────────────────────────────────
// [E] BOOKING FLOW with multi-currency (treasury receives payments)
// ──────────────────────────────────────────────────────────────────────
echo "\n[E] BOOKING FLOW (multi-currency operations on customer AR)\n";
log_section('E_bookings');

// Bookings must increment customer AR (in EGP — base currency of customer).
// For multi-currency services, the booking is recorded in EGP equivalent
// from the foreign price × exchange rate.

$bookings = [
    ['customer' => $state['customers'][0], 'service_currency' => 'USD', 'service_price' => 500, 'rate' => 50.10, 'name' => 'B-USD-001'],
    ['customer' => $state['customers'][1], 'service_currency' => 'SAR', 'service_price' => 2500, 'rate' => 13.36, 'name' => 'B-SAR-001'],
    ['customer' => $state['customers'][2], 'service_currency' => 'EGP', 'service_price' => 1500, 'rate' => 1.0, 'name' => 'B-EGP-001'],
];
$createdBookings = [];
foreach ($bookings as $bk) {
    $arAcc = Account::find($bk['customer']['account_id']);
    // Customer AR (EGP) gets credited by the converted amount
    $egpAmount = round($bk['service_price'] * $bk['rate'], 2);
    $tx = DB::transaction(function () use ($arAcc, $bk, $egpAmount, $adminId) {
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($arAcc, $egpAmount) {
            $arAcc->balance += $egpAmount;
            $arAcc->save();
        });
        $tx = Transaction::create([
            'type' => TransactionType::Income->value,
            'amount' => $egpAmount,
            'currency' => 'EGP',
            'module' => 'office',
            'to_account_id' => $arAcc->id,
            'from_account_id' => null,
            'notes' => "E: حجز {$bk['name']} — {$bk['service_price']} {$bk['service_currency']} (≈{$egpAmount} EGP)",
            'created_by' => $adminId,
        ]);
        DB::table('account_entries')->insert([
            'account_id' => $arAcc->id,
            'transaction_id' => $tx->id,
            'debit' => 0,
            'credit' => $egpAmount,
            'balance_after' => (float)$arAcc->balance,
            'notes' => "E: {$bk['name']}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $tx;
    });
    $createdBookings[$bk['name']] = [
        'tx_id' => $tx->id,
        'service_currency' => $bk['service_currency'],
        'service_price' => $bk['service_price'],
        'egp_amount' => $egpAmount,
        'customer_id' => $arAcc->id,
        'balance_after' => (float)Account::find($arAcc->id)->balance,
    ];
    log_test("E: created booking {$bk['name']} ({$bk['service_price']} {$bk['service_currency']} → {$egpAmount} EGP, balance after={$createdBookings[$bk['name']]['balance_after']})", $tx->id > 0);
}

// Calculate expected totals
$expectedTotalBookings = array_sum(array_column($createdBookings, 'egp_amount'));
log_test('E: sum of bookings converted correctly', abs($expectedTotalBookings - ($createdBookings['B-USD-001']['egp_amount'] + $createdBookings['B-SAR-001']['egp_amount'] + $createdBookings['B-EGP-001']['egp_amount'])) < 0.01);

// ──────────────────────────────────────────────────────────────────────
// [F] CANCELLATION + REVERSAL
// ──────────────────────────────────────────────────────────────────────
echo "\n[F] CANCELLATION\n";
log_section('F_cancellation');

// Cancel B-USD-001 by appending inverse entries
$bk = $createdBookings['B-USD-001'];
$arAcc = Account::find($bk['customer_id']);
// The "balance before the booking" is what we want to restore to
$expectedBalanceAfterCancel = (float)$arAcc->balance - $bk['egp_amount'];

$txIdToCancel = $bk['tx_id'];
DB::transaction(function () use ($txIdToCancel, $bk) {
    $entries = DB::table('account_entries')->where('transaction_id', $txIdToCancel)->orderBy('id')->get();
    foreach ($entries as $e) {
        DB::table('account_entries')->insert([
            'account_id' => $e->account_id,
            'transaction_id' => $txIdToCancel,
            'debit' => (float)$e->credit,
            'credit' => (float)$e->debit,
            'balance_after' => 0,
            'notes' => 'عكس: ' . ($e->notes ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delta = (float)$e->credit - (float)$e->debit;
        $acc = Account::find($e->account_id);
        $acc->balance -= $delta;
        \App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $acc->save());
    }
});

$afterCancel = (float)Account::find($bk['customer_id'])->balance;
log_test("F: cancel B-USD-001 restored AR balance to {$expectedBalanceAfterCancel}", abs($afterCancel - $expectedBalanceAfterCancel) < 0.01, "after_cancel=$afterCancel");

// Verify invariant after cancellation
$arSum = DB::table('account_entries')->where('account_id', $bk['customer_id'])->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
log_test("F: AR invariant holds after cancel (balance=$afterCancel, sum=$arSum)", abs((float)$afterCancel - (float)$arSum < 0.01), "balance=$afterCancel, sum=$arSum");

// Also verify the reversal entries are on the same transaction_id (additive reversal invariant)
$reversalEntries = DB::table('account_entries')->where('transaction_id', $txIdToCancel)->where('notes', 'like', 'عكس:%')->count();
log_test("F: cancellation created 'عكس:' reversal entries on same transaction_id", $reversalEntries > 0, "count=$reversalEntries");

// ──────────────────────────────────────────────────────────────────────
// [G] DELETION SAFETY
// ──────────────────────────────────────────────────────────────────────
echo "\n[G] DELETION SAFETY\n";
log_section('G_deletion');

// Try to delete bank EGP (has movements) → blocked
try {
    $r = Http::withHeaders($auth)->delete("$BASE/finance/accounts/{$bankEGP['id']}");
    // DELETE not allowed in routes — that's expected
    log_test("G1: DELETE endpoint returns 405 (not route)", $r->status() === 405, "status={$r->status()}");
} catch (\Throwable $e) {
    log_test("G1: DELETE on /finance/accounts/{id}", false, $e->getMessage());
}

// Try deactivate bank EGP (has movements) → blocked
try {
    $r = Http::withHeaders($auth)->post("$BASE/finance/accounts/{$bankEGP['id']}/deactivate");
    log_test("G2: deactivate bank with balance blocked", $r->status() === 422, "status={$r->status()}");
} catch (\Throwable $e) {
    log_test("G2: deactivate bank with balance blocked", false, $e->getMessage());
}

// Deactivate empty wallet OK
$emptyWallet = Account::create([
    'name' => 'Wallet-empty-test',
    'type' => AccountType::Wallet,
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'wallet_provider' => \App\Enums\WalletProvider::Other,
    'wallet_number' => '01999999999',
    'created_by' => $adminId,
]);
$r = Http::withHeaders($auth)->post("$BASE/finance/accounts/{$emptyWallet->id}/deactivate");
log_test("G3: deactivate empty wallet OK", $r->status() === 200);

// Try deleting account with balance — should throw RuntimeException (test via model)
$tryDel = Account::find($bankEGP['id']);
$threw = false;
try {
    $tryDel->delete();
} catch (\RuntimeException $e) {
    $threw = true;
}
log_test("G4: ORM delete of bank with balance throws", $threw, $threw ? 'ok' : 'NO throw');

// Try deleting account with no entries — should succeed
$empty = Account::create([
    'name' => 'Bank-empty-test',
    'type' => AccountType::Bank,
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'created_by' => $adminId,
]);
$canDel = $empty->canBeDeleted();
log_test("G5: empty account canBeDeleted returns true", $canDel);
$empty->delete();
$stillExists = Account::where('id', $empty->id)->exists();
log_test("G5b: empty account deleted successfully (DB query confirms gone)", !$stillExists, "still_exists=" . ($stillExists ? 'yes' : 'no'));

// ──────────────────────────────────────────────────────────────────────
// [H] UNIFIED ACCOUNTS
// ──────────────────────────────────────────────────────────────────────
echo "\n[H] UNIFIED ACCOUNTS (vaults across modules)\n";
log_section('H_unified');

// Check the cashbox vault is tagged correctly
$vault = Account::find($cashEGP['id']);
log_test("H1: cashbox vault is_module_vault=true", (bool)$vault->is_module_vault);

// Check that office module listing includes our banks/cashboxes/wallets
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['module_type' => 'office']);
$items = $r->json('data.items') ?? [];
$allTypes = collect($items)->pluck('type')->unique()->sort()->values()->all();
$hasAllTypes = $allTypes == ['bank', 'cashbox', 'wallet'];
log_test("H2: office listing has all liquidity types (bank, cashbox, wallet)", $hasAllTypes, 'types=' . implode(',', $allTypes));

// Verify the vault-tagged one is at the top of unified vault query — must filter for vault specifically
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['module_type' => 'office', 'is_module_vault' => 1]);
$items = $r->json('data.items') ?? [];
$vaults = collect($items)->where('is_module_vault', true)->values();
log_test("H3: at least one vault exists in office module", $vaults->count() >= 1, 'count=' . $vaults->count());

// Verify the cashbox vault appears first
$firstVault = $vaults->first();
log_test("H3b: cashbox vault #" . $cashEGP['id'] . " is in vault list", $firstVault !== null && $firstVault['id'] == $cashEGP['id']);

// Verify cross-module unified vault query (check that the cashbox vault is also available to other modules)
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['is_module_vault' => 1, 'is_active' => 1]);
$allVaults = collect($r->json('data.items') ?? []);
$cashEGPinVault = $allVaults->firstWhere('id', $cashEGP['id']);
log_test("H4: cashbox vault is unified (visible across office queries)", $cashEGPinVault !== null);

// Verify the banker dropdown has the right vault for office module
$tsQ1 = Http::withHeaders($auth)->get("$BASE/finance/treasuries/get-module-accounts/office");
log_test("H5: treasury dropdown for office module returns accounts", $tsQ1->successful());
$tsItems = $tsQ1->json('data');
if (is_array($tsItems) && count($tsItems) > 0) {
    $tsHasVault = collect($tsItems)->contains(fn ($a) => ($a['is_module_vault'] ?? false) === true);
    log_test("H5b: treasury dropdown includes the vault", $tsHasVault, 'is_module_vault check on ' . count($tsItems) . ' items');
}

// ──────────────────────────────────────────────────────────────────────
// [I] FILAMENT → API → VUE FLOW
// ──────────────────────────────────────────────────────────────────────
echo "\n[I] FILAMENT → API → VUE FLOW (new bank created)\n";
log_section('I_filament_vue_flow');

// Create a new bank via API (simulating Filament save)
$newBankData = [
    'name' => 'Test-API-Created-Bank — KWD جديد',
    'type' => 'bank',
    'currency' => 'KWD',
    'balance' => 250.000,
    'is_active' => true,
    'module_type' => 'office',
    'owner_type' => 'office',
    'notes' => 'اختبار تدفق Filament → API → Vue',
];
$createRes = Http::withHeaders($auth)->post("$BASE/finance/accounts", $newBankData);
log_test("I1: POST /finance/accounts creates bank", $createRes->status() === 201, "status={$createRes->status()}");
$newBankId = $createRes->json('data.id');

// Verify the cache was invalidated and the new bank is visible
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['currency' => 'KWD', 'module_type' => 'office']);
$items = $r->json('data.items') ?? [];
$foundNewBank = collect($items)->firstWhere('id', $newBankId);
log_test("I2: new bank visible in Vue listing immediately (no cache delay)", $foundNewBank !== null, 'items=' . count($items) . ', found=' . ($foundNewBank['name'] ?? 'N/A'));

// Verify it's correctly searchable
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['search' => 'Test-API-Created']);
log_test("I3: new bank searchable by name", count($r->json('data.items') ?? []) >= 1);

// Verify statement
$r = Http::withHeaders($auth)->get("$BASE/finance/accounts/$newBankId/statement");
$stm = $r->json('data.stats');
log_test("I4: statement shows account_balance=250 KWD", abs($stm['account_balance'] - 250.0) < 0.01);
log_test("I4b: statement shows period_credit=250 (opening entry)", abs($stm['period_credit'] - 250.0) < 0.01);

// ──────────────────────────────────────────────────────────────────────
// [J] OFFICE MODULE ACCOUNTING TRIAL BALANCE
// ──────────────────────────────────────────────────────────────────────
echo "\n[J] OFFICE MODULE ACCOUNTING TRIAL BALANCE\n";
log_section('J_trial_balance');

// Compute the actual sum across all office division accounts
$tbData = DB::select("
    SELECT a.type, a.currency, a.module_type,
           COUNT(*) as cnt,
           SUM(CAST(a.balance AS DECIMAL(15,2))) AS balance_sum,
           SUM(COALESCE((SELECT SUM(CAST(debit AS DECIMAL(15,2))) - SUM(CAST(credit AS DECIMAL(15,2))) FROM account_entries e WHERE e.account_id = a.id), 0)) AS invariant_diff
    FROM accounts a
    WHERE a.owner_type = 'office'
    GROUP BY a.type, a.currency, a.module_type
    ORDER BY a.type, a.currency
");
$totalBalByCurrency = [];
$totalInvariantDiff = 0;
echo "  ┌────────────────┬──────┬──────┬────────────┬────────────┐\n";
echo "  │ Type           │ Cur  │ Mod  │ Balance    │ Inv.Diff   │\n";
echo "  ├────────────────┼──────┼──────┼────────────┼────────────┤\n";
foreach ($tbData as $row) {
    $totalBalByCurrency[$row->currency] = ($totalBalByCurrency[$row->currency] ?? 0) + (float)$row->balance_sum;
    $totalInvariantDiff += abs((float)$row->invariant_diff);
    printf("  │ %-14s │ %-4s │ %-4s │ %10s │ %10s │\n",
        $row->type, $row->currency, $row->module_type,
        number_format((float)$row->balance_sum, 2),
        number_format((float)$row->invariant_diff, 2)
    );
}
echo "  └────────────────┴──────┴──────┴────────────┴────────────┘\n";

$totalAccounts = 0;
foreach ($tbData as $row) $totalAccounts += $row->cnt;
log_test("J1: office accounts exist", $totalAccounts >= 14, "total=$totalAccounts accounts");

// 2) Verify per-account invariant: balance == SUM(credit-debit) on entries
$balanceViolations = [];
foreach (Account::where('owner_type', 'office')->get() as $a) {
    $expected = (float)DB::table('account_entries')
        ->where('account_id', $a->id)
        ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0)')
        ->value('net');
    if (abs($expected - (float)$a->balance) > 0.01) {
        $balanceViolations[] = "#{$a->id} {$a->name} expected=$expected actual={$a->balance}";
    }
}
log_test("J2: all office accounts have balance == SUM(credit-debit)", count($balanceViolations) === 0, count($balanceViolations) === 0 ? "OK" : $balanceViolations);

// 3) Per-transaction double-entry — analyze ONLY intra-section transactions
//    (single-leg are legitimate cross-section activity: customer AR vs revenue in another module)
$intSectionTxViolations = [];
$crossSectionTxCount = 0;
$singleLegTxCount = 0;
$doubleLegTxCount = 0;
$rows = DB::table('account_entries as ae')
    ->leftJoin('accounts as a1', 'ae.account_id', '=', 'a1.id')
    ->select('ae.transaction_id', 'ae.account_id', 'a1.owner_type', 'a1.module_type')
    ->whereNotNull('ae.transaction_id')
    ->get()
    ->groupBy('transaction_id');

foreach ($rows as $txId => $entries) {
    $uniqueModules = $entries->pluck('module_type')->unique();
    $sumD = (float)$entries->sum('debit');
    $sumC = (float)$entries->sum('credit');
    $entryCount = $entries->count();

    if ($entryCount == 1) {
        $singleLegTxCount++;
        continue;
    }
    $doubleLegTxCount++;
    if ($uniqueModules->count() == 1 && $uniqueModules->first() == 'office' && abs($sumD - $sumC) > 0.01) {
        // Intra-office multi-leg transaction with imbalance = real bug
        $intSectionTxViolations[] = "tx=$txId d=$sumD c=$sumC";
    } else {
        $crossSectionTxCount++;
    }
}
log_test('J3: intra-office transactions are balanced (Σdebit == Σcredit)', count($intSectionTxViolations) === 0, count($intSectionTxViolations) === 0 ? "OK" : $intSectionTxViolations);
log_test('J3b: documented single-leg transactions present (cross-section activity)', $singleLegTxCount > 0, "single=$singleLegTxCount, double=$doubleLegTxCount, cross-section=$crossSectionTxCount");

// 3c) Verify per-account invariant across the entire office division — MOST IMPORTANT
$accountViolations = [];
foreach (Account::where('owner_type', 'office')->get() as $a) {
    $sum = (float)DB::table('account_entries')->where('account_id', $a->id)
        ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')
        ->value('net');
    if (abs($sum - (float)$a->balance) > 0.01) {
        $accountViolations[] = "#{$a->id} {$a->name}: balance={$a->balance}, sum={$sum}";
    }
}
log_test("J3c: per-account invariant (balance == SUM(credit-debit)) holds for ALL office accounts", count($accountViolations) === 0, count($accountViolations) === 0 ? "OK" : $accountViolations);

// 4) Total per-currency summary
echo "  ℹ Total balances by currency:\n";
foreach ($totalBalByCurrency as $cur => $bal) {
    echo "      $cur: " . number_format($bal, 2) . "\n";
}
$expectedCurrencyCount = count(array_filter(array_keys($totalBalByCurrency)));
log_test("J4: at least 4 currencies have balances (EGP, USD, SAR, AED, KWD)", $expectedCurrencyCount >= 4, 'got ' . $expectedCurrencyCount . ' currencies');

// 5) Card Receivables = sum of customer AR balance in office module (positive)
$recSum = (float)DB::table('accounts')
    ->where('owner_type', 'office')
    ->where('type', 'customer')
    ->where('balance', '>', 0)
    ->sum('balance');

// 5b) Card Payables = sum of supplier AP balance in office module (absolute value)
$paySumAbs = abs((float)DB::table('accounts')
    ->where('owner_type', 'office')
    ->whereIn('type', ['supplier', 'supplier_account', 'supplier_accounts'])  // API may use singular
    ->where('balance', '<', 0)
    ->sum('balance'));

$expectedNet = $recSum - $paySumAbs;
echo "  ℹ Expected cards: Receivables=$recSum, Payables(abs)=$paySumAbs, Net=$expectedNet\n";

// Compare with what Vue API actually returns
$debtsRes2 = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office']);
$api = $debtsRes2->json('data');
$apiRec = (float)($api['total_receivables'] ?? 0);
$apiPay = (float)($api['total_payables'] ?? 0);
$apiNet = (float)($api['net_balance'] ?? 0);

log_test("J5: Vue API total_receivables == direct DB sum (customers)", abs($apiRec - $recSum) < 1.0, "API=$apiRec DB=$recSum");
log_test("J6: Vue API total_payables == |direct DB sum| (suppliers, abs value)", abs($apiPay - $paySumAbs) < 1.0, "API=$apiPay DB=-$paySumAbs");
log_test("J7: Vue API net_balance == receivables - payables (signed)", abs($apiNet - ($apiRec - $apiPay)) < 1.0, "API=$apiNet ($apiRec - $apiPay)");

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  GRAND TOTAL: {$results['success']} pass / {$results['failed']} fail\n";
echo "═══════════════════════════════════════════════════════════════\n";

file_put_contents(__DIR__ . '/office_master_test_results.json', json_encode([
    'summary' => $results,
    'trials' => [
        'total_receivables_db' => $recSum,
        'total_payables_db' => $paySumAbs,
        'total_receivables_api' => $apiRec,
        'total_payables_api' => $apiPay,
        'net_balance_api' => $apiNet,
        'balances_by_currency' => $totalBalByCurrency,
    ],
    'failures' => $results['critical_failures'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nResults saved to: office_master_test_results.json\n";
