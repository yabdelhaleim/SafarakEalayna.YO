<?php

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\Bus\BusBookingController;
use App\Http\Controllers\Api\V1\Bus\BusCompanyController;
use App\Http\Controllers\Api\V1\Bus\BusCustomerController;
use App\Http\Controllers\Api\V1\Bus\BusDashboardController;
use App\Http\Controllers\Api\V1\Bus\BusInventoryController;
use App\Http\Controllers\Api\V1\Bus\BusRefundController;
use App\Http\Controllers\Api\V1\Bus\BusTreasuryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Employee\AttendanceController;
use App\Http\Controllers\Api\V1\Employee\EmployeeBonusController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Employee\EmployeeDashboardController;
use App\Http\Controllers\Api\V1\Employee\EmployeeReportController;
use App\Http\Controllers\Api\V1\Fawry\FawryDashboardController;
use App\Http\Controllers\Api\V1\Fawry\FawryMachineApiController;
use App\Http\Controllers\Api\V1\Fawry\FawrySettingsController;
use App\Http\Controllers\Api\V1\Fawry\FawryTransactionController;
use App\Http\Controllers\Api\V1\Fawry\FawryTreasuryController;
use App\Http\Controllers\Api\V1\Finance\AccountController;
use App\Http\Controllers\Api\V1\Finance\SupplierAccountController;
use App\Http\Controllers\Api\V1\Finance\ApprovalController;
use App\Http\Controllers\Api\V1\Finance\AuditController;
use App\Http\Controllers\Api\V1\Finance\CurrencyController;
use App\Http\Controllers\Api\V1\Finance\TransactionController;
use App\Http\Controllers\Api\V1\Finance\TreasuryController;
use App\Http\Controllers\Api\V1\Flight\AirlineAccountController;
use App\Http\Controllers\Api\V1\Flight\AirportController;
use App\Http\Controllers\Api\V1\Flight\AviationController;
use App\Http\Controllers\Api\V1\Flight\FlightCarrierController;
use App\Http\Controllers\Api\V1\Flight\FlightController;
use App\Http\Controllers\Api\V1\Flight\FlightDashboardController;
use App\Http\Controllers\Api\V1\Flight\PassengerController;
use App\Http\Controllers\Api\V1\Flight\FlightGroupController;
use App\Http\Controllers\Api\V1\Flight\FlightSystemController;
use App\Http\Controllers\Api\V1\Flight\FlightTreasuryController;
use App\Http\Controllers\Api\V1\Flight\ModificationController;
use App\Http\Controllers\Api\V1\Flight\RefundController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraDashboardController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraProgramController;
use App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController;
use App\Http\Controllers\Api\V1\HajjUmra\UmrahSupplierApiController;
use App\Http\Controllers\Api\V1\HajjUmraController;
use App\Http\Controllers\Api\V1\HajjUmraReferenceController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\Online\OnlineServiceProviderController;
use App\Http\Controllers\Api\V1\Online\OnlineServiceTypeController;
use App\Http\Controllers\Api\V1\Online\OnlineSettingsController;
use App\Http\Controllers\Api\V1\Online\OnlineTransactionController;
use App\Http\Controllers\Api\V1\PrintSettingController;
use App\Http\Controllers\Api\V1\Reports\FinancialReportController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\Visa\VisaAgentApiController;
use App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController;
use App\Http\Controllers\Api\V1\Visa\VisaTreasuryController;
use App\Http\Controllers\Api\V1\VisaController;
use App\Http\Controllers\Api\V1\Wallet\TransferDashboardController;
use App\Http\Controllers\Api\V1\Wallet\TransferTreasuryController;
use App\Http\Controllers\Api\V1\Wallet\WalletTransactionController;
use App\Http\Controllers\Api\V1\Wallet\WalletTypeController;
use App\Http\Middleware\CaptureFinancialPostingContext;
use App\Http\Middleware\RejectBannedFinancialBypassMarkers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes (No middleware required)
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public routes for booking forms (no authentication required)
Route::prefix('v1/public')->group(function () {
    Route::get('/bus/companies', [BusCompanyController::class, 'publicIndex']);

    Route::get('/bus/inventories/available', [BusInventoryController::class, 'available']);
});

