<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-8 Cleanup — Drop the legacy `bus_tickets` table.
 *
 * Per the F-8 investigation (BUS_MODULE_F8_BUSTICKET_INVESTIGATION_20260813.md):
 * - `bus_tickets` had 0 rows in production MySQL at the time of audit.
 * - No API routes reference `BusTicket`; the legacy `BusTicketController` was
 *   never registered in routes/api.php.
 * - No Vue/frontend, no tests, no policies, no events, no seeders/factories.
 * - The Filament resource was hidden from navigation (`shouldRegisterNavigation=false`)
 *   and self-documented as legacy ("موديل قديم").
 * - The current Bus module uses `BusCompany / BusInventory / BusBooking`.
 * - No foreign keys from any other table reference `bus_tickets`.
 *
 * The migration is reversible: `down()` recreates the original schema with
 * `Blueprint::create()` so a rollback restores the table exactly (within
 * MySQL/Postgres data types). No data is recovered — rollback recreates the
 * table structure only, since the table was empty at the time of removal.
 *
 * After this migration:
 *   - `bus_tickets` table no longer exists.
 *   - Any historical migration that referenced `bus_tickets` (such as
 *     `2026_05_24_232618_add_performance_indexes.php`) had already been
 *     applied — its `safeAddIndexes()` call is guarded against missing
 *     tables, so re-running individual migrations is safe.
 *   - The legacy `BusTicket` model, `BusTicketService`, `BusTicketController`,
 *     `StoreBusTicketRequest`, `UpdateBusTicketRequest`, `BusTicketResource`
 *     (API), and the entire `app/Filament/Admin/Resources/BusTickets/`
 *     directory have been deleted in the same F-8 cleanup sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bus_tickets');
    }

    public function down(): void
    {
        // Recreate the table with the original schema from
        // 2026_04_27_160500_create_bus_tickets_table.php so a rollback is
        // structurally faithful. Data is NOT recovered (the table was empty
        // when this F-8 cleanup was applied).
        Schema::create('bus_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('passenger_name');
            $table->string('phone');
            $table->string('country')->nullable();
            $table->string('bus_name');
            $table->integer('ticket_count');
            $table->string('from_city');
            $table->string('to_city');
            $table->date('departure_date');
            $table->time('departure_time')->nullable();
            $table->date('return_date')->nullable();
            $table->time('return_time')->nullable();
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('profit', 12, 2)->default(0);
            $table->foreignId('employee_id')->constrained('users');
            $table->enum('payment_method', [
                'cash',
                'bank_transfer',
                'cash_wallet',
                'office_safe',
                'office_drawer',
            ]);
            $table->decimal('amount', 12, 2);
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
            $table->index('payment_method');
            $table->index('created_at');
        });
    }
};
