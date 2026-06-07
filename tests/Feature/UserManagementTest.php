<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Users Admin',
            'email' => 'users-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_users_index_returns_available_permissions_and_effective_access(): void
    {
        $employee = User::query()->create([
            'name' => 'Scoped Employee',
            'email' => 'scoped-employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => ['manage_finance', 'view_reports'],
        ]);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.available_permissions.0.id', 'manage_flights');

        $this->assertNotNull(
            collect($response->json('data.users'))->firstWhere('id', $this->admin->id)
        );

        $employeeRow = collect($response->json('data.users'))
            ->firstWhere('id', $employee->id);

        $this->assertSame(['manage_finance', 'view_reports'], $employeeRow['permissions']);
        $this->assertSame(['manage_finance', 'view_reports'], $employeeRow['effective_permissions']);
    }

    public function test_employee_without_stored_permissions_gets_default_module_access(): void
    {
        $employee = User::query()->create([
            'name' => 'Default Employee',
            'email' => 'default-employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
            'permissions' => [],
        ]);

        $this->assertSame(
            UserPermissions::defaultEmployeeModules(),
            UserPermissions::effectiveFor($employee)
        );
    }

    public function test_store_rejects_unknown_permissions(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Bad Perms',
            'email' => 'bad-perms@example.com',
            'password' => 'password123',
            'role' => 'employee',
            'is_active' => true,
            'permissions' => ['view_dashboard', 'manage_finance'],
        ]);

        $response->assertStatus(422);
    }

    public function test_owner_account_cannot_be_deleted(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner User',
            'email' => 'owner-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$owner->id}")
            ->assertStatus(403);
    }
}
