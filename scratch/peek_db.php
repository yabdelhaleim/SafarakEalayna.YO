<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (DB::table('users')->get() as $u) {
    echo "user #{$u->id} {$u->email}\n";
}
echo 'accounts: '.DB::table('accounts')->count()."\n";
