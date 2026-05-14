<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            // تحديث نوع operation_type إلى varchar لدعم القيم الديناميكية
            $table->string('operation_type', 50)->change();

            // تحديث نوع payment_method إلى varchar لدعم القيم الديناميكية
            $table->string('payment_method', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fawry_transactions', function (Blueprint $table) {
            // العودة إلى enum الأصلي
            $table->enum('operation_type', ['withdrawal', 'deposit', 'payment', 'travel_permit'])->change();
            $table->enum('payment_method', ['cash', 'bank_transfer', 'cash_wallet', 'office_safe', 'office_drawer'])->change();
        });
    }
};
