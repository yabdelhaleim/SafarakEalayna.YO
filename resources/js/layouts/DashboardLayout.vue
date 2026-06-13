<template>
  <div class="app-shell" :class="{ 'sidebar-collapsed': !isSidebarOpen }">

    <!-- MOBILE BACKDROP -->
    <transition name="t-backdrop">
      <div
        v-if="isSidebarOpen && isMobile"
        class="backdrop"
        @click="isSidebarOpen = false"
        aria-hidden="true"
      />
    </transition>

    <!-- ═══════════════════════════════════
         SIDEBAR
         Desktop → static flex child (right side in RTL)
         Mobile  → fixed overlay sliding from right
    ══════════════════════════════════════ -->
    <aside class="sidebar" :class="{ 'sidebar--open': isSidebarOpen }">

      <!-- Logo -->
      <div class="sb-header">
        <div class="logo-wrap">
          <div class="logo-icon">
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="32" height="32" rx="8" fill="url(#lg)"/>
              <path d="M9 22L16 10l7 12H9z" fill="white" opacity=".9"/>
              <path d="M9 22h14" stroke="white" stroke-width="1.5" opacity=".45"/>
              <defs>
                <linearGradient id="lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                  <stop stop-color="#3B82F6"/>
                  <stop offset="1" stop-color="#06B6D4"/>
                </linearGradient>
              </defs>
            </svg>
          </div>
          <div class="logo-text">
            <span class="logo-name">سفرك علينا</span>
            <span class="logo-tag">إدارة السفر والسياحة</span>
          </div>
        </div>
        <button v-if="isMobile" class="sb-x-btn" @click="closeSidebar" aria-label="إغلاق">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M15 5L5 15M5 5l10 10"/>
          </svg>
        </button>
      </div>

      <!-- Profile -->
      <div class="sb-profile">
        <div class="p-avatar">
          <span>{{ authStore.userInitial }}</span>
          <span class="p-status"></span>
        </div>
        <div class="p-info">
          <span class="p-name">{{ authStore.userName }}</span>
          <span class="p-role">{{ authStore.isAdmin ? 'مدير النظام' : 'موظف' }}</span>
        </div>
        <svg class="p-arrow" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75">
          <path d="M6 4l4 4-4 4"/>
        </svg>
      </div>

      <!-- Nav -->
      <nav class="sb-nav">

        <div class="nav-grp">
          <span class="grp-label">عام</span>
          <router-link to="/dashboard" class="nl" active-class="nl-active">
            <span class="nl-i"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="7" height="7" rx="1.5"/><rect x="11" y="2" width="7" height="7" rx="1.5"/><rect x="2" y="11" width="7" height="7" rx="1.5"/><rect x="11" y="11" width="7" height="7" rx="1.5"/></svg></span>
            <span class="nl-t">لوحة التحكم</span>
          </router-link>
          <a v-if="authStore.isAdmin" :href="'/admin?token=' + authStore.token" class="nl" target="_blank">
            <span class="nl-i"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8v2m2-2a2 2 0 100 4m0-4a2 2 0 110 4m-12 8v-2m4 0a2 2 0 100-4m0 4a2 2 0 110-4m12 2v-2M6 6a2 2 0 100 4m0-4a2 2 0 110 4m-6 4v-2m4 0a2 2 0 100-4m0 4a2 2 0 110-4m12 2v-2"/></svg></span>
            <span class="nl-t">لوحة التحكم الإدارية</span>
            <span class="nl-badge badge-red">إداري</span>
          </a>
        </div>

        <!-- TOURISM ACCOUNTS -->
        <div class="nav-grp" v-if="hasPermission('manage_flights') || hasPermission('manage_hajj') || hasPermission('manage_online')">
          <span class="grp-label grp-label--tourism text-success">حسابات السياحة</span>
          
          <!-- Flights Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_flights')">
            <button @click="isFlightsOpen = !isFlightsOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/flights')}">
              <span class="nl-i text-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13.5l-3-2.5H3.5L2.5 7l7.5.5 1-4 1.5 1-1 4 3.5.75L18 13.5z"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">وحدة الطيران</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isFlightsOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isFlightsOpen" class="dropdown-content-styled">
              <router-link to="/flights/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/flights/list" class="nl-sub">قائمة الحجوزات</router-link>
              <router-link to="/flights/customers" class="nl-sub">عملاء الطيران</router-link>
              <router-link to="/flights/passengers" class="nl-sub">دليل المسافرين</router-link>
              <router-link v-if="isAdminOrOwner" to="/flights/treasury" class="nl-sub">إدارة القسم (مالية وأرصدة)</router-link>
            </div>
          </div>

          <!-- Hajj & Umra Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_hajj')">
            <button @click="isHajjUmraOpen = !isHajjUmraOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/hajj-umra')}">
              <span class="nl-i text-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">الحج والعمرة</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isHajjUmraOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isHajjUmraOpen" class="dropdown-content-styled">
              <router-link to="/hajj-umra/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/hajj-umra/list" class="nl-sub">قائمة الحجوزات</router-link>
              <router-link to="/hajj-umra/create" class="nl-sub">إنشاء حجز</router-link>
              <router-link v-if="isAdminOrOwner" to="/hajj-umra/treasury" class="nl-sub">إدارة القسم (مالية الحج والعمرة)</router-link>
              <router-link v-if="isAdminOrOwner" to="/hajj-umra/programs" class="nl-sub">إدارة البرامج</router-link>
              <router-link v-if="isAdminOrOwner" to="/hajj-umra/executing-companies" class="nl-sub">إدارة الشركات</router-link>
              <router-link to="/hajj-umra/customer-balances" class="nl-sub">مديونيات العملاء</router-link>
            </div>
          </div>

          <!-- Visas Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_online')">
            <button @click="isVisasOpen = !isVisasOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/visas')}">
              <span class="nl-i text-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="10" cy="10" r="3"/><path d="M7 20h10"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">التأشيرات</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isVisasOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isVisasOpen" class="dropdown-content-styled">
              <router-link to="/visas/list" class="nl-sub">قائمة التأشيرات</router-link>
              <router-link to="/visas/create" class="nl-sub">طلب جديد</router-link>
              <router-link to="/visas/customer-balances" class="nl-sub">مديونيات العملاء</router-link>
              <router-link v-if="isAdminOrOwner" to="/visas/treasury" class="nl-sub">إدارة القسم (مالية التأشيرات)</router-link>
              <router-link v-if="isAdminOrOwner" to="/visas/agents-finance" class="nl-sub">إدارة الوكلاء</router-link>
            </div>
          </div>
        </div>

        <!-- OFFICE ACCOUNTS -->
        <div class="nav-grp" v-if="hasPermission('manage_bus') || hasPermission('manage_online') || hasPermission('manage_treasury')">
          <span class="grp-label grp-label--office text-purple-400">حسابات المكتب</span>

          <!-- Bus Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_bus')">
            <button @click="isBusOpen = !isBusOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/bus')}">
              <span class="nl-i text-purple-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20M6 20v2M18 20v2M6 14h2M16 14h2"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">وحدة الباص</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isBusOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isBusOpen" class="dropdown-content-styled">
              <router-link to="/bus/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/bus/create" class="nl-sub">إنشاء حجز</router-link>
              <router-link to="/bus" class="nl-sub">قائمة الحجوزات</router-link>
              <router-link to="/bus/customers" class="nl-sub">عملاء الباصات</router-link>
              <router-link v-if="isAdminOrOwner" to="/bus/treasury" class="nl-sub">إدارة القسم (مالية الباصات)</router-link>
              <router-link v-if="isAdminOrOwner" to="/bus/companies" class="nl-sub">إدارة الشركات</router-link>
              <router-link v-if="isAdminOrOwner" to="/bus/inventory" class="nl-sub">إدارة الرحلات</router-link>
            </div>
          </div>

          <!-- Fawry Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_treasury')">
            <button @click="isFawryOpen = !isFawryOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/fawry')}">
              <span class="nl-i text-purple-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M12 12h2M12 16h2M8 8h8"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">معاملات فوري</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isFawryOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isFawryOpen" class="dropdown-content-styled">
              <router-link to="/fawry/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/fawry/list" class="nl-sub">قائمة المعاملات</router-link>
              <router-link to="/fawry/customer-balances" class="nl-sub">مديونيات العملاء</router-link>
              <router-link to="/fawry/machines" class="nl-sub">إدارة الماكينات</router-link>
              <router-link v-if="isAdminOrOwner" to="/fawry/treasury" class="nl-sub">إدارة القسم (مالية فوري)</router-link>
            </div>
          </div>

          <!-- Online Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_online')">
            <button @click="isOnlineOpen = !isOnlineOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/online')}">
              <span class="nl-i text-purple-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">الخدمات الإلكترونية</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isOnlineOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isOnlineOpen" class="dropdown-content-styled">
              <router-link to="/online" class="nl-sub">قائمة المعاملات</router-link>
              <router-link to="/online/customer-balances" class="nl-sub">مديونيات العملاء</router-link>
              <router-link v-if="isAdminOrOwner" to="/online/treasury" class="nl-sub">إدارة القسم (مالية الخدمات)</router-link>
              <router-link v-if="isAdminOrOwner" to="/online/service-types" class="nl-sub">أنواع الخدمات</router-link>
              <router-link v-if="isAdminOrOwner" to="/online/providers" class="nl-sub">مزودي الخدمات</router-link>
            </div>
          </div>

          <!-- Wallet Module -->
          <div class="nav-dropdown" v-if="(authStore.isAdmin || authStore.user?.role === 'owner') && hasPermission('manage_treasury')">
            <button @click="isWalletOpen = !isWalletOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/wallet')}">
              <span class="nl-i text-purple-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 00-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">المحافظ والتحويلات</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isWalletOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isWalletOpen" class="dropdown-content-styled">
              <router-link to="/wallet/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/wallet/list" class="nl-sub">قائمة العمليات</router-link>
              <router-link to="/wallet/customer-balances" class="nl-sub">مديونيات العملاء</router-link>
              <router-link v-if="isAdminOrOwner" to="/wallet/treasury" class="nl-sub">إدارة القسم (مالية المحافظ)</router-link>
            </div>
          </div>
        </div>

        <!-- CUSTOMERS MODULE -->
        <div class="nav-grp">
          <span class="grp-label text-amber-400">العملاء والمديونيات</span>

          <router-link to="/customers" class="nl" :class="{'nl-active': $route.path.startsWith('/customers')}">
            <span class="nl-i text-amber-400">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                <path d="M16 3.13a4 4 0 010 7.75"/>
              </svg>
            </span>
            <span class="nl-t">إدارة العملاء</span>
          </router-link>
        </div>

        <!-- ADMINISTRATION & FINANCE -->
        <div class="nav-grp" v-if="hasPermission('manage_finance') || hasPermission('manage_employees') || hasPermission('view_reports') || hasPermission('manage_users')">
          <span class="grp-label text-sky-400">الإدارة والمالية</span>


          <!-- Finance Module -->
          <div class="nav-dropdown" v-if="(authStore.isAdmin || authStore.user?.role === 'owner') && hasPermission('manage_finance')">
            <button @click="isFinanceOpen = !isFinanceOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/finance')}">
              <span class="nl-i text-sky-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">المالية والحسابات</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isFinanceOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isFinanceOpen" class="dropdown-content-styled">
              <router-link to="/finance/dashboard" class="nl-sub">لوحة التحكم</router-link>
              <router-link to="/finance/accounts" class="nl-sub">كشوف الحسابات</router-link>
              <router-link to="/finance/account-statement" class="nl-sub">كشف حساب تفصيلي</router-link>
              <router-link to="/finance/treasury" class="nl-sub">الخزينة</router-link>
              <router-link to="/finance/expenses" class="nl-sub">المصروفات</router-link>
              <router-link to="/finance/transactions" class="nl-sub">سجل المعاملات</router-link>
              <router-link to="/finance/transactions/create" class="nl-sub">معاملة جديدة</router-link>
              <router-link to="/finance/transfers" class="nl-sub">التحويلات المالية</router-link>
              <router-link to="/finance/profit-loss" class="nl-sub">الأرباح والخسائر</router-link>
              <router-link to="/finance/department/tourism" class="nl-sub">المركز المالي - السياحة</router-link>
              <router-link to="/finance/department/office" class="nl-sub">المركز المالي - المكتب</router-link>
              <router-link to="/finance/operations/ledger" class="nl-sub">شحن الأنظمة والتحويلات</router-link>
              <router-link to="/finance/operations/tourism" class="nl-sub">دفتر القيود - السياحة</router-link>
              <router-link to="/finance/operations/office" class="nl-sub">دفتر القيود - المكتب</router-link>
              <router-link to="/suppliers" class="nl-sub">الموردين</router-link>
            </div>
          </div>

          <!-- Employees Module -->
          <div class="nav-dropdown" v-if="hasPermission('manage_employees')">
            <button @click="isEmployeesOpen = !isEmployeesOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/employees')}">
              <span class="nl-i text-sky-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">شؤون الموظفين</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isEmployeesOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isEmployeesOpen" class="dropdown-content-styled">
              <router-link to="/employees/list" class="nl-sub">قائمة الموظفين</router-link>
              <router-link to="/employees/attendance" class="nl-sub">الحضور والغياب</router-link>
            </div>
          </div>

          <!-- Reports Module -->
          <div class="nav-dropdown" v-if="hasPermission('view_reports')">
            <button @click="isReportsOpen = !isReportsOpen" class="nl" :class="{'nl-active': $route.path.startsWith('/reports')}">
              <span class="nl-i text-sky-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 118 2.83M22 12A10 10 0 0012 2v10z"/></svg></span>
              <span class="nl-t" style="flex: 1; text-align: right;">التقارير الشاملة</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="{ transform: isReportsOpen ? 'rotate(180deg)' : 'rotate(0deg)' }" style="width: 1rem; height: 1rem; transition: transform 0.2s; opacity: 0.5;"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div v-show="isReportsOpen" class="dropdown-content-styled">
              <router-link to="/reports" class="nl-sub">مركز التقارير</router-link>
              <router-link to="/reports/debts" class="nl-sub">الديون والمديونيات</router-link>
              <router-link to="/reports/flights-detailed" class="nl-sub">تقرير حركات الطيران</router-link>
            </div>
          </div>

          <!-- Users Link -->
          <router-link v-if="hasPermission('manage_users')" to="/users" class="nl" active-class="nl-active">
            <span class="nl-i text-sky-400"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>
            <span class="nl-t">إدارة المستخدمين</span>
          </router-link>
        </div>


      </nav>

      <!-- Footer -->
      <div class="sb-footer">
        <router-link to="/settings/print" class="sf-btn">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="10" cy="10" r="2.5"/><path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.22 4.22l1.42 1.42M14.36 14.36l1.42 1.42M4.22 15.78l1.42-1.42M14.36 5.64l1.42-1.42"/></svg><span>الإعدادات</span>
        </router-link>
        <button class="sf-btn sf-logout" @click="handleLogout"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M7 17H4a1.5 1.5 0 01-1.5-1.5v-11A1.5 1.5 0 014 3h3M13 14l4-4-4-4M17 10H7"/></svg><span>خروج</span></button>
      </div>

    </aside>

    <!-- ═══════════════════════════════════
         MAIN ZONE
    ══════════════════════════════════════ -->
    <div class="main-zone">

      <!-- Header -->
      <header class="top-bar">

        <div class="tb-start">
          <button class="hbg" :class="{ 'hbg--x': isSidebarOpen }" @click="toggleSidebar" :aria-expanded="isSidebarOpen" aria-label="القائمة">
            <span></span><span></span><span></span>
          </button>
          <div class="breadcrumb">
            <span class="bc-home">سفرك علينا</span>
            <span class="bc-div">/</span>
            <span class="bc-cur">{{ currentPageTitle }}</span>
          </div>
        </div>

        <div class="tb-end gap-2 sm:gap-4">
          <div class="hdr-search">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="9" cy="9" r="6.5"/><path d="M17 17l-3.5-3.5"/></svg>
            <input id="global-quick-search" name="global_quick_search" type="search" placeholder="بحث سريع..." aria-label="بحث" autocomplete="off" />
            <kbd>K</kbd>
          </div>

          <button class="hdr-btn" aria-label="تحديث">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M17 3v5h-5M3 17v-5h5"/><path d="M15.66 7.5A7 7 0 104.34 14.5"/></svg>
          </button>

          <div class="relative">
            <button class="hdr-btn hdr-notif" type="button" aria-label="الإشعارات" @click.stop="toggleNotifDropdown">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M15 8A5 5 0 005 8c0 5.5-2.5 7-2.5 7h15S15 13.5 15 8z"/><path d="M11.45 17.5a1.667 1.667 0 01-2.9 0"/></svg>
              <span v-if="unreadCount > 0" class="notif-badge-count">{{ unreadCount }}</span>
            </button>

            <!-- Notifications Dropdown -->
            <transition name="t-dropdown">
              <div v-if="isNotifDropdownOpen" class="notif-dropdown glass shadow-2xl rounded-2xl border border-slate-700/50" @click.stop>
                <div class="notif-header">
                  <h3>التنبيهات</h3>
                  <button v-if="unreadCount > 0" class="mark-all-read-btn" @click="markAllAsRead">
                    تحديد الكل كمقروء
                  </button>
                </div>
                <div class="notif-list">
                  <div v-if="notifications.length === 0" class="notif-empty">
                    لا توجد تنبيهات جديدة
                  </div>
                  <div v-for="notif in notifications" :key="notif.id" class="notif-item" :class="{ 'notif-item--unread': !notif.read_at }" @click="handleNotifClick(notif)">
                    <div class="notif-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 1rem; height: 1rem;"><path d="M18 13.5l-3-2.5H3.5L2.5 7l7.5.5 1-4 1.5 1-1 4 3.5.75L18 13.5z"/></svg>
                    </div>
                    <div class="notif-content">
                      <p class="notif-message">{{ notif.data.message }}</p>
                      <div class="notif-meta">
                        <span>تاريخ السفر: {{ notif.data.departure_date }} {{ notif.data.departure_time }}</span>
                        <span class="notif-pnr" v-if="notif.data.pnr">PNR: {{ notif.data.pnr }}</span>
                      </div>
                    </div>
                    <button v-if="!notif.read_at" class="mark-read-btn" title="تحديد كمقروء" @click.stop="markAsRead(notif.id)">
                      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" style="width: 0.85rem; height: 0.85rem;"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
                    </button>
                  </div>
                </div>
                <div class="notif-footer">
                  <router-link to="/flights/passengers" @click="isNotifDropdownOpen = false" class="view-all-link">عرض جميع المسافرين</router-link>
                </div>
              </div>
            </transition>
          </div>

          <button class="hdr-avatar" type="button" aria-label="الملف الشخصي">{{ authStore.userInitial }}</button>
        </div>

      </header>

      <!-- Page -->
      <main class="page-body">
        <div v-if="error" class="vue-error-boundary glass rounded-3xl p-12 text-center max-w-lg mx-auto my-12 shadow-2xl shadow-red-500/10 border border-red-500/20">
            <div class="w-20 h-20 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-10 h-10">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
            <h3 class="text-2xl font-bold text-slate-100 mb-4">عذراً، حدث خطأ غير متوقع</h3>
            <p class="text-slate-400 mb-8 leading-relaxed">واجهنا صعوبة في عرض هذه الصفحة حالياً. يمكنك محاولة تحديث الصفحة أو العودة للرئيسية.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <button @click="reloadPage" class="px-8 py-3 bg-primary-600 hover:bg-primary-700 text-white font-bold rounded-xl transition-all">تحديث الصفحة</button>
                <router-link to="/dashboard" @click="error = null" class="px-8 py-3 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl transition-all border border-slate-700">الرئيسية</router-link>
            </div>
        </div>
        <router-view v-else v-slot="{ Component }">
          <transition name="t-page">
            <keep-alive v-if="!route.meta.noKeepAlive" :max="20" exclude="EmployeesAttendance">
              <component
                v-if="Component"
                :is="Component"
                :key="route.fullPath"
              />
            </keep-alive>
            <component
              v-else-if="Component"
              :is="Component"
              :key="route.fullPath"
            />
          </transition>
        </router-view>
      </main>

    </div>

    <!-- TOASTS -->
    <div class="toast-rack" aria-live="polite">
      <transition-group name="t-toast">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="`toast--${t.type}`" role="alert">
          <div class="t-bar"></div>
          <div class="t-icon">
            <svg v-if="t.type==='success'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 5L8 13l-4-4"/></svg>
            <svg v-else-if="t.type==='warning'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 7v4M10 13.5h.01M8.27 3.5l-6.54 11A1.5 1.5 0 003 17h14a1.5 1.5 0 001.27-2.5l-6.54-11a1.5 1.5 0 00-2.46 0z"/></svg>
            <svg v-else viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="10" cy="10" r="8"/><path d="M13 7l-6 6M7 7l6 6"/></svg>
          </div>
          <div class="t-body">
            <p class="t-msg">{{ t.message }}</p>
            <p v-if="t.description" class="t-sub">{{ t.description }}</p>
          </div>
          <button class="t-close" @click="removeToast(t.id)" aria-label="إغلاق"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4L4 12M4 4l8 8"/></svg></button>
        </div>
      </transition-group>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import axios from 'axios';
