<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;

echo "=== PHASE 3: DATA VERIFICATION FROM MANIFEST ===\n";
$manifestFile = __DIR__.'/../BUS_TEST_DATA_MANIFEST.json';
if (! file_exists($manifestFile)) {
    echo "Manifest file not found!\n";
    exit(1);
}

$manifest = json_decode(file_get_contents($manifestFile), true);
echo 'Manifest Run ID: '.($manifest['golden_run_id'] ?? 'N/A')."\n";

foreach ($manifest['verified_entities'] as $item) {
    $entity = $item['entity'];
    $id = $item['id'];
    $modelClass = 'App\\Models\\Bus\\'.$entity;
    if ($entity === 'Customer') {
        $modelClass = 'App\\Models\\Customer';
    }

    if (class_exists($modelClass)) {
        $record = $modelClass::find($id);
        if ($record) {
            echo "[FOUND] {$entity} #{$id} exists in database.\n";
        } else {
            echo "[MISSING] {$entity} #{$id} NOT found in database!\n";
        }
    }
}
