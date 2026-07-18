<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight E2E Test Setup — ينشئ بيانات الاختبار الأساسية (idempotent)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * ينشئ:
 *   - 1 Flight System (Amadeus)
 *   - 1 Flight System (NDC)
 *   - 2 Flight Carriers (EgyptAir EGP, Jazeera KWD)
 *   - 2 Flight Groups (Al-Shola, Freej)
 *   - 2 Customers (Cairo, Jeddah)
 *   - 1 Bank account (EGP)
 *   - 1 Bank account (USD)
 *   - 1 Wallet (Vodafone Cash EGP)
 *   - 1 Postal account (EGP)
 *   - 1 Owner/customer-ledger
 *
 * يخزّن المعرّفات في storage/logs/flight_e2e_ids.json
 *
 * التشغيل: php tests/e2e/flight_e2e_setup.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$now = date('Y-m-d H:i:s');
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Flight E2E Test Setup — {$now}\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$admin = User::where('email', 'admin@safarakealayna.com')->first();
if (! $admin) {
    fwrite(STDERR, "Admin user not found\n");
    exit(1);
}

// Authenticate so observers that use Auth::id() work
Auth::login($admin);

$created_by = $admin->id;
$ids = [
    'admin_user_id' => $created_by,
    'systems' => [],
    'carriers' => [],
    'groups' => [],
    'customers' => [],
    'accounts' => [],
];

