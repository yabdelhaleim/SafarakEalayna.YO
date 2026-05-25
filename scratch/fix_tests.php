<?php

$dir = new RecursiveDirectoryIterator(__DIR__ . '/../tests');
$iterator = new RecursiveIteratorIterator($dir);
$count = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $original = $content;

        // Replace status assertions with success assertions
        $content = str_replace("assertJsonPath('status', true)", "assertJsonPath('success', true)", $content);
        $content = str_replace("assertJsonPath('status', false)", "assertJsonPath('success', false)", $content);
        $content = str_replace("assertJsonPath('status', 'SUCCESS')", "assertJsonPath('success', true)", $content);
        $content = str_replace("assertJsonPath('status', 'ERROR')", "assertJsonPath('success', false)", $content);
        
        $content = str_replace("'status' => true", "'success' => true", $content);
        $content = str_replace("'status' => false", "'success' => false", $content);
        $content = str_replace('"status" => true', '"success" => true', $content);
        $content = str_replace('"status" => false', '"success" => false', $content);

        // Also fix specific occurrences like $response->json('status') === true
        $content = str_replace("json('status')", "json('success')", $content);

        if ($content !== $original) {
            file_put_contents($file->getPathname(), $content);
            $count++;
            echo "Updated: " . $file->getFilename() . "\n";
        }
    }
}

echo "Total updated files: $count\n";
