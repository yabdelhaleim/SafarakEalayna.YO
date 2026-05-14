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
        Schema::create('bus_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('bus_inventories');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('employee_id')->constrained('employees');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('profit', 12, 2);
            $table->enum('status', ['pending', 'paid', 'cancelled']);
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('inventory_id');
            $table->index('customer_id');
            $table->index('employee_id');
            $table->index('status');
            $table->index(['inventory_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_bookings');
    }
};
