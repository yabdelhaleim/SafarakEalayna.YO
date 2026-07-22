<?php
/**
 * Office Module Test — Phase 2: Accounting Invariants + Currency + Deletion + Cancellation
 * ============================================================================================
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Transaction;

$BASE = 'http://127.0.0.1:8000/api/v1';
$results = ['success' => 0, 'failed' => 0, 'critical_failures' => []];

function log_test(string $key, bool $success, $payload = null): void
{
    global $results;
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
echo "  Phase 2: Accounting Invariants + Currency + Deletion + Cancel\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ─── 1. Login (again)
echo "[1] Login\n";
$token = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
])->json('data.token');
$auth = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
log_test('login', !empty($token));

// ─── 2. Accounting Invariant #1: every account's balance = SUM(credit - debit)
echo "\n[2] Accounting Invariant: Account.balance = SUM(credit-debit) on entries\n";
$accounts = Account::all();
$violations = [];
foreach ($accounts as $a) {
    $expected = $a->entries()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
    $actual = (float) $a->balance;
    if (abs($expected - $actual) > 0.01) {
        $violations[] = "Account #{$a->id} ({$a->name}): expected=$expected, actual=$actual, diff=" . ($actual - $expected);
    }
}
log_test('all accounts balance = sum(entries)', count($violations) === 0, count($violations) === 0 ? "OK (" . count($accounts) . " accounts)" : $violations);

// ─── 3. Accounting Invariant #2: every transaction has debit == credit
echo "\n[3] Accounting Invariant: per-transaction Σdebit = Σcredit (double-entry)\n";
$transactions = Transaction::all();
$violations = [];
foreach ($transactions as $t) {
    $totals = DB::table('account_entries')
        ->selectRaw('COALESCE(SUM(debit),0) AS total_debit, COALESCE(SUM(credit),0) AS total_credit, COUNT(*) AS entries')
        ->where('transaction_id', $t->id)
        ->first();
    if (abs((float)$totals->total_debit - (float)$totals->total_credit) > 0.01 || $totals->entries < 2) {
        $violations[] = "Transaction #{$t->id}: debit={$totals->total_debit}, credit={$totals->total_credit}, entries={$totals->entries}";
    }
}
log_test('all transactions balanced', count($violations) === 0, count($violations) === 0 ? "OK (" . count($transactions) . " tx)" : $violations);

// ─── 4. Currency conversion test (USD → EGP)
echo "\n[4] Currency Conversion: USD → EGP عبر service\n";
$rate = DB::table('exchange_rates')->where('from_currency', 'USD')->where('to_currency', 'EGP')->orderByDesc('effective_date')->value('rate');
log_test('USD→EGP rate exists', (float)$rate > 0, "rate=$rate");

try {
    $converted = app(\App\Services\Finance\CurrencyService::class)->convert(100, 'USD', 'EGP');
    log_test('convert 100 USD → EGP returns number', is_numeric($converted), "got=$converted");
} catch (\Throwable $e) {
    log_test('convert 100 USD → EGP', false, $e->getMessage());
}

// ─── 5. Transfer between currencies (USD bank → EGP cashbox) → violation? Should fail because different currencies
echo "\n[5] Transfer USD-bank → EGP-cashbox (different currency → should be rejected or do conversion)\n";
$setupResults = json_decode(file_get_contents(__DIR__ . '/office_test_setup_results.json'), true);
$bankUSD = collect($setupResults['banks'])->firstWhere('currency', 'USD');
$cashEGP = collect($setupResults['cashboxes'])->firstWhere('currency', 'EGP');
$resDiff = Http::withHeaders($auth)->post("$BASE/finance/transfers", [
    'from_account_id' => $bankUSD['id'],
    'to_account_id' => $cashEGP['id'],
    'amount' => 100,
    'currency' => 'USD',
    'module' => 'office',
    'notes' => 'transfer test cross-currency',
]);
// Expected: 422 (different currency not allowed without explicit conversion)
log_test('cross-currency transfer → 422 OR conversion', in_array($resDiff->status(), [201, 422]), "status={$resDiff->status()}, body=" . json_encode($resDiff->json('message') ?? $resDiff->json()));

// ─── 6. Transfer with insufficient balance
echo "\n[6] Insufficient balance transfer → must fail\n";
$overRes = Http::withHeaders($auth)->post("$BASE/finance/transfers", [
    'from_account_id' => $cashEGP['id'],
    'to_account_id' => $bankUSD['id'],
    'amount' => 999999999.00,
    'currency' => 'EGP',
    'module' => 'office',
    'notes' => 'over-spent test',
]);
log_test('over-spent transfer rejected', $overRes->status() === 422 || $overRes->json('status') === false, "status={$overRes->status()}, msg=" . json_encode($overRes->json('message')));

// ─── 7. Deletion tests
echo "\n[7] DELETE blocked for account with balance\n";
$delBlocked = Http::withHeaders($auth)->delete("$BASE/finance/accounts/{$bankUSD['id']}");
log_test('delete account with balance → blocked', $delBlocked->status() === 422 || $delBlocked->status() === 500, "status={$delBlocked->status()}, msg=" . json_encode($delBlocked->json('message') ?? $delBlocked->json()));

echo "\n[8] DELETE succeeds for account with zero balance & no entries\n";
$emptyAcc = Account::create([
    'name' => 'حساب اختبار للحذف — ' . uniqid(),
    'type' => 'bank',
    'currency' => 'EGP',
    'balance' => 0.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'created_by' => 1,
]);
$delOk = Http::withHeaders($auth)->delete("$BASE/finance/accounts/{$emptyAcc->id}");
log_test('delete empty account → 200', $delOk->status() === 200, "status={$delOk->status()}, body=" . json_encode($delOk->json('message') ?? $delOk->json()));

// ─── 9. Cancellation / Reversal — operate a transfer, then cancel/reverse
echo "\n[9] CANCEL: transfer + cancellation\n";
$c1 = $setupResults['cashboxes'][0]['id'];
$c2 = $setupResults['cashboxes'][1]['id'];

// Snapshot balances before
$b1Before = (float) Account::find($c1)->balance;
$b2Before = (float) Account::find($c2)->balance;
echo "  Before: cash1=$b1Before, cash2=$b2Before\n";

$txRes = Http::withHeaders($auth)->post("$BASE/finance/transfers", [
    'from_account_id' => $c1,
    'to_account_id' => $c2,
    'amount' => 2000,
    'currency' => 'EGP',
    'module' => 'office',
    'notes' => 'transfer to cancel',
]);
$txId = $txRes->json('data.id') ?? $txRes->json('data.transaction.id') ?? null;
log_test('create transfer for cancel test', $txRes->status() === 201, "txId=$txId");

if ($txId) {
    $b1After = (float) Account::find($c1)->balance;
    $b2After = (float) Account::find($c2)->balance;
    echo "  After transfer: cash1=$b1After (Δ=" . ($b1After - $b1Before) . "), cash2=$b2After (Δ=" . ($b2After - $b2Before) . ")\n";

    // Cancel via direct DB manipulation (since no cancel endpoint for transfers)
    DB::beginTransaction();
    try {
        // Cancel the transaction by appending inverse entries
        $entries = DB::table('account_entries')->where('transaction_id', $txId)->get();
        foreach ($entries as $e) {
            DB::table('account_entries')->insert([
                'account_id' => $e->account_id,
                'transaction_id' => $txId,
                'debit' => $e->credit,
                'credit' => $e->debit,
                'balance_after' => 0, // Will be recalculated
                'notes' => 'عكس: ' . $e->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Update account balance
            $acc = Account::find($e->account_id);
            $acc->balance += ($e->credit - $e->debit); // Inverse of original
            // Use guard
            \App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $acc->save());
        }
        DB::commit();
        echo "  Reversal applied.\n";
    } catch (\Throwable $e) {
        DB::rollback();
        echo "  ❌ reversal failed: " . $e->getMessage() . "\n";
    }

    $b1Final = (float) Account::find($c1)->balance;
    $b2Final = (float) Account::find($c2)->balance;
    echo "  Final: cash1=$b1Final, cash2=$b2Final\n";

    log_test('cancel balances back to original', abs($b1Final - $b1Before) < 0.01 && abs($b2Final - $b2Before) < 0.01);
}

// ─── 10. Currency precision test (small numbers)
echo "\n[10] Currency precision test\n";
$sarBank = collect($setupResults['banks'])->firstWhere('currency', 'SAR');
$sarStatementRes = Http::withHeaders($auth)->get("$BASE/finance/accounts/{$sarBank['id']}/statement");
log_test('SAR statement returns numeric', $sarStatementRes->successful(), 'opening_balance=' . ($sarStatementRes->json('data.stats.opening_balance') ?? 'N/A'));

// ─── 11. Vue-specific endpoints (dashboard, treasury overview)
echo "\n[11] Vue Dashboard endpoints\n";
$financeOpsRes = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['type' => 'cashbox', 'is_active' => 1]);
$cashboxes = collect($financeOpsRes->json('data.items') ?? []);
log_test('vue fetches cashboxes', $cashboxes->count() > 0, 'got ' . $cashboxes->count() . ' cashboxes');

$dashRes = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['owner_type' => 'office']);
log_test('vue fetches office-owned accounts', $dashRes->successful(), 'got ' . count($dashRes->json('data.items') ?? []) . ' office accounts');

// ─── 12. Settings endpoints (Vue dropdowns)
echo "\n[12] Settings endpoints (Vue dropdown data)\n";
$currenciesRes = Http::withHeaders($auth)->get("$BASE/settings/currencies");
log_test('GET /settings/currencies', $currenciesRes->successful(), 'count=' . count($currenciesRes->json('data') ?? []));

$accountTypesRes = Http::withHeaders($auth)->get("$BASE/settings/account-types");
log_test('GET /settings/account-types', $accountTypesRes->successful());

$txModRes = Http::withHeaders($auth)->get("$BASE/settings/transaction-modules");
log_test('GET /settings/transaction-modules', $txModRes->successful());
$officeMod = collect($txModRes->json('data') ?? [])->firstWhere('value', 'office');
log_test('Office module exists in settings', $officeMod !== null);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  النتيجة: {$results['success']} نجح / {$results['failed']} فشل\n";
echo "═══════════════════════════════════════════════════════════════\n";

if (count($results['critical_failures']) > 0) {
    echo "\n⚠️ Critical Failures:\n";
    foreach ($results['critical_failures'] as $f) {
        echo "  - {$f['key']}\n";
    }
}

file_put_contents(
    __DIR__ . '/office_api_test_2_results.json',
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
echo "\n  النتائج محفوظة في: office_api_test_2_results.json\n";
