<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('refund_requests')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('refund_requests', 'refund_case')) {
                    $table->string('refund_case', 50)->default('airline_cancellation')->after('flight_booking_id');
                }
                if (! Schema::hasColumn('refund_requests', 'airline_penalty')) {
                    $table->decimal('airline_penalty', 15, 2)->default(0)->after('supplier_cost');
                }
                if (! Schema::hasColumn('refund_requests', 'agency_commission')) {
                    $table->decimal('agency_commission', 15, 2)->default(0)->after('airline_penalty');
                }
                if (! Schema::hasColumn('refund_requests', 'apply_commission')) {
                    $table->boolean('apply_commission')->default(true)->after('agency_commission');
                }
                if (! Schema::hasColumn('refund_requests', 'non_refundable_taxes_on_customer')) {
                    $table->boolean('non_refundable_taxes_on_customer')->default(false)->after('apply_commission');
                }
                if (! Schema::hasColumn('refund_requests', 'non_refundable_taxes_amount')) {
                    $table->decimal('non_refundable_taxes_amount', 15, 2)->default(0)->after('non_refundable_taxes_on_customer');
                }
                if (! Schema::hasColumn('refund_requests', 'company_id')) {
                    $table->foreignId('company_id')->nullable()->after('customer_credit_amount')->constrained('customers')->nullOnDelete();
                }
                if (! Schema::hasColumn('refund_requests', 'credit_ledger_entry_id')) {
                    $table->unsignedBigInteger('credit_ledger_entry_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('refund_requests', 'actual_airline_refund_amount')) {
                    $table->decimal('actual_airline_refund_amount', 15, 2)->nullable()->after('supplier_refund_amount');
                }
                if (! Schema::hasColumn('refund_requests', 'refund_variance_amount')) {
                    $table->decimal('refund_variance_amount', 15, 2)->default(0)->after('supplier_refund_loss');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('refund_requests')) {
            Schema::table('refund_requests', function (Blueprint $table) {
                $columns = [
                    'refund_case',
                    'airline_penalty',
                    'agency_commission',
                    'apply_commission',
                    'non_refundable_taxes_on_customer',
                    'non_refundable_taxes_amount',
                    'actual_airline_refund_amount',
                    'refund_variance_amount',
                    'credit_ledger_entry_id',
                ];

                if (Schema::hasColumn('refund_requests', 'company_id')) {
                    $table->dropForeign(['company_id']);
                    $columns[] = 'company_id';
                }

                $table->dropColumn(array_filter($columns, fn ($col) => Schema::hasColumn('refund_requests', $col)));
            });
        }
    }
};
