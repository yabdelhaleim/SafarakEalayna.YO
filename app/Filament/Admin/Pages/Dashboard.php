<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Notifications\Notification;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'لوحة التحكم';

    protected static ?string $navigationLabel = 'لوحة التحكم';

    protected static ?int $navigationSort = -2;

    public function mount(): void
    {
        if (session()->has('error')) {
            Notification::make()
                ->title('تنبيه')
                ->body(session('error'))
                ->danger()
                ->send();
        }
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-home';
    }
}
