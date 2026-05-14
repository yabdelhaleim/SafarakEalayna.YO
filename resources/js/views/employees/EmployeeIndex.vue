<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          الموظفين
        </h1>
        <p class="text-text-muted mt-1">
          إدارة ومتابعة الموظفين
        </p>
      </div>
      <div class="flex gap-3">
        <router-link
          to="/attendance"
          class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold flex items-center justify-center gap-2 transition-all"
        >
          <Clock class="w-5 h-5" />
          الحضور
        </router-link>
        <router-link
          to="/employees/create"
          class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
        >
          <Plus class="w-5 h-5" />
          موظف جديد
        </router-link>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-gold/10 rounded-xl text-gold group-hover:scale-110 transition-transform">
            <Users class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-gold/10 text-gold">
            إجمالي
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الموظفين</div>
          <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">
            {{ store.stats.total_employees }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <UserCheck class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">
            نشط
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">الموظفون النشطون</div>
          <div class="text-2xl font-bold font-mono group-hover:text-success transition-colors">
            {{ store.stats.active_employees }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-blue-400/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-blue-400/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-blue-500/10 rounded-xl text-blue-500 group-hover:scale-110 transition-transform">
            <CalendarCheck class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-blue-500/10 text-blue-500">
            حاضر
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">الحاضرون اليوم</div>
          <div class="text-2xl font-bold font-mono group-hover:text-blue-400 transition-colors">
            {{ store.stats.present_today }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-teal-400/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-teal-400/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-teal-400/10 rounded-xl text-teal-400 group-hover:scale-110 transition-transform">
            <DollarSign class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-teal-400/10 text-teal-400">
            رواتب
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الرواتب</div>
          <div class="text-2xl font-bold font-mono group-hover:text-teal-400 transition-colors">
            {{ store.stats.net_payroll?.toLocaleString() }}
          </div>
          <div class="text-xs text-text-muted mt-1">جنيه/شهر</div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-4">
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="store.filters.search"
          type="text"
          placeholder="بحث بالاسم أو الهاتف..."
          class="w-full pl-10 pr-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="applyFilters"
        />
      </div>

      <select
        v-model="store.filters.department"
        @change="applyFilters"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
      >
        <option value="">جميع الأقسام</option>
        <option
          v-for="dept in store.departments"
          :key="dept.value"
          :value="dept.value"
        >
          {{ dept.label }}
        </option>
      </select>

      <select
        v-model="store.filters.status"
        @change="applyFilters"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
      >
        <option value="">جميع الحالات</option>
        <option
          v-for="status in store.employeeStatuses"
          :key="status.value"
          :value="status.value"
        >
          {{ status.label }}
        </option>
      </select>

      <button
        @click="clearFilters"
        class="text-sm text-text-muted hover:text-gold transition-colors px-4 py-2"
      >
        مسح الفلاتر
      </button>
    </div>

    <!-- Employees Table -->
    <div v-if="store.loading.employees" class="bg-card-bg border border-white/10 rounded-2xl p-12">
      <div class="flex items-center justify-center">
        <Loader2 class="w-8 h-8 text-gold animate-spin" />
      </div>
    </div>

    <div v-else-if="filteredEmployees.length > 0" class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b border-white/5">
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الموظف
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                القسم
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الوظيفة
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الراتب
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الحالة
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                تاريخ التعيين
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                إجراءات
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="employee in filteredEmployees"
              :key="employee.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors"
            >
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-full bg-gold/10 flex items-center justify-center text-gold font-bold">
                    {{ getInitials(employee.name) }}
                  </div>
                  <div>
                    <div class="font-semibold text-sm">{{ employee.name }}</div>
                    <div class="text-xs text-text-muted font-mono">
                      {{ employee.phone }}
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4">
                <span class="text-sm">{{ getDepartmentLabel(employee.department) }}</span>
              </td>
              <td class="px-6 py-4">
                <span class="text-sm">{{ employee.position }}</span>
              </td>
              <td class="px-6 py-4">
                <span class="font-mono font-bold text-gold text-sm">
                  {{ employee.salary?.toLocaleString() }} جنيه
                </span>
              </td>
              <td class="px-6 py-4">
                <span
                  :class="[
                    'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase',
                    getStatusClass(employee.status, employee.is_active)
                  ]"
                >
                  <component
                    :is="getStatusIcon(employee.status)"
                    class="w-3 h-3"
                  />
                  {{ getStatusLabel(employee.status, employee.is_active) }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="text-sm">{{ formatDate(employee.hire_date) }}</span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <router-link
                    :to="`/employees/${employee.id}`"
                    class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-gold transition-all"
                    title="عرض التفاصيل"
                  >
                    <Eye class="w-4 h-4" />
                  </router-link>
                  <router-link
                    :to="`/employees/${employee.id}/edit`"
                    class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-gold transition-all"
                    title="تعديل"
                  >
                    <Edit2 class="w-4 h-4" />
                  </router-link>
                  <button
                    @click="confirmDelete(employee)"
                    class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all"
                    title="حذف"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="bg-card-bg border border-white/10 rounded-2xl p-12 text-center">
      <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-6">
        <Users class="w-10 h-10 text-white/10" />
      </div>
      <h3 class="text-xl font-bold text-text-main mb-2">لا يوجد موظفين</h3>
      <p class="text-text-muted max-w-md mx-auto mb-6">
        ابدأ بإضافة موظفين للنظام
      </p>
      <router-link
        to="/employees/create"
        class="inline-block px-6 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all"
      >
        إضافة موظف
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useEmployeeStore } from '@/stores/employeeStore';
import { useDebounceFn } from '@vueuse/core';
import {
  Plus,
  Search,
  Clock,
  Users,
  UserCheck,
  CalendarCheck,
  DollarSign,
  Eye,
  Edit2,
  Trash2,
  Loader2,
  CheckCircle,
  XCircle,
  AlertCircle,
} from 'lucide-vue-next';

const store = useEmployeeStore();

// Filtered employees
const filteredEmployees = computed(() => store.filteredEmployees);

// Get initials
const getInitials = (name) => {
  if (!name) return '؟';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
};

// Get department label
const getDepartmentLabel = (department) => {
  const dept = store.departments.find((d) => d.value === department);
  return dept?.label || department;
};

// Get status icon
const getStatusIcon = (status) => {
  const icons = {
    active: CheckCircle,
    inactive: XCircle,
    on_leave: AlertCircle,
  };
  return icons[status] || AlertCircle;
};

// Get status class
const getStatusClass = (status, isActive) => {
  if (!isActive) return 'bg-error/10 text-error';
  const classes = {
    active: 'bg-success/10 text-success',
    inactive: 'bg-error/10 text-error',
    on_leave: 'bg-warning/10 text-warning',
  };
  return classes[status] || classes.inactive;
};

// Get status label
const getStatusLabel = (status, isActive) => {
  if (!isActive) return 'غير نشط';
  const statusObj = store.employeeStatuses.find((s) => s.value === status);
  return statusObj?.label || status;
};

// Format date
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

// Apply filters with debounce
const applyFilters = useDebounceFn(() => {
  // Filters are reactive via computed
}, 400);

// Clear filters
const clearFilters = () => {
  store.filters = {
    search: '',
    department: '',
    status: '',
    date_from: '',
    date_to: '',
    page: 1,
    per_page: 15,
  };
};

// Confirm delete
const confirmDelete = async (employee) => {
  if (confirm(`هل أنت متأكد من حذف موظف "${employee.name}"؟`)) {
    try {
      await store.deleteEmployee(employee.id);
      store.addToast('تم حذف الموظف بنجاح');
      await store.fetchEmployees();
      await store.fetchStats();
    } catch (error) {
      store.addToast('فشل حذف الموظف', 'error');
    }
  }
};

onMounted(async () => {
  await Promise.all([store.fetchEmployees(), store.fetchAttendance(), store.fetchStats()]);
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}

.bg-input-bg {
  background-color: var(--input-bg);
}

.text-text-main {
  color: var(--text-main);
}

.text-text-muted {
  color: var(--text-muted);
}

.text-gold {
  color: var(--gold);
}

.bg-gold {
  background-color: var(--gold);
}

.text-success {
  color: var(--success);
}

.text-error {
  color: var(--error);
}

.text-warning {
  color: var(--warning);
}

.text-blue-400 {
  color: #4F8EF7;
}

.text-blue-500 {
  color: #4F8EF7;
}

.bg-success {
  background-color: var(--success);
}

.bg-error {
  background-color: var(--error);
}

.bg-warning {
  background-color: var(--warning);
}

.bg-blue-500 {
  background-color: #4F8EF7;
}

.bg-teal-400 {
  background-color: #2DD4BF;
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
