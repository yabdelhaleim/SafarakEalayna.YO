<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\TransactionModule;
use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;

echo "=== VISA FINANCIAL MODULE & DIVISION RULES ===\n\n";

if (defined(TransactionModule::class.'::Visa')) {
    echo 'TransactionModule::Visa value: '.TransactionModule::Visa->value."\n";
}

$moduleVault = Account::getModuleVault('visa');
if ($moduleVault) {
    echo "Visa Module Vault: Account #{$moduleVault->id} ({$moduleVault->name}) | Division: {$moduleVault->module_type}\n";
} else {
    echo "No specific designated vault with is_module_vault=1 for 'visa' found. Checking generic tourism/office vaults...\n";
}

$tourismAccounts = Account::where('module_type', 'tourism')->count();
$officeAccounts = Account::where('module_type', 'office')->count();
echo "Active Tourism Division Accounts: {$tourismAccounts}\n";
echo "Active Office Division Accounts: {$officeAccounts}\n";
