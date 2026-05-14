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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['income', 'expense', 'transfer', 'refund']);
            $table->enum('module', ['flight', 'bus', 'fawry', 'online', 'hajj_umra', 'visa', 'wallet', 'general']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EGP');
            $table->foreignId('from_account_id')->nullable()->constrained('accounts');
            $table->foreignId('to_account_id')->nullable()->constrained('accounts');
            $table->foreignId('approval_workflow_id')->nullable()->constrained('approval_workflows');
            $table->string('related_type')->nullable(); // morphClass
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['type', 'module']);
            $table->index(['from_account_id', 'to_account_id']);
            $table->index(['related_type', 'related_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
