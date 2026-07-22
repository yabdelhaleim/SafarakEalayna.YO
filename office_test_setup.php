<?php
/**
 * Office Module Test — Setup Script
 * ============================================
 * Creates all test data: exchange rates, customers, suppliers, office accounts.
 * Run via: php office_test_setup.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\ExchangeRate;
use App\Models\Bank;
use App\Models\User;
use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$results = ['success' => 0, 'failed' => 0, 'logs' => []];

function log_result(string $key, bool $success, $payload = null): void
{
    global $results;
    $results['logs'][] = ['key' => $key, 'success' => $success, 'payload' => $payload];
    if ($success) {
        $results['success']++;
        echo "  ✅ $key\n";
    } else {
        $results['failed']++;
        echo "  ❌ $key — " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  إعداد موديول المكتب — Setup Office Module\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ─── 1. Admin user
echo "[1] المستخدم الإداري\n";
$admin = User::first();
log_result('admin.exists', $admin !== null, $admin?->email);

// ─── 2. Exchange rates (للتحويل من العملة الأجنبية للجنيه المصري)
echo "\n[2] أسعار الصرف\n";
$now = now();
$rates = [
    ['from' => 'USD', 'to' => 'EGP', 'rate' => 50.10, 'src' => 'CBE'],
    ['from' => 'SAR', 'to' => 'EGP', 'rate' => 13.36, 'src' => 'CBE'],
    ['from' => 'AED', 'to' => 'EGP', 'rate' => 13.64, 'src' => 'CBE'],
    ['from' => 'KWD', 'to' => 'EGP', 'rate' => 163.51, 'src' => 'CBE'],
    ['from' => 'EGP', 'to' => 'USD', 'rate' => 1 / 50.10, 'src' => 'CBE'],
    ['from' => 'EGP', 'to' => 'SAR', 'rate' => 1 / 13.36, 'src' => 'CBE'],
];
foreach ($rates as $r) {
    $exists = ExchangeRate::where('from_currency', $r['from'])
        ->where('to_currency', $r['to'])
        ->whereDate('effective_date', $now->toDateString())
        ->first();
    if (!$exists) {
        $created = ExchangeRate::create([
            'from_currency' => $r['from'],
            'to_currency' => $r['to'],
            'rate' => $r['rate'],
            'effective_date' => $now->toDateString(),
            'source' => $r['src'],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        log_result("rate.{$r['from']}->{$r['to']}={$r['rate']}", true, $created->id);
    } else {
        log_result("rate.{$r['from']}->{$r['to']} (exists)", true, $exists->id);
    }
}

// ─── 3. Customers
echo "\n[3] العملاء\n";
$customers = [
    ['name' => 'أحمد محمد علي', 'phone' => '01001234567', 'tier' => 'VIP'],
    ['name' => 'فاطمة حسن', 'phone' => '01002345678', 'tier' => 'PREMIUM'],
    ['name' => 'خالد عبدالله', 'phone' => '01003456789', 'tier' => 'STANDARD'],
];
$createdCustomers = [];
foreach ($customers as $c) {
    $existed = Customer::where('phone', $c['phone'])->first();
    if (!$existed) {
        // Create AR account for the customer
        $arAccount = Account::create([
            'name' => 'حساب العميل: ' . $c['name'],
            'type' => AccountType::Customer,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'wallet_transfer', // عميل تابع لقسم المكتب
            'module' => 'wallet_transfer',
            'is_module_vault' => false,
            'notes' => 'حساب عميل افتراضي لمتابعة المديونية',
            'created_by' => $admin->id,
        ]);
        $cust = Customer::create([
            'account_id' => $arAccount->id,
            'full_name' => $c['name'],
            'name' => $c['name'],
            'phone' => $c['phone'],
            'status' => 'active',
            'customer_tier' => $c['tier'],
            'module_type' => 'wallet_transfer',
            'type' => 'individual',
            'created_by' => $admin->id,
        ]);
        $createdCustomers[] = $cust;
        log_result("customer.{$c['phone']}", true, "id={$cust->id}, AR id={$arAccount->id}");
    } else {
        $createdCustomers[] = $existed;
        log_result("customer.{$c['phone']} (exists)", true, "id={$existed->id}");
    }
}

// ─── 4. Suppliers
echo "\n[4] الموردون\n";
$suppliers = [
    ['name' => 'شركة باصات القاهرة — سوبر جيت', 'phone' => '0223456789', 'code' => 'SUPERJET-CAI', 'type' => 'bus_company'],
    ['name' => 'شركة باصات الإسكندرية — جولد باص', 'phone' => '0345678910', 'code' => 'GOLD-Alex', 'type' => 'bus_company'],
    ['name' => 'مورد المحافظ الإلكترونية', 'phone' => '01112223344', 'code' => 'WALLET-SUP', 'type' => 'service_provider'],
];
$createdSuppliers = [];
foreach ($suppliers as $s) {
    $existed = Supplier::where('code', $s['code'])->first();
    if (!$existed) {
        $sup = Supplier::create([
            'code' => $s['code'],
            'name' => $s['name'],
            'phone' => $s['phone'],
            'type' => $s['type'],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $createdSuppliers[] = $sup;
        log_result("supplier.{$s['code']}", true, "id={$sup->id}");
    } else {
        $createdSuppliers[] = $existed;
        log_result("supplier.{$s['code']} (exists)", true, "id={$existed->id}");
    }
}

// ─── 5. Office Module Accounts
echo "\n[5] حسابات المكتب (Office)\n";
echo "  -- بنوك وبريد المكتب (Bank) --\n";

$banks = [
    ['name' => 'البنك الأهلي المصري — جنيه', 'currency' => 'EGP', 'balance' => 50000.00, 'notes' => 'رقم IBAN: EG123456789'],
    ['name' => 'البنك الأهلي المصري — دولار', 'currency' => 'USD', 'balance' => 5000.00, 'notes' => 'حساب بالدولار لشراء التذاكر الدولية'],
    ['name' => 'بنك مصر — جنيه', 'currency' => 'EGP', 'balance' => 25000.00, 'notes' => 'حساب جاري رئيسي'],
    ['name' => 'بنك CIB — ريال سعودي', 'currency' => 'SAR', 'balance' => 8000.00, 'notes' => 'للتحويلات للحج والعمرة'],
    ['name' => 'البريد المصري — جنيه', 'currency' => 'EGP', 'balance' => 5000.00, 'notes' => 'حساب بريد للمكتب'],
    ['name' => 'بنك QNB — كويتي', 'currency' => 'KWD', 'balance' => 100.00, 'notes' => 'حساب بالدينار الكويتي'],
];
$createdBanks = [];
foreach ($banks as $b) {
    $existed = Account::where('name', $b['name'])->where('module_type', 'office')->first();
    if (!$existed) {
        $bank = Account::create([
            'name' => $b['name'],
            'type' => AccountType::Bank,
            'currency' => $b['currency'],
            'balance' => $b['balance'],
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => null,
            'is_module_vault' => false,
            'notes' => $b['notes'],
            'created_by' => $admin->id,
        ]);
        // Opening balance entry
        if ($bank->balance != 0) {
            DB::table('account_entries')->insert([
                'account_id' => $bank->id,
                'transaction_id' => null,
                'debit' => $bank->balance < 0 ? abs($bank->balance) : 0,
                'credit' => $bank->balance > 0 ? $bank->balance : 0,
                'balance_after' => $bank->balance,
                'notes' => 'رصيد افتتاحي',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $createdBanks[] = $bank;
        log_result("bank.{$b['name']}", true, "id={$bank->id}, balance={$bank->balance} {$bank->currency}");
    } else {
        $createdBanks[] = $existed;
        log_result("bank.{$b['name']} (exists)", true, "id={$existed->id}, balance={$existed->balance}");
    }
}

echo "  -- خزائن المكتب النقدية (Cashbox) --\n";
$cashboxes = [
    ['name' => 'خزينة المكتب الرئيسية — جنيه', 'currency' => 'EGP', 'balance' => 30000.00, 'vault' => true, 'notes' => 'الخزنة الرسمية لقسم المكتب'],
    ['name' => 'خزينة الفرع الثاني — جنيه', 'currency' => 'EGP', 'balance' => 10000.00, 'vault' => false, 'notes' => 'فرع ثاني'],
    ['name' => 'خزينة المكتب — دولار', 'currency' => 'USD', 'balance' => 2000.00, 'vault' => false, 'notes' => 'دولار للمدفوعات الدولية'],
    ['name' => 'خزينة المكتب — درهم إماراتي', 'currency' => 'AED', 'balance' => 1000.00, 'vault' => false, 'notes' => 'للتعاملات بالإماراتي'],
];
$createdCashboxes = [];
foreach ($cashboxes as $cb) {
    $existed = Account::where('name', $cb['name'])->where('module_type', 'office')->first();
    if (!$existed) {
        $cash = Account::create([
            'name' => $cb['name'],
            'type' => AccountType::Cashbox,
            'currency' => $cb['currency'],
            'balance' => $cb['balance'],
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => null,
            'is_module_vault' => $cb['vault'],
            'notes' => $cb['notes'],
            'created_by' => $admin->id,
        ]);
        if ($cash->balance != 0) {
            DB::table('account_entries')->insert([
                'account_id' => $cash->id,
                'transaction_id' => null,
                'debit' => $cash->balance < 0 ? abs($cash->balance) : 0,
                'credit' => $cash->balance > 0 ? $cash->balance : 0,
                'balance_after' => $cash->balance,
                'notes' => 'رصيد افتتاحي',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $createdCashboxes[] = $cash;
        log_result("cashbox.{$cb['name']}", true, "id={$cash->id}, vault=" . ($cb['vault'] ? 'YES' : 'NO'));
    } else {
        $createdCashboxes[] = $existed;
        log_result("cashbox.{$cb['name']} (exists)", true, "id={$existed->id}");
    }
}

echo "  -- محافظ المكتب الإلكترونية (Wallet) --\n";
$wallets = [
    ['name' => 'محفظة فودافون كاش رئيسية', 'provider' => WalletProvider::VodafoneCash, 'number' => '01001234567', 'currency' => 'EGP', 'balance' => 15000.00],
    ['name' => 'محفظة إنستاباي', 'provider' => WalletProvider::Instapay, 'number' => '01012345678', 'currency' => 'EGP', 'balance' => 8000.00],
    ['name' => 'محفظة أورنج كاش', 'provider' => WalletProvider::OrangeCash, 'number' => '01022223333', 'currency' => 'EGP', 'balance' => 5000.00],
    ['name' => 'محفظة اتصالات كاش', 'provider' => WalletProvider::EtisalatCash, 'number' => '01055554444', 'currency' => 'EGP', 'balance' => 3000.00],
    ['name' => 'محفظة WE Pay', 'provider' => WalletProvider::WePay, 'number' => '01555556666', 'currency' => 'EGP', 'balance' => 2000.00],
];
$createdWallets = [];
foreach ($wallets as $w) {
    $existed = Account::where('wallet_number', $w['number'])->first();
    if (!$existed) {
        $wallet = Account::create([
            'name' => $w['name'],
            'type' => AccountType::Wallet,
            'currency' => $w['currency'],
            'balance' => $w['balance'],
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'wallet_transfer',
            'is_module_vault' => false,
            'wallet_provider' => $w['provider'],
            'wallet_number' => $w['number'],
            'notes' => 'محفظة إلكترونية للمكتب',
            'created_by' => $admin->id,
        ]);
        if ($wallet->balance != 0) {
            DB::table('account_entries')->insert([
                'account_id' => $wallet->id,
                'transaction_id' => null,
                'debit' => $wallet->balance < 0 ? abs($wallet->balance) : 0,
                'credit' => $wallet->balance > 0 ? $wallet->balance : 0,
                'balance_after' => $wallet->balance,
                'notes' => 'رصيد افتتاحي',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $createdWallets[] = $wallet;
        log_result("wallet.{$w['name']}", true, "id={$wallet->id}, balance={$wallet->balance}");
    } else {
        $createdWallets[] = $existed;
        log_result("wallet.{$w['name']} (exists)", true, "id={$existed->id}");
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  النتيجة: {$results['success']} نجح / {$results['failed']} فشل\n";
echo "═══════════════════════════════════════════════════════════════\n";

// Save to file for the next script
file_put_contents(
    __DIR__ . '/office_test_setup_results.json',
    json_encode([
        'admin_id' => $admin->id,
        'customers' => collect($createdCustomers)->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name])->all(),
        'suppliers' => collect($createdSuppliers)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
        'banks' => collect($createdBanks)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'balance' => (float)$a->balance])->all(),
        'cashboxes' => collect($createdCashboxes)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'balance' => (float)$a->balance, 'vault' => (bool)$a->is_module_vault])->all(),
        'wallets' => collect($createdWallets)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'provider' => $a->wallet_provider?->value, 'number' => $a->wallet_number, 'balance' => (float)$a->balance])->all(),
        'logs' => $results['logs'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "  النتائج محفوظة في: office_test_setup_results.json\n";