import { useFlightStore } from '@/stores/flightStore';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const error = ref(null);
import { onErrorCaptured } from 'vue';
onErrorCaptured((err, instance, info) => {
  console.error('[DashboardLayout] Render error:', err, info);
  error.value = err;
  return false;
});

const hasPermission = (perm) => {
  if (authStore.isAdmin || authStore.user?.role === 'owner') return true;
  return Array.isArray(authStore.user?.permissions) && authStore.user.permissions.includes(perm);
};

const currentPageTitle = computed(() => {
  const t = route.meta?.title;
  if (typeof t === 'string' && t.trim()) return t;
  const name = route.name;
  if (typeof name === 'string' && name) return String(name).replace(/[._]/g, ' ');
  return 'لوحة التحكم';
});
const flightStore = useFlightStore();
const printSettingsStore = usePrintSettingsStore();

const DESKTOP = 1024;
const windowWidth   = ref(window.innerWidth);
const isMobile      = computed(() => windowWidth.value < DESKTOP);
const isSidebarOpen = ref(window.innerWidth >= DESKTOP);
const isTourismOpen = ref(route.path.startsWith('/flights') || route.path.startsWith('/hajj-umra') || route.path.startsWith('/visas'));
const isOfficeSubOpen = ref(route.path.startsWith('/bus') || route.path.startsWith('/fawry') || route.path.startsWith('/online'));

