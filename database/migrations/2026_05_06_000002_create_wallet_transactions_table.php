<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_type_id')->constrained('wallet_types');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name');
            $table->string('wallet_number', 30);

            $table->string('type'); // send | receive

            $table->decimal('amount', 15, 2);
            $table->decimal('service_fee', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            // الحساب الإلكتروني للوكالة (المحفظة)
            $table->foreignId('wallet_account_id')->constrained('accounts');
            // الحساب النقدي المستخدم
            $table->foreignId('cash_account_id')->constrained('accounts');

            $table->foreignId('income_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('expense_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
