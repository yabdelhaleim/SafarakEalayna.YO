<?php
// Force SQLite database connection
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . __DIR__ . '/../database/database.sqlite');

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$accounts = \App\Models\Account::all();
echo sprintf("%-5s | %-30s | %-12s | %-12s | %-10s | %-8s\n", "ID", "Name", "Type", "Module Type", "Balance", "Vault");
echo str_repeat("-", 90) . "\n";
foreach ($accounts as $a) {
    $typeStr = $a->type instanceof \BackedEnum ? $a->type->value : (string) $a->type;
    echo sprintf("%-5d | %-30s | %-12s | %-12s | %-10.2f | %-8s\n", 
        $a->id, 
        mb_substr($a->name, 0, 30), 
        $typeStr, 
        $a->module_type ?? 'N/A', 
        $a->balance, 
        $a->is_module_vault ? 'Yes' : 'No'
    );
}
