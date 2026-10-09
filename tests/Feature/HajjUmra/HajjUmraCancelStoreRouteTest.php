<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\HajjUmraBooking;

/**
 * PHASE 11 — Regression coverage for the Vue store ↔ API route bindings
 * that the Hajj & Umrah frontend relies on.
 *
 * Background (audit defect, pre-fix):
 *   `resources/js/stores/hajjUmraStore.js` previously implemented
 *   `cancelBooking(id, reason)` as `axios.delete(/api/v1/hajj-umra/bookings/{id})`.
 *   That route calls `HajjUmraBookingService::deleteBookingWithReversal()` which
 *   SOFT-DELETES the booking and reverses ALL financial history. The intended
 *   light-cancel flow uses `POST /api/v1/hajj-umra/bookings/{id}/cancel` and
 *   keeps the booking visible with `status = 'cancelled'`.
 *
 * This test guards the two layers of the contract:
 *   (A) Static check — the JS source file uses POST + `/cancel` for cancelBooking.
 *   (B) Behavioural check — POST /cancel flips status without trashing the row;
 *       DELETE fully reverses + soft-deletes (preserved behavior).
 */
class HajjUmraCancelStoreRouteTest extends HajjUmraTestCase
{
    /* ============================================================
     *  A) Static check — store action targets the correct route
     * ============================================================ */

    public function test_store_cancel_booking_uses_post_cancel_not_delete(): void
    {
        $storePath = base_path('resources/js/stores/hajjUmraStore.js');
        $this->assertFileExists($storePath, 'hajjUmraStore.js must exist');

        $source = file_get_contents($storePath);

        // Locate the cancelBooking function body by greedy match from
        // declaration to the closing `},` at the function boundary. We
        // anchor on the next function in the file (deleteBooking) so the
        // extraction stops at the right brace.
        $cancelBody = $this->extractJsFunctionBody($source, 'cancelBooking');
        $deleteBody = $this->extractJsFunctionBody($source, 'deleteBooking');

        $this->assertNotNull($cancelBody, 'cancelBooking function not found in store');
        $this->assertNotNull($deleteBody, 'deleteBooking function not found in store');

        // Positive: cancelBooking calls axios.post with /cancel path.
        $this->assertStringContainsString(
            'axios.post',
            $cancelBody,
            'cancelBooking must call axios.post'
        );
        $this->assertStringContainsString(
            '/cancel',
            $cancelBody,
            'cancelBooking must target the /cancel endpoint'
        );
        $this->assertStringContainsString(
            '/api/v1/hajj-umra/bookings/${id}/cancel',
            $cancelBody,
            'cancelBooking must hit /api/v1/hajj-umra/bookings/{id}/cancel'
        );

        // Negative: cancelBooking MUST NOT issue a bare DELETE on the booking base path.
        $this->assertStringNotContainsString(
            'axios.delete',
            $cancelBody,
            'cancelBooking must NOT use axios.delete (would soft-delete the booking)'
        );

        // Positive: deleteBooking SHOULD still use DELETE on the base path.
        $this->assertStringContainsString(
            'axios.delete',
            $deleteBody,
            'deleteBooking must call axios.delete'
        );
        $this->assertStringContainsString(
            '/api/v1/hajj-umra/bookings/${id}',
            $deleteBody,
            'deleteBooking must hit /api/v1/hajj-umra/bookings/{id}'
        );
    }

    /**
     * Naive JS function-body extractor: returns the text between the opening
     * `{` after `async <name>(...)` and the closing `}` that ends the function.
     * Sufficient for top-level store actions which are simple async functions.
     */
    private function extractJsFunctionBody(string $source, string $functionName): ?string
    {
        if (! preg_match('/async\s+' . preg_quote($functionName, '/') . '\s*\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]); // position right after the opening `{`
        $depth = 1;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            $c = $source[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }
        return null;
    }

    /* ============================================================
     *  B) Behavioural — POST /cancel is light, DELETE is destructive
     * ============================================================ */

    public function test_post_cancel_route_is_light_cancel_status_only(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'phase-11 regression',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame(HajjUmraStatus::Cancelled, $booking->status,
            'POST /cancel must set status = cancelled');
        $this->assertNull($booking->deleted_at,
            'POST /cancel must NOT soft-delete the booking (it must remain visible)');
    }

    public function test_delete_route_remains_destructive_full_reversal(): void
    {
        $booking = $this->makeBooking();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")
            ->assertOk();

        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
        // Original transactions remain; reversal entries added (additive pattern).
        $this->assertDatabaseHas('transactions', [
            'id' => $booking->expense_transaction_id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $booking->income_transaction_id,
        ]);
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $program = $this->makeProgram();
        $payload = array_merge([
            'customer' => [
                'full_name' => 'P11 Cancel ' . uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }
}
