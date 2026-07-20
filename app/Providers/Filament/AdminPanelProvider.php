<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\AirportResource;
use App\Filament\Resources\TreasuryResource;
use App\Http\Middleware\AuthenticateWithApiToken;
use App\Http\Middleware\SetFilamentLocale;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\GoogleFontProvider;
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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('سفرك علينا')
            ->brandLogo(fn () => view('filament.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/favicon.ico'))
            ->colors([
                'primary' => Color::hex('#185FA5'),
                'info' => Color::hex('#378ADD'),
                'success' => Color::hex('#1D9E75'),
                'warning' => Color::hex('#BA7517'),
                'danger' => Color::hex('#A32D2D'),
                'gray' => Color::Slate,
            ])
            ->darkMode(false)
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(Width::Full)
            ->font('Cairo', provider: GoogleFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('260px')
            ->collapsedSidebarWidth('68px')

            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->resources([
                AirportResource::class,
                TreasuryResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')

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
                SetFilamentLocale::class,
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
                    ->isActiveWhen(fn () => request()->is('dashboard*')),
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn () => new HtmlString('<link rel="stylesheet" href="'.asset('css/filament-auth.css').'">'),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn () => new HtmlString('<link rel="stylesheet" href="'.asset('css/filament-admin.css').'">'),
            );
    }
}
