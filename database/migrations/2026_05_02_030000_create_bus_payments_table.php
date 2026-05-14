<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bus_bookings');
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', [
                'cash',
                'bank_transfer',
                'cash_wallet',
                'postal_transfer',
                'office_safe',
                'office_drawer',
            ])->default('cash');
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('booking_id');
            $table->index('payment_method');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_payments');
    }
};
