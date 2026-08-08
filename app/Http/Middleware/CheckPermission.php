<?php

namespace App\Http\Middleware;

use App\Support\UserPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Legacy `module.action` permission keys mapped to the new `manage_*` keys
     * defined in {@see UserPermissions::definitions()}.
     *
     * Routes in this codebase still call `permission:wallet.create`,
     * `permission:fawry.create`, etc. — these need to keep working while we
     * migrate to the new permission system. The middleware translates the old
     * key to the corresponding new one and checks the user's effective
     * permissions instead of the previous hard-coded role→permission map.
     *
     * @var array<string, string>
     */
    private const PERMISSION_ALIASES = [
        // Cash / wallet / fawry operations all live under `manage_treasury`
        // ("فوري والمحافظ") in the current permission system. Employees who
        // can record a wallet receive transaction should be allowed to record
        // fawry / wallet transactions as well.
        'wallet.create' => UserPermissions::MANAGE_TREASURY,
        'wallet.view' => UserPermissions::MANAGE_TREASURY,
        'wallet.*' => UserPermissions::MANAGE_TREASURY,
        'fawry.create' => UserPermissions::MANAGE_TREASURY,
        'fawry.view' => UserPermissions::MANAGE_TREASURY,
        'fawry.*' => UserPermissions::MANAGE_TREASURY,

        // Finance/accounts/transactions require `manage_finance`.
        'finance.view' => UserPermissions::MANAGE_FINANCE,
        'accounts.view' => UserPermissions::MANAGE_FINANCE,
        'transactions.view' => UserPermissions::MANAGE_FINANCE,

        // Employee/HR/bonuses map to `manage_employees`.
        'employees.view' => UserPermissions::MANAGE_EMPLOYEES,
        'employees.create' => UserPermissions::MANAGE_EMPLOYEES,
        'employees.edit' => UserPermissions::MANAGE_EMPLOYEES,
        'employees.bonuses' => UserPermissions::MANAGE_EMPLOYEES,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول',
                'data' => null,
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Admins and owners always have full access — no per-permission check.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return $next($request);
        }

        // Resolve the requested permission (e.g. "wallet.create") to a key in
        // the new system (e.g. "manage_treasury"). If the requested key is
        // already a valid `manage_*` key, it is returned as-is.
        $requiredPermission = $this->resolvePermission($permission);

        // If we cannot resolve it to a known permission, fall back to the
        // hard legacy role map so that historical permission strings keep
        // working. This guards against unknown keys leaking through.
        if ($requiredPermission === null) {
            if (! $this->hasLegacyRolePermission($user, $permission)) {
                return $this->forbid();
            }

            return $next($request);
        }

        $effective = UserPermissions::effectiveFor($user);

        if (! in_array($requiredPermission, $effective, true)) {
            return $this->forbid();
        }

        return $next($request);
    }

    /**
     * Translate a permission string (possibly legacy, possibly new) into a
     * valid {@see UserPermissions::definitions()} key. Returns `null` if the
     * string does not correspond to any known permission.
     */
    private function resolvePermission(string $permission): ?string
    {
        // Already in the new permission system?
        if (in_array($permission, UserPermissions::keys(), true)) {
            return $permission;
        }

        // Exact-match alias (e.g. "wallet.create" → "manage_treasury").
        if (isset(self::PERMISSION_ALIASES[$permission])) {
            return self::PERMISSION_ALIASES[$permission];
        }

        // Wildcard alias (e.g. "flights.*" → "manage_flights").
        foreach (self::PERMISSION_ALIASES as $alias => $target) {
            if (str_ends_with($alias, '*')) {
                $prefix = str_replace('*', '', $alias);
                if (str_starts_with($permission, $prefix)) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * Fallback for legacy permission strings we have not migrated yet.
     * Mirrors the previous `$rolePermissions` map so existing routes that
     * pass old keys (e.g. "buses.create") keep functioning for managers /
     * employees that the admin has previously seeded with the old role map.
     */
    private function hasLegacyRolePermission($user, string $requiredPermission): bool
    {
        $map = [
            'admin' => [
                'flights.*', 'buses.*', 'services.*', 'online.*',
                'hajj_umra.*', 'visa.*', 'wallet.*', 'fawry.*',
                'employees.*', 'finance.*', 'customers.*',
                'reports.*', 'users.*', 'settings.*',
            ],
            'manager' => [
                'flights.view', 'flights.create', 'flights.edit', 'flights.confirm', 'flights.cancel',
                'buses.view', 'buses.create', 'buses.edit',
                'services.view', 'services.create', 'services.edit',
                'online.view', 'online.create', 'online.edit',
                'hajj_umra.view', 'hajj_umra.create', 'hajj_umra.edit',
                'visa.view', 'visa.create', 'visa.edit',
                'wallet.view', 'wallet.create',
                'fawry.view', 'fawry.create',
                'employees.view', 'employees.create', 'employees.edit', 'employees.bonuses',
                'finance.view', 'accounts.view', 'transactions.view',
                'customers.view', 'customers.create', 'customers.edit',
                'reports.*',
            ],
            'employee' => [
                'flights.view', 'flights.create',
                'buses.view', 'buses.create',
                'services.view', 'services.create',
                'online.view', 'online.create',
                'hajj_umra.view', 'hajj_umra.create',
                'visa.view', 'visa.create',
                'wallet.view', 'wallet.create',
                'fawry.view', 'fawry.create',
                'customers.view', 'customers.create',
            ],
        ];

        $permissions = $map[$user->role] ?? [];

        foreach ($permissions as $permission) {
            if (str_ends_with($permission, '*')) {
                $prefix = str_replace('*', '', $permission);
                if (str_starts_with($requiredPermission, $prefix)) {
                    return true;
                }
            } elseif ($permission === $requiredPermission) {
                return true;
            }
        }

        return false;
    }

    private function forbid(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'ليس لديك صلاحية للقيام بهذا الإجراء',
            'data' => null,
        ], Response::HTTP_FORBIDDEN);
    }
}
