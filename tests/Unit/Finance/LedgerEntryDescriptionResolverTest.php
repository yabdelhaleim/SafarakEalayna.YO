<?php

namespace Tests\Unit\Finance;

use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LedgerEntryDescriptionResolverTest extends TestCase
{
    public function test_flight_booking_description_uses_customer_route_and_date(): void
    {
        $customer = new Customer(['full_name' => 'ياسر محمود أحمد نوح']);

        $booking = new FlightBooking([
            'from_airport' => 'سوهاج',
            'to_airport' => 'الكويت',
            'departure_date' => '2025-06-29',
            'passenger_count' => 1,
        ]);
        $booking->setRelation('customer', $customer);
        $booking->setRelation('passengers', collect());

        $description = app(LedgerEntryDescriptionResolver::class)->forFlightBooking($booking);

        $this->assertSame(
            'حجز تذكرة طيران للعميل / ياسر محمود أحمد نوح / سوهاج - الكويت / 29-06-2025',
            $description,
        );
    }

    public function test_flight_booking_description_lists_multiple_passengers(): void
    {
        $customer = new Customer(['full_name' => 'أحمد علي محمد']);

        $passengers = Collection::make([
            new FlightPassenger(['first_name' => 'سارة', 'last_name' => 'أحمد']),
            new FlightPassenger(['first_name' => 'محمد', 'last_name' => 'أحمد']),
            new FlightPassenger(['first_name' => 'ليلى', 'last_name' => 'أحمد']),
        ]);

        $booking = new FlightBooking([
            'from_airport' => 'القاهرة',
            'to_airport' => 'جدة',
            'departure_date' => '2025-07-10',
            'passenger_count' => 3,
        ]);
        $booking->setRelation('customer', $customer);
        $booking->setRelation('passengers', $passengers);

        $description = app(LedgerEntryDescriptionResolver::class)->forFlightBooking($booking);

        $this->assertStringContainsString('حجز تذكرة طيران للعميل / أحمد علي محمد / القاهرة - جدة / 10-07-2025', $description);
        $this->assertStringContainsString('3 مسافرين', $description);
        $this->assertStringContainsString('سارة أحمد', $description);
    }
}
