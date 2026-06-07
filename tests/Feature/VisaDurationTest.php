<?php

namespace Tests\Feature;

use App\Models\HajjUmra\VisaDuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisaDurationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Duration Tester',
            'email' => 'duration-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_visa_durations_api_returns_active_ordered_durations(): void
    {
        // 1. Create a mix of active and inactive visa durations with different sort orders
        VisaDuration::query()->create([
            'code' => 'active_2',
            'label_ar' => 'نشط ٢',
            'label_en' => 'Active 2',
            'months' => 3,
            'entry_type' => 'multiple',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        VisaDuration::query()->create([
            'code' => 'active_1',
            'label_ar' => 'نشط ١',
            'label_en' => 'Active 1',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        VisaDuration::query()->create([
            'code' => 'inactive_duration',
            'label_ar' => 'غير نشط',
            'label_en' => 'Inactive',
            'months' => 6,
            'entry_type' => 'single',
            'sort_order' => 5,
            'is_active' => false,
        ]);

        VisaDuration::query()->create([
            'code' => 'umrah_duration',
            'label_ar' => 'عمرة',
            'label_en' => 'Umrah',
            'months' => null,
            'entry_type' => 'single',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        // 2. Call the endpoint
        $response = $this->getJson('/api/v1/visa/settings/durations');

        // 3. Assert response
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'label',
                        'days',
                        'code',
                        'label_ar',
                        'label_en',
                        'months',
                        'entry_type',
                    ]
                ]
            ]);

        $data = $response->json('data');

        // Verify that only active records are returned (3 out of 4)
        $this->assertCount(3, $data);

        // Verify correct sort ordering: active_1 (sort 10), active_2 (sort 20), umrah_duration (sort 30)
        $this->assertSame('active_1', $data[0]['code']);
        $this->assertSame('active_2', $data[1]['code']);
        $this->assertSame('umrah_duration', $data[2]['code']);

        // Verify days mapping calculation
        $this->assertSame(30, $data[0]['days']); // 1 month * 30
        $this->assertSame(90, $data[1]['days']); // 3 months * 30
        $this->assertNull($data[2]['days']);      // null months -> null days
    }
}
