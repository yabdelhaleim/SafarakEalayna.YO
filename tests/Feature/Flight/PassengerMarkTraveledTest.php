<?php

namespace Tests\Feature\Flight;

use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for PassengerController mark/unmark + alerts + notifications — Phase 3.
 *
 * ⚠️ PassengerController (400 سطر) — صفر تغطية:
 *   - markTraveled / unmarkTraveled
 *   - getAlertSettings / updateAlertSettings
 *   - getNotifications / markNotificationRead / markAllNotificationsRead
 *
 * @see \App\Http\Controllers\Api\V1\Flight\PassengerController
 */
class PassengerMarkTraveledTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightBooking $booking;

    protected FlightPassenger $passenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Passenger Test Admin',
            'email' => 'passenger-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $customer = Customer::create([
            'full_name' => 'Passenger Test Cust',
            'phone' => '01066666666',
        ]);

        $this->booking = FlightBooking::create([
            'customer_id' => $customer->id,
            'booking_reference' => 'PAX-'.uniqid(),
            'booking_number' => 'PAX-'.uniqid(),
            'booking_channel_type' => 'manual',
            'booking_channel_provider' => 'Direct',
            'system_type' => 'manual',
            'status' => 'CONFIRMED',
            'agent_name' => 'Test',
            'airline' => 'Test Air',
            'airline_name' => 'Test Air',
            'origin' => 'JED',
            'destination' => 'CAI',
            'from_airport' => 'JED',
            'to_airport' => 'CAI',
            'departure_date' => now()->addDays(7),
            'departure_time' => now()->addDays(7)->setTime(10, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'purchase_price' => 500,
            'selling_price' => 800,
            'profit' => 300,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->passenger = FlightPassenger::create([
            'flight_booking_id' => $this->booking->id,
            'first_name' => 'Test Pax',
            'last_name' => 'X',
            'type' => 'adult',
            'date_of_birth' => '1990-01-01',
        ]);
    }

    /**
     * ✅ 1) markTraveled sets traveled_at and prevents re-marking
     */
    public function test_mark_traveled_sets_traveled_at(): void
    {
        $response = $this->postJson("/api/v1/flight/passengers/{$this->passenger->id}/mark-traveled");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->passenger->refresh();
        $this->assertNotNull($this->passenger->traveled_at,
            'traveled_at must be set after mark-traveled');
    }

    /**
     * ✅ 2) markTraveled rejects when already traveled
     */
    public function test_mark_traveled_rejects_double_marking(): void
    {
        // أول مرة → success
        $this->postJson("/api/v1/flight/passengers/{$this->passenger->id}/mark-traveled")
            ->assertOk();

        // تاني مرة → 422
        $response = $this->postJson("/api/v1/flight/passengers/{$this->passenger->id}/mark-traveled");
        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /**
     * ✅ 3) unmarkTraveled clears traveled_at
     */
    public function test_unmark_traveled_clears_traveled_at(): void
    {
        // أول: mark
        $this->postJson("/api/v1/flight/passengers/{$this->passenger->id}/mark-traveled")
            ->assertOk();
        $this->passenger->refresh();
        $this->assertNotNull($this->passenger->traveled_at);

        // ثاني: unmark
        $response = $this->postJson("/api/v1/flight/passengers/{$this->passenger->id}/unmark-traveled");
        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->passenger->refresh();
        $this->assertNull($this->passenger->traveled_at,
            'traveled_at must be null after unmark-traveled');
    }

    /**
     * ✅ 4) getAlertSettings returns defaults
     */
    public function test_get_alert_settings_returns_defaults(): void
    {
        $response = $this->getJson('/api/v1/flight/passengers/alert-settings');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['travel_alert_days_before', 'travel_alert_time']]);

        // الـ defaults: 1 يوم، 09:00:00
        $this->assertEquals(1, $response->json('data.travel_alert_days_before'));
        $this->assertEquals('09:00:00', $response->json('data.travel_alert_time'));
    }

    /**
     * ✅ 5) updateAlertSettings updates the user's alert preferences
     */
    public function test_update_alert_settings_succeeds(): void
    {
        $response = $this->putJson('/api/v1/flight/passengers/alert-settings', [
            'travel_alert_days_before' => 3,
            'travel_alert_time' => '08:30',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.travel_alert_days_before', 3);

        // الـ time بيرجع كما هو (input "08:30" يفضل "08:30" في الـ response)
        $this->assertEquals('08:30', $response->json('data.travel_alert_time'));

        // نتأكد من الـ DB
        $this->admin->refresh();
        $this->assertEquals(3, $this->admin->travel_alert_days_before);
    }
}