<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'name')) {
                $table->string('name')->nullable();
            }
            if (!Schema::hasColumn('customers', 'nationality')) {
                $table->enum('nationality', ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'JO', 'OTHER'])->default('EG');
            }
            if (!Schema::hasColumn('customers', 'gender')) {
                $table->enum('gender', ['male', 'female'])->nullable();
            }
            if (!Schema::hasColumn('customers', 'address')) {
                $table->text('address')->nullable();
            }
            if (!Schema::hasColumn('customers', 'status')) {
                $table->enum('status', ['active', 'blocked', 'vip'])->default('active');
            }
            if (!Schema::hasColumn('customers', 'total_spent')) {
                $table->decimal('total_spent', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('customers', 'bookings_count')) {
                $table->integer('bookings_count')->default(0);
            }
        });

        // Copy full_name into name for any existing customers
        DB::table('customers')->whereNull('name')->orWhere('name', '')->update([
            'name' => DB::raw('full_name')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'nationality',
                'gender',
                'address',
                'status',
                'total_spent',
                'bookings_count',
            ]);
        });
    }
};
