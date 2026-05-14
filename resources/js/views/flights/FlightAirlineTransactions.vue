<template>
  <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-wrap items-center gap-4">
      <button
        @click="router.back()"
        class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-white transition-all"
        title="رجوع"
      >
        <ArrowRight class="w-5 h-5" />
      </button>
      <router-link
        :to="{ name: 'airline-accounts.list' }"
        class="text-sm font-bold text-gold hover:underline"
      >
        ← كل حسابات الطيران
      </router-link>
      <div class="min-w-0 flex-1">
        <h1 class="text-3xl font-extrabold text-white">معاملات حساب شركة الطيران</h1>
        <p class="text-muted mt-1">سجل المعاملات المالية لحساب شركة الطيران</p>
      </div>
    </div>

    <!-- Account Info Card -->
    <div v-if="!loading && account" class="p-6 bg-card border border-white/10 rounded-2xl">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-2xl font-bold text-white">{{ account.name }}</h2>
          <p class="text-sm text-muted mt-1">{{ account.code }} • {{ account.system_type }}</p>
        </div>
        <div class="text-left">
          <div class="text-sm text-muted mb-1">الرصيد الحالي</div>
          <div class="text-2xl font-bold font-mono" :class="account.balance > 0 ? 'text-success' : 'text-error'">
            {{ account.balance.toLocaleString() }} {{ account.currency }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div class="p-4 bg-white/5 rounded-xl">
          <div class="text-sm text-muted mb-1">رصيد الائتمان</div>
          <div class="font-bold font-mono text-gold">
            {{ account.credit_limit.toLocaleString() }} {{ account.currency }}
          </div>
        </div>
        <div class="p-4 bg-white/5 rounded-xl">
          <div class="text-sm text-muted mb-1">الرصيد المتاح</div>
          <div class="font-bold font-mono" :class="account.available_balance > 0 ? 'text-success' : 'text-error'">
            {{ account.available_balance.toLocaleString() }} {{ account.currency }}
          </div>
        </div>
        <div class="p-4 bg-white/5 rounded-xl">
          <div class="text-sm text-muted mb-1">عدد المعاملات</div>
          <div class="font-bold font-mono text-white">
            {{ totalTransactions }}
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 border-4 border-gold border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-muted">جاري تحميل المعاملات...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="p-8 bg-error/10 border border-error/30 rounded-2xl text-center">
      <AlertCircle class="w-16 h-16 text-error mx-auto mb-4" />
      <h3 class="text-xl font-bold text-error mb-2">فشل في تحميل المعاملات</h3>
      <p class="text-muted mb-4">{{ error }}</p>
      <button
        @click="fetchTransactions"
        class="px-6 py-2 bg-error/10 text-error rounded-xl hover:bg-error/20 transition-colors font-bold"
      >
        إعادة المحاولة
      </button>
    </div>

    <!-- Transactions Table -->
    <div v-else class="bg-card border border-white/10 rounded-2xl overflow-hidden">
      <!-- Filters -->
      <div class="p-4 border-b border-white/10 flex flex-wrap items-center gap-4">
        <select
          v-model="filters.type"
          @change="fetchTransactions"
          class="px-4 py-2 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer"
        >
          <option value="">جميع أنواع المعاملات</option>
          <option value="credit">شحن</option>
          <option value="debit">خصم</option>
          <option value="refund">استرداد</option>
        </select>

        <input
          v-model="filters.from_date"
          type="date"
          class="px-4 py-2 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          @change="fetchTransactions"
        />

        <input
          v-model="filters.to_date"
          type="date"
          class="px-4 py-2 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          @change="fetchTransactions"
        />

        <button
          @click="clearFilters"
          class="text-sm text-muted hover:text-gold transition-colors px-4 py-2"
        >
          مسح الفلاتر
        </button>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">التاريخ والوقت</th>
              <th class="px-6 py-4 font-semibold">النوع</th>
              <th class="px-6 py-4 font-semibold">المبلغ</th>
              <th class="px-6 py-4 font-semibold">الرصيد قبل</th>
              <th class="px-6 py-4 font-semibold">الرصيد بعد</th>
              <th class="px-6 py-4 font-semibold">الحجز</th>
              <th class="px-6 py-4 font-semibold">البيان</th>
              <th class="px-6 py-4 font-semibold">بواسطة</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="transactions.length === 0">
              <tr>
                <td colspan="8" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center gap-4">
                    <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                      <Receipt class="w-10 h-10 text-white/10" />
                    </div>
                    <div class="max-w-xs">
                      <h3 class="text-xl font-bold">لا توجد معاملات</h3>
                      <p class="text-muted text-sm mt-1">لم يتم العثور على أي معاملات لهذا الحساب.</p>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <template v-else>
              <tr
                v-for="transaction in transactions"
                :key="transaction.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors"
              >
                <td class="px-6 py-4">
                  <div class="text-sm">{{ formatDate(transaction.created_at) }}</div>
                  <div class="text-xs text-muted">{{ formatTime(transaction.created_at) }}</div>
                </td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                      transaction.type === 'credit' ? 'bg-success/10 text-success' :
                      transaction.type === 'debit' ? 'bg-error/10 text-error' :
                      'bg-warning/10 text-warning'
                    ]"
                  >
                    <span v-if="transaction.type === 'credit'" class="w-1.5 h-1.5 rounded-full bg-success"></span>
                    <span v-else-if="transaction.type === 'debit'" class="w-1.5 h-1.5 rounded-full bg-error"></span>
                    <span v-else class="w-1.5 h-1.5 rounded-full bg-warning"></span>
                    {{ getTransactionTypeLabel(transaction.type) }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'font-mono font-bold text-sm',
                      transaction.type === 'credit' ? 'text-success' : 'text-error'
                    ]"
                  >
                    {{ transaction.type === 'credit' ? '+' : '-' }}{{ parseFloat(transaction.amount).toLocaleString() }}
                  </span>
                </td>
                <td class="px-6 py-4 font-mono text-sm">
                  {{ parseFloat(transaction.balance_before).toLocaleString() }}
                </td>
                <td class="px-6 py-4 font-mono font-bold text-sm">
                  {{ parseFloat(transaction.balance_after).toLocaleString() }}
                </td>
                <td class="px-6 py-4">
                  <span v-if="transaction.flight_booking" class="text-sm text-gold hover:underline cursor-pointer">
                    {{ transaction.flight_booking.booking_number }}
                  </span>
                  <span v-else class="text-sm text-muted">-</span>
                </td>
                <td class="px-6 py-4 text-sm max-w-xs truncate" :title="transaction.description">
                  {{ transaction.description || '-' }}
                </td>
                <td class="px-6 py-4 text-sm text-muted">
                  {{ transaction.created_by?.name || '-' }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.lastPage > 1" class="px-6 py-4 bg-white/5 border-t border-white/10 flex items-center justify-between text-sm text-muted">
        <div>
          عرض {{ (pagination.currentPage - 1) * pagination.perPage + 1 }}
          - {{ Math.min(pagination.currentPage * pagination.perPage, pagination.total) }}
          من {{ pagination.total }} نتيجة
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="goToPage(pagination.currentPage - 1)"
            :disabled="pagination.currentPage === 1"
            class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30 disabled:hover:bg-transparent"
          >
            <ChevronLeft class="w-4 h-4" />
          </button>
          <button
            v-for="page in visiblePages"
            :key="page"
            @click="goToPage(page)"
            :class="[
              'w-8 h-8 flex items-center justify-center rounded-lg font-bold transition-colors',
              page === pagination.currentPage ? 'bg-gold text-black' : 'hover:bg-white/10'
            ]"
            :disabled="page === '...'"
          >
            {{ page }}
          </button>
          <button
            @click="goToPage(pagination.currentPage + 1)"
            :disabled="pagination.currentPage === pagination.lastPage"
            class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30 disabled:hover:bg-transparent"
          >
            <ChevronRight class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import {
  ArrowRight,
  AlertCircle,
  Receipt,
  ChevronLeft,
  ChevronRight
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();

const account = ref(null);
const transactions = ref([]);
const loading = ref(true);
const error = ref(null);

const filters = ref({
  type: '',
  from_date: '',
  to_date: ''
});

const pagination = ref({
  total: 0,
  currentPage: 1,
  lastPage: 1,
  perPage: 20
});

const totalTransactions = computed(() => pagination.value.total);

const visiblePages = computed(() => {
  const current = pagination.value.currentPage;
  const last = pagination.value.lastPage;
  const delta = 2;
  const range = [];
  const rangeWithDots = [];

  for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
    range.push(i);
  }

  if (current - delta > 2) {
    rangeWithDots.push(1, '...');
  } else {
    rangeWithDots.push(1);
  }

  rangeWithDots.push(...range);

  if (current + delta < last - 1) {
    rangeWithDots.push('...', last);
  } else if (last > 1) {
    rangeWithDots.push(last);
  }

  return rangeWithDots;
});

