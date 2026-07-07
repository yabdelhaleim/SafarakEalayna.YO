<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('customers')->delete();
DB::table('suppliers')->delete();
DB::table('accounts')->whereIn('type', ['customer', 'supplier'])->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// عميل مكتب - account.module_type=bus - بدون أي حجوزات
$accBus = DB::table('accounts')->insertGetId([
    'name' => 'عميل مكتب - باص (بدون حجوزات)',
    'type' => 'customer',
    'module_type' => 'bus',
    'balance' => 1500.00,
    'currency' => 'EGP',
    'is_active' => 1,
    'owner_type' => 'office',
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('customers')->insertGetId([
    'account_id' => $accBus,
    'full_name' => 'عميل مكتب - باص بدون حجز',
    'phone' => '01011111111',
    'status' => 'active',
    'created_at' => now(), 'updated_at' => now(),
]);

// عميل طيران - account.module_type=flights - بدون حجوزات
$accFlt = DB::table('accounts')->insertGetId([
    'name' => 'عميل طيران (بدون حجوزات)',
    'type' => 'customer',
    'module_type' => 'flights',
    'balance' => 5000.00,
    'currency' => 'EGP',
    'is_active' => 1,
    'owner_type' => 'owner',
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('customers')->insertGetId([
    'account_id' => $accFlt,
    'full_name' => 'عميل طيران بدون حجز',
    'phone' => '01022222222',
    'status' => 'active',
    'created_at' => now(), 'updated_at' => now(),
]);

// مورد باص - رصيد موجب
$accSup = DB::table('accounts')->insertGetId([
    'name' => 'شركة باصات النيل',
    'type' => 'supplier',
    'module_type' => 'bus',
    'balance' => 1200.00,
    'currency' => 'EGP',
    'is_active' => 1,
    'owner_type' => 'office',
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('suppliers')->insert([
    'name' => 'شركة باصات النيل',
    'code' => 'SUP-BUS-001',
    'type' => 'bus_company',
    'phone' => '01033333333',
    'account_id' => $accSup,
    'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);

echo "✓ بيانات الاختبار جاهزة\n\n";

$service = app(\App\Services\Reports\FinancialReportService::class);

echo "=== office report ===\n";
$r = $service->getDebtsReport(['department' => 'office']);
echo "total_receivables : " . $r['total_receivables'] . "\n";
echo "total_payables    : " . $r['total_payables'] . "\n";
echo "items count       : " . count($r['items']) . "\n";
foreach ($r['items'] as $item) {
    $dir = $item['balance'] > 0 ? '[مستحق لنا]' : '[مستحق علينا]';
    echo "  $dir {$item['name']} | {$item['entity_type']} | dept={$item['department']} | mod={$item['module']} | {$item['balance']}\n";
}

echo "\n=== tourism report ===\n";
$r2 = $service->getDebtsReport(['department' => 'tourism']);
echo "total_receivables : " . $r2['total_receivables'] . "\n";
echo "items count       : " . count($r2['items']) . "\n";
foreach ($r2['items'] as $item) {
    $dir = $item['balance'] > 0 ? '[مستحق لنا]' : '[مستحق علينا]';
    echo "  $dir {$item['name']} | {$item['entity_type']}\n";
}
