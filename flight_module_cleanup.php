<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Comprehensive Test Data Cleanup (v3)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يستخدم SET FOREIGN_KEY_CHECKS = 0 لتنظيف شامل وسريع
 * ⚠️ للاستخدام في بيئة التيست فقط (production = risk)
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_cleanup.log', 'w');
function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { t("    ✅ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function warn(string $m) { t("    ⚠  {$m}"); }

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Cleanup v3 (FK checks disabled)          ║");
t("║  ⚠️  TEST ENVIRONMENT ONLY                                 ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// 1) قبل
$before = [
    'transactions' => DB::table('transactions')->count(),
    'flight_bookings' => DB::table('flight_bookings')->count(),
    'passengers' => DB::table('passengers')->count(),
    'airline_transactions' => DB::table('airline_transactions')->count(),
    'accounts' => DB::table('accounts')->count(),
    'transfers' => DB::table('transfers')->count(),
];
t("\n═══ 1) قبل الحذف ═══");
foreach ($before as $t => $c) info("$t: $c");

// 2) تعطيل FK checks + تنظيف
t("\n═══ 2) تعطيل FK checks وتنظيف ═══");
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
ok('FK checks disabled');

$deleted = [];
$tables_to_clean = [
    // البيانات المالية
    'account_entries'           => 'سجلات الأرصدة',
    'airline_credits'           => 'أرصدة شركات الطيران',
    'airline_transactions'      => 'حركات شركات الطيران',
    'flight_system_transactions' => 'حركات أنظمة الحجز',
    'flight_group_transactions' => 'حركات المجموعات',
    'transactions'              => 'كل المعاملات',
    'transfers'                 => 'التحويلات',
    'treasury_transactions'     => 'حركات الخزينة',
    'ledger_reconciliation_findings' => 'نتائج التسوية',
    'refund_requests'           => 'طلبات الاسترداد',

    // بيانات الطيران
    'flight_refunds'            => 'استردادات الطيران',
    'flight_pricing'            => 'تسعير الطيران',
    'flight_pricings'           => 'تسعير (plural)',
    'flight_payments'           => 'مدفوعات الطيران',
    'flight_tickets'            => 'تذاكر الطيران',
    'flight_segments'           => 'قطاعات الرحلة',
    'passengers'                => 'الركاب',
    'ticket_modifications'      => 'تعديلات التذاكر',
    'flight_bookings'           => 'الحجوزات',

    // الـ master data للتيست
    'flight_groups'             => 'مجموعات الطيران (FLT-TEST)',
    'flight_carriers'           => 'شركات الطيران (FLT-TEST)',
    'flight_systems'            => 'أنظمة الحجز (FLT-TEST)',
    'airline_accounts'          => 'حسابات شركات (FLT-TEST)',
    'airports'                  => 'المطارات (T*)',
    'customers'                 => 'العملاء (TEST_*)',
    'employees'                 => 'الموظفين (TEST_*)',
    'accounts'                  => 'الحسابات (FLT-TEST)',
    'treasuries'                => 'الخزائن (FLT-TEST)',
    'currencies'                => 'العملات',
    'exchange_rates'            => 'أسعار الصرف',
];

try {
    foreach ($tables_to_clean as $table => $desc) {
        // تخطي الجداول غير الموجودة
        try {
            $count = DB::table($table)->count();
        } catch (\Throwable $e) {
            continue;
        }
        if ($count == 0) continue;

        // حذف شروط خاصة (FLT-TEST فقط مثلاً)
        if (in_array($table, ['flight_groups', 'flight_carriers', 'flight_systems', 'airline_accounts', 'airports', 'customers', 'employees', 'accounts', 'treasuries'])) {
            if (in_array($table, ['flight_groups', 'flight_carriers', 'flight_systems', 'airline_accounts', 'airports', 'airports'])) {
                if ($table == 'airports') {
                    $count = DB::table($table)->whereIn('iata_code', ['TCAI', 'TJED', 'TRUH', 'TDXB', 'TKWI', 'DELX'])->delete();
                } else {
                    $count = DB::table($table)->where('name', 'like', 'FLT-TEST-%')->delete();
                }
            } elseif (in_array($table, ['customers', 'employees'])) {
                $count = DB::table($table)->where('full_name', 'like', 'TEST_%')->delete();
            } elseif (in_array($table, ['accounts', 'treasuries'])) {
                $count = DB::table($table)->where('name', 'like', 'FLT-TEST-%')->delete();
            } else {
                $count = DB::table($table)->delete();
            }
        } else {
            $count = DB::table($table)->delete();
        }
        $deleted[$table] = $count;
        info("$table ($desc): $count");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    ok('FK checks re-enabled');

} catch (\Throwable $e) {
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    warn('ERROR: ' . $e->getMessage());
}

// 3) التحقق
t("\n═══ 3) الحالة النهائية ═══");
$allTables = [
    'flight_bookings', 'flight_payments', 'passengers', 'flight_segments',
    'flight_refunds', 'ticket_modifications', 'airline_transactions',
    'flight_system_transactions', 'flight_group_transactions', 'transactions',
    'airline_accounts', 'airline_credits', 'transfers', 'treasury_transactions',
    'account_entries', 'refund_requests', 'ledger_reconciliation_findings',
    'flight_groups', 'flight_carriers', 'flight_systems', 'airports',
    'customers', 'employees', 'accounts', 'treasuries', 'currencies', 'exchange_rates',
    'flight_tickets', 'flight_pricings', 'flight_pricing',
];

$clean = 0;
$dirty = 0;
foreach ($allTables as $t) {
    try {
        $c = DB::table($t)->count();
        if ($c == 0) {
            ok("$t: 0");
            $clean++;
        } else {
            warn("$t: $c (متبقي)");
            $dirty++;
        }
    } catch (\Throwable $e) {
        // table not found
    }
}
t("\n  📊 $clean جدول نظيف، $dirty جدول فيه بيانات متبقية");

// 4) حساب الـ Trial Balance بعد التنظيف
t("\n═══ 4) Trial Balance بعد التنظيف ═══");
$posTotal = DB::table('accounts')->where('balance', '>', 0)->sum('balance');
$negTotal = DB::table('accounts')->where('balance', '<', 0)->sum('balance');
$netTotal = $posTotal + $negTotal;
info("إجمالي الأرصدة الموجبة: " . number_format($posTotal, 2));
info("إجمالي الأرصدة السالبة: " . number_format($negTotal, 2));
info("الصافي: " . number_format($netTotal, 2));
if ($dirty == 0 && abs($netTotal) < 1) {
    ok("🎉 DB نظيف محاسبياً!");
} else {
    warn("⚠️ الرصيد الصافي ليس 0 — قد يكون capital accounts متبقية");
}

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'before' => $before,
    'deleted' => $deleted,
    'after_trial_balance' => [
        'positive' => (float) $posTotal,
        'negative' => (float) $negTotal,
        'net' => (float) $netTotal,
    ],
    'clean_tables' => $clean,
    'dirty_tables' => $dirty,
];
file_put_contents(__DIR__ . '/storage/logs/flight_test/cleanup_v3_' . date('Y-m-d_His') . '.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n✅ Cleanup v3 DONE.\n";