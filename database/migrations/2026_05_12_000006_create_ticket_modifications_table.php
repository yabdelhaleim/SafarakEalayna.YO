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
        Schema::create('ticket_modifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('flight_bookings')->cascadeOnDelete();
            $table->string('modification_type', 50)->default('date_change'); // date_change, destination_change, both
            
            // Original vs New Values
            $table->date('original_departure_date')->nullable();
            $table->date('new_departure_date')->nullable();
            $table->string('original_destination')->nullable();
            $table->string('new_destination')->nullable();
            $table->string('original_flight_number')->nullable();
            $table->string('new_flight_number')->nullable();

            // Pricing & Accounting fields
            $table->decimal('airline_change_fee', 15, 2)->default(0);
            $table->decimal('agency_commission', 15, 2)->default(0);
            $table->decimal('total_charged_to_customer', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('payment_method')->nullable();
            
            // Fixed Financial Rule & Status Guards
            $table->boolean('deducted_from_airline_balance')->default(true);
            $table->string('status', 50)->default('draft'); // draft, pending, quoted, approved, confirmed
            
            // Notes & Metadata
            $table->text('notes')->nullable();
            $table->foreignId('modified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // Snapshots at Confirmation
            $table->decimal('airline_change_fee_snapshot', 15, 2)->nullable();
            $table->decimal('commission_snapshot', 15, 2)->nullable();
            $table->decimal('exchange_rate_snapshot', 15, 6)->nullable();

            // Audit Trail
            $table->string('ip_address', 45)->nullable();
            $table->string('reason_for_change')->nullable();

            // Reconciliation Layer
            $table->string('reconciliation_status', 50)->default('unreconciled'); // unreconciled, matched, disputed
            $table->string('reconciled_invoice_number')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            $table->timestamps();
        });

        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->timestamp('last_modified_at')->nullable();
            $table->unsignedInteger('modification_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_bookings', function (Blueprint $table) {
            $table->dropColumn(['last_modified_at', 'modification_count']);
        });

        Schema::dropIfExists('ticket_modifications');
    }
};