const isFlightsOpen = ref(route.path.startsWith('/flights'));
const isHajjUmraOpen = ref(route.path.startsWith('/hajj-umra'));
const isVisasOpen = ref(route.path.startsWith('/visas'));
const isBusOpen = ref(route.path.startsWith('/bus'));
const isFawryOpen = ref(route.path.startsWith('/fawry'));
const isOnlineOpen = ref(route.path.startsWith('/online'));
const isWalletOpen = ref(route.path.startsWith('/wallet'));
const isFinanceOpen = ref(route.path.startsWith('/finance'));
const isEmployeesOpen = ref(route.path.startsWith('/employees'));
const isReportsOpen = ref(route.path.startsWith('/reports'));
const isAdminOrOwner = computed(() => authStore.isAdmin || authStore.user?.role === 'owner');

function onResize() {
  const oldMobile = isMobile.value;
  windowWidth.value = window.innerWidth;
  const newMobile = isMobile.value;

  // If transitioning from mobile to desktop, ensure sidebar is open
  if (oldMobile && !newMobile) {
    isSidebarOpen.value = true;
  }
  // If transitioning from desktop to mobile, ensure sidebar is closed
  else if (!oldMobile && newMobile) {
    isSidebarOpen.value = false;
  }
}
const toggleSidebar = () => { isSidebarOpen.value = !isSidebarOpen.value; };
const closeSidebar  = () => { isSidebarOpen.value = false; };

