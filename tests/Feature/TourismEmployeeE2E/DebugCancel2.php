<?php
namespace Tests\Feature\TourismEmployeeE2E;

class DebugCancel2 extends EmployeeFlightE2ETest
{
    public function test_debug(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);
        $this->actAs($this->normalEmployee);
        
        // Test admin route detection
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0,
            'office_penalty' => 0,
        ]);
        $status = $response->status();
        if (function_exists('dump')) {
            dump("STATUS=$status");
            dump("BODY=", $response->getContent());
        }
        $this->assertContains($status, [403, 422], "Should be 403 or 422, got $status");
    }
}
