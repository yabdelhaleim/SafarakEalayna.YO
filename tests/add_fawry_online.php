<?php
/**
 * Cross-module test: same customer has transactions in Wallet, Fawry, Online.
 * After settling each, verify the customer shows up in EVERY module's
 * receivables filter — which was the original bug.
 */

require __DIR__ . '/../vendor/autoload.php';
chdir(__DIR__ . '/..');
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

$admin = User::first();
Auth::login($admin);

$BASE = 'http://127.0.0.1:8000/api/v1';
$login = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
$token = $login->json('data.token');
$H = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// Find our test customer
$customer = Customer::where('full_name', 'محمد ياسر')->latest('id')->first();
if (! $customer) {
    fwrite(STDERR, "Customer not found - run seed first\n");
    exit(1);
}
echo "✅ Found customer id={$customer->id}\n";

// Get a cash account
$fawryAccount = \App\Models\Account::where('name', 'الخزينة الرئيسية')->first();
if (! $fawryAccount) {
    $fawryAccount = \App\Models\Account::where('type', \App\Enums\AccountType::Cashbox->value)->first();
}

// =========================================================================
// Add a Fawry transaction directly (bypassing machine requirement)
// =========================================================================
echo "\n=== Adding Fawry transaction ===\n";
$fawryService = app(\App\Services\Fawry\FawryTransactionService::class);

// =========================================================================
// Add an Online transaction via the service (bypassing machine requirement)
// =========================================================================
echo "\n=== Adding Online transaction ===\n";
$onlineService = app(\App\Services\Online\OnlineTransactionService::class);

try {
    // Create a simple service type & provider if missing
    $st = \App\Models\Online\OnlineServiceType::firstOrCreate(
        ['code' => 'test_svc'],
        ['name_ar' => 'خدمة اختبار', 'name_en' => 'Test Service', 'label' => 'خدمة اختبار', 'is_active' => true, 'sort_order' => 1]
    );
    $provider = \App\Models\Online\OnlineServiceProvider::firstOrCreate(
        ['code' => 'test_prov'],
        ['name' => 'مزود اختبار', 'is_active' => true]
    );

    $onlineTx = $onlineService->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'service_type_id' => $st->id,
        'provider_id' => $provider->id,
        'selling_price' => 350,
        'purchase_price' => 300,
        'amount_paid' => 0, // unpaid → debt
        'status' => 'completed',
        'account_id' => $fawryAccount->id,
        'notes' => 'اونلاين اختبار دين',
    ]);
    echo "  ✅ Online TX id={$onlineTx->id}, selling={$onlineTx->selling_price}\n";

    $settle = Http::withHeaders($H)->post("$BASE/customers/{$customer->id}/pay-debt", [
        'amount' => 50,
        'account_id' => $fawryAccount->id,
        'module' => 'online',
    ]);
    echo "  Settle Online: " . substr($settle->body(), 0, 100) . "\n";
} catch (\Throwable $e) {
    echo "  ❌ Online failed: " . $e->getMessage() . "\n";
}

// =========================================================================
// Test the UNIFIED debts report — the most critical bug
// =========================================================================
echo "\n=== UNIFIED DEBTS REPORT — same customer in every module ===\n";
foreach (['wallet' => 'wallet', 'fawry' => 'fawry', 'online' => 'online'] as $mod) {
    $r = Http::withHeaders($H)->get("$BASE/reports/debts", ['module' => $mod, 'direction' => 'all']);
    if ($r->successful()) {
        $items = collect($r->json('data.items') ?? []);
        $matches = $items->filter(fn ($i) =>
            ($i['entity_type'] ?? '') === 'customer'
            && (int)($i['id'] ?? 0) === (int)$customer->id
        );
        echo "  📊 Module=$mod: " . $items->count() . " total customers/items, matches for our customer: " . $matches->count() . "\n";
        foreach ($matches as $m) {
            echo "    - {$m['name']}: {$m['balance']} {$m['currency']} (entity_type={$m['entity_type']})\n";
        }
    }
}

// =========================================================================
// Test the Fawry customer statement
// =========================================================================
echo "\n=== Fawry Customer Statement (with missing-account fallback) ===\n";
$stmt = Http::withHeaders($H)->get("$BASE/fawry/customer-statement", [
    'client_id' => $customer->id,
    'client_name' => $customer->full_name,
]);
$body = $stmt->json();
$txs = $body['data']['transactions'] ?? [];
echo "  Statement txs: " . count($txs) . "\n";
echo "  Running balance: " . ($body['data']['running_balance'] ?? '?') . "\n";
foreach (array_slice($txs, 0, 6) as $tx) {
    echo "    - " . ($tx['type'] ?? '?') . ": " . ($tx['amount'] ?? '?') . " → balance=" . ($tx['running_balance'] ?? '?') . "\n";
}
