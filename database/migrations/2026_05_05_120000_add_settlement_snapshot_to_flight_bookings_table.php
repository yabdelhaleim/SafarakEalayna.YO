<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * لقطة وقت الحجز: عملة التسعير، عملة رصيد التسوية، وسعر الصرف (جنيه لكل 1 وحدة من عملة الرصيد)
     * حتى لا يتأثر الحجز القديم بتغيّر أسعار العملات لاحقاً.
     */
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->string('currency_used', 10)->nullable()->after('exchange_rate');
            $table->string('balance_currency_used', 10)->nullable()->after('currency_used');
            $table->decimal('exchange_rate_used', 18, 6)->nullable()->after('balance_currency_used');
        });
    }

    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropColumn(['currency_used', 'balance_currency_used', 'exchange_rate_used']);
        });
    }
};
