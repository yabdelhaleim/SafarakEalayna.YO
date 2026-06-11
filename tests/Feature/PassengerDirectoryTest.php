<?php

namespace Tests\Feature;

use App\Models\Passenger;
use App\Models\FlightBooking;
use App\Models\Customer;
use App\Models\User;
use App\Enums\FlightBookingStatus;
use App\Enums\BookingChannelType;
use App\Enums\TripType;
use App\Notifications\PassengerAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_active' => true,
            'travel_alert_days_before' => 1,
            'travel_alert_time' => '09:00:00'
        ]);
        $this->customer = Customer::factory()->create();

        Sanctum::actingAs($this->user);
    }

    public function test_get_and_update_alert_settings(): void
    {
        $response = $this->getJson('/api/v1/flight/passengers/alert-settings');
        $response->assertOk()
            ->assertJsonPath('data.travel_alert_days_before', 1)
            ->assertJsonPath('data.travel_alert_time', '09:00:00');

        $updateResponse = $this->putJson('/api/v1/flight/passengers/alert-settings', [
            'travel_alert_days_before' => 2,
            'travel_alert_time' => '10:00:00'
        ]);
        $updateResponse->assertOk()
            ->assertJsonPath('data.travel_alert_days_before', 2)
            ->assertJsonPath('data.travel_alert_time', '10:00:00');

        $this->user->refresh();
        $this->assertEquals(2, $this->user->travel_alert_days_before);
        $this->assertEquals('10:00:00', $this->user->travel_alert_time);
    }

    public function test_list_passengers_ordered_by_upcoming_first(): void
    {
        // 1. Create a past booking (yesterday)
        $pastBooking = FlightBooking::create([
            'booking_reference' => 'REF-PAST-' . rand(1000, 9999),
            'booking_channel_type' => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'customer_id' => $this->customer->id,
            'agent_name' => 'Test Agent',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->subDay()->toDateString(),
            'departure_time' => '12:00',
            'trip_type' => TripType::ONE_WAY->value,
            'airline' => 'MS',
            'passenger_count' => 1,
            'status' => FlightBookingStatus::CONFIRMED->value
        ]);

        $pastPassenger = Passenger::create([
            'flight_booking_id' => $pastBooking->id,
            'first_name' => 'Past',
            'last_name' => 'Passenger',
            'passport_number' => 'A11111111',
            'national_id' => '12345678901234',
            'type' => 'adult'
        ]);

        // 2. Create an upcoming booking (tomorrow)
        $upcomingBooking = FlightBooking::create([
            'booking_reference' => 'REF-UPCO-' . rand(1000, 9999),
            'booking_channel_type' => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'customer_id' => $this->customer->id,
            'agent_name' => 'Test Agent',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '12:00',
            'trip_type' => TripType::ONE_WAY->value,
            'airline' => 'MS',
            'passenger_count' => 1,
            'status' => FlightBookingStatus::CONFIRMED->value
        ]);

        $upcomingPassenger = Passenger::create([
            'flight_booking_id' => $upcomingBooking->id,
            'first_name' => 'Upcoming',
            'last_name' => 'Passenger',
            'passport_number' => 'B22222222',
            'national_id' => '12345678905678',
            'type' => 'adult'
        ]);

        // List passengers API
        $response = $this->getJson('/api/v1/flight/passengers');
        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $upcomingPassenger->id) // Upcoming should be first
            ->assertJsonPath('data.items.1.id', $pastPassenger->id);    // Past should be second

        // Test search
        $searchResponse = $this->getJson('/api/v1/flight/passengers?search=Upcoming');
        $searchResponse->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $upcomingPassenger->id);

        // Test trip_status=past filter
        $filterResponse = $this->getJson('/api/v1/flight/passengers?trip_status=past');
        $filterResponse->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $pastPassenger->id);
    }

    public function test_generate_passenger_alerts_command(): void
    {
        // Set current time mock or just compute alert time based on current time to guarantee execution
        $this->user->update([
            'travel_alert_days_before' => 1,
            'travel_alert_time' => now()->subHour()->format('H:i:s') // Guaranteed to be past alert time
        ]);

        // Create booking for tomorrow (1 day before)
        $booking = FlightBooking::create([
            'booking_reference' => 'REF-ALERT-' . rand(1000, 9999),
            'booking_channel_type' => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'customer_id' => $this->customer->id,
            'agent_name' => 'Test Agent',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '12:00',
            'trip_type' => TripType::ONE_WAY->value,
            'airline' => 'MS',
            'passenger_count' => 1,
            'status' => FlightBookingStatus::CONFIRMED->value
        ]);

        $passenger = Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name' => 'Alert',
            'last_name' => 'Passenger',
            'passport_number' => 'A99999999',
            'national_id' => '12345678909999',
            'type' => 'adult'
        ]);

        $this->assertCount(0, $this->user->unreadNotifications);

        // Run the command
        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $this->assertCount(1, $this->user->unreadNotifications);

        $notification = $this->user->unreadNotifications->first();
        $this->assertEquals(PassengerAlertNotification::class, $notification->type);
        $this->assertEquals($passenger->id, $notification->data['passenger_id']);

        // Check listing notifications API
        $response = $this->getJson('/api/v1/flight/passengers/notifications');
        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $notification->id);

        // Mark as read
        $markResponse = $this->postJson("/api/v1/flight/passengers/notifications/{$notification->id}/mark-read");
        $markResponse->assertOk();

        $this->user->refresh();
        $this->assertCount(0, $this->user->unreadNotifications);
    }
}
