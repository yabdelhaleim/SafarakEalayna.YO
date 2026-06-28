<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-l from-slate-900 via-[#111e36] to-slate-900 p-6 rounded-3xl border border-white/5 relative overflow-hidden">
      <div class="absolute -right-20 -top-20 w-60 h-60 bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-20 -bottom-20 w-60 h-60 bg-gold/5 rounded-full blur-3xl pointer-events-none"></div>
      
      <div class="relative z-10">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-sky-400">Employee Commission Reports</p>
        <h1 class="mt-1 text-3xl font-black text-white tracking-tight">تقرير عمولات وإنتاجية الموظفين</h1>
        <p class="mt-2 text-sm text-text-muted">
          استعرض عدد العمليات المنجزة لكل موظف واحسب العمولات المستحقة شهرياً مع إمكانية صرفها كحافز (Bonus) مباشرة.
        </p>
      </div>
    </div>

    <!-- Configuration & Date Filter Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Dates selection (5 cols) -->
      <div class="lg:col-span-5 bg-card-bg border border-white/10 rounded-2xl p-5 space-y-4">
        <h3 class="font-bold text-sm text-white flex items-center gap-2">
          <Calendar class="w-4 h-4 text-gold" />
          تحديد فترة احتساب الإنتاجية
        </h3>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-text-muted mb-2">من تاريخ</label>
            <input
              v-model="filters.from_date"
              type="date"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white"
              @change="fetchSummary"
            />
          </div>
          <div>
            <label class="block text-xs text-text-muted mb-2">إلى تاريخ</label>
            <input
              v-model="filters.to_date"
              type="date"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white"
              @change="fetchSummary"
            />
          </div>
        </div>

        <div class="pt-2">
          <button
            @click="setToCurrentMonth"
            class="text-xs text-gold hover:underline font-bold"
          >
            إعادة تعيين للشهر الحالي
          </button>
        </div>
      </div>

      <!-- Commission Rates (7 cols) -->
      <div class="lg:col-span-7 bg-card-bg border border-white/10 rounded-2xl p-5 space-y-4">
        <h3 class="font-bold text-sm text-white flex items-center gap-2">
          <Coins class="w-4 h-4 text-gold" />
          تعديل نسب العمولات الافتراضية (ج.م للعملية الواحدة)
        </h3>
        
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div>
            <label class="block text-xs text-text-muted mb-2">عمولة الطيران ✈️</label>
            <input
              v-model.number="rates.flight"
              type="number"
              min="0"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white text-center font-mono font-bold"
            />
          </div>
          <div>
            <label class="block text-xs text-text-muted mb-2">عمولة الباص 🚌</label>
            <input
              v-model.number="rates.bus"
              type="number"
              min="0"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white text-center font-mono font-bold"
            />
          </div>
          <div>
            <label class="block text-xs text-text-muted mb-2">عمولة فوري/أونلاين 💻</label>
            <input
              v-model.number="rates.online"
              type="number"
              min="0"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white text-center font-mono font-bold"
            />
          </div>
          <div>
            <label class="block text-xs text-text-muted mb-2">خدمات أخرى ⚙️</label>
            <input
              v-model.number="rates.service"
              type="number"
              min="0"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white text-center font-mono font-bold"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Summary Report Table -->
    <div v-if="loading" class="bg-card-bg border border-white/10 rounded-2xl p-12 text-center">
      <Loader2 class="w-8 h-8 text-gold animate-spin mx-auto mb-4" />
      <span class="text-text-muted text-sm">جاري جلب إحصائيات إنتاجية الموظفين...</span>
    </div>

    <div v-else-if="summaryData.length > 0" class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-2xl">
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead>
            <tr class="bg-white/[0.03] border-b border-white/5 text-text-muted text-xs">
              <th class="p-4 font-bold">الموظف</th>
              <th class="p-4 text-center font-bold">عدد تذاكر الطيران (✈️)</th>
              <th class="p-4 text-center font-bold">عدد تذاكر الباص (🚌)</th>
              <th class="p-4 text-center font-bold">خدمات أونلاين/فوري (💻)</th>
              <th class="p-4 text-center font-bold">خدمات عامة (⚙️)</th>
              <th class="p-4 text-center font-bold">إجمالي العمليات</th>
              <th class="p-4 text-center font-bold text-gold">العمولة المحسوبة</th>
              <th class="p-4 text-center font-bold">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5 text-white">
            <tr v-for="emp in summaryData" :key="emp.employee_id" class="hover:bg-white/[0.01] transition-colors">
              <td class="p-4">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-gold/10 flex items-center justify-center text-gold font-bold text-sm">
                    {{ getInitials(emp.employee_name) }}
                  </div>
                  <div>
                    <span class="font-bold text-white block">{{ emp.employee_name }}</span>
                    <span class="text-[10px] text-text-muted block">رقم التعريف: #{{ emp.employee_id }}</span>
                  </div>
                </div>
              </td>
              <td class="p-4 text-center font-mono font-semibold">{{ emp.flight_count }}</td>
              <td class="p-4 text-center font-mono font-semibold">{{ emp.bus_count }}</td>
              <td class="p-4 text-center font-mono font-semibold">{{ emp.online_count }}</td>
              <td class="p-4 text-center font-mono font-semibold">{{ emp.service_count }}</td>
              <td class="p-4 text-center">
                <span class="px-2.5 py-1 rounded-lg bg-white/5 text-xs font-mono font-black">
                  {{ emp.total_count }}
                </span>
              </td>
              <td class="p-4 text-center text-gold font-mono font-black text-base">
                {{ calculateCommission(emp).toLocaleString() }} ج.م
              </td>
              <td class="p-4 text-center">
                <button
                  @click="openDisburseModal(emp)"
                  class="px-3.5 py-2 rounded-xl bg-gold hover:bg-gold/90 text-black text-xs font-black transition-all hover:scale-[1.03] active:scale-[0.97]"
                  :disabled="calculateCommission(emp) <= 0"
                >
                  صرف العمولة كـ حافز
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-else class="bg-card-bg border border-white/10 rounded-2xl p-12 text-center">
      <p class="text-text-muted text-sm">لا توجد عمليات مسجلة لأي موظف في الفترة المحددة.</p>
    </div>

    <!-- Payout Disbursement Modal -->
    <div v-if="disburseModal.isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="closeDisburseModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-md rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 bg-gradient-to-r from-emerald-600 to-teal-600 border-b border-white/10 text-white">
          <h3 class="text-xl font-bold flex items-center gap-2">
            <Coins class="w-6 h-6 text-white" />
            تأكيد صرف عمولة موظف
          </h3>
          <p class="text-xs text-white/80 mt-1">
            سيتم خصم المبلغ من الصندوق المحدد وإضافته كمكافأة في سجل الموظف المالي.
          </p>
        </div>

        <form @submit.prevent="submitDisbursement" class="p-6 space-y-5">
          <div v-if="disburseModal.error" class="p-3 bg-error/10 border border-error/20 text-error rounded-xl text-xs">
            {{ disburseModal.error }}
          </div>

          <div>
            <label class="block text-xs text-text-muted mb-2">اسم الموظف</label>
            <input
              :value="disburseModal.employeeName"
              disabled
              type="text"
              class="w-full px-3 py-2.5 bg-input-bg/50 border border-white/5 rounded-xl text-sm outline-none text-text-muted font-bold"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">المبلغ المستحق (ج.م)</label>
              <input
                v-model.number="disburseModal.form.amount"
                type="number"
                min="1"
                required
                class="w-full px-3 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white font-mono font-bold"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">الحساب المالي للصرف</label>
              <select
                v-model="disburseModal.form.account_id"
                required
                class="w-full px-3 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white cursor-pointer"
              >
                <option value="" disabled>اختر الصندوق/الخزينة</option>
                <option
                  v-for="acc in treasuryAccounts"
                  :key="acc.id"
                  :value="acc.id"
                >
                  {{ acc.name }} ({{ acc.balance?.toLocaleString() }} ج.م)
                </option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs text-text-muted mb-2">البيان / تفاصيل الصرف</label>
            <textarea
              v-model="disburseModal.form.reason"
              required
              rows="3"
              class="w-full px-3 py-2 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none text-white resize-none"
            ></textarea>
          </div>

          <div class="flex gap-3 justify-end pt-2 border-t border-white/5">
            <button
              type="button"
              class="px-4 py-2 bg-white/5 hover:bg-white/10 text-white rounded-xl text-sm font-bold transition"
              @click="closeDisburseModal"
            >
              إلغاء
            </button>
            <button
              type="submit"
              class="px-5 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl text-sm font-black transition flex items-center gap-2"
              :disabled="disburseModal.isSubmitting"
            >
              <Loader2 v-if="disburseModal.isSubmitting" class="w-4 h-4 animate-spin text-black" />
              تأكيد عملية الصرف
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import { Calendar, Coins, Loader2 } from 'lucide-vue-next';
import { unwrapAccountsApiResponse } from '@/composables/useTreasuryAccountGroups';

const loading = ref(false);
const summaryData = ref([]);
const treasuryAccounts = ref([]);

const filters = reactive({
  from_date: '',
  to_date: '',
});

// Default commission rates (EGP)
const rates = reactive({
  flight: 50,
  bus: 20,
  online: 30,
  service: 30,
});

const disburseModal = reactive({
  isOpen: false,
  isSubmitting: false,
  error: '',
  employeeId: null,
  employeeName: '',
  form: {
    amount: 0,
    account_id: '',
    reason: '',
  },
});

const getInitials = (name) => {
  if (!name) return '؟';
  return String(name).split(' ').filter(Boolean).map(x => x[0]).join('').slice(0, 2).toUpperCase();
};

const setToCurrentMonth = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  
  filters.from_date = `${year}-${month}-01`;
  
  const lastDay = new Date(year, now.getMonth() + 1, 0).getDate();
  filters.to_date = `${year}-${month}-${String(lastDay).padStart(2, '0')}`;
  
  fetchSummary();
};