// Use flightStore toasts as the main toast system
const toasts = ref([]);

// Watch flightStore toasts and sync with local toasts
watch(() => flightStore.toasts, (newToasts) => {
  toasts.value = [...newToasts];
}, { deep: true });

// Auto-close sidebar on route change (Mobile only)
watch(() => route.path, () => {
  if (isMobile.value) {
    isSidebarOpen.value = false;
  }
});

// Prevent body scroll when sidebar is open on mobile
watch(isSidebarOpen, (isOpen) => {
  if (isMobile.value) {
    document.body.style.overflow = isOpen ? 'hidden' : '';
  } else {
    document.body.style.overflow = '';
  }
}, { immediate: true });

function addToast(message, type = 'success', description = null) {
  flightStore.addToast(message, type);
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id);
  if (index !== -1) {
    toasts.value.splice(index, 1);
  }
}

// Also expose to window for backward compatibility
window.addToast = addToast;

async function handleLogout() {
  await authStore.logout();
  router.push('/login');
}

function reloadPage() {
  error.value = null;
  window.location.reload();
}

const handleEsc = (e) => {
  if (e.key === 'Escape' && isSidebarOpen.value && isMobile.value) {
    isSidebarOpen.value = false;
  }
};

// Notifications state
const notifications = ref([]);
const unreadCount = ref(0);
const isNotifDropdownOpen = ref(false);

