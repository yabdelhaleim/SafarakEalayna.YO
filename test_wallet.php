<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

echo "=== Wallet Module CRUD Test (Full) ===\n\n";
$passed = 0; $failed = 0;

function check($label, $condition) {
    global $passed, $failed;
    if ($condition) { echo "  ✓ {$label}\n"; $passed++; }
    else             { echo "  ✗ {$label}\n"; $failed++; }
}

DB::beginTransaction();
try {
    $user = User::first();
    if (!$user) {
        $user = User::create(['name'=>'Admin Test','email'=>'admin_t@t.com','password'=>bcrypt('123456'),'role'=>'admin','is_active'=>true]);
    }
    Auth::login($user);
    check("User logged in", Auth::check());

    $walletAccount = Account::where('type', 'wallet')->first()
        ?? Account::create(['name'=>'Test Wallet','type'=>AccountType::Wallet->value,'balance'=>10000,'currency'=>'EGP','is_active'=>true,'owner_type'=>'owner']);
    $cashAccount = Account::where('type', 'cashbox')->first()
        ?? Account::create(['name'=>'Test Cash','type'=>AccountType::Cashbox->value,'balance'=>5000,'currency'=>'EGP','is_active'=>true,'owner_type'=>'owner']);
    $walletType = WalletType::first();
    if (!$walletType) {
        $walletType = WalletType::create(['name'=>'فودافون كاش','code'=>'voda_t2','is_active'=>true,'sort_order'=>1]);
    }

    check("Wallet account exists", $walletAccount !== null);
    check("Cash account exists",   $cashAccount !== null);
    check("Wallet type exists",    $walletType !== null);

    $iW = (float) Account::find($walletAccount->id)->balance;
    $iC = (float) Account::find($cashAccount->id)->balance;
    echo "\n  Initial wallet: {$iW} | cash: {$iC}\n\n";

    $service = app(WalletTransactionService::class);

    // SEND
    echo "--- SEND ---\n";
    $s = $service->createTransaction(['wallet_type_id'=>$walletType->id,'customer_name'=>'أحمد','wallet_number'=>'01012345678','type'=>'send','amount'=>500,'service_fee'=>10,'wallet_account_id'=>$walletAccount->id,'cash_account_id'=>$cashAccount->id]);
    check("Created ID={$s->id}",     $s->id > 0);
    check("total_amount = 510",      (float)$s->total_amount === 510.0);
    check("income_tx set",           $s->income_transaction_id !== null);
    check("expense_tx set",          $s->expense_transaction_id !== null);
    $w1=(float)Account::find($walletAccount->id)->balance; $c1=(float)Account::find($cashAccount->id)->balance;
    check("Wallet ↓ 500",            $w1 === $iW-500);
    check("Cash ↑ 510",              $c1 === $iC+510);
    echo "  Wallet: {$iW}→{$w1} | Cash: {$iC}→{$c1}\n\n";

    // RECEIVE
    echo "--- RECEIVE ---\n";
    $r = $service->createTransaction(['wallet_type_id'=>$walletType->id,'customer_name'=>'سارة','wallet_number'=>'01098765432','type'=>'receive','amount'=>300,'service_fee'=>8,'wallet_account_id'=>$walletAccount->id,'cash_account_id'=>$cashAccount->id]);
    check("Created",                 $r->id > 0);
    check("total_amount = 292",      (float)$r->total_amount === 292.0);
    $w2=(float)Account::find($walletAccount->id)->balance; $c2=(float)Account::find($cashAccount->id)->balance;
    check("Wallet ↑ 300",            $w2 === $w1+300);
    check("Cash ↓ 292",              $c2 === $c1-292);
    echo "  Wallet: {$w1}→{$w2} | Cash: {$c1}→{$c2}\n\n";

    // LIST
    echo "--- LIST & FILTER ---\n";
    check("List ≥ 2",                $service->getAllTransactions([])->total() >= 2);
    check("Filter type=send works",  $service->getAllTransactions(['type'=>'send'])->total() >= 1);
    check("Filter type=receive works",$service->getAllTransactions(['type'=>'receive'])->total() >= 1);

    // SHOW
    echo "\n--- SHOW ---\n";
    $found = $service->getTransactionById($s->id);
    check("Found",                   $found->id === $s->id);
    check("walletType loaded",       $found->walletType !== null);

    // UPDATE
    echo "\n--- UPDATE ---\n";
    check("Notes updated",           $service->updateTransaction($s, ['notes'=>'updated'])->notes === 'updated');

    // DAILY SUMMARY
    echo "\n--- DAILY SUMMARY ---\n";
    $sum = $service->getDailySummary(now()->toDateString());
    check("total ≥ 2",               $sum['total_transactions'] >= 2);
    check("send_count ≥ 1",          $sum['send_count'] >= 1);
    check("receive_count ≥ 1",       $sum['receive_count'] >= 1);
    check("total_fees = 18",         (float)$sum['total_fees'] === 18.0);
    echo "  " . json_encode($sum) . "\n";

    // DELETE + REVERSAL
    echo "\n--- DELETE + REVERSAL ---\n";
    $wB=(float)Account::find($walletAccount->id)->balance;
    $cB=(float)Account::find($cashAccount->id)->balance;
    $service->deleteTransaction($s->fresh());
    $wA=(float)Account::find($walletAccount->id)->balance;
    $cA=(float)Account::find($cashAccount->id)->balance;
    check("Soft deleted",            WalletTransaction::withTrashed()->find($s->id)?->deleted_at !== null);
    check("Wallet reversed +500",    $wA === $wB+500);
    check("Cash reversed -510",      $cA === $cB-510);
    echo "  Before W={$wB} C={$cB} → After W={$wA} C={$cA}\n\n";

    // ROUTES
    echo "--- ROUTES ---\n";
    $rn = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn($r) => $r->getName())->filter()->values()->toArray();
    foreach(['wallet.types.index','wallet_transactions.index','wallet_transactions.store','wallet_transactions.show','wallet_transactions.update','wallet_transactions.destroy','wallet.transactions.daily-summary'] as $route) {
        check($route, in_array($route, $rn));
    }

    // CLASSES & ENUMS
    echo "\n--- CLASSES & ENUMS ---\n";
    check("WalletTypeResource",        class_exists(\App\Filament\Resources\Wallet\WalletTypeResource::class));
    check("WalletTransactionResource", class_exists(\App\Filament\Resources\Wallet\WalletTransactionResource::class));
    check("WalletTransactionType Send",\App\Enums\WalletTransactionType::Send->label() === 'إرسال رصيد');
    check("TransactionModule::Wallet", \App\Enums\TransactionModule::Wallet->value === 'wallet');

} catch (\Exception $e) {
    echo "\n❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    $failed++;
} finally {
    DB::rollBack();
    echo "\n================================\n";
    echo "Results: ✓ {$passed} passed   ✗ {$failed} failed\n";
}
