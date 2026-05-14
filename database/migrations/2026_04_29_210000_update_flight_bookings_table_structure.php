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
            // إضافة الحقول المفقودة من الـ Model
            $table->foreignId('employee_id')->nullable()->after('customer_id');
            $table->foreignId('account_id')->nullable()->after('employee_id');
            $table->foreignId('created_by')->nullable()->after('account_id');

            // حقول الحجز والنظام
            $table->string('booking_number')->nullable()->after('id');
            $table->enum('system_type', ['manual', 'online', 'gds', 'api'])->default('manual')->after('booking_number');
            $table->string('pnr')->nullable()->after('system_type');

            // حقول الرحلة الجديدة
            $table->string('airline_name')->nullable()->after('airline');
            $table->string('from_airport')->nullable()->after('origin');
            $table->string('to_airport')->nullable()->after('destination');
            $table->time('arrival_time')->nullable()->after('departure_time');
            $table->text('trip_details')->nullable()->after('trip_type');

            // حقول الأسعار
            $table->decimal('purchase_price', 12, 2)->default(0)->after('baggage_allowance_kg');
            $table->decimal('selling_price', 12, 2)->default(0)->after('purchase_price');
            $table->decimal('profit', 12, 2)->default(0)->after('selling_price');
            $table->string('currency', 3)->default('SAR')->after('profit');

            // حقول إضافية - إعادة تسمية passenger_count إلى passengers_count
            // سنضيف passengers_count وننقل البيانات منه

            // إضافة المؤشرات والعلاقات
            $table->index('employee_id');
            $table->index('account_id');
            $table->index('created_by');
            $table->index('booking_number');
            $table->index('system_type');
        });

        // نقل البيانات من الحقول القديمة إلى الجديدة
        DB::statement('UPDATE flight_bookings SET booking_number = booking_reference WHERE booking_number IS NULL');
        DB::statement('UPDATE flight_bookings SET airline_name = airline WHERE airline_name IS NULL');
        DB::statement('UPDATE flight_bookings SET from_airport = origin WHERE from_airport IS NULL');
        DB::statement('UPDATE flight_bookings SET to_airport = destination WHERE to_airport IS NULL');

        // إضافة القيود الخارجية
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            // حذف الحقول الجيدة
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by']);

            $table->dropIndex(['employee_id']);
            $table->dropIndex(['account_id']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['booking_number']);
            $table->dropIndex(['system_type']);

            $table->dropColumn([
                'employee_id',
                'account_id',
                'created_by',
                'booking_number',
                'system_type',
                'pnr',
                'airline_name',
                'from_airport',
                'to_airport',
                'arrival_time',
                'trip_details',
                'purchase_price',
                'selling_price',
                'profit',
                'currency',
            ]);
        });
    }
};
