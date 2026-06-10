<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConcurrentSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_can_use_same_endpoint_simultaneously()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response1 = $this->actingAs($user1, 'sanctum')->get('/api/user');
        $response2 = $this->actingAs($user2, 'sanctum')->get('/api/user');

        $response1->assertStatus(200);
        $response2->assertStatus(200);
    }

    public function test_unauthenticated_request_returns_401_not_redirect()
    {
        $response = $this->getJson('/api/user');

        // لازم يرجع 401 JSON مش يعمل redirect لـ /login
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_user_session_not_affected_by_other_user()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // user1 بيعمل requests متعددة
        $this->actingAs($user1, 'sanctum')->getJson('/api/user')->assertStatus(200);
        $this->actingAs($user1, 'sanctum')->getJson('/api/user')->assertStatus(200);

        // user2 يشتغل في نفس الوقت
        $this->actingAs($user2, 'sanctum')->getJson('/api/user')->assertStatus(200);

        // user1 لسه شغال
        $this->actingAs($user1, 'sanctum')->getJson('/api/user')->assertStatus(200);
    }
}