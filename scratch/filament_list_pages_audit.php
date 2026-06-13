<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ManageRecords;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Livewire;

$user = User::query()->where('role', 'admin')->where('is_active', true)->first()
    ?? User::factory()->create(['role' => 'admin', 'is_active' => true]);

if (! Employee::query()->where('user_id', $user->id)->exists()) {
    Employee::query()->create(['user_id' => $user->id, 'status' => 'active']);
}

auth()->login($user);
Filament::setCurrentPanel(Filament::getPanel('admin'));

$listPageClasses = [
    ListRecords::class,
    ManageRecords::class,
];

$ok = [];
$fail = [];

foreach (Filament::getCurrentPanel()->getResources() as $resource) {
    foreach ($resource::getPages() as $pageKey => $page) {
        $pageClass = $page->getPage();
        $isList = false;
        foreach ($listPageClasses as $base) {
            if (is_subclass_of($pageClass, $base)) {
                $isList = true;
                break;
            }
        }
        if (! $isList) {
            continue;
        }

        $label = class_basename($resource) . '::' . $pageKey;
        try {
            Livewire::test($pageClass)->assertSuccessful();
            $ok[] = $label;
        } catch (Throwable $e) {
            $fail[] = "{$label}: " . strtok($e->getMessage(), "\n");
        }
    }
}

foreach (Filament::getCurrentPanel()->getPages() as $pageClass) {
    $label = 'Page::' . class_basename($pageClass);
    try {
        Livewire::test($pageClass)->assertSuccessful();
        $ok[] = $label;
    } catch (Throwable $e) {
        $fail[] = "{$label}: " . strtok($e->getMessage(), "\n");
    }
}

echo "=== LIST / DASHBOARD PAGE LOAD TEST ===\n";
echo 'Passed: ' . count($ok) . "\n";
echo 'Failed: ' . count($fail) . "\n\n";
foreach ($fail as $line) {
    echo "FAIL: {$line}\n";
}
