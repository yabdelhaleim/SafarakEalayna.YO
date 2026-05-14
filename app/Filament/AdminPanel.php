<?php

namespace App\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvents;
use Filament\Pages;
use Filament\Resources;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Middleware\Localization;
use App\Filament\Pages\DashboardPage;

class AdminPanel extends \Filament\Panel
{
    public function boot(): void
    {
        //
    }

    public static function getDefault(): static
    {
        return static::make('admin')
            ->login()
            ->brandName('سفارك إليّنا')
            ->logo(asset('images/logo.png'))
            ->favicon(asset('images/favicon.ico'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => '#3b82f6',
                'secondary' => '#0891b2',
                'success' => '#10b981',
                'warning' => '#f59e0b',
                'danger' => '#ef4444',
                'gray' => '#64748b',
            ])
            ->font('IBM Plex Sans Arabic')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->navigationGroups([
                'حجوزات',
                'المالية',
                'العملاء',
                'الموظفين',
                'التقارير',
                'الإعدادات',
            ])
            ->pages([
                DashboardPage::class,
            ])
            ->widgets([
                // سيتم إضافتها لاحقاً
            ])
            ->middleware([
                Authenticate::class,
                DispatchServingFilamentEvents::class,
                StartSession::class,
                Localization::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationLabels([
                'dashboard' => 'لوحة التحكم',
                'filament-user' => 'الحساب',
            ])
            ->renderHook(
                'panels::head.end',
                fn (): string => '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">'
            )
            ->plugins([
                // يمكن إضافة Plugins لاحقاً
            ])
            ->spa()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']);
    }

    public static function getRoutes(): array
    {
        return [
            // ...your routes
        ];
    }
}
