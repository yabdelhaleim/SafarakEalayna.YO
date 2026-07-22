<?php
/**
 * Office Module Test — API Endpoints
 * ============================================
 * Tests all API endpoints after login (auth:sanctum + active + admin middleware).
 * Run via: php office_api_test_1.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

$BASE = 'http://127.0.0.1:8000/api/v1';
$results = ['success' => 0, 'failed' => 0, 'logs' => []];

function log_test(string $key, bool $success, $payload = null): void
{
    global $results;
    $results['logs'][] = ['key' => $key, 'success' => $success, 'payload' => $payload];
    if ($success) {
        $results['success']++;
        echo "  ✅ $key\n";
    } else {
        $results['failed']++;
        echo "  ❌ $key — " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  اختبار APIs لموديول المكتب\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ─── Step 1: Login
echo "[1] تسجيل الدخول\n";
$loginRes = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
$loginData = $loginRes->json();
$token = $loginData['data']['token'] ?? null;
log_test('login', $loginRes->successful() && $token !== null, "status={$loginRes->status()}, hasToken=" . ($token ? 'YES' : 'NO'));

if (!$token) {
    echo "Login failed. Aborting.\n";
    var_dump($loginData);
    exit(1);
}

$authHeader = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// ─── Step 2: GET /finance/accounts (index)
echo "\n[2] GET /finance/accounts\n";
$accountsRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts");
$accounts = $accountsRes->json();
log_test('accounts.list (200)', $accountsRes->successful(), "status={$accountsRes->status()}, items=" . count($accounts['data']['items'] ?? []));

if (isset($accounts['data']['items']) && count($accounts['data']['items']) > 0) {
    $firstBank = collect($accounts['data']['items'])->firstWhere('type', 'bank');
    log_test('accounts.list has banks', $firstBank !== null, 'first bank: ' . ($firstBank['name'] ?? 'N/A'));
}

// Test filter by type=cashbox
$cashboxRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts", ['type' => 'cashbox']);
log_test('accounts.filter cashbox', $cashboxRes->successful() && collect($cashboxRes->json('data.items'))->every(fn($i) => $i['type'] === 'cashbox'), 'all items type=cashbox? ' . (collect($cashboxRes->json('data.items'))->every(fn($i) => $i['type'] === 'cashbox') ? 'YES' : 'NO'));

// Test filter by type=wallet + wallet_provider
$walletRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts", ['type' => 'wallet', 'wallet_provider' => 'vodafone_cash']);
log_test('accounts.filter wallet+vodafone', $walletRes->successful(), 'items=' . count($walletRes->json('data.items') ?? []));

// Test filter by currency
$usdRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts", ['currency' => 'USD']);
log_test('accounts.filter currency=USD', $usdRes->successful(), 'USD items=' . count($usdRes->json('data.items') ?? []));

// Test filter by is_active
$activeRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts", ['is_active' => 1]);
log_test('accounts.filter is_active=true', $activeRes->successful(), 'active items=' . count($activeRes->json('data.items') ?? []));

// Search by name
$searchRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts", ['search' => 'الأهلي']);
log_test('accounts.search "الأهلي"', $searchRes->successful(), 'results=' . count($searchRes->json('data.items') ?? []));

// ─── Step 3: Test create a new office account via API
echo "\n[3] POST /finance/accounts — إنشاء حساب جديد عبر API\n";
$bankCreateRes = Http::withHeaders($authHeader)->post("$BASE/finance/accounts", [
    'name' => 'بنك QNB — كويتي (Test API)',
    'type' => 'bank',
    'currency' => 'KWD',
    'balance' => 100.000,
    'is_active' => true,
    'module_type' => 'office',
    'owner_type' => 'office',
    'notes' => 'حساب تم إنشاؤه عبر API للتيست',
]);
log_test('create bank (KWD)', $bankCreateRes->status() === 201, "status={$bankCreateRes->status()}, body=" . json_encode($bankCreateRes->json('message')));
$newBankId = $bankCreateRes->json('data.id');

// Create wallet with required fields
$walletCreateRes = Http::withHeaders($authHeader)->post("$BASE/finance/accounts", [
    'name' => 'محفظة بريد Test API',
    'type' => 'wallet',
    'currency' => 'EGP',
    'balance' => 1000.00,
    'is_active' => true,
    'module_type' => 'office',
    'owner_type' => 'office',
    'wallet_provider' => 'postal',
    'wallet_number' => '01234567890',
    'notes' => 'محفظة بريدية تم إنشاؤها عبر API',
]);
log_test('create wallet (postal)', $walletCreateRes->status() === 201, "status={$walletCreateRes->status()}, message=" . json_encode($walletCreateRes->json('message')));
$newWalletId = $walletCreateRes->json('data.id');

// Try to create wallet without required wallet_provider (should fail validation)
$invalidWalletRes = Http::withHeaders($authHeader)->post("$BASE/finance/accounts", [
    'name' => 'محفظة غير صالحة',
    'type' => 'wallet',
    'currency' => 'EGP',
    'balance' => 100,
    'is_active' => true,
    'module_type' => 'office',
]);
log_test('create wallet without provider → 422', $invalidWalletRes->status() === 422, "status={$invalidWalletRes->status()}, errors=" . json_encode($invalidWalletRes->json('errors') ?? []));

// ─── Step 4: GET /finance/accounts/{id}
echo "\n[4] GET /finance/accounts/{id}\n";
if ($newBankId) {
    $showRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts/$newBankId");
    log_test('show new bank', $showRes->successful(), 'name=' . ($showRes->json('data.name') ?? 'N/A'));
}

// ─── Step 5: GET /finance/accounts/{id}/statement
echo "\n[5] GET /finance/accounts/{id}/statement — كشف حساب\n";
$setupResults = json_decode(file_get_contents(__DIR__ . '/office_test_setup_results.json'), true);
$bank1Id = $setupResults['banks'][0]['id'] ?? null;
if ($bank1Id) {
    $stmtRes = Http::withHeaders($authHeader)->get("$BASE/finance/accounts/$bank1Id/statement");
    $stmt = $stmtRes->json('data');
    log_test('statement of new bank', $stmtRes->successful(), 'opening_balance=' . ($stmt['stats']['opening_balance'] ?? 'N/A') . ', period_credit=' . ($stmt['stats']['period_credit'] ?? 0));
}

// ─── Step 6: Transfer between accounts
echo "\n[6] POST /finance/transfers — تحويلات\n";
$cash1Id = $setupResults['cashboxes'][0]['id'];
$cash2Id = $setupResults['cashboxes'][1]['id'];
$transferRes = Http::withHeaders($authHeader)->post("$BASE/finance/transfers", [
    'from_account_id' => $cash1Id,
    'to_account_id' => $cash2Id,
    'amount' => 5000.00,
    'notes' => 'تحويل تجريبي لاختبار الـ API',
    'module' => 'office',
    'currency' => 'EGP',
]);
log_test('transfer cashbox1→cashbox2 (5000 EGP)', $transferRes->status() === 201, "status={$transferRes->status()}, body=" . json_encode($transferRes->json('message')));

if ($transferRes->successful()) {
    $txId = $transferRes->json('data.transaction.id') ?? $transferRes->json('data.transaction_id') ?? null;

    // ─── Step 7: Transfer History
    echo "\n[7] GET /finance/transfers — سجل التحويلات\n";
    $thRes = Http::withHeaders($authHeader)->get("$BASE/finance/transfers");
    $thItems = $thRes->json('data.items') ?? [];
    log_test('transfer history list', $thRes->successful(), 'transfers count=' . count($thItems));

    // Check balances after transfer
    echo "\n[8] التحقق من الأرصدة بعد التحويل\n";
    $c1 = Http::withHeaders($authHeader)->get("$BASE/finance/accounts/$cash1Id")->json('data');
    $c2 = Http::withHeaders($authHeader)->get("$BASE/finance/accounts/$cash2Id")->json('data');
    $expectedC1 = (float)$setupResults['cashboxes'][0]['balance'] - 5000;
    $expectedC2 = (float)$setupResults['cashboxes'][1]['balance'] + 5000;
    log_test("cashbox1 balance = {$c1['balance']} (expected $expectedC1)", abs((float)$c1['balance'] - $expectedC1) < 0.01);
    log_test("cashbox2 balance = {$c2['balance']} (expected $expectedC2)", abs((float)$c2['balance'] - $expectedC2) < 0.01);
}

// ─── Step 9: Reports
echo "\n[9] GET /reports/debts?department=office — تقرير مديونيات المكتب\n";
$debtsRes = Http::withHeaders($authHeader)->get("$BASE/reports/debts", ['department' => 'office']);
$debts = $debtsRes->json('data');
log_test('reports/debts?department=office', $debtsRes->successful(), 'total_receivables=' . ($debts['total_receivables'] ?? '?') . ', total_payables=' . ($debts['total_payables'] ?? '?') . ', items=' . count($debts['items'] ?? []));

// ─── Step 10: Office Trial Balance
echo "\n[10] GET /reports/office-trial-balance\n";
$trialRes = Http::withHeaders($authHeader)->get("$BASE/reports/office-trial-balance");
log_test('reports/office-trial-balance', $trialRes->successful(), 'status=' . $trialRes->status());

// ─── Step 11: Health check
echo "\n[11] GET /health\n";
$healthRes = Http::withHeaders($authHeader)->get("$BASE/health");
log_test('health endpoint', $healthRes->successful(), 'status=' . $healthRes->status());

// ─── Step 12: Logout
echo "\n[12] POST /auth/logout\n";
$logoutRes = Http::withHeaders($authHeader)->post("$BASE/auth/logout");
log_test('logout', $logoutRes->successful(), 'status=' . $logoutRes->status());

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  النتيجة: {$results['success']} نجح / {$results['failed']} فشل\n";
echo "═══════════════════════════════════════════════════════════════\n";

file_put_contents(
    __DIR__ . '/office_api_test_1_results.json',
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
echo "  النتائج محفوظة في: office_api_test_1_results.json\n";
