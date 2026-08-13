<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Office Liquidity Snapshot — Read-only Reconnaissance
 * ════════════════════════════════════════════════════════════════════════════
 *
 * المرحلة 0 من خطة إصلاح الحسابات:
 *   يجيب كل الخزن النقدية والبنوك والمحافظ الإلكترونية في قسم المكتب
 *   (accounts.module_type = 'office') ويعرض رصيدها المخزّن الحالي.
 *
 * الغرض:
 *   - نعدّ الـ liquidity accounts اللي هنراجع أرصدتها في المراحل الجاية.
 *   - الـ output ده هو نقطة البداية لكل خطوات الـ reconciliation.
 *
 * ملاحظات:
 *   - READ-ONLY فقط. لا يتم تعديل أي شيء في الـ DB.
 *   - نوع 'treasury' القديم تم حذفه من الـ ENUM (Phase 3.5b cleanup)
 *     فالـ filter يشمل cashbox / bank / wallet فقط (مع backup type='treasury'
 *     احتياطياً لو في قديم لسه في الـ DB).
 *
 * التشغيل:
 *   cd C:\travile\SafarakEalayna
 *   php scripts/office_liquidity_snapshot.php
 *
 * المخرجات:
 *   - جدول منسق على الـ stdout
 *   - JSON في storage/logs/office_liquidity_snapshot.json
 */

if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

use App\Models\Account;
use Illuminate\Support\Facades\DB;

// ─── Output helpers ───
function out_info(string $m): void { echo "    ℹ  $m\n"; }
function out_warn(string $m): void { echo "    ⚠  $m\n"; }
function out_line(): void { echo "\n" . str_repeat('─', 78) . "\n"; }
function out_section(string $name): void {
    echo "\n" . str_repeat('═', 78) . "\n";
    echo "  $name\n";
    echo str_repeat('═', 78) . "\n";
}

// ─── Banner ───
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  Office Liquidity Snapshot — مرحلة 0 من إصلاح حسابات المكتب        ║\n";
echo "║  (Read-only · آمن على البرودكشن)                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// ─── DB info ───
$conn = DB::connection()->getName();
$driver = DB::connection()->getDriverName();
$dbName = DB::connection()->getDatabaseName();
out_info("DB connection : $conn ($driver)");
out_info("DB name       : $dbName");
out_info("Run at        : " . date('Y-m-d H:i:s'));
out_line();

