<?php

$dir = new RecursiveDirectoryIterator(__DIR__ . '/app/Http/Requests');
$iterator = new RecursiveIteratorIterator($dir);

$pattern = '/throw new ValidationException\(\s*\$this->validator,\s*response\(\)->json\(\[\s*\'status\' => false,\s*\'message\' => \'Unknown fields are not allowed.\',\s*\'errors\' => array_fill_keys\(\$unknown, \[\'This field is not allowed.\'\]\),\s*\'data\' => null,\s*\], 422\)\s*\);/s';

$replacement = "throw \Illuminate\Validation\ValidationException::withMessages(\n                array_fill_keys(\$unknown, 'This field is not allowed.')\n            );";

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, $replacement, $content);
            file_put_contents($file->getPathname(), $newContent);
            echo "Fixed: " . $file->getPathname() . "\n";
        }
    }
}
