<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fawry_machine_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fawry_machine_id')->constrained('fawry_machines')->onDelete('cascade');
            $table->foreignId('fawry_transaction_id')->nullable()->constrained('fawry_transactions')->onDelete('set null');
            $table->string('type'); // credit, debit
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fawry_machine_transactions');
    }
};
