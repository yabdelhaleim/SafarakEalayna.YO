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
        Schema::table('refund_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('refund_requests', 'supplier_cost')) {
                $table->decimal('supplier_cost', 15, 2)->nullable()->after('original_amount');
            }
            if (! Schema::hasColumn('refund_requests', 'supplier_refund_amount')) {
                $table->decimal('supplier_refund_amount', 15, 2)->nullable()->after('supplier_cost');
            }
            if (! Schema::hasColumn('refund_requests', 'supplier_refund_loss')) {
                $table->decimal('supplier_refund_loss', 15, 2)->default(0)->after('supplier_refund_amount');
            }
            if (! Schema::hasColumn('refund_requests', 'supplier_refund_confirmed')) {
                $table->boolean('supplier_refund_confirmed')->default(false)->after('status');
            }
            if (! Schema::hasColumn('refund_requests', 'supplier_refund_confirmed_at')) {
                $table->timestamp('supplier_refund_confirmed_at')->nullable()->after('supplier_refund_confirmed');
            }
            if (! Schema::hasColumn('refund_requests', 'customer_credit_amount')) {
                $table->decimal('customer_credit_amount', 15, 2)->default(0)->after('refund_amount');
            }
            if (! Schema::hasColumn('refund_requests', 'customer_debt_settled_amount')) {
                $table->decimal('customer_debt_settled_amount', 15, 2)->default(0)->after('customer_credit_amount');
            }
            if (! Schema::hasColumn('refund_requests', 'disbursed_amount')) {
                $table->decimal('disbursed_amount', 15, 2)->default(0)->after('customer_debt_settled_amount');
            }
            if (! Schema::hasColumn('refund_requests', 'disbursement_status')) {
                $table->string('disbursement_status', 30)->default('pending')->after('disbursed_amount');
            }
        });

        if (! Schema::hasTable('customer_credit_disbursements')) {
            Schema::create('customer_credit_disbursements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('disbursement_type', 30); // cash, bank, booking_settlement
                $table->decimal('amount', 15, 2);
                $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->nullOnDelete();
                $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('target_flight_booking_id')->nullable()->constrained('flight_bookings')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('status', 30)->default('completed'); // completed, reversed
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['refund_request_id', 'status']);
                $table->index(['customer_id', 'status']);
                $table->index('disbursement_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credit_disbursements');

        Schema::table('refund_requests', function (Blueprint $table) {
            $columnsToDrop = [
                'supplier_cost',
                'supplier_refund_amount',
                'supplier_refund_loss',
                'supplier_refund_confirmed',
                'supplier_refund_confirmed_at',
                'customer_credit_amount',
                'customer_debt_settled_amount',
                'disbursed_amount',
                'disbursement_status',
            ];

            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('refund_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
