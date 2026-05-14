<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fawry_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->enum('operation_type', ['withdrawal', 'deposit', 'payment', 'travel_permit']);
            $table->decimal('client_amount', 12, 2);
            $table->decimal('fawry_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('profit', 12, 2)->default(0);
            $table->foreignId('employee_id')->constrained('users');
            $table->enum('payment_method', [
                'cash',
                'bank_transfer',
                'cash_wallet',
                'office_safe',
                'office_drawer',
            ]);
            $table->decimal('amount', 12, 2);
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
            $table->index('payment_method');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fawry_transactions');
    }
};
