<?php

namespace Database\Seeders;

use App\Support\UserPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [B-02] SEC-1 Employee Lockout Remediation Seeder
 *
 * Context (2026-08-21):
 *   The SEC-1 fix in UserPermissions::effectiveFor() changed the permission
 *   model from "grant-by-role-default" to "deny-by-default". After this fix,
 *   any user with role='employee' or role='manager' who has permissions=null
 *   or permissions=[] is completely locked out of Wallet and Fawry routes
 *   (which require manage_treasury).
 *
 * This seeder:
 *   1. Finds all active non-admin, non-owner users with no stored permissions.
 *   2. Grants them the full historical permission set that matched their role
 *      in the pre-SEC-1 legacy map (see CheckPermission::hasLegacyRolePermission).
 *   3. Is IDEMPOTENT: if a user already has at least one valid stored permission,
 *      their record is left untouched. The admin can then further narrow/expand
 *      permissions via the UI.
 *
 * Usage:
 *   php artisan db:seed --class=GrantTreasuryPermissionSeeder
 *
 * DO NOT add to DatabaseSeeder::run() — this is a one-time fix seeder.
 * After running, verify in staging that an employee can POST /api/v1/wallet/transactions.
 */
class GrantTreasuryPermissionSeeder extends Seeder
{
    /**
     * Historical default permissions per role, mirroring the pre-SEC-1
     * CheckPermission::hasLegacyRolePermission() map translated to
     * the new UserPermissions key system.
     *
     * Only keys that exist in UserPermissions::keys() are valid.
     */
    private const ROLE_DEFAULT_PERMISSIONS = [
        'manager' => [
            UserPermissions::MANAGE_FLIGHTS,
            UserPermissions::MANAGE_BUS,
            UserPermissions::MANAGE_HAJJ,
            UserPermissions::MANAGE_ONLINE,
            UserPermissions::MANAGE_TREASURY,
            UserPermissions::MANAGE_REFUNDS,
            UserPermissions::MANAGE_FINANCE,
            UserPermissions::MANAGE_EMPLOYEES,
            UserPermissions::VIEW_REPORTS,
        ],
        'employee' => [
            // Matches UserPermissions::defaultEmployeeModules()
            UserPermissions::MANAGE_FLIGHTS,
            UserPermissions::MANAGE_BUS,
            UserPermissions::MANAGE_HAJJ,
            UserPermissions::MANAGE_ONLINE,
            UserPermissions::MANAGE_TREASURY,
            UserPermissions::MANAGE_REFUNDS,
        ],
    ];

    public function run(): void
    {
        $validKeys = UserPermissions::keys();

        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('[B-02] GrantTreasuryPermissionSeeder — بدء التشغيل');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $updated = 0;
        $skipped = 0;
        $skippedNames = [];

        DB::table('users')
            ->whereIn('role', array_keys(self::ROLE_DEFAULT_PERMISSIONS))
            ->where('is_active', true)
            ->lazyById()
            ->each(function (object $user) use ($validKeys, &$updated, &$skipped, &$skippedNames) {
                // Parse existing permissions from JSON column.
                $stored = is_string($user->permissions)
                    ? (json_decode($user->permissions, true) ?? [])
                    : (is_array($user->permissions) ? $user->permissions : []);

                // Intersect with valid keys to strip any stale/invalid entries.
                $storedValid = array_values(array_intersect($stored, $validKeys));

                // Idempotent: if user already has >=1 valid stored permission,
                // do NOT overwrite. Admin has taken explicit control.
                if (count($storedValid) > 0) {
                    $skipped++;
                    $skippedNames[] = "{$user->name} (#{$user->id})";
                    return;
                }

                // Determine the default permission set for this role.
                $defaultPerms = self::ROLE_DEFAULT_PERMISSIONS[$user->role] ?? [];

                if (empty($defaultPerms)) {
                    $this->command->warn("  Role غير معروف: {$user->role} — تم تخطي #{$user->id}");
                    $skipped++;
                    return;
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'permissions' => json_encode(array_values($defaultPerms)),
                        'updated_at'  => now(),
                    ]);

                $this->command->line("  [+] #{$user->id} {$user->name} ({$user->role})");

                Log::info('[B-02] GrantTreasuryPermissionSeeder: granted permissions', [
                    'user_id'     => $user->id,
                    'user_name'   => $user->name,
                    'role'        => $user->role,
                    'permissions' => $defaultPerms,
                ]);

                $updated++;
            });

        $this->command->info('');
        $this->command->info("  Total منحت: {$updated} — تم تخطي: {$skipped}");
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('[B-02] GrantTreasuryPermissionSeeder — اكتمل');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