async function fetchNotifications() {
  if (!authStore.token) return;
  try {
    const response = await axios.get('/api/v1/flight/passengers/notifications?type=unread');
    notifications.value = response.data.data.items || [];
    unreadCount.value = response.data.data.pagination?.total || 0;
  } catch (e) {
    console.error('Failed to fetch notifications', e);
  }
}

function toggleNotifDropdown() {
  isNotifDropdownOpen.value = !isNotifDropdownOpen.value;
  if (isNotifDropdownOpen.value) {
    fetchNotifications();
  }
}

async function markAsRead(id) {
  try {
    await axios.post(`/api/v1/flight/passengers/notifications/${id}/mark-read`);
    notifications.value = notifications.value.filter(n => n.id !== id);
    unreadCount.value = Math.max(0, unreadCount.value - 1);
    addToast('تم تحديد التنبيه كمقروء', 'success');
  } catch (e) {
    console.error(e);
    addToast('فشل في تحديث حالة التنبيه', 'error');
  }
}

async function markAllAsRead() {
  try {
    await axios.post('/api/v1/flight/passengers/notifications/mark-all-read');
    notifications.value = [];
    unreadCount.value = 0;
    addToast('تم تحديد جميع التنبيهات كمقروءة', 'success');
  } catch (e) {
    console.error(e);
    addToast('فشل في تحديث حالة التنبيهات', 'error');
  }
}

function handleNotifClick(notif) {
  if (!notif.read_at) {
    markAsRead(notif.id);
  }
  isNotifDropdownOpen.value = false;
  router.push('/flights/passengers');
}

const closeDropdowns = () => {
  isNotifDropdownOpen.value = false;
};

watch(() => authStore.token, (val) => {
  if (val) {
    fetchNotifications();
  } else {
    notifications.value = [];
    unreadCount.value = 0;
  }
});

onMounted(async () => {
  window.addEventListener('resize', onResize, { passive: true });
  window.addEventListener('keydown', handleEsc);
  window.addEventListener('click', closeDropdowns);

  window.addEventListener('show-toast', ({ detail }) =>
    addToast(detail.message, detail.type, detail.description)
  );
  // Initialize auth - load user data if token exists
  await authStore.initAuth();
  if (authStore.isAuthenticated) {
    printSettingsStore.fetch().catch(() => {});
    fetchNotifications();
  }
});
onUnmounted(() => {
  window.removeEventListener('resize', onResize);
  window.removeEventListener('keydown', handleEsc);
  window.removeEventListener('click', closeDropdowns);
});
</script>

<style>
/* Font is imported once in app.css to avoid duplication and multiple network requests */

:root {
  --sb-w:   270px;
  --hdr-h:  60px;

  --bg:      #080D18;
  --surf:    #0E1525;
  --raise:   #141E30;
  --hover:   #1A2640;

  --blue:    #3B82F6;
  --blue-lo: rgba(59,130,246,.10);
  --blue-md: rgba(59,130,246,.22);
  --cyan:    #06B6D4;
  --green:   #10B981;
  --amber:   #F59E0B;
  --red:     #EF4444;

  --t1: #F1F5F9;
  --t2: #8EA5C2;
  --t3: #3E5474;

  --b:    rgba(255,255,255,.07);
  --b-hi: rgba(59,130,246,.35);

  --r: 10px;
  --ease: cubic-bezier(.4,0,.2,1);
}

/* Only box-sizing reset — DO NOT reset margin/padding globally, it kills Tailwind utilities */
*, *::before, *::after { box-sizing: border-box; }

html { direction: rtl; height: 100%; }

#app { height: 100%; }

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--raise); border-radius: 4px; }
</style>

<style scoped>

/* ══ SHELL ══════════════════════════════ */
.app-shell {
  display: flex;           /* RTL: children flow right → left naturally */
  height: 100vh;
  overflow: hidden;
  background: var(--bg);
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  font-size: 14px;
  line-height: 1.5;
  color: var(--t1);
  -webkit-font-smoothing: antialiased;
}

.app-shell a { text-decoration: none; color: inherit; }
.app-shell button { font-family: inherit; cursor: pointer; border: none; background: none; }

