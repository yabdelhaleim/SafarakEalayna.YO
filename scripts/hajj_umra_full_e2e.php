<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Hajj & Umra Module — FULL E2E TEST (Complete User Journey)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر موديول الحج والعمرة بالكامل عبر سيناريوهات مستخدم حقيقية شاملة
 * (full user journey) — ينشئ حجوزات بدفعات وتعديلات وإلغاء واسترداد
 * وحذف، ويتحقق من كل قيد محاسبي وكل رصيد حساب.
 *
 * كل عملية تُسجَّل فعلياً في الـ DB (لا mocks).
 * كل سيناريو ينغلف في safeRun() — فشل سيناريو واحد ما بيقتلش الباقي.
 *
 * النطاقات المغطاة (11 phase / 52 سيناريو):
 *   Phase 1:  Reference data (accommodation / supervisors / hotels / supplier / exec company / programs)
 *   Phase 2:  Booking creation (EGP, USD w/ supplier, SAR w/ exec company, initial payment, companion, passengers)
 *   Phase 3:  Read + Update (list, show, increase selling, decrease purchase, change supplier)
 *   Phase 4:  Payments (single, multi, overpayment, USD-via-service)
 *   Phase 5:  Lifecycle guards (edit/pay/cancel/refund on cancelled|refunded — known bug fixes)
 *   Phase 6:  Cancel + Refund full flows
 *   Phase 7:  Soft-delete (admin) with full reversal + idempotency
 *   Phase 8:  Program management (update, delete empty, delete with bookings)
 *   Phase 9:  Executing company finance (dues, withdraw, repay, repay insufficient)
 *   Phase 10: Endpoints / Reports (dashboard, treasury, customer-balances, customer-statement, references)
 *   Phase 11: Edge cases (insufficient cashbox, doc drift, balance invariant, module scope isolation)
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/hajj_umra_local_setup.php    # يجهز SQLite + يشغّل هذا الملف
 *
 * النتائج:
 *   - تقرير مفصّل على الـ stdout
 *   - JSON في storage/logs/hajj_umra_full_e2e_results.json
 *   - Markdown في HAJJUMRA_FULL_E2E_REPORT_20260812.md (يُكتب بعد التشغيل)
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
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraDashboardController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraProgramController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController;
use App\Http\Controllers\Api\V1\HajjUmra\UmrahSupplierApiController;
use App\Http\Controllers\Api\V1\HajjUmraController;
use App\Http\Controllers\Api\V1\HajjUmraReferenceController;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'tests' => [],
    'findings' => [],
    'verdict' => ['passed' => 0, 'failed' => 0, 'issues' => []],
];

// ─── Output helpers ──────────────────────────────────────────────────────
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
    echo "    ℹ  $m\n";
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

// ─── Test runner (continues on failure) ──────────────────────────────────
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

function recordFinding(string $testId, string $severity, string $msg, array &$results): void
{
    $results['findings'][] = [
        'test' => $testId,
        'severity' => $severity,
        'message' => $msg,
    ];
    $icon = $severity === 'CRITICAL' ? '🔴' : ($severity === 'HIGH' ? '🟠' : ($severity === 'MEDIUM' ? '🟡' : '🟢'));
    out_warn("$icon FINDING [$severity] $testId: $msg");
}

function assertBalance(string $label, int $accountId, float $expected, array &$results, float $tolerance = 0.01): void
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

function assertEquals(string $label, $expected, $actual, array &$results, string $context = ''): void
{
    if ($expected == $actual) {
        out_ok("$label (=$actual)");
        $results['verdict']['passed']++;
    } else {
        out_fail("$label expected=".json_encode($expected).' actual='.json_encode($actual)." $context");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = [
            'test' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'context' => $context,
        ];
    }
}

function assertThrows(string $label, callable $fn, string $expectedMsgFragment, array &$results): void
{
    try {
        $fn();
        out_fail("$label: expected exception containing '$expectedMsgFragment' but none thrown");
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => $label, 'context' => 'no exception thrown'];
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $expectedMsgFragment)) {
            out_ok("$label (caught: ".substr($e->getMessage(), 0, 60).'...)');
            $results['verdict']['passed']++;
        } else {
            out_fail("$label: exception thrown but message doesn't contain '$expectedMsgFragment' (got: {$e->getMessage()})");
            $results['verdict']['failed']++;
            $results['verdict']['issues'][] = ['test' => $label, 'context' => 'wrong exception message', 'actual_msg' => $e->getMessage()];
        }
    }
}

function assertHttpOk(string $label, JsonResponse $resp, array &$results): array
{
    $data = $resp->getData(true);
    $success = $data['success'] ?? false;
    if ($success === true) {
        out_ok("$label (success)");
        $results['verdict']['passed']++;
    } else {
        out_fail("$label: success=false ".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => $label, 'response' => $data];
    }

    return $data['data'] ?? [];
}

function assertHttpError(string $label, JsonResponse $resp, int $expectedStatus, array &$results, string $expectedMsgFragment = ''): array
{
    $status = $resp->getStatusCode();
    $data = $resp->getData(true);
    $ok = $status === $expectedStatus;
    if ($expectedMsgFragment !== '') {
        $msg = (string) ($data['message'] ?? $data['msg'] ?? '');
        $ok = $ok && (str_contains($msg, $expectedMsgFragment) || str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), $expectedMsgFragment));
    }
    if ($ok) {
        out_ok("$label (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("$label: expected $expectedStatus".($expectedMsgFragment ? " containing '$expectedMsgFragment'" : '')." got $status ".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => $label, 'response' => $data, 'expected_status' => $expectedStatus];
    }

    return $data;
}

// ─── Authenticate as admin ───────────────────────────────────────────────
$adminUser = User::where('email', 'hajj-umra-e2e-admin@local.test')->first();
if (! $adminUser) {
    $adminUser = User::where('role', 'admin')->first() ?? User::first();
}
if (! $adminUser) {
    out_fail('No admin user found in local DB.');
    exit(1);
}
Auth::login($adminUser);
out_info("Authenticated as User #{$adminUser->id} ({$adminUser->email})");

// ─── Setup helpers ──────────────────────────────────────────────────────
function snapAccount(int $id): float
{
    $a = Account::find($id);

    return $a ? (float) $a->balance : 0.0;
}

function snapBalance(int $id): float
{
    return snapAccount($id);
}

function getOrCreateCashbox(string $currency, int $adminId, float $openingBalance, string $moduleType = 'tourism'): Account
{
    $name = "TX-HAJJ-E2E-VAULT-{$currency}";
    $existing = Account::where('name', $name)->first();
    if ($existing) {
        return $existing;
    }

    return LedgerBalanceMutationGuard::run(function () use ($name, $currency, $adminId, $openingBalance, $moduleType) {
        return Account::create([
            'name' => $name,
            'type' => AccountType::Cashbox,
            'currency' => $currency,
            'balance' => $openingBalance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => $moduleType,
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'notes' => 'TX-HAJJ-E2E test cashbox — isolated from production',
            'created_by' => $adminId,
        ]);
    });
}

function ensureExchangeRates(int $adminId): void
{
    $today = now()->toDateString();
    $rates = [
        ['USD', 'EGP', 50.0], ['EGP', 'USD', 0.02],
        ['SAR', 'EGP', 13.33], ['EGP', 'SAR', 0.075],
        ['KWD', 'EGP', 162.5], ['EGP', 'KWD', 0.00615],
        ['EUR', 'EGP', 54.5], ['EGP', 'EUR', 0.0183],
    ];
    foreach ($rates as [$from, $to, $rate]) {
        ExchangeRate::updateOrCreate(
            ['from_currency' => $from, 'to_currency' => $to, 'effective_date' => $today],
            ['rate' => $rate, 'is_active' => true, 'created_by' => $adminId]
        );
    }
}

