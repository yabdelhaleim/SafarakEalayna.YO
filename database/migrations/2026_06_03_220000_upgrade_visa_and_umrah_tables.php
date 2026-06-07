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
        // 1. Upgrade visa_agents
        Schema::table('visa_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('visa_agents', 'visa_type')) {
                $table->string('visa_type', 50)->nullable()->after('country');
            }
            if (! Schema::hasColumn('visa_agents', 'default_cost_price')) {
                $table->decimal('default_cost_price', 10, 2)->nullable()->after('visa_type');
            }
        });

        // 2. Create umrah_suppliers
        if (! Schema::hasTable('umrah_suppliers')) {
            Schema::create('umrah_suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('phone', 20)->nullable();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->decimal('default_cost_price', 10, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Upgrade hajj_umra_bookings
        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('hajj_umra_bookings', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('program_id')->constrained('umrah_suppliers')->nullOnDelete();
            }
            if (! Schema::hasColumn('hajj_umra_bookings', 'companion_purchase_price')) {
                $table->decimal('companion_purchase_price', 10, 2)->default(0.00)->after('purchase_price');
            }
            if (! Schema::hasColumn('hajj_umra_bookings', 'companion_selling_price')) {
                $table->decimal('companion_selling_price', 10, 2)->default(0.00)->after('selling_price');
            }
            if (! Schema::hasColumn('hajj_umra_bookings', 'accommodation_choice')) {
                $table->string('accommodation_choice', 50)->default('standard')->after('per_person');
            }
            if (! Schema::hasColumn('hajj_umra_bookings', 'accommodation_extra_charge')) {
                $table->decimal('accommodation_extra_charge', 10, 2)->default(0.00)->after('accommodation_choice');
            }
        });

        // 4. Create umrah_transaction_passengers
        if (! Schema::hasTable('umrah_transaction_passengers')) {
            Schema::create('umrah_transaction_passengers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained('hajj_umra_bookings')->onDelete('cascade');
                $table->enum('category', ['adult', 'child_with_bed', 'child_no_bed', 'infant']);
                $table->integer('count');
                $table->decimal('unit_price', 10, 2);
                $table->decimal('subtotal', 10, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('umrah_transaction_passengers');

        Schema::table('hajj_umra_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('supplier_id');
            $table->dropColumn([
                'supplier_id',
                'companion_purchase_price',
                'companion_selling_price',
                'accommodation_choice',
                'accommodation_extra_charge',
            ]);
        });

        Schema::dropIfExists('umrah_suppliers');

        Schema::table('visa_agents', function (Blueprint $table) {
            $table->dropColumn(['visa_type', 'default_cost_price']);
        });
    }
};
