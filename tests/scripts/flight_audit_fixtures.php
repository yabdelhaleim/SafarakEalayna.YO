<?php
/**
 * FLIGHT FULL AUDIT — Phase 2 / Section 2 (Stress fixtures).
 *
 * Pre-flight:
 *  - APP_ENV=stress
 *  - DB_DATABASE=safarak_stress
 *
 * Uses canonical application paths only:
 *  - FlightCarrierRechargeService::rechargeFromAccount()
 *  - FlightSystemRechargeService::rechargeFromAccount()
 *  - TransactionService::recordTransfer()  (for group debt payDebt)
 *
 * NEVER manually sets account.balance / flight_carriers.balance / flight_systems.balance.
 * NEVER inserts AccountEntry or Transaction rows directly.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Setting\Currency as SettingCurrency;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Hard abort
$env = env('APP_ENV');
$db = config('database.connections.mysql.database');
$sel = DB::selectOne('SELECT DATABASE() AS d')->d;
if ($env !== 'stress' || $db !== 'safarak_stress' || $sel !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: env={$env} db={$db} sel={$sel}\n");
    exit(2);
}
echo "ENV: APP_ENV=stress DB_DATABASE=safarak_stress\n\n";

Auth::loginUsingId(1);
$user = User::find(1);

// ============================================================================
// 1. Ensure currencies seeded (using canonical rates from FawryModuleProductionTestSeeder)
// ============================================================================
echo "[1] Seeding currencies (canonical rates from FawryModuleProductionTestSeeder)…\n";
$ccyRows = [
    ['code' => 'EGP', 'name_ar' => 'الجنيه المصري',  'name_en' => 'Egyptian Pound', 'symbol' => 'ج.م', 'exchange_rate' => 1.0000,  'is_active' => 1, 'order' => 1],
    ['code' => 'USD', 'name_ar' => 'الدولار الأمريكي', 'name_en' => 'US Dollar',     'symbol' => '$',   'exchange_rate' => 49.5000, 'is_active' => 1, 'order' => 2],
    ['code' => 'SAR', 'name_ar' => 'الريال السعودي',  'name_en' => 'Saudi Riyal',   'symbol' => 'ر.س', 'exchange_rate' => 13.2000, 'is_active' => 1, 'order' => 3],
    ['code' => 'KWD', 'name_ar' => 'الدينار الكويتي',  'name_en' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exchange_rate' => 161.5000, 'is_active' => 1, 'order' => 4],
];
foreach ($ccyRows as $row) {
    $exists = SettingCurrency::where('code', $row['code'])->first();
    if (!$exists) {
        SettingCurrency::create($row);
        echo "  + added currency {$row['code']} rate={$row['exchange_rate']}\n";
    } else {
        echo "  = existing currency {$row['code']} rate={$exists->exchange_rate}\n";
    }
}
echo "  Total: " . SettingCurrency::count() . "\n\n";

// ============================================================================
// 2. Ensure treasury accounts for each currency (only direct create is acceptable here — these are reference accounts, not balance-bearing until recharged)
// ============================================================================
echo "[2] Seeding multi-currency treasury accounts…\n";
$treasurySeeds = [
    ['name' => 'STRESS-FLIGHTS-TREASURY-USD', 'type' => 'cashbox',    'currency' => 'USD', 'initial_balance' => 10000.00],
    ['name' => 'STRESS-FLIGHTS-TREASURY-SAR', 'type' => 'cashbox',    'currency' => 'SAR', 'initial_balance' => 10000.00],
    ['name' => 'STRESS-FLIGHTS-TREASURY-KWD', 'type' => 'cashbox',    'currency' => 'KWD', 'initial_balance' => 1000.00],
    ['name' => 'STRESS-FLIGHTS-TREASURY-EGP', 'type' => 'cashbox',    'currency' => 'EGP', 'initial_balance' => 0.00],
];
foreach ($treasurySeeds as $seed) {
    $existing = Account::where('name', $seed['name'])->first();
    if (!$existing) {
        Account::create([
            'name'        => $seed['name'],
            'type'        => $seed['type'],
            'currency'    => $seed['currency'],
            'balance'     => 0.00,
            'is_active'   => true,
            'module_type' => 'tourism',
        ]);
        echo "  + created treasury {$seed['name']} ({$seed['currency']}) balance=0.00 (to be funded via canonical journal transfer)\n";
    } else {
        echo "  = existing treasury {$seed['name']} balance={$existing->balance}\n";
    }
}

// ============================================================================
// 3. Existing carrier STRESS-FC-001 — already EGP. Add foreign-currency carrier STRESS-FC-002 (USD).
// ============================================================================
echo "\n[3] Seeding multi-currency carriers…\n";
$carrierSeeds = [
    ['code' => 'STRESS-FC-001', 'name' => 'STRESS FC EGP',     'currency' => 'EGP', 'credit_limit' => 50000.0],
    ['code' => 'STRESS-FC-USD', 'name' => 'STRESS FC USD',     'currency' => 'USD', 'credit_limit' => 5000.0],
    ['code' => 'STRESS-FC-SAR', 'name' => 'STRESS FC SAR',     'currency' => 'SAR', 'credit_limit' => 20000.0],
    ['code' => 'STRESS-FC-KWD', 'name' => 'STRESS FC KWD',     'currency' => 'KWD', 'credit_limit' => 1000.0],
];
foreach ($carrierSeeds as $seed) {
    $existing = FlightCarrier::where('code', $seed['code'])->first();
    if (!$existing) {
        FlightCarrier::create([
            'code'         => $seed['code'],
            'name'         => $seed['name'],
            'currency'     => $seed['currency'],
            'credit_limit' => $seed['credit_limit'],
            'is_active'    => true,
            'created_by'   => $user->id,
        ]);
        echo "  + created carrier {$seed['code']} ({$seed['currency']})\n";
    } else {
        echo "  = existing carrier {$seed['code']} balance={$existing->balance}\n";
    }
}

// ============================================================================
// 4. Fund foreign-currency carriers via FlightCarrierRechargeService (CANONICAL PATH)
// ============================================================================
echo "\n[4] Funding source treasuries via canonical journal transfer + recharging carriers…\n";
$svc = app(FlightCarrierRechargeService::class);
$txSvc = app(\App\Services\Finance\TransactionService::class);

// Funding from STRESS-HU-VAULT (egp) requires cross-currency conversion. For
// canonical funding, simulate by treating the source treasury as if it had
// foreign-currency opening balance posted via a canonical system account.
// In production, multi-currency opening balances are seeded via admin paths;
// for the audit we accept a documented CLASSS-C setup: a SystemAccount-funded
// opening balance via TransactionService::recordJournalTransfer into each
// foreign-currency treasury, with the system-side explicitly noted.

// 4a. Fund each foreign-currency treasury via canonical transfer from a fountain account
$funderAcct = Account::getModuleVault('tourism') ?? Account::getModuleVault('flights') ?? Account::where('type', 'cashbox')->where('currency', 'EGP')->where('balance', '>=', 100000)->first();
if (! $funderAcct) {
    fwrite(STDERR, "HARD-ABORT: no EGP funder vault with >= 100000 balance to seed multi-currency treasuries.\n");
    exit(2);
}
$funderBefore = (float) $funderAcct->fresh()->balance;

$treasuryFunding = [
    'STRESS-FLIGHTS-TREASURY-USD' => 10000.0,
    'STRESS-FLIGHTS-TREASURY-SAR' => 10000.0,
    'STRESS-FLIGHTS-TREASURY-KWD' => 1000.0,
];
foreach ($treasuryFunding as $name => $amt) {
    $t = Account::where('name', $name)->first();
    if (!$t) continue;
    // Use canonical conversion path for cross-currency
    $rate = null;
    $ccy = SettingCurrency::where('code', $t->currency)->first();
    if ($ccy) $rate = (float) $ccy->exchange_rate;
    try {
        $txSvc->recordJournalTransfer([
            'from_account_id' => $funderAcct->id,
            'to_account_id'   => $t->id,
            'amount'          => $amt * ($rate ?? 1.0),  // EGP cost
            'converted_amount'=> $amt,
            'exchange_rate'   => $rate,
            'currency'        => $t->currency,
            'notes'           => 'AUDIT fixture seed: opening balance for ' . $t->name,
            'module'          => \App\Enums\TransactionModule::Flight->value,
            'related_type'    => null,
            'related_id'      => null,
            'created_by'      => $user->id,
        ]);
        $funderAfter = (float) $funderAcct->fresh()->balance;
        echo "  ✓ funded {$name}: foreign={$amt} {$t->currency} (egp=" . round($amt*($rate??1),2) . ") — funder EGP {$funderBefore}->{$funderAfter}\n";
    } catch (\Throwable $e) {
        echo "  ! funding failed for {$name}: " . $e->getMessage() . "\n";
    }
}

// 4b. Recharge carriers from their currency-matching treasury
$rechargeAmounts = [
    'STRESS-FC-USD' => 5000.0,
    'STRESS-FC-SAR' => 10000.0,
    'STRESS-FC-KWD' => 500.0,
];
foreach ($rechargeAmounts as $code => $amt) {
    $carrier = FlightCarrier::where('code', $code)->first();
    $source  = Account::where('name', 'STRESS-FLIGHTS-TREASURY-' . $carrier->currency)->first();
    if (!$source || (float) $source->fresh()->balance < $amt) {
        echo "  ! cannot recharge {$code}: source treasury missing or insufficient (balance=" . (float)($source->fresh()->balance ?? 0) . ", need {$amt})\n";
        continue;
    }
    $beforeBalance = (float) $carrier->fresh()->balance;
    $beforeTreasury = (float) $source->fresh()->balance;
    try {
        $result = $svc->rechargeFromAccount($carrier, $source, $amt, 'AUDIT fixture seed');
        $afterCarrier  = (float) $carrier->fresh()->balance;
        $afterTreasury = (float) $source->fresh()->balance;
        $ok = abs($afterCarrier - ($beforeBalance + $amt)) < 0.01 && abs(($afterTreasury - ($beforeTreasury - $amt))) < 0.01;
        echo sprintf(
            "  %s %s amount=%.2f carrier_balance %.2f -> %.2f treasury_balance %.2f -> %.2f\n",
            $ok ? '✓' : '✗',
            $code,
            $amt,
            $beforeBalance,
            $afterCarrier,
            $beforeTreasury,
            $afterTreasury,
        );
    } catch (\Throwable $e) {
        echo "  ! recharge failed for {$code}: " . $e->getMessage() . "\n";
    }
}

// ============================================================================
// 5. Create one FlightSystem (TYPE C)
// ============================================================================
echo "\n[5] Seeding FlightSystem (TYPE C)…\n";
$system = FlightSystem::where('code', 'STRESS-FS-001')->first();
if (!$system) {
    $system = FlightSystem::create([
        'code'         => 'STRESS-FS-001',
        'name'         => 'STRESS FS',
        'type'         => 'gds',
        'currency'     => 'EGP',
        'credit_limit' => 200000.0,
        'is_active'    => true,
        'created_by'   => $user->id,
    ]);
    echo "  + created system STRESS-FS-001 (EGP)\n";
} else {
    echo "  = existing system STRESS-FS-001 balance={$system->balance}\n";
}

// ============================================================================
// 6. Recharge the FlightSystem via canonical FlightSystemRechargeService
// ============================================================================
echo "\n[6] Recharging FlightSystem via FlightSystemRechargeService (canonical path)…\n";
$sourceEgp = Account::where('name', 'STRESS-FLIGHTS-TREASURY-EGP')->first();
if (!$sourceEgp) {
    // Try the default flights vault
    $sourceEgp = Account::getModuleVault('flights');
}
if ($sourceEgp) {
    $systemBefore = (float) $system->fresh()->balance;
    $systemAmount = $systemBefore >= 50000 ? 0 : (50000 - $systemBefore);
    if ($systemAmount > 0) {
        // First, fund the source EGP treasury from a tourism vault with balance, via canonical journal transfer.
        if ((float) $sourceEgp->balance < $systemAmount) {
            $funder = Account::where('module_type', 'tourism')->where('type', 'cashbox')->where('balance', '>=', (float) $systemAmount + 50000)->first();
            if ($funder) {
                try {
                    $txSvc = app(\App\Services\Finance\TransactionService::class);
                    $txSvc->recordJournalTransfer([
                        'from_account_id' => $funder->id,
                        'to_account_id'   => $sourceEgp->id,
                        'amount'          => 50000.0,
                        'notes'           => 'AUDIT fixture seed: fund flights EGP treasury',
                        'module'          => \App\Enums\TransactionModule::Flight->value,
                        'related_type'    => FlightSystem::class,
                        'related_id'      => $system->id,
                        'created_by'      => $user->id,
                    ]);
                    $funderAfter = (float) $funder->fresh()->balance;
                    $sourceAfter = (float) $sourceEgp->fresh()->balance;
                    echo "  ✓ funded {$sourceEgp->name}: +50000 from {$funder->name} (now {$funderAfter})\n";
                } catch (\Throwable $e) {
                    echo "  ! funding failed: " . $e->getMessage() . "\n";
                }
            }
        }
        try {
            $sysSvc = app(FlightSystemRechargeService::class);
            $result = $sysSvc->rechargeFromAccount($system, $sourceEgp, $systemAmount, 'AUDIT fixture seed');
            $afterSys = (float) $system->fresh()->balance;
            echo "  ✓ system recharged: {$systemBefore} -> {$afterSys} (-{$systemAmount} from {$sourceEgp->name})\n";
        } catch (\Throwable $e) {
            echo "  ! system recharge failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  = system already has sufficient balance ({$systemBefore})\n";
    }
}

// ============================================================================
// 7. Create one FlightGroup (TYPE B) linked to the EGP carrier
// ============================================================================
echo "\n[7] Seeding FlightGroup (TYPE B)…\n";
$egpCarrier = FlightCarrier::where('code', 'STRESS-FC-001')->first();
$group = FlightGroup::where('code', 'STRESS-FG-001')->first();
if (!$group) {
    $group = FlightGroup::create([
        'flight_carrier_id' => $egpCarrier->id,
        'code'              => 'STRESS-FG-001',
        'name'              => 'STRESS Group',
        'currency'          => 'EGP',
        'credit_limit'      => 200000.0,
        'is_active'         => true,
        'created_by'        => $user->id,
    ]);
    echo "  + created group STRESS-FG-001 (EGP) carrier=" . $egpCarrier->code . " credit_limit=200000\n";
} else {
    echo "  = existing group STRESS-FG-001 credit_limit=" . $group->credit_limit . "\n";
}

// ============================================================================
// Summary
// ============================================================================
echo "\n=== FIXTURE SUMMARY ===\n";
echo "Currencies: " . SettingCurrency::count() . "\n";
echo "Carriers: " . FlightCarrier::count() . "\n";
foreach (FlightCarrier::all() as $c) {
    echo " - {$c->code} {$c->name} balance={$c->balance} {$c->currency} avail=" . $c->available_balance . "\n";
}
echo "Systems: " . FlightSystem::count() . "\n";
foreach (FlightSystem::all() as $s) {
    echo " - {$s->code} {$s->name} balance={$s->balance} {$s->currency} avail=" . $s->available_balance . "\n";
}
echo "Groups: " . FlightGroup::count() . "\n";
foreach (FlightGroup::all() as $g) {
    echo " - {$g->code} {$g->name} credit_limit={$g->credit_limit} {$g->currency}\n";
}
echo "Treasury accounts (flights module): " . Account::where('module_type', 'flights')->where('type', 'cashbox')->count() . "\n";
foreach (Account::where('module_type', 'flights')->where('type', 'cashbox')->get() as $a) {
    echo " - {$a->name} balance={$a->balance} {$a->currency}\n";
}
echo "\nDone.\n";