/* ══ BACKDROP ═══════════════════════════ */
.backdrop {
  position: fixed; inset: 0;
  z-index: 90;
  background: rgba(8,13,24,.82);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}
.t-backdrop-enter-active,
.t-backdrop-leave-active { transition: opacity .28s var(--ease); }
.t-backdrop-enter-from,
.t-backdrop-leave-to     { opacity: 0; }

/* ══ SIDEBAR ════════════════════════════ */
/*
  DESKTOP:
  - Sidebar is a natural flex child → sits on the RIGHT (RTL start)
  - width transition collapses it smoothly

  MOBILE:
  - Sidebar is `position: fixed; right: 0`
  - Slides out with translateX(100%) → goes off right edge (correct for RTL)
  - Slides in with translateX(0)
*/
.sidebar {
  width: var(--sb-w);
  flex-shrink: 0;
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--surf);
  border-left: 1px solid var(--b);   /* inner edge = left in RTL layout */
  background-image: radial-gradient(ellipse 90% 35% at 50% 0%, rgba(59,130,246,.06), transparent);
  position: relative;
  z-index: 100;
  /* Desktop collapse animation */
  transition: width .32s var(--ease), opacity .3s;
}

/* Desktop collapsed */
@media (min-width: 1024px) {
  .app-shell.sidebar-collapsed .sidebar {
    width: 0;
    opacity: 0;
    overflow: hidden;
    border: none;
  }
}

/* Mobile: overlay */
@media (max-width: 1023px) {
  .sidebar {
    position: fixed;
    top: 0; bottom: 0; right: 0;
    width: var(--sb-w);
    transform: translateX(105%);   /* hidden: off right edge */
    transition: transform .32s var(--ease), box-shadow .32s;
    box-shadow: none;
    overflow-y: auto;
  }
  .sidebar--open {
    transform: translateX(0);
    box-shadow: -24px 0 64px rgba(0,0,0,.55);
  }
}

/* ─ SB Header ─ */
.sb-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 16px;
  border-bottom: 1px solid var(--b);
  flex-shrink: 0;
}
.logo-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; }
.logo-icon { width: 36px; height: 36px; flex-shrink: 0; }
.logo-icon svg { width: 100%; height: 100%; display: block; }
.logo-text { min-width: 0; }
.logo-name { display: block; font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.logo-tag  { display: block; font-size: 10px; color: var(--t3); margin-top: 1px; }
.sb-x-btn  {
  width: 28px; height: 28px; border-radius: 7px;
  border: 1px solid var(--b); background: transparent; color: var(--t2);
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.sb-x-btn:hover { background: var(--hover); color: var(--t1); }
.sb-x-btn svg { width: 14px; height: 14px; }

/* ─ SB Profile ─ */
.sb-profile {
  display: flex; align-items: center; gap: 9px;
  margin: 10px 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--raise);
  border: 1px solid var(--b);
  cursor: pointer;
  flex-shrink: 0;
  transition: background .15s, border-color .15s;
}
.sb-profile:hover { background: var(--hover); border-color: var(--b-hi); }
.p-avatar {
  position: relative;
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--cyan));
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: white; flex-shrink: 0;
}
.p-status {
  position: absolute; bottom: 0; left: 0;
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--green); border: 2px solid var(--surf);
}
.p-info { flex: 1; min-width: 0; }
.p-name { display: block; font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.p-role { display: block; font-size: 11px; color: var(--t3); }
.p-arrow { width: 13px; height: 13px; color: var(--t3); flex-shrink: 0; }

/* ─ Nav ─ */
.sb-nav {
  flex: 1; overflow-y: auto;
  padding: 8px 12px 10px;
  display: flex; flex-direction: column; gap: 2px;
}
.nav-grp { margin-bottom: 8px; }
.grp-label {
  display: block;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--t3);
  padding: 10px 10px 5px;
}
.grp-sublabel {
  display: block;
  font-size: 10px;
  font-weight: 600;
  color: var(--t3);
  opacity: 0.85;
  padding: 0 10px 8px;
  letter-spacing: 0.02em;
}
.grp-sublabel--office {
  color: rgba(196, 181, 253, 0.75);
}
.grp-label--tourism {
  color: rgba(52, 211, 153, 0.95);
}
.grp-label--office {
  color: rgba(196, 181, 253, 0.95);
}
.grp-label--sub {
  font-size: 9.5px;
  opacity: 0.9;
  padding-top: 10px;
  text-transform: none;
  letter-spacing: 0.04em;
}
.nl {
  display: flex; align-items: center; gap: 7px;
  padding: 8px 10px;
  border-radius: 8px;
  color: var(--t2); font-size: 13px; font-weight: 500;
  transition: background .14s, color .14s;
  position: relative;
}
.nl:hover { background: var(--hover); color: var(--t1); }
.nl--active,
.nl-active {
  background: var(--blue-lo);
  color: var(--blue);
  border: 1px solid var(--blue-md);
  box-shadow: 0 0 12px rgba(59,130,246,.08);
}
.nl--active::after,
.nl-active::after {
  content: '';
  position: absolute; right: 0; top: 22%; bottom: 22%;
  width: 3px; border-radius: 3px 0 0 3px;
  background: var(--blue);
  box-shadow: 0 0 8px rgba(59,130,246,.5);
}
.nl-i { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.nl-i svg { width: 16px; height: 16px; }
.nl-t { flex: 1; }
.nl-num {
  font-size: 11px; color: var(--t3);
  background: var(--raise); border-radius: 99px; padding: 1px 6px;
}
.nl-badge {
  font-size: 10px; font-weight: 800;
  padding: 2px 7px; border-radius: 99px;
}
.badge-blue { background: var(--blue); color: white; }
.badge-red  { background: var(--red);  color: white; }
.nl-live {
  display: flex; align-items: center; gap: 4px;
  font-size: 10.5px; font-weight: 600; color: var(--green);
}
.live-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--green); box-shadow: 0 0 5px var(--green);
  animation: blink 2s infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.35} }

