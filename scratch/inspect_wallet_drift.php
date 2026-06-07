<?php

use App\Models\Account;
use App\Models\AccountEntry;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach ([637, 638, 640] as $id) {
    $a = Account::find($id);
    echo "#{$id} {$a->name}\n";
    echo "  created={$a->created_at} updated={$a->updated_at}\n";
    echo "  balance={$a->balance} notes=".($a->notes ?? '-')."\n";
    echo "  created_by={$a->created_by}\n";
}

// First entry per account
foreach ([637, 638] as $id) {
    $first = AccountEntry::where('account_id', $id)->orderBy('id')->first();
    if ($first) {
        echo "First entry #{$id}: id={$first->id} balance_after={$first->balance_after} at {$first->created_at}\n";
    }
}
