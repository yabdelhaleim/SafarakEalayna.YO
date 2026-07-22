<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\TransactionModule;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Finance\TransactionService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);
$txService = app(TransactionService::class);

// Try just recordIncome to isolate the bug
$vodafone = WalletType::where('code', 'vodafone_cash')->first();
$wallet = Account::where('name', 'like', '%فودافون كاش%')->first();
$cashbox = Account::where('name', 'خزينة المحافظ النقدية')->first();
$customer = Customer::first();
$user = User::find(1);

// Verify customer account exists
$customerAccount = $customer->account_id ? Account::find($customer->account_id) : null;
echo "Customer account: " . ($customerAccount?->id ?? 'NULL') . " currency={$customerAccount?->currency} module={$customerAccount?->module_type}\n";

// Try recordIncome on customer account
echo "\n--- Test recordIncome ---\n";
try {
    $income = $txService->recordIncome([
        'amount' => 100,
        'to_account_id' => $customerAccount->id,
        'module' => TransactionModule::Wallet->value,
        'notes' => 'debug test',
        'created_by' => $user->id,
    ]);
    echo "✅ recordIncome OK: id={$income->id}\n";
} catch (\Throwable $e) {
    echo "❌ recordIncome ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Try recordExpense on wallet
echo "\n--- Test recordExpense on wallet ---\n";
try {
    $expense = $txService->recordExpense([
        'amount' => 100,
        'from_account_id' => $wallet->id,
        'module' => TransactionModule::Wallet->value,
        'notes' => 'debug wallet',
        'created_by' => $user->id,
    ]);
    echo "✅ recordExpense OK: id={$expense->id}\n";
} catch (\Throwable $e) {
    echo "❌ recordExpense ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
