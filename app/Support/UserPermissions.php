<?php

namespace App\Support;

use App\Models\User;

class UserPermissions
{
    public const MANAGE_FLIGHTS = 'manage_flights';

    public const MANAGE_BUS = 'manage_bus';

    public const MANAGE_HAJJ = 'manage_hajj';

    public const MANAGE_ONLINE = 'manage_online';

    public const MANAGE_TREASURY = 'manage_treasury';

    public const MANAGE_REFUNDS = 'manage_refunds';

    public const MANAGE_FINANCE = 'manage_finance';

    public const MANAGE_EMPLOYEES = 'manage_employees';

    public const VIEW_REPORTS = 'view_reports';

    public const MANAGE_USERS = 'manage_users';

    /**
     * @return list<array{id: string, name: string, desc: string, group: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'id' => self::MANAGE_FLIGHTS,
                'name' => 'موديول الطيران',
                'desc' => 'حجوزات وتذاكر الطيران وعملاء القسم',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_BUS,
                'name' => 'موديول الباصات',
                'desc' => 'حجوزات النقل البري والشركات الناقلة',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_HAJJ,
                'name' => 'موديول الحج والعمرة',
                'desc' => 'برامج الحج والعمرة والحجوزات',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_ONLINE,
                'name' => 'التأشيرات والخدمات الإلكترونية',
                'desc' => 'تأشيرات سياحية ومعاملات الأونلاين',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_TREASURY,
                'name' => 'فوري والمحافظ',
                'desc' => 'معاملات فوري والمحافظ والتحويلات',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_REFUNDS,
                'name' => 'الاسترداد المالي',
                'desc' => 'تنفيذ طلبات الاسترداد على الحجوزات (طيران، حج وعمرة، تأشيرات)',
                'group' => 'modules',
            ],
            [
                'id' => self::MANAGE_FINANCE,
                'name' => 'المالية والحسابات',
                'desc' => 'الخزينة العامة، كشوف الحسابات، والتحويلات',
                'group' => 'admin',
            ],
            [
                'id' => self::MANAGE_EMPLOYEES,
                'name' => 'شؤون الموظفين',
                'desc' => 'الموظفين والحضور والمكافآت',
                'group' => 'admin',
            ],
            [
                'id' => self::VIEW_REPORTS,
                'name' => 'التقارير والإحصائيات',
                'desc' => 'مركز التقارير والديون والمديونيات',
                'group' => 'admin',
            ],
            [
                'id' => self::MANAGE_USERS,
                'name' => 'إدارة المستخدمين',
                'desc' => 'إنشاء الحسابات وتحديد الصلاحيات',
                'group' => 'admin',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'id');
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::keys();
    }

    /**
     * Default module access for employees without explicit permissions.
     *
     * @return list<string>
     */
    public static function defaultEmployeeModules(): array
    {
        return [
            self::MANAGE_FLIGHTS,
            self::MANAGE_BUS,
            self::MANAGE_HAJJ,
            self::MANAGE_ONLINE,
            self::MANAGE_TREASURY,
            self::MANAGE_REFUNDS,
        ];
    }

    /**
     * Permissions used for route guards and navigation.
     *
     * Deny-by-default (SEC-1 fix, 2026-08-21):
     *   - admin / owner → always full (`all()`)
     *   - any other role → ONLY the stored, whitelisted permissions.
     *     Empty / null / all-invalid stored permissions → `[]` (deny-all).
     *
     * Pre-fix, employees with `permissions=null` or `permissions=[]`
     * silently received `defaultEmployeeModules()`, which includes
     * `manage_treasury` and therefore unlocked wallet posting. That
     * meant any newly-created `role='employee'` user could post wallet
     * transactions immediately, with no way for an admin to "lock them
     * out" short of changing their role.
     *
     * Post-fix, every non-admin/non-owner user MUST be granted
     * permissions explicitly. `defaultEmployeeModules()` is preserved
     * as a convenience constant for seeders / fixtures that explicitly
     * seed it into `permissions` — it is no longer auto-applied.
     *
     * @return list<string>
     */
    public static function effectiveFor(User $user): array
    {
        $stored = is_array($user->permissions) ? array_values($user->permissions) : [];
        $stored = array_values(array_intersect($stored, self::keys()));

        if (in_array($user->role, ['admin', 'owner'], true)) {
            // Admin/owner always have full access; stored perms override the
            // default-all only when explicitly granted (allows admin to
            // temporarily narrow their own access for testing).
            return $stored !== [] ? $stored : self::all();
        }

        // Any other role: deny-by-default. Return ONLY what is explicitly
        // stored. Empty stored perms → [] → route guards will reject.
        return $stored;
    }

    /**
     * @param  list<string>|null  $permissions
     * @return list<string>
     */
    public static function sanitize(?array $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }

        return array_values(array_intersect($permissions, self::keys()));
    }
}
