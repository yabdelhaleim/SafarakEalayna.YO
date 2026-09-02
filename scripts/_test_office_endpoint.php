<?php

/**
 * Quick smoke-test for the new OfficeTreasuryController::accountTransactions.
 *
 * Boots Laravel in CLI mode and simulates an authenticated GET on the
 * endpoint by resolving the route directly. Verifies:
 *   1. Account validation rejects non-office accounts.
 *   2. Account #66 (wallet قثص) returns its 2 fawry transactions.
 *   3. Account #5 (كاش 102 - wallet) returns all module types.
 *
 * No DB writes. Read-only.
 */

use App\Http\Controllers\Api\V1\Office\OfficeTreasuryController;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo "=== OfficeTreasuryController smoke test ===\n\n";

// 1) Negative test: tourism account
echo "[1] Negative: tourism account (e.g. #1) should be rejected.\n";
$tourism = Account::where('module_type', 'tourism')->first();
if ($tourism) {
    $belongs = OfficeTreasuryController::belongsToOfficeDivision($tourism);
    echo "  Account #{$tourism->id} ({$tourism->name}, module_type={$tourism->module_type}) → belongs? "
        .($belongs ? 'YES' : 'NO')."\n";
    $belongs ? print "  ❌ FAIL: should have been rejected\n"
             : print "  ✅ OK: correctly rejected.\n";
} else {
    echo "  (no tourism account exists to test against — skipping)\n";
}
echo "\n";

// 2) Positive: account #66 (قثص) — should have 2 fawry operations
echo "[2] Positive: account #66 (قثص - wallet) — should have 2 fawry entries.\n";
$acc66 = Account::find(66);
if ($acc66) {
    echo "  Account #66: name={$acc66->name}, type={$acc66->type}, "
        ."module_type={$acc66->module_type}, balance={$acc66->balance}\n";
    $belongs = OfficeTreasuryController::belongsToOfficeDivision($acc66);
    echo '  belongs to office division? '.($belongs ? 'YES' : 'NO')."\n";

    // Manually query the same way the controller does
    $txs = Transaction::where(function ($q) use ($acc66) {
        $q->where('from_account_id', $acc66->id)
            ->orWhere('to_account_id', $acc66->id);
    })->with(['fromAccount:id,name', 'toAccount:id,name'])->latest()->get();

    echo "  Total transactions touching this account: {$txs->count()}\n";
    foreach ($txs as $tx) {
        echo "    #{$tx->id} type={$tx->type} module={$tx->module} amount={$tx->amount} "
            ."from={$tx->from_account_id} to={$tx->to_account_id} notes='{$tx->notes}'\n";
    }
}
echo "\n";

// 3) Positive: account #5 (كاش 102) — should have bus + fawry + general + others
echo "[3] Positive: account #5 (كاش 102 - wallet).\n";
$acc5 = Account::find(5);
if ($acc5) {
    echo "  Account #5: name={$acc5->name}, type={$acc5->type}, "
        ."module_type={$acc5->module_type}, balance={$acc5->balance}\n";
    $belongs = OfficeTreasuryController::belongsToOfficeDivision($acc5);
    echo '  belongs to office division? '.($belongs ? 'YES' : 'NO')."\n";

    $txs = Transaction::where(function ($q) use ($acc5) {
        $q->where('from_account_id', $acc5->id)
            ->orWhere('to_account_id', $acc5->id);
    })->latest()->get();

    echo "  Total transactions: {$txs->count()}\n";
    $byModule = $txs->groupBy('module');
    foreach ($byModule as $mod => $list) {
        echo "    module={$mod}: ".count($list)." transactions\n";
    }
}
echo "\n";

// 4) Endpoint via HTTP simulation (via Laravel test helper)
echo "[4] Endpoint simulation: dispatch the route via Request → controller.\n";
$acc = Account::find(66);
if ($acc) {
    $request = Request::create("/api/v1/office/treasury/accounts/{$acc->id}/transactions", 'GET');
    $request->setUserResolver(fn () => User::first());
    $controller = new OfficeTreasuryController;
    $response = $controller->accountTransactions($request, $acc);
    $payload = json_decode($response->getContent(), true);
    echo '  HTTP-style response: success='.($payload['success'] ?? 'n/a')
         ." | message='{$payload['message']}'\n";
    if (isset($payload['data'])) {
        $data = $payload['data'];
        if (is_array($data)) {
            $items = $data['data'] ?? [];
            echo '  Returned '.count($items).' transactions '
                 .'(current_page='.($data['current_page'] ?? 'n/a')
                 .', last_page='.($data['last_page'] ?? 'n/a').")\n";
            foreach ($items as $tx) {
                echo "    #{$tx['id']} type={$tx['type']} module={$tx['module']} "
                    ."amount={$tx['amount']} notes='{$tx['notes']}'\n";
            }
        } else {
            echo '  data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
        }
    }
}

echo "\n=== Done ===\n";
