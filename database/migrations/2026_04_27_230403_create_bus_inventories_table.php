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
        Schema::create('bus_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('bus_companies');
            $table->string('route', 200);
            $table->date('travel_date');
            $table->time('departure_time')->nullable();
            $table->integer('total_tickets');
            $table->integer('available_tickets');
            $table->decimal('cost_per_ticket', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->enum('payment_type', ['cash', 'deferred']);
            $table->decimal('total_cost', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('remaining_debt', 12, 2);
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('travel_date');
            $table->index('available_tickets');
            $table->index('remaining_debt');
            $table->index(['company_id', 'travel_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_inventories');
    }
};
