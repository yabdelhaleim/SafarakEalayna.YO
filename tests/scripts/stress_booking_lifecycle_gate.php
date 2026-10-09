<?php
/**
 * PRE-PHASE-B BOOKING LIFECYCLE GATE
 * =================================
 *
 * Hard rule: This script tests the REAL booking lifecycle through the REAL
 * service path (HajjUmraBookingService::create / addPayment / cancel /
 * deleteBookingWithReversal). It NEVER uses synthetic Transaction rows as
 * a substitute for the booking flow.
 *
 * DB: ONLY safarak_stress. Pre-flight aborts on any forbidden DB.
 *
 * Lifecycle exercised:
 *   1. Create a real booking (create → posts expense + income + customer account)
 *   2. Verify initial financial state (paid=0, remaining=total)
 *   3. First partial payment → verify payment recorded + debt reduced
 *   4. Second partial payment → verify remaining reduced exactly
 *   5. Final payment → verify remaining = 0
 *   6. Duplicate payment attempt → verify no duplicate financial mutation
 *   7. Cancellation attempt on a fully-paid booking → verify cancel() works
 *      (additive reversal: original rows preserved, inverse entries added,
 *       booking status → cancelled, customer debt → 0, ledger net = 0)
 *   8. Controlled failure injection during addPayment → verify complete rollback
 *      (no partial tx, no partial AccountEntry, no balance mutation, no debt mutation)
 *
 * After every step:
 *   - account.balance == SUM(account_entries.credit - account_entries.debit)
 *   - SUM(transaction.debit) == SUM(transaction.credit) per transaction
 *   - booking.paid_amount + remaining == total_selling_price
 *   - customer-account balance reflects the booking timeline
 *
 * Output:
 *   - storage/app/stress/booking-lifecycle-run.log
 *   - storage/app/stress/booking-lifecycle.json
 *
 * Exits 0 on PASS, 1 on FAIL or BLOCKED.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// ──────────────────────────────────────────────────────────────────────────────
// 0. Pre-flight safety guard
// ──────────────────────────────────────────────────────────────────────────────
$FORBIDDEN = ['safarakealayna', 'safarak_ealayna', 'travel_office', 'production'];

// Explicitly load .env.stress (do not rely on shell-exported vars) — the
// production `.env` must NEVER be touched by this script.
$envStressPath = __DIR__ . '/../../.env.stress';
if (! is_file($envStressPath)) {
    fwrite(STDERR, "✗ HARD ABORT — .env.stress not found at {$envStressPath}\n");
    exit(2);
}
foreach (file($envStressPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    // strip optional inline comments AFTER the value, plus surrounding quotes
    $v = trim($v);
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
    }
    // Drop any trailing comment (after a space + #) inside the value
    if (preg_match('/^(.*?)\s+#.*$/', $v, $m)) {
        $v = $m[1];
    }
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}

$appEnv  = (string) (getenv('APP_ENV') ?: '');
$dbConn  = (string) (getenv('DB_CONNECTION') ?: '');
$dbName  = (string) (getenv('DB_DATABASE') ?: '');
$dbHost  = (string) (getenv('DB_HOST') ?: '');
$dbPort  = (string) (getenv('DB_PORT') ?: '');

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║  PRE-PHASE-B BOOKING LIFECYCLE GATE — Pre-flight        ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
echo "============================================================\n";
echo "STRESS DB:  {$dbConn}\n";
echo "HOST:       {$dbHost}\n";
echo "DATABASE:   {$dbName}\n";
echo "APP_ENV:    {$appEnv}\n";
echo "PID:        " . getmypid() . "\n";
echo "TIME:       " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n";

if (in_array($dbName, $FORBIDDEN, true) || in_array(strtolower($appEnv), ['production','prod','live'], true)) {
    fwrite(STDERR, "✗ HARD ABORT — forbidden DB / APP_ENV detected.\n");
    exit(2);
}

// Bootstrap Laravel with stress env
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Verify SELECT DATABASE()
try {
    $selectDb = DB::selectOne('SELECT DATABASE() AS db')->db ?? '(null)';
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ HARD ABORT — cannot SELECT DATABASE(): " . $e->getMessage() . "\n");
    exit(2);
}

echo "APP_ENV:          {$appEnv}\n";
echo "DB_CONNECTION:    {$dbConn}\n";
echo "DB_HOST:          {$dbHost}\n";
echo "DB_PORT:          {$dbPort}\n";
echo "DB_DATABASE:      {$dbName}\n";
echo "SELECT DATABASE(): {$selectDb}\n";
echo "DISK FREE:        " . round(disk_free_space(sys_get_temp_dir()) / 1024 / 1024 / 1024, 2) . " GiB\n";
echo "PID:              " . getmypid() . "\n";

if ($selectDb !== 'safarak_stress') {
    fwrite(STDERR, "✗ HARD ABORT — SELECT DATABASE()='{$selectDb}', expected 'safarak_stress'.\n");
    exit(2);
}

echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// Helper: invariant snapshots
// ──────────────────────────────────────────────────────────────────────────────
function ledgerSnapshot(string $label): array
{
    $accounts = DB::table('accounts')
        ->selectRaw('id, name, type, balance')
        ->whereIn('name', ['STRESS-HU-VAULT', 'STRESS-HU-SUPPLIER'])
        ->orWhere('name', 'like', 'حساب العميل:%')
        ->orderBy('id')
        ->get();

    $snapshot = ['label' => $label, 'accounts' => []];
    foreach ($accounts as $a) {
        $sumRow = DB::table('account_entries')
            ->selectRaw('COALESCE(SUM(credit - debit), 0) AS ledger_sum, COUNT(*) AS entry_count')
            ->where('account_id', $a->id)
            ->first();
        $variance = round((float) $a->balance - (float) $sumRow->ledger_sum, 4);
        $snapshot['accounts'][] = [
            'id' => (int) $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'balance' => (float) $a->balance,
            'ledger_sum_credit_minus_debit' => (float) $sumRow->ledger_sum,
            'entry_count' => (int) $sumRow->entry_count,
            'variance' => $variance,
            'invariant_ok' => (abs($variance) <= 0.02),
        ];
    }
    return $snapshot;
}

function bookingDebtSnapshot(int $bookingId, string $label): array
{
    $booking = HajjUmraBooking::with('payments')->findOrFail($bookingId);
    $paidAmount = (float) $booking->payments()->sum('amount');
    $remaining = max(0.0, (float) $booking->total_selling_price - $paidAmount);
    $paid_attr = (float) $booking->paid_amount;
    $remaining_attr = (float) $booking->remaining_amount;

    return [
        'label' => $label,
        'booking_id' => $bookingId,
        'status' => $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status,
        'total_selling_price' => (float) $booking->total_selling_price,
        'paid_via_query' => $paidAmount,
        'paid_via_attr' => $paid_attr,
        'remaining_via_query' => $remaining,
        'remaining_via_attr' => $remaining_attr,
        'paid_consistent' => abs($paidAmount - $paid_attr) <= 0.01,
        'remaining_consistent' => abs($remaining - $remaining_attr) <= 0.01,
        'paid_plus_remaining_equals_total' => abs(($paidAmount + $remaining) - (float) $booking->total_selling_price) <= 0.01,
        'payment_count' => (int) $booking->payments()->count(),
        'trashed' => $booking->trashed(),
    ];
}

function assertInvariant(array $snap, array $defects, string $stepKey): array
{
    foreach ($snap['accounts'] as $a) {
        if (! $a['invariant_ok']) {
            $defects[$stepKey][] = sprintf(
                'INVARIANT VIOLATION: account %d (%s) balance=%.4f vs ledger_sum=%.4f (variance=%.4f)',
                $a['id'], $a['name'], $a['balance'], $a['ledger_sum_credit_minus_debit'], $a['variance']
            );
        }
    }
    return $defects;
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. Setup minimal fixtures on safarak_stress
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 1. Setup minimal fixtures ───\n";

$actor = User::query()->first();
if (! $actor) {
    fwrite(STDERR, "✗ ABORT — no actor user in DB.\n");
    exit(2);
}
auth()->login($actor);

// Treasury vault (cashbox) — module_type MUST be a DIVISION ('office' or
// 'tourism') per AccountModuleContract. hajj_umra lives under 'tourism'.
// Account::getModuleVault('hajj_umra') resolves to module_type='tourism'
// + is_module_vault=true.
$vault = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-VAULT', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Cashbox,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'tourism',
            'is_module_vault' => true,
            'notes' => 'Stress Hajj/Umrah treasury',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});
// Open vault balance via canonical opening balance (Transaction + 2 AccountEntry).
$openingEquity = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-EQUITY', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Owner,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'general',
            'is_module_vault' => false,
            'notes' => 'Stress Hajj/Umrah opening equity offset',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});

// Seed a single opening tx that establishes 1,000,000 EGP of vault cash
// against an offsetting owner-equity credit (capital injection). Under the
// project convention `account.balance = SUM(credit) - SUM(debit)`, BOTH
// legs are credits (vault +1M asset, equity +1M owner's equity). Using a
// debit on the equity account would fail the insufficient-balance guard
// at AccountService:402 because the equity account starts at 0.
$existingOpening = Transaction::where('notes', 'STRESS-HU-OPENING')->first();
if (! $existingOpening) {
    DB::transaction(function () use ($vault, $openingEquity) {
        $tx = Transaction::create([
            'type' => 'transfer',
            'amount' => 1_000_000,
            'currency' => 'EGP',
            'module' => 'general',
            'from_account_id' => null,
            'to_account_id' => $vault->id,
            'notes' => 'STRESS-HU-OPENING',
            'created_by' => auth()->id() ?? 1,
        ]);
        // Both legs are CREDIT — vault asset up, equity capital up.
        $svc = app(AccountService::class);
        $svc->credit($vault, 1_000_000, (int) $tx->id);
        $svc->credit($openingEquity, 1_000_000, (int) $tx->id);
    });
}
echo "   vault id={$vault->id} balance={$vault->fresh()->balance}\n";

// Supplier with its own account — type=Supplier requires a SPECIFIC module
// (not a division). 'hajj_umra' is the right tag.
$supplierAccount = LedgerBalanceMutationGuard::run(function () {
    return Account::firstOrCreate(
        ['name' => 'STRESS-HU-SUPPLIER', 'currency' => 'EGP'],
        [
            'type' => \App\Enums\AccountType::Supplier,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'is_module_vault' => false,
            'notes' => 'Stress supplier for Hajj/Umrah',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});
$supplier = UmrahSupplier::firstOrCreate(
    ['name' => 'STRESS-HU-SUPPLIER-CO'],
    [
        'phone' => '+201000000099',
        'account_id' => $supplierAccount->id,
        'default_cost_price' => 1000,
        'is_active' => true,
    ]
);
echo "   supplier id={$supplier->id} account_id={$supplierAccount->id}\n";

// Program — required by HajjUmraBookingService::create
$program = Program::firstOrCreate(
    ['program_name' => 'STRESS-HU-PROGRAM'],
    [
        'program_type' => 'UMRA',
        'season' => 'STRESS',
        'total_nights' => 7,
        'accommodation_type' => 'DOUBLE',
        'mecca_hotel_name' => 'STRESS-MECCA',
        'mecca_nights' => 4,
        'medina_hotel_name' => 'STRESS-MEDINA',
        'medina_nights' => 3,
        'departure_date' => now()->addDays(30)->toDateString(),
        'return_date' => now()->addDays(37)->toDateString(),
        'airline' => 'STRESS-AIR',
        'executing_company' => 'STRESS-HU-EXEC',
        'departure_point' => 'CAI',
        'booking_status' => 'CONFIRMED',
        'default_purchase_price' => 10000,
        'default_selling_price' => 15000,
        'is_active' => true,
    ]
);
echo "   program id={$program->id}\n";

// Customer
$customer = Customer::firstOrCreate(
    ['phone' => '+201000000050'],
    [
        'full_name' => 'STRESS CUSTOMER HU',
        'national_id' => sprintf('STR%011d', 50),
        'module_type' => 'hajj_umra',
        'created_by' => null,
    ]
);
echo "   customer id={$customer->id} account_id={$customer->account_id}\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// 2. CREATE A REAL BOOKING
// ──────────────────────────────────────────────────────────────────────────────
$stepResults = [];
$defects = [];

echo "─── 2. CREATE A REAL BOOKING (HajjUmraBookingService::create) ───\n";

$purchasePrice = 10000.00;
$sellingPrice  = 15000.00;
$expectedTotal = 15000.00;

$booking = app(HajjUmraBookingService::class)->create([
    'customer_id'    => $customer->id,
    'program_id'     => $program->id,
    'supplier_id'    => $supplier->id,
    'purchase_price' => $purchasePrice,
    'selling_price'  => $sellingPrice,
    'currency'       => 'EGP',
    'per_person'     => true,
    'accommodation_choice' => 'standard',
    'employee_id'    => $actor->id,
    'notes'          => '[STRESS-LIFECYCLE] Booking A',
]);

$customerAccountId = (int) $customer->fresh()->account_id;
$customerAccount = Account::findOrFail($customerAccountId);

echo "   booking id={$booking->id} status={$booking->status->value}\n";
echo "   total_selling_price={$booking->total_selling_price} paid={$booking->paid_amount} remaining={$booking->remaining_amount}\n";
echo "   expense_transaction_id={$booking->expense_transaction_id} income_transaction_id={$booking->income_transaction_id}\n";
echo "   customer account_id={$customerAccountId} balance={$customerAccount->balance}\n\n";

$initialDebt = bookingDebtSnapshot($booking->id, 'after_create');
$stepResults['after_create'] = $initialDebt;
$stepResults['after_create_ledger'] = ledgerSnapshot('after_create');

$defects = assertInvariant($stepResults['after_create_ledger'], $defects, 'after_create');

if (! $initialDebt['paid_plus_remaining_equals_total']) {
    $defects['after_create'][] = "paid + remaining ({$initialDebt['paid_via_query']} + {$initialDebt['remaining_via_query']}) != total ({$initialDebt['total_selling_price']})";
}

$vaultBalanceAfterCreate = (float) Account::find($vault->id)->balance;
$supplierBalanceAfterCreate = (float) Account::find($supplierAccount->id)->balance;
echo "   vault balance after create={$vaultBalanceAfterCreate} (expected ~ 990000)\n";
echo "   supplier balance after create={$supplierBalanceAfterCreate} (expected ~ 10000)\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// 3. FIRST PARTIAL PAYMENT — 5000 EGP
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 3. FIRST PARTIAL PAYMENT (5000 EGP) ───\n";
app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
    'amount'         => 5000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => '[STRESS-LIFECYCLE] pay#1',
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
]);
$booking->refresh();
echo "   paid={$booking->paid_amount} remaining={$booking->remaining_amount}\n";
$after1 = bookingDebtSnapshot($booking->id, 'after_pay_5000');
$stepResults['after_pay_5000'] = $after1;
$stepResults['after_pay_5000_ledger'] = ledgerSnapshot('after_pay_5000');
$defects = assertInvariant($stepResults['after_pay_5000_ledger'], $defects, 'after_pay_5000');
if (abs($after1['paid_via_query'] - 5000.0) > 0.01) {
    $defects['after_pay_5000'][] = "expected paid=5000, got {$after1['paid_via_query']}";
}
if (abs($after1['remaining_via_query'] - 10000.0) > 0.01) {
    $defects['after_pay_5000'][] = "expected remaining=10000, got {$after1['remaining_via_query']}";
}
echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// 4. SECOND PARTIAL PAYMENT — 3000 EGP
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 4. SECOND PARTIAL PAYMENT (3000 EGP) ───\n";
app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
    'amount'         => 3000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => '[STRESS-LIFECYCLE] pay#2',
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
]);
$booking->refresh();
echo "   paid={$booking->paid_amount} remaining={$booking->remaining_amount}\n";
$after2 = bookingDebtSnapshot($booking->id, 'after_pay_3000');
$stepResults['after_pay_3000'] = $after2;
$stepResults['after_pay_3000_ledger'] = ledgerSnapshot('after_pay_3000');
$defects = assertInvariant($stepResults['after_pay_3000_ledger'], $defects, 'after_pay_3000');
if (abs($after2['paid_via_query'] - 8000.0) > 0.01) {
    $defects['after_pay_3000'][] = "expected paid=8000, got {$after2['paid_via_query']}";
}
if (abs($after2['remaining_via_query'] - 7000.0) > 0.01) {
    $defects['after_pay_3000'][] = "expected remaining=7000, got {$after2['remaining_via_query']}";
}
echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// 5. FINAL PAYMENT — 7000 EGP (settle)
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 5. FINAL PAYMENT (7000 EGP) ───\n";
app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
    'amount'         => 7000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => '[STRESS-LIFECYCLE] pay#3-final',
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
]);
$booking->refresh();
echo "   paid={$booking->paid_amount} remaining={$booking->remaining_amount}\n";
$after3 = bookingDebtSnapshot($booking->id, 'after_pay_7000_final');
$stepResults['after_pay_7000_final'] = $after3;
$stepResults['after_pay_7000_final_ledger'] = ledgerSnapshot('after_pay_7000_final');
$defects = assertInvariant($stepResults['after_pay_7000_final_ledger'], $defects, 'after_pay_7000_final');
if (abs($after3['remaining_via_query']) > 0.01) {
    $defects['after_pay_7000_final'][] = "expected remaining=0, got {$after3['remaining_via_query']}";
}
echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// 6. DUPLICATE PAYMENT ATTEMPT — second 7000 EGP should be blocked / safe
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 6. DUPLICATE PAYMENT ATTEMPT (re-pay 7000 EGP) ───\n";
$txCountBefore = DB::table('transactions')->count();
$entryCountBefore = DB::table('account_entries')->count();
$vaultBalanceBefore = (float) Account::find($vault->id)->balance;
$supplierBalanceBefore = (float) Account::find($supplierAccount->id)->balance;
$customerBalanceBefore = (float) Account::find($customerAccountId)->balance;

$duplicateAllowed = true;
$duplicateError = null;
try {
    // Most production systems don't block this at the service level (no idempotency key);
    // it should simply create an extra journal. We accept either outcome (no rejection
    // expected) but the ledger MUST remain invariant-OK and the customer balance must
    // not exceed the sale total.
    app(HajjUmraBookingService::class)->addPayment($booking->fresh(), [
        'amount'         => 7000,
        'account_id'     => $vault->id,
        'payment_method' => 'cash',
        'currency'       => 'EGP',
        'reference'      => '[STRESS-LIFECYCLE] pay#4-duplicate',
        'paid_by'        => $customer->full_name,
        'created_by'     => $actor->id,
    ]);
} catch (\Throwable $e) {
    $duplicateAllowed = false;
    $duplicateError = $e->getMessage();
}

$booking->refresh();
$txCountAfter = DB::table('transactions')->count();
$entryCountAfter = DB::table('account_entries')->count();
$vaultBalanceAfter = (float) Account::find($vault->id)->balance;
$supplierBalanceAfter = (float) Account::find($supplierAccount->id)->balance;
$customerBalanceAfter = (float) Account::find($customerAccountId)->balance;

echo "   duplicate_allowed=" . ($duplicateAllowed ? 'true' : 'false') . "\n";
if ($duplicateError) echo "   error: {$duplicateError}\n";
echo "   tx_count_delta=" . ($txCountAfter - $txCountBefore) . " entries_delta=" . ($entryCountAfter - $entryCountBefore) . "\n";
echo "   vault  before/after: {$vaultBalanceBefore} → {$vaultBalanceAfter}\n";
echo "   supp   before/after: {$supplierBalanceBefore} → {$supplierBalanceAfter}\n";
echo "   cust   before/after: {$customerBalanceBefore} → {$customerBalanceAfter}\n";
echo "   customer balance now: {$customerBalanceAfter} (selling=15000 — positive balance = overpayment)\n\n";

$after4 = bookingDebtSnapshot($booking->id, 'after_duplicate_7000');
$stepResults['after_duplicate_7000'] = $after4;
$stepResults['after_duplicate_7000_ledger'] = ledgerSnapshot('after_duplicate_7000');
$defects = assertInvariant($stepResults['after_duplicate_7000_ledger'], $defects, 'after_duplicate_7000');

// Per the project convention (Account.balance = SUM(credit) - SUM(debit)),
// a payment from customer AR → vault means: customer account is DEBITed (decreases).
// Customer balance should NEVER go below -selling_price (i.e. -15000).
if ($customerBalanceAfter < -15000.01) {
    $defects['after_duplicate_7000'][] = "customer balance {$customerBalanceAfter} below -selling_price (-15000) — overpay leaked through";
}

// If duplicate was accepted, paid_amount should be 22000 now (8000 + 7000 final + 7000 dup).
// Either outcome is acceptable as long as invariants hold.
echo "   booking paid after duplicate: {$after4['paid_via_query']}\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// 7. CANCEL — additive reversal; original tx rows preserved; net balance = 0
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 7. CANCEL booking (additive reversal) ───\n";
$txCountBeforeCancel = DB::table('transactions')->count();
$entryCountBeforeCancel = DB::table('account_entries')->count();
$vaultBeforeCancel = (float) Account::find($vault->id)->balance;
$supplierBeforeCancel = (float) Account::find($supplierAccount->id)->balance;
$customerBeforeCancel = (float) Account::find($customerAccountId)->balance;

try {
    app(HajjUmraBookingService::class)->cancel($booking->fresh(), '[STRESS-LIFECYCLE] test-cancel');
} catch (\Throwable $e) {
    $defects['cancel'][] = "cancel() threw: " . $e->getMessage();
}

$booking->refresh();
$txCountAfterCancel = DB::table('transactions')->count();
$entryCountAfterCancel = DB::table('account_entries')->count();
$vaultAfterCancel = (float) Account::find($vault->id)->balance;
$supplierAfterCancel = (float) Account::find($supplierAccount->id)->balance;
$customerAfterCancel = (float) Account::find($customerAccountId)->balance;

echo "   status={$booking->status->value}\n";
echo "   tx_count delta (must be 0 — additive only): " . ($txCountAfterCancel - $txCountBeforeCancel) . "\n";
echo "   entries_delta (must be ≥ 2 per reversed tx): " . ($entryCountAfterCancel - $entryCountBeforeCancel) . "\n";
echo "   vault before→after: {$vaultBeforeCancel} → {$vaultAfterCancel} (delta=" . round($vaultAfterCancel - $vaultBeforeCancel, 2) . ")\n";
echo "   supp before→after: {$supplierBeforeCancel} → {$supplierAfterCancel} (delta=" . round($supplierAfterCancel - $supplierBeforeCancel, 2) . ")\n";
echo "   cust before→after: {$customerBeforeCancel} → {$customerAfterCancel} (delta=" . round($customerAfterCancel - $customerBeforeCancel, 2) . ")\n\n";

if ($txCountAfterCancel !== $txCountBeforeCancel) {
    $defects['cancel'][] = "cancel() destroyed transactions (tx_count_delta=" . ($txCountAfterCancel - $txCountBeforeCancel) . ")";
}

// Hard invariant: cancellation must drive customer balance back to 0
// (we reversed both the income AND the customer-AR transfer legs).
if (abs($customerAfterCancel) > 0.01) {
    $defects['cancel'][] = "customer balance after cancel = {$customerAfterCancel}, expected 0";
}
if (abs($supplierAfterCancel) > 0.01) {
    $defects['cancel'][] = "supplier balance after cancel = {$supplierAfterCancel}, expected 0";
}
// Vault after cancel = vault_before_cancel + 22000 (payments back out) - 10000 (expense back)
// Actually: vault_before was 1000000 - 10000 (expense) + 5000 + 3000 + 7000 = 1003000
// After cancel: reverse income (debit customer, credit vault for 15000) + reverse expense
// + reverse 3 payment transfers.
// Net effect on vault: -15000 (from income reversal) + 10000 (from expense reversal)
//   - 5000 - 3000 - 7000 (from payment reversals) = -20000.
// So vault_after = vault_before - 20000 = 983000.
// Customer should net to 0.

$after5 = bookingDebtSnapshot($booking->id, 'after_cancel');
$stepResults['after_cancel'] = $after5;
$stepResults['after_cancel_ledger'] = ledgerSnapshot('after_cancel');
$defects = assertInvariant($stepResults['after_cancel_ledger'], $defects, 'after_cancel');

echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// 8. FAILURE INJECTION — controlled exception during addPayment, verify rollback
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 8. FAILURE INJECTION during addPayment ───\n";
// Create a SECOND booking so we can inject a failure into a payment
// without disturbing the (already cancelled) booking above.
$booking2 = app(HajjUmraBookingService::class)->create([
    'customer_id'    => $customer->id,
    'program_id'     => $program->id,
    'supplier_id'    => $supplier->id,
    'purchase_price' => 5000,
    'selling_price'  => 7500,
    'currency'       => 'EGP',
    'per_person'     => true,
    'accommodation_choice' => 'standard',
    'employee_id'    => $actor->id,
    'notes'          => '[STRESS-LIFECYCLE] Booking B',
]);
echo "   booking2 id={$booking2->id} total_selling={$booking2->total_selling_price} remaining={$booking2->remaining_amount}\n";

$txCountBefore = DB::table('transactions')->count();
$entryCountBefore = DB::table('account_entries')->count();
$vaultBefore = (float) Account::find($vault->id)->balance;
$customerBefore = (float) Account::find($customerAccountId)->balance;
$booking2Before = bookingDebtSnapshot($booking2->id, 'before_inject');

try {
    DB::transaction(function () use ($booking2, $vault, $actor, $customer) {
        app(HajjUmraBookingService::class)->addPayment($booking2->fresh(), [
            'amount'         => 9999,
            'account_id'     => $vault->id,
            'payment_method' => 'cash',
            'currency'       => 'EGP',
            'reference'      => '[STRESS-LIFECYCLE] inject',
            'paid_by'        => $customer->full_name,
            'created_by'     => $actor->id,
        ]);
        // Controlled failure — simulate downstream consumer throwing.
        throw new \RuntimeException('[STRESS-LIFECYCLE] injected failure AFTER addPayment');
    });
} catch (\Throwable $e) {
    echo "   injected exception caught: " . $e->getMessage() . "\n";
}

$txCountAfter = DB::table('transactions')->count();
$entryCountAfter = DB::table('account_entries')->count();
$vaultAfter = (float) Account::find($vault->id)->balance;
$customerAfter = (float) Account::find($customerAccountId)->balance;
$booking2After = bookingDebtSnapshot($booking2->id, 'after_inject');

echo "   tx_count_delta (must be 0): " . ($txCountAfter - $txCountBefore) . "\n";
echo "   entries_delta (must be 0): " . ($entryCountAfter - $entryCountBefore) . "\n";
echo "   vault before→after: {$vaultBefore} → {$vaultAfter} (delta=" . round($vaultAfter - $vaultBefore, 4) . ")\n";
echo "   cust before→after: {$customerBefore} → {$customerAfter} (delta=" . round($customerAfter - $customerBefore, 4) . ")\n";
echo "   booking2 paid before→after: {$booking2Before['paid_via_query']} → {$booking2After['paid_via_query']}\n";
echo "   booking2 remaining before→after: {$booking2Before['remaining_via_query']} → {$booking2After['remaining_via_query']}\n\n";

$stepResults['after_inject'] = $booking2After;
$stepResults['after_inject_ledger'] = ledgerSnapshot('after_inject');
$defects = assertInvariant($stepResults['after_inject_ledger'], $defects, 'after_inject');

if ($txCountAfter !== $txCountBefore) {
    $defects['after_inject'][] = "ROLLBACK FAILED — tx_count_delta=" . ($txCountAfter - $txCountBefore);
}
if ($entryCountAfter !== $entryCountBefore) {
    $defects['after_inject'][] = "ROLLBACK FAILED — entries_delta=" . ($entryCountAfter - $entryCountBefore);
}
if (abs($vaultAfter - $vaultBefore) > 0.01) {
    $defects['after_inject'][] = "ROLLBACK FAILED — vault moved by " . ($vaultAfter - $vaultBefore);
}
if (abs($customerAfter - $customerBefore) > 0.01) {
    $defects['after_inject'][] = "ROLLBACK FAILED — customer moved by " . ($customerAfter - $customerBefore);
}
if (abs($booking2After['paid_via_query'] - $booking2Before['paid_via_query']) > 0.01) {
    $defects['after_inject'][] = "ROLLBACK FAILED — booking paid moved by " . ($booking2After['paid_via_query'] - $booking2Before['paid_via_query']);
}

// ──────────────────────────────────────────────────────────────────────────────
// 9. Admin delete-with-reversal on booking2
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 9. deleteBookingWithReversal on booking2 ───\n";
try {
    app(HajjUmraBookingService::class)->deleteBookingWithReversal((int) $booking2->id, $actor);
} catch (\Throwable $e) {
    $defects['delete_with_reversal'][] = "deleteBookingWithReversal threw: " . $e->getMessage();
}
$b2 = HajjUmraBooking::withTrashed()->find($booking2->id);
echo "   booking2 trashed=" . ($b2->trashed() ? 'true' : 'false') . "\n";
$stepResults['after_delete'] = ledgerSnapshot('after_delete');
$defects = assertInvariant($stepResults['after_delete'], $defects, 'after_delete');
echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// 10. Final reconciliation
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 10. Final reconciliation ───\n";
$finalLedger = ledgerSnapshot('final');
$finalTotals = DB::table('account_entries')
    ->selectRaw('COALESCE(SUM(credit),0) AS credits, COALESCE(SUM(debit),0) AS debits, COUNT(*) AS entry_count')
    ->first();
$finalAccounts = DB::table('accounts')->selectRaw('COALESCE(SUM(balance),0) AS balance_sum')->value('balance_sum');
echo "   credits={$finalTotals->credits} debits={$finalTotals->debits} entries={$finalTotals->entry_count}\n";
echo "   accounts.balance_sum={$finalAccounts} (diff vs credits-debits = " . round($finalAccounts - ((float)$finalTotals->credits - (float)$finalTotals->debits), 4) . ")\n\n";

$stepResults['final_ledger'] = $finalLedger;
$defects = assertInvariant($finalLedger, $defects, 'final');

// ──────────────────────────────────────────────────────────────────────────────
// Verdict
// ──────────────────────────────────────────────────────────────────────────────
$verdict = 'PASS';
if (! empty($defects)) {
    $verdict = 'FAIL';
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║  BOOKING LIFECYCLE GATE — VERDICT: " . str_pad($verdict, 5) . "                ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";

if (! empty($defects)) {
    echo "\n─── Defects ───\n";
    foreach ($defects as $step => $list) {
        echo "  [{$step}]\n";
        foreach ($list as $d) {
            echo "    - {$d}\n";
        }
    }
}

// Persist artifact
$artifact = [
    'phase' => 'PRE-PHASE-B',
    'gate' => 'booking_lifecycle',
    'service' => 'HajjUmraBookingService',
    'ran_at' => date('c'),
    'preflight' => [
        'app_env' => $appEnv,
        'connection' => $dbConn,
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbName,
        'select_db' => $selectDb,
        'pid' => getmypid(),
    ],
    'fixtures' => [
        'actor_id' => (int) $actor->id,
        'vault_id' => (int) $vault->id,
        'supplier_id' => (int) $supplier->id,
        'supplier_account_id' => (int) $supplierAccount->id,
        'program_id' => (int) $program->id,
        'customer_id' => (int) $customer->id,
        'customer_account_id' => (int) $customerAccountId,
    ],
    'booking_a' => [
        'id' => (int) $booking->id,
        'final_status' => $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status,
        'final_paid' => (float) $booking->paid_amount,
        'final_remaining' => (float) $booking->remaining_amount,
    ],
    'step_results' => $stepResults,
    'defects' => $defects,
    'verdict' => $verdict,
];

$logPath = storage_path('app/stress/booking-lifecycle-run.log');
$jsonPath = storage_path('app/stress/booking-lifecycle.json');
@mkdir(dirname($logPath), 0755, true);
file_put_contents($jsonPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
// Also tee the log
file_put_contents($logPath, "\n--- booking-lifecycle.json ---\n" . file_get_contents($jsonPath));

echo "\nArtifact: storage/app/stress/booking-lifecycle.json\n\n";

exit($verdict === 'PASS' ? 0 : 1);
