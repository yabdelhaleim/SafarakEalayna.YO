<template>
  <div class="online-index-view mx-auto max-w-7xl space-y-8 pb-16">
    <header class="flight-hero relative !from-violet-900/90 !to-slate-900/90">
      <div class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-violet-400/90">الخدمات الإلكترونية</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">إدارة المعاملات</h1>
          <p class="mt-2 text-sm text-text-muted">نظام متكامل لتتبع عمليات التحويل والدفع الإلكتروني وضمان توازن الخزينة.</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
          <router-link
            to="/online/service-types"
            class="btn-airline-ghost rounded-xl px-4 py-2.5 text-sm font-bold"
          >
            <Layers class="mb-0.5 ml-2 inline h-4 w-4" /> أنواع الخدمات
          </router-link>
          <router-link
            to="/online/providers"
            class="btn-airline-ghost rounded-xl px-4 py-2.5 text-sm font-bold"
          >
            <Network class="mb-0.5 ml-2 inline h-4 w-4" /> المزودون
          </router-link>
          <router-link
            to="/online/execute"
            class="rounded-xl bg-violet-600 px-6 py-2.5 text-sm font-black text-white shadow-lg shadow-violet-600/20 transition-all hover:scale-[1.02] active:scale-[0.98]"
          >
            <Plus class="mb-0.5 ml-2 inline h-4 w-4" /> معاملة جديدة
          </router-link>
        </div>
      </div>
    </header>

    <!-- Daily summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <SummaryCard
        :icon="Globe"
        title="إجمالي المعاملات"
        :value="store.stats.total_transactions"
        accent="gold"
      />
      <SummaryCard
        :icon="ShoppingCart"
        title="إجمالي الشراء"
        :value="formatMoney(store.stats.total_purchase)"
        accent="warning"
      />
      <SummaryCard
        :icon="Banknote"
        title="إجمالي البيع"
        :value="formatMoney(store.stats.total_selling)"
        accent="info"
      />
      <SummaryCard
        :icon="TrendingUp"
        title="إجمالي الربح"
        :value="formatMoney(store.stats.total_profit)"
        accent="success"
      />
    </div>

    <!-- Filters -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-[260px] relative">
        <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="store.filters.search"
          type="text"
          placeholder="ابحث برقم العميل أو اسمه أو المرجع..."
          class="w-full px-10 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="onFiltersChanged"
        />
      </div>

      <select
        v-model="store.filters.service_type_id"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm min-w-[160px] cursor-pointer"
        @change="onFiltersChanged"
      >
        <option value="">كل أنواع الخدمات</option>
        <option v-for="t in store.serviceTypes" :key="t.id" :value="t.id">
          {{ t.name ?? t.name_ar }}
        </option>
      </select>

      <select
        v-model="store.filters.provider_id"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm min-w-[160px] cursor-pointer"
        @change="onFiltersChanged"
      >
        <option value="">كل المزودين</option>
        <option v-for="p in store.providers" :key="p.id" :value="p.id">
          {{ p.name ?? p.name_ar }}
        </option>
      </select>

      <select
        v-model="store.filters.payment_method"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm min-w-[160px] cursor-pointer"
        @change="onFiltersChanged"
      >
        <option value="">كل طرق الدفع</option>
        <option v-for="m in store.paymentMethods" :key="m.code" :value="m.code">
          {{ m.label }}
        </option>
      </select>

      <select
        v-model="store.filters.status"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm min-w-[140px] cursor-pointer"
        @change="onFiltersChanged"
      >
        <option value="">كل الحالات</option>
        <option v-for="s in store.statuses" :key="s.value" :value="s.value">
          {{ s.label }}
        </option>
      </select>

      <button
        type="button"
        class="text-sm text-text-muted hover:text-gold transition-colors px-3 py-2"
        @click="clearFilters"
      >
        مسح الفلاتر
      </button>
    </div>

    <!-- Table -->
    <div v-if="store.loading.transactions" class="bg-card-bg border border-white/10 rounded-2xl p-12 flex items-center justify-center">
      <Loader2 class="w-8 h-8 text-gold animate-spin" />
    </div>

    <div v-else-if="store.transactions.length" class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-white/5 text-text-muted text-xs uppercase tracking-wider">
              <th class="px-4 py-3 text-right font-bold">#</th>
              <th class="px-4 py-3 text-right font-bold">العميل</th>
              <th class="px-4 py-3 text-right font-bold">الخدمة</th>
              <th class="px-4 py-3 text-right font-bold">المزود</th>
              <th class="px-4 py-3 text-right font-bold">سعر الشراء</th>
              <th class="px-4 py-3 text-right font-bold">سعر البيع</th>
              <th class="px-4 py-3 text-right font-bold">الربح</th>
              <th class="px-4 py-3 text-right font-bold">طريقة الدفع</th>
              <th class="px-4 py-3 text-right font-bold">الحالة</th>
              <th class="px-4 py-3 text-right font-bold">التاريخ</th>
              <th class="px-4 py-3 text-right font-bold">إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="tx in store.transactions"
              :key="tx.id"
              class="border-b border-white/5 hover:bg-white/[0.03] transition-colors"
            >
              <td class="px-4 py-3 font-mono font-bold text-gold">#{{ tx.id }}</td>
              <td class="px-4 py-3">
                <div class="font-semibold">{{ tx.customer_name || '—' }}</div>
                <div class="text-xs text-text-muted font-mono">{{ tx.customer_phone || '' }}</div>
              </td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold"
                  :style="{ backgroundColor: (tx.service_type?.color ?? '#6B7280') + '22', color: tx.service_type?.color ?? '#9CA3AF' }"
                >
                  {{ tx.service_type?.name ?? '—' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span v-if="tx.provider" class="text-xs">
                  {{ tx.provider.name }}
                </span>
                <span v-else class="text-text-muted text-xs">—</span>
              </td>
              <td class="px-4 py-3 font-mono">{{ formatMoney(tx.purchase_price) }}</td>
              <td class="px-4 py-3 font-mono">{{ formatMoney(tx.selling_price) }}</td>
              <td class="px-4 py-3 font-mono font-bold text-success">
                {{ formatMoney(tx.profit) }}
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-white/5">
                  {{ tx.payment_method_label || tx.payment_method }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold"
                  :class="statusBadgeClass(tx.status)"
                >
                  {{ tx.status_label || tx.status }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-text-muted whitespace-nowrap">
                {{ formatDateTime(tx.created_at) }}
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-1">
                  <button
                    class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-violet-400 transition-all"
                    title="عرض"
                    @click="viewTransaction(tx)"
                  >
                    <Eye class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useDebounceFn } from '@vueuse/core';
import {
  Plus,
  Search,
  Globe,
  Network,
  Layers,
  Loader2,
  Eye,
  Trash2,
  Banknote,
  ShoppingCart,
  TrendingUp,
} from 'lucide-vue-next';
import { useOnlineStore } from '@/stores/onlineStore';
import SummaryCard from '@/components/online/OnlineSummaryCard.vue';

const store = useOnlineStore();
const router = useRouter();

const onFiltersChanged = useDebounceFn(() => store.fetchTransactions({ page: 1 }), 300);

const goToPage = (page) => {
  store.filters.page = page;
  store.fetchTransactions();
};

const clearFilters = () => {
  store.resetFilters();
  store.fetchTransactions();
};

const formatMoney = (value) =>
  new Intl.NumberFormat('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    value ?? 0,
  ) + ' ج.م';

const formatDateTime = (raw) => {
  if (!raw) return '';
  const d = new Date(raw);
  return d.toLocaleString('ar-EG', {
    dateStyle: 'short',
    timeStyle: 'short',
  });
};

/** Tailwind classes من لون حالة الـ API (`/online/settings/statuses`) */
const statusBadgeClass = (status) => {
  const token = store.statuses.find((s) => s.value === status)?.color ?? '';
  const map = {
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-error/10 text-error',
  };
  return map[token] ?? 'bg-white/5 text-text-muted';
};

const viewTransaction = (tx) => {
  store.currentTransaction = tx;
  router.push({ path: '/online', query: { id: tx.id } });
};

onMounted(async () => {
  await store.fetchAllSettings();
  await Promise.all([store.fetchTransactions(), store.fetchDailySummary()]);
});
</script>

<style scoped>
.online-index-view {
  --violet: #8b5cf6;
  --card-bg: #111827;
  --input-bg: #1f2937;
  --text-main: #f9fafb;
  --text-muted: #9ca3af;
  --success: #10b981;
  --error: #ef4444;
}

.flight-hero {
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.9) 0%, rgba(15, 23, 42, 0.8) 100%);
  padding: 2.5rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(139, 92, 246, 0.1);
  overflow: hidden;
}

.flight-hero::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -20%;
  width: 140%;
  height: 200%;
  background: radial-gradient(circle, rgba(139, 92, 246, 0.05) 0%, transparent 70%);
  pointer-events: none;
}

.btn-airline-ghost {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text-main);
  transition: all 0.2s ease;
}

.btn-airline-ghost:hover {
  background: rgba(139, 92, 246, 0.1);
  border-color: rgba(139, 92, 246, 0.3);
  color: var(--violet);
}

.bg-card-bg { background-color: var(--card-bg); }
.bg-input-bg { background-color: var(--input-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-violet-400 { color: var(--violet); }
.text-success { color: var(--success); }
.bg-success\/10 { background-color: color-mix(in srgb, var(--success) 10%, transparent); }
.text-warning { color: var(--warning); }
.bg-warning\/10 { background-color: color-mix(in srgb, var(--warning) 10%, transparent); }
.text-error { color: var(--error); }
.font-mono { font-family: 'IBM Plex Sans Arabic', monospace; }
</style>
