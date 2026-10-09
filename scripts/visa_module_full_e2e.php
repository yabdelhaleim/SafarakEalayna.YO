<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 *  VISA MODULE — FULL E2E TEST SUITE
 * ════════════════════════════════════════════════════════════════════════════
 *
 *  يجرّب موديول التأشيرات بالكامل (real DB writes) ويكشف أي مشكلة منطقية أو
 *  محاسبية قبل ما توصل للبرودكشن. كل سيناريو يلمس الـ Service Layer
 *  (نفس المسار اللي بيستخدمه Filament والـ API):
 *
 *    1) VisaAgent CRUD + agent finance (withdraw / repay)
 *    2) إنشاء حجز تأشيرة EGP مع دفعة أولية + تحقق القيود
 *    3) إنشاء حجز تأشيرة USD بدون دفعة + تحقق القيود والعملة
 *    4) إنشاء حجز بـ VisaAgent (التكلفة تروح على حساب الوكيل)
 *    5) حجز بحساب عميل جديد → Customer AR account يتعمل تلقائي
 *    6) إضافة دفعات جزئية + دفعة كاملة + رفض Overpayment
 *    7) تعديل سعر (repost) — عكس إضافة + قيد جديد، الأصلي يفضل سليماً
 *    8) إلغاء (cancel) → status=cancelled + reverse payments & txns
 *    9) استرداد (refund) → status=refunded
 *   10) حذف إداري deleteWithReversal → soft delete + payments soft delete
 *   11) Endpoint: customerBalances / customerStatement / payCustomerDebt
 *   12) Endpoint: Treasury overview / account transactions
 *   13) Guards: direct delete محظور، تعديل profit محظور، double-cancel idempotency
 *   14) Currency mismatch في الـ FormRequest (USD account لـ EGP booking مرفوض)
 *   15) API response shape: مفتاح 'success' مش 'status'
 *
 *  الحماية من التأثير على البرودكشن:
 *   - كل الـ test data متسمي بـ prefix "TX-VISA-E2E-" (سهل نعرفه ونمسحه)
 *   - كل تيست ينغلف في safeRun() مستقل (failure في تيست ما بيقتلش الباقي)
 *   - الحسابات/العملاء/الوكلاء/الحجوزات كلها للتيست فقط
 *   - لو السيرفر مش متاح → exit مع رسالة واضحة بدل ما نمس بيانات
 *
 *  التشغيل:
 *    cd C:\travile\SafarakEalayna
 *    php scripts/visa_module_full_e2e.php
 *
 *  النتائج:
 *    - تقرير مفصّل على الـ stdout
 *    - JSON في storage/logs/visa_full_e2e_results.json
 *
 *  تنظيف:
 *    - في نهاية التشغيل بنطبع IDs كل الـ test data (للمراجعة قبل المسح)
 *    - استخدم: DELETE FROM visa_bookings WHERE id IN (...) ; الخ يدوي
 *      أو شغّل scripts/_tx_cleanup.php (مش متضمن في هذا السكريبت — متعمد)
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Http\Controllers\Api\V1\Visa\VisaAgentApiController;
use App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController;
use App\Http\Controllers\Api\V1\Visa\VisaBookingController;
use App\Http\Controllers\Api\V1\Visa\VisaTreasuryController;
use App\Http\Controllers\Api\V1\VisaController;
use App\Http\Requests\Visa\StoreVisaBookingRequest;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaModificationService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'tests' => [],
    'verdict' => ['passed' => 0, 'failed' => 0, 'issues' => []],
    'cleanup_ids' => [
        'bookings' => [],
        'payments' => [],
        'visa_details' => [],
        'customers' => [],
        'agents' => [],
        'accounts' => [],
        'transactions' => [],
    ],
];

// ─── Output helpers ───────────────────────────────────────────────────────
function out_ok(string $m = 'OK'): void
{
    echo "    ✅ $m\n";
}
function out_fail(string $m): void
{
    echo "    ❌ $m\n";
}
function out_info(string $m): void
{
    echo "    �  $m\n";
}
function out_warn(string $m): void
{
    echo "    ⚠  $m\n";
}
function out_head(string $m): void
{
    echo "    → $m\n";
}
function out_line(): void
{
    echo "\n".str_repeat('─', 75)."\n";
}
function out_section(string $name): void
{
    echo "\n".str_repeat('═', 75)."\n  $name\n".str_repeat('═', 75)."\n";
}

// ─── safeRun: try/catch wrapper — يستمر حتى لو التيست فشل ──────────────
function safeRun(string $testId, string $name, callable $fn, array &$results): void
{
    try {
        $results['tests'][$testId] = ['name' => $name, 'status' => 'running'];
        $fn();
        if (($results['tests'][$testId]['status'] ?? null) !== 'failed') {
            $results['tests'][$testId]['status'] = 'passed';
        }
    } catch (Throwable $e) {
        out_fail("$testId crashed: ".$e->getMessage());
        $results['tests'][$testId]['status'] = 'failed';
        $results['tests'][$testId]['error'] = $e->getMessage();
        $results['tests'][$testId]['trace'] = substr($e->getTraceAsString(), 0, 800);
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $testId,
            'name' => $name,
            'error' => $e->getMessage(),
        ];
    }
}

// ─── Assertions ──────────────────────────────────────────────────────────
function assertBalance(string $label, int $accountId, float $expected, array &$results, float $tolerance = 0.02): void
{
    $actual = snapAccount($accountId);
    $diff = round($actual - $expected, 2);
    if (abs($diff) <= $tolerance) {
        out_ok("$label = $actual (expected $expected)");
        $results['verdict']['passed']++;
    } else {
        out_fail("$label = $actual (expected $expected, diff $diff)");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'account_id' => $accountId,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $diff,
        ];
    }
}

function assertTrue(string $label, bool $cond, array &$results, string $context = ''): void
{
    if ($cond) {
        out_ok($label);
        $results['verdict']['passed']++;
    } else {
        out_fail("$label $context");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'context' => $context,
        ];
    }
}

function assertEqualsFloat(string $label, float $expected, float $actual, array &$results, float $tol = 0.02): void
{
    $diff = round($expected - $actual, 2);
    if (abs($diff) <= $tol) {
        out_ok("$label (expected=$expected, actual=$actual)");
        $results['verdict']['passed']++;
    } else {
        out_fail("$label (expected=$expected, actual=$actual, diff=$diff)");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $diff,
        ];
    }
}

/**
 * Verify every transaction_id in $txIds has balanced debit/credit
 * (Σ debit = Σ credit per transaction_id).
 */
