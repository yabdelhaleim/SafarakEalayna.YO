<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 Security Hardening — Rate limiting regression test.
 *
 * Verifies that:
 *  - login endpoint is rate-limited to 5/min per IP (throttle:auth)
 *  - register endpoint is rate-limited to 5/min per IP (throttle:auth)
 *  - repeated bad credentials don't bypass the throttle
 *
 * @see \App\Providers\AppServiceProvider::boot()
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Rate Limit User',
            'email' => 'ratelimit@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_login_endpoint_throttles_after_5_attempts(): void
    {
        // Hit login 5 times with bad credentials — should all return 401 (not throttled yet)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $this->user->email,
                'password' => 'wrong-password',
            ]);
            $this->assertSame(401, $response->status(), "Attempt {$i} should be 401, got {$response->status()}");
        }

        // 6th attempt should be throttled (429)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(429, $response->status(), "6th attempt should be throttled, got {$response->status()}");
    }

    public function test_register_endpoint_throttles_after_5_attempts(): void
    {
        $base = now()->timestamp;

        // Hit register 5 times — should all return 422 (validation) or 201 (success) but not throttled
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/register', [
                'name' => 'Test',
                'email' => "user{$base}-{$i}@test.test",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);
            $this->assertNotSame(429, $response->status(), "Attempt {$i} should not be throttled, got {$response->status()}");
        }

        // 6th attempt should be throttled
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'email' => "user{$base}-6@test.test",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertSame(429, $response->status(), "6th attempt should be throttled, got {$response->status()}");
    }

    public function test_correct_credentials_dont_get_throttled_at_first(): void
    {
        // One correct login should succeed (not throttled)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $this->assertNotSame(429, $response->status());
        $this->assertSame(200, $response->status());
    }
}