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
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('<style> svg, .fi-icon, .fi-btn svg, .fi-sidebar svg, .fi-topbar svg { max-width: 1.5rem !important; max-height: 1.5rem !important; display: inline-block; } </style>')
            )
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
            ->navigationGroups([
                NavigationGroup::make('الرئيسية'),
                NavigationGroup::make('الحجوزات'),
                NavigationGroup::make('الحج والعمرة'),
                NavigationGroup::make('bus'),
                NavigationGroup::make('الطيران'),
                NavigationGroup::make(FawryModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-bolt'),
                NavigationGroup::make(OnlineModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-globe-alt'),
                NavigationGroup::make('الخدمات'),
                NavigationGroup::make(WalletModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-credit-card'),
                NavigationGroup::make('الحسابات'),
                NavigationGroup::make('المالية'),
                NavigationGroup::make('الحسابات والمالية'),
                NavigationGroup::make('التقارير'),
                NavigationGroup::make('الإعدادات'),
                NavigationGroup::make('إدارة العملاء'),
            ])
            ->navigationItems([
                NavigationItem::make('لوحة التحكم (التطبيق)')
                    ->group('الرئيسية')
                    ->icon('heroicon-o-home')
                    ->url(fn () => url('/dashboard'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('dashboard*') && ! request()->is('admin*'))
                    ->sort(2),
                NavigationItem::make(FlightModuleNavigation::PARENT_LABEL)
                    ->group('الطيران')
                    ->icon('heroicon-o-paper-airplane')
                    ->url(fn () => FlightSystemResource::getUrl())
                    ->isActiveWhen(fn () => request()->is(
                        'admin/flight-systems*',
                        'admin/flight-carriers*',
                        'admin/flight-bookings*',
                        'admin/airports*',
                        'admin/flight-systems-balances-page',
                        'admin/flight-cash-treasury-page',
                        'admin/bank-accounts*',
                        'admin/passengers*',
                        'admin/ticket-modifications*',
                    ))
                    ->sort(1),

                NavigationItem::make(BusModuleNavigation::PARENT_LABEL)
                    ->group('bus')
                    ->icon('heroicon-o-truck')
                    ->url(fn () => BusCompanyResource::getUrl())
                    ->isActiveWhen(fn () => request()->is([
                        'admin/bus-companies*',
                        'admin/bus-inventories*',
                        'admin/bus-bookings*',
                        'admin/bus-company-payments*',
                    ]))
                    ->sort(0),
                NavigationItem::make('الحج والعمرة')
                    ->group('الحجوزات')
                    ->icon('heroicon-o-building-library')
                    ->url(fn () => url('/admin/hajj-umra'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/hajj-umra*'))
                    ->sort(2),
                NavigationItem::make('التأشيرات')
                    ->group('الحجوزات')
                    ->icon('heroicon-o-identification')
                    ->url(fn () => url('/admin/visas'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/visas*'))
                    ->sort(3),
                NavigationItem::make(FawryModuleNavigation::PARENT_LABEL)
                    ->group(FawryModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-bolt')
                    ->url(fn () => FawryOperationTypeResource::getUrl())
                    ->isActiveWhen(fn () => request()->is(
                        'admin/fawry-operation-types*',
                        'admin/fawry-payment-methods*',
                        'admin/fawry-currencies*',
                        'admin/fawry-transactions*',
                    ))
                    ->sort(0),
                NavigationItem::make(OnlineModuleNavigation::PARENT_LABEL)
                    ->group(OnlineModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-globe-alt')
                    ->url(fn () => OnlineServiceTypeResource::getUrl())
                    ->isActiveWhen(fn () => request()->is(
                        'admin/online-service-types*',
                        'admin/online-service-providers*',
                        'admin/online-transactions*',
                    ))
                    ->sort(0),
                NavigationItem::make(WalletModuleNavigation::PARENT_LABEL)
                    ->group(WalletModuleNavigation::NAVIGATION_GROUP)
                    ->icon('heroicon-o-arrows-right-left')
                    ->url(fn () => WalletTypeResource::getUrl())
                    ->isActiveWhen(fn () => request()->is(
                        'admin/wallet-types*',
                        'admin/wallet-accounts*',
                        'admin/wallet-transactions*',
                    ))
                    ->sort(0),
                NavigationItem::make('حسابات الشركات')
                    ->group('الحسابات')
                    ->icon('heroicon-o-building-office')
                    ->url(fn () => url('/admin/airline-accounts'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/airline-accounts*'))
                    ->sort(1),
                NavigationItem::make('الخزينة')
                    ->group('الحسابات')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn () => url('/admin/treasury'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/treasury*'))
                    ->sort(2),
                NavigationItem::make('كشف الحساب')
                    ->group('الحسابات')
                    ->icon('heroicon-o-document-text')
                    ->url(fn () => url('/admin/accounts'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/accounts*'))
                    ->sort(3),
                NavigationItem::make('الموردون')
                    ->group('الحسابات')
                    ->icon('heroicon-o-users')
                    ->url(fn () => url('/admin/suppliers'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/suppliers*'))
                    ->sort(4),
                NavigationItem::make('التقارير الشاملة')
                    ->group('التقارير')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn () => url('/admin/reports'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/reports*'))
                    ->sort(1),
                NavigationItem::make('الموظفون')
                    ->group('الإعدادات')
                    ->icon('heroicon-o-user-group')
                    ->url(fn () => url('/admin/employees'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/employees*'))
                    ->sort(1),
                NavigationItem::make('الحضور والانصراف')
                    ->group('الإعدادات')
                    ->icon('heroicon-o-clock')
                    ->url(fn () => url('/admin/attendance'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/attendance*'))
                    ->sort(2),
                NavigationItem::make('إدارة المستخدمين')
                    ->group('الإعدادات')
                    ->icon('heroicon-o-user-group')
                    ->url(fn () => url('/admin/users'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('admin/users*'))
                    ->sort(3),
                NavigationItem::make('العودة للتطبيق')
                    ->icon('heroicon-o-arrow-left')
                    ->url(fn () => url('/dashboard'), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn () => request()->is('dashboard*'))
                    ->sort(100),
            ]);
    }
}
