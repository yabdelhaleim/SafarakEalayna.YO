<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          {{ title }}
        </h1>
        <p class="text-text-muted mt-1">
          إدارة ومراجعة العمليات المالية الخاصة بـ {{ subtitle }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <router-link
          to="/finance/transactions/create"
          class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
        >
          <Plus class="w-5 h-5" />
          معاملة جديدة
        </router-link>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-5 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-4 shadow-xl">
      <!-- Search -->
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="filters.search"
          type="text"
          placeholder="بحث بالوصف أو رقم المعاملة..."
          class="w-full pl-10 pr-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm transition-all focus:ring-2 focus:ring-gold/20"
          @input="handleFilterChange"
        />
      </div>

      <!-- Module Filter (Specific to this page) -->
      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] font-bold text-text-muted uppercase px-1">الموديول</label>
        <select
          v-model="filters.module"
          @change="handleFilterChange"
          class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[160px] transition-all hover:bg-white/5"
        >
          <option :value="allowedModules">جميع أقسام {{ title }}</option>
          <option
            v-for="m in filteredModuleOptions"
            :key="m.value"
            :value="m.value"
          >
            {{ m.label }}
          </option>
        </select>
      </div>

      <!-- Account Filter -->
      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] font-bold text-text-muted uppercase px-1">الحساب المالي</label>
        <select
          v-model="filters.account_id"
          @change="handleFilterChange"
          class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[200px] transition-all hover:bg-white/5"
        >
          <option value="">جميع الحسابات</option>
          <optgroup v-for="group in groupedAccounts" :key="group.label" :label="group.label">
            <option
              v-for="acc in group.accounts"
              :key="acc.id"
              :value="acc.id"
            >
              {{ acc.name }} ({{ acc.currency }})
            </option>
          </optgroup>
        </select>
      </div>

      <!-- Date Range -->
      <div class="flex items-center gap-2">
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] font-bold text-text-muted uppercase px-1">من تاريخ</label>
          <input
            v-model="filters.date_from"
            type="date"
            class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm transition-all"
            @change="handleFilterChange"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] font-bold text-text-muted uppercase px-1">إلى تاريخ</label>
          <input
            v-model="filters.date_to"
            type="date"
            class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm transition-all"
            @change="handleFilterChange"
          />
        </div>
      </div>

      <!-- Reset -->
      <button
        @click="resetFilters"
        class="mt-auto mb-1 p-3 text-text-muted hover:text-gold transition-all hover:bg-gold/10 rounded-xl"
        title="إعادة ضبط الفلاتر"
      >
        <RotateCcw class="w-5 h-5" />
      </button>
    </div>

    <!-- Stats Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 flex items-center gap-4 shadow-lg border-r-4 border-r-success">
        <div class="w-12 h-12 bg-success/10 rounded-xl flex items-center justify-center text-success">
          <TrendingUp class="w-6 h-6" />
        </div>
        <div>
          <p class="text-xs font-bold text-text-muted uppercase">إجمالي المقبوضات</p>
          <h3 class="text-2xl font-black text-success">{{ formatCurrency(store.stats.total_income) }}</h3>
        </div>
      </div>
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 flex items-center gap-4 shadow-lg border-r-4 border-r-error">
        <div class="w-12 h-12 bg-error/10 rounded-xl flex items-center justify-center text-error">
          <TrendingDown class="w-6 h-6" />
        </div>
        <div>
          <p class="text-xs font-bold text-text-muted uppercase">إجمالي المصروفات</p>
          <h3 class="text-2xl font-black text-error">{{ formatCurrency(store.stats.total_expense) }}</h3>
        </div>
      </div>
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 flex items-center gap-4 shadow-lg border-r-4 border-r-gold">
        <div class="w-12 h-12 bg-gold/10 rounded-xl flex items-center justify-center text-gold">
          <DollarSign class="w-6 h-6" />
        </div>
        <div>
          <p class="text-xs font-bold text-text-muted uppercase">صافي التدفق</p>
          <h3 class="text-2xl font-black text-gold">{{ formatCurrency(store.stats.net_profit) }}</h3>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-2xl relative">
      <div v-if="store.loading.transactions" class="absolute inset-0 bg-black/40 backdrop-blur-[2px] z-10 flex items-center justify-center">
        <div class="flex flex-col items-center gap-3">
          <div class="w-10 h-10 border-4 border-gold border-t-transparent rounded-full animate-spin"></div>
          <span class="text-gold font-bold">جاري التحميل...</span>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="bg-white/5 text-[11px] text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-5 font-bold">الرقم</th>
              <th class="px-6 py-5 font-bold">التاريخ</th>
              <th class="px-6 py-5 font-bold">القسم</th>
              <th class="px-6 py-5 font-bold">الحساب</th>
              <th class="px-6 py-5 font-bold">الوصف</th>
              <th class="px-6 py-5 font-bold">المبلغ</th>
              <th class="px-6 py-5 font-bold text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="transaction in store.transactions"
              :key="transaction.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors group"
            >
              <td class="px-6 py-4">
                <span class="font-mono text-gold font-bold text-xs bg-gold/10 px-2 py-1 rounded">
                  #{{ transaction.id }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-text-muted whitespace-nowrap">
                {{ formatDate(transaction.created_at) }}
              </td>
              <td class="px-6 py-4">
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-sky-200">
                  {{ getModuleLabel(transaction.module) }}
                </span>
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-col gap-0.5">
                  <div class="text-xs font-bold text-white">{{ transaction.from_account_name || transaction.to_account_name }}</div>
                  <div class="text-[10px] text-text-muted">{{ getAccountTypeLabel(transaction.from_account_type || transaction.to_account_type) }}</div>
                </div>
              </td>
              <td class="px-6 py-4">
                <div class="max-w-xs truncate font-medium text-sm text-white/90" :title="transaction.notes">
                  {{ transaction.notes || '-' }}
                </div>
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-col items-start">
                  <span
                    :class="[
                      'font-mono font-black text-sm',
                      transaction.type === 'income' ? 'text-success' : 'text-error'
                    ]"
                  >
                    {{ transaction.type === 'income' ? '+' : '-' }}
                    {{ formatNumber(transaction.amount) }}
                  </span>
                  <span class="text-[10px] font-bold text-text-muted">{{ transaction.type === 'income' ? 'قبض' : 'صرف' }}</span>
                </div>
              </td>
              <td class="px-6 py-4 text-left">
                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all transform translate-x-2 group-hover:translate-x-0">
                  <button class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-white transition-all">
                    <Eye class="w-4 h-4" />
                  </button>
                  <button class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="!store.transactions.length && !store.loading.transactions">
              <td colspan="7" class="px-6 py-32 text-center">
                <div class="flex flex-col items-center gap-4 grayscale opacity-40">
                  <Inbox class="w-16 h-16 text-text-muted" />
                  <p class="font-bold text-lg">لا توجد عمليات مسجلة تطابق الفلاتر المختارة</p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="px-6 py-5 bg-white/5 border-t border-white/10 flex items-center justify-between">
        <div class="text-xs text-text-muted font-bold">
          عرض صفحة {{ store.pagination.current_page }} من {{ store.pagination.last_page }}
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="changePage(store.pagination.current_page - 1)"
            :disabled="store.pagination.current_page === 1"
            class="p-2 bg-white/5 border border-white/10 rounded-lg hover:border-gold disabled:opacity-30 disabled:cursor-not-allowed transition-all"
          >
            <ChevronRight class="w-4 h-4" />
          </button>
          <button
            @click="changePage(store.pagination.current_page + 1)"
            :disabled="store.pagination.current_page === store.pagination.last_page"
            class="p-2 bg-white/5 border border-white/10 rounded-lg hover:border-gold disabled:opacity-30 disabled:cursor-not-allowed transition-all"
          >
            <ChevronLeft class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { useFinanceStore } from '@/stores/financeStore';
