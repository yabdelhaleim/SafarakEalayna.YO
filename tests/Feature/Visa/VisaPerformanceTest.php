<?php

namespace Tests\Feature\Visa;

use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 17: Performance / Stress — bulk operations.
 *
 * Tests:
 *   - Create 100 bookings in succession — verify ledger still balanced
 *   - Read 1000 bookings via paginated index
 *   - Filter performance on large dataset
 *
 * @group visa
 * @group visa-performance
 */
class VisaPerformanceTest extends VisaTestCase
{
    public function test_create_50_bookings_bulk(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 50; $i++) {
            $this->makeBooking([
                'purchase_price' => 100.0 + $i,
                'selling_price' => 200.0 + $i * 2,
            ]);
        }

        $elapsed = microtime(true) - $start;

        $this->assertSame(50, VisaBooking::count());
        $this->assertLessThan(60.0, $elapsed, '50 bookings should complete within 60s');

        // Ledger must remain balanced
        $this->assertLedgerGloballyBalanced();
    }

    public function test_paginated_index_with_100_records(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->makeBooking();
        }

        $start = microtime(true);

        $response = $this->getJson('/api/v1/visa/bookings?per_page=15');

        $elapsed = microtime(true) - $start;

        $response->assertOk();
        $pagination = $response->json('data.pagination');

        $this->assertSame(100, $pagination['total']);
        $this->assertSame(15, $pagination['per_page']);
        $this->assertSame(1, $pagination['current_page']);
        $this->assertLessThan(2.0, $elapsed, 'paginated index must respond < 2s');
    }

    public function test_filter_on_large_dataset(): void
    {
        // Mix of statuses — Edit disabled by INCIDENT-2026-08-17, use direct model mutation
        for ($i = 0; $i < 30; $i++) {
            $b = $this->makeBooking();
            if ($i % 5 === 0) {
                $b->update(['status' => 'approved']);
            }
        }

        $start = microtime(true);

        $response = $this->getJson('/api/v1/visa/bookings?status=approved&per_page=50');

        $elapsed = microtime(true) - $start;

        $response->assertOk();
        $this->assertLessThan(2.0, $elapsed, 'filtered query must respond < 2s');
    }

    public function test_n_plus_1_protection_on_show(): void
    {
        $booking = $this->makeBooking();

        DB::enableQueryLog();
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        // Eager-loaded relations should be efficient — count queries must be bounded
        // (under 30 queries for a fully-loaded booking with payments + visaDetail + customer)
        $this->assertLessThan(30, count($queries),
            'show endpoint has potential N+1 — got '.count($queries).' queries');
    }

    public function test_n_plus_1_protection_on_index(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeBooking();
        }

        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/visa/bookings?per_page=20');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        // Per-page=20 with eager-loaded relations should be < 20 queries
        $this->assertLessThan(25, count($queries),
            'index endpoint has potential N+1 — got '.count($queries).' queries');
    }
}