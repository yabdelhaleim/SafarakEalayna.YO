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
        // 1. Add optional company_id to customers table if not present
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'company_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('type')->constrained('customers')->nullOnDelete();
            });
        }

        // 2. Create customer_credit_ledger table (Additive & Immutable)
        if (! Schema::hasTable('customer_credit_ledger')) {
            Schema::create('customer_credit_ledger', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('source_type', 50)->index(); // refund, overpayment, deposit, booking_settlement, disbursement, reversal
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->decimal('amount', 15, 2); // Positive = Credit added, Negative = Credit consumed
                $table->decimal('balance_after', 15, 2);
                $table->string('currency', 3)->default('EGP');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable()->index();
                $table->unsignedBigInteger('reversal_entry_id')->nullable()->index();
                $table->timestamps();

                $table->index(['customer_id', 'created_at']);
                $table->index(['company_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credit_ledger');

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'company_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
