<?php
/**
 * MODULE COVERAGE GATE — minimal required master data seeder.
 *
 * Mode: STRESS-ONLY (safarak_stress). Idempotent: re-runnable.
 *
 * Creates ONLY the minimum dataset required to make Phase 2 module E2E
 * runs possible. Does NOT modify:
 *  - production/dev databases (hard-aborted by env check)
 *  - production application code
 *  - existing balances (no AccountEntry writes for opening balances)
 *  - existing routes/services/migrations
 *
 * Deterministic naming convention: STRESS-* prefix.
 *
 * Created (idempotent):
 *  1. STRESS-OFFICE-VAULT    (accounts,        cashbox,  is_module_vault=1)
 *  2. STRESS-FC-001          (flight_carriers, balance=0)
 *  3. STRESS-BUSCOMP-001     (bus_companies,   via factory)
 *  4. STRESS-BUSINV-001      (bus_inventories, via factory)
 *  5. STRESS-TYPE-001        (online_service_types)
 *  6. STRESS-WT-001          (wallet_types,   via factory)
 *  7. STRESS-WALLET-001      (accounts, type=wallet)
 *  8. STRESS-CASHBOX-001     (accounts, type=cashbox)
 *
 * NOT created (per spec):
 *  - Visa detail records (created inline by VisaBookingService)
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Enums\AccountType;
use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Flight\FlightCarrier;
use App\Models\Online\OnlineServiceType;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;

// ===== Hard-abort environment guard =====
$env  = env('APP_ENV');
$db   = config('database.connections.mysql.database');
$sel  = DB::selectOne('SELECT DATABASE() AS d')->d;
if ($env !== 'stress') {
    fwrite(STDERR, "HARD-ABORT: APP_ENV is '{$env}', not 'stress'.\n");
    exit(2);
}
if ($db !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: DB_DATABASE is '{$db}', not 'safarak_stress'.\n");
    exit(2);
}
if ($sel !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: SELECT DATABASE() is '{$sel}', not 'safarak_stress'.\n");
    exit(2);
}

echo "=== ENVIRONMENT VERIFIED ===\n";
echo "APP_ENV=stress  DB_DATABASE=safarak_stress  SELECT DATABASE()=safarak_stress\n\n";

$created = [];
$skipped = [];

// Snapshot pre-state for ledger consistency check
$preBalanceSum = (float) DB::selectOne("SELECT COALESCE(SUM(balance), 0) AS s FROM accounts")->s;
$preEntryCount = (int) DB::selectOne("SELECT COUNT(*) AS c FROM account_entries")->c;
$preAccountCount = (int) DB::selectOne("SELECT COUNT(*) AS c FROM accounts")->c;

echo "PRE-STATE: accounts={$preAccountCount}  account_entries={$preEntryCount}  balance_sum={$preBalanceSum}\n\n";

// =========================================================================
// 1. Office Vault
// =========================================================================
echo "[1/8] Office Vault: STRESS-OFFICE-VAULT\n";
$existing = Account::where('name', 'STRESS-OFFICE-VAULT')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['office_vault'] = $existing->id;
} else {
    $vault = Account::create([
        'name'            => 'STRESS-OFFICE-VAULT',
        'type'            => AccountType::Cashbox,
        'currency'        => 'EGP',
        'balance'         => 0,
        'is_active'       => true,
        'owner_type'      => Account::OWNER_TYPE_OWNER,
        'module_type'     => 'office',
        'module'          => null,
        'is_module_vault' => true,
        'notes'           => 'Stress-only office vault for Module Coverage Gate (PG-01)',
        'created_by'      => 1,
    ]);
    echo "  CREATED id={$vault->id} balance={$vault->balance} is_module_vault=" . ($vault->is_module_vault ? '1' : '0') . "\n";
    $created['office_vault'] = $vault->id;
}

// =========================================================================
// 2. Flight Carrier
// =========================================================================
echo "\n[2/8] Flight Carrier: STRESS-FC-001\n";
$existing = FlightCarrier::withTrashed()->where('code', 'STRESS-FC-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['flight_carrier'] = $existing->id;
} else {
    $carrier = FlightCarrier::create([
        'name'         => 'STRESS-AIRLINE-001',
        'code'         => 'STRESS-FC-001',
        'currency'     => 'EGP',
        'is_active'    => true,
        'created_by'   => 1,
    ]);
    echo "  CREATED id={$carrier->id} code={$carrier->code} balance={$carrier->balance}\n";
    $created['flight_carrier'] = $carrier->id;
}

// =========================================================================
// 3. Bus Company (factory) + 4. Bus Inventory (factory)
// =========================================================================
echo "\n[3/8] Bus Company: STRESS-BUSCOMP-001\n";
$existing = BusCompany::withTrashed()->where('name', 'STRESS-BUSCOMP-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['bus_company'] = $existing->id;
    $busCompany = $existing;
} else {
    $busCompany = BusCompany::factory()->create([
        'name'       => 'STRESS-BUSCOMP-001',
        'phone'      => '01000000001',
        'is_active'  => true,
        'created_by' => 1,
    ]);
    echo "  CREATED id={$busCompany->id}\n";
    $created['bus_company'] = $busCompany->id;
}

echo "\n[4/8] Bus Inventory: STRESS-BUSINV-001\n";
$existing = BusInventory::withTrashed()->where('notes', 'STRESS-BUSINV-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['bus_inventory'] = $existing->id;
} else {
    $busInventory = BusInventory::factory()->create([
        'company_id'           => $busCompany->id,
        'currency'             => 'EGP',
        'exchange_rate_to_egp' => 1.0,
        'payment_type'         => BusInventoryPaymentType::Deferred,
        'is_auto_created'      => false,
        'notes'                => 'STRESS-BUSINV-001',
        'created_by'           => 1,
    ]);
    echo "  CREATED id={$busInventory->id} company_id={$busInventory->company_id} available_tickets={$busInventory->available_tickets}\n";
    $created['bus_inventory'] = $busInventory->id;
}

// =========================================================================
// 5. Online Service Type
// =========================================================================
echo "\n[5/8] Online Service Type: STRESS-TYPE-001\n";
$existing = OnlineServiceType::withTrashed()->where('code', 'STRESS-TYPE-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['online_service_type'] = $existing->id;
} else {
    $serviceType = OnlineServiceType::create([
        'code'        => 'STRESS-TYPE-001',
        'name_ar'     => 'نوع اختبار ضغط',
        'name_en'     => 'Stress service type',
        'is_active'   => true,
        'order'       => 1,
        'color'       => '#000000',
        'created_by'  => 1,
    ]);
    echo "  CREATED id={$serviceType->id} code={$serviceType->code}\n";
    $created['online_service_type'] = $serviceType->id;
}

// =========================================================================
// 6. Wallet Type (factory)
// =========================================================================
echo "\n[6/8] Wallet Type: STRESS-WT-001\n";
$existing = WalletType::where('code', 'STRESS-WT-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['wallet_type'] = $existing->id;
} else {
    // WalletType model does NOT have HasFactory trait, so use direct create().
    // The factory exists but cannot be invoked via factory() — use it directly:
    $factory = app(\Database\Factories\Wallet\WalletTypeFactory::class);
    $walletType = $factory->create([
        'code'      => 'STRESS-WT-001',
        'is_active' => true,
    ]);
    echo "  CREATED id={$walletType->id} code={$walletType->code}\n";
    $created['wallet_type'] = $walletType->id;
}

// =========================================================================
// 7. Wallet Account (Account::create, type=wallet)
// =========================================================================
echo "\n[7/8] Wallet Account: STRESS-WALLET-001\n";
$existing = Account::where('name', 'STRESS-WALLET-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['wallet_account'] = $existing->id;
} else {
    $walletAccount = Account::create([
        'name'            => 'STRESS-WALLET-001',
        'type'            => AccountType::Wallet,
        'currency'        => 'EGP',
        'balance'         => 0,
        'is_active'       => true,
        'owner_type'      => Account::OWNER_TYPE_OWNER,
        'module_type'     => 'office',
        'module'          => 'wallet_transfer',
        'is_module_vault' => false,
        'notes'           => 'Stress-only wallet account for Module Coverage Gate',
        'created_by'      => 1,
    ]);
    echo "  CREATED id={$walletAccount->id} type={$walletAccount->type->value} balance={$walletAccount->balance}\n";
    $created['wallet_account'] = $walletAccount->id;
}

// =========================================================================
// 8. Cashbox Account (Account::create, type=cashbox)
// =========================================================================
echo "\n[8/8] Cashbox Account: STRESS-CASHBOX-001\n";
$existing = Account::where('name', 'STRESS-CASHBOX-001')->first();
if ($existing) {
    echo "  SKIP — already exists (id={$existing->id})\n";
    $skipped['cashbox_account'] = $existing->id;
} else {
    $cashbox = Account::create([
        'name'            => 'STRESS-CASHBOX-001',
        'type'            => AccountType::Cashbox,
        'currency'        => 'EGP',
        'balance'         => 0,
        'is_active'       => true,
        'owner_type'      => Account::OWNER_TYPE_OWNER,
        'module_type'     => 'office',
        'module'          => null,
        'is_module_vault' => false,
        'notes'           => 'Stress-only cashbox account for Module Coverage Gate',
        'created_by'      => 1,
    ]);
    echo "  CREATED id={$cashbox->id} type={$cashbox->type->value} balance={$cashbox->balance}\n";
    $created['cashbox_account'] = $cashbox->id;
}

// =========================================================================
// POST-STATE & invariants
// =========================================================================
echo "\n=== POST-STATE ===\n";
$postBalanceSum = (float) DB::selectOne("SELECT COALESCE(SUM(balance), 0) AS s FROM accounts")->s;
$postEntryCount = (int) DB::selectOne("SELECT COUNT(*) AS c FROM account_entries")->c;
$postAccountCount = (int) DB::selectOne("SELECT COUNT(*) AS c FROM accounts")->c;
$balanceDelta = $postBalanceSum - $preBalanceSum;
$entryDelta = $postEntryCount - $preEntryCount;
$accountDelta = $postAccountCount - $preAccountCount;

echo "  accounts:        {$preAccountCount} -> {$postAccountCount} (delta={$accountDelta})\n";
echo "  account_entries: {$preEntryCount} -> {$postEntryCount} (delta={$entryDelta})\n";
echo "  balance_sum:     {$preBalanceSum} -> {$postBalanceSum} (delta={$balanceDelta})\n";

echo "\n=== INVARIANT CHECKS ===\n";
echo "  - balance_sum unchanged:          " . ($balanceDelta == 0.0 ? 'PASS' : 'FAIL') . " (delta={$balanceDelta})\n";
echo "  - account_entries unchanged:      " . ($entryDelta === 0 ? 'PASS' : 'FAIL') . " (delta={$entryDelta})\n";
echo "  - new accounts count = created:   " . ($accountDelta === count($created) ? 'PASS' : 'FAIL') . " (expected=" . count($created) . ", actual={$accountDelta})\n";

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n=== SUMMARY ===\n";
echo "CREATED: " . count($created) . "\n";
foreach ($created as $k => $id) {
    echo "  {$k} = id={$id}\n";
}
echo "SKIPPED (already existed): " . count($skipped) . "\n";
foreach ($skipped as $k => $id) {
    echo "  {$k} = id={$id}\n";
}

echo "\n=== DONE ===\n";
