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
        Schema::table('flight_bookings', function (Blueprint $table) {
            // ربط الحجز بالـ System و Carrier و Group الجديدة
            $table->foreignId('flight_system_id')->nullable()->constrained()->onDelete('set null')->after('system_type');
            $table->foreignId('flight_carrier_id')->nullable()->constrained()->onDelete('set null')->after('flight_system_id');
            $table->foreignId('flight_group_id')->nullable()->constrained()->onDelete('set null')->after('flight_carrier_id');

            // ربط المطارات
            $table->foreignId('from_airport_id')->nullable()->constrained('airports')->onDelete('set null')->after('from_airport');
            $table->foreignId('to_airport_id')->nullable()->constrained('airports')->onDelete('set null')->after('to_airport');

            // حقول العملة الأجنبية
            $table->string('foreign_currency', 3)->nullable()->after('currency');
            $table->decimal('purchase_price_foreign', 15, 2)->nullable()->after('foreign_currency');
            $table->decimal('exchange_rate', 15, 4)->default(1.0)->after('purchase_price_foreign');
            $table->decimal('purchase_price_egp', 15, 2)->nullable()->after('exchange_rate');

            //Indexes
            $table->index('flight_system_id');
            $table->index('flight_carrier_id');
            $table->index('flight_group_id');
            $table->index('from_airport_id');
            $table->index('to_airport_id');
            $table->index('foreign_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropIndex(['foreign_currency']);
            $table->dropIndex(['to_airport_id']);
            $table->dropIndex(['from_airport_id']);
            $table->dropIndex(['flight_group_id']);
            $table->dropIndex(['flight_carrier_id']);
            $table->dropIndex(['flight_system_id']);

            $table->dropForeign(['flight_group_id']);
            $table->dropForeign(['flight_carrier_id']);
            $table->dropForeign(['flight_system_id']);
            $table->dropForeign(['to_airport_id']);
            $table->dropForeign(['from_airport_id']);

            $table->dropColumn([
                'flight_system_id',
                'flight_carrier_id',
                'flight_group_id',
                'from_airport_id',
                'to_airport_id',
                'foreign_currency',
                'purchase_price_foreign',
                'exchange_rate',
                'purchase_price_egp'
            ]);
        });
    }
};
