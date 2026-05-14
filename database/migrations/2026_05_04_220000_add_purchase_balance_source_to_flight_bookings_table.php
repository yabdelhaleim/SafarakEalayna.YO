<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->string('purchase_balance_source', 20)
                ->nullable()
                ->after('sale_gl_transaction_id')
                ->comment('carrier|system|both — مصدر خصم تكلفة الشراء؛ both للسجلات القديمة عند خصم الاثنين');
        });

        // سجلات قديمة: كان الخادم يخصم من الناقل والنظام معاً عند وجود المعرّفين.
        DB::table('flight_bookings')
            ->whereNotNull('flight_carrier_id')
            ->whereNotNull('flight_system_id')
            ->where('purchase_price', '>', 0)
            ->update(['purchase_balance_source' => 'both']);

        DB::table('flight_bookings')
            ->whereNotNull('flight_carrier_id')
            ->whereNull('flight_system_id')
            ->where('purchase_price', '>', 0)
            ->update(['purchase_balance_source' => 'carrier']);

        DB::table('flight_bookings')
            ->whereNull('flight_carrier_id')
            ->whereNotNull('flight_system_id')
            ->where('purchase_price', '>', 0)
            ->update(['purchase_balance_source' => 'system']);
    }

    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropColumn('purchase_balance_source');
        });
    }
};
