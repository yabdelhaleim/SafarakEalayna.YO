<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-display font-black text-transparent bg-clip-text bg-gradient-to-l from-rose-400 to-rose-600 flex items-center gap-3">
          <Banknote class="w-10 h-10 text-rose-500" />
          المصروفات
        </h1>
        <p class="text-sm text-text-muted mt-1">سجل تفصيلي بكافة المصروفات وتصنيفاتها لضمان الشفافية المالية.</p>
      </div>

      <button
        @click="openExpenseModal"
        class="bg-gradient-to-l from-rose-500 to-rose-600 hover:from-rose-400 hover:to-rose-500 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-rose-500/20 transition-all flex items-center gap-2"
      >
        <Plus class="w-5 h-5" />
        تسجيل مصروف جديد
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="bg-card-bg border border-white/5 p-6 rounded-2xl shadow-xl">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 bg-gradient-to-br from-rose-500/20 to-rose-600/10 rounded-2xl flex items-center justify-center shadow-inner shadow-rose-500/20 border border-rose-500/20">
            <Coins class="w-7 h-7 text-rose-400" />
          </div>
          <div>
            <p class="text-sm font-medium text-text-muted">إجمالي المصروفات (هذا الشهر)</p>
            <p class="text-2xl font-bold text-white mt-1">
              {{ formatCurrency(stats.thisMonth) }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Expenses Table -->
    <div class="bg-card-bg border border-white/5 rounded-2xl shadow-xl overflow-hidden">
      <div class="p-6 border-b border-white/5 flex flex-col sm:flex-row justify-between items-center gap-4">
        <h2 class="text-lg font-bold text-white">سجل المصروفات</h2>
        
        <!-- Filters -->
        <div class="flex gap-3">
          <select
            v-model="filters.expenseAccount"
            class="bg-input-bg border border-white/10 text-white rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-rose-500 transition-colors"
          >
            <option value="">جميع بنود المصروفات</option>
            <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">
              {{ acc.name }}
            </option>
          </select>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-right">
          <thead>
            <tr class="bg-white/5 border-b border-white/5 text-sm text-text-muted">
              <th class="p-4 font-semibold">تاريخ التسجيل</th>
              <th class="p-4 font-semibold">بند المصروف (الحساب)</th>
              <th class="p-4 font-semibold">توجيه القسم</th>
              <th class="p-4 font-semibold">تم الدفع من (الخزينة)</th>
              <th class="p-4 font-semibold">المبلغ</th>
              <th class="p-4 font-semibold">البيان / الوصف</th>
              <th class="p-4 font-semibold">بواسطة</th>
            </tr>
          </thead>
          <tbody class="text-sm text-white divide-y divide-white/5">
            <template v-if="asyncState === 'loading'">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td v-for="j in 7" :key="j" class="p-4">
                  <div class="h-4 bg-white/5 animate-pulse rounded w-full"></div>
                </td>
              </tr>
            </template>
            <tr v-else-if="asyncState === 'error'" class="text-center">
              <td colspan="7" class="p-8 text-error">حدث خطأ أثناء تحميل المصروفات</td>
            </tr>
            <tr v-else-if="asyncState === 'empty'" class="text-center">
              <td colspan="7" class="p-8 text-text-muted">لا توجد مصروفات مسجلة بعد.</td>
            </tr>
            <template v-else>
              <tr v-for="expense in expenses" :key="expense.id" class="hover:bg-white/5 transition-colors">
                <td class="p-4">
                  <div class="flex flex-col">
                    <span class="font-medium">{{ formatDate(expense.created_at) }}</span>
                    <span class="text-xs text-text-muted">{{ formatTime(expense.created_at) }}</span>
                  </div>
                </td>
                <td class="p-4">
                  <span class="bg-rose-500/20 text-rose-300 px-3 py-1 rounded-lg font-medium text-xs">
                    {{ expense.to_account_name || expense.to_account?.name || 'غير محدد' }}
                  </span>
                </td>
                <td class="p-4">
                  <span class="bg-indigo-500/20 text-indigo-300 px-3 py-1 rounded-lg font-medium text-xs">
                    {{ formatModule(expense.module) }}
                  </span>
                </td>
                <td class="p-4">
                  <span class="text-emerald-400 font-medium">
                    {{ expense.from_account_name || expense.from_account?.name || 'غير محدد' }}
                  </span>
                </td>
                <td class="p-4 font-bold text-white">
                  {{ formatCurrency(expense.amount) }}
                </td>
                <td class="p-4 text-text-muted max-w-xs truncate" :title="expense.notes">
                  {{ expense.notes || '---' }}
                </td>
                <td class="p-4 text-text-muted">
                  {{ expense.created_by_name || expense.created_by?.name || '---' }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- New Expense Modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeExpenseModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-lg rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 border-b border-white/10 bg-gradient-to-l from-rose-500/10 to-transparent">
          <h3 class="text-xl font-bold text-white flex items-center gap-2">
            <PlusCircle class="w-6 h-6 text-rose-400" />
            تسجيل مصروف جديد
          </h3>
          <p class="text-xs text-text-muted mt-1">يتم تسجيل قيد محاسبي مزدوج لضمان سلامة الخزينة</p>
        </div>
        
        <form @submit.prevent="submitExpense" class="p-6 space-y-5">
          <div v-if="globalError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3 rounded-lg text-sm">
            {{ globalError }}
          </div>
          <div v-if="successMessage" class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-3 rounded-lg text-sm">
            {{ successMessage }}
          </div>
          <div>
            <label class="block text-sm font-medium text-white mb-2">1. توجيه المصروف (القسم الرئيسي)</label>
            <select
              v-model="form.category"
              @change="updateModuleSelection"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors shadow-inner"
            >
              <option value="general">مصروفات عامة (إدارية)</option>
              <option value="tourism">قسم السياحة</option>
              <option value="office">قسم المكتب</option>
            </select>
            <p class="text-xs text-text-muted mt-2 text-rose-300/70">أولاً، حدد القسم الذي تريد تحميل هذا المصروف عليه في تقرير الأرباح والخسائر.</p>
          </div>

          <div v-if="form.category !== 'general'">
            <label class="block text-sm font-medium text-white mb-2">2. تحديد الموديول الفرعي</label>
            <select
              v-model="form.module"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors shadow-inner"
            >
              <template v-if="form.category === 'tourism'">
                <option value="flight">قسم الطيران</option>
                <option value="hajj_umra">قسم الحج والعمرة</option>
                <option value="visa">قسم التأشيرات</option>
              </template>
              <template v-else-if="form.category === 'office'">
                <option value="bus">قسم الباص</option>
                <option value="fawry">قسم فوري</option>
                <option value="online">الخدمات الإلكترونية</option>
              </template>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '2' : '3' }}. السحب من (الخزينة/البنك/المحفظة)</label>
            <select
              v-model="form.from_account_id"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
            >
              <option value="" disabled>اختر الطريقة المالية لسحب المصروف...</option>
              <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }} (الرصيد المتاح: {{ formatCurrency(acc.balance) }})
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '3' : '4' }}. تصنيف المصروف المحاسبي (بند المصروف)</label>
            <select
              v-model="form.expense_account_id"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
            >
              <option value="" disabled>اختر التصنيف (مثل: رواتب، إيجار، تسويق)</option>
              <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }}
              </option>
            </select>
            <p v-if="expenseAccounts.length === 0" class="text-xs text-rose-400 mt-2 font-bold">
              ⚠️ القائمة فارغة! يجب إضافة تصنيفات المصروفات من شاشة "الحسابات والخزائن" > زر الإضافة (نوع الحساب: مصروفات) لكي تظهر هنا.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '4' : '5' }}. المبلغ المدفوع</label>
            <div class="relative">
              <input
                type="number"
                v-model="form.amount"
                required
                min="0.01"
                step="0.01"
                class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 pr-12 focus:outline-none focus:border-rose-500 transition-colors"
                placeholder="أدخل المبلغ..."
              />
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-muted font-bold">EGP</span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '5' : '6' }}. البيان / الوصف</label>
            <textarea
              v-model="form.notes"
              rows="3"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
              placeholder="اكتب تفاصيل أو سبب المصروف..."
            ></textarea>
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="button"
              @click="closeExpenseModal"
              class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors"
            >
              إلغاء
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="flex-1 bg-gradient-to-l from-rose-500 to-rose-600 hover:from-rose-400 hover:to-rose-500 text-white py-3 rounded-xl font-bold shadow-lg shadow-rose-500/20 transition-all flex justify-center items-center gap-2 disabled:opacity-50"
            >
              <span v-if="isSubmitting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else>حفظ واعتماد</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import {
  Banknote,
  Plus,
  Coins,
  PlusCircle
} from 'lucide-vue-next';
import { useAsyncState } from '@/composables/useAsyncState';
import axios from 'axios';

