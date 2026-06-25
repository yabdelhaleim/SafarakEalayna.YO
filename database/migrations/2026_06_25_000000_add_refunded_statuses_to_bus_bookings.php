<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds refunded / partially_refunded to bus_bookings.status.
     * BusBookingStatus enum and BusBookingService already use these values;
     * the original migration only allowed pending, paid, cancelled.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `bus_bookings` MODIFY COLUMN `status` ENUM(
            'pending',
            'paid',
            'cancelled',
            'refunded',
            'partially_refunded'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `bus_bookings` SET `status` = 'cancelled' WHERE `status` IN ('refunded', 'partially_refunded')");

        DB::statement("ALTER TABLE `bus_bookings` MODIFY COLUMN `status` ENUM(
            'pending',
            'paid',
            'cancelled'
        ) NOT NULL DEFAULT 'pending'");
    }
};