import { useDebounceFn } from '@vueuse/core';
import {
  Search,
  Plus,
  RotateCcw,
  TrendingUp,
  TrendingDown,
  DollarSign,
  Eye,
  Trash2,
  Inbox,
  ChevronRight,
  ChevronLeft
} from 'lucide-vue-next';

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  allowedModules: { type: Array, required: true }
});

const store = useFinanceStore();

// Local Filters
const filters = reactive({
  search: '',
  module: props.allowedModules,
  account_id: '',
  date_from: '',
  date_to: '',
  page: 1,
  per_page: 15
});

// Computed
const filteredModuleOptions = computed(() => {
  return store.transactionModules.filter(m => props.allowedModules.includes(m.value));
});

const groupedAccounts = computed(() => {
  if (!store.accounts.length) return [];
  const groups = {};
  store.accounts.forEach(acc => {
    const typeLabel = getAccountTypeLabel(acc.type);
    if (!groups[typeLabel]) {
      groups[typeLabel] = { label: typeLabel, accounts: [] };
    }
    groups[typeLabel].accounts.push(acc);
  });
  return Object.values(groups);
});

// Actions
const handleFilterChange = useDebounceFn(() => {
  filters.page = 1;
  fetchData();
}, 400);

const fetchData = async () => {
  await Promise.all([
    store.fetchTransactions({
      ...filters,
      module: filters.module
    }),
    store.fetchStats({
      from_date: filters.date_from,
      to_date: filters.date_to,
      module: filters.module
    })
  ]);
};

const resetFilters = () => {
  filters.search = '';
  filters.module = props.allowedModules;
  filters.account_id = '';
  filters.date_from = '';
  filters.date_to = '';
  filters.page = 1;
  fetchData();
};

const changePage = (page) => {
  filters.page = page;
  fetchData();
};

// Utils
const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('ar-EG', {
    day: 'numeric',
    month: 'short',
    year: 'numeric'
  });
};

const formatCurrency = (val) => {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP'
  }).format(val || 0);
};

const formatNumber = (val) => {
  return Number(val || 0).toLocaleString('ar-EG');
};

const getModuleLabel = (val) => {
  return store.transactionModules.find(m => m.value === val)?.label || val;
};

const getAccountTypeLabel = (val) => {
  return store.accountTypes.find(t => t.value === val)?.label || val;
};

onMounted(async () => {
  await Promise.all([
    store.fetchSettingsMeta(),
    store.fetchAccounts()
  ]);
  fetchData();
});
</script>

<style scoped>
.animate-shimmer {
  background: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0) 0%,
    rgba(255, 255, 255, 0.05) 50%,
    rgba(255, 255, 255, 0) 100%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

.text-success { color: #10B981; }
.text-error { color: #EF4444; }
.bg-success\/10 { background-color: rgba(16, 185, 129, 0.1); }
.bg-error\/10 { background-color: rgba(239, 68, 68, 0.1); }
</style>
