<?php
/**
 * Office Module — Setup (clean state)
 * ====================================================
 * Wipes ALL office module data and re-creates:
 *   - Admin user (already exists, skip if so)
 *   - Exchange rates for EGP, USD, SAR, AED, KWD
 *   - 5 banks, 5 cashboxes, 5 wallets in office division
 *   - 3 customers (each gets an AR account)
 *   - 3 suppliers
 *
 * Output: office_master_state.json
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Account;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Enums\AccountType;
use App\Enums\WalletProvider;

function reset_data(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('account_entries')->truncate();
    DB::table('transactions')->truncate();
    DB::table('transfers')->truncate();
    DB::table('accounts')->truncate();
    DB::table('customers')->truncate();
    DB::table('suppliers')->truncate();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    echo "✓ Wiped all office data (entries, transactions, accounts, customers, suppliers)\n";
}

function open_account(User $admin, string $name, AccountType $type, string $currency, float $balance, string $module = 'office', string $notes = '', bool $vault = false, ?string $provider = null, ?string $number = null): Account
{
    $account = Account::create([
        'name' => $name,
        'type' => $type,
        'currency' => $currency,
        'balance' => 0,  // set below via guard
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => $module,
        'module' => $type === AccountType::Wallet ? 'wallet_transfer' : null,
        'is_module_vault' => $vault,
        'wallet_provider' => $provider,
        'wallet_number' => $number,
        'notes' => $notes,
        'created_by' => $admin->id,
    ]);

    if ($balance != 0) {
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
            $account->balance = $balance;
            $account->save();
        });

        DB::table('account_entries')->insert([
            'account_id' => $account->id,
            'transaction_id' => null,
            'debit' => $balance < 0 ? abs($balance) : 0,
            'credit' => $balance > 0 ? $balance : 0,
            'balance_after' => $balance,
            'notes' => 'رصيد افتتاحي',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $account;
}

echo "═══════════════════════════════════════════════════════════\n";
echo "  Master Office Setup (clean state)\n";
echo "═══════════════════════════════════════════════════════════\n\n";

reset_data();

// ── 1) Admin user (create one if missing)
$admin = User::first();
if (!$admin) {
    $admin = User::create([
        'name' => 'Master Test Admin',
        'email' => 'master-admin@example.com',
        'password' => Hash::make('Master@2026!'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    echo "✓ Created admin user\n";
} else {
    // Reset password to known value
    $admin->password = Hash::make('Master@2026!');
    $admin->save();
    echo "✓ Reset password for {$admin->email}\n";
}

$password = 'Master@2026!';

// ── 2) Exchange rates (full set)
echo "\n[2] Exchange rates\n";
$rates = [
    ['USD', 'EGP', 50.10],
    ['SAR', 'EGP', 13.36],
    ['AED', 'EGP', 13.64],
    ['KWD', 'EGP', 163.51],
    ['EUR', 'EGP', 53.50],
];
foreach ($rates as [$from, $to, $rate]) {
    ExchangeRate::updateOrCreate(
        ['from_currency' => $from, 'to_currency' => $to, 'effective_date' => now()->toDateString()],
        ['rate' => $rate, 'source' => 'CBE', 'is_active' => true, 'created_by' => $admin->id]
    );
    echo "  ✓ {$from} → {$to} = {$rate}\n";
}

// ── 3) Banks
echo "\n[3] Banks (Office division)\n";
$banks = [
    ['name' => 'البنك الأهلي المصري — جنيه', 'currency' => 'EGP', 'balance' => 50000, 'vault' => false],
    ['name' => 'البنك الأهلي المصري — دولار', 'currency' => 'USD', 'balance' => 5000, 'vault' => false],
    ['name' => 'بنك مصر — جنيه', 'currency' => 'EGP', 'balance' => 25000, 'vault' => false],
    ['name' => 'بنك CIB — ريال سعودي', 'currency' => 'SAR', 'balance' => 8000, 'vault' => false],
    ['name' => 'البريد المصري — جنيه', 'currency' => 'EGP', 'balance' => 5000, 'vault' => false],
    ['name' => 'بنك QNB — كويتي', 'currency' => 'KWD', 'balance' => 100, 'vault' => false],
];
$createdBanks = [];
foreach ($banks as $b) {
    $acc = open_account($admin, $b['name'], AccountType::Bank, $b['currency'], $b['balance'], 'office', '', $b['vault']);
    $createdBanks[$b['currency']][] = $acc;
    echo "  ✓ {$b['name']} — {$b['balance']} {$b['currency']}\n";
}

// ── 4) Cashboxes (one is the vault)
echo "\n[4] Cashboxes (Office division)\n";
$cashboxes = [
    ['name' => 'خزينة المكتب الرئيسية — جنيه (VAULT)', 'currency' => 'EGP', 'balance' => 30000, 'vault' => true],
    ['name' => 'خزينة الفرع الثاني — جنيه', 'currency' => 'EGP', 'balance' => 10000, 'vault' => false],
    ['name' => 'خزينة المكتب — دولار', 'currency' => 'USD', 'balance' => 2000, 'vault' => false],
    ['name' => 'خزينة المكتب — درهم إماراتي', 'currency' => 'AED', 'balance' => 1000, 'vault' => false],
    ['name' => 'خزينة المكتب — ريال سعودي', 'currency' => 'SAR', 'balance' => 5000, 'vault' => false],
];
$createdCashboxes = [];
foreach ($cashboxes as $cb) {
    $acc = open_account($admin, $cb['name'], AccountType::Cashbox, $cb['currency'], $cb['balance'], 'office', '', $cb['vault']);
    $createdCashboxes[$cb['currency']][] = $acc;
    echo "  ✓ {$cb['name']} — {$cb['balance']} {$cb['currency']}" . ($cb['vault'] ? ' [VAULT]' : '') . "\n";
}

// ── 5) Wallets (one per provider)
echo "\n[5] Wallets (Office division)\n";
$wallets = [
    ['name' => 'محفظة فودافون كاش رئيسية', 'provider' => WalletProvider::VodafoneCash, 'number' => '01001234567', 'balance' => 15000],
    ['name' => 'محفظة إنستاباي', 'provider' => WalletProvider::Instapay, 'number' => '01012345678', 'balance' => 8000],
    ['name' => 'محفظة أورنج كاش', 'provider' => WalletProvider::OrangeCash, 'number' => '01022223333', 'balance' => 5000],
    ['name' => 'محفظة اتصالات كاش', 'provider' => WalletProvider::EtisalatCash, 'number' => '01055554444', 'balance' => 3000],
    ['name' => 'محفظة WE Pay', 'provider' => WalletProvider::WePay, 'number' => '01555556666', 'balance' => 2000],
];
$createdWallets = [];
foreach ($wallets as $w) {
    $acc = open_account($admin, $w['name'], AccountType::Wallet, 'EGP', $w['balance'], 'office', '', false, $w['provider']->value, $w['number']);
    $createdWallets[] = $acc;
    echo "  ✓ {$w['name']} — {$w['balance']} EGP [{$w['provider']->value}]\n";
}

// ── 6) Customers with AR accounts
echo "\n[6] Customers\n";
$customerData = [
    ['full_name' => 'أحمد محمد علي', 'phone' => '01001234567', 'tier' => 'VIP'],
    ['full_name' => 'فاطمة حسن إبراهيم', 'phone' => '01002345678', 'tier' => 'PREMIUM'],
    ['name' => 'خالد عبدالله سعيد', 'phone' => '01003456789', 'tier' => 'STANDARD'],
];
$createdCustomers = [];
foreach ($customerData as $c) {
    $arAccount = open_account(
        $admin,
        'حساب العميل: ' . ($c['full_name'] ?? $c['name']),
        AccountType::Customer,
        'EGP',
        0,
        'wallet_transfer',
        'حساب عميل افتراضي لمتابعة المديونية',
        false
    );
    $cust = Customer::create([
        'account_id' => $arAccount->id,
        'full_name' => $c['full_name'] ?? $c['name'],
        'name' => $c['full_name'] ?? $c['name'],
        'phone' => $c['phone'],
        'status' => 'active',
        'customer_tier' => $c['tier'],
        'module_type' => 'wallet_transfer',
        'type' => 'individual',
        'created_by' => $admin->id,
    ]);
    $createdCustomers[] = $cust;
    echo "  ✓ {$cust->full_name} ({$c['phone']}) — AR account #{$arAccount->id}\n";
}

// ── 7) Suppliers (with linked AP accounts)
echo "\n[7] Suppliers (with AP accounts)\n";
$suppliers = [
    ['code' => 'SUPERJET-CAI', 'name' => 'شركة باصات القاهرة — سوبر جيت', 'phone' => '0223456789', 'type' => 'bus_company', 'module' => 'bus'],
    ['code' => 'GOLD-Alex', 'name' => 'شركة باصات الإسكندرية — جولد باص', 'phone' => '0345678910', 'type' => 'bus_company', 'module' => 'bus'],
    ['code' => 'WALLET-SUP', 'name' => 'مورد المحافظ الإلكترونية', 'phone' => '01112223344', 'type' => 'service_provider', 'module' => 'wallet_transfer'],
];
$createdSuppliers = [];
foreach ($suppliers as $s) {
    $apAccount = open_account(
        $admin,
        $s['name'] . ' — AP',
        AccountType::Supplier,
        'EGP',
        0,
        $s['module'],
        'حساب مورد ' . $s['name'],
        false
    );
    $sup = Supplier::create([
        'account_id' => $apAccount->id,
        'code' => $s['code'],
        'name' => $s['name'],
        'phone' => $s['phone'],
        'type' => $s['type'],
        'is_active' => true,
        'created_by' => $admin->id,
    ]);
    $createdSuppliers[] = $sup;
    echo "  ✓ {$sup->name} ({$sup->code}) — AP account #{$apAccount->id}\n";
}

// ── Persist state for next phase
$state = [
    'admin' => ['id' => $admin->id, 'email' => $admin->email, 'password' => $password],
    'banks' => collect($createdBanks)->flatten(1)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'balance' => (float)$a->balance])->all(),
    'cashboxes' => collect($createdCashboxes)->flatten(1)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'balance' => (float)$a->balance, 'vault' => (bool)$a->is_module_vault])->all(),
    'wallets' => collect($createdWallets)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'provider' => $a->wallet_provider?->value, 'number' => $a->wallet_number, 'balance' => (float)$a->balance])->all(),
    'customers' => collect($createdCustomers)->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name, 'account_id' => $c->account_id])->all(),
    'suppliers' => collect($createdSuppliers)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code])->all(),
];

file_put_contents(__DIR__ . '/office_master_state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  Setup complete. State saved to office_master_state.json.\n";
echo "  Admin: {$admin->email} / {$password}\n";
echo "═══════════════════════════════════════════════════════════\n";
