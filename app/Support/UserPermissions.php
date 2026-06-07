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
        ];
    }

    /**
     * Permissions used for route guards and navigation.
     *
     * @return list<string>
     */
    public static function effectiveFor(User $user): array
    {
        $stored = is_array($user->permissions) ? array_values($user->permissions) : [];
        $stored = array_values(array_intersect($stored, self::keys()));

        if (in_array($user->role, ['admin', 'owner'], true)) {
            return $stored !== [] ? $stored : self::all();
        }

        if ($stored !== []) {
            return $stored;
        }

        return self::defaultEmployeeModules();
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
