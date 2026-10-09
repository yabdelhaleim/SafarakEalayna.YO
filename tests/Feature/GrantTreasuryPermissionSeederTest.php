<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserPermissions;
use Database\Seeders\GrantTreasuryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GrantTreasuryPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_without_permissions_is_locked_out_and_then_unlocked_by_seeder(): void
    {
        // 1. Create an active employee user with no permissions (or empty array)
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => null,
        ]);

        // 2. Try to list wallet transactions as the employee - should return 403
        Sanctum::actingAs($employee);
        $response = $this->getJson('/api/v1/wallet/transactions');
        $response->assertStatus(403);

        // 3. Run the seeder
        $this->seed(GrantTreasuryPermissionSeeder::class);

        // Refresh user from DB to get updated permissions
        $employee->refresh();

        // Verify that the seeder granted the expected permissions
        $this->assertContains(UserPermissions::MANAGE_TREASURY, $employee->permissions);

        // 4. Try listing wallet transactions again - should return 200 (OK)
        $response2 = $this->getJson('/api/v1/wallet/transactions');
        $response2->assertStatus(200);
    }

    public function test_seeder_is_idempotent_and_does_not_override_existing_permissions(): void
    {
        // 1. Create an active employee user who already has some custom permissions (e.g. just flights)
        $employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [UserPermissions::MANAGE_FLIGHTS],
        ]);

        // 2. Run the seeder
        $this->seed(GrantTreasuryPermissionSeeder::class);

        // 3. Refresh user and verify their permissions are completely untouched (only has flights)
        $employee->refresh();
        $this->assertEquals([UserPermissions::MANAGE_FLIGHTS], $employee->permissions);
    }
}
