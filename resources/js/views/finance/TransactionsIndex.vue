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
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
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
            <template v-if="store.loading.transactions">
              <tr v-for="i in 10" :key="i" class="border-b border-white/5">
                <td v-for="j in 7" :key="j" class="px-6 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="filteredTransactions.length > 0">
              <tr
                v-for="(transaction, index) in filteredTransactions"
                :key="transaction.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors group"
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
                  <span class="font-semibold text-sm">{{ transaction.description }}</span>
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
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <router-link
                      to="#"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-white transition-all"
                      title="عرض"
                    >
                      <Eye class="w-4 h-4" />
                    </router-link>
                    <router-link
                      to="#"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-gold transition-all"
                      title="تعديل"
                    >
                      <Edit2 class="w-4 h-4" />
                    </router-link>
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
            <tr v-else>
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
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="px-6 py-4 bg-white/5 border-t border-white/10 flex items-center justify-between text-sm text-text-muted">
        <div>
          عرض {{ (store.pagination.current_page - 1) * store.pagination.per_page + 1 }} -
          {{ Math.min(store.pagination.current_page * store.pagination.per_page, filteredTransactions.length) }}
          من {{ filteredTransactions.length }} معاملة
        </div>
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
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useFinanceStore } from '@/stores/financeStore';
import { useDebounceFn } from '@vueuse/core';
import {
  Plus,
  Search,
  Eye,
  Edit2,
  Trash2,
  FileText,
} from 'lucide-vue-next';

const store = useFinanceStore();

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
const applyFilters = useDebounceFn(() => {
  store.fetchTransactions();
}, 400);

// Clear filters
const clearFilters = () => {
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
  store.fetchTransactions();
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
      await store.fetchTransactions();
    } catch (error) {
      store.addToast('فشل حذف المعاملة', 'error');
    }
  }
};

onMounted(async () => {
  await store.fetchSettingsMeta();
  await store.fetchTransactions();
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
