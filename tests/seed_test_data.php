<?php
/**
 * Simple seed: create one customer via tinker, then call APIs to make
 * transactions.  Avoids manual UI clicks while still exercising the
 * full backend flow.
 */

require __DIR__ . '/../vendor/autoload.php';
chdir(__DIR__ . '/..');
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Wallet\WalletType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';

// Authenticate via API
$login = Http::post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
if (! $login->successful()) {
    fwrite(STDERR, "Login failed: " . $login->body() . "\n");
    exit(1);
}
$token = $login->json('data.token');
$H = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// Login via auth to use tinker-side code
$admin = \App\Models\User::first();
Auth::login($admin);

echo "=== Cleaning previous test data ===\n";
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('wallet_transactions')->where('customer_id', '>=', 1)->delete();
DB::table('customers')->where('full_name', 'محمد ياسر')->update([
    'deleted_at' => null,
]);
DB::table('customers')->where('full_name', 'محمد ياسر')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "  cleaned\n";

echo "\n=== Setting up test customer & accounts ===\n";

// Customer
$customer = Customer::create([
    'full_name' => 'محمد ياسر',
    'phone' => '01098765433',
    'type' => 'individual',
    'status' => 'active',
    'module_type' => 'wallet_transfer',
    'created_by' => $admin->id,
]);
echo "  ✅ Customer '{$customer->full_name}' (id={$customer->id})\n";

// Wallet account
$walletAccount = Account::firstOrCreate(
    ['name' => 'فودافون كاش - محفظة اختبار'],
    [
        'type' => AccountType::Wallet->value,
        'balance' => 10000,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'office',
        'module' => 'wallet',
        'is_module_vault' => false,
        'wallet_provider' => 'vodafone_cash',
        'wallet_number' => '01070985102',
        'created_by' => $admin->id,
    ]
);
echo "  ✅ Wallet account id={$walletAccount->id}\n";

// Cash account
$cashAccount = Account::where('name', 'الخزينة الرئيسية')->first();
if (! $cashAccount) {
    $cashAccount = Account::firstOrCreate(
        ['name' => 'خزينة اختبار للديون'],
        [
            'type' => AccountType::Cashbox->value,
            'balance' => 50000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'module' => 'general',
            'is_module_vault' => false,
            'created_by' => $admin->id,
        ]
    );
}
echo "  ✅ Cash account id={$cashAccount->id}\n";

// Wallet type
$walletType = WalletType::firstOrCreate(
    ['code' => 'vodafone_cash'],
    ['name' => 'فودافون كاش', 'is_active' => true, 'sort_order' => 1]
);
echo "  ✅ Wallet type id={$walletType->id}\n";

echo "\n=== Create 2 wallet transactions for customer ===\n";
$service = app(\App\Services\Wallet\WalletTransactionService::class);

$tx1 = $service->createTransaction([
    'wallet_type_id' => $walletType->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'wallet_number' => '01070985102',
    'type' => 'send',
    'amount' => 19,
    'service_fee' => 5,
    'amount_paid' => 0,  // unpaid → debt
    'wallet_account_id' => $walletAccount->id,
    'cash_account_id' => $cashAccount->id,
    'notes' => 'محفظة - اختبار دين 1',
]);
echo "  ✅ TX1 id={$tx1->id}, amount=19+5, customer_name='{$tx1->customer_name}'\n";

$tx2 = $service->createTransaction([
    'wallet_type_id' => $walletType->id,
    'customer_id' => $customer->id,
    'customer_name' => $customer->full_name,
    'wallet_number' => '01070985102',
    'type' => 'send',
    'amount' => 200,
    'service_fee' => 6,
    'amount_paid' => 0,
    'wallet_account_id' => $walletAccount->id,
    'cash_account_id' => $cashAccount->id,
    'notes' => 'محفظة - اختبار دين 2',
]);
echo "  ✅ TX2 id={$tx2->id}, amount=200+6\n";

// Settle 10 EGP via API
echo "\n=== Settle 10 EGP debt via API ===\n";
$payRes = Http::withHeaders($H)->post("$BASE/customers/{$customer->id}/pay-debt", [
    'amount' => 10,
    'account_id' => $cashAccount->id,
    'module' => 'wallet',
    'notes' => 'تسديد جزئي 10 ج.م',
]);
echo "  " . substr($payRes->body(), 0, 200) . "\n";

// Add a Fawry transaction for the same customer (if a machine is available)
echo "\n=== SCENARIO (extra): Make Fawry transaction for customer ===\n";
$fawryMachinesRes = Http::withHeaders($H)->get("$BASE/fawry/machines");
$fawryMachines = $fawryMachinesRes->json('data.machines') ?? [];
echo "  Machines returned: " . count($fawryMachines) . "\n";
if (count($fawryMachines) > 0) {
    $fawryMachine = $fawryMachines[0];
    echo "  Using machine: '" . ($fawryMachine['name'] ?? '?') . "' (id=" . ($fawryMachine['id'] ?? '?') . ")\n";
    $fawryRes = Http::withHeaders($H)->post("$BASE/fawry/transactions", [
        'client_id' => $customer->id,
        'client_name' => $customer->full_name,
        'client_phone' => $customer->phone,
        'machine_id' => $fawryMachine['id'],
        'operation_type' => 'withdrawal',
        'selling_price' => 500,
        'amount' => 0,
        'notes' => 'فوري اختبار دين',
    ]);
    echo "  " . substr($fawryRes->body(), 0, 200) . "\n";

    if ($fawryRes->successful()) {
        // Settle 100 EGP from Fawry
        $settleRes = Http::withHeaders($H)->post("$BASE/customers/{$customer->id}/pay-debt", [
            'amount' => 100,
            'account_id' => $cashAccount->id,
            'module' => 'fawry',
            'notes' => 'تسديد فوري 100 ج.م',
        ]);
        echo "  Settle Fawry: " . substr($settleRes->body(), 0, 200) . "\n";
    }
} else {
    echo "  (No fawry machine — skipping)\n";
}

// Add an Online transaction for the same customer
echo "\n=== SCENARIO (extra): Make Online transaction for customer ===\n";
$onlineAccs = Http::withHeaders($H)->get("$BASE/online/settings/accounts")->json('data') ?? [];
if (count($onlineAccs) > 0) {
    $onlineAcc = $onlineAccs[0];
    $onlineRes = Http::withHeaders($H)->post("$BASE/online/transactions", [
        'customer_id' => $customer->id,
        'customer_name' => $customer->full_name,
        'service_type_id' => 1,
        'provider_id' => null,
        'selling_price' => 350,
        'purchase_price' => 300,
        'amount_paid' => 0,
        'status' => 'completed',
        'payment_method' => 'cash',
        'account_id' => $onlineAcc['id'] ?? $cashAccount->id,
        'notes' => 'اونلاين اختبار دين',
    ]);
    echo "  " . substr($onlineRes->body(), 0, 200) . "\n";

    if ($onlineRes->successful()) {
        $settleRes = Http::withHeaders($H)->post("$BASE/customers/{$customer->id}/pay-debt", [
            'amount' => 50,
            'account_id' => $cashAccount->id,
            'module' => 'online',
            'notes' => 'تسديد اونلاين 50',
        ]);
        echo "  Settle Online: " . substr($settleRes->body(), 0, 200) . "\n";
    }
} else {
    echo "  (No online setup — skipping)\n";
}

echo "\n✅ Seed complete. Customer ID: {$customer->id}\n";
echo "Now run: php tests/user_flow_test.php\n";
