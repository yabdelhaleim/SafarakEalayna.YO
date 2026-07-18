<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * E2E SETUP — موديول التأشيرات (Visa Module)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يجهّز بيانات اختبارية idempotent بأسماء TEST_VISA_E2E_* على قاعدة البيانات
 * الحية دون المساس بالبيانات الموجودة.
 *
 * التشغيل: php tests/e2e/visa_setup.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════\n";
echo "  E2E SETUP — Visa Module Test Data\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// ════════════════════════════════════════════════════════════
// 1) E2E admin user (idempotent)
// ════════════════════════════════════════════════════════════
$e2eUser = User::firstOrCreate(
    ['email' => 'visa-e2e@test.com'],
    [
        'name' => 'Visa E2E Admin',
        'password' => bcrypt('VisaE2E#Test2026'),
        'role' => 'admin',
        'is_active' => true,
    ]
);
echo "  [1] User: #{$e2eUser->id} {$e2eUser->email}\n";

// ════════════════════════════════════════════════════════════
// 2) Customers (2 customers)
// ════════════════════════════════════════════════════════════
$customer1 = Customer::firstOrCreate(
    ['phone' => '01000000001'],
    [
        'full_name' => 'TEST_VISA_E2E_CUSTOMER_1',
        'passport' => 'TEST-V-E2E-001-' . uniqid(),
        'city' => 'Cairo',
        'created_by' => $e2eUser->id,
    ]
);
echo "  [2a] Customer 1: #{$customer1->id} {$customer1->full_name}\n";

$customer2 = Customer::firstOrCreate(
    ['phone' => '01000000002'],
    [
        'full_name' => 'TEST_VISA_E2E_CUSTOMER_2',
        'passport' => 'TEST-V-E2E-002-' . uniqid(),
        'city' => 'Alexandria',
        'created_by' => $e2eUser->id,
    ]
);
echo "  [2b] Customer 2: #{$customer2->id} {$customer2->full_name}\n";

// ════════════════════════════════════════════════════════════
// 3) VisaAgent (with linked Supplier Account)
// ════════════════════════════════════════════════════════════
$agentAccount = Account::firstOrCreate(
    ['name' => 'TEST_VISA_E2E_AGENT_ACCOUNT'],
    [
        'type' => 'supplier',
        'currency' => 'EGP',
        'balance' => 0,
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'visas',
        'is_module_vault' => false,
        'created_by' => $e2eUser->id,
    ]
);
echo "  [3a] Agent supplier account: #{$agentAccount->id} balance={$agentAccount->balance}\n";

$agent = VisaAgent::firstOrCreate(
    ['company_name' => 'TEST_VISA_E2E_AGENT'],
    [
        'contact_person' => 'Test Agent',
        'phone' => '01000000099',
        'is_active' => true,
        'default_cost_price' => 1000.0,
        'account_id' => $agentAccount->id,
        'created_by' => $e2eUser->id,
    ]
);
// Make sure agent has account_id (firstOrCreate may have created it before account_id was set)
if (! $agent->account_id) {
    $agent->update(['account_id' => $agentAccount->id]);
}
echo "  [3b] Agent: #{$agent->id} {$agent->company_name}\n";

// ════════════════════════════════════════════════════════════
// 4) VisaDuration (1 record)
// ════════════════════════════════════════════════════════════
$duration = VisaDuration::firstOrCreate(
    ['code' => 'TEST-E2E-1M'],
    [
        'label_ar' => 'مدة اختبار E2E شهر واحد',
        'label_en' => 'TEST E2E 1 Month',
        'months' => 1,
        'entry_type' => 'single',
        'sort_order' => 999,
        'is_active' => true,
    ]
);
echo "  [4] Duration: #{$duration->id} {$duration->code}\n";

// ════════════════════════════════════════════════════════════
// 5) Cashbox (Visa vault) — large balance to avoid insufficient funds
// ════════════════════════════════════════════════════════════
// NOTE: Liquidity accounts (cashbox/wallet/bank) require module_type to be a DIVISION
// (office or tourism), not a specific module. Visa lives in tourism division, so
// module_type='tourism'. The Account::getModuleVault('visas') lookup will still find
// this vault because of the AccountModuleDivision fallback logic.
$cashbox = Account::firstOrCreate(
    ['name' => 'TEST_VISA_E2E_VAULT'],
    [
        'type' => 'cashbox',
        'currency' => 'EGP',
        'balance' => 200000,
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'tourism',
        'is_module_vault' => true,
        'created_by' => $e2eUser->id,
    ]
);
// If the vault already existed but balance is low, top it up
if ((float) $cashbox->balance < 150000) {
    // Top up via journal transfer from tourism unified vault
    $tourismVault = Account::find(55); // known id from previous inspection
    if ($tourismVault && $tourismVault->balance > 50000) {
        $transferAmount = 200000 - (float) $cashbox->balance;
        \App\Services\Finance\TransactionService::class;
        try {
            $svc = app(\App\Services\Finance\TransactionService::class);
            $svc->recordJournalTransfer([
                'amount' => $transferAmount,
                'from_account_id' => $tourismVault->id,
                'to_account_id' => $cashbox->id,
                'module' => 'general',
                'notes' => 'TEST_VISA_E2E_SETUP_TOPUP',
                'created_by' => $e2eUser->id,
            ]);
            echo "  [5b] Vault topped up by {$transferAmount}\n";
        } catch (\Throwable $e) {
            echo "  [5b] Top-up skipped: {$e->getMessage()}\n";
        }
    }
}
$cashbox = $cashbox->fresh();
echo "  [5] Cashbox vault: #{$cashbox->id} balance={$cashbox->balance}\n";

// ════════════════════════════════════════════════════════════
// Output IDs as JSON for the runner script
// ════════════════════════════════════════════════════════════
$ids = [
    'user_id' => $e2eUser->id,
    'user_email' => $e2eUser->email,
    'customer_1_id' => $customer1->id,
    'customer_2_id' => $customer2->id,
    'agent_id' => $agent->id,
    'agent_account_id' => $agentAccount->id,
    'duration_id' => $duration->id,
    'cashbox_id' => $cashbox->id,
    'cashbox_balance_initial' => (float) $cashbox->balance,
];

$jsonPath = storage_path('logs/visa_e2e_ids.json');
file_put_contents($jsonPath, json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  IDs saved to: {$jsonPath}\n";
echo "═══════════════════════════════════════════════════════════\n";
echo json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "═══════════════════════════════════════════════════════════\n";