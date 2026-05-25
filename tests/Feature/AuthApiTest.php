<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user'],
                'errors',
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Another User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors' => ['email'],
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_register_fails_with_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'success' => false,
            ]);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'test@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'success' => false,
            ]);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'email' => 'test@test.com',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'success' => false,
            ]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user'],
                'errors',
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_update_profile_fails_with_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'user@example.com',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'success' => false,
            ]);
    }

    public function test_update_profile_allows_same_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'name' => 'Test User Updated',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'success' => true,
            ]);
    }

    public function test_update_profile_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(200);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_update_profile_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'success' => false,
            ]);
    }
}