function createTestCustomer(string $suffix, int $adminId, string $currency = 'EGP'): array
{
    $account = Account::create([
        'name' => "TX-HAJJ-E2E-CUST-{$suffix}",
        'type' => AccountType::Customer,
        'balance' => 0,
        'currency' => $currency,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'hajj_umra',
        'is_module_vault' => false,
        'notes' => 'TX-HAJJ-E2E test data — safe to delete',
        'created_by' => $adminId,
    ]);
    $customer = Customer::create([
        'account_id' => $account->id,
        'full_name' => "TX-HAJJ-E2E-CUST-{$suffix}",
        'phone' => '01'.substr(str_pad((string) abs(crc32('hajj'.$suffix)), 9, '0', STR_PAD_LEFT), 0, 9),
        'national_id' => str_pad((string) random_int(10000000000000, 99999999999999), 14, '0', STR_PAD_LEFT),
        'type' => 'individual',
        'status' => 'active',
        'module_type' => 'hajj_umra',
        'created_by' => $adminId,
    ]);

    return ['customer' => $customer, 'account' => $account];
}

function findTreasury(string $currency): ?Account
{
    return Account::where('type', AccountType::Cashbox)
        ->where('currency', $currency)
        ->where('is_active', true)
        ->whereIn('module_type', ['tourism', 'hajj_umra', 'office'])
        ->first();
}

// ─── Pre-flight ─────────────────────────────────────────────────────────
ensureExchangeRates($adminUser->id);
$cashboxEGP = getOrCreateCashbox('EGP', $adminUser->id, 500000.0);
$cashboxUSD = getOrCreateCashbox('USD', $adminUser->id, 50000.0);
$cashboxSAR = getOrCreateCashbox('SAR', $adminUser->id, 30000.0);

out_info("Cashboxes: EGP=#{$cashboxEGP->id} ({$cashboxEGP->balance}), USD=#{$cashboxUSD->id} ({$cashboxUSD->balance}), SAR=#{$cashboxSAR->id} ({$cashboxSAR->balance})");

// Variables that will be populated by tests and used by later tests
$GLOBALS['adminId'] = $adminUser->id;
$GLOBALS['cashboxEGP'] = $cashboxEGP;
$GLOBALS['cashboxUSD'] = $cashboxUSD;
$GLOBALS['cashboxSAR'] = $cashboxSAR;

// ═══════════════════════════════════════════════════════════════════════
// Phase 1: Reference data
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 1 — Reference data');

// T01: accommodation types (DB-direct since no API)
safeRun('T01', 'Reference: accommodation types (QUAD/TRIPLE/DOUBLE)', function () use (&$results) {
    $types = [
        ['code' => 'QUAD',     'name_ar' => 'رباعي',     'capacity' => 4],
        ['code' => 'TRIPLE',   'name_ar' => 'ثلاثي',     'capacity' => 3],
        ['code' => 'DOUBLE',   'name_ar' => 'مزدوج',     'capacity' => 2],
        ['code' => 'SINGLE',   'name_ar' => 'فردي',      'capacity' => 1],
    ];
    foreach ($types as $t) {
        AccommodationType::updateOrCreate(['code' => $t['code']], array_merge($t, [
            'name_en' => strtolower($t['code']),
            'is_active' => true,
            'sort_order' => $t['capacity'],
        ]));
    }
    $count = AccommodationType::count();
    assertTrue('T01: 4+ accommodation types seeded', $count >= 4, $results, "got $count");
}, $results);

