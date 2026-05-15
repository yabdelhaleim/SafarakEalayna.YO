<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        to="/employees"
        class="p-2 hover:bg-white/10 rounded-lg transition-all"
      >
        <ArrowRight class="w-5 h-5 text-text-muted" />
      </router-link>
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          تفاصيل الموظف
        </h1>
        <p class="text-text-muted mt-1">موظف #{{ id }}</p>
      </div>
    </div>

    <div v-if="store.loading.employees" class="flex items-center justify-center py-20">
      <Loader2 class="w-8 h-8 text-gold animate-spin" />
    </div>

    <div v-else-if="employee" class="space-y-6">
      <!-- Employee Details Card -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <div class="flex items-start justify-between mb-6">
          <div class="flex items-center gap-4">
            <div class="w-20 h-20 rounded-full bg-gold/10 flex items-center justify-center text-gold font-bold text-2xl">
              {{ getInitials(employee.name) }}
            </div>
            <div>
              <h3 class="font-display font-extrabold text-2xl text-text-main">
                {{ employee.name }}
              </h3>
              <p class="text-text-muted">{{ employee.position }}</p>
            </div>
          </div>
          <div class="flex gap-2">
            <router-link
              :to="`/employees/${employee.id}/edit`"
              class="px-4 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold text-sm transition-all"
            >
              تعديل
            </router-link>
            <button
              @click="confirmDelete"
              class="px-4 py-2 bg-error/10 hover:bg-error/20 text-error rounded-xl font-bold text-sm transition-all"
            >
              حذف
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div>
            <p class="text-xs text-text-muted mb-1">البريد الإلكتروني</p>
            <p class="text-sm">{{ employee.email }}</p>
          </div>
          <div>
            <p class="text-xs text-text-muted mb-1">رقم الهاتف</p>
            <p class="font-mono text-sm">{{ employee.phone }}</p>
          </div>
          <div>
            <p class="text-xs text-text-muted mb-1">العنوان</p>
            <p class="text-sm">{{ employee.address || 'غير محدد' }}</p>
          </div>
        </div>
      </div>

      <!-- Work Details -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">
          معلومات العمل
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <div>
            <p class="text-xs text-text-muted mb-1">القسم</p>
            <p class="font-semibold text-sm">
              {{ getDepartmentLabel(employee.department) }}
            </p>
          </div>
          <div>
            <p class="text-xs text-text-muted mb-1">المسمى الوظيفي</p>
            <p class="font-semibold text-sm">{{ employee.position }}</p>
          </div>
          <div>
            <p class="text-xs text-text-muted mb-1">الراتب الشهري</p>
            <p class="font-mono font-bold text-gold text-lg">
              {{ employee.salary?.toLocaleString() }} جنيه
            </p>
          </div>
          <div>
            <p class="text-xs text-text-muted mb-1">تاريخ التعيين</p>
            <p class="font-semibold text-sm">{{ formatDate(employee.hire_date) }}</p>
          </div>
        </div>

        <div class="mt-6 p-4 bg-success/10 border border-success/20 rounded-xl">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-text-muted">الحالة</p>
              <div class="flex items-center gap-2 mt-1">
                <CheckCircle v-if="employee.is_active" class="w-4 h-4 text-success" />
                <XCircle v-else class="w-4 h-4 text-error" />
                <span class="font-semibold">
                  {{ employee.is_active ? 'نشط' : 'غير نشط' }}
                </span>
              </div>
            </div>
            <div>
              <p class="text-sm text-text-muted">مدة العمل</p>
              <p class="font-semibold text-lg">
                {{ calculateWorkDuration() }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Attendance Summary -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">
          سجل الحضور
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <div class="p-4 bg-success/10 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <CheckCircle class="w-4 h-4 text-success" />
              <span class="text-sm text-text-muted">أيام الحضور</span>
            </div>
            <p class="text-2xl font-bold font-mono text-success">
              {{ attendanceStats.present }}
            </p>
          </div>
          <div class="p-4 bg-error/10 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <XCircle class="w-4 h-4 text-error" />
              <span class="text-sm text-text-muted">أيام الغياب</span>
            </div>
            <p class="text-2xl font-bold font-mono text-error">
              {{ attendanceStats.absent }}
            </p>
          </div>
          <div class="p-4 bg-warning/10 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <AlertCircle class="w-4 h-4 text-warning" />
              <span class="text-sm text-text-muted">أيام الإجازة</span>
            </div>
            <p class="text-2xl font-bold font-mono text-warning">
              {{ attendanceStats.leave }}
            </p>
          </div>
        </div>

        <router-link
          to="/attendance"
          class="block text-center px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
        >
          عرض سجل الحضور الكامل
        </router-link>
      </div>

      <!-- Bonuses & Deductions -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">
          المكافآت والخصومات
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="p-4 bg-success/10 border border-success/20 rounded-xl">
            <div class="flex items-center justify-between mb-4">
              <span class="text-sm text-text-muted">إجمالي المكافآت</span>
              <DollarSign class="w-5 h-5 text-success" />
            </div>
            <p class="text-2xl font-bold font-mono text-success">
              {{ employee.bonuses_total?.toLocaleString() || 0 }} جنيه
            </p>
          </div>
          <div class="p-4 bg-error/10 border border-error/20 rounded-xl">
            <div class="flex items-center justify-between mb-4">
              <span class="text-sm text-text-muted">إجمالي الخصومات</span>
              <DollarSign class="w-5 h-5 text-error" />
            </div>
            <p class="text-2xl font-bold font-mono text-error">
              {{ employee.deductions_total?.toLocaleString() || 0 }} جنيه
            </p>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">
          الإجراءات السريعة
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <router-link
            to="/attendance"
            class="px-4 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all flex items-center justify-center gap-2"
          >
            <Clock class="w-4 h-4" />
            تسجيل حضور
          </router-link>
          <button
            @click="openModal('bonus')"
            class="px-4 py-3 bg-success/10 hover:bg-success/20 text-success rounded-xl font-bold transition-all flex items-center justify-center gap-2"
          >
            <Plus class="w-4 h-4" />
            إضافة مكافأة
          </button>
          <button
            @click="openModal('deduction')"
            class="px-4 py-3 bg-error/10 hover:bg-error/20 text-error rounded-xl font-bold transition-all flex items-center justify-center gap-2"
          >
            <Minus class="w-4 h-4" />
            إضافة خصم
          </button>
          <button
            @click="openModal('draw')"
            class="px-4 py-3 bg-warning/10 hover:bg-warning/20 text-warning rounded-xl font-bold transition-all flex items-center justify-center gap-2"
          >
            <DollarSign class="w-4 h-4" />
            سحب من الراتب (سلفة)
          </button>
        </div>
      </div>
      <!-- Employee Operations (Transactions) -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6 flex items-center gap-2">
          <Clock class="w-5 h-5 text-indigo-400" />
          سجل العمليات الأخير (إنتاجية الموظف)
        </h3>
        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="bg-white/5 border-b border-white/5 text-sm text-text-muted">
                <th class="p-3">تاريخ العملية</th>
                <th class="p-3">نوع الحركة</th>
                <th class="p-3">الموديول / القسم</th>
                <th class="p-3">المبلغ</th>
                <th class="p-3">البيان</th>
                <th class="p-3">الإجراء</th>
              </tr>
            </thead>
            <tbody class="text-sm text-white divide-y divide-white/5">
              <tr v-if="loadingTransactions" class="text-center">
                <td colspan="6" class="p-6 text-text-muted">جاري تحميل حركات الموظف...</td>
              </tr>
              <tr v-else-if="!transactions || transactions.length === 0" class="text-center">
                <td colspan="6" class="p-6 text-text-muted">لا توجد عمليات مسجلة لهذا الموظف حتى الآن.</td>
              </tr>
              <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-white/5 transition-colors">
                <td class="p-3">
                  <div class="flex flex-col">
                    <span class="font-medium">{{ formatDate(tx.created_at) }}</span>
                  </div>
                </td>
                <td class="p-3">
                  <span :class="tx.type === 'income' ? 'text-emerald-400 bg-emerald-500/10' : 'text-rose-400 bg-rose-500/10'" class="px-2 py-1 rounded text-xs font-bold">
                    {{ tx.type === 'income' ? 'إيراد (مبيعات)' : 'مصروف (دفع)' }}
                  </span>
                </td>
                <td class="p-3">
                  <span class="bg-indigo-500/20 text-indigo-300 px-2 py-1 rounded text-xs">
                    {{ tx.module }}
                  </span>
                </td>
                <td class="p-3 font-bold font-mono">
                  {{ parseFloat(tx.amount).toLocaleString('en-US') }} جنيه
                </td>
                <td class="p-3 text-text-muted max-w-[200px] truncate" :title="tx.notes">
                  {{ tx.notes || 'بدون بيان' }}
                </td>
                <td class="p-3">
                  <button @click="openModal('bonus', tx.notes)" class="text-xs bg-gold/10 hover:bg-gold/20 text-gold px-3 py-1.5 rounded transition-all font-bold">
                    مكافأة على العملية
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Financial Action Modal (Bonus / Deduction / Draw) -->
    <div v-if="modal.isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-md rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 border-b border-white/10" :class="modalTheme.bgClass">
          <h3 class="text-xl font-bold text-white flex items-center gap-2">
            <component :is="modalTheme.icon" class="w-6 h-6" :class="modalTheme.textClass" />
            {{ modalTheme.title }}
          </h3>
          <p class="text-xs text-white/70 mt-1">{{ modalTheme.subtitle }}</p>
        </div>
        
        <form @submit.prevent="submitFinancialAction" class="p-6 space-y-5">
          <div v-if="modal.error" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3 rounded-lg text-sm">
            {{ modal.error }}
          </div>
          
          <div>
            <label class="block text-sm font-medium text-white mb-2">المبلغ (جنيه مصري)</label>
            <input
              type="number"
              v-model="modal.form.amount"
              required
              min="0.01"
              step="0.01"
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-gold transition-colors font-mono"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">السحب / الدفع من (الخزينة/المحفظة)</label>
            <select
              v-model="modal.form.account_id"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-gold transition-colors"
            >
              <option value="" disabled>اختر المورد المالي...</option>
              <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }} (الرصيد: {{ acc.balance?.toLocaleString() }})
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">السبب / البيان</label>
            <textarea
              v-model="modal.form.reason"
              required
              rows="3"
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-gold transition-colors"
            ></textarea>
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="button"
              @click="closeModal"
              class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors"
            >
              إلغاء
            </button>
            <button
              type="submit"
              :disabled="modal.isSubmitting"
              class="flex-1 text-white py-3 rounded-xl font-bold transition-all flex justify-center items-center gap-2"
              :class="modalTheme.btnClass"
            >
              <span v-if="modal.isSubmitting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else>حفظ واعتماد</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useRoute, useRouter } from 'vue-router';
