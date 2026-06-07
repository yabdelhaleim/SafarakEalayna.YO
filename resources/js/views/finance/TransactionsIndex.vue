<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          المعاملات المالية
        </h1>
        <p class="text-text-muted mt-1">
          جميع عمليات الدخل والمصروف والتحويلات
        </p>
      </div>
      <router-link
        to="/finance/transactions/create"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <Plus class="w-5 h-5" />
        معاملة جديدة
      </router-link>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-4">
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="store.filters.search"
          type="text"
          placeholder="بحث بالوصف أو الرقم..."
          class="w-full pl-10 pr-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="applyFilters"
        />
      </div>

      <select
        v-model="store.filters.type"
        @change="applyFilters"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
      >
        <option value="">جميع الأنواع</option>
        <option
          v-for="t in store.transactionTypes"
          :key="t.value"
          :value="t.value"
        >
          {{ t.label }}
        </option>
      </select>

      <select
        v-model="store.filters.module"
        @change="applyFilters"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
      >
        <option value="">جميع الأقسام</option>
        <option
          v-for="m in store.transactionModules"
          :key="m.value"
          :value="m.value"
        >
          {{ m.label }}
        </option>
      </select>

      <input
        v-model="store.filters.date_from"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="applyFilters"
      />

      <input
        v-model="store.filters.date_to"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="applyFilters"
      />

      <button
        @click="clearFilters"
        class="text-sm text-text-muted hover:text-gold transition-colors px-4 py-2"
      >
        مسح الفلاتر
      </button>
    </div>

    <!-- Transactions Table -->
    <template v-if="state === 'loading'">
      <TableSkeleton :rows="store.filters.per_page || 15" :columns="7" />
    </template>
    <div v-else-if="state === 'error'" class="p-6 bg-card-bg border border-white/10 rounded-2xl text-center text-error">
      {{ asyncError || 'حدث خطأ أثناء تحميل المعاملات' }}
    </div>
    <div v-else class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">الرقم</th>
              <th class="px-6 py-4 font-semibold">التاريخ</th>
              <th class="px-6 py-4 font-semibold">النوع</th>
              <th class="px-6 py-4 font-semibold">القسم</th>
              <th class="px-6 py-4 font-semibold">الوصف</th>
              <th class="px-6 py-4 font-semibold">المبلغ</th>
              <th class="px-6 py-4 font-semibold text-right">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="state === 'success'">
              <tr
                v-for="(transaction, index) in filteredTransactions"
                :key="transaction.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors group cursor-pointer"
                @click="goToDetail(transaction)"
              >
                <td class="px-6 py-4">
                  <span class="font-mono text-gold font-bold text-sm">
                    #{{ transaction.id }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-text-muted">
                    {{ formatDate(transaction.date) }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <div
                    :class="[
                      'inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                      transactionTypeStyles[transaction.type]
                    ]"
                  >
                    <span
                      v-if="transaction.type === 'income'"
                      class="w-1.5 h-1.5 rounded-full bg-current"
                    ></span>
                    {{ transactionTypeLabel(transaction.type) }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-xs text-text-muted">
                    {{ transactionModuleLabel(transaction.module) }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <span class="font-semibold text-sm">{{ transaction.notes }}</span>
                </td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'font-mono font-bold text-sm',
                      transaction.type === 'income'
                        ? 'text-success'
                        : transaction.type === 'expense'
                        ? 'text-error'
                        : 'text-blue-500'
                    ]"
                  >
                    {{ transaction.type === 'income' ? '+' : transaction.type === 'expense' ? '-' : '' }}
                    {{ transaction.amount?.toLocaleString() || 0 }}
                  </span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity" @click.stop>
                    <button
                      @click="goToDetail(transaction)"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-white transition-all"
                      title="عرض"
                    >
                      <Eye class="w-4 h-4" />
                    </button>
                    <button
                      @click="openEditTransaction(transaction)"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-gold transition-all"
                      title="تعديل"
                    >
                      <Edit2 class="w-4 h-4" />
                    </button>
                    <button
                      @click="confirmDelete(transaction)"
                      class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all"
                      title="حذف"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </template>
            <template v-else-if="state === 'empty'">
              <tr>
                <td colspan="7" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center gap-4">
                    <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                      <FileText class="w-10 h-10 text-white/10" />
                    </div>
                    <div class="max-w-xs">
                      <h3 class="text-xl font-bold text-text-main">لا توجد معاملات</h3>
                      <p class="text-text-muted text-sm mt-1">
                        ابدأ بإضافة معاملة مالية جديدة
                      </p>
                    </div>
                    <router-link
                      to="/finance/transactions/create"
                      class="mt-2 px-6 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all"
                    >
                      إضافة معاملة
                    </router-link>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="px-6 py-4 bg-white/5 border-t border-white/10 flex items-center justify-between text-sm text-text-muted">
        <div>
          عرض {{ (store.pagination.current_page - 1) * store.pagination.per_page + 1 }} -
          {{ Math.min(store.pagination.current_page * store.pagination.per_page, store.pagination.total) }}
          من {{ store.pagination.total }} معاملة
        </div>
        <div class="flex items-center gap-4">
          <div class="flex items-center gap-2">
            <select
              v-model="store.filters.per_page"
              @change="applyFilters"
              class="px-3 py-2 bg-input-bg border border-white/5 rounded-lg focus:border-gold outline-none text-sm"
            >
              <option :value="10">10 per page</option>
              <option :value="15">15 per page</option>
              <option :value="25">25 per page</option>
              <option :value="50">50 per page</option>
            </select>
          </div>

          <div v-if="store.pagination.last_page > 1" class="flex gap-2">
            <button 
              type="button"
              @click="changePage(store.pagination.current_page - 1)"
              :disabled="store.pagination.current_page === 1"
              class="p-2 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-gold disabled:opacity-30 transition-all cursor-pointer"
            >
              <ChevronRight class="w-5 h-5" />
            </button>
            <div class="flex items-center gap-1 px-4 text-sm font-bold text-white">
              <span class="text-gold">{{ store.pagination.current_page }}</span>
              <span class="text-text-muted">/</span>
              <span>{{ store.pagination.last_page }}</span>
            </div>
            <button 
              type="button"
              @click="changePage(store.pagination.current_page + 1)"
              :disabled="store.pagination.current_page === store.pagination.last_page"
              class="p-2 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-gold disabled:opacity-30 transition-all cursor-pointer"
            >
              <ChevronLeft class="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Transaction Modal -->
    <teleport to="body">
      <div 
        v-if="showEditModal" 
        class="fixed inset-0 z-[300] flex items-center justify-center bg-black/90 p-4 backdrop-blur-xl animate-in fade-in duration-300"
        @click.self="closeEditModal"
      >
        <div class="bg-card-bg w-full max-w-xl !p-0 overflow-hidden shadow-2xl border border-white/10 rounded-2xl animate-in zoom-in-95 duration-300">
          <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center text-gold border border-gold/20 shadow-2xl shadow-gold/10">
                <Edit2 class="w-6 h-6" />
              </div>
              <div>
                <h3 class="text-2xl font-black text-text-main text-right">تعديل المعاملة المالية</h3>
                <p class="text-xs text-text-muted font-bold uppercase tracking-widest mt-1 text-right">تحديث تفاصيل القيد المالي #{{ editForm.id }}</p>
              </div>
            </div>
            <button @click="closeEditModal" class="p-2 text-text-muted hover:text-white transition-colors">
              <X class="w-6 h-6" />
            </button>
          </div>

          <form @submit.prevent="submitEditTransaction" class="p-8 space-y-6">
            <!-- Type Choice -->
            <div class="space-y-2 text-right">
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">نوع المعاملة</label>
              <div class="grid grid-cols-2 gap-4">
                <button
                  type="button"
                  @click="editForm.type = 'income'"
                  class="py-3 rounded-xl border-2 transition-all font-bold flex items-center justify-center gap-2"
                  :class="editForm.type === 'income' ? 'border-success bg-success/10 text-success' : 'border-white/10 text-text-muted hover:border-gold/30'"
                >
                  <TrendingUp class="w-5 h-5" />
                  <span>إيداع / دائن (+)</span>
                </button>
                <button
                  type="button"
                  @click="editForm.type = 'expense'"
                  class="py-3 rounded-xl border-2 transition-all font-bold flex items-center justify-center gap-2"
                  :class="editForm.type === 'expense' ? 'border-error bg-error/10 text-error' : 'border-white/10 text-text-muted hover:border-gold/30'"
                >
                  <TrendingDown class="w-5 h-5" />
                  <span>سحب / مدين (-)</span>
                </button>
              </div>
            </div>

            <!-- Amount & Date -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">المبلغ *</label>
                <div class="relative group">
                  <input 
                    v-model.number="editForm.amount" 
                    type="number" 
                    step="0.01" 
                    required 
                    class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono w-full text-white"
                    placeholder="0.00"
                  />
                </div>
              </div>

              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">تاريخ المعاملة</label>
                <input 
                  v-model="editForm.date" 
                  type="date"
                  required
                  class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full text-white"
                />
              </div>
            </div>

            <!-- Module & Description -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">القسم</label>
                <select 
                  v-model="editForm.module"
                  class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full text-white"
                >
                  <option v-for="m in store.transactionModules" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
              </div>
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الوصف والبيان *</label>
                <input 
                  v-model="editForm.description" 
                  type="text"
                  required
                  class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full text-white"
                  placeholder="بيان الحركة..."
                />
              </div>
            </div>

            <!-- Account & Reference -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الحساب المالي</label>
                <select 
                  v-model="editForm.account_id"
                  class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full text-white"
                >
                  <option value="">اختر الحساب</option>
                  <option v-for="acc in store.accounts" :key="acc.id" :value="acc.id">
                    {{ acc.name }} ({{ acc.currency }})
                  </option>
                </select>
              </div>
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">رقم المرجع</label>
                <input 
                  v-model="editForm.reference" 
                  type="text"
                  class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full font-mono text-white"
                  placeholder="رقم الإيصال أو الحجز..."
                />
              </div>
            </div>

            <!-- Notes -->
            <div class="space-y-2 text-right">
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات إضافية</label>
              <textarea 
                v-model="editForm.notes" 
                rows="2" 
                class="px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm w-full resize-none text-white"
                placeholder="ملاحظات اختيارية..."
              ></textarea>
            </div>

            <div class="flex gap-4 pt-4">
              <button 
                type="submit" 
                :disabled="submitting"
                class="flex-1 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all disabled:opacity-50 flex items-center justify-center gap-2"
              >
                <span v-if="submitting" class="w-5 h-5 border-2 border-black/30 border-t-black animate-spin rounded-full"></span>
                {{ submitting ? 'جاري الحفظ...' : 'حفظ التعديلات' }}
              </button>
              <button 
                type="button" 
                @click="closeEditModal"
                class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all text-white animate-in"
              >
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFinanceStore } from '@/stores/financeStore';
import { useAsyncState } from '@/composables/useAsyncState';
import TableSkeleton from '@/components/skeletons/TableSkeleton.vue';
import { useDebounceFn } from '@vueuse/core';
import {
  Plus,
  Search,
  Eye,
  Edit2,
  Trash2,
  FileText,
  X,
  TrendingUp,
  TrendingDown,
  ChevronRight,
  ChevronLeft
} from 'lucide-vue-next';

