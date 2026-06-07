<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Services\Finance\TreasuryService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Contracts\Console\Kernel;

$base = Account::query()
    ->whereIn('owner_type', ['office', 'owner'])
    ->where('name', 'not like', '%عميل%')
    ->where('name', 'not like', '%شركة%')
    ->where('name', 'not like', '%مورد%')
    ->where('name', 'not like', '%إقفال%')
    ->where('name', 'not like', '%(نظام)%')
    ->where('name', 'not like', '%ذممة%')
    ->where('name', 'not like', '%sad%')
    ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
    ->active();

$all = (clone $base)->get();
$overview = app(TreasuryService::class)->getTreasuryOverview();
$inOverview = collect();
foreach ($overview['modules'] as $mod) {
    foreach ($mod['accounts'] as $a) {
        $inOverview->push($a['id']);
    }
}
$missing = $all->whereNotIn('id', $inOverview->unique());

echo 'Total liquidity accounts: '.$all->count().PHP_EOL;
echo 'In treasury overview: '.$inOverview->unique()->count().PHP_EOL;
echo 'Missing from overview: '.$missing->count().PHP_EOL;
if ($missing->count()) {
    foreach ($missing->take(20) as $a) {
        echo '  #'.$a->id.' '.$a->name.' module='.($a->module ?? 'null').' module_type='.($a->module_type ?? 'null').' type='.$a->type->value.PHP_EOL;
    }
}
echo 'Total balance all: '.$all->sum('balance').PHP_EOL;
echo 'Total balance overview stats: '.$overview['stats']['total_liquidity'].PHP_EOL;
echo 'Module keys in overview: '.implode(', ', array_keys($overview['modules'])).PHP_EOL;
$byModuleType = $all->groupBy(fn ($a) => $a->module_type ?: 'null')->map->count()->sortDesc();
echo 'By module_type:'.PHP_EOL;
foreach ($byModuleType as $k => $v) {
    echo '  '.$k.': '.$v.PHP_EOL;
}

// Check category assignment issues
foreach ($overview['modules'] as $key => $mod) {
    $cat = $mod['category'] ?? 'MISSING';
    echo "Module [$key] category=$cat accounts=".count($mod['accounts']).PHP_EOL;
}

$treasuryQuery = Account::whereIn('owner_type', ['office', 'owner'])
    ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
    ->active()
    ->get();
echo PHP_EOL.'TreasuryService raw query count: '.$treasuryQuery->count().PHP_EOL;
$excluded = $treasuryQuery->filter(fn ($a) => str_contains($a->name, 'عميل')
    || str_contains($a->name, 'شركة')
    || str_contains($a->name, 'مورد')
    || str_contains($a->name, 'إقفال')
    || str_contains($a->name, '(نظام)')
    || str_contains($a->name, 'ذممة')
    || str_contains($a->name, 'sad'));
echo 'Excluded by name patterns: '.$excluded->count().PHP_EOL;
foreach ($excluded->take(10) as $a) {
    echo '  #'.$a->id.' '.$a->name.' balance='.$a->balance.PHP_EOL;
}

echo PHP_EOL.'Real treasury accounts (16):'.PHP_EOL;
foreach ($all as $a) {
    $moduleKey = $a->module ?: ($a->module_type ?: 'general');
    echo '  #'.$a->id.' '.$a->name.' | module='.$a->module.' | module_type='.$a->module_type.' | key='.$moduleKey.' | bal='.$a->balance.PHP_EOL;
}
