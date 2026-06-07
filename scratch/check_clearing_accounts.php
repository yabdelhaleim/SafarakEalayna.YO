<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;

$names = [
    'إقفال تكاليف التأشيرات',
    'إقفال إيرادات التأشيرات',
    'إقفال تكاليف الحج والعمرة',
    'إقفال إيرادات الحج والعمرة',
];

foreach ($names as $name) {
    $a = Account::where('name', $name)->first();
    if ($a) {
        echo "$name: ID={$a->id}, Type={$a->type->value}, Balance={$a->balance}\n";
    } else {
        echo "$name: NOT FOUND\n";
    }
}
