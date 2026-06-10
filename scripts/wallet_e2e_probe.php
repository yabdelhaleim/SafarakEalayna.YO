<?php

declare(strict_types=1);

use Throwable;
use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = [];
$fail = static function (string $msg) use (&$out): void {
    $out[] = 'FAIL: '.$msg;
    echo implode(PHP_EOL, $out).PHP_EOL;
    exit(1);
};

$user = User::query()
    ->where('is_active', true)
    ->whereIn('role', ['admin', 'owner'])
    ->first();

if (! $user) {
    $user = User::query()->create([
        'name' => 'E2E Wallet Tester',
        'email' => 'wallet-e2e@local.test',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    $out[] = 'Created temp admin: '.$user->email;
} else {
    $out[] = 'Using admin: '.$user->email;
}

Sanctum::actingAs($user, ['*']);

// Ensure wallet types exist (like SettingSeeder)
$walletType = WalletType::query()->where('code', 'vodafone_cash')->first();
if (! $walletType) {
    $walletType = WalletType::query()->create([
        'name' => 'فودافون كاش',
        'code' => 'vodafone_cash',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $out[] = 'Created wallet type vodafone_cash';
}

$walletAccount = Account::query()
    ->where('module_type', 'wallet_transfer')
    ->where('type', AccountType::Wallet)
    ->where('is_active', true)
    ->where('wallet_provider', WalletProvider::VodafoneCash)
    ->first();

if (! $walletAccount) {
    $walletAccount = Account::query()->create([
        'name' => 'E2E فودافون — تحويلات',
        'type' => AccountType::Wallet,
        'module_type' => 'wallet_transfer',
        'module' => 'wallet_transfer',
        'owner_type' => 'office',
        'wallet_provider' => WalletProvider::VodafoneCash,
        'wallet_number' => '01099990001',
        'balance' => 50000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = 'Created wallet account #'.$walletAccount->id;
} else {
    $out[] = 'Wallet account #'.$walletAccount->id.' balance='.$walletAccount->balance;
}

$cashAccount = Account::query()
    ->where('module_type', 'wallet_transfer')
    ->whereIn('type', [AccountType::Cashbox, AccountType::Bank, AccountType::Treasury])
    ->where('is_active', true)
    ->first();

if (! $cashAccount) {
    $cashAccount = Account::query()->create([
        'name' => 'E2E خزينة تحويلات',
        'type' => AccountType::Cashbox,
        'module_type' => 'wallet_transfer',
        'module' => 'wallet_transfer',
        'owner_type' => 'office',
        'balance' => 20000,
        'currency' => 'EGP',
        'is_active' => true,
        'created_by' => $user->id,
    ]);
    $out[] = 'Created cash account #'.$cashAccount->id;
} else {
    $out[] = 'Cash account #'.$cashAccount->id.' balance='.$cashAccount->balance;
}

$walletBalBefore = (float) $walletAccount->fresh()->balance;
$cashBalBefore = (float) $cashAccount->fresh()->balance;

// ── API 1: treasury overview (wallet/create source) ──
$treasuryRes = app(\App\Http\Controllers\Api\V1\Wallet\TransferTreasuryController::class)->overview();
$treasury = json_decode($treasuryRes->getContent(), true);
$treasuryWalletIds = collect($treasury['data']['wallets'] ?? [])->pluck('id')->all();
if (! in_array($walletAccount->id, $treasuryWalletIds, true)) {
    $fail('Treasury overview missing wallet account #'.$walletAccount->id);
}
$out[] = 'OK treasury overview: '.count($treasuryWalletIds).' wallet(s)';

// ── API 2: finance accounts module=wallet ──
$financeReq = Illuminate\Http\Request::create('/api/v1/finance/accounts', 'GET', [
    'module' => 'wallet',
    'per_page' => 100,
    'is_active' => 1,
]);
$financeRes = app(\App\Http\Controllers\Api\V1\Finance\AccountController::class)->index($financeReq);
$finance = json_decode($financeRes->getContent(), true);
$financeIds = collect($finance['data']['items'] ?? [])->pluck('id')->all();
if (! in_array($walletAccount->id, $financeIds, true)) {
    $fail('Finance accounts (module=wallet) missing wallet #'.$walletAccount->id);
}
$out[] = 'OK finance accounts: wallet visible';

// ── API 3: wallet types ──
$typesRes = app(\App\Http\Controllers\Api\V1\Wallet\WalletTypeController::class)
    ->index(Illuminate\Http\Request::create('/api/v1/wallet/types'));
$types = json_decode($typesRes->getContent(), true);
$typeIds = collect($types['data'] ?? [])->pluck('id')->all();
if (! in_array($walletType->id, $typeIds, true)) {
    $fail('Wallet types API missing vodafone_cash type');
}
$out[] = 'OK wallet types: '.count($typeIds).' type(s)';

// ── API 4: create SEND transaction (full cycle) ──
$amount = 500.0;
$fee = 15.0;
$payload = [
    'wallet_type_id' => $walletType->id,
    'customer_name' => 'عميل تجريبي E2E',
    'wallet_number' => '01012345678',
    'type' => 'send',
    'amount' => $amount,
    'service_fee' => $fee,
    'amount_paid' => $amount + $fee,
    'wallet_account_id' => $walletAccount->id,
    'cash_account_id' => $cashAccount->id,
    'notes' => 'E2E automated probe',
];

try {
    $tx = app(\App\Services\Wallet\WalletTransactionService::class)->createTransaction($payload);
} catch (Throwable $e) {
    $fail('Create transaction: '.$e->getMessage());
}

$txId = $tx->id;
if (! $txId) {
    $fail('No transaction id after create');
}

$walletBalAfter = (float) $walletAccount->fresh()->balance;
$cashBalAfter = (float) $cashAccount->fresh()->balance;

// Send: wallet -amount, cash +amount+fee
$expectedWallet = $walletBalBefore - $amount;
$expectedCash = $cashBalBefore + $amount + $fee;

if (abs($walletBalAfter - $expectedWallet) > 0.01) {
    $fail("Wallet balance wrong: got {$walletBalAfter}, expected {$expectedWallet}");
}
if (abs($cashBalAfter - $expectedCash) > 0.01) {
    $fail("Cash balance wrong: got {$cashBalAfter}, expected {$expectedCash}");
}

$tx = WalletTransaction::query()->find($txId);
if (! $tx || ! $tx->income_transaction_id || ! $tx->expense_transaction_id) {
    $fail('Transaction missing ledger links');
}

// ── HTTP smoke (wallet/create endpoints via running server) ──
$token = $user->createToken('wallet-e2e-probe')->plainTextToken;
$base = rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');
$http = Illuminate\Support\Facades\Http::withToken($token)->acceptJson();

$typesHttp = $http->get($base.'/api/v1/wallet/types');
if (! $typesHttp->successful()) {
    $fail('HTTP wallet/types failed: '.$typesHttp->status());
}
$out[] = 'OK HTTP GET /api/v1/wallet/types';

$treasuryHttp = $http->get($base.'/api/v1/wallet/treasury/overview');
if (! $treasuryHttp->successful()) {
    $fail('HTTP treasury/overview failed: '.$treasuryHttp->status());
}
$httpWalletIds = collect($treasuryHttp->json('data.wallets'))->pluck('id')->all();
if (! in_array($walletAccount->id, $httpWalletIds, true)) {
    $fail('HTTP treasury overview missing wallet #'.$walletAccount->id);
}
$out[] = 'OK HTTP GET /api/v1/wallet/treasury/overview';

$financeHttp = $http->get($base.'/api/v1/finance/accounts', [
    'module' => 'wallet',
    'per_page' => 100,
    'is_active' => 1,
]);
if (! $financeHttp->successful()) {
    $fail('HTTP finance/accounts failed: '.$financeHttp->status());
}
$out[] = 'OK HTTP GET /api/v1/finance/accounts?module=wallet';

$out[] = 'OK created transaction #'.$txId.' (send '.$amount.' + fee '.$fee.')';
$out[] = 'OK wallet balance: '.$walletBalBefore.' → '.$walletBalAfter;
$out[] = 'OK cash balance: '.$cashBalBefore.' → '.$cashBalAfter;
$out[] = 'OK ledger: income #'.$tx->income_transaction_id.', expense #'.$tx->expense_transaction_id;
$out[] = 'RESULT: MODULE OK — full wallet cycle passed';

echo implode(PHP_EOL, $out).PHP_EOL;
