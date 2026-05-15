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
            $indexes = Schema::getIndexes('flight_bookings');
            $indexNames = array_column($indexes, 'name');

            if (!in_array('flight_bookings_created_at_index', $indexNames)) {
                $table->index('created_at');
            }
            if (Schema::hasColumn('flight_bookings', 'flight_carrier_id') && !in_array('flight_bookings_flight_carrier_id_index', $indexNames)) {
                $table->index('flight_carrier_id');
            }
            if (Schema::hasColumn('flight_bookings', 'flight_system_id') && !in_array('flight_bookings_flight_system_id_index', $indexNames)) {
                $table->index('flight_system_id');
            }
        });

        Schema::table('bus_bookings', function (Blueprint $table) {
            $indexes = Schema::getIndexes('bus_bookings');
            $indexNames = array_column($indexes, 'name');
            if (!in_array('bus_bookings_created_at_index', $indexNames)) {
                $table->index('created_at');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $indexes = Schema::getIndexes('transactions');
            $indexNames = array_column($indexes, 'name');
            if (!in_array('transactions_created_at_index', $indexNames)) {
                $table->index('created_at');
            }
        });

        if (Schema::hasTable('online_transactions')) {
            Schema::table('online_transactions', function (Blueprint $table) {
                $indexes = Schema::getIndexes('online_transactions');
                $indexNames = array_column($indexes, 'name');
                if (!in_array('online_transactions_created_at_index', $indexNames)) {
                    $table->index('created_at');
                }
            });
        }

        if (Schema::hasTable('fawry_transactions')) {
            Schema::table('fawry_transactions', function (Blueprint $table) {
                $indexes = Schema::getIndexes('fawry_transactions');
                $indexNames = array_column($indexes, 'name');
                if (!in_array('fawry_transactions_created_at_index', $indexNames)) {
                    $table->index('created_at');
                }
            });
        }

        if (Schema::hasTable('hajj_umra_bookings')) {
            Schema::table('hajj_umra_bookings', function (Blueprint $table) {
                $indexes = Schema::getIndexes('hajj_umra_bookings');
                $indexNames = array_column($indexes, 'name');
                if (!in_array('hajj_umra_bookings_created_at_index', $indexNames)) {
                    $table->index('created_at');
                }
            });
        }
        
        if (Schema::hasTable('visa_bookings')) {
            Schema::table('visa_bookings', function (Blueprint $table) {
                $indexes = Schema::getIndexes('visa_bookings');
                $indexNames = array_column($indexes, 'name');
                if (!in_array('visa_bookings_created_at_index', $indexNames)) {
                    $table->index('created_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            if (Schema::hasColumn('flight_bookings', 'flight_carrier_id')) {
                $table->dropIndex(['flight_carrier_id']);
            }
            if (Schema::hasColumn('flight_bookings', 'flight_system_id')) {
                $table->dropIndex(['flight_system_id']);
            }
        });

        Schema::table('bus_bookings', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        if (Schema::hasTable('online_transactions')) {
            Schema::table('online_transactions', function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }

        if (Schema::hasTable('fawry_transactions')) {
            Schema::table('fawry_transactions', function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('hajj_umra_bookings')) {
            Schema::table('hajj_umra_bookings', function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }
        
        if (Schema::hasTable('visa_bookings')) {
            Schema::table('visa_bookings', function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }
    }
};
