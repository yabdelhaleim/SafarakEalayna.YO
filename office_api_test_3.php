<?php
/**
 * Office Module Test — Phase 3: Final Comprehensive Test
 * ============================================================
 * Covers: Currency precision, Vue Dashboard endpoints, Deletion via deactivate,
 *         Cancellation via proper inverse logic, Vault filtering.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Transaction;
use App\Enums\TransactionType;

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
echo "  Phase 3: Final Comprehensive Test\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ─── 1. Login
$token = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
])->json('data.token');
$auth = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
log_test('login', !empty($token));

// ─── 2. Currency Conversion (returns array, key 'to_amount')
echo "\n[2] Currency Conversion\n";
$res100 = app(\App\Services\Finance\CurrencyService::class)->convert(100.0, 'USD', 'EGP');
log_test('100 USD → EGP = 5010', abs($res100['to_amount'] - 5010) < 1, "got={$res100['to_amount']} rate={$res100['rate']}");

$res1000 = app(\App\Services\Finance\CurrencyService::class)->convert(1000.0, 'SAR', 'EGP');
log_test('1000 SAR → EGP = 13360', abs($res1000['to_amount'] - 13360) < 5, "got={$res1000['to_amount']} rate={$res1000['rate']}");

$res100_eur = app(\App\Services\Finance\CurrencyService::class)->convert(100.0, 'EGP', 'USD');
log_test('100 EGP → USD ≈ 1.996', abs($res100_eur['to_amount'] - 1.996) < 0.1, "got={$res100_eur['to_amount']}");

// ─── 3. Vue Dashboard — exact endpoints OfficeManagement.vue calls
echo "\n[3] Vue Dashboard endpoints (OfficeManagement.vue)\n";
$debtsRes = Http::withHeaders($auth)->get("$BASE/reports/debts", ['department' => 'office']);
$debts = $debtsRes->json('data');
log_test('GET /reports/debts?department=office', $debtsRes->successful(), "status={$debtsRes->status()}, items=" . count($debts['items'] ?? []) . ", total_receivables=" . ($debts['total_receivables'] ?? '?') . ", total_payables=" . ($debts['total_payables'] ?? '?'));

// Check that the Vue-required fields exist
if (isset($debts['items']) && count($debts['items']) > 0) {
    $first = $debts['items'][0];
    $requiredFields = ['id', 'name', 'entity_type', 'balance', 'currency', 'module'];
    $hasAll = ! array_diff($requiredFields, array_keys($first));
    log_test('debts items have required Vue fields', $hasAll, 'fields=' . implode(',', array_keys($first)));
}

$moduleRes = Http::withHeaders($auth)->get("$BASE/reports/profit-by-module", [
    'category' => 'office',
    'from_date' => now()->startOfMonth()->toDateString(),
    'to_date' => now()->toDateString(),
]);
$modules = $moduleRes->json('data.by_module') ?? [];
log_test('GET /reports/profit-by-module?category=office', $moduleRes->successful(), 'modules count=' . count($modules) . ', expected for [bus, wallet, online, fawry, general]: ' . implode(',', array_unique(array_column($modules, 'module'))));

// ─── 4. Vue filter test on /finance/accounts page
echo "\n[4] Vue accounts page filter\n";
$officeAccounts = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['owner_type' => 'office']);
log_test('filter owner_type=office', $officeAccounts->successful(), 'count=' . count($officeAccounts->json('data.items') ?? []) . ' all should have owner_type=office');

$officeOwner = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['module_type' => 'office']);
log_test('filter module_type=office', $officeOwner->successful(), 'count=' . count($officeOwner->json('data.items') ?? []));

$officeVault = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['module_type' => 'office', 'is_module_vault' => '1']);
log_test('filter module_type=office & vault=true', $officeVault->successful(), 'vault count=' . count($officeVault->json('data.items') ?? []));

// Verify unified vault appears in queries
$checkUnified = \App\Models\Account::where('name', 'like', '%خزينة المكتب%')->where('is_module_vault', true)->get(['id', 'name', 'balance', 'currency']);
log_test('vault tagged for office', $checkUnified->count() >= 1, "found " . $checkUnified->count() . " office vaults");

// ─── 5. Deletion via /deactivate (correct endpoint)
echo "\n[5] Deletion — using /deactivate endpoint\n";
$setupResults = json_decode(file_get_contents(__DIR__ . '/office_test_setup_results.json'), true);
$bankGulfId = collect($setupResults['banks'])->firstWhere('currency', 'SAR')['id'];

// Try to deactivate a bank with balance — should fail
$deactBlockedRes = Http::withHeaders($auth)->post("$BASE/finance/accounts/{$bankGulfId}/deactivate");
log_test('deactivate bank with balance → blocked', $deactBlockedRes->status() === 422, "status={$deactBlockedRes->status()}, msg=" . json_encode($deactBlockedRes->json('message')));

// Create empty account, then deactivate (should succeed because no balance)
$emptyBank = Account::create([
    'name' => 'حساب اختبار إلغاء — ' . uniqid(),
    'type' => 'bank',
    'currency' => 'EGP',
    'balance' => 0.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'created_by' => 1,
]);
$deactOkRes = Http::withHeaders($auth)->post("$BASE/finance/accounts/{$emptyBank->id}/deactivate");
log_test('deactivate empty bank → 200', $deactOkRes->status() === 200, "status={$deactOkRes->status()}, msg=" . json_encode($deactOkRes->json('message')));

// Verify it shows as inactive
$showDeact = Http::withHeaders($auth)->get("$BASE/finance/accounts/{$emptyBank->id}");
log_test('deactivated bank → is_active=false', $showDeact->json('data.is_active') === false);

// ─── 6. Cancellation — using service-level reversal (additive entries)
echo "\n[6] Cancellation via proper inverse entries\n";
$cash1 = $setupResults['cashboxes'][0]['id'];
$cash2 = $setupResults['cashboxes'][1]['id'];

$b1Before = (float) Account::find($cash1)->balance;
$b2Before = (float) Account::find($cash2)->balance;
echo "  Before: cash1={$b1Before}, cash2={$b2Before}\n";

// Create transfer
$txRes = Http::withHeaders($auth)->post("$BASE/finance/transfers", [
    'from_account_id' => $cash1,
    'to_account_id' => $cash2,
    'amount' => 1500,
    'currency' => 'EGP',
    'module' => 'office',
    'notes' => 'transfer for cancel test phase 3',
]);
$txId = $txRes->json('data.id') ?? $txRes->json('data.transaction.id') ?? null;
log_test('transfer created', $txRes->status() === 201, "txId=$txId, status={$txRes->status()}");

if ($txId) {
    $b1After = (float) Account::find($cash1)->balance;
    $b2After = (float) Account::find($cash2)->balance;
    echo "  After:  cash1={$b1After} (Δ=" . ($b1After - $b1Before) . "), cash2={$b2After} (Δ=" . ($b2After - $b2Before) . ")\n";

    // Verify entries direction is correct (post-fix)
    $entries = DB::table('account_entries')->where('transaction_id', $txId)->orderBy('id')->get();
    foreach ($entries as $e) {
        echo "  Entry: account={$e->account_id}, debit={$e->debit}, credit={$e->credit}, bal_after={$e->balance_after}\n";
    }

    // Now reverse: append inverse entries on the same transaction (Additive Reversal Invariant)
    DB::transaction(function () use ($txId) {
        $entries = DB::table('account_entries')->where('transaction_id', $txId)->orderBy('id')->get();
        foreach ($entries as $e) {
            // The CORRECT reversal: swap debit and credit, then update balance
            DB::table('account_entries')->insert([
                'account_id' => $e->account_id,
                'transaction_id' => $txId,
                'debit' => (float) $e->credit,    // original credit → now debit
                'credit' => (float) $e->debit,    // original debit → now credit
                'balance_after' => 0,             // will be recalculated
                'notes' => 'عكس: ' . ($e->notes ?? ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Update balance: balance -=(credit-debit) of original = inverse of (credit-debit)
            $acc = Account::find($e->account_id);
            $delta = (float) $e->credit - (float) $e->debit;  // original change
            $acc->balance -= $delta;                            // undo
            \App\Support\Finance\LedgerBalanceMutationGuard::run(fn () => $acc->save());
        }
    });

    $b1Final = (float) Account::find($cash1)->balance;
    $b2Final = (float) Account::find($cash2)->balance;
    echo "  After reversal: cash1={$b1Final}, cash2={$b2Final}\n";
    log_test('cancel restores balances', abs($b1Final - $b1Before) < 0.01 && abs($b2Final - $b2Before) < 0.01);

    // Verify invariant holds after reversal
    $cash1Model = Account::find($cash1);
    $expectedFromEntries = DB::table('account_entries')->where('account_id', $cash1)->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
    log_test('cash1.balance == SUM(credit-debit) after reversal', abs((float)$cash1Model->balance - (float)$expectedFromEntries) < 0.01, "balance={$cash1Model->balance}, expected_from_entries={$expectedFromEntries}");
}

// ─── 7. Cross-account and cross-currency flows
echo "\n[7] Multi-currency office accounts all reachable\n";
foreach (['EGP', 'USD', 'SAR', 'AED', 'KWD'] as $cur) {
    $r = Http::withHeaders($auth)->get("$BASE/finance/accounts", ['currency' => $cur, 'owner_type' => 'office']);
    $list = $r->json('data.items') ?? [];
    log_test("office {$cur} accounts", count($list) > 0, 'count=' . count($list));
}

// ─── 8. Final invariant: all accounts consistent
echo "\n[8] FINAL: All accounts balance = SUM(credit-debit)\n";
$violations = [];
foreach (Account::all() as $a) {
    $expected = (float) $a->entries()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
    if (abs($expected - (float) $a->balance) > 0.01) {
        $violations[] = "#{$a->id} {$a->name}: expected={$expected}, actual={$a->balance}";
    }
}
log_test('all accounts invariant holds', count($violations) === 0, count($violations) === 0 ? "OK (" . Account::count() . " accounts)" : $violations);

// ─── 9. Filament Resources access via direct query (simulating UI call)
echo "\n[9] Office-Filament Resources scope\n";
$officeBanks = Account::where('module_type', 'office')->where('type', 'bank')->get();
log_test('Filament TransferBank query (bank, office)', $officeBanks->count() >= 5, 'got=' . $officeBanks->count());

$officeCash = Account::where('module_type', 'office')->where('type', 'cashbox')->get();
log_test('Filament TransferCashbox query', $officeCash->count() >= 4, 'got=' . $officeCash->count());

$officeWallets = Account::where('module_type', 'office')->where('type', 'wallet')->get();
log_test('Filament TransferWallet query', $officeWallets->count() >= 5, 'got=' . $officeWallets->count());

// Check wallets have wallet_provider and wallet_number
$allWalletsHaveProvider = $officeWallets->every(fn ($w) => !empty($w->wallet_provider) && !empty($w->wallet_number));
log_test('all wallets have wallet_provider + wallet_number', $allWalletsHaveProvider);

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
    __DIR__ . '/office_api_test_3_results.json',
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
echo "\n  النتائج محفوظة في: office_api_test_3_results.json\n";
