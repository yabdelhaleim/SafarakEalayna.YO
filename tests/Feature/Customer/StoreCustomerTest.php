<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_can_create_customer_with_passport_and_today_birth_date(): void
    {
        $today = now()->toDateString();

        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'عميل حج تجريبي',
            'phone' => '01099887766',
            'national_id' => '29801011234567',
            'travel_country' => 'السعودية',
            'passport_number' => 'A12345678',
            'date_of_birth' => $today,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'phone' => '01099887766',
            'passport_number' => 'A12345678',
        ]);
    }
}
