<?php

namespace Tests\Feature;

use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Enums\TripType;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger as Passenger;
use App\Models\Flight\FlightSegment;
use App\Models\User;
use App\Notifications\PassengerAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verification suite for the return-leg and segment fixes in دليل المسافرين.
 *
 * Regression coverage for:
 *   - Date filter on the manifest including return_date and segment dates.
 *   - Notification generation for return legs (round-trip) and segments.
 *   - Reversed direction for return notifications (TO -> FROM).
 *   - Dedupe so we don't double-notify and don't miss the return leg.
 */
class ReturnAndSegmentAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_active'                => true,
            'travel_alert_days_before' => 1,
            // Use a time earlier than now so the command is guaranteed to run.
            'travel_alert_time'        => '00:00:00',
        ]);
        $this->customer = Customer::factory()->create();

        Sanctum::actingAs($this->user);
    }

    private function makeRoundTripBooking(array $overrides = []): FlightBooking
    {
        $defaults = [
            'booking_reference'      => 'REF-RT-' . rand(1000, 9999),
            'booking_channel_type'   => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'customer_id'            => $this->customer->id,
            'agent_name'             => 'Test Agent',
            'origin'                 => 'CAI',
            'destination'            => 'JED',
            'departure_date'         => now()->addDays(2)->toDateString(),
            'departure_time'         => '12:00',
            'return_date'            => now()->addDays(9)->toDateString(),
            'return_time'            => '18:30',
            'trip_type'              => TripType::ROUND_TRIP->value,
            'airline'                => 'MS',
            'pnr'                    => 'RTP' . rand(1000, 9999),
            'passenger_count'        => 1,
            'status'                 => FlightBookingStatus::CONFIRMED->value,
        ];

        return FlightBooking::create(array_merge($defaults, $overrides));
    }

    private function makeSegmentBooking(array $overrides = []): FlightBooking
    {
        $booking = $this->makeRoundTripBooking(array_merge([
            'trip_type'    => TripType::ROUND_TRIP->value,
            'departure_date' => now()->addDays(3)->toDateString(),
            'return_date'  => now()->addDays(10)->toDateString(),
        ], $overrides));

        // First segment = outbound leg, second segment = return leg.
        FlightSegment::create([
            'flight_booking_id' => $booking->id,
            'from_airport'      => 'CAI',
            'to_airport'        => 'RUH',
            'departure_date'    => now()->addDays(3)->toDateString(),
            'departure_time'    => '08:00',
            'arrival_time'      => '11:00',
            'airline'           => 'SV',
            'flight_number'     => 'SV123',
            'sort_order'        => 1,
        ]);
        FlightSegment::create([
            'flight_booking_id' => $booking->id,
            'from_airport'      => 'RUH',
            'to_airport'        => 'CAI',
            'departure_date'    => now()->addDays(10)->toDateString(),
            'departure_time'    => '14:00',
            'arrival_time'      => '17:00',
            'airline'           => 'SV',
            'flight_number'     => 'SV456',
            'sort_order'        => 2,
        ]);

        return $booking->fresh(['segments']);
    }

    // ────────────────────────────────────────────────────────────────────
    // 1. Manifest list filter
    // ────────────────────────────────────────────────────────────────────

    public function test_manifest_filter_includes_round_trip_when_only_return_date_matches_window(): void
    {
        // departure_date is well outside the window, return_date is inside.
        $booking = $this->makeRoundTripBooking([
            'departure_date' => now()->subDays(5)->toDateString(),
            'return_date'    => now()->addDays(5)->toDateString(),
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Layla',
            'last_name'         => 'Khalid',
            'passport_number'   => 'P22222222',
            'national_id'       => '22222222222222',
            'type'              => 'adult',
        ]);

        // Search by return_date only — outbound is far in the past.
        $targetDate = now()->addDays(5)->toDateString();
        $response   = $this->getJson("/api/v1/flight/passengers?departure_date_from={$targetDate}&departure_date_to={$targetDate}&trip_status=all");

        $response->assertOk();

        $items = $response->json('data.items');
        $legs  = collect($items)->pluck('leg')->unique()->values()->all();

        // The booking has no segments, so the controller must emit two rows:
        // one outbound and one return. Both must surface when the filter
        // covers the return date.
        $this->assertContains('outbound', $legs, 'Outbound row is missing.');
        $this->assertContains('return', $legs, 'Return row is missing — the filter is still keyed to departure_date only.');
    }

    public function test_manifest_filter_picks_up_segment_dates_outside_booking_window(): void
    {
        $booking = $this->makeSegmentBooking([
            'departure_date' => now()->addDays(20)->toDateString(),
            'return_date'    => now()->addDays(30)->toDateString(),
        ]);

        // Override segments so one of them falls inside the filter window
        // even though the booking's outbound/return dates are far outside.
        $segments = $booking->segments;
        $segments[0]->update(['departure_date' => now()->addDays(7)->toDateString()]);
        $segments[1]->update(['departure_date' => now()->addDays(40)->toDateString()]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Omar',
            'last_name'         => 'Hasan',
            'passport_number'   => 'P33333333',
            'national_id'       => '33333333333333',
            'type'              => 'adult',
        ]);

        $targetDate = now()->addDays(7)->toDateString();
        $response   = $this->getJson("/api/v1/flight/passengers?departure_date_from={$targetDate}&departure_date_to={$targetDate}&trip_status=all");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(
            1,
            count($response->json('data.items')),
            'A passenger whose segment date matches the filter must appear.'
        );
    }

    public function test_manifest_trip_status_upcoming_includes_return_leg_when_outbound_is_past(): void
    {
        $booking = $this->makeRoundTripBooking([
            'departure_date' => now()->subDays(2)->toDateString(),
            'return_date'    => now()->addDays(3)->toDateString(),
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Hadi',
            'last_name'         => 'Sami',
            'passport_number'   => 'P44444444',
            'national_id'       => '44444444444444',
            'type'              => 'adult',
        ]);

        $response = $this->getJson('/api/v1/flight/passengers?trip_status=upcoming');

        $response->assertOk();
        $items   = $response->json('data.items');
        $passengerIds = collect($items)->pluck('passenger_id')->unique()->values()->all();

        $this->assertContains(
            $booking->passengers()->first()->id ?? Passenger::where('flight_booking_id', $booking->id)->value('id'),
            $passengerIds,
            'Round-trip with past outbound but future return must be in upcoming.'
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // 2. Notifications: return leg
    // ────────────────────────────────────────────────────────────────────

    public function test_notification_generated_for_return_leg_of_round_trip(): void
    {
        // Outbound: 5 days from now, Return: 1 day from now → notification fires.
        $booking = $this->makeRoundTripBooking([
            'departure_date' => now()->addDays(5)->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Salma',
            'last_name'         => 'Yusuf',
            'passport_number'   => 'P55555555',
            'national_id'       => '55555555555555',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $notifications = $this->user->notifications;

        $returnNotif = $notifications->first(fn ($n) =>
            ($n->data['leg_type'] ?? null) === PassengerAlertNotification::LEG_RETURN
        );

        $this->assertNotNull($returnNotif, 'No return-leg notification was generated.');
        $this->assertSame(PassengerAlertNotification::LEG_RETURN, $returnNotif->data['leg_type']);
        $this->assertSame('عودة', $returnNotif->data['leg_label_ar']);

        // Direction must be REVERSED for the return leg. The notification
        // stores the FROM of the return (= booking TO) and the TO of the
        // return (= booking FROM).
        $this->assertSame(
            $booking->destination ?? $booking->to_airport,
            $returnNotif->data['from_airport'],
            'Return notification `from_airport` must equal the booking destination.'
        );
        $this->assertSame(
            $booking->origin ?? $booking->from_airport,
            $returnNotif->data['to_airport'],
            'Return notification `to_airport` must equal the booking origin.'
        );

        // Message should mention "يعود" and the round-trip label.
        $this->assertStringContainsString('يعود', $returnNotif->data['message']);
        $this->assertStringContainsString('عودة', $returnNotif->data['message']);
        $this->assertStringContainsString($booking->pnr, $returnNotif->data['message']);
    }

    public function test_outbound_notification_still_fires_alongside_return_notification(): void
    {
        // Both outbound (1 day) and return (1 day) match — expect TWO alerts.
        $booking = $this->makeRoundTripBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Both',
            'last_name'         => 'Legs',
            'passport_number'   => 'P66666666',
            'national_id'       => '66666666666666',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $notifications = $this->user->notifications;

        $legTypes = $notifications->pluck('data.leg_type')->unique()->values()->all();
        sort($legTypes);

        $this->assertSame(
            [PassengerAlertNotification::LEG_OUTBOUND, PassengerAlertNotification::LEG_RETURN],
            $legTypes,
            'Expected one outbound and one return notification for the same passenger.'
        );
    }

    public function test_notification_dedupes_when_run_twice(): void
    {
        $booking = $this->makeRoundTripBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'No',
            'last_name'         => 'Dup',
            'passport_number'   => 'P77777777',
            'national_id'       => '77777777777777',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');
        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $this->assertCount(2, $this->user->notifications, 'Dedupe failed — got duplicate alerts.');
    }

    public function test_one_way_booking_does_not_generate_return_notification(): void
    {
        $booking = FlightBooking::create([
            'booking_reference'      => 'REF-OW-' . rand(1000, 9999),
            'booking_channel_type'   => BookingChannelType::SIGN->value,
            'booking_channel_provider' => 'SIGN',
            'customer_id'            => $this->customer->id,
            'agent_name'             => 'Test Agent',
            'origin'                 => 'CAI',
            'destination'            => 'DXB',
            'departure_date'         => now()->addDay()->toDateString(),
            'departure_time'         => '09:00',
            'trip_type'              => TripType::ONE_WAY->value,
            'airline'                => 'EK',
            'passenger_count'        => 1,
            'status'                 => FlightBookingStatus::CONFIRMED->value,
        ]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'One',
            'last_name'         => 'Way',
            'passport_number'   => 'P88888888',
            'national_id'       => '88888888888888',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $notifications = $this->user->notifications;

        $this->assertCount(1, $notifications);
        $this->assertSame(
            PassengerAlertNotification::LEG_OUTBOUND,
            $notifications->first()->data['leg_type'] ?? null
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // 3. Notifications: segments
    // ────────────────────────────────────────────────────────────────────

    public function test_notification_generated_for_each_segment(): void
    {
        // Both segments fall tomorrow → 2 notifications (one per segment).
        $booking = $this->makeSegmentBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        // Force both segments to land on tomorrow (the override of the
        // booking's outbound/return dates does NOT auto-update segments).
        $segments = $booking->segments;
        $segments[0]->update(['departure_date' => now()->addDay()->toDateString()]);
        $segments[1]->update(['departure_date' => now()->addDay()->toDateString()]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Seg',
            'last_name'         => 'Ment',
            'passport_number'   => 'P99999999',
            'national_id'       => '99999999999999',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $notifications = $this->user->notifications;

        $segmentNotifs = $notifications->filter(fn ($n) =>
            ($n->data['leg_type'] ?? null) === PassengerAlertNotification::LEG_SEGMENT
        );

        $this->assertCount(2, $segmentNotifs, 'Expected one notification per matching segment.');

        $segmentIds = $segmentNotifs->pluck('data.segment_id')->sort()->values()->all();
        $this->assertCount(2, array_unique($segmentIds), 'Segment ids must be unique per notification.');

        foreach ($segmentNotifs as $notif) {
            $this->assertStringStartsWith('خط سير', $notif->data['leg_label_ar']);
            $this->assertStringContainsString('يسافر', $notif->data['message']);
        }
    }

    public function test_segment_notification_dedupes_by_segment_id(): void
    {
        $booking = $this->makeSegmentBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        // Override segment dates to land on tomorrow so the command matches.
        $segments = $booking->segments;
        $segments[0]->update(['departure_date' => now()->addDay()->toDateString()]);
        $segments[1]->update(['departure_date' => now()->addDay()->toDateString()]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'Seg',
            'last_name'         => 'Dup',
            'passport_number'   => 'P10101010',
            'national_id'       => '10101010101010',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');
        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $this->assertCount(2, $this->user->notifications, 'Dedupe failed — got duplicate segment alerts.');
    }

    public function test_segment_booking_does_not_emit_booking_level_outbound_or_return_leg(): void
    {
        // When segments exist, the booking-level fallback must NOT emit
        // outbound/return notifications for the same date — segments are the
        // canonical source of truth.
        $booking = $this->makeSegmentBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date'    => now()->addDay()->toDateString(),
        ]);

        // Force segments to land on tomorrow so the command has matching legs.
        $segments = $booking->segments;
        $segments[0]->update(['departure_date' => now()->addDay()->toDateString()]);
        $segments[1]->update(['departure_date' => now()->addDay()->toDateString()]);

        Passenger::create([
            'flight_booking_id' => $booking->id,
            'first_name'        => 'SegOnly',
            'last_name'         => 'NoDup',
            'passport_number'   => 'P11111112',
            'national_id'       => '11111111111112',
            'type'              => 'adult',
        ]);

        Artisan::call('app:generate-passenger-alerts');

        $this->user->refresh();
        $notifications = $this->user->notifications;

        $legTypes = $notifications->pluck('data.leg_type')->unique()->values()->all();
        sort($legTypes);

        $this->assertSame(
            [PassengerAlertNotification::LEG_SEGMENT],
            $legTypes,
            'A booking with segments must not produce booking-level outbound/return notifications.'
        );
    }
}