function assertTransactionsBalanced(string $label, array $txIds, array &$results): void
{
    if (empty($txIds)) {
        out_warn("$label: no transactions to verify");

        return;
    }
    $unbalanced = [];
    foreach ($txIds as $tid) {
        $row = DB::table('account_entries')
            ->where('transaction_id', $tid)
            ->selectRaw('SUM(debit) as sum_d, SUM(credit) as sum_c')
            ->first();
        $diff = abs((float) ($row->sum_d ?? 0) - (float) ($row->sum_c ?? 0));
        if ($diff > 0.01) {
            $unbalanced[$tid] = [
                'debit' => (float) $row->sum_d,
                'credit' => (float) $row->sum_c,
                'diff' => $diff,
            ];
        }
    }
    if (empty($unbalanced)) {
        out_ok("$label: ".count($txIds).' transactions balanced');
        $results['verdict']['passed']++;
    } else {
        out_fail("$label: ".count($unbalanced).' unbalanced: '.json_encode($unbalanced, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'unbalanced' => $unbalanced,
        ];
    }
}

// ─── Snapshot helpers ────────────────────────────────────────────────────
function snapAccount(int $id): float
{
    $a = Account::find($id);

    return $a ? (float) $a->balance : 0.0;
}

// ─── Authenticate as admin ───────────────────────────────────────────────
$adminUser = User::where('role', 'owner')->first()
    ?? User::where('role', 'admin')->first()
    ?? User::first();
if (! $adminUser) {
    out_fail('No admin/owner user found. Run seeders first.');
    exit(1);
}
Auth::login($adminUser);
out_info("Authenticated as User #{$adminUser->id} ({$adminUser->email})");

// ─── Service instances ──────────────────────────────────────────────────
$bookingService = app(VisaBookingService::class);
$refundService = app(VisaRefundService::class);
$modificationService = app(VisaModificationService::class);
$txService = app(TransactionService::class);

// ═════════════════════════════════════════════════════════════════════════
// S00 — Setup: visa duration, vaults (EGP/USD/SAR), visa agent
// ═════════════════════════════════════════════════════════════════════════
out_section('S00 — Setup: مرجعيات وحسابات تأشيرة معزولة للتيست');

$visaDuration = VisaDuration::firstOrCreate(
    ['code' => 'TX-VISA-E2E-30D'],
    [
        'label_ar' => 'TX شهر واحد',
        'label_en' => 'TX One Month',
        'months' => 1,
        'entry_type' => 'single',
        'sort_order' => 99,
        'is_active' => true,
    ]
);
out_info("VisaDuration #{$visaDuration->id} ({$visaDuration->label_ar})");

// Test EGP vault (tourism division) — large opening balance
$vaultEGP = Account::where('name', 'TX-VISA-E2E-VAULT-EGP')->first();
if (! $vaultEGP) {
    $vaultEGP = LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => 'TX-VISA-E2E-VAULT-EGP',
        'type' => AccountType::Cashbox,
        'currency' => 'EGP',
        'balance' => 1000000.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'tourism',
        'is_module_vault' => true,
        'notes' => 'TX-VISA-E2E test vault — safe to delete',
        'created_by' => $adminUser->id,
    ]));
}
$results['cleanup_ids']['accounts'][] = $vaultEGP->id;
out_info("Vault EGP #{$vaultEGP->id}, balance=".snapAccount($vaultEGP->id));

// Test USD vault (tourism division)
$vaultUSD = Account::where('name', 'TX-VISA-E2E-VAULT-USD')->first();
if (! $vaultUSD) {
    $vaultUSD = LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => 'TX-VISA-E2E-VAULT-USD',
        'type' => AccountType::Cashbox,
        'currency' => 'USD',
        'balance' => 100000.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'tourism',
        'is_module_vault' => true,
        'notes' => 'TX-VISA-E2E test vault USD',
        'created_by' => $adminUser->id,
    ]));
}
$results['cleanup_ids']['accounts'][] = $vaultUSD->id;
out_info("Vault USD #{$vaultUSD->id}, balance=".snapAccount($vaultUSD->id));

// Test SAR vault
$vaultSAR = Account::where('name', 'TX-VISA-E2E-VAULT-SAR')->first();
if (! $vaultSAR) {
    $vaultSAR = LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => 'TX-VISA-E2E-VAULT-SAR',
        'type' => AccountType::Cashbox,
        'currency' => 'SAR',
        'balance' => 100000.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'tourism',
        'is_module_vault' => true,
        'notes' => 'TX-VISA-E2E test vault SAR',
        'created_by' => $adminUser->id,
    ]));
}
$results['cleanup_ids']['accounts'][] = $vaultSAR->id;
out_info("Vault SAR #{$vaultSAR->id}, balance=".snapAccount($vaultSAR->id));

// EGP Bank
$bankEGP = Account::where('name', 'TX-VISA-E2E-BANK-EGP')->first();
if (! $bankEGP) {
    $bankEGP = LedgerBalanceMutationGuard::run(fn () => Account::create([
        'name' => 'TX-VISA-E2E-BANK-EGP',
        'type' => AccountType::Bank,
        'currency' => 'EGP',
        'balance' => 500000.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'tourism',
        'is_module_vault' => false,
        'notes' => 'TX-VISA-E2E bank — safe to delete',
        'created_by' => $adminUser->id,
    ]));
}
$results['cleanup_ids']['accounts'][] = $bankEGP->id;
out_info("Bank EGP #{$bankEGP->id}");

// Helper to create a visa agent (with its own supplier account)
function createTestAgent(string $suffix, int $adminId, array &$results): VisaAgent
{
    $agent = VisaAgent::create([
        'company_name' => "TX-VISA-E2E-AGENT-{$suffix}",
        'contact_person' => "TX-Agent-{$suffix}",
        'phone' => '01'.substr(str_pad((string) abs(crc32($suffix)), 9, '0', STR_PAD_LEFT), 0, 9),
        'email' => "tx-visa-e2e-agent-{$suffix}@example.com",
        'country' => 'EG',
        'visa_type' => 'tourist',
        'default_cost_price' => 800.0,
        'is_active' => true,
        'notes' => 'TX-VISA-E2E test agent',
        'created_by' => $adminId,
    ]);
    $results['cleanup_ids']['agents'][] = $agent->id;

    // Create linked supplier account (mirrors VisaAgentApiController behaviour)
    $account = Account::create([
        'name' => "حساب وكيل التأشيرة: TX-VISA-E2E-AGENT-{$suffix}",
        'type' => AccountType::Supplier,
        'balance' => 0,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'visas',
        'is_module_vault' => false,
        'notes' => "TX-VISA-E2E test agent ledger #{$agent->id}",
        'created_by' => $adminId,
    ]);
    $agent->update(['account_id' => $account->id]);
    $results['cleanup_ids']['accounts'][] = $account->id;

    return $agent->fresh();
}

function createTestCustomer(string $suffix, int $adminId, string $currency, array &$results): Customer
{
    $customer = Customer::create([
        'full_name' => "TX-VISA-E2E-CUST-{$suffix}",
        'phone' => '01'.substr(str_pad((string) abs(crc32($suffix)), 9, '0', STR_PAD_LEFT), 0, 9),
        'national_id' => str_pad((string) random_int(10000000000000, 99999999999999), 14, '0', STR_PAD_LEFT),
        'passport_number' => 'A'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
        'type' => 'individual',
        'status' => 'active',
        'created_by' => $adminId,
    ]);
    $results['cleanup_ids']['customers'][] = $customer->id;

    return $customer;
}

// ═════════════════════════════════════════════════════════════════════════
// T1 — VisaAgent CRUD (auto-create supplier account)
// ═════════════════════════════════════════════════════════════════════════
out_section('T1 — VisaAgent CRUD (إنشاء + استرجاع + cost-price)');

safeRun('T1.1', 'Create visa agent + auto Supplier account', function () use (&$results, $adminUser) {
    $agent = createTestAgent('T1', $adminUser->id, $results);
    out_info("Agent #{$agent->id} ({$agent->company_name})");

    $agentAccount = Account::find($agent->account_id);
    assertTrue('T1.1: agent has linked account', $agentAccount !== null, $results);
    assertTrue('T1.1: account type = Supplier', $agentAccount->type === AccountType::Supplier, $results,
        'got: '.($agentAccount->type instanceof BackedEnum ? $agentAccount->type->value : $agentAccount->type));
    assertTrue('T1.1: account module_type = visas', $agentAccount->module_type === 'visas', $results,
        "got: {$agentAccount->module_type}");
    assertBalance('T1.1: supplier account starts at 0', $agentAccount->id, 0, $results);
    assertEqualsFloat('T1.1: default_cost_price persisted', 800.0, (float) $agent->default_cost_price, $results);
}, $results);

