<?php
/**
 * fresh_balanced_db.php
 * تهيئة قاعدة البيانات وتشغيل الـ Seeder الشامل للحسابات المتوازنة
 */
define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n============================================================\n";
echo "  تهيئة وإعادة ضخ البيانات المحاسبية المتوازنة\n";
echo "============================================================\n\n";

// 1. تنظيف الجداول
Schema::disableForeignKeyConstraints();
$tablesToTruncate = [
    'account_entries',
    'transactions',
    'accounts',
    'treasuries',
    'treasury_transactions',
    'customers',
    'suppliers',
    'flight_carriers',
    'flight_systems',
    'flight_bookings',
    'visa_bookings',
    'visa_details',
    'bus_bookings',
    'bus_inventories',
    'bus_companies',
    'hajj_umra_bookings',
    'programs'
];

foreach ($tablesToTruncate as $tbl) {
    DB::table($tbl)->truncate();
}
Schema::enableForeignKeyConstraints();
echo "  ✅ تم إفراغ جميع الجداول.\n";

// 2. تشغيل الـ Seeders
echo "  → تشغيل UserSeeder...\n";
\Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'UserSeeder']);

echo "  → تشغيل UnifiedVaultsSeeder...\n";
\Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'UnifiedVaultsSeeder']);

echo "  → تشغيل AccountingTestDataSeeder...\n";
\Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'AccountingTestDataSeeder']);

// 3. التحقق النهائي من الـ Desync
$desyncs = DB::select("
    SELECT a.id, a.name, a.balance as stored_bal,
           COALESCE(SUM(ae.debit) - SUM(ae.credit), 0) as computed_bal,
           ABS(a.balance - COALESCE(SUM(ae.debit) - SUM(ae.credit), 0)) as bal_diff
    FROM accounts a
    LEFT JOIN account_entries ae ON ae.account_id = a.id
    WHERE a.deleted_at IS NULL
    GROUP BY a.id, a.name, a.balance
    HAVING bal_diff > 0.50
");

if (count($desyncs) === 0) {
    echo "  ✅ جميع الحسابات متزامنة تماماً (0 desync)!\n";
} else {
    echo "  ❌ يوجد " . count($desyncs) . " حساب غير متزامن!\n";
    foreach ($desyncs as $d) {
        echo "     - #{$d->id} [{$d->name}] stored={$d->stored_bal} computed={$d->computed_bal} diff={$d->bal_diff}\n";
    }
}

// التحقق من توازن إجمالي القيود (مدين ودائن)
$totals = DB::table('account_entries')
    ->selectRaw('SUM(debit) as td, SUM(credit) as tc')
    ->first();
$td = round((float)($totals->td ?? 0), 2);
$tc = round((float)($totals->tc ?? 0), 2);
$balDiff = round(abs($td - $tc), 2);

echo "  📊 الميزان الكلي الحالي:\n";
echo "    مجموع المدين:  " . number_format($td, 2) . " EGP\n";
echo "    مجموع الدائن: " . number_format($tc, 2) . " EGP\n";
echo "    الفرق:        {$balDiff} EGP\n";

if ($balDiff <= 0.01) {
    echo "  ✅ الميزان المحاسبي العام متوازن وصحيح 100%!\n\n";
} else {
    echo "  ❌ الميزان العام غير متوازن!\n\n";
}
