<template>
  <aside
    :class="[
      'fixed top-0 right-0 h-full bg-card-bg border-l border-white/10 shadow-2xl z-50 transition-transform duration-300',
      'w-72',
      isOpen ? 'translate-x-0' : 'translate-x-full'
    ]"
  >
    <!-- Logo Section -->
    <div class="p-6 border-b border-white/10">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 bg-gradient-to-br from-gold to-gold/60 rounded-xl flex items-center justify-center shadow-lg shadow-gold/20">
          <Plane class="w-6 h-6 text-black" />
        </div>
        <div>
          <h1 class="font-display font-extrabold text-xl text-gold">سفرك علينا </h1>
          <p class="text-xs text-text-muted">نظام إدارة السفر</p>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-2">
      <!-- Main -->
      <div class="space-y-1">
        <p class="px-4 text-xs font-bold text-text-muted uppercase tracking-widest mb-2">الرئيسية</p>
        <SidebarLink
          to="/dashboard"
          label="لوحة التحكم"
          :icon="markRaw(LayoutDashboard)"
        />
      </div>

      <!-- Tourism: طيران + حج وعمرة + تأشيرات -->
      <div class="space-y-1 pt-4">
        <p class="px-4 text-xs font-bold uppercase tracking-widest mb-0.5 text-emerald-400/90">السياحة</p>
        <p class="px-4 mb-2 text-[10px] text-text-muted/90">طيران · حج وعمرة · تأشيرات</p>
        <SidebarLink
          to="/customers"
          label="العملاء"
          :icon="markRaw(UsersIcon)"
        />
        <SidebarDropdown
          label="وحدة الطيران"
          :icon="markRaw(PaperAirplaneIcon)"
          activePathPrefix="/flights"
          :items="[
            { label: 'داش بورد الطيران', to: '/flights/dashboard' },
            { label: 'حجوزات الطيران', to: '/flights/list' },
            { label: 'خزينة وأرصدة الطيران', to: '/flights/treasury' }
          ]"
        />
        <SidebarDropdown
          label="الحج والعمرة"
          :icon="markRaw(LibraryBigIcon)"
          activePathPrefix="/hajj-umra"
          :items="[
            { label: 'داش بورد الحج والعمرة', to: '/hajj-umra/dashboard' },
            { label: 'الحجوزات', to: '/hajj-umra/list' },
            { label: 'إنشاء حجز', to: '/hajj-umra/create' },
            { label: 'المالية والخزنة', to: '/hajj-umra/treasury' },
            { label: 'الشركات المنفذة (سحب/سداد)', to: '/hajj-umra/executing-companies' }
          ]"
        />
        <SidebarDropdown
          label="التأشيرات"
          :icon="markRaw(IdentificationIcon)"
          activePathPrefix="/visas"
          :items="[
            { label: 'داش بورد التأشيرات', to: '/visas' },
            { label: 'قائمة الطلبات', to: '/visas/list' },
            { label: 'المالية والخزنة', to: '/visas/treasury' },
            { label: 'مالية الوكلاء (سحب/سداد)', to: '/visas/agents-finance' }
          ]"
        />
        <SidebarLink
          to="/airline-accounts"
          label="حسابات شركات الطيران"
          :icon="markRaw(PaperAirplaneIcon)"
        />
      </div>

      <!-- Office: باص + كاش + فوري + أونلاين (+ إداري) -->
      <div class="space-y-1 pt-4">
        <p class="px-4 text-xs font-bold uppercase tracking-widest mb-0.5 text-violet-300/90">المكتب</p>
        <p class="px-4 mb-2 text-[10px] text-text-muted/90">باص · كاش · فوري · أونلاين</p>
        <SidebarDropdown
          label="وحدة الباصات"
          :icon="markRaw(TruckIcon)"
          activePathPrefix="/bus"
          :items="[
            { label: 'داش بورد الباصات', to: '/bus/dashboard' },
            { label: 'حجوزات الباصات', to: '/bus/list' },
            { label: 'إنشاء حجز', to: '/bus/create' },
            { label: 'المالية والخزنة', to: '/bus/treasury' },
            { label: 'الشركات', to: '/bus/companies' },
            { label: 'الرحلات والأسعار', to: '/bus/inventory' }
          ]"
        />
        <p class="px-4 pt-1 text-[10px] font-bold text-text-muted uppercase tracking-widest">الكاش والخزائن</p>
        <SidebarDropdown
          label="محافظ وتحويلات"
          :icon="markRaw(Wallet)"
          activePathPrefix="/wallet"
          :items="[
            { label: 'لوحة المحافظ', to: '/wallet/dashboard' },
            { label: 'قائمة العمليات', to: '/wallet' },
            { label: 'خزينة المحافظ', to: '/wallet/treasury' }
          ]"
        />
        <SidebarLink
          to="/finance/accounts"
          label="الحسابات والخزائن"
          :icon="markRaw(BuildingOfficeIcon)"
        />
        <SidebarLink
          to="/treasury"
          label="الخزينة العامة"
          :icon="markRaw(BanknotesIcon)"
        />
        <SidebarLink
          to="/finance/accounts"
          label="كشف الحساب"
          :icon="markRaw(DocumentTextIcon)"
        />
        <p class="px-4 pt-2 text-[10px] font-bold text-text-muted uppercase tracking-widest">فوري</p>
        <SidebarDropdown
          label="معاملات فوري"
          :icon="markRaw(BoltIcon)"
          activePathPrefix="/fawry"
          :items="[
            { label: 'داش بورد فوري', to: '/fawry/dashboard' },
            { label: 'قائمة المعاملات', to: '/fawry/list' },
            { label: 'خزينة فوري', to: '/fawry/treasury' }
          ]"
        />
        <p class="px-4 pt-2 text-[10px] font-bold text-text-muted uppercase tracking-widest">أونلاين</p>
        <SidebarDropdown
          label="الخدمات الإلكترونية"
          :icon="markRaw(ComputerDesktopIcon)"
          activePathPrefix="/online"
          :items="[
            { label: 'قائمة المعاملات', to: '/online' },
            { label: 'الخزنة والمالية', to: '/online/treasury' },
            { label: 'تنفيذ معاملة', to: '/online/execute' },
            { label: 'أنواع الخدمات', to: '/online/service-types' },
            { label: 'مزودو الخدمات', to: '/online/providers' }
          ]"
        />
        <SidebarLink
          to="/suppliers"
          label="الموردون"
          :icon="markRaw(UsersIcon)"
        />
        <p class="px-4 pt-2 text-[10px] font-bold text-text-muted uppercase tracking-widest">المالية</p>
        <SidebarLink
          to="/invoices"
          label="الفواتير"
          :icon="markRaw(DocumentTextIcon)"
        />
        <SidebarLink
          to="/finance/transactions"
          label="المعاملات المالية"
          :icon="markRaw(ChartBarIcon)"
        />
        <SidebarLink
          to="/finance/expenses"
          label="المصروفات"
          :icon="markRaw(BanknotesIcon)"
        />
        <SidebarLink
          to="/finance/profit-loss"
          label="الأرباح والخسائر"
          :icon="markRaw(ChartBarIcon)"
        />
        <SidebarLink
          to="/finance/transfers"
          label="التحويلات"
          :icon="markRaw(BanknotesIcon)"
        />
        <p class="px-4 pt-2 text-[10px] font-bold text-text-muted uppercase tracking-widest">الموارد البشرية</p>
        <SidebarLink
          to="/employees"
          label="الموظفون"
          :icon="markRaw(UserGroupIcon)"
        />
        <SidebarLink
          to="/attendance"
          label="الحضور والانصراف"
          :icon="markRaw(ClockIcon)"
        />
        <SidebarLink
          to="/users"
          label="إدارة المستخدمين"
          :icon="markRaw(UserGroupIcon)"
        />
        <SidebarLink
          to="/reports"
          label="التقارير الشاملة"
          :icon="markRaw(ChartBarIcon)"
        />
      </div>
    </nav>

    <!-- User Section -->
    <div class="p-4 border-t border-white/10">
      <div class="flex items-center gap-3 p-3 bg-input-bg rounded-xl">
        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center font-bold text-white">
          {{ userInitial }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-sm truncate">{{ userName }}</p>
          <p class="text-xs text-text-muted truncate">{{ userRole }}</p>
        </div>
        <button
          @click="handleLogout"
          class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-error transition-all"
          title="تسجيل الخروج"
        >
          <LogOut class="w-4 h-4" />
        </button>
      </div>
    </div>

    <!-- Overlay for mobile -->
    <div
      v-if="isOpen"
      class="fixed inset-0 bg-black/50 z-40 lg:hidden"
      @click="closeSidebar"
    ></div>
  </aside>
</template>

<script setup>
import { computed, markRaw } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import SidebarLink from './SidebarLink.vue';
import SidebarDropdown from './SidebarDropdown.vue';
import {
  LayoutDashboard,
  PaperAirplaneIcon,
  LibraryBigIcon,
  IdentificationIcon,
  TruckIcon,
  BoltIcon,
  Wallet,
  ComputerDesktopIcon,
  BuildingOfficeIcon,
  BanknotesIcon,
  DocumentTextIcon,
  UsersIcon,
  ChartBarIcon,
  UserGroupIcon,
  ClockIcon,
  LogOut
} from 'lucide-vue-next';

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close']);

const router = useRouter();
const authStore = useAuthStore();

const userName = computed(() => authStore.userName);
const userRole = computed(() => (authStore.isAdmin ? 'مدير النظام' : 'موظف'));
const userInitial = computed(() => authStore.userInitial);

const closeSidebar = () => {
  emit('close');
};

const handleLogout = async () => {
  if (!confirm('هل أنت متأكد من تسجيل الخروج؟')) return;
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
aside {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

/* Custom scrollbar */
aside nav::-webkit-scrollbar {
  width: 4px;
}

aside nav::-webkit-scrollbar-track {
  background: transparent;
}

aside nav::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 2px;
}

aside nav::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>
File modified at: Sat May  2 05:58:02 EST 2026
