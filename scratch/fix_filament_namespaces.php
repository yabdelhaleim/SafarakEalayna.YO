<?php
$directory = __DIR__.'/../app/Filament';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

$count = 0;
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $originalContent = $content;

        // Replace specific imports or usages
        $content = str_replace('use Filament\Tables\Actions\\', 'use Filament\Actions\\', $content);
        $content = str_replace('Tables\Actions\Action', '\Filament\Actions\Action', $content);
        $content = str_replace('Tables\Actions\EditAction', '\Filament\Actions\EditAction', $content);
        $content = str_replace('Tables\Actions\ViewAction', '\Filament\Actions\ViewAction', $content);
        $content = str_replace('Tables\Actions\DeleteAction', '\Filament\Actions\DeleteAction', $content);
        $content = str_replace('Tables\Actions\BulkActionGroup', '\Filament\Actions\BulkActionGroup', $content);
        $content = str_replace('Tables\Actions\DeleteBulkAction', '\Filament\Actions\DeleteBulkAction', $content);
        $content = str_replace('\Filament\Tables\Actions\EditAction', '\Filament\Actions\EditAction', $content);
        $content = str_replace('use Filament\Tables\Actions;', 'use Filament\Actions;', $content);
        
        if ($content !== $originalContent) {
            file_put_contents($file->getPathname(), $content);
            echo "Updated: " . $file->getPathname() . "\n";
            $count++;
        }
    }
}
echo "Total files updated: $count\n";
