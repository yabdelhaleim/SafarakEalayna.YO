<?php
/**
 * HARD USER TEST - Reproduces the exact scenarios described
 * by the user in Arabic:
 *  - عميل مسجل، 2 عمليات على المحفظة → العميل يظهر اسمه في "آخر عمليات المحفظة"
 *  - تسديد دين → العملية تظهر في كشف الحساب
 *  - تقرير الديون الموحد → فوري/محافظ/خدمات إلكترونية كلها ظاهرة
 */

require __DIR__ . '/../vendor/autoload.php';
chdir(__DIR__ . '/..');
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';

// Step 1: Authenticate
$login = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
if (! $login->successful()) {
    fwrite(STDERR, "Login failed: " . $login->body() . "\n");
    exit(1);
}
$token = $login->json('data.token') ?? $login->json('data.access_token') ?? $login->json('token');
echo "✅ Login OK\n";
$auth = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// Step 2: Get the TransferDashboard's recent_transactions (this is what shows "آخر عمليات المحفظة")
echo "\n=== SCENARIO 1: آخر عمليات المحفظة - TransferDashboard.vue ===\n";
$dash = Http::withHeaders($auth)->get("$BASE/wallet/dashboard");
if ($dash->successful()) {
    $payload = $dash->json('data');
    $recent = $payload['recent_transactions'] ?? [];
    echo "Recent transactions: " . count($recent) . "\n";
    $names_ok = 0;
    $names_missing = 0;
    foreach (array_slice($recent, 0, 5) as $tx) {
        $name = $tx['customer_name'] ?? $tx['client_name'] ?? null;
        if ($name && $name !== '—' && $name !== '') {
            $names_ok++;
            echo "  ✅ id={$tx['id']}, name='$name'\n";
        } else {
            $names_missing++;
            echo "  ❌ id={$tx['id']}, name=EMPTY (customer_name=" . json_encode($tx['customer_name'] ?? null) . ")\n";
        }
    }
    echo "Summary: $names_ok have names, $names_missing missing\n";
}

// Step 3: Wallet Customer Balances
echo "\n=== SCENARIO 2: مديونيات العملاء (المحافظ) ===\n";
$cb = Http::withHeaders($auth)->get("$BASE/wallet/customer-balances");
if ($cb->successful()) {
    $data = $cb->json('data');
    echo "Customers in debt: " . count($data) . "\n";
    foreach (array_slice($data, 0, 5) as $r) {
        echo "  - '{$r['client_name']}' debt=" . ($r['total_debt'] ?? 0) . " paid=" . ($r['total_paid'] ?? 0) . "\n";
    }
}

// Step 4: Customer Statement (محاكاة "مش مسمعه العمليات" + "لو هسدد ديون عليها مش ظاهرة")
echo "\n=== SCENARIO 3: Customer Statement - هل العمليات ظاهرة؟ ===\n";
$cb = Http::withHeaders($auth)->get("$BASE/wallet/customer-balances");
if ($cb->successful()) {
    $data = $cb->json('data');
    $firstWithDebt = null;
    foreach ($data as $r) {
        if ($r['total_debt'] > 0 && $r['client_id']) {
            $firstWithDebt = $r;
            break;
        }
    }

    if ($firstWithDebt) {
        echo "Testing customer: '{$firstWithDebt['client_name']}' (id={$firstWithDebt['client_id']})\n";
        $stmt = Http::withHeaders($auth)->get("$BASE/wallet/customer-statement", [
            'client_id' => $firstWithDebt['client_id'],
            'client_name' => $firstWithDebt['client_name'],
        ]);
        $txs = $stmt->json('data.transactions') ?? [];
        $running = $stmt->json('data.running_balance') ?? 0;
        echo "Statement result: " . count($txs) . " transactions, running_balance=$running\n";
        foreach (array_slice($txs, 0, 10) as $tx) {
            $type = $tx['type'] ?? '?';
            $amount = $tx['amount'] ?? '?';
            $balance = $tx['running_balance'] ?? '?';
            echo "    $type: $amount → balance=$balance\n";
        }
        if (count($txs) === 0) {
            echo "  ❌ EMPTY - هذا مشكلة! العمليات مش ظاهرة\n";
        } else {
            // Check for "سداد دفعة" (settlement) entries
            $settlements = array_filter($txs, fn($tx) => ($tx['type'] ?? '') === 'سداد دفعة');
            echo "Settlements (سداد): " . count($settlements) . "\n";
        }
    } else {
        echo "No customer with debt + client_id found in wallet\n";
    }
}

// Step 5: Unified Debts Report per module (محاكاة المستحق لنا)
echo "\n=== SCENARIO 4: تقرير الديون الموحد - المستحق لنا لكل موديول ===\n";
foreach (['wallet' => 'محافظ', 'fawry' => 'فوري', 'online' => 'خدمات إلكترونية', 'bus' => 'باص'] as $mod => $label) {
    $r = Http::withHeaders($auth)->get("$BASE/reports/debts", [
        'module' => $mod,
        'direction' => 'all',
    ]);
    if ($r->successful()) {
        $items = $r->json('data.items') ?? [];
        $customerItems = array_filter($items, fn($i) => ($i['entity_type'] ?? '') === 'customer');
        $totalRecv = $r->json('data.total_receivables') ?? 0;
        echo "  📊 $label ($mod): customers=" . count($customerItems) . ", total_receivables=$totalRecv\n";
        foreach (array_slice($customerItems, 0, 3) as $item) {
            echo "    - {$item['name']}: {$item['balance']} {$item['currency']}\n";
        }
    } else {
        echo "  $label ($mod): فشل " . substr($r->body(), 0, 80) . "\n";
    }
}

// Step 6: Walk-in Fawry in unified debts report
echo "\n=== SCENARIO 5: Walk-in Fawry (العملاء الفوري غير المسجلين) ===\n";
$r = Http::withHeaders($auth)->get("$BASE/reports/debts", ['entity_type' => 'walkin_fawry']);
if ($r->successful()) {
    $items = $r->json('data.items') ?? [];
    echo "Walk-in Fawry entries: " . count($items) . "\n";
    foreach (array_slice($items, 0, 5) as $item) {
        echo "  - {$item['name']}: {$item['balance']} {$item['currency']} ({$item['tx_count']} transactions)\n";
    }
}

echo "\n✅ Test complete\n";
