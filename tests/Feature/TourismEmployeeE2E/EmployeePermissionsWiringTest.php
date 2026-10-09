<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\User;
use App\Support\UserPermissions;

/**
 * Verifies the UserPermissions wiring matches what EmployeeTestCase sets up.
 *
 * If these wiring tests fail, every other Employee audit test would be
 * meaningless — so they run first.
 */
class EmployeePermissionsWiringTest extends EmployeeTestCase
{
    public function test_admin_has_all_permissions(): void
    {
        $perms = UserPermissions::effectiveFor($this->admin);

        $this->assertContains(UserPermissions::MANAGE_FLIGHTS, $perms);
        $this->assertContains(UserPermissions::MANAGE_HAJJ, $perms);
        $this->assertContains(UserPermissions::MANAGE_ONLINE, $perms);
        $this->assertContains(UserPermissions::MANAGE_TREASURY, $perms);
        $this->assertContains(UserPermissions::MANAGE_FINANCE, $perms);
        $this->assertContains(UserPermissions::MANAGE_EMPLOYEES, $perms);
        $this->assertContains(UserPermissions::VIEW_REPORTS, $perms);
        $this->assertContains(UserPermissions::MANAGE_USERS, $perms);
        $this->assertTrue($this->admin->isAdmin());
    }

    public function test_normal_employee_has_default_module_set(): void
    {
        $perms = UserPermissions::effectiveFor($this->normalEmployee);

        $this->assertContains(UserPermissions::MANAGE_FLIGHTS, $perms);
        $this->assertContains(UserPermissions::MANAGE_HAJJ, $perms);
        $this->assertContains(UserPermissions::MANAGE_ONLINE, $perms);
        $this->assertContains(UserPermissions::MANAGE_BUS, $perms);
        $this->assertContains(UserPermissions::MANAGE_TREASURY, $perms);

        // Admin-only perms NOT in default employee set
        $this->assertNotContains(UserPermissions::MANAGE_FINANCE, $perms);
        $this->assertNotContains(UserPermissions::MANAGE_EMPLOYEES, $perms);
        $this->assertNotContains(UserPermissions::VIEW_REPORTS, $perms);
        $this->assertNotContains(UserPermissions::MANAGE_USERS, $perms);

        $this->assertFalse($this->normalEmployee->isAdmin());
        $this->assertTrue($this->normalEmployee->isEmployee());
    }

    public function test_restricted_employee_has_only_flights(): void
    {
        $perms = UserPermissions::effectiveFor($this->restrictedEmployee);

        $this->assertSame([UserPermissions::MANAGE_FLIGHTS], $perms);
        $this->assertNotContains(UserPermissions::MANAGE_HAJJ, $perms);
        $this->assertNotContains(UserPermissions::MANAGE_ONLINE, $perms);
    }

    /**
     * SECURITY FINDING: The system has no way to grant an employee ZERO permissions.
     * - If `permissions` is null/empty/invalid → employee receives default modules.
     * - This is by-design in UserPermissions::effectiveFor() (line 138 fallback).
     * - An admin who wants to "lock down" an employee temporarily cannot — they
     *   must deactivate the account instead.
     */
    public function test_locked_employee_falls_back_to_default_modules(): void
    {
        $perms = UserPermissions::effectiveFor($this->lockedEmployee);

        // The system DESIGN forces fallback to defaults when permissions is empty.
        $this->assertSame(UserPermissions::defaultEmployeeModules(), $perms);
        $this->assertNotSame([], $perms);
        $this->assertFalse($this->lockedEmployee->isAdmin());
    }

    public function test_employee_with_only_invalid_permission_keys_still_gets_defaults(): void
    {
        $user = $this->makeUser('employee', ['permissions' => ['bogus_key_xyz']]);
        $perms = UserPermissions::effectiveFor($user);

        // The intersection drops the bogus key; the empty stored array falls back to defaults.
        $this->assertSame(UserPermissions::defaultEmployeeModules(), $perms);
    }

    public function test_inactive_employee_flag_is_false(): void
    {
        $this->assertFalse((bool) $this->inactiveEmployee->is_active);
        $this->assertTrue($this->inactiveEmployee->isEmployee());
    }

    public function test_two_employees_are_distinct_users(): void
    {
        $this->assertNotSame($this->normalEmployee->id, $this->otherEmployee->id);
        $this->assertNotSame($this->normalEmployee->id, $this->restrictedEmployee->id);
        $this->assertNotSame($this->normalEmployee->id, $this->lockedEmployee->id);
    }

    public function test_active_middleware_rejects_inactive_employee(): void
    {
        $this->actAs($this->inactiveEmployee);

        // Hit any protected Tourism endpoint — should be rejected by EnsureIsActive
        $response = $this->getJson('/api/v1/hajj-umra/bookings');

        $this->assertSame(401, $response->status(), 'Inactive employee must be rejected with 401');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        // Forget all auth state from setUp() so this call is truly unauthenticated
        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs(new \App\Models\User(), []);

        // Now make the call without any user bound to the guard
        auth()->forgetGuards();
        $this->app['auth']->forgetUser();

        $response = $this->getJson('/api/v1/hajj-umra/bookings');

        $this->assertSame(401, $response->status(), 'Unauthenticated request must be rejected');
    }
}