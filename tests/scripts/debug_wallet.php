<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);
$service = app(WalletTransactionService::class);

// Try a simple send transaction
$vodafone = WalletType::where('code', 'vodafone_cash')->first();
$wallet = Account::where('name', 'like', '%فودافون كاش%')->first();
$cashbox = Account::where('name', 'خزينة المحافظ النقدية')->first();
$customer = Customer::first();
$user = User::find(1);

echo "Vodafone: id={$vodafone->id}\n";
echo "Wallet: id={$wallet->id} name={$wallet->name} balance={$wallet->balance}\n";
echo "Cashbox: id={$cashbox->id} name={$cashbox->name} balance={$cashbox->balance}\n";
echo "Customer: id={$customer->id} name={$customer->full_name} account_id={$customer->account_id}\n";

try {
    $tx = $service->createTransaction([
        'wallet_type_id' => $vodafone->id,
        'customer_id' => $customer->id,
        'wallet_number' => '01012345678',
        'type' => WalletTransactionType::Send->value,
        'amount' => 100,
        'service_fee' => 5,
        'amount_paid' => 105,
        'wallet_account_id' => $wallet->id,
        'cash_account_id' => $cashbox->id,
        'employee_id' => $user->id,
        'notes' => 'debug test',
    ]);
    echo "✅ OK - id={$tx->id}\n";
    var_dump($tx);
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString() . "\n";
}