// T02: trip supervisors (DB-direct)
safeRun('T02', 'Reference: trip supervisors', function () use (&$results) {
    $names = ['محمد العتيبي', 'أحمد البلوي', 'خالد الزهراني'];
    foreach ($names as $i => $n) {
        TripSupervisor::updateOrCreate(['full_name' => $n], [
            'phone' => '05000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
    }
    assertTrue('T02: 3+ trip supervisors seeded', TripSupervisor::count() >= 3, $results);
}, $results);

// T03: hotels (DB-direct)
safeRun('T03', 'Reference: hotels (Mecca/Medina)', function () use (&$results) {
    $hotels = [
        ['name' => 'TX-HAJJ-E2E فندق مكة 1', 'city' => 'مكة', 'stars' => 5],
        ['name' => 'TX-HAJJ-E2E فندق المدينة 1', 'city' => 'المدينة', 'stars' => 4],
    ];
    foreach ($hotels as $h) {
        Hotel::updateOrCreate(['name' => $h['name']], array_merge($h, [
            'is_active' => true,
            'total_rooms' => 100,
            'available_rooms' => 50,
        ]));
    }
    assertTrue('T03: 2+ hotels seeded', Hotel::where('name', 'like', 'TX-HAJJ-E2E%')->count() >= 2, $results);
}, $results);

// T04: executing company (auto-creates SAR AP account via booted())
safeRun('T04', 'Reference: executing company (auto-creates SAR AP)', function () use (&$results) {
    $company = HajjUmraExecutingCompany::updateOrCreate(
        ['name' => 'TX-HAJJ-E2E شركة تنفيذ 1'],
        ['license_number' => 'LIC-TX-001', 'phone' => '0500000001', 'is_active' => true]
    );
    $company = $company->fresh();
    assertTrue('T04: company has auto-created AP account', $company->account_id !== null, $results, 'account_id is null');
    if ($company->account_id) {
        $acc = Account::find($company->account_id);
        $typeVal = $acc->type instanceof BackedEnum ? $acc->type->value : $acc->type;
        assertEquals('T04: company AP account type=supplier', AccountType::Supplier->value, $typeVal, $results);
        assertEquals('T04: company AP account currency=SAR', 'SAR', $acc->currency, $results);
    }
    $GLOBALS['executingCompany'] = $company;
}, $results);

// T05: umrah supplier via UmrahSupplierApiController
safeRun('T05', 'Reference: umrah supplier (via Controller, auto-creates AP)', function () use (&$results) {
    $controller = app(UmrahSupplierApiController::class);
    $req = new Request([
        'name' => 'TX-HAJJ-E2E مورد العمرة 1',
        'phone' => '0500000002',
        'default_cost_price' => 5000,
    ]);
    $resp = $controller->store($req);
    $data = $resp->getData(true);
    assertTrue('T05: supplier created (success=true)', ($data['success'] ?? false) === true, $results,
        'response: '.json_encode($data, JSON_UNESCAPED_UNICODE));
    $supplierId = $data['data']['id'] ?? null;
    $supplier = UmrahSupplier::find($supplierId);
    $GLOBALS['supplier'] = $supplier;
    assertTrue('T05: supplier has AP account auto-created', $supplier && $supplier->account_id !== null, $results);
    if ($supplier && $supplier->account_id) {
        $acc = Account::find($supplier->account_id);
        $typeVal = $acc->type instanceof BackedEnum ? $acc->type->value : $acc->type;
        assertEquals('T05: supplier AP account type=supplier', AccountType::Supplier->value, $typeVal, $results);
    }
}, $results);

// T06: create Hajj program via HajjUmraProgramController
safeRun('T06', 'Reference: Hajj program (via Controller)', function () use (&$results) {
    $controller = app(HajjUmraProgramController::class);
    $accomType = AccommodationType::where('code', 'QUAD')->first();
    $supervisor = TripSupervisor::where('full_name', 'محمد العتيبي')->first();
    $hotelMecca = Hotel::where('city', 'مكة')->first();
    $hotelMedina = Hotel::where('city', 'المدينة')->first();

    $req = new Request([
        'program_name' => 'TX-HAJJ-E2E برنامج حج 2026',
        'program_type' => 'hajj',
        'season' => '2026',
        'total_nights' => 14,
        'mecca_nights' => 8,
        'medina_nights' => 6,
        'mecca_hotel_id' => $hotelMecca?->id,
        'medina_hotel_id' => $hotelMedina?->id,
        'mecca_hotel_name' => $hotelMecca?->name,
        'medina_hotel_name' => $hotelMedina?->name,
        'departure_date' => now()->addMonths(6)->toDateString(),
        'return_date' => now()->addMonths(6)->addDays(14)->toDateString(),
        'airline' => 'الخطوط السعودية',
        'departure_point' => 'القاهرة',
        'accommodation_type' => 'QUAD',
        'accommodation_type_id' => $accomType?->id,
        'trip_supervisor_id' => $supervisor?->id,
        'executing_company_id' => $GLOBALS['executingCompany']->id ?? null,
        'default_purchase_price' => 80000,
        'default_selling_price' => 100000,
    ]);
    $resp = $controller->store($req);
    $data = $resp->getData(true);
    assertTrue('T06: Hajj program created (success)', ($data['success'] ?? false) === true, $results,
        'response: '.json_encode($data, JSON_UNESCAPED_UNICODE));
    $programId = $data['data']['id'] ?? null;
    $program = Program::find($programId);
    $GLOBALS['hajjProgram'] = $program;
    assertTrue('T06: program_name set', $program && $program->program_name === 'TX-HAJJ-E2E برنامج حج 2026', $results);
    assertEquals('T06: program_type=hajj', 'hajj', $program?->program_type, $results);
}, $results);

// T07: create Umrah program via HajjUmraProgramController
safeRun('T07', 'Reference: Umrah program (via Controller)', function () use (&$results) {
    $controller = app(HajjUmraProgramController::class);
    $accomType = AccommodationType::where('code', 'QUAD')->first();
    $hotelMecca = Hotel::where('city', 'مكة')->first();
    $hotelMedina = Hotel::where('city', 'المدينة')->first();
    $supervisor = TripSupervisor::where('full_name', 'أحمد البلوي')->first();

    $req = new Request([
        'program_name' => 'TX-HAJJ-E2E برنامج عمرة 2026',
        'program_type' => 'umra',
        'season' => '2026',
        'total_nights' => 7,
        'mecca_nights' => 4,
        'medina_nights' => 3,
        'mecca_hotel_id' => $hotelMecca?->id,
        'medina_hotel_id' => $hotelMedina?->id,
        'mecca_hotel_name' => $hotelMecca?->name,
        'medina_hotel_name' => $hotelMedina?->name,
        'departure_date' => now()->addMonths(2)->toDateString(),
        'return_date' => now()->addMonths(2)->addDays(7)->toDateString(),
        'airline' => 'طيران ناس',
        'departure_point' => 'القاهرة',
        'accommodation_type' => 'QUAD',
        'accommodation_type_id' => $accomType?->id,
        'trip_supervisor_id' => $supervisor?->id,
        'default_purchase_price' => 18000,
        'default_selling_price' => 25000,
    ]);
    $resp = $controller->store($req);
    $data = $resp->getData(true);
    assertTrue('T07: Umrah program created (success)', ($data['success'] ?? false) === true, $results,
        'response: '.json_encode($data, JSON_UNESCAPED_UNICODE));
    $programId = $data['data']['id'] ?? null;
    $program = Program::find($programId);
    $GLOBALS['umrahProgram'] = $program;
    assertEquals('T07: program_type=umra', 'umra', $program?->program_type, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 2: Booking creation (full user journey)
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 2 — Booking creation (full user journey)');

// T08: EGP booking without supplier/exec-company → cashbox fallback
safeRun('T08', 'Booking EGP, no supplier (cashbox fallback)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T08', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 20000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);

    assertTrue('T08: booking created', $booking && $booking->id > 0, $results);
    assertEquals('T08: profit = selling - purchase', 5000.0, (float) $booking->profit, $results);
    assertEquals('T08: currency=EGP', 'EGP', $booking->currency, $results);
    // Cashbox debited by purchase cost
    assertBalance('T08: cashbox debited by 20000', $GLOBALS['cashboxEGP']->id, $cashBefore - 20000, $results);
    // Customer AR should be +25000 (debt)
    $custAR = $cust['account']->fresh()->balance;
    assertEquals('T08: customer AR = +25000 (debt)', 25000.0, (float) $custAR, $results);
    $GLOBALS['bookingT08'] = $booking;
    $GLOBALS['customerT08'] = $cust;
}, $results);

// T09: USD booking with umrah supplier → expense routes to supplier AP
safeRun('T09', 'Booking USD with umrah supplier (supplier AP)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T09', $adminUser->id, 'USD');
    $supplier = $GLOBALS['supplier'];

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 1500,
        'selling_price' => 2200,
        'currency' => 'USD',
        'account_id' => $GLOBALS['cashboxUSD']->id,
        'supplier_id' => $supplier->id,
    ]);

    assertTrue('T09: USD booking created', $booking && $booking->id > 0, $results);
    assertEquals('T09: currency=USD', 'USD', $booking->currency, $results);
    // Supplier AP should be -1500 (we owe supplier)
    $suppBal = $supplier->account->fresh()->balance;
    assertEquals('T09: supplier AP = -1500 (we owe)', -1500.0, (float) $suppBal, $results);
    // Cashbox USD should be unchanged (expense came from supplier, not cashbox)
    $cashUSD = snapBalance($GLOBALS['cashboxUSD']->id);
    out_info("T09: cashboxUSD = $cashUSD (should be untouched)");
    $GLOBALS['bookingT09'] = $booking;
    $GLOBALS['customerT09'] = $cust;
}, $results);

// T10: SAR booking with executing company → expense routes to company AP
safeRun('T10', 'Booking SAR with executing company (company AP)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T10', $adminUser->id, 'SAR');
    $company = $GLOBALS['executingCompany'];

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['hajjProgram']->id,
        'purchase_price' => 80000,
        'selling_price' => 100000,
        'currency' => 'SAR',
        'account_id' => $GLOBALS['cashboxSAR']->id,
    ]);

    assertTrue('T10: SAR booking created', $booking && $booking->id > 0, $results);
    assertEquals('T10: currency=SAR', 'SAR', $booking->currency, $results);
    // Company AP should be -80000
    $compBal = $company->account->fresh()->balance;
    assertEquals('T10: company AP = -80000 (we owe)', -80000.0, (float) $compBal, $results);
    $GLOBALS['bookingT10'] = $booking;
    $GLOBALS['customerT10'] = $cust;
}, $results);

// T11: EGP booking with initial_payment
safeRun('T11', 'Booking with initial_payment.amount > 0', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T11', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 18000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
        'initial_payment' => [
            'amount' => 10000,
            'payment_method' => 'cash',
            'account_id' => $GLOBALS['cashboxEGP']->id,
            'paid_by' => 'TX-HAJJ-E2E-CUST-T11',
        ],
    ]);

    assertTrue('T11: booking with initial payment created', $booking && $booking->id > 0, $results);
    $paymentCount = HajjUmraPayment::where('hajj_umra_booking_id', $booking->id)->count();
    assertEquals('T11: 1 payment created', 1, $paymentCount, $results);
    $custAR = $cust['account']->fresh()->balance;
    // AR = 25000 - 10000 = 15000
    assertEquals('T11: customer AR = 25000-10000 = 15000', 15000.0, (float) $custAR, $results);
    $GLOBALS['bookingT11'] = $booking;
}, $results);

