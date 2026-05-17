<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$u = App\Models\User::where('email', 'admin@safarakalayna.com')->first();
if ($u) {
    $u->password = Hash::make('admin123');
    $u->save();
    echo "Password reset successful for " . $u->email . "\n";
} else {
    echo "User not found\n";
}