/* ─ SB Footer ─ */
.sb-footer {
  padding: 10px 12px; border-top: 1px solid var(--b);
  display: flex; gap: 6px; flex-shrink: 0;
}
.sf-btn {
  flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 9px 10px; border-radius: 8px; border: 1px solid var(--b);
  background: var(--raise); color: var(--t2);
  font-size: 12.5px; font-weight: 500;
  transition: background .15s, color .15s, border-color .15s;
}
.sf-btn:hover { background: var(--hover); color: var(--t1); border-color: var(--b-hi); }
.sf-btn svg { width: 15px; height: 15px; }
.sf-logout:hover { background: rgba(239,68,68,.1); color: var(--red); border-color: rgba(239,68,68,.3); }

/* ══ MAIN ZONE ══════════════════════════ */
.main-zone {
  flex: 1;            /* takes all remaining space to the LEFT in RTL */
  min-width: 0;
  height: 100vh;
  display: flex; flex-direction: column;
  overflow: hidden;
}

/* ══ TOP BAR ════════════════════════════ */
.top-bar {
  height: var(--hdr-h);
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 18px; gap: 12px;
  background: rgba(14,21,37,.88);
  backdrop-filter: blur(18px) saturate(160%);
  -webkit-backdrop-filter: blur(18px) saturate(160%);
  border-bottom: 1px solid var(--b);
  box-shadow: 0 1px 0 rgba(59,130,246,.04), 0 4px 24px rgba(0,0,0,.3);
  position: relative; z-index: 50;
}

.tb-start { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.tb-end   { display: flex; align-items: center; gap: 10px; flex: 1; justify-content: flex-end; }

/* Hamburger */
.hbg {
  width: 36px; height: 36px;
  border-radius: 8px; border: 1px solid var(--b); background: var(--raise);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 5px; padding: 0 9px;
  transition: background .15s, border-color .15s;
}
.hbg:hover { background: var(--hover); border-color: var(--b-hi); }
.hbg > span {
  display: block; height: 1.5px; border-radius: 99px; background: var(--t2);
  transition: transform .28s var(--ease), opacity .22s, width .28s;
}
.hbg > span:nth-child(1) { width: 100%; }
.hbg > span:nth-child(2) { width: 70%; }
.hbg > span:nth-child(3) { width: 44%; }
.hbg--x > span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); width: 100%; }
.hbg--x > span:nth-child(2) { opacity: 0; transform: scaleX(0); }
.hbg--x > span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); width: 100%; }

/* Breadcrumb */
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.bc-home { color: var(--t3); }
.bc-div  { color: var(--t3); font-size: 16px; line-height: 1; }
.bc-cur  { color: var(--t1); font-weight: 600; }

/* Header Search */
.hdr-search {
  display: flex; align-items: center; gap: 8px;
  background: var(--raise); border: 1px solid var(--b); border-radius: 8px;
  padding: 0 10px; height: 34px;
  flex: 1; max-width: 260px;
  transition: border-color .2s, box-shadow .2s;
}
.hdr-search:focus-within {
  border-color: var(--b-hi);
  box-shadow: 0 0 0 3px var(--blue-lo);
}
.hdr-search > svg { width: 14px; height: 14px; color: var(--t3); flex-shrink: 0; }
.hdr-search input {
  flex: 1; border: none; outline: none;
  background: transparent; font-size: 13px; color: var(--t1);
  font-family: inherit; direction: rtl;
}
.hdr-search input::placeholder { color: var(--t3); }
.hdr-search kbd {
  font-size: 10px; color: var(--t3);
  border: 1px solid var(--b); border-radius: 4px; padding: 1px 5px;
  font-family: monospace; flex-shrink: 0;
}
@media (max-width: 640px) {
  .hdr-search { display: none; }
  .breadcrumb { display: none; }
}

/* Header icon buttons */
.hdr-btn {
  width: 34px; height: 34px; border-radius: 8px;
  border: 1px solid var(--b); background: var(--raise);
  color: var(--t2);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: background .15s, color .15s, border-color .15s;
  position: relative;
}
.hdr-btn:hover { background: var(--hover); color: var(--t1); border-color: var(--b-hi); }
.hdr-btn svg { width: 15px; height: 15px; }
.n-badge {
  position: absolute; top: -5px; left: -5px;
  min-width: 17px; height: 17px; border-radius: 99px;
  background: var(--red); border: 2px solid var(--surf);
  font-size: 9px; font-weight: 800; color: white;
  display: flex; align-items: center; justify-content: center; padding: 0 3px;
}
.hdr-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--cyan));
  border: 2px solid transparent;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: white; flex-shrink: 0;
  transition: border-color .2s, box-shadow .2s;
}
.hdr-avatar:hover { border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-lo); }

/* ══ PAGE BODY ══════════════════════════ */
.page-body { flex: 1; overflow-y: auto; padding: 24px 24px; }
@media (max-width: 640px) { .page-body { padding: 14px 12px; } }

.t-page-enter-active,
.t-page-leave-active { transition: opacity .2s var(--ease), transform .2s var(--ease); }
.t-page-enter-from   { opacity: 0; transform: translateY(7px); }
.t-page-leave-to     { opacity: 0; transform: translateY(-4px); }

