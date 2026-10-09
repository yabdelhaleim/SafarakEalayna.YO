<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9.8 — Double-Payment Defect Fix (VISA)
 *
 * Adds a UNIQUE constraint on `(visa_booking_id, transaction_reference)`
 * to defend against the known double-payment defect:
 *   - Same booking + same `transaction_reference` + DIFFERENT `idempotency_key`
 *   - Without this constraint, two `visa_payments` rows + two transfer txs
 *     + double credit to vault are created.
 *
 * Safety pre-check (read-only):
 *   Before adding the unique index, the migration aborts (throws) if it
 *   detects any existing (booking, reference) duplicates with non-null
 *   references. This is a SAFETY guard — it never deletes or modifies
 *   data; it only aborts the migration so an admin can manually
 *   de-duplicate. DESTRUCTIVE operations are explicitly forbidden.
 *
 * NULL semantics:
 *   MySQL/MariaDB/SQLite all allow multiple NULL values in a unique index,
 *   so legacy callers that don't supply a reference keep working.
 *
 * Down(): drops the unique index only. No data modification.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Pre-check for existing duplicates (read-only abort)
        $duplicates = $this->findDuplicates();
        if (! empty($duplicates)) {
            Log::error('Phase 9.8 migration ABORTED — existing duplicates found', [
                'duplicates' => $duplicates,
            ]);
            throw new \RuntimeException(
                'Phase 9.8 migration aborted: existing (visa_booking_id, transaction_reference) '
                .'duplicates found. Manually de-duplicate the visa_payments table before '
                .'re-running this migration. Destructive operations (TRUNCATE, DELETE) are '
                .'NOT performed by this migration. See logs for details.'
            );
        }

        // Step 2: Idempotency guard — skip if the unique index already exists.
        // Laravel's Schema::hasIndex() works on MySQL, MariaDB, PostgreSQL,
        // and SQLite (uses pragma index_list).
        if (Schema::hasIndex('visa_payments', 'vp_ref_uniq')) {
            return; // already migrated
        }

        Schema::table('visa_payments', function (Blueprint $table) {
            // Composite unique index on (visa_booking_id, transaction_reference):
            //   - Same booking + same reference → DB rejects the duplicate
            //   - Same booking + different references → allowed
            //   - Different bookings + same reference → allowed (per-booking scope)
            //   - Multiple NULL references on same booking → allowed (legacy path)
            $table->unique(
                ['visa_booking_id', 'transaction_reference'],
                'vp_ref_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('visa_payments', function (Blueprint $table) {
            $table->dropUnique('vp_ref_uniq');
        });
    }

    /**
     * Read-only: find any (visa_booking_id, transaction_reference) duplicates.
     *
     * @return list<array{visa_booking_id:int, transaction_reference:string, count:int}>
     */
    private function findDuplicates(): array
    {
        $driver = DB::connection()->getDriverName();

        // Portable GROUP BY ... HAVING COUNT(*) > 1 query (works on MySQL, MariaDB, SQLite)
        $rows = DB::table('visa_payments')
            ->select('visa_booking_id', 'transaction_reference', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('transaction_reference')
            ->groupBy('visa_booking_id', 'transaction_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $rows->map(fn ($r) => [
            'visa_booking_id' => (int) $r->visa_booking_id,
            'transaction_reference' => (string) $r->transaction_reference,
            'count' => (int) $r->cnt,
        ])->all();
    }
};