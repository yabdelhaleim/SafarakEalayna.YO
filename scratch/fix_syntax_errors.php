<?php
$directory = __DIR__.'/../app/Filament';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

$count = 0;
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $originalContent = $content;

        // Fix double backslashes and double Filament names
        $content = str_replace('\\Filament\\\\Filament\\Actions', '\\Filament\\Actions', $content);
        $content = str_replace('\\Filament\\Filament\\Actions', '\\Filament\\Actions', $content);
        $content = str_replace('\\\\Filament\\Actions', '\\Filament\\Actions', $content);
        
        if ($content !== $originalContent) {
            file_put_contents($file->getPathname(), $content);
            echo "Fixed Syntax: " . $file->getPathname() . "\n";
            $count++;
        }
    }
}
echo "Total files fixed: $count\n";