import { useEmployeeStore } from '@/stores/employeeStore';
import {
  ArrowRight,
  Loader2,
  CheckCircle,
  XCircle,
  AlertCircle,
  DollarSign,
  Clock,
  Plus,
  Minus,
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();
const store = useEmployeeStore();

const id = route.params.id;
const employee = computed(() => store.employees.find((e) => e.id === Number(id)));

const loadingTransactions = ref(false);
const transactions = ref([]);
const treasuryAccounts = ref([]);

const modal = ref({
  isOpen: false,
  type: 'bonus', // 'bonus', 'deduction', 'draw'
  isSubmitting: false,
  error: '',
  form: {
    amount: '',
    account_id: '',
    reason: ''
  }
});

const modalTheme = computed(() => {
  if (modal.value.type === 'bonus') {
    return {
      title: 'إضافة مكافأة (Bonus)',
      subtitle: 'سيتم خصم المبلغ من الخزينة لحساب الموظف',
      bgClass: 'bg-gradient-to-l from-success/20 to-transparent',
      textClass: 'text-success',
      icon: Plus,
      btnClass: 'bg-success hover:bg-success/80'
    };
  } else if (modal.value.type === 'deduction') {
    return {
      title: 'إضافة خصم (Deduction)',
      subtitle: 'سيتم إضافة المبلغ المخصوم للخزينة من راتب الموظف',
      bgClass: 'bg-gradient-to-l from-error/20 to-transparent',
      textClass: 'text-error',
      icon: Minus,
      btnClass: 'bg-error hover:bg-error/80'
    };
  } else {
    return {
      title: 'سحب من الراتب / سلفة (Advance)',
      subtitle: 'سحب جزء من الراتب مقدماً وخصمه من الخزينة',
      bgClass: 'bg-gradient-to-l from-warning/20 to-transparent',
      textClass: 'text-warning',
      icon: DollarSign,
      btnClass: 'bg-warning hover:bg-warning/80 text-black'
    };
  }
});

// Attendance stats (mock data for now)
const attendanceStats = ref({
  present: 22,
  absent: 2,
  leave: 1,
});

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

// Format date
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
};