const router = useRouter();
const store = useFinanceStore();

const { state, error: asyncError, setLoading, setSuccess, setError } = useAsyncState('idle');

const loadData = async () => {
  setLoading();
  try {
    await store.fetchTransactions();
    const isEmpty = filteredTransactions.value.length === 0;
    setSuccess(isEmpty);
  } catch (err) {
    console.error('Failed to load transactions:', err);
    setError(err);
  }
};

const showEditModal = ref(false);
const submitting = ref(false);
const editForm = ref({
  id: null,
  type: 'income',
  amount: null,
  date: '',
  module: 'general',
  description: '',
  account_id: '',
  reference: '',
  notes: '',
});

const openEditTransaction = (transaction) => {
  editForm.value = {
    id: transaction.id,
    type: transaction.type || 'income',
    amount: transaction.amount || null,
    date: transaction.date ? new Date(transaction.date).toISOString().split('T')[0] : '',
    module: transaction.module || 'general',
    description: transaction.description || '',
    account_id: transaction.account_id || '',
    reference: transaction.reference || '',
    notes: transaction.notes || '',
  };
  showEditModal.value = true;
};

const closeEditModal = () => {
  showEditModal.value = false;
};

const submitEditTransaction = async () => {
  if (submitting.value) return;
  submitting.value = true;
  try {
    await store.updateTransaction(editForm.value.id, editForm.value);
    store.addToast('تم تحديث المعاملة بنجاح');
    showEditModal.value = false;
    await loadData();
  } catch (error) {
    store.addToast('فشل تحديث المعاملة', 'error');
  } finally {
    submitting.value = false;
  }
};

