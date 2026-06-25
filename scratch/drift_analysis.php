<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;

$tolerance = 0.02;
$ledgerNet = AccountEntry::query()
    ->selectRaw('account_id, SUM(COALESCE(credit,0) - COALESCE(debit,0)) AS net')
    ->groupBy('account_id')
    ->pluck('net', 'account_id');

$withEntries = [];
$openingOnly = [];

foreach (Account::all() as $acc) {
    $entryCount = AccountEntry::where('account_id', $acc->id)->count();
    $stored = round((float) $acc->balance, 2);
    $ledger = round((float) ($ledgerNet[$acc->id] ?? 0), 2);
    $diff = round($stored - $ledger, 2);

    if ($entryCount === 0 && abs($stored) > $tolerance) {
        $openingOnly[] = ['id' => $acc->id, 'name' => $acc->name, 'stored' => $stored];
        continue;
    }
    if ($entryCount > 0 && abs($diff) > $tolerance) {
        $withEntries[] = ['id' => $acc->id, 'name' => $acc->name, 'stored' => $stored, 'ledger' => $ledger, 'diff' => $diff, 'entries' => $entryCount];
    }
}

echo "حسابات بأرصدة افتتاحية فقط (بدون قيود): ".count($openingOnly)."\n";
echo "حسابات لها قيود لكن الرصيد لا يطابق الدفتر: ".count($withEntries)."\n\n";

if ($withEntries) {
    echo "⚠️ انحراف حقيقي بعد الحركات:\n";
    foreach ($withEntries as $d) {
        echo sprintf("  #%d %s | مخزن=%s | دفتر=%s | فرق=%s | قيود=%d\n", $d['id'], $d['name'], number_format($d['stored'], 2), number_format($d['ledger'], 2), number_format($d['diff'], 2), $d['entries']);
    }
} else {
    echo "✅ كل الحسابات التي لها قيود محاسبية متطابقة مع الدفتر — نسبة خطأ 0%\n";
}

$ps = DB::table('print_settings')->first();
echo "\nرأس المال التأسيسي (سياحة): ".number_format((float)($ps->base_capital ?? 0), 2)."\n";
echo "رأس المال التأسيسي (مكتب): ".number_format((float)($ps->office_base_capital ?? 0), 2)."\n";