const fetchTransactions = async (page = 1) => {
  loading.value = true;
  error.value = null;

  try {
    const accountId = route.params.id;
    const params = {
      page,
      per_page: 20
    };

    if (filters.value.type) params.type = filters.value.type;
    if (filters.value.from_date) params.from_date = filters.value.from_date;
    if (filters.value.to_date) params.to_date = filters.value.to_date;

    const response = await axios.get(`/api/v1/flight/airline-accounts/${accountId}/transactions`, { params });

    account.value = response.data?.data?.account;
    transactions.value = response.data?.data?.transactions?.data || [];
    pagination.value = {
      total: response.data?.data?.transactions?.total || 0,
      currentPage: response.data?.data?.transactions?.current_page || 1,
      lastPage: response.data?.data?.transactions?.last_page || 1,
      perPage: response.data?.data?.transactions?.per_page || 20
    };
  } catch (err) {
    console.error('Failed to fetch transactions:', err);
    error.value = err.response?.data?.message || 'فشل في تحميل المعاملات';
  } finally {
    loading.value = false;
  }
};

const goToPage = (page) => {
  if (page < 1 || page > pagination.value.lastPage || page === '...') return;
  fetchTransactions(page);
};

const clearFilters = () => {
  filters.value = {
    type: '',
    from_date: '',
    to_date: ''
  };
  fetchTransactions(1);
};

const formatDate = (dateString) => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};

const formatTime = (dateString) => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleTimeString('ar-EG', {
    hour: '2-digit',
    minute: '2-digit'
  });
};

const getTransactionTypeLabel = (type) => {
  const labels = {
    credit: 'شحن',
    debit: 'خصم',
    refund: 'استرداد'
  };
  return labels[type] || type;
};

onMounted(() => {
  fetchTransactions();
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning); }
</style>
