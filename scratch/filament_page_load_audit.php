<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

$user = User::query()->where('role', 'admin')->where('is_active', true)->first();
if (! $user) {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Employee::query()->create(['user_id' => $user->id, 'status' => 'active']);
}

auth()->login($user);
Filament::setCurrentPanel(Filament::getPanel('admin'));

$ok = [];
$fail = [];

foreach (Filament::getCurrentPanel()->getResources() as $resource) {
    $name = class_basename($resource);
    $pages = $resource::getPages();

    foreach ($pages as $pageKey => $page) {
        $pageClass = $page->getPage();
        $label = "{$name}::{$pageKey}";

        try {
            Livewire::test($pageClass)->assertSuccessful();
            $ok[] = $label;
        } catch (Throwable $e) {
            $fail[] = "{$label}: {$e->getMessage()}";
        }
    }
}

foreach (Filament::getCurrentPanel()->getPages() as $pageClass) {
    $label = class_basename($pageClass);

    try {
        Livewire::test($pageClass)->assertSuccessful();
        $ok[] = "Page::{$label}";
    } catch (Throwable $e) {
        $fail[] = "Page::{$label}: {$e->getMessage()}";
    }
}

echo "=== FILAMENT RUNTIME PAGE LOAD TEST ===\n";
echo 'Passed: ' . count($ok) . "\n";
echo 'Failed: ' . count($fail) . "\n\n";

if ($fail !== []) {
    echo "FAILURES:\n";
    foreach ($fail as $line) {
        echo "  - {$line}\n";
    }
} else {
    echo "All pages loaded successfully.\n";
}
