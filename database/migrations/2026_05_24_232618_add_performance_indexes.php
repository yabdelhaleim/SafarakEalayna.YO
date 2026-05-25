<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $safeAddIndexes = function(string $tableName, array $columns) {
            if (!Schema::hasTable($tableName)) {
                return;
            }
            $indexes = Schema::getIndexes($tableName);
            $indexNames = array_column($indexes, 'name');

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns, $indexNames) {
                foreach ($columns as $col) {
                    if (is_array($col)) {
                        // Composite index: e.g. [['customer_id', 'status'], 'idx_name']
                        $columnList = $col[0];
                        $customName = $col[1] ?? null;
                        $indexName = $customName ?: ($tableName . '_' . implode('_', $columnList) . '_index');
                        if (!in_array($indexName, $indexNames)) {
                            if ($customName) {
                                $table->index($columnList, $customName);
                            } else {
                                $table->index($columnList);
                            }
                        }
                    } else {
                        // Single column index
                        $indexName = $tableName . '_' . $col . '_index';
                        if (!in_array($indexName, $indexNames)) {
                            if (Schema::hasColumn($tableName, $col)) {
                                $table->index($col);
                            }
                        }
                    }
                }
            });
        };

        // ===== HIGH-PRIORITY: Zero-index tables =====
        $safeAddIndexes('wallet_transactions', [
            'wallet_type_id', 'customer_id', 'wallet_account_id', 'cash_account_id',
            'income_transaction_id', 'expense_transaction_id', 'employee_id', 'created_by',
            'type', 'created_at'
        ]);

        $safeAddIndexes('refund_requests', ['flight_booking_id', 'treasury_id', 'status', 'created_at']);
        $safeAddIndexes('airline_credits', ['flight_carrier_id', 'customer_id', 'flight_booking_id', 'refund_request_id', 'status']);
        $safeAddIndexes('ticket_modifications', ['booking_id', 'status', 'reconciliation_status', 'created_at']);
        $safeAddIndexes('bus_refund_requests', ['bus_booking_id', 'company_id', 'status', 'created_at']);
        $safeAddIndexes('flight_pricings', ['flight_booking_id']);

        // ===== HIGH-PRIORITY: FK indexes on booking/payment tables =====
        $safeAddIndexes('flight_bookings', [
            [['customer_id', 'status'], 'idx_flight_bookings_customer_status'],
            'employee_id', 'flight_group_id', 'account_id', 'airline_account_id',
            'from_airport_id', 'to_airport_id', 'sale_gl_transaction_id', 'system_type',
            'departure_date', 'created_at'
        ]);

        $safeAddIndexes('flight_payments', ['account_id', 'transaction_id', 'payment_date', 'created_at']);
        $safeAddIndexes('flight_refunds', ['transaction_id']);
        $safeAddIndexes('airline_transactions', ['flight_carrier_id', 'created_at']);
        $safeAddIndexes('flight_system_transactions', ['created_at']);
        $safeAddIndexes('flight_groups', ['account_id', 'created_at']);

        // ===== HIGH-PRIORITY: Bus module indexes =====
        $safeAddIndexes('bus_bookings', ['account_id', 'payment_status', 'created_at']);
        $safeAddIndexes('bus_payments', ['account_id', 'transaction_id', 'created_at']);
        $safeAddIndexes('bus_company_payments', ['account_id', 'transaction_id', 'created_at']);
        $safeAddIndexes('bus_inventories', ['account_id', 'payment_type', 'created_at']);
        $safeAddIndexes('bus_companies', ['account_id']);
        $safeAddIndexes('bus_tickets', ['from_city', 'to_city', 'departure_date', 'passenger_name']);

        // ===== HIGH-PRIORITY: Hajj/Umra module indexes =====
        $safeAddIndexes('hajj_umra_bookings', ['customer_id', 'program_id', 'account_id', 'employee_id', 'expense_transaction_id', 'income_transaction_id', 'created_at']);
        $safeAddIndexes('hajj_umra_payments', ['hajj_umra_booking_id', 'account_id', 'transaction_id']);

        // ===== HIGH-PRIORITY: Visa module indexes =====
        $safeAddIndexes('visa_bookings', ['customer_id', 'visa_detail_id', 'account_id', 'employee_id', 'expense_transaction_id', 'income_transaction_id', 'created_at']);
        $safeAddIndexes('visa_payments', ['visa_booking_id', 'account_id', 'transaction_id']);
        $safeAddIndexes('visa_details', ['visa_agent_id', 'visa_duration_id', 'country']);

        // ===== HIGH-PRIORITY: Fawry module indexes =====
        $safeAddIndexes('fawry_transactions', ['account_id', 'operation_type', 'currency_id', 'client_id', 'expense_transaction_id', 'income_transaction_id', 'created_at']);

        // ===== HIGH-PRIORITY: Online module indexes =====
        $safeAddIndexes('online_transactions', ['expense_transaction_id', 'income_transaction_id', 'created_at']);

        // ===== HIGH-PRIORITY: Accounts indexes =====
        $safeAddIndexes('accounts', [
            'is_active',
            [['module_type', 'is_module_vault', 'is_active'], 'idx_accounts_module_vault_active']
        ]);

        // ===== HIGH-PRIORITY: Polymorphic indexes =====
        $safeAddIndexes('invoices', [
            [['reference_type', 'reference_id']],
            'transaction_id', 'created_at'
        ]);

        $safeAddIndexes('invoice_items', [
            [['item_type', 'item_id']]
        ]);

        $safeAddIndexes('invoice_payments', ['transaction_id', 'account_id', 'payment_method']);

        // ===== HIGH-PRIORITY: Transactions indexes =====
        $safeAddIndexes('transactions', ['program_id', 'created_at']);

        // ===== HIGH-PRIORITY: Programs/Hotels indexes =====
        $safeAddIndexes('programs', ['executing_company_id', 'trip_supervisor_id', 'accommodation_type_id', 'departure_date', 'is_active']);
        $safeAddIndexes('hotels', ['is_active', 'account_id', 'city']);

        // ===== HIGH-PRIORITY: Service module indexes =====
        $safeAddIndexes('service_orders', ['created_at']);
        $safeAddIndexes('service_payments', ['transaction_id']);

        // ===== HIGH-PRIORITY: Customers/Suppliers indexes =====
        $safeAddIndexes('customers', ['email', 'status', 'type', 'account_id', 'city']);
        $safeAddIndexes('suppliers', ['account_id', 'email', 'city']);

        // ===== HIGH-PRIORITY: Auth/Employee indexes =====
        $safeAddIndexes('users', ['role', 'is_active']);
        $safeAddIndexes('employees', ['full_name', 'phone', 'email']);

        // ===== HIGH-PRIORITY: Payroll indexes =====
        $safeAddIndexes('payrolls', ['status', 'transaction_id', 'paid_at']);

        // ===== SOFT DELETE INDEXES =====
        foreach (['hajj_umra_bookings', 'visa_bookings', 'visa_details', 'bus_bookings',
                  'bus_inventories', 'bus_companies', 'service_orders', 'programs', 'hotels',
                  'suppliers', 'invoices', 'fawry_transactions', 'online_transactions',
                  'wallet_transactions', 'flight_carriers', 'flight_groups', 'flight_systems',
                  'flight_bookings', 'bus_refund_requests', 'ticket_modifications',
                  'airline_credits', 'refund_requests'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                $safeAddIndexes($table, ['deleted_at']);
            }
        }
    }

    public function down(): void
    {
        // We do not drop indexes in down() — it's safe to leave them.
        // If you need to roll back, do it manually per-index.
    }
};
