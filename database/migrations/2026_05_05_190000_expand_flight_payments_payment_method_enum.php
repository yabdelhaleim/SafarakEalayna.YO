<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align MySQL ENUM with App\Enums\FlightPaymentMethod (vodafone_cash, instapay were missing).
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `flight_payments` MODIFY `payment_method` ENUM(
            'cash',
            'bank_transfer',
            'cash_wallet',
            'postal_transfer',
            'office_safe',
            'office_drawer',
            'mixed',
            'vodafone_cash',
            'instapay'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `flight_payments` SET `payment_method` = 'cash_wallet' WHERE `payment_method` IN ('vodafone_cash','instapay')");

        DB::statement("ALTER TABLE `flight_payments` MODIFY `payment_method` ENUM(
            'cash',
            'bank_transfer',
            'cash_wallet',
            'postal_transfer',
            'office_safe',
            'office_drawer',
            'mixed'
        ) NOT NULL");
    }
};