/* ══ TOASTS ═════════════════════════════ */
.toast-rack {
  position: fixed; bottom: 18px; left: 18px;
  z-index: 9999;
  display: flex; flex-direction: column; gap: 8px;
  pointer-events: none; width: 300px;
}
.toast {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 13px 11px 10px;
  border-radius: var(--r); border: 1px solid var(--b);
  background: var(--surf); backdrop-filter: blur(18px);
  pointer-events: auto; position: relative; overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.04);
}
.t-bar { position: absolute; right: 0; top: 0; bottom: 0; width: 3px; }
.toast--success .t-bar { background: var(--green); }
.toast--warning .t-bar { background: var(--amber); }
.toast--error   .t-bar { background: var(--red); }
.t-icon {
  width: 30px; height: 30px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.t-icon svg { width: 13px; height: 13px; }
.toast--success .t-icon { background: rgba(16,185,129,.12); color: var(--green); }
.toast--warning .t-icon { background: rgba(245,158,11,.12);  color: var(--amber); }
.toast--error   .t-icon { background: rgba(239,68,68,.12);   color: var(--red); }
.t-body { flex: 1; min-width: 0; }
.t-msg { font-size: 13px; font-weight: 600; }
.t-sub { font-size: 11.5px; color: var(--t2); margin-top: 2px; }
.t-close {
  width: 22px; height: 22px; border: none; background: transparent;
  color: var(--t3); border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: background .14s, color .14s;
}
.t-close:hover { background: var(--hover); color: var(--t1); }
.t-close svg { width: 11px; height: 11px; }
.t-toast-enter-active { transition: all .3s cubic-bezier(.34,1.56,.64,1); }
.t-toast-leave-active { transition: all .22s var(--ease); }
.t-toast-enter-from   { opacity: 0; transform: translateX(-14px) scale(.96); }
.t-toast-leave-to     { opacity: 0; transform: scale(.94); }
@media (max-width: 640px) {
  .toast-rack { left: 10px; right: 10px; width: auto; bottom: 10px; }
  .top-bar { padding: 0 10px; gap: 6px; }
  .breadcrumb { display: none; }
  .tb-start { gap: 6px; }
  .tb-end { gap: 6px; }
  .hdr-btn { width: 32px; height: 32px; }
}
.dropdown-content-styled {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-right: 38px;
  margin-top: 4px;
  border-right: 1px solid rgba(255, 255, 255, 0.05);
}
.nl-sub {
  display: block;
  padding: 8px 12px;
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--t2);
  border-radius: 8px;
  transition: all 0.2s;
  text-decoration: none;
}
.nl-sub:hover {
  background: rgba(255, 255, 255, 0.03);
  color: var(--t1);
  padding-right: 16px;
}
.nl-sub.router-link-active {
  color: var(--gold);
  background: rgba(212, 175, 55, 0.05);
}

.nl-management-tourism {
  margin-top: 6px;
  background: rgba(16, 185, 129, 0.03) !important;
  border: 1px dashed rgba(16, 185, 129, 0.1) !important;
}
.nl-management-tourism:hover {
  border-color: rgba(16, 185, 129, 0.3) !important;
  background: rgba(16, 185, 129, 0.08) !important;
}

.nl-management-office {
  margin-top: 6px;
  background: rgba(168, 85, 247, 0.03) !important;
  border: 1px dashed rgba(168, 85, 247, 0.1) !important;
}
.nl-management-office:hover {
  border-color: rgba(168, 85, 247, 0.3) !important;
  background: rgba(168, 85, 247, 0.08) !important;
}

.badge-success { background: var(--green); color: black; }
.badge-purple { background: #a855f7; color: white; }

/* ══ NOTIFICATIONS STYLES ══════════════ */
.notif-badge-count {
  position: absolute;
  top: -2px;
  left: -2px;
  background: var(--red);
  color: white;
  font-size: 10px;
  font-weight: 700;
  width: 15px;
  height: 15px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--surf);
}

.notif-dropdown {
  position: absolute;
  top: 50px;
  left: 0;
  width: 340px;
  background: rgba(14, 21, 37, 0.96);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  z-index: 120;
  display: flex;
  flex-direction: column;
  max-height: 420px;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.08);
}

.notif-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.notif-header h3 {
  font-size: 14px;
  font-weight: 600;
  margin: 0;
  color: var(--t1);
}

.mark-all-read-btn {
  font-size: 11px;
  color: var(--blue);
  cursor: pointer;
  background: none;
  border: none;
  padding: 0;
}

.mark-all-read-btn:hover {
  text-decoration: underline;
}

.notif-list {
  flex: 1;
  overflow-y: auto;
}

.notif-empty {
  padding: 24px;
  text-align: center;
  color: var(--t3);
  font-size: 13px;
}

.notif-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  cursor: pointer;
  transition: background 0.15s;
  position: relative;
  text-align: right;
}

.notif-item:hover {
  background: var(--hover);
}

.notif-item--unread {
  background: rgba(59, 130, 246, 0.04);
}

.notif-item--unread::before {
  content: '';
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--blue);
}

.notif-icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: rgba(16, 185, 129, 0.1);
  color: var(--green);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
}

.notif-content {
  flex: 1;
  min-width: 0;
}

.notif-message {
  font-size: 12.5px;
  line-height: 1.4;
  margin: 0 0 4px 0;
  color: var(--t1);
  font-weight: 500;
}

.notif-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 11px;
  color: var(--t3);
}

.notif-pnr {
  background: rgba(255, 255, 255, 0.05);
  padding: 0 4px;
  border-radius: 4px;
  color: var(--t2);
}

.mark-read-btn {
  color: var(--t3);
  opacity: 0.5;
  transition: opacity 0.15s, color 0.15s;
  flex-shrink: 0;
  padding: 4px;
  margin-top: -2px;
  border-radius: 6px;
  background: none;
  border: none;
}

.mark-read-btn:hover {
  opacity: 1;
  color: var(--green);
  background: rgba(16, 185, 129, 0.05);
}

.notif-footer {
  padding: 10px 16px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  text-align: center;
}

.view-all-link {
  font-size: 12px;
  color: var(--t2);
  transition: color 0.15s;
  display: block;
  text-decoration: none;
}

.view-all-link:hover {
  color: var(--blue);
}

/* Transitions */
.t-dropdown-enter-active,
.t-dropdown-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}
.t-dropdown-enter-from,
.t-dropdown-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* ══ PRINT STYLES ══════════════════════ */
@media print {
  .app-shell {
    height: auto !important;
    overflow: visible !important;
    display: block !important;
    background: transparent !important;
  }
  .sidebar, .top-bar, .toast-rack, .backdrop {
    display: none !important;
  }
  .main-zone {
    height: auto !important;
    overflow: visible !important;
    display: block !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .page-body {
    padding: 0 !important;
    overflow: visible !important;
  }
}
</style>