DB::transaction(function () use (&$ids, $created_by) {
    // ── Flight Systems ────────────────────────────────────────────
    $sys1 = FlightSystem::firstOrCreate(
        ['code' => 'AMA_E2E'],
        [
            'name' => 'Amadeus (E2E)',
            'type' => 'amadeus',
            'description' => 'نظام أماديوس للاختبار E2E',
            'is_active' => true,
            'balance' => 100000,
            'currency' => 'EGP',
            'credit_limit' => 200000,
            'created_by' => $created_by,
        ]
    );
    $sys2 = FlightSystem::firstOrCreate(
        ['code' => 'NDC_E2E'],
        [
            'name' => 'NDC (E2E)',
            'type' => 'ndc',
            'description' => 'نظام NDC للاختبار E2E',
            'is_active' => true,
            'balance' => 50000,
            'currency' => 'EGP',
            'credit_limit' => 100000,
            'created_by' => $created_by,
        ]
    );
    $ids['systems']['amadeus'] = $sys1->id;
    $ids['systems']['ndc'] = $sys2->id;
    echo "✅ Systems: Amadeus={$sys1->id} NDC={$sys2->id}\n";

    // ── Flight Carriers ───────────────────────────────────────────
    $car1 = FlightCarrier::firstOrCreate(
        ['code' => 'MS_E2E'],
        [
            'flight_system_id' => $sys1->id,
            'name' => 'مصر للطيران (E2E)',
            'iata_code' => 'MS',
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 200000,
            'is_active' => true,
            'created_by' => $created_by,
        ]
    );
    $car2 = FlightCarrier::firstOrCreate(
        ['code' => 'J9_E2E'],
        [
            'flight_system_id' => $sys2->id,
            'name' => 'طيران الجزيرة (E2E)',
            'iata_code' => 'J9',
            'currency' => 'KWD',
            'balance' => 0,
            'credit_limit' => 5000,
            'is_active' => true,
            'created_by' => $created_by,
        ]
    );
    $car3 = FlightCarrier::firstOrCreate(
        ['code' => 'SV_E2E'],
        [
            'flight_system_id' => $sys1->id,
            'name' => 'الخطوط السعودية (E2E)',
            'iata_code' => 'SV',
            'currency' => 'SAR',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $created_by,
        ]
    );
    $ids['carriers']['egyptair'] = $car1->id;
    $ids['carriers']['jazeera_kwd'] = $car2->id;
    $ids['carriers']['saudi_sar'] = $car3->id;
    echo "✅ Carriers: MS(EGP)={$car1->id} J9(KWD)={$car2->id} SV(SAR)={$car3->id}\n";

    // ── Flight Groups ─────────────────────────────────────────────
    $grp1 = FlightGroup::firstOrCreate(
        ['name' => 'مجموعة الشعلة (E2E)'],
        [
            'code' => 'SHL_E2E',
            'flight_carrier_id' => $car1->id,
            'commission_rate' => 2.5,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $created_by,
            'notes' => 'مجموعة الشعلة - E2E',
        ]
    );
    $grp2 = FlightGroup::firstOrCreate(
        ['name' => 'مجموعة فرياج (E2E)'],
        [
            'code' => 'FRJ_E2E',
            'flight_carrier_id' => $car2->id,
            'commission_rate' => 3.0,
            'credit_limit' => 2000,
            'is_active' => true,
            'created_by' => $created_by,
            'notes' => 'مجموعة فرياج - E2E',
        ]
    );
    $ids['groups']['alshola'] = $grp1->id;
    $ids['groups']['freej'] = $grp2->id;
    echo "✅ Groups: الشعلة={$grp1->id} فرياج={$grp2->id}\n";

    // ── Customers ─────────────────────────────────────────────────
    $cust1 = Customer::firstOrCreate(
        ['phone' => '01000000001'],
        [
            'full_name' => 'أحمد محمد (Flight E2E)',
            'email' => 'ahmed.e2e@test.com',
            'national_id' => '30001010000001',
            'city' => 'القاهرة',
            'nationality' => 'EG',
            'gender' => 'male',
            'type' => 'individual',
            'customer_tier' => 'PREMIUM',
            'status' => 'active',
            'created_by' => $created_by,
        ]
    );
    $cust2 = Customer::firstOrCreate(
        ['phone' => '01000000002'],
        [
            'full_name' => 'سارة علي (Flight E2E)',
            'email' => 'sara.e2e@test.com',
            'national_id' => '30001020000002',
            'city' => 'الإسكندرية',
            'nationality' => 'EG',
            'gender' => 'female',
            'type' => 'individual',
            'customer_tier' => 'STANDARD',
            'status' => 'active',
            'created_by' => $created_by,
        ]
    );
    $ids['customers']['ahmed'] = $cust1->id;
    $ids['customers']['sara'] = $cust2->id;
    echo "✅ Customers: أحمد={$cust1->id} سارة={$cust2->id}\n";

    // ── Account: Cashbox EGP ──────────────────────────────────────
    $cashEGP = Account::firstOrCreate(
        ['name' => 'خزينة Flight E2E EGP', 'type' => 'cashbox', 'currency' => 'EGP'],
        [
            'treasury_type' => 'main',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 500000,
            'created_by' => $created_by,
            'notes' => 'خزينة رئيسية EGP للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['cashbox_egp'] = $cashEGP->id;

    // ── Account: Cashbox KWD ──────────────────────────────────────
    $cashKWD = Account::firstOrCreate(
        ['name' => 'خزينة Flight E2E KWD', 'type' => 'cashbox', 'currency' => 'KWD'],
        [
            'treasury_type' => 'main',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 5000,
            'created_by' => $created_by,
            'notes' => 'خزينة KWD للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['cashbox_kwd'] = $cashKWD->id;

    // ── Account: Bank EGP ─────────────────────────────────────────
    $bankEGP = Account::firstOrCreate(
        ['name' => 'بنك مصر Flight E2E EGP', 'type' => 'bank', 'currency' => 'EGP'],
        [
            'treasury_type' => 'main',
            'bank_name' => 'بنك مصر',
            'account_number' => 'EG-FLIGHT-E2E-001',
            'branch_name' => 'الفرع الرئيسي',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 1000000,
            'created_by' => $created_by,
            'notes' => 'حساب بنكي EGP للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['bank_egp'] = $bankEGP->id;

    // ── Account: Bank USD ─────────────────────────────────────────
    $bankUSD = Account::firstOrCreate(
        ['name' => 'بنك مصر Flight E2E USD', 'type' => 'bank', 'currency' => 'USD'],
        [
            'treasury_type' => 'main',
            'bank_name' => 'بنك مصر',
            'account_number' => 'EG-FLIGHT-E2E-USD-001',
            'branch_name' => 'الفرع الرئيسي',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 50000,
            'created_by' => $created_by,
            'notes' => 'حساب بنكي USD للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['bank_usd'] = $bankUSD->id;

    // ── Account: Wallet Vodafone EGP ──────────────────────────────
    $walletVF = Account::firstOrCreate(
        ['name' => 'محفظة فودافون Flight E2E', 'type' => 'wallet', 'currency' => 'EGP'],
        [
            'wallet_provider' => 'vodafone_cash',
            'wallet_number' => '01012345678',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 50000,
            'created_by' => $created_by,
            'notes' => 'محفظة فودافون للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['wallet_vf'] = $walletVF->id;

    // ── Account: Postal EGP ───────────────────────────────────────
    $postal = Account::firstOrCreate(
        ['name' => 'بريد Flight E2E EGP', 'type' => 'bank', 'currency' => 'EGP'],
        [
            'treasury_type' => 'postal',
            'bank_name' => 'مكتب البريد',
            'account_number' => 'POST-FLIGHT-E2E-001',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flights',
            'is_module_vault' => true,
            'balance' => 100000,
            'created_by' => $created_by,
            'notes' => 'حساب بريد للاختبار E2E - الطيران',
        ]
    );
    $ids['accounts']['postal_egp'] = $postal->id;

    echo "✅ Accounts: cashbox_egp={$cashEGP->id} cashbox_kwd={$cashKWD->id} bank_egp={$bankEGP->id} bank_usd={$bankUSD->id} wallet_vf={$walletVF->id} postal={$postal->id}\n";
});

// حفظ المعرّفات
file_put_contents(
    storage_path('logs/flight_e2e_ids.json'),
    json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  تم حفظ المعرّفات في: storage/logs/flight_e2e_ids.json\n";
echo "═══════════════════════════════════════════════════════════════\n";