// Calculate work duration
const calculateWorkDuration = () => {
  if (!employee.value?.hire_date) return 'غير محدد';
  const hireDate = new Date(employee.value.hire_date);
  const today = new Date();
  const months = Math.floor((today - hireDate) / (1000 * 60 * 60 * 24 * 30));

  if (months < 1) return 'أقل من شهر';
  if (months === 1) return 'شهر واحد';
  if (months < 12) return `${months} أشهر`;

  const years = Math.floor(months / 12);
  const remainingMonths = months % 12;

  if (remainingMonths === 0) return `${years} سنة`;
  return `${years} سنة و ${remainingMonths} أشهر`;
};

// Confirm delete
const confirmDelete = async () => {
  if (confirm(`هل أنت متأكد من حذف موظف "${employee.value?.name}"؟`)) {
    try {
      await store.deleteEmployee(employee.value.id);
      store.addToast('تم حذف الموظف بنجاح');
      router.push('/employees');
    } catch (error) {
      store.addToast('فشل حذف الموظف', 'error');
    }
  }
};

// Modal actions
const openModal = (type, defaultReason = '') => {
  modal.value.type = type;
  modal.value.error = '';
  modal.value.form = {
    amount: '',
    account_id: '',
    reason: defaultReason ? `مكافأة على: ${defaultReason}` : ''
  };
  modal.value.isOpen = true;
};

