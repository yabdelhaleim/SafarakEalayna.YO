<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "Safe Schema Additions...\n";

if (!Schema::hasColumn('accounts', 'owner_type')) {
    Schema::table('accounts', function($t) {
        $t->string('owner_type')->default('owner')->after('is_active');
    });
    echo "Added owner_type to accounts\n";
}

if (!Schema::hasColumn('accounts', 'module')) {
    Schema::table('accounts', function($t) {
        $t->string('module')->nullable()->after('module_type');
    });
    echo "Added module to accounts\n";
}

if (!Schema::hasColumn('accounts', 'is_module_vault')) {
    Schema::table('accounts', function($t) {
        $t->boolean('is_module_vault')->default(false)->after('module');
    });
    echo "Added is_module_vault to accounts\n";
}

echo "Finished Safe Additions.\n";
