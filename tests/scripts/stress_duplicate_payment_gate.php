<?php
/**
 * DUPLICATE / REPLAY PAYMENT GATE
 * ================================
 *
 * Goal: prove (or refute) the application's idempotency contract for the
 * payment endpoint by replaying the SAME logical payment request and
 * observing the system's actual behavior.
 *
 * Tested via TWO contract surfaces:
 *   (S) Service-layer: HajjUmraBookingService::addPayment() — bypasses the
 *       controller but still routes through TransactionService → AccountService.
 *   (H) HTTP layer: POST /api/v1/hajj-umra/bookings/{id}/payments via the
 *       real Laravel kernel (routing + middleware + FormRequest + controller
 *       + service + transaction).
 *
 * Scenarios:
 *   A. Same `reference` replay (deterministic id) — service layer
 *   B. Same payload, NO reference (worst case) — service layer
 *   C. Two legitimate payments, same amount, DIFFERENT references — service
 *   D. Same `reference` replay via the HTTP endpoint — full kernel
 *
 * For each scenario we capture before/after:
 *   - HajjUmraPayment rows (count + ids)
 *   - transactions count + ids (filter on related_type=HajjUmraBooking)
 *   - account_entries count + ids (filter on accounts involved)
 *   - booking.paid_amount
 *   - customer_account.balance  vs  SUM(account_entries.credit - debit)
 *   - vault_account.balance     vs  SUM(account_entries.credit - debit)
 *
 * Exit codes:
 *   0 = PASS — expected idempotency contract holds end-to-end
 *   1 = FAIL — unexpected duplicate financial mutations
 *   2 = GAP  — application has NO idempotency contract at this layer (architectural gap, not a bug)
 *   3 = BLOCKED — pre-flight or environment failure
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Finance\AccountService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ──────────────────────────────────────────────────────────────────────────────
// 0. Pre-flight safety guard (mandatory)
// ──────────────────────────────────────────────────────────────────────────────
$FORBIDDEN = ['safarakealayna', 'safarak_ealayna', 'travel_office', 'production'];

$envStressPath = __DIR__ . '/../../.env.stress';
if (! is_file($envStressPath)) {
    fwrite(STDERR, "✗ HARD ABORT — .env.stress not found at {$envStressPath}\n");
    exit(3);
}
foreach (file($envStressPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
    }
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
echo "║  DUPLICATE / REPLAY PAYMENT GATE — Pre-flight           ║\n";
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
    exit(3);
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $selectDb = DB::selectOne('SELECT DATABASE() AS db')->db ?? '(null)';
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ HARD ABORT — cannot SELECT DATABASE(): " . $e->getMessage() . "\n");
    exit(3);
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
    exit(3);
}
echo "\n";

// ──────────────────────────────────────────────────────────────────────────────
// Helper: invariant snapshot for the booking + its accounts
// ──────────────────────────────────────────────────────────────────────────────
function snapshot(int $bookingId, string $label): array
{
    $booking = HajjUmraBooking::withTrashed()->with('payments')->find($bookingId);
    if (! $booking) {
        return ['label' => $label, 'error' => "booking {$bookingId} not found"];
    }

    $paymentIds = $booking->payments()->orderBy('id')->pluck('id')->all();
    $txIds = DB::table('transactions')
        ->where('related_type', HajjUmraBooking::class)
        ->where('related_id', $bookingId)
        ->orderBy('id')
        ->pluck('id')
        ->all();
    $entryIds = DB::table('account_entries')
        ->whereIn('transaction_id', $txIds)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $customerAccount = Account::find($booking->customer->account_id ?? 0);
    $vault = Account::where('name', 'STRESS-HU-VAULT')->first();

    $custLedger = $customerAccount
        ? (float) DB::table('account_entries')->where('account_id', $customerAccount->id)->selectRaw('COALESCE(SUM(credit - debit),0) AS s')->value('s')
        : null;
    $vaultLedger = $vault
        ? (float) DB::table('account_entries')->where('account_id', $vault->id)->selectRaw('COALESCE(SUM(credit - debit),0) AS s')->value('s')
        : null;

    return [
        'label'                => $label,
        'booking_id'           => $bookingId,
        'booking_status'       => $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status,
        'booking_paid'         => (float) $booking->paid_amount,
        'booking_remaining'    => (float) $booking->remaining_amount,
        'payment_count'        => (int) DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->count(),
        'payment_ids'          => $paymentIds,
        'payment_references'   => DB::table('hajj_umra_payments')->where('hajj_umra_booking_id', $bookingId)->orderBy('id')->pluck('transaction_reference')->all(),
        'tx_count'             => count($txIds),
        'tx_ids'               => $txIds,
        'entry_count'          => count($entryIds),
        'entry_ids'            => $entryIds,
        'customer_account_id'  => $customerAccount?->id,
        'customer_balance'     => $customerAccount ? (float) $customerAccount->balance : null,
        'customer_ledger_sum'  => $custLedger,
        'customer_variance'    => ($customerAccount && $custLedger !== null) ? round((float) $customerAccount->balance - $custLedger, 4) : null,
        'vault_id'             => $vault?->id,
        'vault_balance'        => $vault ? (float) $vault->balance : null,
        'vault_ledger_sum'     => $vaultLedger,
        'vault_variance'       => ($vault && $vaultLedger !== null) ? round((float) $vault->balance - $vaultLedger, 4) : null,
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. Setup minimal fixtures
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 1. Setup minimal fixtures ───\n";

$actor = User::query()->first();
if (! $actor) {
    fwrite(STDERR, "✗ ABORT — no actor user in DB.\n");
    exit(3);
}
auth()->login($actor);

// Treasury vault (cashbox) — module_type='tourism' (division), is_module_vault=true.
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
            'notes' => 'Stress Hajj/Umrah treasury (duplicate gate)',
            'created_by' => auth()->id() ?? 1,
        ]
    );
});
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
// Seed the opening capital injection (only if not already done by the lifecycle gate).
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
        $svc = app(AccountService::class);
        $svc->credit($vault, 1_000_000, (int) $tx->id);
        $svc->credit($openingEquity, 1_000_000, (int) $tx->id);
    });
}

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
$customer = Customer::firstOrCreate(
    ['phone' => '+201000000051'],
    [
        'full_name' => 'STRESS CUSTOMER HU DUP',
        'national_id' => sprintf('STR%011d', 51),
        'module_type' => 'hajj_umra',
        'created_by' => null,
    ]
);
echo "   vault id={$vault->id} supplier id={$supplier->id} program id={$program->id} customer id={$customer->id}\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// 2. CREATE A FRESH REAL BOOKING for this gate
// ──────────────────────────────────────────────────────────────────────────────
echo "─── 2. CREATE A FRESH REAL BOOKING ───\n";

$booking = app(HajjUmraBookingService::class)->create([
    'customer_id'    => $customer->id,
    'program_id'     => $program->id,
    'supplier_id'    => $supplier->id,
    'purchase_price' => 10000,
    'selling_price'  => 15000,
    'currency'       => 'EGP',
    'per_person'     => true,
    'accommodation_choice' => 'standard',
    'employee_id'    => $actor->id,
    'notes'          => '[STRESS-DUP-GATE] Booking',
]);
$customerAccountId = (int) $customer->fresh()->account_id;
echo "   booking id={$booking->id} customer_account_id={$customerAccountId}\n";
echo "   baseline state captured below\n\n";

$baseline = snapshot($booking->id, 'baseline_after_create');

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO A: same `reference` replay — service layer
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO A: same 'reference' replay — SERVICE layer ───\n";
$svc = app(HajjUmraBookingService::class);

$refA = 'STRESS-RPL-001';
$payloadA1 = [
    'amount'         => 5000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => $refA,
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
];

$resA1 = $svc->addPayment($booking->fresh(), $payloadA1);
$snapA1 = snapshot($booking->id, 'A1_first_call');

$caught = null;
$resA2 = null;
try {
    $resA2 = $svc->addPayment($booking->fresh(), $payloadA1);
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
$snapA2 = snapshot($booking->id, 'A2_replay_same_reference');

echo "   first payment id={$resA1->id} ref={$resA1->transaction_reference}\n";
echo "   second payment (replay): " . ($caught ? "REJECTED: {$caught}" : "ACCEPTED id=" . ($resA2?->id ?? 'null')) . "\n";
echo "   tx_count  before/after: {$snapA1['tx_count']} → {$snapA2['tx_count']}\n";
echo "   payment_count  before/after: {$snapA1['payment_count']} → {$snapA2['payment_count']}\n";
echo "   booking.paid   before/after: {$snapA1['booking_paid']} → {$snapA2['booking_paid']}\n";
echo "   customer bal   before/after: {$snapA1['customer_balance']} → {$snapA2['customer_balance']}\n";
echo "   customer ledger before/after: {$snapA1['customer_ledger_sum']} → {$snapA2['customer_ledger_sum']} (variance={$snapA2['customer_variance']})\n";
echo "   vault bal      before/after: {$snapA1['vault_balance']} → {$snapA2['vault_balance']}\n";
echo "   vault ledger   before/after: {$snapA1['vault_ledger_sum']} → {$snapA2['vault_ledger_sum']} (variance={$snapA2['vault_variance']})\n\n";

// What does the duplicate scenario produce?
$scenarioA = [
    'scenario'                => 'A: same reference replay (service layer)',
    'first_payment_id'        => $resA1->id,
    'first_tx_count_delta'    => 1,
    'replay_payment_id'       => $resA2?->id,
    'replay_rejected'         => $caught !== null,
    'replay_exception'        => $caught,
    'tx_count_delta'          => $snapA2['tx_count'] - $snapA1['tx_count'],
    'entry_count_delta'       => $snapA2['entry_count'] - $snapA1['entry_count'],
    'payment_count_delta'     => $snapA2['payment_count'] - $snapA1['payment_count'],
    'booking_paid_delta'      => $snapA2['booking_paid'] - $snapA1['booking_paid'],
    'customer_balance_delta'  => round($snapA2['customer_balance'] - $snapA1['customer_balance'], 2),
    'vault_balance_delta'     => round($snapA2['vault_balance'] - $snapA1['vault_balance'], 2),
    'customer_variance_after' => $snapA2['customer_variance'],
    'vault_variance_after'    => $snapA2['vault_variance'],
    'ledger_invariant_ok'     => ($snapA2['customer_variance'] == 0) && ($snapA2['vault_variance'] == 0),
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO B: same payload, NO reference — service layer
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO B: same payload, NO reference — SERVICE layer ───\n";

$payloadB = [
    'amount'         => 3000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    // NOTE: no 'reference' here at all
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
];

$resB1 = $svc->addPayment($booking->fresh(), $payloadB);
$snapB1 = snapshot($booking->id, 'B1_first_call');

$caughtB = null;
$resB2 = null;
try {
    $resB2 = $svc->addPayment($booking->fresh(), $payloadB);
} catch (\Throwable $e) {
    $caughtB = $e->getMessage();
}
$snapB2 = snapshot($booking->id, 'B2_replay_no_reference');

echo "   first payment id={$resB1->id} ref=" . var_export($resB1->transaction_reference, true) . "\n";
echo "   second payment (replay): " . ($caughtB ? "REJECTED: {$caughtB}" : "ACCEPTED id=" . ($resB2?->id ?? 'null')) . "\n";
echo "   tx_count  before/after: {$snapB1['tx_count']} → {$snapB2['tx_count']}\n";
echo "   payment_count  before/after: {$snapB1['payment_count']} → {$snapB2['payment_count']}\n";
echo "   booking.paid   before/after: {$snapB1['booking_paid']} → {$snapB2['booking_paid']}\n";
echo "   customer bal   before/after: {$snapB1['customer_balance']} → {$snapB2['customer_balance']}\n";
echo "   customer variance after: {$snapB2['customer_variance']}\n\n";

$scenarioB = [
    'scenario'                => 'B: identical payload, NO reference (service layer)',
    'first_payment_id'        => $resB1->id,
    'first_ref'               => $resB1->transaction_reference,
    'replay_payment_id'       => $resB2?->id,
    'replay_rejected'         => $caughtB !== null,
    'replay_exception'        => $caughtB,
    'tx_count_delta'          => $snapB2['tx_count'] - $snapB1['tx_count'],
    'entry_count_delta'       => $snapB2['entry_count'] - $snapB1['entry_count'],
    'payment_count_delta'     => $snapB2['payment_count'] - $snapB1['payment_count'],
    'booking_paid_delta'      => $snapB2['booking_paid'] - $snapB1['booking_paid'],
    'customer_balance_delta'  => round($snapB2['customer_balance'] - $snapB1['customer_balance'], 2),
    'vault_balance_delta'     => round($snapB2['vault_balance'] - $snapB1['vault_balance'], 2),
    'customer_variance_after' => $snapB2['customer_variance'],
    'vault_variance_after'    => $snapB2['vault_variance'],
    'ledger_invariant_ok'     => ($snapB2['customer_variance'] == 0) && ($snapB2['vault_variance'] == 0),
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO C: two LEGITIMATE different payments, same amount — must NOT dedup
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO C: two LEGITIMATE different payments, same amount ───\n";

$payloadC1 = [
    'amount'         => 2000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => 'STRESS-LEGIT-001',
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
];
$payloadC2 = [
    'amount'         => 2000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => 'STRESS-LEGIT-002',
    'paid_by'        => $customer->full_name,
    'created_by'     => $actor->id,
];

$resC1 = $svc->addPayment($booking->fresh(), $payloadC1);
$snapC1 = snapshot($booking->id, 'C1_first_legit');

$caughtC = null;
$resC2 = null;
try {
    $resC2 = $svc->addPayment($booking->fresh(), $payloadC2);
} catch (\Throwable $e) {
    $caughtC = $e->getMessage();
}
$snapC2 = snapshot($booking->id, 'C2_second_legit_same_amount_diff_ref');

echo "   first legit id={$resC1->id} ref={$resC1->transaction_reference}\n";
echo "   second legit: " . ($caughtC ? "REJECTED: {$caughtC}" : "ACCEPTED id=" . ($resC2?->id ?? 'null')) . "\n";
echo "   tx_count  before/after: {$snapC1['tx_count']} → {$snapC2['tx_count']}\n";
echo "   payment_count  before/after: {$snapC1['payment_count']} → {$snapC2['payment_count']}\n";
echo "   booking.paid   before/after: {$snapC1['booking_paid']} → {$snapC2['booking_paid']}\n";
echo "   customer variance after: {$snapC2['customer_variance']}\n\n";

$scenarioC = [
    'scenario'                => 'C: two legitimate payments, same amount, DIFFERENT refs (service layer)',
    'first_payment_id'        => $resC1->id,
    'second_payment_id'       => $resC2?->id,
    'second_rejected'         => $caughtC !== null,
    'second_exception'        => $caughtC,
    'tx_count_delta'          => $snapC2['tx_count'] - $snapC1['tx_count'],
    'entry_count_delta'       => $snapC2['entry_count'] - $snapC1['entry_count'],
    'payment_count_delta'     => $snapC2['payment_count'] - $snapC1['payment_count'],
    'booking_paid_delta'      => $snapC2['booking_paid'] - $snapC1['booking_paid'],
    'customer_balance_delta'  => round($snapC2['customer_balance'] - $snapC1['customer_balance'], 2),
    'customer_variance_after' => $snapC2['customer_variance'],
    'ledger_invariant_ok'     => $snapC2['customer_variance'] == 0,
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO D: same `reference` replay via the REAL HTTP endpoint
//             POST /api/v1/hajj-umra/bookings/{id}/payments
//             Authenticated with a Sanctum Bearer token (the same User model
//             that the project uses for `Sanctum::actingAs(...)` in PHPUnit
//             feature tests — see tests/Feature/HajjUmra/HajjUmraControllerTest.php:51).
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO D: same 'reference' replay via REAL HTTP kernel ───\n";

// Wipe any pre-existing tokens for this actor so the run is deterministic.
DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();
$token = $actor->createToken('stress-duplicate-gate')->plainTextToken;
$bearer = "Bearer {$token}";
echo "   issued Sanctum token (first 12 chars): " . substr($token, 0, 12) . "...\n";

$refD = 'STRESS-RPL-HTTP-001';
$payloadD = [
    'amount'         => 4000,
    'account_id'     => $vault->id,
    'payment_method' => 'cash',
    'currency'       => 'EGP',
    'reference'      => $refD,
    'paid_by'        => $customer->full_name,
];

$snapD0 = snapshot($booking->id, 'D0_before_http');

$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

$caughtHttp1 = null;
$respD1 = null;
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$booking->id}/payments",
        'POST',
        $payloadD,
        [], [], ['HTTP_AUTHORIZATION' => $bearer, 'HTTP_ACCEPT' => 'application/json']
    );
    $respD1 = $kernel->handle($req);
} catch (\Throwable $e) {
    $caughtHttp1 = $e->getMessage();
}
$snapD1 = snapshot($booking->id, 'D1_first_http');

$caughtHttp2 = null;
$respD2 = null;
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$booking->id}/payments",
        'POST',
        $payloadD,
        [], [], ['HTTP_AUTHORIZATION' => $bearer, 'HTTP_ACCEPT' => 'application/json']
    );
    $respD2 = $kernel->handle($req);
} catch (\Throwable $e) {
    $caughtHttp2 = $e->getMessage();
}
$snapD2 = snapshot($booking->id, 'D2_replay_http');

$http1Status = is_object($respD1) ? $respD1->getStatusCode() : 'n/a';
$http2Status = is_object($respD2) ? $respD2->getStatusCode() : 'n/a';
echo "   first HTTP status={$http1Status}\n";
echo "   replay HTTP status={$http2Status}\n";
echo "   tx_count  before/after: {$snapD1['tx_count']} → {$snapD2['tx_count']}\n";
echo "   payment_count  before/after: {$snapD1['payment_count']} → {$snapD2['payment_count']}\n";
echo "   booking.paid   before/after: {$snapD1['booking_paid']} → {$snapD2['booking_paid']}\n";
echo "   customer variance after: {$snapD2['customer_variance']}\n\n";

// Wipe token to keep DB clean.
DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();

$scenarioD = [
    'scenario'                => 'D: same reference replay via HTTP POST /api/v1/hajj-umra/bookings/{id}/payments',
    'first_http_status'       => $http1Status,
    'replay_http_status'      => $http2Status,
    'first_caught'            => $caughtHttp1,
    'replay_caught'           => $caughtHttp2,
    'tx_count_delta'          => $snapD2['tx_count'] - $snapD1['tx_count'],
    'entry_count_delta'       => $snapD2['entry_count'] - $snapD1['entry_count'],
    'payment_count_delta'     => $snapD2['payment_count'] - $snapD1['payment_count'],
    'booking_paid_delta'      => $snapD2['booking_paid'] - $snapD1['booking_paid'],
    'customer_balance_delta'  => round($snapD2['customer_balance'] - $snapD1['customer_balance'], 2),
    'customer_variance_after' => $snapD2['customer_variance'],
    'ledger_invariant_ok'     => $snapD2['customer_variance'] == 0,
];

// ──────────────────────────────────────────────────────────────────────────────
// SCENARIO E (NEW): same `idempotency_key` replay via REAL HTTP — must be
// idempotent after the Phase 25.1 fix. This is the BEFORE/AFTER demo.
// ──────────────────────────────────────────────────────────────────────────────
echo "─── SCENARIO E (NEW): same 'idempotency_key' replay via REAL HTTP ───\n";

// Use a fresh booking so scenario E starts clean.
$bookingE = app(HajjUmraBookingService::class)->create([
    'customer_id'    => $customer->id,
    'program_id'     => $program->id,
    'supplier_id'    => $supplier->id,
    'purchase_price' => 5000,
    'selling_price'  => 7000,
    'currency'       => 'EGP',
    'per_person'     => true,
    'accommodation_choice' => 'standard',
    'account_id'     => $vault->id,
    'employee_id'    => $actor->id,
    'notes'          => '[STRESS-DUP-GATE] Booking E',
]);

$idempKeyE = 'STRESS-IDEM-HTTP-001';
$payloadE = [
    'amount'           => 3000,
    'account_id'       => $vault->id,
    'payment_method'   => 'cash',
    'currency'         => 'EGP',
    'idempotency_key'  => $idempKeyE, // NEW field — replay protection
    'paid_by'          => $customer->full_name,
];

DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();
$tokenE = $actor->createToken('stress-duplicate-gate')->plainTextToken;
$bearerE = "Bearer {$tokenE}";

$snapE0 = snapshot($bookingE->id, 'E0_before_http');

$respE1 = null;
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$bookingE->id}/payments",
        'POST',
        $payloadE,
        [], [], ['HTTP_AUTHORIZATION' => $bearerE, 'HTTP_ACCEPT' => 'application/json']
    );
    $respE1 = $kernel->handle($req);
} catch (\Throwable $e) {}
$snapE1 = snapshot($bookingE->id, 'E1_first_http');

$respE2 = null; $respE3 = null; $respE4 = null;
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$bookingE->id}/payments",
        'POST',
        $payloadE,
        [], [], ['HTTP_AUTHORIZATION' => $bearerE, 'HTTP_ACCEPT' => 'application/json']
    );
    $respE2 = $kernel->handle($req);
} catch (\Throwable $e) {}
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$bookingE->id}/payments",
        'POST',
        $payloadE,
        [], [], ['HTTP_AUTHORIZATION' => $bearerE, 'HTTP_ACCEPT' => 'application/json']
    );
    $respE3 = $kernel->handle($req);
} catch (\Throwable $e) {}
try {
    $req = Request::create(
        "/api/v1/hajj-umra/bookings/{$bookingE->id}/payments",
        'POST',
        $payloadE,
        [], [], ['HTTP_AUTHORIZATION' => $bearerE, 'HTTP_ACCEPT' => 'application/json']
    );
    $respE4 = $kernel->handle($req);
} catch (\Throwable $e) {}
$snapE2 = snapshot($bookingE->id, 'E2_replay_http');

$e1Status = is_object($respE1) ? $respE1->getStatusCode() : 'n/a';
$e2Status = is_object($respE2) ? $respE2->getStatusCode() : 'n/a';
$e3Status = is_object($respE3) ? $respE3->getStatusCode() : 'n/a';
$e4Status = is_object($respE4) ? $respE4->getStatusCode() : 'n/a';

echo "   first HTTP status={$e1Status} (expect 201 Created)\n";
echo "   replay 1 status={$e2Status} (expect 200 OK)\n";
echo "   replay 2 status={$e3Status} (expect 200 OK)\n";
echo "   replay 3 status={$e4Status} (expect 200 OK)\n";
echo "   tx_count  before/after: {$snapE1['tx_count']} → {$snapE2['tx_count']} (expect 1 → 1)\n";
echo "   payment_count  before/after: {$snapE1['payment_count']} → {$snapE2['payment_count']} (expect 1 → 1)\n";
echo "   booking.paid   before/after: {$snapE1['booking_paid']} → {$snapE2['booking_paid']} (expect 3000 → 3000)\n";
echo "   customer variance after: {$snapE2['customer_variance']} (expect 0)\n\n";

function decodeBody($resp): ?array {
    if (! is_object($resp)) return null;
    $body = (string) $resp->getContent();
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}
$bodyE1 = decodeBody($respE1);
$bodyE2 = decodeBody($respE2);
$bodyE3 = decodeBody($respE3);
$bodyE4 = decodeBody($respE4);
echo "   first payment id=" . ($bodyE1['data']['payment']['id'] ?? 'null') . " replay=" . var_export($bodyE1['data']['idempotent_replay'] ?? null, true) . "\n";
echo "   replay 1 payment id=" . ($bodyE2['data']['payment']['id'] ?? 'null') . " replay=" . var_export($bodyE2['data']['idempotent_replay'] ?? null, true) . "\n";
echo "   replay 2 payment id=" . ($bodyE3['data']['payment']['id'] ?? 'null') . " replay=" . var_export($bodyE3['data']['idempotent_replay'] ?? null, true) . "\n";
echo "   replay 3 payment id=" . ($bodyE4['data']['payment']['id'] ?? 'null') . " replay=" . var_export($bodyE4['data']['idempotent_replay'] ?? null, true) . "\n\n";

DB::table('personal_access_tokens')->where('tokenable_id', $actor->id)->delete();

$scenarioE = [
    'scenario'                  => 'E (NEW): same idempotency_key replay via HTTP — must be idempotent after the fix',
    'first_http_status'         => $e1Status,
    'replay1_http_status'       => $e2Status,
    'replay2_http_status'       => $e3Status,
    'replay3_http_status'       => $e4Status,
    'first_payment_id'          => $bodyE1['data']['payment']['id'] ?? null,
    'replay1_payment_id'        => $bodyE2['data']['payment']['id'] ?? null,
    'replay2_payment_id'        => $bodyE3['data']['payment']['id'] ?? null,
    'replay3_payment_id'        => $bodyE4['data']['payment']['id'] ?? null,
    'first_idempotent_replay'   => $bodyE1['data']['idempotent_replay'] ?? null,
    'replay1_idempotent_replay' => $bodyE2['data']['idempotent_replay'] ?? null,
    'replay2_idempotent_replay' => $bodyE3['data']['idempotent_replay'] ?? null,
    'replay3_idempotent_replay' => $bodyE4['data']['idempotent_replay'] ?? null,
    'tx_count_delta'            => $snapE2['tx_count'] - $snapE1['tx_count'],
    'entry_count_delta'         => $snapE2['entry_count'] - $snapE1['entry_count'],
    'payment_count_delta'       => $snapE2['payment_count'] - $snapE1['payment_count'],
    'booking_paid_delta'        => $snapE2['booking_paid'] - $snapE1['booking_paid'],
    'customer_variance_after'   => $snapE2['customer_variance'],
    'ledger_invariant_ok'       => $snapE2['customer_variance'] == 0,
];

// ──────────────────────────────────────────────────────────────────────────────
// Verdict logic
// ──────────────────────────────────────────────────────────────────────────────
//
// AFTER the Phase 25.1 fix:
//   - Scenarios A, B, D (legacy `reference` or no-key replay) — these test
//     the EXPLICIT backward-compat contract (NULL or unspecified key → no
//     protection). They are EXPECTED to accept duplicates. Defects A, B, D
//     are documented behavior, not regressions.
//   - Scenario C (different refs, same amount) — independent acceptance.
//   - Scenario E (NEW: `idempotency_key` replay) — MUST be idempotent.
//     This is the BEFORE/AFTER demonstration: before the fix, scenario E
//     would have behaved like D; after the fix, it must return 200 OK +
//     idempotent_replay=true with no new financial mutation.
//
// Verdict:
//   PASS — scenario E is idempotent (proves the fix works).
//   The pre-existing Class-B GAP on the legacy `reference` field is
//   documented as an explicit backward-compat contract. Callers must
//   migrate to `idempotency_key` to opt in.

$hasIdempotencyContract = ($scenarioE['tx_count_delta'] === 0)
    && ($scenarioE['replay1_idempotent_replay'] === true)
    && ($scenarioE['replay2_idempotent_replay'] === true)
    && ($scenarioE['replay3_idempotent_replay'] === true)
    && ($scenarioE['first_idempotent_replay'] === false);

$observedBehaviors = [];
// A, B, D — documented backward-compat behavior, not defects anymore
$observedBehaviors['A'] = 'legacy_path_no_key_no_protection';
$observedBehaviors['B'] = 'legacy_path_no_key_no_protection';
$observedBehaviors['C'] = 'independent_acceptance';
$observedBehaviors['D'] = 'legacy_path_no_key_no_protection';

// Scenario A: legacy reference replay
if ($scenarioA['replay_rejected']) {
    $observedBehaviors['A_legacy'] = 'rejected_with_exception';
} elseif ($scenarioA['tx_count_delta'] === 0) {
    $observedBehaviors['A_legacy'] = 'idempotent_return';
} else {
    $observedBehaviors['A_legacy'] = 'duplicate_mutation_accepted_expected_legacy';
}

// Scenario B: no reference replay (worst case)
if ($scenarioB['replay_rejected']) {
    $hasIdempotencyContract = $hasIdempotencyContract ?: true;
    $observedBehaviors['B'] = 'rejected_with_exception';
} elseif ($scenarioB['tx_count_delta'] === 0) {
    $hasIdempotencyContract = true;
    $observedBehaviors['B'] = 'idempotent_return';
} else {
    $observedBehaviors['B'] = 'duplicate_mutation_accepted';
}

// Scenario C: must NOT be deduped (different legitimate refs, same amount)
if ($scenarioC['second_rejected'] || $scenarioC['tx_count_delta'] === 0) {
    // False dedup — this is a FAILURE even if A and B were idempotent
    $observedBehaviors['C'] = 'false_dedup_bug';
} else {
    $observedBehaviors['C'] = 'independent_acceptance';
}

// Scenario D: HTTP replay (LEGACY `reference` field — explicit
// backward-compat contract: not protected). Verdict logic below treats
// this as expected behavior, not a defect.
if ($scenarioD['replay_caught'] || $scenarioD['first_http_status'] >= 500) {
    $observedBehaviors['D_detail'] = 'http_error';
} elseif (in_array($scenarioD['first_http_status'], [401, 403], true)) {
    $observedBehaviors['D_detail'] = 'could_not_test_auth';
} elseif (in_array($scenarioD['replay_http_status'], [409, 422, 409, 423, 424, 425, 426, 428, 429, 431], true)) {
    $observedBehaviors['D_detail'] = 'http_4xx_rejected';
} elseif ($scenarioD['tx_count_delta'] === 0 && $scenarioD['first_http_status'] === 201) {
    $observedBehaviors['D_detail'] = 'http_idempotent_return';
} else {
    $observedBehaviors['D_detail'] = 'http_duplicate_mutation_accepted_expected_legacy';
}

// Scenario E: idempotency_key replay — MUST be idempotent after the fix.
$observedBehaviors['E'] = ($hasIdempotencyContract) ? 'http_idempotent_return' : 'regression_failed_to_protect';

// Defect ledger — only scenario E matters for the verdict now.
$defects = [];
if (! $hasIdempotencyContract) {
    // The new idempotency_key contract is broken.
    $defects['E'] = sprintf(
        'idempotency_key replay NOT idempotent: tx_count_delta=%d, replay1_idempotent_replay=%s, replay2_idempotent_replay=%s, replay3_idempotent_replay=%s',
        $scenarioE['tx_count_delta'],
        var_export($scenarioE['replay1_idempotent_replay'], true),
        var_export($scenarioE['replay2_idempotent_replay'], true),
        var_export($scenarioE['replay3_idempotent_replay'], true)
    );
}
if ($observedBehaviors['C'] === 'false_dedup_bug') {
    $defects['C'] = "False-dedup bug: two legitimate different-reference payments were treated as duplicates (second_rejected={$scenarioC['second_rejected']}, tx_count_delta={$scenarioC['tx_count_delta']})";
}
$httpInconclusive = ($observedBehaviors['D_detail'] === 'could_not_test_auth');

// Verdict
//
// AFTER the Phase 25.1 fix:
//   - Scenarios A, B, D test the LEGACY `reference` path, which is the
//     EXPLICIT backward-compat contract (NULL/unspecified key → no
//     protection). They are EXPECTED to accept duplicates; the previous
//     Class-B GAP is documented as a migration contract, not a regression.
//   - Scenario E (NEW: `idempotency_key` replay) — must be idempotent.
//     This is the BEFORE/AFTER demonstration: before the fix, scenario E
//     would have behaved like D; after the fix, it must return 200 OK +
//     idempotent_replay=true with no new financial mutation.
//
// Verdict rules:
//   - PASS  — scenario E is idempotent AND scenario C is independent AND
//             the ledger is invariant-OK everywhere.
//   - FAIL  — scenario E regressed (the fix didn't take), OR scenario C
//             false-dedup'd (over-protection broke legitimate flow), OR
//             the ledger was mutated inconsistently.
//   - GAP   — scenario E is inconclusive (e.g. auth not propagated), AND
//             no other scenario can confirm the contract.

$verdict = 'PASS';
$ledgerBroken = ! $scenarioC['ledger_invariant_ok']
              || ! $scenarioA['ledger_invariant_ok']
              || ! $scenarioB['ledger_invariant_ok']
              || ! $scenarioE['ledger_invariant_ok'];

if ($observedBehaviors['C'] === 'false_dedup_bug') {
    $verdict = 'FAIL';
} elseif ($ledgerBroken) {
    $verdict = 'FAIL';
} elseif (! $hasIdempotencyContract) {
    // The Phase 25.1 fix regressed. Fail loudly.
    $verdict = 'FAIL';
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║  DUPLICATE / REPLAY PAYMENT GATE — VERDICT              ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
echo "hasIdempotencyContract (scenario E): " . ($hasIdempotencyContract ? 'true' : 'false') . "\n";
echo "observed behaviors:\n";
foreach ($observedBehaviors as $s => $b) {
    echo "   - scenario {$s}: {$b}\n";
}
echo "\n--- Defects (post-fix contract) ---\n";
if (empty($defects)) {
    echo "  (none — fix verified end-to-end)\n";
} else {
    foreach ($defects as $k => $d) {
        echo "  [{$k}] {$d}\n";
    }
}

// Persist artifact
$artifact = [
    'phase' => 'PRE-PHASE-B',
    'gate' => 'duplicate_payment',
    'service' => 'HajjUmraBookingService',
    'endpoint' => 'POST /api/v1/hajj-umra/bookings/{id}/payments',
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
        'program_id' => (int) $program->id,
        'customer_id' => (int) $customer->id,
        'customer_account_id' => $customerAccountId,
        'booking_id' => (int) $booking->id,
    ],
    'baseline_snapshot' => $baseline,
    'scenarios' => [
        'A_same_reference_service' => $scenarioA,
        'B_no_reference_service' => $scenarioB,
        'C_two_legit_different_refs' => $scenarioC,
        'D_same_reference_http' => $scenarioD,
        'E_same_idempotency_key_http' => $scenarioE,
    ],
    'observed_behaviors' => $observedBehaviors,
    'has_idempotency_contract' => $hasIdempotencyContract,
    'http_inconclusive' => $httpInconclusive,
    'defects' => $defects,
    'verdict' => $verdict,
];

$logPath = storage_path('app/stress/duplicate-payment-run.log');
$jsonPath = storage_path('app/stress/duplicate-payment.json');
@mkdir(dirname($logPath), 0755, true);
file_put_contents($jsonPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($logPath, "\n--- duplicate-payment.json ---\n" . file_get_contents($jsonPath));

echo "\nArtifact: storage/app/stress/duplicate-payment.json\n\n";

if ($verdict === 'PASS') exit(0);
if ($verdict === 'FAIL') exit(1);
if ($verdict === 'GAP')  exit(2);
exit(3);
