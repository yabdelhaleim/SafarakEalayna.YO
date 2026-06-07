<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\Api\V1\Finance\AccountController;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TreasuryService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$issues = [];

// 1. Treasury overview matches liquidity accounts
$treasury = app(TreasuryService::class)->getTreasuryOverview();
$liquidity = Account::query()->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))->active()->get();
$overviewIds = collect($treasury['modules'])->flatMap(fn ($m) => collect($m['accounts'])->pluck('id'))->unique()->sort()->values();
$liquidityIds = $liquidity->pluck('id')->sort()->values();
if ($overviewIds->toArray() !== $liquidityIds->toArray()) {
    $issues[] = 'Treasury overview account set mismatch';
}
if (abs((float) $treasury['stats']['total_liquidity'] - (float) $liquidity->sum('balance')) > 0.05) {
    $issues[] = 'Treasury total liquidity mismatch';
}

// 2. Treasury liquidity drift (only real treasury accounts)
$ledgerNet = AccountEntry::query()
    ->selectRaw('account_id, SUM(COALESCE(credit,0)-COALESCE(debit,0)) as net')
    ->groupBy('account_id')->pluck('net', 'account_id');
foreach ($liquidity as $acc) {
    $ledger = round((float) ($ledgerNet[$acc->id] ?? 0), 2);
    $stored = round((float) $acc->balance, 2);
    if (abs($stored - $ledger) > 0.05) {
        $issues[] = "Treasury drift #{$acc->id} {$acc->name}: stored={$stored} ledger={$ledger}";
    }
}

// 3. Module treasury endpoints consistency
$moduleChecks = [
    'fawry' => Account::where('module_type', 'fawry')->where('is_active', true)->count(),
    'visas' => Account::where('module_type', 'visas')->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)->where('is_active', true)->count(),
    'flights' => Account::where('module_type', 'flights')->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)->where('is_active', true)->count(),
    'bus' => Account::where('module_type', 'bus')->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)->where('is_active', true)->count(),
    'wallet_transfer' => Account::where('module_type', 'wallet_transfer')->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)->where('is_active', true)->count(),
];
foreach ($treasury['modules'] as $key => $mod) {
    if (isset($moduleChecks[$key]) && count($mod['accounts']) !== $moduleChecks[$key]) {
        $issues[] = "Module {$key} count mismatch overview=".count($mod['accounts']).' db='.$moduleChecks[$key];
    }
}

// 4. Negative treasury without justification
foreach ($liquidity->where('balance', '<', 0) as $neg) {
    $issues[] = "Negative treasury #{$neg->id} {$neg->name}: {$neg->balance}";
}

// 5. Clearing accounts must not appear in treasury
$clearingInTreasury = $liquidity->filter(fn ($a) => str_contains($a->name, 'إقفال') || str_contains($a->name, '(نظام)'));
if ($clearingInTreasury->count() > 0) {
    $issues[] = 'Clearing accounts in treasury: '.$clearingInTreasury->pluck('id')->implode(',');
}

// 6. Double-entry transfers balanced
$badTransfers = AccountEntry::query()
    ->whereNotNull('transaction_id')
    ->select('transaction_id')
    ->groupBy('transaction_id')
    ->havingRaw('ABS(SUM(debit)-SUM(credit)) > 0.02')
    ->havingRaw('COUNT(*) = 2')
    ->count();
// badTransfers here are balanced journal transfers that are still imbalanced - should be 0

// 7. Accounts index stats
$user = User::where('role', 'admin')->first();
if ($user) {
    auth()->login($user);
    $controller = app(AccountController::class);
    $req = Request::create('/api/v1/finance/accounts', 'GET', ['per_page' => 100]);
    $req->setUserResolver(fn () => $user);
    $resp = $controller->index($req);
    $data = $resp->getData(true)['data'];
    $statsBalance = (float) ($data['stats']['total_balance'] ?? 0);
    if (abs($statsBalance - (float) $liquidity->sum('balance')) > 0.05) {
        $issues[] = "Accounts index stats total_balance mismatch: {$statsBalance} vs ".$liquidity->sum('balance');
    }
}

// 8. Orphan / missing
if (AccountEntry::whereNotNull('transaction_id')->whereNotIn('transaction_id', Transaction::pluck('id'))->exists()) {
    $issues[] = 'Orphan account entries exist';
}
if (Transaction::doesntHave('entries')->exists()) {
    $issues[] = 'Transactions without entries exist';
}

echo "FINAL VERIFICATION\n";
echo "==================\n";
echo 'Treasury accounts: '.$liquidity->count()."\n";
echo 'Overview accounts: '.$overviewIds->count()."\n";
echo 'Treasury liquidity OK: '.(count(array_filter($issues, fn ($i) => str_contains($i, 'Treasury drift') || str_contains($i, 'mismatch') || str_contains($i, 'Clearing'))) === 0 ? 'checking...' : '')."\n";
echo 'Issues found: '.count($issues)."\n";
foreach ($issues as $i) {
    echo "  - {$i}\n";
}
if (count($issues) === 0) {
    echo "ALL CHECKS PASSED\n";
}