// ─── Query: كل accounts من نوع liquidity في المكتب ───
$accounts = DB::table('accounts')
    ->where(function ($q) {
        $q->whereIn('type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('type', 'treasury'); // احتياطياً — قديم
    })
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->orderBy('type')
    ->orderBy('currency')
    ->orderBy('name')
    ->get([
        'id',
        'name',
        'type',
        'treasury_type',
        'bank_name',
        'account_number',
        'currency',
        'balance',
        'is_active',
        'is_module_vault',
        'created_at',
        'updated_at',
    ]);

// ─── Summary counts by type ───
$byType = $accounts->groupBy('type')->map->count();
$totalBalanceByCurrency = $accounts->groupBy('currency')->map(function ($rows) {
    return round($rows->sum('balance'), 2);
});

out_section('ملخص حسب النوع');
foreach (['cashbox', 'bank', 'wallet', 'treasury'] as $t) {
    $count = $byType[$t] ?? 0;
    if ($count > 0) {
        $label = match ($t) {
            'cashbox' => 'خزن نقدي (Cashbox)',
            'bank' => 'حسابات بنكية (Bank)',
            'wallet' => 'محافظ إلكترونية (Wallet)',
            'treasury' => 'خزينة قديمة (Treasury — legacy)',
            default => $t,
        };
        printf("    %-30s : %d\n", $label, $count);
    }
}
printf("    %-30s : %d\n", 'الإجمالي', $accounts->count());
out_line();

out_section('ملخص حسب العملة (إجمالي الرصيد المخزّن)');
foreach ($totalBalanceByCurrency as $cur => $sum) {
    printf("    %-10s : %12.2f\n", $cur, $sum);
}
out_line();

// ─── Detail table ───
out_section('تفاصيل كل الـ accounts في المكتب');

if ($accounts->isEmpty()) {
    out_warn('لا توجد حسابات liquidity في قسم المكتب.');
    echo "\n";
    exit(0);
}

// حساب عرض الأعمدة
$idW = max(4, strlen('ID'));
$nameW = max(20, $accounts->max(fn($a) => mb_strlen($a->name)));
$typeW = 10;
$curW = 8;
$balW = 16;
$actW = 7;

$totalW = $idW + $nameW + $typeW + $curW + $balW + $actW + 9;
echo "\n";
printf(
    "  %-{$idW}s | %-{$nameW}s | %-{$typeW}s | %-{$curW}s | %{$balW}s | %-{$actW}s\n",
    'ID', 'Name', 'Type', 'Curr', 'Stored Balance', 'Active'
);
echo str_repeat('─', $totalW + 2) . "\n";

foreach ($accounts as $acc) {
    $balStr = number_format((float) $acc->balance, 2);
    $balColored = $acc->balance < 0
        ? "\033[31m$balStr\033[0m"
        : ($acc->balance == 0 ? "\033[90m$balStr\033[0m" : "\033[32m$balStr\033[0m");

    printf(
        "  %-{$idW}d | %-{$nameW}s | %-{$typeW}s | %-{$curW}s | %{$balW}s | %-{$actW}s\n",
        $acc->id,
        mb_substr($acc->name, 0, $nameW),
        $acc->type,
        $acc->currency ?? '-',
        $balColored,
        $acc->is_active ? 'Yes' : 'No'
    );
}
echo str_repeat('─', $totalW + 2) . "\n";
echo "  (الأخضر = رصيد موجب · الأحمر = رصيد سالب · الرمادي = صفر)\n";
echo "\n";

// ─── Save JSON snapshot ───
$jsonPath = storage_path('logs/office_liquidity_snapshot.json');
$snapshot = [
    'metadata' => [
        'script' => 'office_liquidity_snapshot.php',
        'phase' => '0 — Reconnaissance',
        'run_at' => date('Y-m-d H:i:s'),
        'db_connection' => $conn,
        'db_name' => $dbName,
        'filter' => [
            'types' => ['cashbox', 'bank', 'wallet', 'treasury (legacy)'],
            'module_type' => 'office',
            'deleted_at' => 'IS NULL',
        ],
    ],
    'summary' => [
        'total_accounts' => $accounts->count(),
        'by_type' => $byType->toArray(),
        'total_balance_by_currency' => $totalBalanceByCurrency->toArray(),
    ],
    'accounts' => $accounts->map(fn($a) => [
        'id' => $a->id,
        'name' => $a->name,
        'type' => $a->type,
        'treasury_type' => $a->treasury_type,
        'bank_name' => $a->bank_name,
        'account_number' => $a->account_number,
        'currency' => $a->currency,
        'stored_balance' => (float) $a->balance,
        'is_active' => (bool) $a->is_active,
        'is_module_vault' => (bool) $a->is_module_vault,
        'created_at' => $a->created_at,
        'updated_at' => $a->updated_at,
    ])->toArray(),
];

file_put_contents(
    $jsonPath,
    json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

out_section('الخطوة الجاية');
out_info("JSON snapshot saved: $jsonPath");
out_info("عدد الـ accounts: {$accounts->count()}");
echo "\n";
out_warn('المرحلة 0 READ-ONLY — لم يتم تعديل أي شيء في الـ DB.');
echo "\n";
out_section('ماذا بعد؟');
echo "  1) راجع الجدول فوق. تأكد إن كل الخزن والبنوك والمحافظ ظاهرة.\n";
echo "  2) راجع الـ JSON في storage/logs/office_liquidity_snapshot.json\n";
echo "  3) لما توافق، هنبدأ المرحلة 1:\n";
echo "     → لكل account، نحسب الرصيد الفعلي من account_entries\n";
echo "     → نقارنه بالـ stored balance\n";
echo "     → نسجل الفروقات (drift) في office_liquidity_drift.json\n";
echo "\n";
