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
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('سفرك علينا')
            ->brandLogo(fn () => view('filament.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/favicon.ico'))
            ->colors([
                'primary' => Color::hex('#185FA5'),
                'info'    => Color::hex('#378ADD'),
                'success' => Color::hex('#1D9E75'),
                'warning' => Color::hex('#BA7517'),
                'danger'  => Color::hex('#A32D2D'),
                'gray'    => Color::Slate,
            ])
            ->darkMode(false)
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(Width::Full)
            ->font('Cairo', provider: \Filament\FontProviders\GoogleFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('260px')
            ->collapsedSidebarWidth('68px')

            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')

            ->resources([
                \App\Filament\Resources\FlightResource::class,
                \App\Filament\Resources\BookingResource::class,
                \App\Filament\Resources\CustomerResource::class,
                \App\Filament\Resources\HotelResource::class,
                \App\Filament\Resources\AirportResource::class,
                \App\Filament\Resources\TreasuryResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            ->widgets([
                \Filament\Widgets\AccountWidget::class,
                \App\Filament\Widgets\StatsOverviewWidget::class,
                \App\Filament\Widgets\BookingsChartWidget::class,
                \App\Filament\Widgets\RecentBookingsWidget::class,
                \App\Filament\Widgets\TopDestinationsWidget::class,
            ])

            ->navigationGroups([
                NavigationGroup::make('الرئيسية')
                    ->icon('heroicon-o-home'),
                NavigationGroup::make('إدارة الرحلات')
                    ->icon('heroicon-o-paper-airplane'),
                NavigationGroup::make('الحجوزات والعملاء')
                    ->icon('heroicon-o-ticket'),
                NavigationGroup::make('التقارير المالية')
                    ->icon('heroicon-o-currency-dollar'),
                NavigationGroup::make('الإعدادات')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])

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
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn () => new HtmlString('<link rel="stylesheet" href="' . asset('css/filament-admin.css') . '">'),
            );
    }
}