const globalError = ref('');
const successMessage = ref('');

const { state: asyncState, setLoading, setSuccess, setError } = useAsyncState();
const isModalOpen = ref(false);
const isSubmitting = ref(false);

const expenses = ref([]);
const expenseAccounts = ref([]);
const treasuryAccounts = ref([]);
const stats = ref({ thisMonth: 0 });

const filters = ref({
  expenseAccount: ''
});

const form = ref({
  expense_account_id: '',
  from_account_id: '',
  amount: '',
  notes: '',
  category: 'general',
  module: 'general'
});

const updateModuleSelection = () => {
  if (form.value.category === 'general') {
    form.value.module = 'general';
  } else if (form.value.category === 'tourism') {
    form.value.module = 'flight'; // default
  } else if (form.value.category === 'office') {
    form.value.module = 'bus'; // default
  }
};

// No attachment needed anymore

const openExpenseModal = () => {
  form.value = { expense_account_id: '', from_account_id: '', amount: '', notes: '', category: 'general', module: 'general' };
  isModalOpen.value = true;
};

const closeExpenseModal = () => {
  isModalOpen.value = false;
};

// Formatting utilities
const formatCurrency = (val) => {
  if (!val && val !== 0) return '0.00 EGP';
  return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' EGP';
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('ar-EG');
};