const closeModal = () => {
  modal.value.isOpen = false;
};

const submitFinancialAction = async () => {
  modal.value.isSubmitting = true;
  modal.value.error = '';
  
  try {
    const payload = {
      employee_id: employee.value.id,
      amount: modal.value.form.amount,
      account_id: modal.value.form.account_id,
      reason: modal.value.form.reason
    };
    
    // In backend, salary draw can be recorded as a Deduction with specific notes, or we just map it to deduction
    let endpoint = '/api/v1/employee/bonuses/bonus';
    if (modal.value.type === 'deduction') {
      endpoint = '/api/v1/employee/bonuses/deduction';
    } else if (modal.value.type === 'draw') {
      endpoint = '/api/v1/employee/bonuses/draw';
    }
    
    await axios.post(endpoint, payload);
    
    store.addToast('تمت العملية المالية بنجاح!');
    closeModal();
    // Refresh employee data
    await store.fetchEmployees();
  } catch (error) {
    modal.value.error = error.response?.data?.message || 'حدث خطأ أثناء تنفيذ العملية المالية';
  } finally {
    modal.value.isSubmitting = false;
  }
};

const fetchEmployeeData = async () => {
  try {
    loadingTransactions.value = true;
    
    // Fetch treasuries for the dropdown
    const accountsRes = await axios.get('/api/v1/finance/accounts', { params: { type: 'cashbox,bank,treasury' } });
    treasuryAccounts.value = accountsRes.data?.data || [];
    
    // Fetch transactions made by this employee
    const txRes = await axios.get(`/api/v1/employee/employees/${id}/transactions`);
    transactions.value = txRes.data?.data || [];
    
  } catch (error) {
    console.error('Error fetching employee extra data', error);
  } finally {
    loadingTransactions.value = false;
  }
};

onMounted(async () => {
  await store.fetchEmployees();
  fetchEmployeeData();
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

.bg-success {
  background-color: var(--success);
}

.text-error {
  color: var(--error);
}

.bg-error {
  background-color: var(--error);
}

.text-warning {
  color: var(--warning);
}

.bg-warning {
  background-color: var(--warning);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
