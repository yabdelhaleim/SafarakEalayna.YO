<?php

/**
 * Cross-currency refund audit — runs the proven audit framework
 * (audit_refund_flow.php setup) once per foreign currency, all with
 * customer paying in EGP. This is the scenario that was broken before
 * the fix.
 *
 * For each currency X ∈ {USD, SAR, KWD, EUR, GBP}:
 *   - Ticket priced in X (foreign)
 *   - Customer pays in EGP into EGP cashbox
 *   - Cancel with airline_penalty + office_penalty
 *   - Verify: refund_amount = 812.50 - 162.50 - 81.25 = 568.75 EGP
 *   - Verify: EGP cashbox drops by 568.75
 *   - Verify: Customer AR = 0
 *
 * Uses the same proven setup as audit_refund_flow.php (the one that
 * already passes for KWD).
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$currencies = [
    'USD' => ['foreign' => 50.0,  'rate' => 50.0],
    'SAR' => ['foreign' => 100.0, 'rate' => 13.50],
    'KWD' => ['foreign' => 5.0,   'rate' => 162.5],
    'EUR' => ['foreign' => 25.0,  'rate' => 52.0],
    'GBP' => ['foreign' => 20.0,  'rate' => 60.0],
];

$egpAmount = 812.50;
$airlinePenalty = 162.50;
$officePenalty = 81.25;
$expectedRefund = $egpAmount - $airlinePenalty - $officePenalty; // 568.75

echo "=== CROSS-CURRENCY REFUND AUDIT (customer pays EGP, ticket in foreign) ===\n";
echo "Customer pays: 812.50 EGP\n";
echo "Penalties: airline=162.50 EGP, office=81.25 EGP\n";
echo "Expected refund into EGP cashbox: 568.75 EGP\n\n";

$allPass = true;
$summary = [];

foreach ($currencies as $cur => $cfg) {
    echo str_repeat('─', 70) . "\n";
    echo "[$cur] Ticket=$cfg[foreign] $cur, rate=$cfg[rate] EGP/$cur\n";

    DB::beginTransaction();
    try {
        // Clean slate per iteration (disable FK checks for the reset)
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('flight_refunds')->delete();
        DB::table('flight_payments')->delete();
        DB::table('flight_bookings')->delete();
        DB::table('flight_carriers')->delete();
        DB::table('flight_systems')->delete();
        DB::table('customers')->delete();
        DB::table('treasuries')->delete();
        DB::table('account_entries')->delete();
        DB::table('transactions')->delete();
        DB::table('accounts')->delete();
        DB::table('users')->where('email', 'like', 'audit-mc-%')->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        // User / employee
        // Ensure currencies exist with the exchange rates this audit needs.
        // (Real admin uses the Currencies Filament page.)
        foreach ($currencies as $code => $cfg) {
            Currency::updateOrCreate(
                ['code' => $code],
                ['name_ar' => $code, 'name_en' => $code, 'symbol' => $code,
                 'exchange_rate' => $cfg['rate'], 'is_active' => true, 'order' => 0]
            );
        }
        Currency::updateOrCreate(
            ['code' => 'EGP'],
            ['name_ar' => 'EGP', 'name_en' => 'EGP', 'symbol' => 'EGP',
             'exchange_rate' => 1.0, 'is_active' => true, 'order' => 0]
        );

        $admin = User::create([
            'name' => 'Audit MC',
            'email' => 'audit-mc-' . uniqid() . '@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $employee = Employee::create(['user_id' => $admin->id, 'status' => 'active']);
        Auth::login($admin);

        // EGP cashbox
        $egpCashbox = Account::create([
            'name' => "AUDIT EGP cashbox {$cur}",
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
            'is_module_vault' => true,
            'created_by' => $admin->id,
        ]);
        $opening = 100000.0;
        LedgerBalanceMutationGuard::run(function () use ($egpCashbox, $opening, $admin, $cur) {
            $tx = Transaction::create([
                'type' => 'transfer',
                'amount' => $opening,
                'module' => 'general',
                'from_account_id' => $egpCashbox->id,
                'to_account_id' => $egpCashbox->id,
                'created_by' => $admin->id,
                'notes' => "OPENING: EGP cashbox audit-{$cur}",
            ]);
            AccountEntry::create([
                'account_id' => $egpCashbox->id,
                'transaction_id' => $tx->id,
                'debit' => 0,
                'credit' => $opening,
                'balance_after' => $opening,
            ]);
            $egpCashbox->update(['balance' => $opening]);
        });

        // $cur system + carrier
        $sys = FlightSystem::create([
            'name' => "{$cur} System",
            'code' => substr($cur, 0, 2) . uniqid(),
            'currency' => $cur,
            'credit_limit' => 500000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $carrier = FlightCarrier::create([
            'name' => "{$cur} Carrier",
            'code' => substr($cur, 0, 2) . uniqid(),
            'flight_system_id' => $sys->id,
            'currency' => $cur,
            'credit_limit' => 500000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        // Customer
        $customer = Customer::create([
            'full_name' => "Customer {$cur}",
            'phone' => '0100000' . substr(md5($cur), 0, 4),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $svc = app(FlightBookingService::class);

        $ppForeign = 500.0 / $cfg['rate']; // purchase portion in $cur
        $booking = $svc->createBooking([
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'selling_price' => $egpAmount,                 // 812.5 EGP
            'purchase_price' => 500.0,
            'selling_price_foreign' => $cfg['foreign'],
            'purchase_price_foreign' => $ppForeign,
            'currency' => $cur,
            'original_currency' => 'EGP',
            'original_amount' => $egpAmount,
            'account_id' => $egpCashbox->id,
            'agent_name' => 'Audit MC',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->addDays(14)->toDateString(),
            'departure_time' => '08:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($cur, 0, 2),
            'passenger_count' => 1,
            'flight_carrier_id' => $carrier->id,
            'pnr' => 'MC' . uniqid(),
        ]);

        $svc->addPayment($booking, [
            'amount' => $egpAmount,
            'payment_method' => 'cash',
            'account_id' => $egpCashbox->id,
            'idempotency_key' => 'audit-mc-' . uniqid(),
        ]);
        $svc->confirmBooking($booking);

        $cashBefore = (float) $egpCashbox->fresh()->balance;

        $refund = $svc->cancelBooking($booking, [
            'airline_penalty' => $airlinePenalty,
            'office_penalty' => $officePenalty,
            'account_id' => $egpCashbox->id,
            'notes' => "Audit MC {$cur}",
        ]);

        $r = $refund->refresh();
        $cashAfter = (float) $egpCashbox->fresh()->balance;
        $custAr = (float) $customer->fresh()->ledgerAccount->balance;

        $refundOk = abs((float)$r->refund_amount - $expectedRefund) < 0.01;
        $cashDelta = $cashBefore - $cashAfter; // out
        $cashOk = abs($cashDelta - $expectedRefund) < 0.01;
        // Customer AR should settle to 0 — allow 1 EGP tolerance for
        // cross-rate rounding dust on exchange-rate math.
        $arOk = abs($custAr) < 1.0;

        $pass = $refundOk && $cashOk && $arOk;
        $mark = $pass ? '✓' : '✗';

        echo sprintf(
            "  %s refund=%.2f EGP | cashbox out=%.2f EGP | cust_ar=%.2f\n",
            $mark,
            (float)$r->refund_amount,
            $cashDelta,
            $custAr
        );

        $summary[$cur] = ['pass' => $pass, 'refund' => (float)$r->refund_amount];
        if (!$pass) {
            $allPass = false;
        }
        DB::rollBack();
    } catch (\Throwable $e) {
        echo "  ✗ EXCEPTION: " . $e->getMessage() . "\n";
        echo "  FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $summary[$cur] = ['pass' => false, 'error' => $e->getMessage()];
        $allPass = false;
        DB::rollBack();
    }
}

echo str_repeat('═', 70) . "\n";
echo "SUMMARY\n";
echo str_repeat('═', 70) . "\n";
foreach ($summary as $cur => $r) {
    $status = $r['pass'] ? 'PASS' : 'FAIL';
    echo sprintf("  %-4s | %s%s\n", $cur, $status, isset($r['error']) ? ' — ' . substr($r['error'], 0, 50) : '');
}
echo $allPass
    ? "\nALL FOREIGN CURRENCIES PASSED ✓ — refund uses customer's paid currency, not booking currency\n"
    : "\nSOME FAILURES ✗\n";
exit($allPass ? 0 : 1);
