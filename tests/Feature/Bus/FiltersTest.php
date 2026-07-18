<?php

namespace Tests\Feature\Bus;

use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use Tests\TestCase;

/**
 * Filter scenarios for the booking list endpoint.
 *
 * Validates that filters declared on the Vue side (BusIndex.vue / busStore.js)
 * reach the backend through the BusBookingService::getAllBookings() pipeline.
 *
 * Bug fix verification:
 *   - `route_from` / `route_to` filters were declared in the store but ignored
 *     by the service. After the fix, they narrow the result set correctly.
 *   - `status`, `company_id`, `date_from`, `date_to` already work — locked
 *     down here so future refactors don't regress.
 */
class FiltersTest extends BusTestCase
{
    private function createBusCompany(): BusCompany
    {
        return $this->makeBusCompany([], 0);
    }

    public function test_route_from_filter_narrows_results(): void
    {
        $company = $this->createBusCompany();
        // Two distinct inventories (different routes), one booking each.
        $inv1 = $this->makeInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
        ]);
        $inv2 = $this->makeInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - أسوان',
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv1->id,
            'customer_name' => 'A',
            'customer_phone' => '01091111001',
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv2->id,
            'customer_name' => 'B',
            'customer_phone' => '01091111002',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/bookings?route_from=القاهرة');
        $response->assertOk();

        $count = $response->json('data.pagination.total');
        // Both bookings use Cairo as route_from, so both should match.
        $this->assertEquals(2, $count);

        $response = $this->getJson('/api/v1/bus/bookings?route_to=أسوان');
        $response->assertOk();
        $count = $response->json('data.pagination.total');
        $this->assertEquals(1, $count, 'Only أسوان booking matches route_to filter');
    }

    public function test_status_filter_narrows_results(): void
    {
        $company = $this->createBusCompany();
        $inv = $this->makeInventory(['company_id' => $company->id]);
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv->id,
            'customer_name' => 'Filter Test',
            'customer_phone' => '01091111003',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();

        // Default status is 'pending'.
        $response = $this->getJson('/api/v1/bus/bookings?status=pending');
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.pagination.total'));

        // No booking is cancelled yet.
        $response = $this->getJson('/api/v1/bus/bookings?status=cancelled');
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    public function test_company_filter_narrows_results(): void
    {
        $c1 = $this->createBusCompany();
        $c2 = $this->createBusCompany();
        $inv1 = $this->makeInventory(['company_id' => $c1->id]);
        $inv2 = $this->makeInventory(['company_id' => $c2->id]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv1->id,
            'customer_name' => 'X',
            'customer_phone' => '01091111004',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv2->id,
            'customer_name' => 'Y',
            'customer_phone' => '01091111005',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson("/api/v1/bus/bookings?company_id={$c1->id}");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    public function test_search_filter_finds_by_customer_phone(): void
    {
        $company = $this->createBusCompany();
        $inv = $this->makeInventory(['company_id' => $company->id]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inv->id,
            'customer_name' => 'Searchable',
            'customer_phone' => '01091111099',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/bookings?search=01091111099');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.pagination.total'));
    }

    public function test_date_filter_narrows_results(): void
    {
        $company = $this->createBusCompany();
        $todayInv = $this->makeInventory([
            'company_id' => $company->id,
            'travel_date' => now()->toDateString(),
        ]);
        $futureInv = $this->makeInventory([
            'company_id' => $company->id,
            'travel_date' => now()->addDays(20)->toDateString(),
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $todayInv->id,
            'customer_name' => 'Today',
            'customer_phone' => '01091111006',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $futureInv->id,
            'customer_name' => 'Future',
            'customer_phone' => '01091111007',
            'quantity' => 1,
        ])->assertCreated();

        $today = now()->toDateString();
        $response = $this->getJson("/api/v1/bus/bookings?date_from={$today}&date_to={$today}");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }
}
