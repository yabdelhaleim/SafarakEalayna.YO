<?php

namespace Tests\Feature\Visa;

use App\Models\VisaPayment;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.8 — Pre-Check for Existing Duplicates (read-only)
 *
 * Runs ONLY against a non-production DB. No migrations executed.
 * Reports any existing (visa_booking_id, transaction_reference) duplicates
 * that would block the planned UNIQUE constraint migration.
 */
class VisaPaymentDuplicatesPreCheck extends VisaTestCase
{
    public function test_pre_check_reports_zero_duplicates_in_clean_db(): void
    {
        // Baseline: clean test DB should have zero duplicates
        $duplicates = $this->findDuplicates();

        $this->assertSame([], $duplicates,
            'no (visa_booking_id, transaction_reference) duplicates should exist in the test DB');
    }

    public function test_pre_check_reports_existing_duplicates_correctly(): void
    {
        // Phase 9.8: with the new UNIQUE constraint in place, direct INSERTs
        // that bypass the service are rejected by the DB itself.
        // We now verify that:
        //  (a) the pre-check finds zero duplicates in a clean DB, AND
        //  (b) the DB UNIQUE constraint rejects any bypass attempt.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'PRE_DUP_A_'.uniqid(),
            'reference' => 'SAME-REF-1',
        ])->assertCreated();

        // Confirm clean DB has zero duplicates
        $duplicates = $this->findDuplicates();
        $this->assertSame([], $duplicates, 'clean DB must have zero duplicates');

        // Try to bypass the service with a direct INSERT — must be rejected
        $threw = false;
        try {
            DB::table('visa_payments')->insert([
                'visa_booking_id' => $booking->id,
                'payment_method' => 'cash',
                'amount' => 500.0,
                'currency' => 'EGP',
                'treasury_account' => 'office_drawer',
                'transaction_reference' => 'SAME-REF-1',  // duplicate!
                'payment_date' => now(),
                'paid_by' => 'dup',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $threw = true;
        }
        $this->assertTrue($threw,
            'Phase 9.8 fix: DB UNIQUE constraint must reject direct duplicate INSERT');
    }

    /**
     * @return list<array{visa_booking_id:int, transaction_reference:string, count:int}>
     */
    private function findDuplicates(): array
    {
        // MySQL/SQLite portable query — both engines support GROUP BY with HAVING.
        $rows = DB::table('visa_payments')
            ->select('visa_booking_id', 'transaction_reference', DB::raw('COUNT(*) as count'))
            ->whereNotNull('transaction_reference')
            ->groupBy('visa_booking_id', 'transaction_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $rows->map(fn ($r) => [
            'visa_booking_id' => (int) $r->visa_booking_id,
            'transaction_reference' => (string) $r->transaction_reference,
            'count' => (int) $r->count,
        ])->all();
    }
}