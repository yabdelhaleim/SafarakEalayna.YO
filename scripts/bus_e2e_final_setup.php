<?php

/**
 * FINAL E2E — Test environment setup.
 *
 * Seeds the local SQLite test DB with:
 *   - 1 owner (super-admin)
 *   - 1 admin
 *   - 1 manager (F-10 probe)
 *   - 1 employee (F-10 probe)
 *   - 1 employee for booking creation
 *   - 4 customer test fixtures
 *   - A BusCashbox (EGP) account
 *   - 1 BusCompany "FINAL-E2E-BUS-COMPANY"
 *
 * Then records baseline balances for every account that the FINAL-E2E
 * test will touch.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINAL E2E — SETUP (seed test users + test company + capture baseline)\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// ── 1. Ensure 4 user roles ──
echo "[1] Seeding 4 user roles (owner/admin/manager/employee)…\n";
$roleFixtures = [
    ['owner',    'owner@final.local'],
    ['admin',    'admin@final.local'],
    ['manager',  'manager@final.local'],
    ['employee', 'employee@final.local'],
];

foreach ($roleFixtures as [$role, $email]) {
    $u = User::firstOrCreate(['email' => $email], [
        'name' => 'FINAL E2E '.ucfirst($role),
        'password' => Hash::make('password'),
        'role' => $role,
        'is_active' => true,
    ]);
    echo "    - {$role}: id={$u->id} email={$u->email}\n";
}

// Ensure an employee who will create bookings (separate from probe employee)
$booker = User::firstOrCreate(['email' => 'booker@final.local'], [
    'name' => 'FINAL E2E Booker',
    'password' => Hash::make('password'),
    'role' => 'employee',
    'is_active' => true,
]);
echo "    - booker: id={$booker->id} email={$booker->email}\n";

// ── 2. Capture baseline accounts (before any business ops) ──
echo "\n[2] Capturing baseline balances for accounts the test will touch…\n";

// Pick the main cashbox (BusCashbox / OfficeCashbox) for testing
$busCashbox = Account::where('name', 'LIKE', '%bus%cash%')
    ->orWhere('name', 'LIKE', '%cashbox%')
    ->orWhere('name', 'LIKE', '%office%cash%')
    ->orderBy('id')->first();

if (! $busCashbox) {
    // Create a dedicated test cashbox if none exists
    $busCashbox = Account::firstOrCreate(['code' => 'FINAL-E2E-CASHBOX'], [
        'name' => 'FINAL E2E Cashbox',
        'type' => 'cashbox',
        'module_type' => 'office',
        'balance' => 100000.00,
        'is_active' => true,
    ]);
    echo "    - Created FINAL-E2E-CASHBOX (id={$busCashbox->id}, balance=100000.00)\n";
}

echo "    - BusCashbox: id={$busCashbox->id} name='{$busCashbox->name}' opening_balance={$busCashbox->balance}\n";

// Capture income_clearing + expense_clearing accounts (bus module)
$incomeClearing = Account::where('name', 'LIKE', '%income%clearing%')
    ->orWhere('name', 'LIKE', '%bus%income%')
    ->orderBy('id')->first();
$expenseClearing = Account::where('name', 'LIKE', '%expense%clearing%')
    ->orWhere('name', 'LIKE', '%bus%expense%')
    ->orderBy('id')->first();

echo '    - Income clearing: '.($incomeClearing ? "id={$incomeClearing->id} balance={$incomeClearing->balance}" : 'ABSENT')."\n";
echo '    - Expense clearing: '.($expenseClearing ? "id={$expenseClearing->id} balance={$expenseClearing->balance}" : 'ABSENT')."\n";

// ── 3. Create test customer ──
echo "\n[3] Creating test customer…\n";
$customer = Customer::firstOrCreate(['national_id' => '30001011200999'], [
    'full_name' => 'FINAL E2E Customer',
    'phone' => '01900000999',
    'email' => 'final-customer@test.local',
    'type' => 'individual',
    'customer_tier' => 'STANDARD',
    'nationality' => 'EG',
    'city' => 'القاهرة',
]);
echo "    - Customer: id={$customer->id}\n";

// ── 4. Save baseline state for later verification ──
echo "\n[4] Persisting baseline snapshot to storage/logs/bus_e2e_final_baseline.json…\n";

$baseline = [
    'cashbox_id' => $busCashbox->id,
    'cashbox_name' => $busCashbox->name,
    'cashbox_opening' => (float) $busCashbox->balance,
    'income_clearing_id' => $incomeClearing?->id,
    'income_clearing_opening' => $incomeClearing ? (float) $incomeClearing->balance : null,
    'expense_clearing_id' => $expenseClearing?->id,
    'expense_clearing_opening' => $expenseClearing ? (float) $expenseClearing->balance : null,
    'customer_id' => $customer->id,
    'admin_id' => User::where('role', 'admin')->first()->id,
    'booker_id' => $booker->id,
    'seeded_at' => now()->toIso8601String(),
];

file_put_contents(
    __DIR__.'/../storage/logs/bus_e2e_final_baseline.json',
    json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
echo "\n  ✅ Setup complete.\n";