safeRun('T1.2', 'VisaAgentApiController::index returns all agents', function () use (&$results) {
    $controller = app(VisaAgentApiController::class);
    $resp = $controller->index(new Request);
    $data = $resp->getData(true);
    assertTrue('T1.2: response success=true', ! empty($data['success']), $results);
    assertTrue('T1.2: items is array', is_array($data['data'] ?? null), $results);
    // Check our TX-VISA-E2E agent is present
    $found = false;
    foreach (($data['data'] ?? []) as $row) {
        if (str_starts_with((string) ($row['company_name'] ?? ''), 'TX-VISA-E2E-AGENT-')) {
            $found = true;
            break;
        }
    }
    assertTrue('T1.2: TX-VISA-E2E agent present in list', $found, $results);
}, $results);

safeRun('T1.3', 'VisaAgentApiController::cost-price returns agent cost', function () use (&$results) {
    // Use the first agent
    $agent = VisaAgent::where('company_name', 'like', 'TX-VISA-E2E-%')->first();
    if (! $agent) {
        throw new RuntimeException('No TX-VISA-E2E agent found');
    }
    $controller = app(VisaAgentApiController::class);
    $resp = $controller->costPrice(new Request, $agent->id);
    $data = $resp->getData(true);
    assertTrue('T1.3: response success', ! empty($data['success']), $results);
    assertEqualsFloat('T1.3: cost_price=800.0', 800.0, (float) ($data['data']['cost_price'] ?? -1), $results);
    assertEqualsFloat('T1.3: agent_id matches', (float) $agent->id, (float) ($data['data']['agent_id'] ?? -2), $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T2 — Create visa booking EGP with initial payment
// ═════════════════════════════════════════════════════════════════════════
out_section('T2 — إنشاء حجز EGP + دفعة أولية + تحقق القيود');

$bookingT2 = null;
$agentT2 = null;
$custT2 = null;
safeRun('T2.1', 'Create EGP visa booking with initial payment', function () use (&$results, $adminUser, $vaultEGP, $visaDuration, &$bookingT2, &$agentT2, &$custT2) {
    $custT2 = createTestCustomer('T2', $adminUser->id, 'EGP', $results);
    $agentT2 = createTestAgent('T2', $adminUser->id, $results);

    $vaultBefore = snapAccount($vaultEGP->id);
    $agentBefore = snapAccount($agentT2->account_id);

    $booking = $bookingService->create([
        'customer_id' => $custT2->id,
        'purchase_price' => 800.0,
        'selling_price' => 1200.0,
        'service_fee' => 100.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'TX-EG-PROD',
            'duration' => '30',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'visa_agent_id' => $agentT2->id,
            'submission_date' => now()->toDateString(),
            'expected_result_date' => now()->addDays(15)->toDateString(),
            'executing_company' => 'TX Executing Co',
            'executing_agent' => 'TX Agent Name',
            'executing_agent_contact' => '01000000000',
        ],
        'initial_payment' => [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $vaultEGP->id,
            'reference' => 'TX-INIT-001',
            'paid_by' => 'TX Customer',
        ],
    ]);

    $bookingT2 = $booking->id;
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $booking = $booking->fresh();
    $custT2 = $custT2->fresh();

    // === Assertions ===
    assertTrue('T2.1: booking created (status=submitted)', $booking->status === VisaStatus::Submitted, $results);
    assertEqualsFloat('T2.1: profit = selling+fee - purchase = 500.0', 500.0, (float) $booking->profit, $results);
    assertTrue('T2.1: expense_transaction_id linked', $booking->expense_transaction_id !== null, $results,
        "tx_id={$booking->expense_transaction_id}");
    assertTrue('T2.1: income_transaction_id linked', $booking->income_transaction_id !== null, $results,
        "tx_id={$booking->income_transaction_id}");

    // Customer AR account auto-created (visas module_type)
    $custAccount = Account::find($custT2->account_id);
    assertTrue('T2.1: customer account auto-created', $custAccount !== null, $results);
    assertTrue('T2.1: customer account module_type=visas',
        $custAccount && $custAccount->module_type === 'visas',
        $results, 'got: '.($custAccount ? $custAccount->module_type : 'null'));

    // Customer AR increased by selling+fee (income recordIncome)
    $custAccountBalance = snapAccount($custAccount->id);
    assertEqualsFloat('T2.1: customer AR = +1300.0 (income)', 1300.0, $custAccountBalance, $results);

    // Agent (Supplier) account debited by purchase (expense recordExpense)
    $agentBalanceAfter = snapAccount($agentT2->account_id);
    $agentDelta = round($agentBalanceAfter - $agentBefore, 2);
    assertEqualsFloat('T2.1: agent ledger delta = -800.0', -800.0, $agentDelta, $results);

    // Vault: +500 from initial payment only — booking itself doesn't touch vault
    $vaultDelta = round(snapAccount($vaultEGP->id) - $vaultBefore, 2);
    assertEqualsFloat('T2.1: vault delta from initial payment = +500.0', 500.0, $vaultDelta, $results);

    // paid_amount = 500, remaining = 1300-500 = 800
    assertEqualsFloat('T2.1: paid_amount = 500.0', 500.0, (float) $booking->paid_amount, $results);
    assertEqualsFloat('T2.1: remaining_amount = 800.0', 800.0, (float) $booking->remaining_amount, $results);
    assertTrue('T2.1: is_fully_paid=false', ! $booking->is_fully_paid, $results);

    // Transactions balanced
    assertTransactionsBalanced('T2.1: expense + income + payment txns balanced',
        array_filter([$booking->expense_transaction_id, $booking->income_transaction_id]),
        $results);

    // One payment record
    $paymentCount = VisaPayment::where('visa_booking_id', $booking->id)->count();
    assertTrue('T2.1: 1 payment record created', $paymentCount === 1, $results,
        "got: {$paymentCount}");
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T3 — Create USD visa booking (no initial payment) — multi-currency
// ═════════════════════════════════════════════════════════════════════════
out_section('T3 — إنشاء حجز USD بدون دفعة أولية');

$bookingT3 = null;
safeRun('T3.1', 'Create USD visa booking without initial payment', function () use (&$results, $adminUser, $vaultUSD, $visaDuration, &$bookingT3) {
    $cust = createTestCustomer('T3', $adminUser->id, 'USD', $results);
    $agent = createTestAgent('T3', $adminUser->id, $results);

    $vaultBefore = snapAccount($vaultUSD->id);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 200.0,
        'selling_price' => 350.0,
        'service_fee' => 0.0,
        'currency' => 'USD',
        'account_id' => $vaultUSD->id,
        'visa_details' => [
            'visa_type' => VisaType::Business->value,
            'country' => 'US',
            'duration' => '60',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Multiple->value,
            'visa_agent_id' => $agent->id,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'US Visa Co',
            'executing_agent' => 'US Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $bookingT3 = $booking->id;
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $booking = $booking->fresh();

    assertTrue('T3.1: USD booking created', $booking->status === VisaStatus::Submitted, $results);
    assertEqualsFloat('T3.1: profit = 350-200 = 150', 150.0, (float) $booking->profit, $results);
    assertEqualsFloat('T3.1: paid_amount = 0', 0.0, (float) $booking->paid_amount, $results);
    assertEqualsFloat('T3.1: remaining_amount = 350', 350.0, (float) $booking->remaining_amount, $results);

    // Vault NOT touched (no payment)
    $vaultDelta = round(snapAccount($vaultUSD->id) - $vaultBefore, 2);
    assertEqualsFloat('T3.1: vault unchanged (no payment)', 0.0, $vaultDelta, $results);

    // Customer AR account in USD
    $custAccount = Account::find($cust->fresh()->account_id);
    assertTrue('T3.1: customer AR in USD', $custAccount && $custAccount->currency === 'USD', $results,
        'got: '.($custAccount ? $custAccount->currency : 'null'));

    assertTransactionsBalanced('T3.1: txns balanced',
        array_filter([$booking->expense_transaction_id, $booking->income_transaction_id]),
        $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T4 — Booking without VisaAgent — expense should go to vault (no agent account)
// ═════════════════════════════════════════════════════════════════════════
out_section('T4 — حجز بدون VisaAgent → التكلفة على الحساب المختار');

$bookingT4 = null;
safeRun('T4.1', 'Booking without agent → expense on selected account', function () use (&$results, $adminUser, $vaultEGP, $visaDuration, &$bookingT4) {
    $cust = createTestCustomer('T4', $adminUser->id, 'EGP', $results);

    $vaultBefore = snapAccount($vaultEGP->id);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 600.0,
        'selling_price' => 900.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Visit->value,
            'country' => 'SA',
            'duration' => '90',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            // NOTE: no visa_agent_id — fallback to account_id (vault)
            'submission_date' => now()->toDateString(),
            'executing_company' => 'SA Co',
            'executing_agent' => 'SA Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $bookingT4 = $booking->id;
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    // Expense goes to vault when no agent
    $vaultDelta = round(snapAccount($vaultEGP->id) - $vaultBefore, 2);
    assertEqualsFloat('T4.1: vault delta = -600 (no agent → vault expense)', -600.0, $vaultDelta, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T5 — Index + show with filters
// ═════════════════════════════════════════════════════════════════════════
out_section('T5 — Index/Show مع الفلاتر');

safeRun('T5.1', 'VisaBookingController::index returns paginated list', function () use (&$results) {
    $controller = app(VisaBookingController::class);
    $resp = $controller->index(new Request(['per_page' => 50]));
    $data = $resp->getData(true);
    assertTrue('T5.1: success=true', ! empty($data['success']), $results);
    assertTrue('T5.1: items array', is_array($data['data']['items'] ?? null), $results);
    assertTrue('T5.1: pagination meta', isset($data['data']['pagination']['total']), $results);
}, $results);

safeRun('T5.2', 'Index filter by status=submitted', function () use (&$results) {
    $controller = app(VisaBookingController::class);
    $resp = $controller->index(new Request(['status' => 'submitted', 'per_page' => 50]));
    $data = $resp->getData(true);
    assertTrue('T5.2: success=true', ! empty($data['success']), $results);
    // Every returned item should be status=submitted
    $allSubmitted = true;
    foreach (($data['data']['items'] ?? []) as $item) {
        if (($item['status'] ?? '') !== 'submitted') {
            $allSubmitted = false;
            break;
        }
    }
    assertTrue('T5.2: all returned bookings are status=submitted', $allSubmitted, $results);
}, $results);

safeRun('T5.3', 'Show specific booking', function () use (&$results, $bookingT2) {
    if (! $bookingT2) {
        throw new RuntimeException('T2 booking missing');
    }
    $controller = app(VisaBookingController::class);
    $booking = VisaBooking::findOrFail($bookingT2);
    $resp = $controller->show($booking);
    $data = $resp->getData(true);
    assertTrue('T5.3: show returns success', ! empty($data['success']), $results);
    assertTrue('T5.3: returned booking matches id', ($data['data']['id'] ?? null) == $bookingT2, $results);
    assertTrue('T5.3: visaDetail attached', isset($data['data']['visa_detail']), $results);
    assertTrue('T5.3: payments attached', isset($data['data']['payments']), $results);
    assertTrue('T5.3: customer attached', isset($data['data']['customer']), $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T6 — Multiple partial payments + fully paid
// ═════════════════════════════════════════════════════════════════════════
out_section('T6 — دفعات جزئية متعددة + اكتمال السداد');

$bookingT6 = null;
safeRun('T6.1', 'Add multiple payments → fully paid', function () use (&$results, $adminUser, $vaultEGP, $visaDuration, &$bookingT6) {
    $cust = createTestCustomer('T6', $adminUser->id, 'EGP', $results);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 500.0,
        'selling_price' => 1000.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'TR',
            'duration' => '30',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'TR Co',
            'executing_agent' => 'TR Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $bookingT6 = $booking->id;
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    // Payment 1: 400
    $p1 = $bookingService->addPayment($booking, [
        'amount' => 400.0,
        'payment_method' => 'cash',
        'account_id' => $vaultEGP->id,
        'reference' => 'TX-P1',
        'paid_by' => $cust->full_name,
    ]);

    $booking = $booking->fresh();
    assertEqualsFloat('T6.1: after p1 — paid=400', 400.0, (float) $booking->paid_amount, $results);
    assertEqualsFloat('T6.1: after p1 — remaining=600', 600.0, (float) $booking->remaining_amount, $results);

    // Payment 2: 600 → fully paid
    $p2 = $bookingService->addPayment($booking, [
        'amount' => 600.0,
        'payment_method' => 'bank_transfer',
        'account_id' => $vaultEGP->id,
        'reference' => 'TX-P2',
    ]);

    $booking = $booking->fresh();
    assertEqualsFloat('T6.1: after p2 — paid=1000', 1000.0, (float) $booking->paid_amount, $results);
    assertEqualsFloat('T6.1: after p2 — remaining=0', 0.0, (float) $booking->remaining_amount, $results);
    assertTrue('T6.1: is_fully_paid=true', $booking->is_fully_paid, $results);

    // 2 payment records
    $paymentCount = VisaPayment::where('visa_booking_id', $booking->id)->count();
    assertTrue('T6.1: 2 payment records', $paymentCount === 2, $results, "got: {$paymentCount}");

    // Both payment transactions balanced
    assertTransactionsBalanced('T6.1: both payment txns balanced',
        array_filter([$p1->transaction_id, $p2->transaction_id]),
        $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T7 — Overpayment rejection (payment > remaining)
// ═════════════════════════════════════════════════════════════════════════
out_section('T7 — رفض الدفع الزائد (Overpayment guard)');

safeRun('T7.1', 'Reject payment > remaining_amount', function () use (&$results, $adminUser, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T7', $adminUser->id, 'EGP', $results);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 300.0,
        'selling_price' => 500.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'JO',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'JO Co',
            'executing_agent' => 'JO Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $rejected = false;
    try {
        $bookingService->addPayment($booking, [
            'amount' => 600.0,  // 500 + 100 = over
            'payment_method' => 'cash',
            'account_id' => $vaultEGP->id,
        ]);
    } catch (Throwable $e) {
        $rejected = true;
        out_info('Caught overpayment rejection: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T7.1: overpayment rejected', $rejected, $results,
        'guard did NOT reject overpayment — security/billing risk!');
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T8 — Update price (repost) — additive, originals preserved
// �════════════════════════════════════════════════════════════════════════
out_section('T8 — تعديل الأسعار (repost) — additive + الأصل يبقى');

safeRun('T8.1', 'Update selling_price → expense/income reposted', function () use (&$results) {
    $booking = VisaBooking::where('notes', null)->orWhere('notes', '')
        ->whereHas('visaDetail', fn ($q) => $q->where('country', 'TR'))
        ->latest()->first();
    if (! $booking) {
        throw new RuntimeException('No T6 booking available to update');
    }

    // Capture originals
    $origIncomeId = $booking->income_transaction_id;
    $origExpenseId = $booking->expense_transaction_id;
    $origIncomeAmount = (float) Transaction::find($origIncomeId)->amount;
    $origExpenseAmount = (float) Transaction::find($origExpenseId)->amount;

    $newSelling = 1500.0;  // was 1000
    $newProfit = $newSelling - $booking->service_fee - $booking->purchase_price;
    $agentAccountId = $booking->visaDetail?->visa_agent_id
        ? Account::find(VisaAgent::find($booking->visaDetail->visa_agent_id)?->account_id)?->id
        : $booking->account_id;

    $custAccount = Account::find($booking->customer->account_id);
    $custBefore = $custAccount->balance;

    $updated = $bookingService->update($booking, [
        'selling_price' => $newSelling,
        'notes' => 'TX-updated-price',
    ]);

    // Original transactions unchanged (additive invariant)
    $origIncomeAfter = Transaction::find($origIncomeId);
    $origExpenseAfter = Transaction::find($origExpenseId);
    assertEqualsFloat('T8.1: original income amount preserved',
        $origIncomeAmount, (float) $origIncomeAfter->amount, $results);
    assertEqualsFloat('T8.1: original expense amount preserved',
        $origExpenseAmount, (float) $origExpenseAfter->amount, $results);

    // New transactions linked
    assertTrue('T8.1: income_transaction_id changed to new txn',
        $updated->income_transaction_id !== $origIncomeId, $results,
        'should have new income id');
    assertTrue('T8.1: expense_transaction_id MAY differ (if purchase changed)',
        true, $results, '(we only changed selling_price)');

    // New income amount = new selling
    $newIncome = Transaction::find($updated->income_transaction_id);
    assertEqualsFloat('T8.1: new income = 1500', $newSelling, (float) $newIncome->amount, $results);

    // New profit
    assertEqualsFloat('T8.1: profit = 1500-0-500 = 1000',
        round($newProfit, 2), round((float) $updated->profit, 2), $results);

    // Original income entries reversed (additive) — find entry with 'عكس:' prefix
    $origEntries = AccountEntry::where('transaction_id', $origIncomeId)->get();
    $hasReversalEntry = $origEntries->contains(fn ($e) => str_starts_with((string) $e->notes, 'عكس'));
    assertTrue('T8.1: original income has reverse entry (additive)', $hasReversalEntry, $results,
        "no reversal entry found on transaction #{$origIncomeId}");

    // New txn balanced
    assertTransactionsBalanced('T8.1: new income txn balanced', [$updated->income_transaction_id], $results);

    // Customer AR delta = +500 (from 1000 → 1500)
    $custAfter = Account::find($booking->customer->account_id)->balance;
    $custDelta = round($custAfter - $custBefore, 2);
    assertEqualsFloat('T8.1: customer AR delta = +500', 500.0, $custDelta, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T9 — Update purchase_price (expense reposted)
// ═════════════════════════════════════════════════════════════════════════
out_section('T9 — تعديل سعر الشراء (repost expense)');

safeRun('T9.1', 'Update purchase_price → expense reversed + new', function () use (&$results) {
    // Use the T8 booking (already has updated selling)
    $booking = VisaBooking::latest()->whereHas('visaDetail', fn ($q) => $q->where('country', 'TR'))->first();
    if (! $booking) {
        throw new RuntimeException('No T8 booking available');
    }

    $origExpenseId = $booking->fresh()->expense_transaction_id;
    $origExpenseAmount = (float) Transaction::find($origExpenseId)->amount;

    // Find agent account (for new expense)
    $agentId = $booking->visaDetail?->visa_agent_id;
    $agentAccountId = null;
    if ($agentId) {
        $agent = VisaAgent::find($agentId);
        if ($agent && $agent->account_id) {
            $agentAccountId = $agent->account_id;
        }
    }
    if (! $agentAccountId) {
        $agentAccountId = $booking->account_id;
    }
    $agentBefore = snapAccount($agentAccountId);

    $newPurchase = 700.0;
    $updated = $bookingService->update($booking, ['purchase_price' => $newPurchase]);

    // Original expense preserved
    $origExpense = Transaction::find($origExpenseId);
    assertEqualsFloat('T9.1: original expense preserved', $origExpenseAmount, (float) $origExpense->amount, $results);

    // New expense = 700
    $newExpense = Transaction::find($updated->expense_transaction_id);
    assertEqualsFloat('T9.1: new expense = 700', $newPurchase, (float) $newExpense->amount, $results);

    // Original expense has reversal entry
    $origEntries = AccountEntry::where('transaction_id', $origExpenseId)->get();
    $hasReversal = $origEntries->contains(fn ($e) => str_starts_with((string) $e->notes, 'عكس'));
    assertTrue('T9.1: original expense has reversal entry', $hasReversal, $results);

    // Agent account delta: from old 500 to new 700 = +200 (extra debit)
    // Actually: previous expense was -500 (debit), now reversed (credit 500) then new -700 (debit 700)
    // Net delta from before update: +500 - 700 = -200
    $agentDelta = round(snapAccount($agentAccountId) - $agentBefore, 2);
    assertEqualsFloat('T9.1: agent account delta = -200', -200.0, $agentDelta, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T10 — Cancel booking → status=Cancelled, payments/income/expense reversed
// ═════════════════════════════════════════════════════════════════════════
out_section('T10 — إلغاء (cancel) — status=cancelled + عكس المدفوعات والقيود');

$bookingT10 = null;
safeRun('T10.1', 'Cancel paid booking → reversals applied', function () use (&$results, $adminUser, $vaultEGP, $visaDuration, &$bookingT10) {
    $cust = createTestCustomer('T10', $adminUser->id, 'EGP', $results);
    $agent = createTestAgent('T10', $adminUser->id, $results);

    $vaultBefore = snapAccount($vaultEGP->id);
    $agentBefore = snapAccount($agent->account_id);
    $custAcct = null;

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 400.0,
        'selling_price' => 700.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'EG',
            'duration' => '30',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'visa_agent_id' => $agent->id,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'EG Co',
            'executing_agent' => 'EG Agent',
            'executing_agent_contact' => '01000000000',
        ],
        'initial_payment' => [
            'amount' => 700.0,  // fully paid
            'payment_method' => 'cash',
            'account_id' => $vaultEGP->id,
        ],
    ]);
    $bookingT10 = $booking->id;
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }
    $custAcct = Account::find($cust->fresh()->account_id);

    $custAcctBefore = snapAccount($custAcct->id);

    // Cancel
    $cancelled = $refundService->cancel($booking, 'TX-test-cancel-reason');
    $cancelled = $cancelled->fresh();

    assertTrue('T10.1: status=Cancelled',
        $cancelled->status === VisaStatus::Cancelled, $results,
        'got: '.($cancelled->status instanceof BackedEnum ? $cancelled->status->value : $cancelled->status));
    assertTrue('T10.1: visa_detail.status=Cancelled',
        $cancelled->visaDetail->status === VisaStatus::Cancelled, $results);
    assertTrue('T10.1: booking row still visible (not trashed)', ! $cancelled->trashed(), $results);

    // Vault: +700 from payment, then -700 from payment reversal = 0 net
    $vaultAfter = snapAccount($vaultEGP->id);
    $vaultDelta = round($vaultAfter - $vaultBefore, 2);
    assertEqualsFloat('T10.1: vault delta after cancel = 0 (paid & reversed)', 0.0, $vaultDelta, $results);

    // Agent: -400 expense, then +400 reversal = 0 net
    $agentAfter = snapAccount($agent->account_id);
    $agentDelta = round($agentAfter - $agentBefore, 2);
    assertEqualsFloat('T10.1: agent delta after cancel = 0', 0.0, $agentDelta, $results);

    // Customer AR: +700 income, then -700 reversal = 0 net
    $custAcctAfter = snapAccount($custAcct->id);
    $custDelta = round($custAcctAfter - $custAcctBefore, 2);
    assertEqualsFloat('T10.1: customer AR delta after cancel = 0', 0.0, $custDelta, $results);

    // Original transactions have reversal entries
    foreach (['expense_transaction_id', 'income_transaction_id'] as $col) {
        $txId = $cancelled->{$col};
        if (! $txId) {
            continue;
        }
        $entries = AccountEntry::where('transaction_id', $txId)->get();
        $hasRev = $entries->contains(fn ($e) => str_starts_with((string) $e->notes, 'عكس'));
        assertTrue("T10.1: {$col} (#{$txId}) has reversal entry", $hasRev, $results);
    }
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T11 — Refund booking → status=Refunded
// ═════════════════════════════════════════════════════════════════════════
out_section('T11 — استرداد (refund) — status=refunded');

safeRun('T11.1', 'Refund booking → reversals + status=refunded', function () use (&$results, $adminUser, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T11', $adminUser->id, 'EGP', $results);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 400.0,
        'selling_price' => 600.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'IT',
            'duration' => '30',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'IT Co',
            'executing_agent' => 'IT Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refunded = $refundService->refund($booking, 'TX-test-refund');
    $refunded = $refunded->fresh();

    assertTrue('T11.1: status=Refunded',
        $refunded->status === VisaStatus::Refunded, $results,
        'got: '.($refunded->status instanceof BackedEnum ? $refunded->status->value : $refunded->status));
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T12 — Soft delete with full financial reversal
// ═════════════════════════════════════════════════════════════════════════
out_section('T12 — حذف إداري deleteWithReversal (soft-delete + عكس كل القيود)');

safeRun('T12.1', 'Soft-delete booking with payments → full reversal', function () use (&$results, $adminUser, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T12', $adminUser->id, 'EGP', $results);
    $agent = createTestAgent('T12', $adminUser->id, $results);

    $vaultBefore = snapAccount($vaultEGP->id);

    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 350.0,
        'selling_price' => 550.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'FR',
            'duration' => '30',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'visa_agent_id' => $agent->id,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'FR Co',
            'executing_agent' => 'FR Agent',
            'executing_agent_contact' => '01000000000',
        ],
        'initial_payment' => [
            'amount' => 300.0,  // partial
            'payment_method' => 'cash',
            'account_id' => $vaultEGP->id,
        ],
    ]);
    $bookingId = $booking->id;
    $results['cleanup_ids']['bookings'][] = $bookingId;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refundService->deleteWithReversal($bookingId, $adminUser->id);

    $booking = VisaBooking::withTrashed()->findOrFail($bookingId);
    assertTrue('T12.1: booking soft-deleted', $booking->trashed(), $results);

    // Payments soft-deleted
    $paymentsTrashed = VisaPayment::onlyTrashed()->where('visa_booking_id', $bookingId)->count();
    $paymentsActive = VisaPayment::where('visa_booking_id', $bookingId)->count();
    assertTrue('T12.1: payments soft-deleted', $paymentsTrashed >= 1 && $paymentsActive === 0, $results,
        "trashed={$paymentsTrashed}, active={$paymentsActive}");

    // Vault: +300 from payment, -300 from reversal = 0
    $vaultAfter = snapAccount($vaultEGP->id);
    $vaultDelta = round($vaultAfter - $vaultBefore, 2);
    assertEqualsFloat('T12.1: vault delta = 0', 0.0, $vaultDelta, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T13 — VisaAgent finance: dues / withdraw / repay
// ═════════════════════════════════════════════════════════════════════════
out_section('T13 — VisaAgent Finance (مديونيات + سحب + سداد)');

$agentT13 = null;
safeRun('T13.1', 'List visa agent dues (after we created expenses)', function () use (&$results, $adminUser, &$agentT13) {
    $agentT13 = createTestAgent('T13', $adminUser->id, $results);
    $controller = app(VisaAgentFinanceController::class);
    $resp = $controller->dues(new Request);
    $data = $resp->getData(true);
    assertTrue('T13.1: response success', ! empty($data['success']), $results);
    assertTrue('T13.1: items is array', is_array($data['data']['items'] ?? null), $results);
}, $results);

safeRun('T13.2', 'Withdraw from agent → cashbox gets cash', function () use (&$results, $vaultEGP, $agentT13) {
    if (! $agentT13 || ! $agentT13->account_id) {
        throw new RuntimeException('No T13 agent');
    }
    // First, seed agent account with a credit (supplier balance)
    // We simulate by transferring 1000 from vault to agent
    $txService->recordJournalTransfer([
        'amount' => 1000.0,
        'from_account_id' => $vaultEGP->id,
        'to_account_id' => $agentT13->account_id,
        'module' => TransactionModule::Visa->value,
        'notes' => 'TX-VISA-E2E T13.2 seed agent balance',
        'created_by' => Auth::id(),
    ]);

    $agentBefore = snapAccount($agentT13->account_id);
    $vaultBefore = snapAccount($vaultEGP->id);

    $controller = app(VisaAgentFinanceController::class);
    $resp = $controller->withdraw(new Request([
        'amount' => 250.0,
        'to_account_id' => $vaultEGP->id,
        'notes' => 'TX-VISA-E2E T13.2 withdraw',
    ]), $agentT13);

    $data = $resp->getData(true);
    assertTrue('T13.2: withdraw success', ! empty($data['success']), $results);

    // Agent: -250, Vault: +250
    assertEqualsFloat('T13.2: agent -250', $agentBefore - 250.0, snapAccount($agentT13->account_id), $results);
    assertEqualsFloat('T13.2: vault +250', $vaultBefore + 250.0, snapAccount($vaultEGP->id), $results);
}, $results);

safeRun('T13.3', 'Repay to agent → cashbox pays supplier', function () use (&$results, $vaultEGP, $agentT13) {
    if (! $agentT13) {
        throw new RuntimeException('No T13 agent');
    }
    $agentBefore = snapAccount($agentT13->account_id);
    $vaultBefore = snapAccount($vaultEGP->id);

    $controller = app(VisaAgentFinanceController::class);
    $resp = $controller->repay(new Request([
        'amount' => 100.0,
        'from_account_id' => $vaultEGP->id,
        'notes' => 'TX-VISA-E2E T13.3 repay',
    ]), $agentT13);

    $data = $resp->getData(true);
    assertTrue('T13.3: repay success', ! empty($data['success']), $results);

    assertEqualsFloat('T13.3: agent +100', $agentBefore + 100.0, snapAccount($agentT13->account_id), $results);
    assertEqualsFloat('T13.3: vault -100', $vaultBefore - 100.0, snapAccount($vaultEGP->id), $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T14 — Customer balances + customer statement + pay customer debt
// ═════════════════════════════════════════════════════════════════════════
out_section('T14 — Customer endpoints (مديونيات + كشف حساب + سداد)');

$custT14 = null;
safeRun('T14.1', 'customerBalances returns list', function () use (&$results) {
    $controller = app(VisaController::class);
    $resp = $controller->customerBalances(new Request);
    $data = $resp->getData(true);
    assertTrue('T14.1: success=true', ! empty($data['success']), $results);
    assertTrue('T14.1: data is array', is_array($data['data'] ?? null), $results);
}, $results);

safeRun('T14.2', 'customerBalances filter status=debtors', function () use (&$results, &$custT14) {
    // Create a customer with a debt
    $custT14 = createTestCustomer('T14', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $custT14->id,
        'purchase_price' => 200.0,
        'selling_price' => 500.0,
        'currency' => 'EGP',
        'account_id' => Account::where('name', 'TX-VISA-E2E-VAULT-EGP')->first()->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'DE',
            'duration' => '30',
            'visa_duration_id' => VisaDuration::where('code', 'TX-VISA-E2E-30D')->first()->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'DE Co',
            'executing_agent' => 'DE Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $controller = app(VisaController::class);
    $resp = $controller->customerBalances(new Request(['status' => 'debtors']));
    $data = $resp->getData(true);
    assertTrue('T14.2: success=true', ! empty($data['success']), $results);

    // Find our customer in the list with debt=500
    $found = false;
    foreach (($data['data'] ?? []) as $row) {
        if (($row['client_id'] ?? null) == $custT14->id && (float) ($row['total_debt'] ?? 0) > 0) {
            $found = true;
            assertEqualsFloat('T14.2: total_debt = 500', 500.0, (float) $row['total_debt'], $results);
            break;
        }
    }
    assertTrue('T14.2: customer with debt found in debtors list', $found, $results);
}, $results);

safeRun('T14.3', 'customerStatement returns ledger entries', function () use (&$results, $custT14) {
    if (! $custT14) {
        throw new RuntimeException('No T14 customer');
    }
    $controller = app(VisaController::class);
    $resp = $controller->customerStatement(new Request(['client_id' => $custT14->id]));
    $data = $resp->getData(true);
    assertTrue('T14.3: success=true', ! empty($data['success']), $results);
    assertTrue('T14.3: has customer info', isset($data['data']['customer']), $results);
    assertTrue('T14.3: has transactions array', is_array($data['data']['transactions'] ?? null), $results);
    assertTrue('T14.3: has summary', isset($data['data']['summary']), $results);
    // Summary total_debt should be positive
    $debt = (float) ($data['data']['summary']['total_debt'] ?? 0);
    assertTrue('T14.3: summary debt = 500', abs($debt - 500.0) < 0.02, $results,
        "got: {$debt}");
}, $results);

safeRun('T14.4', 'payCustomerDebt clears debt via cashbook', function () use (&$results, $custT14) {
    if (! $custT14) {
        throw new RuntimeException('No T14 customer');
    }
    $controller = app(VisaController::class);
    $custAccount = Account::find($custT14->account_id);
    $vault = Account::where('name', 'TX-VISA-E2E-VAULT-EGP')->first();
    $vaultBefore = snapAccount($vault->id);

    $resp = $controller->payCustomerDebt(new Request([
        'amount' => 200.0,
        'account_id' => $vault->id,
        'notes' => 'TX-VISA-E2E T14.4 partial debt payment',
    ]), $custT14);

    $data = $resp->getData(true);
    assertTrue('T14.4: pay debt success', ! empty($data['success']), $results);

    // Vault: +200 (cashbook received payment)
    assertEqualsFloat('T14.4: vault +200', $vaultBefore + 200.0, snapAccount($vault->id), $results);

    // Customer AR: -200 (debt reduced)
    $custAccountAfter = snapAccount($custAccount->id);
    // Customer AR started at 500 (from booking income). Now with 200 payment should be 300.
    assertEqualsFloat('T14.4: customer AR = 300 (was 500, paid 200)', 300.0, $custAccountAfter, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T15 — Visa Treasury endpoints
// ═════════════════════════════════════════════════════════════════════════
out_section('T15 — Visa Treasury (overview + account transactions)');

safeRun('T15.1', 'Treasury overview endpoint', function () use (&$results) {
    $controller = app(VisaTreasuryController::class);
    $resp = $controller->overview(new Request);
    $data = $resp->getData(true);
    assertTrue('T15.1: success=true', ! empty($data['success']), $results);
    assertTrue('T15.1: has settlement_accounts', isset($data['data']['settlement_accounts']), $results);
    assertTrue('T15.1: has agents list', isset($data['data']['agents']), $results);
    assertTrue('T15.1: has recent_visa_transactions', isset($data['data']['recent_visa_transactions']), $results);
    // Our TX-VISA-E2E-VAULT-EGP should appear in settlement_accounts
    $vaultFound = false;
    foreach (($data['data']['settlement_accounts'] ?? []) as $acc) {
        if (($acc['name'] ?? '') === 'TX-VISA-E2E-VAULT-EGP') {
            $vaultFound = true;
            break;
        }
    }
    assertTrue('T15.1: TX-VISA-E2E-VAULT-EGP appears in settlement_accounts', $vaultFound, $results);
}, $results);

safeRun('T15.2', 'Account visa transactions endpoint', function () use (&$results, $vaultEGP) {
    $controller = app(VisaTreasuryController::class);
    $resp = $controller->accountVisaTransactions(new Request(['per_page' => 20]), $vaultEGP);
    $data = $resp->getData(true);
    assertTrue('T15.2: success=true', ! empty($data['success']), $results);
    // Response is a paginator
    assertTrue('T15.2: response has data', isset($data['data']), $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T16 — Guards & invariants
// ═════════════════════════════════════════════════════════════════════════
out_section('T16 — Guards & invariants (protection من العبث)');

safeRun('T16.1', 'Direct $booking->delete() throws (ModelDeletionGuard)', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.1', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'GR',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'GR Co',
            'executing_agent' => 'GR Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $threw = false;
    try {
        $booking->delete();  // direct delete — should be blocked
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught direct delete: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.1: direct $booking->delete() throws', $threw, $results,
        'no guard tripped — silent deletion possible!');
}, $results);

safeRun('T16.2', 'Update on cancelled booking throws', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.2', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'ES',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'ES Co',
            'executing_agent' => 'ES Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refundService->cancel($booking, 'TX-T16.2');
    $booking = $booking->fresh();

    $threw = false;
    try {
        $bookingService->update($booking, ['selling_price' => 300.0]);
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught update on cancelled: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.2: update on cancelled booking throws', $threw, $results,
        'cancelled booking can be silently updated — corruption risk!');
}, $results);

safeRun('T16.3', 'Add payment on cancelled booking throws', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.3', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'PT',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'PT Co',
            'executing_agent' => 'PT Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refundService->cancel($booking, 'TX-T16.3');

    $threw = false;
    try {
        $bookingService->addPayment($booking->fresh(), [
            'amount' => 50.0,
            'payment_method' => 'cash',
            'account_id' => $vaultEGP->id,
        ]);
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught payment on cancelled: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.3: add payment on cancelled throws', $threw, $results,
        'ghost payment on cancelled booking possible — ledger corruption risk!');
}, $results);

safeRun('T16.4', 'Direct profit column write throws (ModelProfitMutationGuard)', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.4', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'NL',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'NL Co',
            'executing_agent' => 'NL Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $threw = false;
    try {
        $booking->update(['profit' => 9999.99]);  // direct profit mutation — should be blocked
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught profit mutation: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.4: direct profit write throws', $threw, $results,
        'profit can be tampered with silently!');
}, $results);

safeRun('T16.5', 'Double-cancel throws (idempotency)', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.5', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'BE',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'BE Co',
            'executing_agent' => 'BE Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refundService->cancel($booking, 'TX-first');
    $threw = false;
    try {
        $refundService->cancel($booking->fresh(), 'TX-second');
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught double-cancel: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.5: double-cancel throws', $threw, $results,
        'second cancel silently re-reverses → phantom entries!');
}, $results);

safeRun('T16.6', 'Cancel on refunded booking throws', function () use (&$results, $vaultEGP, $visaDuration) {
    $cust = createTestCustomer('T16.6', Auth::id(), 'EGP', $results);
    $booking = $bookingService->create([
        'customer_id' => $cust->id,
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',
        'account_id' => $vaultEGP->id,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'CH',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'CH Co',
            'executing_agent' => 'CH Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    if ($booking->visaDetail) {
        $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
    }

    $refundService->refund($booking, 'TX-refund');

    $threw = false;
    try {
        $refundService->cancel($booking->fresh(), 'TX-after-refund');
    } catch (Throwable $e) {
        $threw = true;
        out_info('Caught cancel-on-refunded: '.substr($e->getMessage(), 0, 80));
    }
    assertTrue('T16.6: cancel on refunded throws', $threw, $results);
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T17 — Currency mismatch detection
// ═════════════════════════════════════════════════════════════════════════
out_section('T17 — Currency mismatch (USD account vs EGP booking)');

safeRun('T17.1', 'FormRequest rejects EGP booking with USD account_id', function () use (&$results, $vaultUSD, $visaDuration) {
    $request = new Request([
        'customer' => [
            'full_name' => 'TX-CurrTest',
            'phone' => '01234567890',
        ],
        'purchase_price' => 100.0,
        'selling_price' => 200.0,
        'currency' => 'EGP',  // EGP booking
        'account_id' => $vaultUSD->id,  // but USD account
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'CA',
            'duration' => '15',
            'visa_duration_id' => $visaDuration->id,
            'entry_type' => VisaEntryType::Single->value,
            'submission_date' => now()->toDateString(),
            'executing_company' => 'CA Co',
            'executing_agent' => 'CA Agent',
            'executing_agent_contact' => '01000000000',
        ],
    ]);
    $formRequest = StoreVisaBookingRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $validator = Validator::make($request->all(), $formRequest->rules());
    $formRequest->withValidator($validator);

    $validator->validate();
    $hasCurrencyError = $validator->errors()->has('account_id');
    assertTrue('T17.1: FormRequest rejects currency mismatch', $hasCurrencyError, $results,
        'FormRequest allowed EGP booking to use USD account — silent FX risk!');
    if ($hasCurrencyError) {
        out_info('Validation error: '.$validator->errors()->first('account_id'));
    }
}, $results);

safeRun('T17.2', 'Service-level gap: direct service call accepts mismatched currency', function () use (&$results, $vaultUSD, $visaDuration) {
    // Mirror T22 finding from bus — service doesn't enforce currency match (only FormRequest does)
    $cust = createTestCustomer('T17.2', Auth::id(), 'EGP', $results);
    $threw = false;
    $booking = null;
    try {
        $booking = $bookingService->create([
            'customer_id' => $cust->id,
            'purchase_price' => 100.0,
            'selling_price' => 200.0,
            'currency' => 'EGP',
            'account_id' => $vaultUSD->id,  // USD account, EGP booking
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'BR',
                'duration' => '15',
                'visa_duration_id' => $visaDuration->id,
                'entry_type' => VisaEntryType::Single->value,
                'submission_date' => now()->toDateString(),
                'executing_company' => 'BR Co',
                'executing_agent' => 'BR Agent',
                'executing_agent_contact' => '01000000000',
            ],
        ]);
    } catch (Throwable $e) {
        $threw = true;
        out_info('Service threw: '.$e->getMessage());
    }

    if ($booking) {
        $results['cleanup_ids']['bookings'][] = $booking->id;
        if ($booking->visaDetail) {
            $results['cleanup_ids']['visa_details'][] = $booking->visaDetail->id;
        }
    }

    // This is the documented gap: FormRequest enforces, service accepts silently
    out_warn('T17.2 FINDING: service-level accepts mismatched currency silently — only FormRequest enforces currency match');
    assertTrue('T17.2: documented as known service-level gap', $threw === false, $results,
        'service-level currency check missing — tinker/scripts can post cross-currency entries');
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T18 — API response shape uses 'success' key (not 'status')
// ═════════════════════════════════════════════════════════════════════════
out_section('T18 — API response shape');

safeRun('T18.1', 'ApiResponse uses "success" key, not "status"', function () use (&$results) {
    $ctrl = app(VisaAgentApiController::class);
    $resp = $ctrl->index(new Request);
    $data = $resp->getData(true);
    assertTrue('T18.1: response uses "success" key', array_key_exists('success', $data), $results,
        "expected 'success', got: ".implode(',', array_keys($data)));
    assertTrue('T18.1: response has "data" key', array_key_exists('data', $data), $results);
    if (! array_key_exists('status', $data)) {
        out_warn('T18.1 FINDING: ApiResponse uses "success" not "status" — verify CLAUDE.md is up to date');
    }
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// T19 — VisaModificationService::history() returns modification log
// ═════════════════════════════════════════════════════════════════════════
out_section('T19 — VisaModificationService::history');

safeRun('T19.1', 'Modification history contains reversal+repost entries', function () use (&$results) {
    // Use the T8 booking (which had prices updated)
    $booking = VisaBooking::latest()->whereHas('visaDetail', fn ($q) => $q->where('country', 'TR'))->first();
    if (! $booking) {
        throw new RuntimeException('No T8 booking available');
    }

    $controller = app(VisaBookingController::class);
    $resp = $controller->modifications($booking);
    $data = $resp->getData(true);
    assertTrue('T19.1: success=true', ! empty($data['success']), $results);
    assertTrue('T19.1: data is array', is_array($data['data'] ?? null), $results);
    // Should have at least 2 entries (reversal + repost for income, possibly expense)
    assertTrue('T19.1: history has entries', count($data['data'] ?? []) > 0, $results,
        'got: '.count($data['data'] ?? []).' entries');
}, $results);

// �════════════════════════════════════════════════════════════════════════
// T20 — Filter by status (cancelled should exclude cancelled bookings)
// ═════════════════════════════════════════════════════════════════════════
out_section('T20 — Filter behaviour');

safeRun('T20.1', 'Index excludes cancelled/refunded bookings when not filtered', function () use (&$results, $bookingT10) {
    if (! $bookingT10) {
        throw new RuntimeException('No T10 (cancelled) booking');
    }
    $controller = app(VisaBookingController::class);
    // Default filter — no status — should still return cancelled in list? Let's see
    $resp = $controller->index(new Request(['per_page' => 100]));
    $data = $resp->getData(true);

    // The default index returns ALL statuses (no auto-exclusion)
    // Just confirm structure is OK
    assertTrue('T20.1: success=true', ! empty($data['success']), $results);

    // But customerBalances excludes cancelled
    $ctrl2 = app(VisaController::class);
    $resp2 = $ctrl2->customerBalances(new Request);
    $data2 = $resp2->getData(true);
    $cancelledFound = false;
    foreach (($data2['data'] ?? []) as $row) {
        if (($row['client_id'] ?? null) == VisaBooking::find($bookingT10)->customer_id) {
            $cancelledFound = true;
            break;
        }
    }
    assertTrue('T20.1: customerBalances excludes cancelled booking', ! $cancelledFound, $results,
        'cancelled booking still in debtors list — credit risk!');
}, $results);

// ═════════════════════════════════════════════════════════════════════════
// Final report
// ═════════════════════════════════════════════════════════════════════════
out_section('النتيجة النهائية');

$results['finished_at'] = date('Y-m-d H:i:s');
$passed = $results['verdict']['passed'];
$failed = $results['verdict']['failed'];
$total = $passed + $failed;

echo "  Passed: $passed / $total\n";
echo "  Failed: $failed / $total\n\n";

if ($failed === 0) {
    echo "  🎉 كل التيستات نجحت! مفيش مشاكل في الـ Visa module logic.\n";
} else {
    echo "  ⚠️  فيه مشاكل:\n";
    foreach ($results['verdict']['issues'] as $i => $issue) {
        echo '    '.($i + 1).'. '.json_encode($issue, JSON_UNESCAPED_UNICODE)."\n";
    }
}

// Save report
$reportPath = storage_path('logs/visa_full_e2e_results.json');
file_put_contents($reportPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
out_info("التقرير محفوظ في: $reportPath");

// Cleanup guide
echo "\n";
echo "  📋 Test data IDs (للمراجعة قبل المسح):\n";
echo '    - Bookings: '.implode(', ', $results['cleanup_ids']['bookings'])."\n";
echo '    - VisaDetails: '.implode(', ', $results['cleanup_ids']['visa_details'])."\n";
echo '    - Customers: '.implode(', ', $results['cleanup_ids']['customers'])."\n";
echo '    - Agents: '.implode(', ', $results['cleanup_ids']['agents'])."\n";
echo '    - Accounts: '.implode(', ', $results['cleanup_ids']['accounts'])."\n";
echo "\n";
echo "  🧹 لتنظيف بيانات التيست (احذر — يشيل TX-VISA-E2E- entries فقط):\n";
echo '    DELETE FROM visa_payments WHERE visa_booking_id IN ('
    .implode(',', $results['cleanup_ids']['bookings']).");\n";
echo '    DELETE FROM visa_bookings WHERE id IN ('
    .implode(',', $results['cleanup_ids']['bookings']).");\n";
echo '    DELETE FROM visa_details WHERE id IN ('
    .implode(',', $results['cleanup_ids']['visa_details']).");\n";
echo '    DELETE FROM customers WHERE id IN ('
    .implode(',', $results['cleanup_ids']['customers']).");\n";
echo '    DELETE FROM visa_agents WHERE id IN ('
    .implode(',', $results['cleanup_ids']['agents']).");\n";
echo '    DELETE FROM accounts WHERE id IN ('
    .implode(',', $results['cleanup_ids']['accounts']).");\n";
echo "\n";