const goToDetail = (transaction) => {
  router.push(`/finance/transactions/${transaction.id}`);
};

const transactionTypeLabel = (value) =>
  store.transactionTypes.find((t) => t.value === value)?.label || value;

const transactionModuleLabel = (value) =>
  store.transactionModules.find((m) => m.value === value)?.label || value;

const transactionTypeStyles = {
  income: 'bg-success/10 text-success',
  expense: 'bg-error/10 text-error',
  transfer: 'bg-blue-500/10 text-blue-500',
  refund: 'bg-warning/10 text-warning',
};

// Filtered transactions
const filteredTransactions = computed(() => store.filteredTransactions);

// Apply filters with debounce
const applyFilters = useDebounceFn(async () => {
  store.filters.page = 1;
  await loadData();
}, 400);

const changePage = async (page) => {
  if (page < 1 || page > store.pagination.last_page) return;
  store.filters.page = page;
  await loadData();
};

// Clear filters
const clearFilters = async () => {
  store.filters = {
    search: '',
    type: '',
    module: '',
    account_id: '',
    date_from: '',
    date_to: '',
    page: 1,
    per_page: 15,
  };
  await loadData();
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

// Confirm delete
const confirmDelete = async (transaction) => {
  if (confirm(`هل أنت متأكد من حذف المعاملة #${transaction.id}؟`)) {
    try {
      await store.deleteTransaction(transaction.id);
      store.addToast('تم حذف المعاملة بنجاح');
      await loadData();
    } catch (error) {
      store.addToast('فشل حذف المعاملة', 'error');
    }
  }
};

onMounted(async () => {
  await store.fetchSettingsMeta();
  await store.fetchAccounts();
  await loadData();
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

.bg-success {
  background-color: var(--success);
}

.bg-error {
  background-color: var(--error);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