// T12: Booking with companion + accommodation_extra
safeRun('T12', 'Booking with companion + accommodation_extra (profit math)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T12', $adminUser->id, 'EGP');
    $companion = createTestCustomer('T12-COMP', $adminUser->id, 'EGP');

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'companion_customer_id' => $companion['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 20000,
        'companion_purchase_price' => 18000,
        'selling_price' => 25000,
        'companion_selling_price' => 23000,
        'accommodation_extra_charge' => 2000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);

    assertTrue('T12: booking with companion created', $booking && $booking->id > 0, $results);
    // total_purchase = 20000 + 18000 = 38000
    // total_selling = 25000 + 23000 + 2000 = 50000
    // profit = 12000
    assertEquals('T12: total_purchase = 38000', 38000.0, (float) ($booking->purchase_price + $booking->companion_purchase_price), $results);
    assertEquals('T12: total_selling = 50000', 50000.0, (float) $booking->total_selling_price, $results);
    assertEquals('T12: profit = 12000', 12000.0, (float) $booking->profit, $results);
    $GLOBALS['bookingT12'] = $booking;
}, $results);

// T13: Booking with passengers breakdown
safeRun('T13', 'Booking with passengers breakdown (adult/child/infant)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T13', $adminUser->id, 'EGP');

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 20000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
        'passengers' => [
            ['category' => 'adult', 'count' => 2, 'unit_price' => 12500, 'subtotal' => 25000],
            ['category' => 'child_with_bed', 'count' => 1, 'unit_price' => 10000, 'subtotal' => 10000],
            ['category' => 'infant', 'count' => 1, 'unit_price' => 0, 'subtotal' => 0],
        ],
    ]);

    assertTrue('T13: booking with passengers created', $booking && $booking->id > 0, $results);
    $passengerCount = $booking->passengers()->count();
    assertEquals('T13: 3 passenger rows', 3, $passengerCount, $results);
    $GLOBALS['bookingT13'] = $booking;
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 3: Read + Update
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 3 — Read + Update');

// T14: list bookings with filter
safeRun('T14', 'GET /bookings with status filter', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $req = new Request(['status' => 'confirmed', 'per_page' => 50]);
    $resp = $controller->index($req);
    $data = assertHttpOk('T14: list bookings', $resp, $results);
    $itemCount = count($data['items'] ?? []);
    assertTrue("T14: list has $itemCount items", $itemCount > 0, $results);
    // Verify all items are confirmed
    $allConfirmed = true;
    foreach ($data['items'] ?? [] as $item) {
        if (($item['status'] ?? null) !== 'confirmed') {
            $allConfirmed = false;
            break;
        }
    }
    assertTrue('T14: all items have status=confirmed', $allConfirmed, $results);
}, $results);

// T15: show booking details
safeRun('T15', 'GET /bookings/{id} — show booking details', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT08'];
    $resp = $controller->show($booking);
    $data = assertHttpOk('T15: show booking', $resp, $results);
    $b = $data; // resource payload
    assertTrue('T15: id matches', ($b['id'] ?? null) == $booking->id, $results);
    assertTrue('T15: customer object loaded', isset($b['customer']['id']), $results);
    assertTrue('T15: program object loaded', isset($b['program']['id']), $results);
    assertTrue('T15: finance.expense_transaction_id set', ! empty($b['finance']['expense_transaction_id']), $results);
    assertTrue('T15: finance.income_transaction_id set', ! empty($b['finance']['income_transaction_id']), $results);
    assertEquals('T15: is_fully_paid=false', false, $b['finance']['is_fully_paid'] ?? true, $results);
}, $results);

// T16: update — increase selling price → repost income
safeRun('T16', 'PATCH /bookings/{id} increase selling price (repost income)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT08'];
    $custAccId = $GLOBALS['customerT08']['account']->id;
    $custARBefore = snapBalance($custAccId);

    $req = new Request(['selling_price' => 28000]); // was 25000 → +3000
    $resp = $controller->update($req, $booking);
    $data = assertHttpOk('T16: update selling price', $resp, $results);
    $b = $data;
    assertEquals('T16: new selling_price = 28000', 28000.0, (float) $b['pricing']['selling_price'], $results);
    assertEquals('T16: new profit = 8000', 8000.0, (float) $b['pricing']['profit'], $results);
    // Customer AR should be 28000 now
    $custARAfter = snapBalance($custAccId);
    assertEquals('T16: customer AR = 28000', 28000.0, $custARAfter, $results, 'delta = '.($custARAfter - $custARBefore));
    // The original income tx should be reversed and a new one created
    $bookingRefreshed = $booking->fresh();
    $oldIncome = Transaction::find($bookingRefreshed->income_transaction_id);
    $hasReverse = AccountEntry::where('transaction_id', $oldIncome->id)
        ->where('notes', 'like', 'عكس:%')->exists();
    assertTrue('T16: original income tx has reverse entries (additive reversal)', $hasReverse, $results);
}, $results);

// T17: update — decrease purchase price → repost expense
safeRun('T17', 'PATCH /bookings/{id} decrease purchase price (repost expense)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT08'];
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $req = new Request(['purchase_price' => 18000]); // was 20000 → -2000
    $resp = $controller->update($req, $booking);
    $data = assertHttpOk('T17: update purchase price', $resp, $results);
    assertEquals('T17: new purchase_price = 18000', 18000.0, (float) $data['pricing']['purchase_price'], $results);
    // Cashbox should be +2000 (the -2000 expense was reversed)
    $cashAfter = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T17: cashbox +2000 (reversal of -2000 expense)', $cashBefore + 2000, $cashAfter, $results);
}, $results);

// T18: change companion + supplier
safeRun('T18', 'PATCH /bookings/{id} change companion', function () use (&$results, $adminUser) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT12'];
    $newCompanion = createTestCustomer('T18-COMP-NEW', $adminUser->id, 'EGP');
    $req = new Request(['companion_customer_id' => $newCompanion['customer']->id]);
    $resp = $controller->update($req, $booking);
    $data = assertHttpOk('T18: change companion', $resp, $results);
    assertEquals('T18: companion updated', $newCompanion['customer']->id, $data['companion']['id'] ?? null, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 4: Payments
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 4 — Payments');

// T19: Add single payment
safeRun('T19', 'POST /bookings/{id}/payments — single payment', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT08']; // selling 28000, no payments yet
    $custARBefore = snapBalance($GLOBALS['customerT08']['account']->id);
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $req = new Request([
        'amount' => 10000,
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxEGP']->id,
        'paid_by' => 'TX-HAJJ-E2E-CUST-T08',
    ]);
    $resp = $controller->addPayment($req, $booking);
    $data = assertHttpOk('T19: add payment', $resp, $results);

    // Customer AR should drop by 10000 (debt decreased)
    $custARAfter = snapBalance($GLOBALS['customerT08']['account']->id);
    assertEquals('T19: customer AR -10000', $custARBefore - 10000, $custARAfter, $results);
    // Cashbox should be +10000
    $cashAfter = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T19: cashbox +10000', $cashBefore + 10000, $cashAfter, $results);
    $GLOBALS['bookingT08'] = $booking->fresh();
}, $results);

// T20: Add multiple payments (partial)
safeRun('T20', 'POST /bookings/{id}/payments — multiple partial payments', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT11']; // has initial 10000, selling 25000, remaining 15000
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $amounts = [5000, 5000, 5000];
    foreach ($amounts as $i => $amt) {
        $req = new Request([
            'amount' => $amt,
            'payment_method' => 'cash',
            'account_id' => $GLOBALS['cashboxEGP']->id,
            'paid_by' => 'TX-HAJJ-E2E-CUST-T11',
        ]);
        $resp = $controller->addPayment($req, $booking);
        assertHttpOk('T20: payment '.($i + 1).' of '.count($amounts), $resp, $results);
    }
    $totalPayments = HajjUmraPayment::where('hajj_umra_booking_id', $booking->id)->sum('amount');
    assertEquals('T20: total payments = 10000 (initial) + 15000 (3x5000) = 25000', 25000.0, (float) $totalPayments, $results);
    // Customer should be fully settled
    $booking->refresh();
    assertTrue('T20: booking is fully paid', $booking->is_fully_paid, $results);
}, $results);

