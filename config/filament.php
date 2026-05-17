<?php

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
                    \App\Filament\Resources\EmployeeResource::class,
                ],
            ],
            'resources' => [
                // Customers
                \App\Filament\Admin\Resources\Customers\CustomerResource::class,

                // Employees
                \App\Filament\Admin\Resources\Employees\EmployeeResource::class,
                \App\Filament\Admin\Resources\EmployeeAttendances\EmployeeAttendanceResource::class,
                \App\Filament\Admin\Resources\EmployeeBonuses\EmployeeBonusResource::class,

                // Flight Bookings
                \App\Filament\Admin\Resources\FlightBookings\FlightBookingResource::class,
                \App\Filament\Admin\Resources\Passengers\PassengerResource::class,
                \App\Filament\Admin\Resources\FlightPayments\FlightPaymentResource::class,

                // Bus - NEW System
                \App\Filament\Admin\Resources\BusCompanies\BusCompanyResource::class,
                \App\Filament\Admin\Resources\BusInventories\BusInventoryResource::class,
                \App\Filament\Admin\Resources\BusBookings\BusBookingResource::class,
                \App\Filament\Admin\Resources\BusCompanyPayments\BusCompanyPaymentResource::class,

                // Hajj & Umra
                \App\Filament\Admin\Resources\Programs\ProgramResource::class,
                \App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource::class,
                \App\Filament\Admin\Resources\VisaBookings\VisaBookingResource::class,

                // Finance
                \App\Filament\Admin\Resources\Invoices\InvoiceResource::class,
                \App\Filament\Admin\Resources\Suppliers\SupplierResource::class,
                \App\Filament\Admin\Resources\Accounts\AccountResource::class,
                \App\Filament\Admin\Resources\Transactions\TransactionResource::class,
                \App\Filament\Admin\Resources\ExchangeRates\ExchangeRateResource::class,
                \App\Filament\Admin\Resources\TreasuryTransactions\TreasuryTransactionResource::class,

                // Online Services
                \App\Filament\Admin\Resources\OnlineServiceTypes\OnlineServiceTypeResource::class,
                \App\Filament\Admin\Resources\FawryTransactions\FawryTransactionResource::class,

                // Administration
                \App\Filament\Admin\Resources\ApprovalWorkflows\ApprovalWorkflowResource::class,
                \App\Filament\Admin\Resources\AuditLogs\AuditLogResource::class,
            ],
            'pages' => [
                \App\Filament\Admin\Pages\Dashboard::class,
                \App\Filament\Admin\Pages\FlightDashboard::class,
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
