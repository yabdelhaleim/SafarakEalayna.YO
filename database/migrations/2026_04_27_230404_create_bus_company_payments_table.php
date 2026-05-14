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
        Schema::create('bus_company_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('bus_companies');
            $table->foreignId('inventory_id')->nullable()->constrained('bus_inventories');
            $table->decimal('amount', 12, 2);
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->enum('status', ['paid', 'pending', 'cancelled']);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('company_id');
            $table->index('inventory_id');
            $table->index('status');
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_company_payments');
    }
};