const formatTime = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
};

const formatModule = (mod) => {
  const map = {
    'general': 'مصروفات عامة',
    'flight': 'قسم الطيران',
    'hajj_umra': 'قسم الحج والعمرة',
    'visa': 'التأشيرات',
    'bus': 'قسم الباص',
    'fawry': 'فوري',
    'online': 'الخدمات الإلكترونية'
  };
  return map[mod] || 'غير محدد';
};

const fetchInitialData = async () => {
  try {
    setLoading();
    
    const [expenseRes, treasuriesRes, transactionsRes] = await Promise.all([
      axios.get('/api/v1/finance/accounts', { params: { type: 'expense' } }),
      axios.get('/api/v1/finance/accounts', { params: { type: 'cashbox,bank,treasury' } }),
      axios.get('/api/v1/reports/transactions', { params: { type: 'expense', per_page: 50 } }),
    ]);
    
    expenseAccounts.value = expenseRes.data.data || [];
    treasuryAccounts.value = treasuriesRes.data.data || [];
    if (transactionsRes.data && transactionsRes.data.data) {
      expenses.value = transactionsRes.data.data;
      
      // Calculate this month's stats from the fetched expenses
      const currentMonth = new Date().getMonth();
      const currentYear = new Date().getFullYear();
      let thisMonthTotal = 0;
      expenses.value.forEach(exp => {
        const d = new Date(exp.created_at);
        if (d.getMonth() === currentMonth && d.getFullYear() === currentYear) {
          thisMonthTotal += parseFloat(exp.amount || 0);
        }
      });
      stats.value.thisMonth = thisMonthTotal;
    } else {
      expenses.value = [];
    }
    
    setSuccess(expenses.value.length === 0);
  } catch (error) {
    console.error('Error fetching data', error);
    setError(error);
  }
};

const submitExpense = async () => {
  if (isSubmitting.value) return;
  isSubmitting.value = true;

  try {
    const payload = {
      from_account_id: form.value.from_account_id,
      to_account_id: form.value.expense_account_id,
      amount: form.value.amount,
      notes: form.value.notes,
      type: 'expense',
      module: form.value.module
    };

    await axios.post('/api/v1/finance/transfers', payload);

    successMessage.value = 'تم تسجيل المصروف والخصم من الخزينة بنجاح';
    globalError.value = '';
    closeExpenseModal();
    fetchInitialData();
  } catch (error) {
    const msg = error.response?.data?.message || 'حدث خطأ أثناء التسجيل';
    globalError.value = msg;
  } finally {
    isSubmitting.value = false;
  }
};

onMounted(() => {
  fetchInitialData();
});
</script>