const calculateCommission = (emp) => {
  return (
    emp.flight_count * rates.flight +
    emp.bus_count * rates.bus +
    emp.online_count * rates.online +
    emp.service_count * rates.service
  );
};

const fetchSummary = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get('/api/v1/employee/bonuses/summary', {
      params: {
        from_date: filters.from_date,
        to_date: filters.to_date,
      },
    });
    summaryData.value = data?.data || [];
  } catch (error) {
    console.error('Error fetching employee activities summary', error);
  } finally {
    loading.value = false;
  }
};

const loadTreasuryAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/finance/accounts', {
      params: { per_page: 100, types: 'cashbox,bank,treasury,wallet,post', is_active: 1 },
    });
    treasuryAccounts.value = unwrapAccountsApiResponse(res);
  } catch (error) {
    console.error('Error loading treasury accounts', error);
  }
};

const openDisburseModal = (emp) => {
  const commAmount = calculateCommission(emp);
  
  disburseModal.employeeId = emp.employee_id;
  disburseModal.employeeName = emp.employee_name;
  disburseModal.error = '';
  disburseModal.form = {
    amount: commAmount,
    account_id: treasuryAccounts.value[0]?.id || '',
    reason: `صرف عمولة مبيعات وإنجاز حجز الموظف: ${emp.employee_name} عن الفترة من ${filters.from_date} إلى ${filters.to_date}`,
  };
  disburseModal.isOpen = true;
};

const closeDisburseModal = () => {
  disburseModal.isOpen = false;
};

const submitDisbursement = async () => {
  disburseModal.isSubmitting = true;
  disburseModal.error = '';
  
  try {
    const payload = {
      employee_id: disburseModal.employeeId,
      amount: disburseModal.form.amount,
      account_id: disburseModal.form.account_id,
      reason: disburseModal.form.reason,
    };
    
    await axios.post('/api/v1/employee/bonuses/bonus', payload);
    
    window.addToast?.('تم صرف وإثبات عمولة الموظف بنجاح كـ حافز (Bonus)', 'success');
    closeDisburseModal();
    fetchSummary();
  } catch (error) {
    disburseModal.error = error.response?.data?.message || 'فشل إتمام عملية الصرف المالي.';
  } finally {
    disburseModal.isSubmitting = false;
  }
};

onMounted(() => {
  setToCurrentMonth();
  loadTreasuryAccounts();
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}
.bg-input-bg {
  background-color: var(--input-bg);
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
.text-error {
  color: var(--error);
}
</style>
