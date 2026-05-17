<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Auth\AdminLogin;
use App\Filament\Admin\Resources\FawryOperationTypes\FawryOperationTypeResource;
use App\Filament\Admin\Resources\FlightSystems\FlightSystemResource;
use App\Filament\Admin\Resources\OnlineServiceTypes\OnlineServiceTypeResource;
use App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource;
use App\Filament\Admin\Resources\TicketModifications\TicketModificationResource;
use App\Filament\Admin\Resources\WalletTypes\WalletTypeResource;
use App\Filament\Admin\Support\FlightModuleNavigation;
use App\Filament\Admin\Support\WalletModuleNavigation;
use App\Filament\Admin\Support\FawryModuleNavigation;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use App\Filament\Admin\Support\BusModuleNavigation;
use App\Http\Middleware\AuthenticateWithApiToken;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\HtmlString;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(AdminLogin::class)
            ->brandName('سفارك إلينا')
            // ->viteTheme('resources/css/filament/admin/theme.css')

            ->colors([
                'primary' => Color::Sky,
            ])
            ->darkMode(true, false)
            ->defaultThemeMode(ThemeMode::Dark)
            ->maxContentWidth(Width::Full)
            ->font('IBM Plex Sans Arabic')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateWithApiToken::class, // SSO: authenticate via ?token= before Filament checks auth
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            ->navigationItems([
                NavigationItem::make('العودة للتطبيق')
                    ->icon('heroicon-o-arrow-left')
                    ->url(fn () => url('/dashboard'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('dashboard*'))
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn () => new HtmlString('<link rel="stylesheet" href="' . asset('css/filament-auth.css') . '">'),
            );
    }
}
