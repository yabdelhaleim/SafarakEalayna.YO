import { createRouter, createWebHistory } from 'vue-router';
import { h, resolveComponent } from 'vue';
import { useAuthStore } from '@/stores/authStore';

const routes = [
  {
    path: '/',
    redirect: '/dashboard'
  },
  // Auth Pages (no auth required)
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { title: 'تسجيل الدخول', guest: true },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/auth/Register.vue'),
    meta: { title: 'إنشاء حساب', guest: true },
  },
  // Dashboard
  {
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'لوحة التحكم', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard.home',
        component: () => import('@/views/DashboardWrapper.vue'),
        meta: { title: 'لوحة التحكم' },
      },
    ],
  },
  // Customers Module
  {
    path: '/customers',
    name: 'customers.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'العملاء', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'customers.list',
        component: () => import('@/views/customers/CustomerIndex.vue'),
      },
    ],
  },
  // Flights (existing)
  {
    path: '/flights',
    name: 'flights.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'وحدة الطيران', requiresAuth: true },
    redirect: { name: 'flights.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'flights.dashboard',
        component: () => import('@/views/flights/FlightDashboard.vue'),
        meta: { title: 'داش بورد الطيران' },
      },
      {
        path: 'list',
        name: 'flights.list',
        component: () => import('@/views/flights/FlightIndex.vue'),
        meta: { title: 'حجوزات الطيران' },
      },
      {
        path: 'create',
        name: 'flights.create',
        component: () => import('@/views/flights/FlightCreate.vue'),
      },
      {
        path: 'treasury',
        name: 'flights.treasury',
        component: () => import('@/views/flights/FlightTreasuryOverview.vue'),
        meta: { title: 'خزينة وأرصدة الطيران', permission: 'manage_finance' },
      },
      {
        path: ':id',
        name: 'flights.show',
        component: () => import('@/views/flights/FlightShow.vue'),
        props: true,
      },
      {
        path: ':id/edit',
        name: 'flights.edit',
        component: () => import('@/views/flights/FlightEdit.vue'),
        props: true,
      },
      {
        path: 'airline-accounts/:id/transactions',
        name: 'flights.airline-transactions',
        component: () => import('@/views/flights/FlightAirlineTransactions.vue'),
        props: true,
        meta: { title: 'معاملات حساب شركة الطيران' },
      },
    ],
  },
  // Hajj & Umra Module
  {
    path: '/hajj-umra',
    name: 'hajj.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'الحج والعمرة', requiresAuth: true },
    redirect: { name: 'hajj.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'hajj.dashboard',
        component: () => import('@/views/hajjUmra/HajjUmraDashboard.vue'),
        meta: { title: 'داش بورد الحج والعمرة' },
      },
      {
        path: 'list',
        name: 'hajj.list',
        component: () => import('@/views/hajjUmra/HajjUmraIndex.vue'),
        meta: { title: 'حجوزات الحج والعمرة' },
      },
      {
        path: 'create',
        name: 'hajj.create',
        component: () => import('@/views/hajjUmra/HajjUmraCreate.vue'),
        meta: { title: 'إنشاء حجز حج/عمرة' },
      },
      {
        path: 'treasury',
        name: 'hajj.treasury',
        component: () => import('@/views/hajjUmra/HajjUmraTreasury.vue'),
        meta: { title: 'مالية وخزنة الحج والعمرة', permission: 'manage_finance' },
      },
      {
        path: 'executing-companies',
        name: 'hajj.executing-companies',
        component: () => import('@/views/hajjUmra/HajjUmraExecutingCompaniesDue.vue'),
        meta: { title: 'الشركات المنفذة (سحب/سداد)' },
      },
      {
        path: ':id',
        name: 'hajj.show',
        component: () => import('@/views/hajjUmra/HajjUmraShow.vue'),
        props: true,
      },
      {
        path: ':id/edit',
        name: 'hajj.edit',
        component: () => import('@/views/hajjUmra/HajjUmraEdit.vue'),
        props: true,
      },
      {
        path: 'programs',
        name: 'hajj.programs',
        component: { render: () => h(resolveComponent('router-view')) },
        meta: { title: 'برامج الحج والعمرة', requiresAuth: true },
        children: [
          {
            path: '',
            name: 'hajj.programs.list',
            component: () => import('@/views/hajjUmra/Programs/ProgramIndex.vue'),
          },
          {
            path: 'create',
            name: 'hajj.programs.create',
            component: () => import('@/views/hajjUmra/Programs/ProgramCreate.vue'),
          },
          {
            path: ':id/edit',
            name: 'hajj.programs.edit',
            component: () => import('@/views/hajjUmra/Programs/ProgramEdit.vue'),
            props: true,
          },
        ],
      },
    ],
  },
  {
    path: '/visas',
    name: 'visa.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'التأشيرات', requiresAuth: true },
    redirect: { name: 'visa.list' },
    children: [
      {
        path: 'list',
        name: 'visa.list',
        component: () => import('@/views/visa/VisaIndex.vue'),
        meta: { title: 'قائمة التأشيرات' }
      },
      {
        path: 'treasury',
        name: 'visa.treasury',
        component: () => import('@/views/visa/VisaTreasury.vue'),
        meta: { title: 'خزينة التأشيرات', permission: 'manage_finance' }
      },
      {
        path: 'agents-finance',
        name: 'visa.agents-finance',
        component: () => import('@/views/visa/VisaAgentsFinance.vue'),
        meta: { title: 'مالية وكلاء التأشيرات' }
      },
      {
        path: 'create',
        name: 'visa.create',
        component: () => import('@/views/visa/VisaCreate.vue'),
        meta: { title: 'طلب جديد' }
      },
      {
        path: ':id',
        name: 'visa.show',
        component: () => import('@/views/visa/VisaShow.vue'),
        props: true,
      },
      {
        path: ':id/edit',
        name: 'visa.edit',
        component: () => import('@/views/visa/VisaEdit.vue'),
        props: true,
      },
    ],
  },
  // Bus Module
  {
    path: '/bus',
    name: 'bus.module',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'وحدة الباص', requiresAuth: true },
    redirect: { name: 'bus.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'bus.dashboard',
        component: () => import('@/views/bus/BusDashboard.vue'),
        meta: { title: 'داش بورد الباص' },
      },
      {
        path: 'treasury',
        name: 'bus.treasury',
        component: () => import('@/views/bus/BusTreasury.vue'),
        meta: { title: 'خزينة ومالية الباص', permission: 'manage_finance' },
      },
      {
        path: 'list',
        name: 'bus.list',
        component: () => import('@/views/bus/BusIndex.vue'),
        meta: { title: 'حجوزات الباصات' },
      },
      {
        path: 'create',
        name: 'bus.create',
        component: () => import('@/views/bus/BusCreate.vue'),
        meta: { title: 'إنشاء حجز باص' },
      },

      {
        path: 'companies',
        name: 'bus.companies',
        component: () => import('@/views/bus/BusCompanyIndex.vue'),
        meta: { title: 'شركات الباصات' },
      },
      {
        path: 'companies/:id/statement',
        name: 'bus.companies.statement',
        component: () => import('@/views/bus/BusCompanyStatement.vue'),
        meta: { title: 'كشف حساب شركة الباص' },
        props: true,
      },
      {
        path: 'inventory',
        name: 'bus.inventory',
        component: () => import('@/views/bus/BusInventoryIndex.vue'),
        meta: { title: 'رحلات وأسعار الباص' },
      },
      {
        path: ':id',
        name: 'bus.show',
        component: () => import('@/views/bus/BusShow.vue'),
        props: true,
      },
    ],
  },
  // Wallet & Transfers Module
  {
    path: '/wallet',
    name: 'wallet.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'المحافظ والتحويلات', requiresAuth: true },
    redirect: { name: 'wallet.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'wallet.dashboard',
        component: () => import('@/views/wallet/TransferDashboard.vue'),
        meta: { title: 'داش بورد المحافظ' },
      },
      {
        path: 'list',
        name: 'wallet.list',
        component: () => import('@/views/wallet/WalletIndex.vue'),
        meta: { title: 'قائمة العمليات' },
      },
      {
        path: 'treasury',
        name: 'wallet.treasury',
        component: () => import('@/views/wallet/TransferTreasury.vue'),
        meta: { title: 'خزينة المحافظ', permission: 'manage_finance' },
      },
      {
        path: 'create',
        name: 'wallet.create',
        component: () => import('@/views/wallet/WalletCreate.vue'),
        meta: { title: 'عملية جديدة' },
      },
    ],
  },
  // Online Module
  {
    path: '/online',
    name: 'online.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'الخدمات Online', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'online.list',
        component: () => import('@/views/online/OnlineIndex.vue'),
      },
      {
        path: 'treasury',
        name: 'online.treasury',
        component: () => import('@/views/online/OnlineTreasury.vue'),
        meta: { title: 'خزنة ومالية الخدمات الإلكترونية', permission: 'manage_finance' },
      },
      {
        path: 'execute',
        name: 'online.execute',
        component: () => import('@/views/online/OnlineExecute.vue'),
      },
    ],
  },
  // Online Service Types
  {
    path: '/online/service-types',
    name: 'online.service-types',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'أنواع الخدمات الأونلاين', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'online.service-types.list',
        component: () => import('@/views/online/OnlineServiceTypesIndex.vue'),
      },
    ],
  },
  // Online Service Providers
  {
    path: '/online/providers',
    name: 'online.providers',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'مزودو الخدمات الأونلاين', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'online.providers.list',
        component: () => import('@/views/online/OnlineProvidersIndex.vue'),
      },
    ],
  },
  // Fawry Module
  {
    path: '/fawry',
    name: 'fawry.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'معاملات فوري', requiresAuth: true },
    redirect: { name: 'fawry.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'fawry.dashboard',
        component: () => import('@/views/fawry/FawryDashboard.vue'),
        meta: { title: 'داش بورد فوري' },
      },
      {
        path: 'list',
        name: 'fawry.list',
        component: () => import('@/views/fawry/FawryIndex.vue'),
      },
      {
        path: 'treasury',
        name: 'fawry.treasury',
        component: () => import('@/views/fawry/FawryTreasury.vue'),
        meta: { title: 'خزينة وأرصدة فوري', permission: 'manage_finance' },
      },
      {
        path: 'create',
        name: 'fawry.create',
        component: () => import('@/views/fawry/FawryCreate.vue'),
        meta: { title: 'معاملة فوري جديدة' },
      },
      {
        path: ':id',
        name: 'fawry.show',
        component: () => import('@/views/fawry/FawryShow.vue'),
        props: true,
      },
      {
        path: ':id/edit',
        name: 'fawry.edit',
        component: () => import('@/views/fawry/FawryEdit.vue'),
        props: true,
      },
    ],
  },

  // Treasury



  // Employees Module
  {
    path: '/employees',
    name: 'employees.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'شؤون الموظفين', requiresAuth: true, permission: 'manage_employees' },
    redirect: { name: 'employees.list' },
    children: [
      {
        path: 'list',
        name: 'employees.list',
        component: () => import('@/views/employees/EmployeeIndex.vue'),
        meta: { title: 'قائمة الموظفين' },
      },
      {
        path: 'attendance',
        name: 'employees.attendance',
        component: () => import('@/views/employees/AttendanceIndex.vue'),
        meta: { title: 'سجل الحضور والغياب' },
      },
      {
        path: 'create',
        name: 'employees.create',
        component: () => import('@/views/employees/EmployeeCreate.vue'),
        meta: { title: 'إضافة موظف جديد' },
      },
      {
        path: ':id',
        name: 'employees.show',
        component: () => import('@/views/employees/EmployeeShow.vue'),
        props: true,
      },
    ],
  },

  // Finance Module
  {
    path: '/finance',
    name: 'finance.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'المالية والحسابات', requiresAuth: true, permission: 'manage_finance' },
    redirect: { name: 'finance.dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'finance.dashboard',
        component: () => import('@/views/finance/FinanceDashboard.vue'),
        meta: { title: 'لوحة التحكم المالية' },
      },
      {
        path: 'accounts',
        name: 'finance.accounts',
        component: () => import('@/views/finance/AccountsIndex.vue'),
        meta: { title: 'كشوف الحسابات' },
      },
      {
        path: 'treasury',
        name: 'finance.treasury',
        component: () => import('@/views/finance/TreasuryOverview.vue'),
        meta: { title: 'خزينة المكتب' },
      },
      {
        path: 'expenses',
        name: 'finance.expenses',
        component: () => import('@/views/finance/ExpensesIndex.vue'),
        meta: { title: 'المصروفات العامة' },
      },
      {
        path: 'transactions',
        name: 'finance.transactions',
        component: () => import('@/views/finance/TransactionsIndex.vue'),
        meta: { title: 'سجل المعاملات' },
      },
      {
        path: 'transfers',
        name: 'finance.transfers',
        component: () => import('@/views/finance/TransfersIndex.vue'),
        meta: { title: 'التحويلات المالية' },
      },
    ],
  },

  // Suppliers Module
  {
    path: '/suppliers',
    name: 'suppliers.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'الموردين', requiresAuth: true },
    children: [
      {
        path: '',
        name: 'suppliers.list',
        component: () => import('@/views/finance/SuppliersIndex.vue'),
      },
    ],
  },

  // Reports Module
  {
    path: '/reports',
    name: 'reports.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'التقارير الشاملة', requiresAuth: true, permission: 'view_reports' },
    children: [
      {
        path: '',
        name: 'reports.list',
        component: () => import('@/views/reports/ReportsIndex.vue'),
      },
    ],
  },

  // Users Module
  {
    path: '/users',
    name: 'users.index',
    component: () => import('@/layouts/DashboardLayout.vue'),
    meta: { title: 'إدارة المستخدمين والصلاحيات', requiresAuth: true, permission: 'manage_users' },
    children: [
      {
        path: '',
        name: 'users.list',
        component: () => import('@/views/users/UsersIndex.vue'),
      },
    ],
  },

  // Catch-all 404 route
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFound.vue'),
    meta: { title: 'الصفحة غير موجودة' }
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to, from) => {
  const authStore = useAuthStore();
  const initialToken = localStorage.getItem('auth_token');

  // Ensure auth is initialized to check permissions correctly
  if (initialToken && !authStore.user) {
    await authStore.initAuth();
  }

  // Get the fresh, updated token status from store (handles 401 logouts during initAuth)
  const token = authStore.token;

  // 1. Check if route requires auth
  if (to.matched.some(record => record.meta.requiresAuth)) {
    if (!token) {
      return { name: 'login', query: { redirect: to.fullPath } };
    }
  }

  // 2. Check Permissions
  const requiredPermission = to.meta.permission || to.matched.find(r => r.meta.permission)?.meta.permission;
  if (requiredPermission) {
    const hasPerm = authStore.isAdmin || 
                    authStore.user?.role === 'owner' || 
                    (authStore.user?.permissions && authStore.user.permissions.includes(requiredPermission));
    
    if (!hasPerm) {
      // Redirect to dashboard if not authorized
      return { name: 'dashboard.home' };
    }
  }

  // If route is for guests only (login/register) and user has token → redirect to dashboard
  if (to.meta.guest && token) {
    return { name: 'dashboard.home' };
  }

  // Update page title
  if (to.meta.title) {
    document.title = `${to.meta.title} | سفارك إلينا`;
  }

  // Return true to continue navigation
  return true;
});

export default router;
