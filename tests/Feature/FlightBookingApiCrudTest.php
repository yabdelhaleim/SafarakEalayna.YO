<?php

namespace Tests\Feature;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightBookingApiCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Customer $customer;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->treasury = Account::create([
            'name' => 'API Treasury',
            'type' => 'treasury',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function minimalCreatePayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'selling_price' => 500,
            'purchase_price' => 300,
            'currency' => 'EGP',
            'account_id' => $this->treasury->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'departure_time' => '09:30',
            'arrival_time' => '13:00',
            'flight_number' => 'MS999',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'passengers' => [
                ['name' => 'API Pax', 'type' => 'adult'],
            ],
        ];
    }

    public function test_update_with_empty_departure_time_does_not_null_column(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalCreatePayload());
        $create->assertCreated();
        $id = (int) $create->json('data.id');
        $beforeTime = $create->json('data.departure_time');

        $update = $this->putJson("/api/v1/flight/bookings/{$id}", [
            'notes' => 'تعديل بعد الحفظ',
            'departure_time' => '',
        ]);

        $update->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Booking updated successfully.');

        $this->assertSame($beforeTime, $update->json('data.departure_time'));
    }

    public function test_flight_bookings_api_crud_response_shapes(): void
    {
        $create = $this->postJson('/api/v1/flight/bookings', $this->minimalCreatePayload());
        $create->assertCreated()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'booking_number',
                    'departure_time',
                    'selling_price',
                    'purchase_price',
                    'status',
                ],
                'errors',
            ])
            ->assertJsonPath('status', true)
            ->assertJsonPath('errors', null);

        $id = (int) $create->json('data.id');

        $this->putJson("/api/v1/flight/bookings/{$id}", ['notes' => 'CRUD test note'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.notes', 'CRUD test note');

        $this->getJson("/api/v1/flight/bookings/{$id}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $id);

        $this->getJson('/api/v1/flight/bookings?per_page=5')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'items',
                    'pagination' => [
                        'total',
                        'per_page',
                        'current_page',
                        'last_page',
                        'has_more',
                    ],
                ],
                'errors',
            ]);

        $prices = $this->postJson("/api/v1/flight/bookings/{$id}/prices", [
            'purchase_price' => 310,
            'selling_price' => 520,
        ]);
        $prices->assertOk()->assertJsonPath('status', true);
        $this->assertEqualsWithDelta(310.0, (float) $prices->json('data.purchase_price'), 0.01);
        $this->assertEqualsWithDelta(520.0, (float) $prices->json('data.selling_price'), 0.01);

        $this->postJson("/api/v1/flight/bookings/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', FlightBookingStatus::CONFIRMED->value);

        $pending = $this->postJson('/api/v1/flight/bookings', array_merge($this->minimalCreatePayload(), [
            'flight_number' => 'MS888',
            'selling_price' => 400,
            'purchase_price' => 200,
        ]));
        $pending->assertCreated();
        $pendingId = (int) $pending->json('data.id');

        $this->deleteJson("/api/v1/flight/bookings/{$pendingId}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Booking deleted successfully.')
            ->assertJsonPath('data', null);
    }
}
