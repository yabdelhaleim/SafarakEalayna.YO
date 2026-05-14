<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('passenger_name');
            $table->string('phone');
            $table->string('country')->nullable();
            $table->string('bus_name');
            $table->integer('ticket_count');
            $table->string('from_city');
            $table->string('to_city');
            $table->date('departure_date');
            $table->time('departure_time')->nullable();
            $table->date('return_date')->nullable();
            $table->time('return_time')->nullable();
            $table->decimal('purchase_price', 12, 2);
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
        Schema::dropIfExists('bus_tickets');
    }
};