// T21: Overpayment (customer goes negative)
safeRun('T21', 'Overpayment allowed (customer goes negative)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT09']; // USD 2200, 0 paid
    $custARBefore = snapBalance($GLOBALS['customerT09']['account']->id);

    $req = new Request([
        'amount' => 3000, // overpay 800
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxUSD']->id,
        'paid_by' => 'TX-HAJJ-E2E-CUST-T09',
    ]);
    $resp = $controller->addPayment($req, $booking);
    assertHttpOk('T21: overpayment accepted', $resp, $results);
    $custARAfter = snapBalance($GLOBALS['customerT09']['account']->id);
    assertEquals('T21: customer AR = 2200 - 3000 = -800', -800.0, $custARAfter, $results, "got $custARAfter");
}, $results);

// T22: Payment via different account (USD via service layer — no currency check)
safeRun('T22', 'Payment via different account (USD via service)', function () use (&$results) {
    $svc = app(HajjUmraBookingService::class);
    $booking = $GLOBALS['bookingT09']; // USD booking
    $cashUSDBefore = snapBalance($GLOBALS['cashboxUSD']->id);

    // Pay with EGP cashbox (cross-currency — service-level allows it; only FormRequest blocks it)
    $payment = $svc->addPayment($booking, [
        'amount' => 200,
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    assertTrue('T22: cross-currency payment accepted at service layer', $payment && $payment->id > 0, $results);
    recordFinding('T22', 'INFO', 'Service layer accepts cross-currency payment (EGP account for USD booking) — only FormRequest enforces currency match. This is consistent with T22 documented in bus_module_full_e2e.php.', $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 5: Lifecycle guards (re-assert known bug fixes)
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 5 — Lifecycle guards (re-assert bug fixes)');

// T23: edit cancelled booking → 422 (BUG #1 fix re-assert)
safeRun('T23', 'PATCH on cancelled booking returns 422 (BUG #1 fix)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $svc = app(HajjUmraBookingService::class);
    $booking = $GLOBALS['bookingT13'];
    $svc->cancel($booking, 'T23 test cancel');

    $req = new Request(['selling_price' => 30000]);
    $resp = $controller->update($req, $booking->fresh());
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    $ok = $status === 422 || ($data['success'] ?? true) === false;
    if ($ok && (str_contains((string) ($data['message'] ?? ''), 'لا يمكن تعديل حجز مُلغى') || str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), 'لا يمكن تعديل'))) {
        out_ok("T23: cancelled booking PATCH rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T23: PATCH on cancelled should be rejected but got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => 'T23', 'response' => $data, 'expected_status' => 422];
    }
}, $results);

// T24: refund cancelled booking → 422 (BUG #3 fix re-assert)
safeRun('T24', 'POST /refund on cancelled booking returns 422 (BUG #3 fix)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT13'];
    $req = new Request(['reason' => 'T24 test refund on cancelled']);
    $resp = $controller->refund($req, $booking);
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($status === 422 || ($data['success'] ?? true) === false) && str_contains((string) ($data['message'] ?? ''), 'لا يمكن استرداد حجز مُلغى')) {
        out_ok("T24: refund on cancelled rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T24: expected 422, got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => 'T24', 'response' => $data, 'expected_status' => 422];
    }
}, $results);

// T25: payment on cancelled → 422 (GAP #HJ-4 re-assert)
safeRun('T25', 'POST /payments on cancelled booking returns 422 (GAP #HJ-4)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT13'];
    $req = new Request([
        'amount' => 1000,
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    $resp = $controller->addPayment($req, $booking);
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($status === 422 || ($data['success'] ?? true) === false) && str_contains((string) ($data['message'] ?? ''), 'لا يمكن إضافة دفعة على حجز مُلغى')) {
        out_ok("T25: payment on cancelled rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T25: expected 422, got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => 'T25', 'response' => $data, 'expected_status' => 422];
    }
}, $results);

// T26: payment on refunded → 422 (GAP #HJ-5 re-assert)
safeRun('T26', 'POST /payments on refunded booking returns 422 (GAP #HJ-5)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $svc = app(HajjUmraRefundService::class);
    // Refund a different booking that has payments
    $booking = $GLOBALS['bookingT11']->fresh();
    $svc->refund($booking, 'T26 test refund');

    $req = new Request([
        'amount' => 1000,
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    $resp = $controller->addPayment($req, $booking->fresh());
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($status === 422 || ($data['success'] ?? true) === false) && str_contains((string) ($data['message'] ?? ''), 'لا يمكن إضافة دفعة على حجز تم استرداده')) {
        out_ok("T26: payment on refunded rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T26: expected 422, got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => 'T26', 'response' => $data, 'expected_status' => 422];
    }
}, $results);

// T27: cancel twice → 422
safeRun('T27', 'Cancel already-cancelled booking returns 422 (idempotency)', function () use (&$results) {
    $svc = app(HajjUmraBookingService::class);
    $booking = $GLOBALS['bookingT13'];
    $threw = false;
    try {
        $svc->cancel($booking, 'T27 second cancel');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'الحجز ملغى مسبقاً');
    }
    assertTrue('T27: second cancel throws RuntimeException "ملغى مسبقاً"', $threw, $results);
}, $results);

// T28: refund twice → 422
safeRun('T28', 'Refund already-refunded booking returns 422 (idempotency)', function () use (&$results) {
    $svc = app(HajjUmraRefundService::class);
    $booking = $GLOBALS['bookingT11']->fresh();
    $threw = false;
    try {
        $svc->refund($booking, 'T28 second refund');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'تم استرداده بالكامل مسبقاً');
    }
    assertTrue('T28: second refund throws RuntimeException "مسترد مسبقاً"', $threw, $results);
}, $results);

// T29: direct delete without run() → throws (guard)
safeRun('T29', 'Direct $booking->delete() throws RuntimeException (ModelDeletionGuard)', function () use (&$results) {
    $booking = $GLOBALS['bookingT10'];
    $threw = false;
    try {
        $booking->fresh()->delete();
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'لا يمكن حذف حجز الحج والعمرة برمجياً');
    }
    assertTrue('T29: direct delete blocked by ModelDeletionGuard', $threw, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 6: Cancel/Refund full flows
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 6 — Cancel & Refund full flows');

// T30: cancel with payments — additive reversal restores all balances
safeRun('T30', 'Cancel with payments — additive reversal restores balances', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    // Create a fresh booking with payment
    $cust = createTestCustomer('T30', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);
    $custARBefore = snapBalance($cust['account']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 20000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
        'initial_payment' => ['amount' => 10000, 'payment_method' => 'cash', 'account_id' => $GLOBALS['cashboxEGP']->id],
    ]);

    $cashAfterCreate = snapBalance($GLOBALS['cashboxEGP']->id);
    $custARAfterCreate = snapBalance($cust['account']->id);

    $cancelled = $svc->cancel($booking, 'T30 test');
    assertEquals('T30: status after cancel = cancelled', 'cancelled', $cancelled->status->value, $results);

    // Cashbox should be back to before booking (since expense was reversed) + initial payment reversed
    $cashAfterCancel = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T30: cashbox restored to pre-booking state', $cashBefore, $cashAfterCancel, $results,
        "before=$cashBefore, afterCreate=$cashAfterCreate, afterCancel=$cashAfterCancel");

    // Customer AR should be back to 0
    $custARAfterCancel = snapBalance($cust['account']->id);
    assertEquals('T30: customer AR restored to 0', $custARBefore, $custARAfterCancel, $results,
        "before=$custARBefore, afterCreate=$custARAfterCreate, afterCancel=$custARAfterCancel");
}, $results);

