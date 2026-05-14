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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts');
            $table->foreignId('to_account_id')->constrained('accounts');
            $table->decimal('amount', 15, 2);
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('exchange_rate', 10, 6)->nullable(); // لو currencies مختلفة
            $table->decimal('converted_amount', 15, 2)->nullable(); // بعد التحويل
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->foreignId('approval_workflow_id')->nullable()->constrained('approval_workflows');
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_account_id', 'to_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
