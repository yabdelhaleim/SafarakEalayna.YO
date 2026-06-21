<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          التحويلات المالية
        </h1>
        <p class="text-text-muted mt-1">
          تحويل الأموال بين الحسابات المختلفة
        </p>
      </div>
      <router-link
        to="/finance/transfers/create"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <Plus class="w-5 h-5" />
        تحويل جديد
      </router-link>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-blue-500/10 rounded-lg">
            <ArrowRightLeft class="w-4 h-4 text-blue-500" />
          </div>
          <span class="text-sm text-text-muted">إجمالي التحويلات</span>
        </div>
        <p class="text-2xl font-bold font-mono text-blue-500">
          {{ store.pagination?.total ?? (Array.isArray(store.transfers) ? store.transfers.length : 0) }}
        </p>
        <p class="text-xs text-text-muted mt-1">تحويل</p>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-gold/10 rounded-lg">
            <DollarSign class="w-4 h-4 text-gold" />
          </div>
          <span class="text-sm text-text-muted">إجمالي المبلغ المحول</span>
        </div>
        <p class="text-2xl font-bold font-mono text-gold">
          {{ formatCurrency(totalTransferred, 'EGP') }}
        </p>
        <p class="text-xs text-text-muted mt-1">معادل بالجنيه</p>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-success/10 rounded-lg">
            <Calendar class="w-4 h-4 text-success" />
          </div>
          <span class="text-sm text-text-muted">تحويلات اليوم</span>
        </div>
        <p class="text-2xl font-bold font-mono text-success">
          {{ todayTransfers }}
        </p>
        <p class="text-xs text-text-muted mt-1">تحويل</p>
      </div>
    </div>

    <!-- Transfers Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">الرقم</th>
              <th class="px-6 py-4 font-semibold">التاريخ</th>
              <th class="px-6 py-4 font-semibold">من حساب</th>
              <th class="px-6 py-4 font-semibold">إلى حساب</th>
              <th class="px-6 py-4 font-semibold">المبلغ</th>
              <th class="px-6 py-4 font-semibold">الوصف</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.transfers">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td v-for="j in 6" :key="j" class="px-6 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="Array.isArray(store.transfers) && store.transfers.length > 0">
              <tr
                v-for="(transfer, index) in store.transfers"
                :key="transfer.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors group"
              >
                <td class="px-6 py-4">
                  <span class="font-mono text-gold font-bold text-sm">
                    #{{ transfer.id }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-text-muted">
                    {{ formatDate(transfer.date || transfer.created_at) }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <div class="p-1.5 bg-error/10 rounded">
                      <ArrowUpRight class="w-3 h-3 text-error" />
                    </div>
                    <span class="text-sm font-semibold">{{ transfer.from_account?.name || '-' }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <div class="p-1.5 bg-success/10 rounded">
                      <ArrowDownRight class="w-3 h-3 text-success" />
                    </div>
                    <span class="text-sm font-semibold">{{ transfer.to_account?.name || '-' }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col font-mono text-sm">
                    <span class="font-bold text-blue-500">
                      {{ formatCurrency(transfer.amount, transfer.from_account?.currency) }}
                    </span>
                    <span v-if="transfer.from_account?.currency !== transfer.to_account?.currency && transfer.converted_amount" class="text-xs text-text-muted mt-0.5">
                      = {{ formatCurrency(transfer.converted_amount, transfer.to_account?.currency) }}
                      (سعر الصرف: {{ transfer.exchange_rate }})
                    </span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-text-muted">{{ transfer.description || transfer.notes || '-' }}</span>
                </td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="6" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <ArrowRightLeft class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-text-main">لا توجد تحويلات</h3>
                    <p class="text-text-muted text-sm mt-1">
                      ابدأ بتحويل أموال بين الحسابات
                    </p>
                  </div>
                  <router-link
                    to="/finance/transfers/create"
                    class="mt-2 px-6 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all"
                  >
                    تحويل جديد
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div
      v-if="store.pagination.last_page > 1"
      class="flex items-center justify-between bg-card-bg border border-white/10 rounded-2xl px-6 py-4"
    >
      <p class="text-sm text-text-muted">
        صفحة {{ store.pagination.current_page }} من {{ store.pagination.last_page }}
        ({{ store.pagination.total }} تحويل)
      </p>
      <div class="flex items-center gap-2">
        <button
          type="button"
          :disabled="store.pagination.current_page <= 1 || store.loading.transfers"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-sm font-semibold disabled:opacity-50"
          @click="changePage(store.pagination.current_page - 1)"
        >
          السابق
        </button>
        <button
          type="button"
          :disabled="store.pagination.current_page >= store.pagination.last_page || store.loading.transfers"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-sm font-semibold disabled:opacity-50"
          @click="changePage(store.pagination.current_page + 1)"
        >
          التالي
        </button>
      </div>
    </div>

    <p
      v-if="store.errors.fetch"
      class="text-sm text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3"
    >
      {{ store.errors.fetch }}
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useFinanceStore } from '@/stores/financeStore';
import {
  Plus,
  ArrowRightLeft,
  DollarSign,
  Calendar,
  ArrowUpRight,
  ArrowDownRight,
} from 'lucide-vue-next';

const store = useFinanceStore();

const totalTransferred = computed(() => store.transferSummary?.total_amount ?? 0);

const todayTransfers = computed(() => store.transferSummary?.today_count ?? 0);

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

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency || 'EGP',
  }).format(amount || 0);
}

function changePage(page) {
  if (page < 1 || page > store.pagination.last_page) return;
  store.fetchTransfers({ page, per_page: store.pagination.per_page || 20 });
}

onMounted(async () => {
  await store.fetchTransfers({ page: 1, per_page: 20 });
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
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

.text-blue-500 {
  color: #4F8EF7;
}

.text-error {
  color: var(--error);
}

.text-success {
  color: var(--success);
}

.bg-error {
  background-color: var(--error);
}

.bg-success {
  background-color: var(--success);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
