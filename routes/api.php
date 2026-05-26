<?php

use App\Http\Middleware\CaptureFinancialPostingContext;
use App\Http\Middleware\RejectBannedFinancialBypassMarkers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes (No middleware required)
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [\App\Http\Controllers\Api\Auth\AuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\Api\Auth\AuthController::class, 'login']);
});

// Public routes for booking forms (no authentication required)
Route::prefix('v1/public')->group(function () {
    Route::get('/bus/companies', [\App\Http\Controllers\Api\V1\Bus\BusCompanyController::class, 'publicIndex']);

    Route::get('/bus/inventories/available', [\App\Http\Controllers\Api\V1\Bus\BusInventoryController::class, 'available']);
});

// Protected Authentication Routes (Require authentication)
Route::prefix('v1/auth')->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\AuthController::class, 'logout']);
    Route::get('/me', [\App\Http\Controllers\Api\Auth\AuthController::class, 'me']);
    Route::put('/profile', [\App\Http\Controllers\Api\Auth\AuthController::class, 'updateProfile']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware([
    'auth:sanctum',
    CaptureFinancialPostingContext::class,
    RejectBannedFinancialBypassMarkers::class,
])->group(function () {
    // Health Check
    Route::get('/health', function () {
        return \App\Helpers\ApiResponse::success('System is operational', [
            'timestamp' => now()->toIso8601String()
        ]);
    });

    // Aviation Next Number route for the frontend
    Route::get('aviation/next-number', [\App\Http\Controllers\Api\V1\Flight\AviationController::class, 'nextNumber']);

    Route::prefix('settings')->group(function () {
        Route::get('payment-methods', [\App\Http\Controllers\Api\V1\SettingController::class, 'paymentMethods']);
        Route::get('operation-types', [\App\Http\Controllers\Api\V1\SettingController::class, 'operationTypes']);
        Route::get('currencies', [\App\Http\Controllers\Api\V1\SettingController::class, 'currencies']);
        Route::get('trip-types', [\App\Http\Controllers\Api\V1\SettingController::class, 'tripTypes']);
        Route::get('account-types', [\App\Http\Controllers\Api\V1\SettingController::class, 'accountTypes']);
        Route::get('transaction-types', [\App\Http\Controllers\Api\V1\SettingController::class, 'transactionTypes']);
        Route::get('transaction-modules', [\App\Http\Controllers\Api\V1\SettingController::class, 'transactionModules']);
        Route::get('flight-booking-reference', [\App\Http\Controllers\Api\V1\SettingController::class, 'flightBookingReference']);
    });

    // Dashboard API (Admin Only)
    Route::middleware('admin')->get('dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index']);

    // Finance API
    Route::prefix('finance')->group(function () {
        // Accounts listing is accessible to all authenticated users (to record payments)
        Route::get('accounts', [\App\Http\Controllers\Api\V1\Finance\AccountController::class, 'index'])->name('finance_accounts.index');

        Route::middleware('admin')->group(function () {
            Route::apiResource('accounts', \App\Http\Controllers\Api\V1\Finance\AccountController::class)->except(['index', 'destroy'])->names('finance_accounts');
            Route::get('treasuries/get-overview', [\App\Http\Controllers\Api\V1\Finance\TreasuryController::class, 'getOverview']);
            Route::get('treasuries/get-module-accounts/{module}', [\App\Http\Controllers\Api\V1\Finance\TreasuryController::class, 'getModuleAccounts']);
            Route::apiResource('treasuries', \App\Http\Controllers\Api\V1\Finance\TreasuryController::class)->names('finance_treasuries');
            Route::apiResource('currencies', \App\Http\Controllers\Api\V1\Finance\CurrencyController::class)->names('finance_currencies');
            Route::apiResource('approvals', \App\Http\Controllers\Api\V1\Finance\ApprovalController::class);
            Route::apiResource('audits', \App\Http\Controllers\Api\V1\Finance\AuditController::class);

            // Account specific routes
            Route::post('accounts/{account}/deactivate', [\App\Http\Controllers\Api\V1\Finance\AccountController::class, 'deactivate']);
            Route::get('accounts/{account}/statement', [\App\Http\Controllers\Api\V1\Finance\AccountController::class, 'statement']);
            Route::post('transfers', [\App\Http\Controllers\Api\V1\Finance\AccountController::class, 'transfer']);
            Route::get('transfers', [\App\Http\Controllers\Api\V1\Finance\AccountController::class, 'transferHistory']);


            // Transactions resource routes
            Route::apiResource('transactions', \App\Http\Controllers\Api\V1\Finance\TransactionController::class)->except(['index', 'show'])->names('finance_transactions');
        });
    });

    // Flights API
        Route::prefix('flight')->group(function () {
            Route::get('booking-form/employees', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'employeesForBooking']);
            Route::apiResource('bookings', \App\Http\Controllers\Api\V1\Flight\FlightController::class)
                ->parameters(['bookings' => 'flightBooking'])
                ->names('flight_bookings');
        Route::apiResource('aviation', \App\Http\Controllers\Api\V1\Flight\AviationController::class);

        // System types endpoint
        Route::get('system-types', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'systemTypes']);

        // Dashboard Endpoint
        Route::get('dashboard', [\App\Http\Controllers\Api\V1\Flight\FlightDashboardController::class, 'index']);

        Route::prefix('treasury')->group(function () {
            Route::get('overview', [\App\Http\Controllers\Api\V1\Flight\FlightTreasuryController::class, 'overview']);
            Route::get('systems/{system}/transactions', [\App\Http\Controllers\Api\V1\Flight\FlightTreasuryController::class, 'systemTransactions']);
            Route::post('systems/{system}/recharge', [\App\Http\Controllers\Api\V1\Flight\FlightTreasuryController::class, 'rechargeSystem']);
            Route::get('accounts/{account}/flight-transactions', [\App\Http\Controllers\Api\V1\Flight\FlightTreasuryController::class, 'accountFlightTransactions']);
        });

        // Flight Systems & Carriers endpoints
        Route::apiResource('systems', \App\Http\Controllers\Api\V1\Flight\FlightSystemController::class)->names('flight_systems');
        Route::apiResource('carriers', \App\Http\Controllers\Api\V1\Flight\FlightCarrierController::class)->names('flight_carriers');
        Route::get('carriers/{carrier}/balance', [\App\Http\Controllers\Api\V1\Flight\FlightCarrierController::class, 'balance']);
        Route::get('carriers/{carrier}/groups', [\App\Http\Controllers\Api\V1\Flight\FlightGroupController::class, 'getByCarrier']);
        Route::get('groups/{group}/statement', [\App\Http\Controllers\Api\V1\Flight\FlightGroupController::class, 'statement']);
        Route::post('groups/{group}/pay-debt', [\App\Http\Controllers\Api\V1\Flight\FlightGroupController::class, 'payDebt']);
        Route::apiResource('groups', \App\Http\Controllers\Api\V1\Flight\FlightGroupController::class)->names('flight_groups')->only(['index', 'show']);

        // Airports endpoints
        Route::prefix('airports')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Flight\AirportController::class, 'index']);
            Route::get('/search', [\App\Http\Controllers\Api\V1\Flight\AirportController::class, 'search']);
            Route::get('/popular', [\App\Http\Controllers\Api\V1\Flight\AirportController::class, 'popular']);
            Route::get('/by-iata', [\App\Http\Controllers\Api\V1\Flight\AirportController::class, 'getByIata']);
            Route::get('/grouped', [\App\Http\Controllers\Api\V1\Flight\AirportController::class, 'groupedByCountry']);
        });

        // Flight booking specific routes
        Route::post('bookings/{flightBooking}/prices', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'updatePrices']);
        Route::post('bookings/{flightBooking}/confirm', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'confirm']);
        Route::post('bookings/{flightBooking}/payments', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'addPayment']);
        Route::post('bookings/{flightBooking}/send-ticket-email', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'sendTicketEmail']);
        Route::post('bookings/{flightBooking}/cancel', [\App\Http\Controllers\Api\V1\Flight\FlightController::class, 'cancel']);

        // Airline accounts API
        Route::prefix('airline-accounts')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'destroy']);
            Route::post('/add-credit', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'addCredit']);
            Route::get('/{accountId}/transactions', [\App\Http\Controllers\Api\V1\Flight\AirlineAccountController::class, 'transactions']);
        });

        // Ticket Refund System (Multi-Currency) API
        Route::prefix('refunds')->group(function () {
            Route::get('/treasuries', [\App\Http\Controllers\Api\V1\Flight\RefundController::class, 'treasuries']);
            Route::get('/airline-credits', [\App\Http\Controllers\Api\V1\Flight\RefundController::class, 'airlineCredits']);
            Route::post('/', [\App\Http\Controllers\Api\V1\Flight\RefundController::class, 'store']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\Flight\RefundController::class, 'show']);
            Route::post('/{id}/process', [\App\Http\Controllers\Api\V1\Flight\RefundController::class, 'process']);
        });

        // Ticket Modification System API
        Route::prefix('modifications')->group(function () {
            Route::post('/', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'store']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'show']);
            Route::patch('/{id}/status', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'updateStatus']);
            Route::post('/{id}/confirm', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'confirm']);
            Route::post('/{id}/reconcile', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'reconcile']);
        });

        Route::get('bookings/{id}/modifications', [\App\Http\Controllers\Api\V1\Flight\ModificationController::class, 'bookingModifications']);
    });

    // Bus API
        Route::prefix('bus')->group(function () {
            Route::get('inventories/available', [\App\Http\Controllers\Api\V1\Bus\BusInventoryController::class, 'available']);
            Route::get('bookings/stats', [\App\Http\Controllers\Api\V1\Bus\BusBookingController::class, 'stats']);
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\Bus\BusDashboardController::class, 'index']);

            Route::prefix('treasury')->group(function () {
                Route::get('overview', [\App\Http\Controllers\Api\V1\Bus\BusTreasuryController::class, 'overview']);
                Route::get('accounts/{account}/bus-transactions', [\App\Http\Controllers\Api\V1\Bus\BusTreasuryController::class, 'accountBusTransactions']);
            });

            Route::get('customers', [\App\Http\Controllers\Api\V1\Bus\BusCustomerController::class, 'index']);
            Route::get('companies/{company}/statement', [\App\Http\Controllers\Api\V1\Bus\BusCompanyController::class, 'statement']);
            Route::post('companies/{company}/pay-debt', [\App\Http\Controllers\Api\V1\Bus\BusCompanyController::class, 'payDebt']);
            Route::apiResource('companies', \App\Http\Controllers\Api\V1\Bus\BusCompanyController::class);
            Route::apiResource('inventories', \App\Http\Controllers\Api\V1\Bus\BusInventoryController::class)
                ->parameters(['inventories' => 'busInventory']);
            Route::apiResource('bookings', \App\Http\Controllers\Api\V1\Bus\BusBookingController::class)
                ->except(['update'])
                ->parameters(['bookings' => 'busBooking'])
                ->names('bus_bookings');

            Route::post('inventories/{busInventory}/pay-debt', [\App\Http\Controllers\Api\V1\Bus\BusInventoryController::class, 'payDebt']);

            Route::post('bookings/{busBooking}/pay', [\App\Http\Controllers\Api\V1\Bus\BusBookingController::class, 'pay']);
            Route::match(['post', 'patch'], 'bookings/{busBooking}/cancel', [\App\Http\Controllers\Api\V1\Bus\BusBookingController::class, 'cancel']);

            // Bus Refund System
            Route::prefix('refunds')->group(function () {
                Route::get('/treasuries', [\App\Http\Controllers\Api\V1\Bus\BusRefundController::class, 'treasuries']);
                Route::post('/', [\App\Http\Controllers\Api\V1\Bus\BusRefundController::class, 'store']);
                Route::get('/{id}', [\App\Http\Controllers\Api\V1\Bus\BusRefundController::class, 'show']);
                Route::post('/{id}/process', [\App\Http\Controllers\Api\V1\Bus\BusRefundController::class, 'process']);
            });
        });

    // Wallet & Transfers API
        Route::prefix('wallet')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\Wallet\TransferDashboardController::class, 'index']);
            Route::get('types', [\App\Http\Controllers\Api\V1\Wallet\WalletTypeController::class, 'index'])->name('wallet.types.index');
            Route::get('transactions/daily-summary', [\App\Http\Controllers\Api\V1\Wallet\WalletTransactionController::class, 'dailySummary'])->name('wallet.transactions.daily-summary');
            Route::apiResource('transactions', \App\Http\Controllers\Api\V1\Wallet\WalletTransactionController::class)->names('wallet_transactions');

            // Treasury API for Wallets
            Route::get('treasury/overview', [\App\Http\Controllers\Api\V1\Wallet\TransferTreasuryController::class, 'overview']);
            Route::get('treasury/accounts/{account}/transactions', [\App\Http\Controllers\Api\V1\Wallet\TransferTreasuryController::class, 'accountTransactions']);
        });

    // Online Services API
        Route::prefix('online')->group(function () {
            // Settings (master data needed for the Vue UI - everything dynamic)
            Route::prefix('settings')->group(function () {
                Route::get('all', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'all']);
                Route::get('service-types', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'serviceTypes']);
                Route::get('providers', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'providers']);
                Route::get('payment-methods', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'paymentMethods']);
                Route::get('accounts', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'accounts']);
                Route::get('customers', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'customers']);
                Route::get('employees', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'employees']);
                Route::get('statuses', [\App\Http\Controllers\Api\V1\Online\OnlineSettingsController::class, 'statuses']);
            });

            // Master CRUD: service types
            Route::get('service-types/active', [\App\Http\Controllers\Api\V1\Online\OnlineServiceTypeController::class, 'active']);
            Route::apiResource('service-types', \App\Http\Controllers\Api\V1\Online\OnlineServiceTypeController::class)
                ->parameters(['service-types' => 'onlineServiceType'])
                ->names('online_service_types');

            // Master CRUD: providers
            Route::get('providers/active', [\App\Http\Controllers\Api\V1\Online\OnlineServiceProviderController::class, 'active']);
            Route::apiResource('providers', \App\Http\Controllers\Api\V1\Online\OnlineServiceProviderController::class)
                ->parameters(['providers' => 'onlineServiceProvider'])
                ->names('online_service_providers');

            // Operations CRUD: transactions
            Route::get('transactions/daily-summary', [\App\Http\Controllers\Api\V1\Online\OnlineTransactionController::class, 'dailySummary']);
            Route::apiResource('transactions', \App\Http\Controllers\Api\V1\Online\OnlineTransactionController::class)
                ->parameters(['transactions' => 'onlineTransaction'])
                ->names('online_transactions');
        });

    // Fawry API
        Route::prefix('fawry')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\Fawry\FawryDashboardController::class, 'index']);
            Route::get('transactions/daily-summary', [\App\Http\Controllers\Api\V1\Fawry\FawryTransactionController::class, 'dailySummary']);
            Route::apiResource('transactions', \App\Http\Controllers\Api\V1\Fawry\FawryTransactionController::class)
                ->parameters(['transactions' => 'fawryTransaction'])
                ->names('fawry_transactions');

            // Fawry Treasury API
            Route::get('treasury/overview', [\App\Http\Controllers\Api\V1\Fawry\FawryTreasuryController::class, 'overview']);
            Route::get('treasury/accounts/{account}/transactions', [\App\Http\Controllers\Api\V1\Fawry\FawryTreasuryController::class, 'accountTransactions']);

            // Fawry Settings API
            Route::prefix('settings')->group(function () {
                Route::get('operation-types', [\App\Http\Controllers\Api\V1\Fawry\FawrySettingsController::class, 'operationTypes']);
                Route::get('payment-methods', [\App\Http\Controllers\Api\V1\Fawry\FawrySettingsController::class, 'paymentMethods']);
                Route::get('currencies', [\App\Http\Controllers\Api\V1\Fawry\FawrySettingsController::class, 'currencies']);
                Route::get('all', [\App\Http\Controllers\Api\V1\Fawry\FawrySettingsController::class, 'all']);
            });
        });

    // Employee API
        Route::prefix('employee')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\Employee\EmployeeDashboardController::class, 'index']);
            Route::get('employees/reference-data', [\App\Http\Controllers\Api\V1\Employee\EmployeeController::class, 'referenceData']);
            Route::get('employees/{id}/transactions', [\App\Http\Controllers\Api\V1\Employee\EmployeeController::class, 'transactions']);
            Route::apiResource('employees', \App\Http\Controllers\Api\V1\Employee\EmployeeController::class)->names('employee_employees');
            
            Route::post('bonuses/bonus', [\App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class, 'bonus']);
            Route::post('bonuses/deduction', [\App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class, 'deduction']);
            Route::post('bonuses/draw', [\App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class, 'draw']);
            Route::get('bonuses/summary', [\App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class, 'activitySummary']);
            Route::get('bonuses/employee-summary/{id}', [\App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class, 'employeeSummary']);
            Route::apiResource('bonuses', \App\Http\Controllers\Api\V1\Employee\EmployeeBonusController::class)
                ->parameters(['bonuses' => 'employeeBonus'])
                ->except(['store'])
                ->names('employee_bonuses');
            
            Route::apiResource('attendances', \App\Http\Controllers\Api\V1\Employee\AttendanceController::class)->names('employee_attendances');
            Route::apiResource('reports', \App\Http\Controllers\Api\V1\Employee\EmployeeReportController::class)->names('employee_reports');
        });

    // Reports API (Admin Only)
    Route::prefix('reports')->middleware('admin')->group(function () {
        Route::get('financial/summary', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'financialSummary']);
        Route::get('financial/accounts-balance', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'accountsBalance']);
        Route::get('transactions', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'transactions']);
        Route::get('transactions/{id}', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'transactionDetail']);
        Route::get('sales', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'sales']);
        Route::get('profit-loss', [\App\Http\Controllers\Api\V1\Reports\ReportController::class, 'profitLoss']);

        // Financial Reports
        Route::get('treasury', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'treasuryReport']);
        Route::get('profit', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'profitReport']);
        Route::get('customer-debts', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'customerDebtsReport']);
        Route::get('financial/customer-debts', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'customerDebtsReport']);
        Route::get('supplier-debts', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'supplierDebtsReport']);
        Route::get('financial/supplier-debts', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'supplierDebtsReport']);
        Route::get('debts', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'debtsReport']);
        Route::get('summary', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'financialSummary']);
        Route::get('profit-by-module', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'profitByModule']);
        Route::get('cash-flow-realtime', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'cashFlowRealtime']);
        Route::get('customer-ledger-balances', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'customerLedgerBalances']);
        Route::get('bank-ledger-reconciliation', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'bankLedgerReconciliation']);
        Route::get('ledger-reconciliation/latest', [\App\Http\Controllers\Api\V1\Reports\FinancialReportController::class, 'latestLedgerReconciliation']);
    });

    // Customers API
        Route::get('customers/{customer}/statement', [\App\Http\Controllers\Api\V1\CustomerController::class, 'statement']);
        Route::post('customers/{customer}/pay-debt', [\App\Http\Controllers\Api\V1\CustomerController::class, 'payDebt'])->name('customers.pay_debt');
        Route::apiResource('customers', \App\Http\Controllers\Api\V1\CustomerController::class)->names('customers');

    // Hajj & Umra API
        Route::prefix('hajj-umra')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraDashboardController::class, 'index']);
            Route::get('treasury/overview', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController::class, 'overview']);
            Route::get('treasury/accounts/{account}/transactions', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController::class, 'accountHajjUmraTransactions']);

            Route::get('executing-companies/dues', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController::class, 'dues']);
            Route::post('executing-companies/{company}/withdraw', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController::class, 'withdraw']);
            Route::post('executing-companies/{company}/repay', [\App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController::class, 'repay']);

            // Reference data (مدارة من Filament)
            Route::get('settings/programs', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'programs']);
            Route::get('settings/executing-companies', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'executingCompanies']);
            Route::get('settings/trip-supervisors', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'tripSupervisors']);
            Route::get('settings/accommodation-types', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'accommodationTypes']);
            Route::get('settings/statuses', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'statuses']);

            Route::get('bookings', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'index']);
            Route::post('bookings', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'store']);
            Route::get('bookings/{hajjUmra}', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'show']);
            Route::match(['put', 'patch'], 'bookings/{hajjUmra}', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'update']);
            Route::delete('bookings/{hajjUmra}', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'destroy']);
            Route::post('bookings/{hajjUmra}/payments', [\App\Http\Controllers\Api\V1\HajjUmraController::class, 'addPayment']);
        });

        // Visa API
        Route::prefix('visa')->group(function () {
            Route::get('settings/agents', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'visaAgents']);
            Route::get('settings/durations', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'visaDurations']);
            Route::get('settings/statuses', [\App\Http\Controllers\Api\V1\HajjUmraReferenceController::class, 'statuses']);

            Route::get('treasury/overview', [\App\Http\Controllers\Api\V1\Visa\VisaTreasuryController::class, 'overview']);
            Route::get('treasury/accounts/{account}/transactions', [\App\Http\Controllers\Api\V1\Visa\VisaTreasuryController::class, 'accountVisaTransactions']);

            Route::get('agents/dues', [\App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController::class, 'dues']);
            Route::post('agents/{agent}/withdraw', [\App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController::class, 'withdraw']);
            Route::post('agents/{agent}/repay', [\App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController::class, 'repay']);

            Route::get('bookings', [\App\Http\Controllers\Api\V1\VisaController::class, 'index']);
            Route::post('bookings', [\App\Http\Controllers\Api\V1\VisaController::class, 'store']);
            Route::get('bookings/{visa}', [\App\Http\Controllers\Api\V1\VisaController::class, 'show']);
            Route::match(['put', 'patch'], 'bookings/{visa}', [\App\Http\Controllers\Api\V1\VisaController::class, 'update']);
            Route::delete('bookings/{visa}', [\App\Http\Controllers\Api\V1\VisaController::class, 'destroy']);
            Route::post('bookings/{visa}/payments', [\App\Http\Controllers\Api\V1\VisaController::class, 'addPayment']);
        });

    // Invoices API
        Route::apiResource('invoices', \App\Http\Controllers\Api\V1\InvoiceController::class)->names('invoices');

    // Suppliers API
    Route::apiResource('suppliers', \App\Http\Controllers\Api\V1\SupplierController::class);

    // Users Management API (Admin Only)
    Route::apiResource('users', \App\Http\Controllers\Api\V1\UserController::class)->middleware('admin');
});