// Protected Authentication Routes (Require authentication)
Route::prefix('v1/auth')->middleware('auth:sanctum')->group(function () {
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware([
    'auth:sanctum',
    'active',
    CaptureFinancialPostingContext::class,
    RejectBannedFinancialBypassMarkers::class,
])->group(function () {
    // Health Check
    Route::get('/health', function () {
        return ApiResponse::success('System is operational', [
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Aviation Next Number route for the frontend
    Route::get('aviation/next-number', [AviationController::class, 'nextNumber']);

    Route::prefix('settings')->group(function () {
        Route::get('payment-methods', [SettingController::class, 'paymentMethods']);
        Route::get('operation-types', [SettingController::class, 'operationTypes']);
        Route::get('currencies', [SettingController::class, 'currencies']);
        Route::get('trip-types', [SettingController::class, 'tripTypes']);
        Route::get('account-types', [SettingController::class, 'accountTypes']);
        Route::get('transaction-types', [SettingController::class, 'transactionTypes']);
        Route::get('transaction-modules', [SettingController::class, 'transactionModules']);
        Route::get('flight-booking-reference', [SettingController::class, 'flightBookingReference']);
        Route::get('print', [PrintSettingController::class, 'show']);
        Route::middleware('admin')->put('print', [PrintSettingController::class, 'update']);
    });

    // Dashboard API (Admin Only)
    Route::middleware('admin')->get('dashboard', [DashboardController::class, 'index']);

    // Finance API
    Route::prefix('finance')->group(function () {
        // Accounts listing is accessible to all authenticated users (to record payments)
        Route::get('accounts', [AccountController::class, 'index'])->name('finance_accounts.index');

        Route::middleware('admin')->group(function () {
            Route::apiResource('accounts', AccountController::class)->except(['index', 'destroy'])->names('finance_accounts');
            Route::get('treasuries/get-overview', [TreasuryController::class, 'getOverview']);
            Route::get('treasuries/export-trial-balance', [TreasuryController::class, 'exportTrialBalance']);
            Route::get('treasuries/get-module-accounts/{module}', [TreasuryController::class, 'getModuleAccounts']);
            Route::apiResource('treasuries', TreasuryController::class)->names('finance_treasuries');
            Route::post('currencies/convert', [CurrencyController::class, 'convert']);
            Route::get('currencies/active-rates', [CurrencyController::class, 'getActiveRates']);
            Route::post('currencies/set-rate', [CurrencyController::class, 'setRate']);
            Route::apiResource('currencies', CurrencyController::class)->names('finance_currencies');
            Route::apiResource('approvals', ApprovalController::class);
            Route::apiResource('audits', AuditController::class);

            // Account specific routes
            Route::post('accounts/{account}/deactivate', [AccountController::class, 'deactivate']);
            Route::get('accounts/{account}/statement', [AccountController::class, 'statement']);
            Route::post('transfers', [AccountController::class, 'transfer']);
            Route::get('transfers', [AccountController::class, 'transferHistory']);

            // Transactions resource routes
            Route::apiResource('transactions', TransactionController::class)->except(['index', 'show'])->names('finance_transactions');
        });
    });

    // Flights API
    Route::prefix('flight')->group(function () {
        Route::get('booking-form/employees', [FlightController::class, 'employeesForBooking']);
        Route::apiResource('bookings', FlightController::class)
            ->parameters(['bookings' => 'flightBooking'])
            ->names('flight_bookings');
        Route::apiResource('aviation', AviationController::class);

        // System types endpoint
        Route::get('system-types', [FlightController::class, 'systemTypes']);

        // Dashboard Endpoint
        Route::get('dashboard', [FlightDashboardController::class, 'index']);

        Route::prefix('treasury')->group(function () {
            Route::get('overview', [FlightTreasuryController::class, 'overview']);
            Route::get('systems/{system}/transactions', [FlightTreasuryController::class, 'systemTransactions']);
            Route::post('systems/{system}/recharge', [FlightTreasuryController::class, 'rechargeSystem']);
            Route::get('accounts/{account}/flight-transactions', [FlightTreasuryController::class, 'accountFlightTransactions']);
        });

        // Flight Systems & Carriers endpoints
        Route::apiResource('systems', FlightSystemController::class)->names('flight_systems');
        Route::apiResource('carriers', FlightCarrierController::class)->names('flight_carriers');
        Route::get('carriers/{carrier}/balance', [FlightCarrierController::class, 'balance']);
        Route::post('carriers/{carrier}/recharge', [FlightCarrierController::class, 'recharge']);
        Route::get('carriers/{carrier}/groups', [FlightGroupController::class, 'getByCarrier']);
        Route::get('groups/{group}/statement', [FlightGroupController::class, 'statement']);
        Route::post('groups/{group}/pay-debt', [FlightGroupController::class, 'payDebt']);
        Route::apiResource('groups', FlightGroupController::class)->names('flight_groups')->only(['index', 'show']);

        // Airports endpoints
        Route::prefix('airports')->group(function () {
            Route::get('/', [AirportController::class, 'index']);
            Route::get('/search', [AirportController::class, 'search']);
            Route::get('/popular', [AirportController::class, 'popular']);
            Route::get('/by-iata', [AirportController::class, 'getByIata']);
            Route::get('/grouped', [AirportController::class, 'groupedByCountry']);
        });

        // Flight booking specific routes
        Route::post('bookings/{flightBooking}/prices', [FlightController::class, 'updatePrices']);
        Route::post('bookings/{flightBooking}/confirm', [FlightController::class, 'confirm']);
        Route::post('bookings/{flightBooking}/payments', [FlightController::class, 'addPayment']);
        Route::post('bookings/{flightBooking}/send-ticket-email', [FlightController::class, 'sendTicketEmail']);
        Route::post('bookings/{flightBooking}/cancel', [FlightController::class, 'cancel']);

        // Airline accounts API
        Route::prefix('airline-accounts')->group(function () {
            Route::get('/', [AirlineAccountController::class, 'index']);
            Route::post('/', [AirlineAccountController::class, 'store']);
            Route::put('/{id}', [AirlineAccountController::class, 'update']);
            Route::delete('/{id}', [AirlineAccountController::class, 'destroy']);
            Route::post('/add-credit', [AirlineAccountController::class, 'addCredit']);
            Route::get('/{accountId}/transactions', [AirlineAccountController::class, 'transactions']);
        });

        // Ticket Refund System (Multi-Currency) API
        Route::prefix('refunds')->group(function () {
            Route::get('/treasuries', [RefundController::class, 'treasuries']);
            Route::get('/airline-credits', [RefundController::class, 'airlineCredits']);
            Route::post('/', [RefundController::class, 'store']);
            Route::get('/{id}', [RefundController::class, 'show']);
            Route::post('/{id}/process', [RefundController::class, 'process']);
        });

        // Ticket Modification System API
        Route::prefix('modifications')->group(function () {
            Route::post('/', [ModificationController::class, 'store']);
            Route::get('/{id}', [ModificationController::class, 'show']);
            Route::patch('/{id}/status', [ModificationController::class, 'updateStatus']);
            Route::post('/{id}/confirm', [ModificationController::class, 'confirm']);
            Route::post('/{id}/reconcile', [ModificationController::class, 'reconcile']);
        });

        Route::get('bookings/{id}/modifications', [ModificationController::class, 'bookingModifications']);

        // Passengers Directory & Notifications routes
        Route::prefix('passengers')->group(function () {
            Route::get('/', [PassengerController::class, 'index']);
            Route::get('/alert-settings', [PassengerController::class, 'getAlertSettings']);
            Route::put('/alert-settings', [PassengerController::class, 'updateAlertSettings']);
            Route::get('/notifications', [PassengerController::class, 'getNotifications']);
            Route::post('/notifications/mark-all-read', [PassengerController::class, 'markAllNotificationsRead']);
            Route::post('/notifications/{id}/mark-read', [PassengerController::class, 'markNotificationRead']);
        });
    });

    // Bus API
    Route::prefix('bus')->group(function () {
        Route::get('inventories/available', [BusInventoryController::class, 'available']);
        Route::get('bookings/stats', [BusBookingController::class, 'stats']);
        Route::get('dashboard', [BusDashboardController::class, 'index']);

        Route::prefix('treasury')->group(function () {
            Route::get('overview', [BusTreasuryController::class, 'overview']);
            Route::get('accounts/{account}/bus-transactions', [BusTreasuryController::class, 'accountBusTransactions']);
        });

        Route::get('customers', [BusCustomerController::class, 'index']);
        Route::get('companies/{company}/statement', [BusCompanyController::class, 'statement']);
        Route::post('companies/{company}/pay-debt', [BusCompanyController::class, 'payDebt']);
        Route::apiResource('companies', BusCompanyController::class);
        Route::apiResource('inventories', BusInventoryController::class)
            ->parameters(['inventories' => 'busInventory']);
        Route::apiResource('bookings', BusBookingController::class)
            ->except(['update'])
            ->parameters(['bookings' => 'busBooking'])
            ->names('bus_bookings');

        Route::post('inventories/{busInventory}/pay-debt', [BusInventoryController::class, 'payDebt']);

        Route::post('bookings/{busBooking}/pay', [BusBookingController::class, 'pay']);
        Route::match(['post', 'patch'], 'bookings/{busBooking}/cancel', [BusBookingController::class, 'cancel']);

        // Bus Refund System
        Route::prefix('refunds')->group(function () {
            Route::get('/treasuries', [BusRefundController::class, 'treasuries']);
            Route::post('/', [BusRefundController::class, 'store']);
            Route::get('/{id}', [BusRefundController::class, 'show']);
            Route::post('/{id}/process', [BusRefundController::class, 'process']);
        });
    });

    // Wallet & Transfers API
    Route::prefix('wallet')->group(function () {
        Route::get('dashboard', [TransferDashboardController::class, 'index']);
        Route::get('types', [WalletTypeController::class, 'index'])->name('wallet.types.index');
        Route::get('customer-balances', [WalletTransactionController::class, 'customerBalances']);
        Route::get('customer-statement', [WalletTransactionController::class, 'customerStatement']);
        Route::get('transactions/daily-summary', [WalletTransactionController::class, 'dailySummary'])->name('wallet.transactions.daily-summary');
        Route::apiResource('transactions', WalletTransactionController::class)->names('wallet_transactions');

        // Treasury API for Wallets
        Route::get('treasury/overview', [TransferTreasuryController::class, 'overview']);
        Route::get('treasury/accounts/{account}/transactions', [TransferTreasuryController::class, 'accountTransactions']);
    });

    // Online Services API
    Route::prefix('online')->group(function () {
        // Settings (master data needed for the Vue UI - everything dynamic)
        Route::prefix('settings')->group(function () {
            Route::get('all', [OnlineSettingsController::class, 'all']);
            Route::get('service-types', [OnlineSettingsController::class, 'serviceTypes']);
            Route::get('providers', [OnlineSettingsController::class, 'providers']);
            Route::get('payment-methods', [OnlineSettingsController::class, 'paymentMethods']);
            Route::get('accounts', [OnlineSettingsController::class, 'accounts']);
            Route::get('customers', [OnlineSettingsController::class, 'customers']);
            Route::get('employees', [OnlineSettingsController::class, 'employees']);
            Route::get('statuses', [OnlineSettingsController::class, 'statuses']);
        });

        // Master CRUD: service types
        Route::get('service-types/active', [OnlineServiceTypeController::class, 'active']);
        Route::apiResource('service-types', OnlineServiceTypeController::class)
            ->parameters(['service-types' => 'onlineServiceType'])
            ->names('online_service_types');

        // Master CRUD: providers
        Route::get('providers/active', [OnlineServiceProviderController::class, 'active']);
        Route::apiResource('providers', OnlineServiceProviderController::class)
            ->parameters(['providers' => 'onlineServiceProvider'])
            ->names('online_service_providers');

        // Operations CRUD: transactions
        Route::get('customer-balances', [OnlineTransactionController::class, 'customerBalances']);
        Route::get('customer-statement', [OnlineTransactionController::class, 'customerStatement']);
        Route::get('transactions/daily-summary', [OnlineTransactionController::class, 'dailySummary']);
        Route::apiResource('transactions', OnlineTransactionController::class)
            ->parameters(['transactions' => 'onlineTransaction'])
            ->names('online_transactions');
    });

    // Fawry API
    Route::prefix('fawry')->group(function () {
        Route::get('dashboard', [FawryDashboardController::class, 'index']);

        // Fawry Machines API
        Route::prefix('machines')->group(function () {
            Route::get('/', [FawryMachineApiController::class, 'index']);
            Route::get('/{id}/transactions', [FawryMachineApiController::class, 'transactions']);
            Route::post('/{id}/recharge', [FawryMachineApiController::class, 'recharge']);
        });
        Route::get('accounts', [FawryMachineApiController::class, 'fawryAccounts']);
        Route::get('customer-balances', [FawryTransactionController::class, 'customerBalances']);
        Route::get('customer-statement', [FawryTransactionController::class, 'customerStatement']);
        Route::get('transactions/daily-summary', [FawryTransactionController::class, 'dailySummary']);
        Route::apiResource('transactions', FawryTransactionController::class)
            ->parameters(['transactions' => 'fawryTransaction'])
            ->names('fawry_transactions');

        // Fawry Treasury API
        Route::get('treasury/overview', [FawryTreasuryController::class, 'overview']);
        Route::get('treasury/accounts/{account}/transactions', [FawryTreasuryController::class, 'accountTransactions']);

        // Fawry Settings API
        Route::prefix('settings')->group(function () {
            Route::get('operation-types', [FawrySettingsController::class, 'operationTypes']);
            Route::get('payment-methods', [FawrySettingsController::class, 'paymentMethods']);
            Route::get('currencies', [FawrySettingsController::class, 'currencies']);
            Route::get('all', [FawrySettingsController::class, 'all']);
        });
    });

    // Employee API
    Route::prefix('employee')->group(function () {
        Route::get('dashboard', [EmployeeDashboardController::class, 'index']);
        Route::get('employees/reference-data', [EmployeeController::class, 'referenceData']);
        Route::get('employees/{id}/transactions', [EmployeeController::class, 'transactions']);
        Route::apiResource('employees', EmployeeController::class)->names('employee_employees');

        Route::post('bonuses/bonus', [EmployeeBonusController::class, 'bonus']);
        Route::post('bonuses/deduction', [EmployeeBonusController::class, 'deduction']);
        Route::post('bonuses/draw', [EmployeeBonusController::class, 'draw']);
        Route::get('bonuses/summary', [EmployeeBonusController::class, 'activitySummary']);
        Route::get('bonuses/employee-summary/{id}', [EmployeeBonusController::class, 'employeeSummary']);
        Route::apiResource('bonuses', EmployeeBonusController::class)
            ->parameters(['bonuses' => 'employeeBonus'])
            ->except(['store'])
            ->names('employee_bonuses');

        Route::apiResource('attendances', AttendanceController::class)->names('employee_attendances');
        Route::apiResource('reports', EmployeeReportController::class)->names('employee_reports');
    });

    // Reports API (Admin Only)
    Route::prefix('reports')->middleware('admin')->group(function () {
        Route::get('financial/summary', [ReportController::class, 'financialSummary']);
        Route::get('financial/accounts-balance', [ReportController::class, 'accountsBalance']);
        Route::get('transactions', [ReportController::class, 'transactions']);
        Route::get('transactions/{id}', [ReportController::class, 'transactionDetail']);
        Route::get('sales', [ReportController::class, 'sales']);
        Route::get('profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('finance/operations', [ReportController::class, 'financeOperations']);

        // Financial Reports
        Route::get('treasury', [FinancialReportController::class, 'treasuryReport']);
        Route::get('profit', [FinancialReportController::class, 'profitReport']);
        Route::get('customer-debts', [FinancialReportController::class, 'customerDebtsReport']);
        Route::get('financial/customer-debts', [FinancialReportController::class, 'customerDebtsReport']);
        Route::get('supplier-debts', [FinancialReportController::class, 'supplierDebtsReport']);
        Route::get('financial/supplier-debts', [FinancialReportController::class, 'supplierDebtsReport']);
        Route::get('debts', [FinancialReportController::class, 'debtsReport']);
        Route::get('summary', [FinancialReportController::class, 'financialSummary']);
        Route::get('profit-by-module', [FinancialReportController::class, 'profitByModule']);
        Route::get('cash-flow-realtime', [FinancialReportController::class, 'cashFlowRealtime']);
        Route::get('customer-ledger-balances', [FinancialReportController::class, 'customerLedgerBalances']);
        Route::get('bank-ledger-reconciliation', [FinancialReportController::class, 'bankLedgerReconciliation']);
        Route::get('ledger-reconciliation/latest', [FinancialReportController::class, 'latestLedgerReconciliation']);
        Route::get('capital-analysis', [FinancialReportController::class, 'capitalAnalysis']);
        Route::get('trial-balance', [FinancialReportController::class, 'trialBalance']);
        Route::get('office-trial-balance', [FinancialReportController::class, 'officeTrialBalance']);
        Route::get('flights/detailed', [FinancialReportController::class, 'detailedFlightReport']);
    });

    // Customers API
    Route::get('customers/{customer}/statement', [CustomerController::class, 'statement']);
    Route::post('customers/{customer}/pay-debt', [CustomerController::class, 'payDebt'])->name('customers.pay_debt');
    Route::apiResource('customers', CustomerController::class)->names('customers');

    // Hajj & Umra API
    Route::prefix('hajj-umra')->group(function () {
        Route::get('dashboard', [HajjUmraDashboardController::class, 'index']);
        Route::get('treasury/overview', [HajjUmraTreasuryController::class, 'overview']);
        Route::get('treasury/accounts/{account}/transactions', [HajjUmraTreasuryController::class, 'accountHajjUmraTransactions']);

        Route::get('executing-companies/dues', [HajjUmraExecutingCompanyFinanceController::class, 'dues']);
        Route::post('executing-companies/{company}/withdraw', [HajjUmraExecutingCompanyFinanceController::class, 'withdraw']);
        Route::post('executing-companies/{company}/repay', [HajjUmraExecutingCompanyFinanceController::class, 'repay']);

        Route::get('programs', [HajjUmraProgramController::class, 'index']);
        Route::post('programs', [HajjUmraProgramController::class, 'store']);
        Route::get('programs/{program}', [HajjUmraProgramController::class, 'show']);
        Route::match(['put', 'patch'], 'programs/{program}', [HajjUmraProgramController::class, 'update']);

        // Reference data (مدارة من Filament)
        Route::get('settings/programs', [HajjUmraReferenceController::class, 'programs']);
        Route::get('settings/executing-companies', [HajjUmraReferenceController::class, 'executingCompanies']);
        Route::get('settings/trip-supervisors', [HajjUmraReferenceController::class, 'tripSupervisors']);
        Route::get('settings/accommodation-types', [HajjUmraReferenceController::class, 'accommodationTypes']);
        Route::get('settings/statuses', [HajjUmraReferenceController::class, 'statuses']);

        Route::get('customer-balances', [HajjUmraController::class, 'customerBalances']);
        Route::get('customer-statement', [HajjUmraController::class, 'customerStatement']);

        Route::get('bookings', [HajjUmraController::class, 'index']);
        Route::post('bookings', [HajjUmraController::class, 'store']);
        Route::get('bookings/{hajjUmra}', [HajjUmraController::class, 'show']);
        Route::match(['put', 'patch'], 'bookings/{hajjUmra}', [HajjUmraController::class, 'update']);
        Route::delete('bookings/{hajjUmra}', [HajjUmraController::class, 'destroy']);
        Route::post('bookings/{hajjUmra}/payments', [HajjUmraController::class, 'addPayment']);
    });

    // Visa API
    Route::prefix('visa')->group(function () {
        Route::get('settings/agents', [HajjUmraReferenceController::class, 'visaAgents']);
        Route::get('settings/durations', [HajjUmraReferenceController::class, 'visaDurations']);
        Route::get('settings/statuses', [HajjUmraReferenceController::class, 'statuses']);

        Route::get('treasury/overview', [VisaTreasuryController::class, 'overview']);
        Route::get('treasury/accounts/{account}/transactions', [VisaTreasuryController::class, 'accountVisaTransactions']);

        Route::get('agents/dues', [VisaAgentFinanceController::class, 'dues']);
        Route::post('agents/{agent}/withdraw', [VisaAgentFinanceController::class, 'withdraw']);
        Route::post('agents/{agent}/repay', [VisaAgentFinanceController::class, 'repay']);

        Route::get('bookings', [VisaController::class, 'index']);
        Route::post('bookings', [VisaController::class, 'store']);
        Route::get('bookings/{visa}', [VisaController::class, 'show']);
        Route::match(['put', 'patch'], 'bookings/{visa}', [VisaController::class, 'update']);
        Route::delete('bookings/{visa}', [VisaController::class, 'destroy']);
        Route::post('bookings/{visa}/payments', [VisaController::class, 'addPayment']);

        // مديونيات عملاء التأشيرات
        Route::get('customer-balances', [VisaController::class, 'customerBalances']);
        Route::get('customer-statement', [VisaController::class, 'customerStatement']);
        Route::post('customers/{customer}/pay-debt', [VisaController::class, 'payCustomerDebt']);
    });

    // Invoices API
    Route::apiResource('invoices', InvoiceController::class)->names('invoices');

    // Suppliers API
    Route::apiResource('suppliers', SupplierController::class);
    Route::prefix('suppliers')->group(function () {
        Route::match(['get', 'post'], '/{supplier}/account/recharge', [SupplierAccountController::class, 'recharge']);
        Route::get('/{supplier}/account/statement', [SupplierAccountController::class, 'statement']);
        Route::get('/{supplier}/account/balance', [SupplierAccountController::class, 'balance']);
    });

    // Users Management API (Admin Only)
    Route::apiResource('users', UserController::class)->middleware('admin');

    // Improvements Endpoints inside V1
    Route::get('visa-agents', [VisaAgentApiController::class, 'index']);
    Route::post('visa-agents', [VisaAgentApiController::class, 'store']);
    Route::get('visa-agents/{id}/cost-price', [VisaAgentApiController::class, 'costPrice']);
    Route::get('umrah-suppliers', [UmrahSupplierApiController::class, 'index']);
    Route::post('umrah-suppliers', [UmrahSupplierApiController::class, 'store']);
    Route::get('clients', [CustomerController::class, 'search']);
});

// Global API aliases without v1 prefix for backward compatibility and specific tool queries
Route::middleware('auth:sanctum')->group(function () {
    Route::get('visa-agents', [VisaAgentApiController::class, 'index']);
    Route::post('visa-agents', [VisaAgentApiController::class, 'store']);
    Route::get('visa-agents/{id}/cost-price', [VisaAgentApiController::class, 'costPrice']);
    Route::get('umrah-suppliers', [UmrahSupplierApiController::class, 'index']);
    Route::post('umrah-suppliers', [UmrahSupplierApiController::class, 'store']);
    Route::get('clients', [CustomerController::class, 'search']);
    Route::get('accounts', [AccountController::class, 'index']);
});
