export const USER_PERMISSIONS = [
  { id: 'manage_flights', name: 'موديول الطيران', desc: 'حجوزات وتذاكر الطيران وعملاء القسم', group: 'modules' },
  { id: 'manage_bus', name: 'موديول الباصات', desc: 'حجوزات النقل البري والشركات الناقلة', group: 'modules' },
  { id: 'manage_hajj', name: 'موديول الحج والعمرة', desc: 'برامج الحج والعمرة والحجوزات', group: 'modules' },
  { id: 'manage_online', name: 'التأشيرات والخدمات الإلكترونية', desc: 'تأشيرات سياحية ومعاملات الأونلاين', group: 'modules' },
  { id: 'manage_treasury', name: 'فوري والمحافظ', desc: 'معاملات فوري والمحافظ والتحويلات', group: 'modules' },
  { id: 'manage_finance', name: 'المالية والحسابات', desc: 'الخزينة العامة، كشوف الحسابات، والتحويلات', group: 'admin' },
  { id: 'manage_employees', name: 'شؤون الموظفين', desc: 'الموظفين والحضور والمكافآت', group: 'admin' },
  { id: 'view_reports', name: 'التقارير والإحصائيات', desc: 'مركز التقارير والديون والمديونيات', group: 'admin' },
  { id: 'manage_users', name: 'إدارة المستخدمين', desc: 'إنشاء الحسابات وتحديد الصلاحيات', group: 'admin' },
];

export const DEFAULT_EMPLOYEE_MODULE_PERMISSIONS = USER_PERMISSIONS
  .filter((perm) => perm.group === 'modules')
  .map((perm) => perm.id);

export const getPermissionLabel = (id) => {
  const perm = USER_PERMISSIONS.find((item) => item.id === id);
  return perm ? perm.name : id;
};
