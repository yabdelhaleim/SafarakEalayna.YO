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
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');

            // Payment details
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'credit_card', 'check', 'other'])->default('cash');
            $table->string('reference_number')->nullable();
            $table->date('payment_date');

            // Transaction link
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->foreignId('account_id')->nullable()->constrained('accounts');

            // Notes
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');

            $table->timestamps();

            // Index
            $table->index(['invoice_id', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
