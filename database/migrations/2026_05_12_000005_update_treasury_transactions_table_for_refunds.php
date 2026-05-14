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
        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->onDelete('set null');
            $table->foreignId('refund_request_id')->nullable()->constrained('refund_requests')->onDelete('set null');
            $table->string('type')->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();
            $table->decimal('base_amount', 15, 2)->nullable();
            $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->dropForeign(['treasury_id']);
            $table->dropForeign(['refund_request_id']);
            $table->dropColumn([
                'treasury_id',
                'refund_request_id',
                'type',
                'exchange_rate',
                'base_amount',
                'description',
            ]);
        });
    }
};
