<?php

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\FlightDashboard;
use App\Filament\Admin\Resources\Accounts\AccountResource;
use App\Filament\Admin\Resources\BusBookings\BusBookingResource;
use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use App\Filament\Admin\Resources\BusCompanyPayments\BusCompanyPaymentResource;
use App\Filament\Admin\Resources\BusInventories\BusInventoryResource;
use App\Filament\Admin\Resources\EmployeeBonuses\EmployeeBonusResource;
use App\Filament\Admin\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Admin\Resources\FawryTransactions\FawryTransactionResource;
use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\OnlineServiceTypes\OnlineServiceTypeResource;
use App\Filament\Admin\Resources\Programs\ProgramResource;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Resources\Transactions\TransactionResource;
use App\Filament\Admin\Resources\TreasuryTransactions\TreasuryTransactionResource;
use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use App\Filament\Resources\EmployeeResource;

return [
    'default' => 'admin',

    'panels' => [
        'admin' => [
            'id' => 'admin',
            'file' => 'App\\Filament\\AdminPanel',
            'login' => true,
            'brand' => 'سفرك علينا',
            'brandName' => 'سفرك علينا',
            'favicon' => null,
            'hasDatabaseNotifications' => true,
            'hasDatabaseNotificationsMedia' => true,
            'defaultAvatarProvider' => null,
            'defaultUserMenuEnabled' => true,
            'maxContentWidth' => 'full',
            'navigation' => [
                'groups' => [
                    'عام',
                    'العملاء',
                    'حجوزات',
                    'الحج والعمرة',
                    'التأشيرات',
                    'المالية',
                    'الخدمات الإلكترونية',
                    'الموظفين',
                    'الإعدادات',
                ],
            ],
            'breadcrumbs' => true,
            'theme' => [
                'darkMode' => false,
                'hasDarkMode' => false,
            ],
            'sidebar' => [
                'is_collapsible_on_desktop' => true,
                'is_collapsed_on_desktop' => false,
                'rendersSidebarBeforeAuth' => false,
            ],
            'globallySearch' => [
                'enabled' => true,
            ],
            'auth' => [
                'guard' => 'web',
                'pages' => [
                    EmployeeResource::class,
                ],
            ],
            'resources' => [
                // Employees
                EmployeeBonusResource::class,

                // Flight Bookings
                FlightBookingResource::class,
                // Bus - NEW System
                BusCompanyResource::class,
                BusInventoryResource::class,
                BusBookingResource::class,
                BusCompanyPaymentResource::class,

                // Hajj & Umra
                ProgramResource::class,
                HajjUmraBookingResource::class,
                VisaBookingResource::class,

                // Finance
                InvoiceResource::class,
                SupplierResource::class,
                AccountResource::class,
                TransactionResource::class,
                ExchangeRateResource::class,
                TreasuryTransactionResource::class,

                // Online Services
                OnlineServiceTypeResource::class,
                FawryTransactionResource::class,

                // Administration
            ],
            'pages' => [
                Dashboard::class,
                FlightDashboard::class,
            ],
            'widgets' => [
                // Widgets will be registered here
            ],
        ],
    ],

    'theme' => [
        'default' => 'light',
        'dark' => [
            'colors' => [
                'primary' => '#3b82f6',
                'danger' => '#ef4444',
                'gray' => '#6b7280',
                'info' => '#3b82f6',
                'success' => '#10b981',
                'warning' => '#f59e0b',
            ],
        ],
        'light' => [
            'colors' => [
                'primary' => '#3b82f6',
                'danger' => '#ef4444',
                'gray' => '#6b7280',
                'info' => '#3b82f6',
                'success' => '#10b981',
                'warning' => '#f59e0b',
            ],
        ],
    ],
];
