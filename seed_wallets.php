<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Wallet\WalletType;

if (WalletType::count() === 0) {
    WalletType::insert([
        ['name' => 'فودافون كاش', 'code' => 'vodafone_cash', 'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'انستاباي',    'code' => 'instapay',      'is_active' => 1, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'اورنج كاش',  'code' => 'orange_cash',   'is_active' => 1, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);
    echo "✓ Seeded " . WalletType::count() . " wallet types.\n";
} else {
    echo "Already have " . WalletType::count() . " wallet types.\n";
}