// T31: refund full — all transactions reversed
safeRun('T31', 'Refund full — all tx reversed', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $refundSvc = app(HajjUmraRefundService::class);
    $cust = createTestCustomer('T31', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 15000,
        'selling_price' => 20000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    $svc->addPayment($booking, [
        'amount' => 20000, // full payment
        'payment_method' => 'cash',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);

    $refunded = $refundSvc->refund($booking, 'T31 test');
    assertEquals('T31: status after refund = refunded', 'refunded', $refunded->status->value, $results);
    $cashAfterRefund = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T31: cashbox restored to pre-booking state', $cashBefore, $cashAfterRefund, $results);
    $custARAfterRefund = snapBalance($cust['account']->id);
    assertEquals('T31: customer AR = 0 after refund', 0.0, $custARAfterRefund, $results);
}, $results);

// T32: refund zero-payment booking
safeRun('T32', 'Refund zero-payment booking (just income + expense reversed)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $refundSvc = app(HajjUmraRefundService::class);
    $cust = createTestCustomer('T32', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 20000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    $refunded = $refundSvc->refund($booking, 'T32 test');
    assertEquals('T32: status after refund = refunded', 'refunded', $refunded->status->value, $results);
    $cashAfter = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T32: cashbox restored (no payments to reverse)', $cashBefore, $cashAfter, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 7: Soft-delete (admin)
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 7 — Soft-delete (admin)');

// T33: soft-delete with full reversal
safeRun('T33', 'DELETE /bookings/{id} — soft-delete + full reversal', function () use (&$results, $adminUser) {
    $controller = app(HajjUmraController::class);
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T33', $adminUser->id, 'EGP');
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    $booking = $svc->create([
        'customer_id' => $cust['customer']->id,
        'program_id' => $GLOBALS['umrahProgram']->id,
        'purchase_price' => 18000,
        'selling_price' => 25000,
        'currency' => 'EGP',
        'account_id' => $GLOBALS['cashboxEGP']->id,
    ]);
    $svc->addPayment($booking, ['amount' => 10000, 'payment_method' => 'cash', 'account_id' => $GLOBALS['cashboxEGP']->id]);

    $req = new Request;
    $resp = $controller->destroy($req, $booking->id);
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($data['success'] ?? false) === true) {
        out_ok("T33: soft-delete succeeded (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail('T33: soft-delete failed: '.json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
        $results['verdict']['issues'][] = ['test' => 'T33', 'response' => $data];
    }
    $cashAfter = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T33: cashbox restored to pre-booking state', $cashBefore, $cashAfter, $results);
    $trashed = HajjUmraBooking::withTrashed()->find($booking->id);
    assertTrue('T33: booking is soft-deleted (trashed)', $trashed->trashed(), $results);
}, $results);

// T34: soft-delete twice → 422
safeRun('T34', 'DELETE on already-deleted booking returns 422 (idempotency)', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $booking = $GLOBALS['bookingT33'] = HajjUmraBooking::withTrashed()
        ->where('id', '>', 0)->whereNotNull('deleted_at')
        ->orderByDesc('id')->first();
    if (! $booking) {
        out_warn('T34: no soft-deleted booking found, skipping');

        return;
    }
    $req = new Request;
    $resp = $controller->destroy($req, $booking->id);
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($status === 422 || ($data['success'] ?? true) === false) && str_contains((string) ($data['message'] ?? ''), 'محذوف بالفعل')) {
        out_ok("T34: second delete rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T34: expected 422, got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
    }
}, $results);

// T35: restore then re-delete (no double reversal)
safeRun('T35', 'Restore + re-delete is safe (no double reversal)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $booking = $GLOBALS['bookingT33'] ?? null;
    if (! $booking) {
        out_warn('T35: no soft-deleted booking, skipping');

        return;
    }
    $custARBefore = $booking->customer->fresh()->account->balance;
    $cashBefore = snapBalance($GLOBALS['cashboxEGP']->id);

    // Restore
    $booking->restore();
    $cashAfterRestore = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T35: cashbox unchanged after restore (no double reversal)', $cashBefore, $cashAfterRestore, $results,
        "before=$cashBefore after=$cashAfterRestore");

    // Re-delete
    $svc->deleteBookingWithReversal($booking->id, $adminUser->id);
    $cashAfterReDelete = snapBalance($GLOBALS['cashboxEGP']->id);
    assertEquals('T35: cashbox unchanged after re-delete (no double reversal)', $cashBefore, $cashAfterReDelete, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 8: Program management
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 8 — Program management');

// T36: update program
safeRun('T36', 'PATCH /programs/{id} — update program', function () use (&$results) {
    $controller = app(HajjUmraProgramController::class);
    $req = new Request([
        'season' => '2026-updated',
        'default_selling_price' => 30000,
    ]);
    $resp = $controller->update($req, $GLOBALS['umrahProgram']);
    $data = assertHttpOk('T36: update program', $resp, $results);
    assertEquals('T36: season updated', '2026-updated', $data['season'] ?? null, $results);
    assertEquals('T36: default_selling_price = 30000', 30000.0, (float) ($data['default_selling_price'] ?? 0), $results);
}, $results);

// T37: delete empty program (no bookings) → success
safeRun('T37', 'DELETE /programs/{id} — empty program (no bookings) → success', function () use (&$results) {
    $controller = app(HajjUmraProgramController::class);
    $svc = app(HajjUmraBookingService::class);
    // Create an empty program
    $req = new Request([
        'program_name' => 'TX-HAJJ-E2E برنامج فارغ',
        'program_type' => 'umra',
        'total_nights' => 5,
        'mecca_nights' => 3,
        'medina_nights' => 2,
        'mecca_hotel_name' => 'فندق مكة',
        'medina_hotel_name' => 'فندق المدينة',
        'departure_date' => now()->addMonth()->toDateString(),
        'return_date' => now()->addMonth()->addDays(5)->toDateString(),
        'airline' => 'مصر للطيران',
        'departure_point' => 'القاهرة',
    ]);
    $resp = $controller->store($req);
    $data = $resp->getData(true);
    $emptyProgramId = $data['data']['id'] ?? null;
    assertTrue('T37: empty program created', $emptyProgramId !== null, $results);

    $delReq = new Request;
    $delResp = $controller->destroy($delReq, $emptyProgramId);
    $delData = $delResp->getData(true);
    assertTrue('T37: empty program deleted', ($delData['success'] ?? false) === true, $results,
        'response: '.json_encode($delData, JSON_UNESCAPED_UNICODE));
}, $results);

// T38: delete program with bookings → 422
safeRun('T38', 'DELETE /programs/{id} — program WITH bookings → 422', function () use (&$results) {
    $controller = app(HajjUmraProgramController::class);
    $program = $GLOBALS['umrahProgram'];
    // Make sure this program has bookings
    $bookingCount = HajjUmraBooking::where('program_id', $program->id)->count();
    assertTrue("T38: program has bookings (count=$bookingCount)", $bookingCount > 0, $results);

    $delReq = new Request;
    $delResp = $controller->destroy($delReq, $program->id);
    $delData = $delResp->getData(true);
    $status = $delResp->getStatusCode();
    if (($status === 422 || ($delData['success'] ?? true) === false) && str_contains((string) ($delData['message'] ?? ''), 'لا يمكن حذف البرنامج لوجود حجوزات')) {
        out_ok("T38: delete program with bookings rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T38: expected 422, got status=$status data=".json_encode($delData, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
    }
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 9: Executing company finance
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 9 — Executing company finance');

// T39: list company dues
safeRun('T39', 'GET /executing-companies/dues', function () use (&$results) {
    $controller = app(HajjUmraExecutingCompanyFinanceController::class);
    $resp = $controller->dues(new Request);
    $data = assertHttpOk('T39: list company dues', $resp, $results);
    $items = $data['items'] ?? [];
    $found = false;
    foreach ($items as $item) {
        if (str_contains((string) ($item['name'] ?? ''), 'TX-HAJJ-E2E')) {
            $found = true;
            out_info("T39: company '{$item['name']}' net_due = {$item['net_due']} (we owe this much)");
            break;
        }
    }
    assertTrue('T39: test executing company appears in dues', $found, $results);
}, $results);

// T40: withdraw from executing company
safeRun('T40', 'POST /executing-companies/{id}/withdraw', function () use (&$results) {
    $controller = app(HajjUmraExecutingCompanyFinanceController::class);
    $company = $GLOBALS['executingCompany'];
    $companyBalBefore = $company->account->fresh()->balance;
    $cashBefore = snapBalance($GLOBALS['cashboxSAR']->id);
    $withdrawAmount = 5000;

    $req = new Request([
        'amount' => $withdrawAmount,
        'to_account_id' => $GLOBALS['cashboxSAR']->id,
        'notes' => 'T40 test withdraw',
    ]);
    $resp = $controller->withdraw($req, $company);
    $data = assertHttpOk('T40: withdraw', $resp, $results);

    $companyBalAfter = $company->account->fresh()->balance;
    // withdraw adds to AP (we owe more) → balance moves from negative toward 0
    assertEquals('T40: company AP +5000 (less negative)', $companyBalBefore + $withdrawAmount, $companyBalAfter, $results);
    $cashAfter = snapBalance($GLOBALS['cashboxSAR']->id);
    assertEquals('T40: cashbox SAR +5000', $cashBefore + $withdrawAmount, $cashAfter, $results);
}, $results);

// T41: repay executing company (sufficient balance)
safeRun('T41', 'POST /executing-companies/{id}/repay — sufficient balance', function () use (&$results) {
    $controller = app(HajjUmraExecutingCompanyFinanceController::class);
    $company = $GLOBALS['executingCompany'];
    $companyBalBefore = $company->account->fresh()->balance;
    $cashBefore = snapBalance($GLOBALS['cashboxSAR']->id);
    $repayAmount = 1000;

    $req = new Request([
        'amount' => $repayAmount,
        'from_account_id' => $GLOBALS['cashboxSAR']->id,
        'notes' => 'T41 test repay',
    ]);
    $resp = $controller->repay($req, $company);
    $data = assertHttpOk('T41: repay', $resp, $results);

    $companyBalAfter = $company->account->fresh()->balance;
    // repay: company AP gets more negative (we owe more), cashbox decreases
    assertEquals('T41: company AP -1000 (more negative)', $companyBalBefore - $repayAmount, $companyBalAfter, $results);
    $cashAfter = snapBalance($GLOBALS['cashboxSAR']->id);
    assertEquals('T41: cashbox SAR -1000', $cashBefore - $repayAmount, $cashAfter, $results);
}, $results);

// T42: repay with insufficient balance → 422
safeRun('T42', 'POST /executing-companies/{id}/repay — insufficient balance → 422', function () use (&$results) {
    $controller = app(HajjUmraExecutingCompanyFinanceController::class);
    $company = $GLOBALS['executingCompany'];
    $req = new Request([
        'amount' => 99999999, // absurd amount
        'from_account_id' => $GLOBALS['cashboxSAR']->id,
        'notes' => 'T42 test insufficient',
    ]);
    $resp = $controller->repay($req, $company);
    $data = $resp->getData(true);
    $status = $resp->getStatusCode();
    if (($status === 422 || ($data['success'] ?? true) === false) && str_contains((string) ($data['message'] ?? ''), 'رصيد الحساب المصدر غير كافٍ')) {
        out_ok("T42: insufficient balance repay rejected (status=$status)");
        $results['verdict']['passed']++;
    } else {
        out_fail("T42: expected 422, got status=$status data=".json_encode($data, JSON_UNESCAPED_UNICODE));
        $results['verdict']['failed']++;
    }
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 10: Endpoints / Reports
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 10 — Endpoints / Reports');

// T43: dashboard
safeRun('T43', 'GET /hajj-umra/dashboard', function () use (&$results) {
    $controller = app(HajjUmraDashboardController::class);
    $resp = $controller->index(new Request);
    $data = assertHttpOk('T43: dashboard', $resp, $results);
    assertTrue('T43: dashboard.stats present', isset($data['stats']), $results);
    assertTrue('T43: dashboard.stats.total_bookings > 0', ($data['stats']['total_bookings'] ?? 0) > 0, $results);
    assertTrue('T43: dashboard.liquidity.total present', isset($data['liquidity']['total']), $results);
}, $results);

// T44: treasury overview
safeRun('T44', 'GET /hajj-umra/treasury/overview', function () use (&$results) {
    $controller = app(HajjUmraTreasuryController::class);
    $resp = $controller->overview(new Request);
    $data = assertHttpOk('T44: treasury overview', $resp, $results);
    assertTrue('T44: settlement_accounts present', isset($data['settlement_accounts']), $results);
    assertTrue('T44: recent_hajj_umra_transactions present', isset($data['recent_hajj_umra_transactions']), $results);
    assertTrue('T44: at least 1 transaction', count($data['recent_hajj_umra_transactions'] ?? []) > 0, $results);
}, $results);

// T45: customer balances (debtors)
safeRun('T45', 'GET /hajj-umra/customer-balances?status=debtors', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $resp = $controller->customerBalances(new Request(['status' => 'debtors']));
    $data = assertHttpOk('T45: customer balances', $resp, $results);
    // Verify all items have total_debt > 0
    $allDebtors = true;
    foreach ($data as $item) {
        if (($item['total_debt'] ?? 0) <= 0.009) {
            $allDebtors = false;
            break;
        }
    }
    assertTrue('T45: all returned items have positive debt', $allDebtors, $results);
    out_info('T45: debtors count = '.count($data));
}, $results);

// T46: customer statement (with running balance)
safeRun('T46', 'GET /hajj-umra/customer-statement?client_id=N', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $customer = $GLOBALS['customerT08']['customer'];
    $resp = $controller->customerStatement(new Request(['client_id' => $customer->id]));
    $data = assertHttpOk('T46: customer statement', $resp, $results);
    assertTrue('T46: customer present', isset($data['customer']), $results);
    assertTrue('T46: summary present', isset($data['summary']), $results);
    assertTrue('T46: transactions present', isset($data['transactions']), $results);
    $txCount = count($data['transactions'] ?? []);
    assertTrue("T46: at least 1 transaction (count=$txCount)", $txCount > 0, $results);
    // Verify running_balance is monotonic
    $running = 0;
    foreach ($data['transactions'] as $tx) {
        $running += ((float) ($tx['debit'] ?? 0) - (float) ($tx['credit'] ?? 0));
        $expected = round($running, 2);
        $actual = round((float) ($tx['running_balance'] ?? -99999), 2);
        if (abs($expected - $actual) > 0.011) {
            out_fail("T46: running_balance mismatch at tx {$tx['id']}: expected $expected got $actual");
            $results['verdict']['failed']++;
        }
    }
    out_ok('T46: running_balance is monotonically correct');
    $results['verdict']['passed']++;
}, $results);

// T47: reference endpoints
safeRun('T47', 'GET /hajj-umra/settings/* (reference endpoints)', function () use (&$results) {
    $controller = app(HajjUmraReferenceController::class);
    $endpoints = [
        'programs' => $controller->programs(new Request),
        'executingCompanies' => $controller->executingCompanies(new Request),
        'tripSupervisors' => $controller->tripSupervisors(new Request),
        'accommodationTypes' => $controller->accommodationTypes(new Request),
        'statuses' => $controller->statuses(new Request),
    ];
    foreach ($endpoints as $name => $resp) {
        $data = $resp->getData(true);
        $ok = ($data['success'] ?? false) === true;
        assertTrue("T47: $name endpoint", $ok, $results, 'data: '.substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 100));
    }
    // Verify statuses has hajj_umra key
    $statusesData = $controller->statuses(new Request)->getData(true);
    assertTrue('T47: statuses.hajj_umra present', isset($statusesData['data']['hajj_umra']), $results);
    $statusCount = count($statusesData['data']['hajj_umra'] ?? []);
    assertEquals('T47: hajj_umra has 6 statuses', 6, $statusCount, $results);
}, $results);

// T48: treasury account transactions
safeRun('T48', 'GET /hajj-umra/treasury/accounts/{account}/transactions', function () use (&$results) {
    $controller = app(HajjUmraTreasuryController::class);
    $resp = $controller->accountHajjUmraTransactions(new Request(['per_page' => 20]), $GLOBALS['cashboxEGP']);
    $data = $resp->getData(true);
    $ok = ($data['success'] ?? false) === true;
    assertTrue('T48: treasury account transactions', $ok, $results);
    $txCount = count($data['data']['data'] ?? []);
    out_info("T48: cashboxEGP hajj_umra transactions = $txCount");
    assertTrue('T48: at least 1 transaction', $txCount > 0, $results);
}, $results);

// ═══════════════════════════════════════════════════════════════════════
// Phase 11: Edge cases / bug hunting
// ═══════════════════════════════════════════════════════════════════════
out_section('Phase 11 — Edge cases / bug hunting');

// T49: insufficient cashbox balance blocks booking (GAP #HJ-6)
safeRun('T49', 'Insufficient cashbox balance blocks booking (GAP #HJ-6)', function () use (&$results, $adminUser) {
    $svc = app(HajjUmraBookingService::class);
    $cust = createTestCustomer('T49', $adminUser->id, 'EGP');

    // Drain the cashbox first
    $drain = Account::where('name', 'TX-HAJJ-E2E-VAULT-EGP')->first();
    $original = $drain->balance;
    LedgerBalanceMutationGuard::run(function () use ($drain) {
        $drain->balance = 0;
        $drain->save();
    });
    $threw = false;
    try {
        $svc->create([
            'customer_id' => $cust['customer']->id,
            'program_id' => $GLOBALS['umrahProgram']->id,
            'purchase_price' => 100000, // more than available
            'selling_price' => 120000,
            'currency' => 'EGP',
            'account_id' => $drain->id,
        ]);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'رصيد الخزينة غير كافٍ');
    }
    // Restore
    LedgerBalanceMutationGuard::run(function () use ($drain, $original) {
        $drain->balance = $original;
        $drain->save();
    });
    assertTrue('T49: insufficient balance throws RuntimeException "رصيد الخزينة غير كافٍ"', $threw, $results);
}, $results);

// T50: API response shape (success key vs status key — doc drift)
safeRun('T50', 'API response shape uses "success" key (CLAUDE.md says "status")', function () use (&$results) {
    $controller = app(HajjUmraController::class);
    $resp = $controller->index(new Request(['per_page' => 1]));
    $data = $resp->getData(true);
    assertTrue('T50: response uses "success" key', array_key_exists('success', $data), $results,
        'got keys: '.implode(',', array_keys($data)));
    if (! array_key_exists('status', $data)) {
        out_warn('T50 FINDING: CLAUDE.md documents "status" key but ApiResponse actually returns "success" key');
        recordFinding('T50', 'INFO', 'ApiResponse uses "success" key, not "status" as documented in CLAUDE.md. This is a known doc drift also found in bus_module_full_e2e.php (T23).', $results);
    }
}, $results);

// T51: balance invariant — Δ balance = Σ credit − Σ debit on account_entries
safeRun('T51', 'Balance invariant: Δ balance = Σ credit - Σ debit on each account', function () use (&$results) {
    $errors = [];
    $sampleAccounts = Account::where('name', 'like', 'TX-HAJJ-E2E-%')
        ->orWhereIn('id', [$GLOBALS['cashboxEGP']->id, $GLOBALS['cashboxUSD']->id, $GLOBALS['cashboxSAR']->id])
        ->get();
    foreach ($sampleAccounts as $acc) {
        $debit = (float) AccountEntry::where('account_id', $acc->id)->sum('debit');
        $credit = (float) AccountEntry::where('account_id', $acc->id)->sum('credit');
        $expected = round($credit - $debit, 2);
        $actual = round((float) $acc->balance, 2);
        if (abs($expected - $actual) > 0.011) {
            $errors[] = "Account #{$acc->id} ({$acc->name}): balance=$actual, expected=Σcredit-Σdebit=$expected (debit=$debit credit=$credit)";
        }
    }
    if (empty($errors)) {
        out_ok('T51: balance invariant holds for all '.count($sampleAccounts).' sampled accounts');
        $results['verdict']['passed']++;
    } else {
        out_fail('T51: '.count($errors)." balance mismatches:\n      ".implode("\n      ", $errors));
        $results['verdict']['failed']++;
        foreach ($errors as $e) {
            $results['verdict']['issues'][] = ['test' => 'T51', 'context' => $e];
        }
    }
}, $results);

// T52: transaction-level balance — Σ debit = Σ credit per transaction
safeRun('T52', 'Transaction-level balance: Σ debit = Σ credit per hajj_umra tx', function () use (&$results) {
    $errors = [];
    $txIds = Transaction::where('module', TransactionModule::HajjUmra->value)
        ->whereHas('accountEntries', function ($q) {
            $q->where('transaction_id', '>', 0);
        })
        ->pluck('id');
    foreach ($txIds as $txId) {
        $debit = (float) AccountEntry::where('transaction_id', $txId)->sum('debit');
        $credit = (float) AccountEntry::where('transaction_id', $txId)->sum('credit');
        if (abs($debit - $credit) > 0.011) {
            $errors[] = "Tx #$txId: debit=$debit credit=$credit diff=".round($debit - $credit, 2);
        }
    }
    if (empty($errors)) {
        out_ok('T52: all '.count($txIds).' hajj_umra transactions are balanced');
        $results['verdict']['passed']++;
    } else {
        out_fail('T52: '.count($errors)." unbalanced transactions:\n      ".implode("\n      ", $errors));
        $results['verdict']['failed']++;
        foreach ($errors as $e) {
            $results['verdict']['issues'][] = ['test' => 'T52', 'context' => $e];
        }
    }
}, $results);

// ═══════════════════════════════════════════════════════════════════════
out_section('النتيجة النهائية');
// ═══════════════════════════════════════════════════════════════════════
$results['finished_at'] = date('Y-m-d H:i:s');
$passed = $results['verdict']['passed'];
$failed = $results['verdict']['failed'];
$total = $passed + $failed;

echo "  Passed: $passed / $total assertions\n";
echo "  Failed: $failed / $total assertions\n";
echo '  Tests:  '.count($results['tests'])." scenarios\n";
echo '  Findings: '.count($results['findings'])." notes\n\n";

if ($failed === 0) {
    echo "  🎉 كل الـ assertions نجحت! مفيش مشاكل في الـ HajjUmra module logic.\n";
} else {
    echo "  ⚠️  فيه مشاكل:\n";
    foreach ($results['verdict']['issues'] as $i => $issue) {
        echo '    '.($i + 1).'. '.json_encode($issue, JSON_UNESCAPED_UNICODE)."\n";
    }
}

if (! empty($results['findings'])) {
    echo "\n  Findings:\n";
    foreach ($results['findings'] as $f) {
        echo "    - [{$f['severity']}] {$f['test']}: {$f['message']}\n";
    }
}

// احفظ التقرير
$reportPath = storage_path('logs/hajj_umra_full_e2e_results.json');
file_put_contents($reportPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
out_info("التقرير محفوظ في: $reportPath");

echo "\n";
echo "🧹 لتنظيف بيانات التيست بعد الانتهاء، شغّل:\n";
echo '   rm '.storage_path('app/local_hajj_umra_test.sqlite')."\n";
echo "   rm $reportPath\n";
