<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * الصلاحيات المتاحة لكل دور
     */
    private array $rolePermissions = [
        'admin' => [
            'flights.*', 'buses.*', 'services.*', 'online.*',
            'employees.*', 'finance.*', 'customers.*',
            'reports.*', 'users.*', 'settings.*'
        ],
        'manager' => [
            'flights.view', 'flights.create', 'flights.edit', 'flights.confirm', 'flights.cancel',
            'buses.view', 'buses.create', 'buses.edit',
            'services.view', 'services.create', 'services.edit',
            'online.view', 'online.create', 'online.edit',
            'employees.view', 'employees.create', 'employees.edit', 'employees.bonuses',
            'finance.view', 'accounts.view', 'transactions.view',
            'customers.view', 'customers.create', 'customers.edit',
            'reports.*'
        ],
        'employee' => [
            'flights.view', 'flights.create',
            'buses.view', 'buses.create',
            'services.view', 'services.create',
            'online.view', 'online.create',
            'customers.view', 'customers.create',
        ],
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول',
                'data' => null,
            ], Response::HTTP_UNAUTHORIZED);
        }

        // المسؤولون لديهم صلاحيات كاملة
        if ($user->role === 'admin') {
            return $next($request);
        }

        // التحقق من الصلاحيات
        if (!$this->hasPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك صلاحية للقيام بهذا الإجراء',
                'data' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * التحقق من أن المستخدم لديه الصلاحية المطلوبة
     */
    private function hasPermission($user, string $requiredPermission): bool
    {
        $role = $user->role;
        $permissions = $this->rolePermissions[$role] ?? [];

        foreach ($permissions as $permission) {
            // دعم wildcard (flights.* يطابق flights.view, flights.create، إلخ)
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
}
