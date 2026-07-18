<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-12">
    <!-- Header & Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden print:hidden">
      <div class="absolute top-0 right-0 w-64 h-64 bg-gold/10 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none"></div>

      <div class="relative z-10">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-l from-gold to-yellow-200 tracking-tight flex items-center gap-3">
          <Plane class="w-10 h-10 text-gold" />
          ديون الناقلين - Flight Carriers Debt
        </h1>
        <p class="text-white/60 mt-2 font-medium text-lg">
          متابعة أرصدة الناقلين (الرصيد الفعلي + الرصيد المتاح بعد حد الائتمان) وحالة المديونيات.
        </p>
      </div>

      <div class="flex items-center gap-3 relative z-10">
        <button
          @click="fetchCarriers"
          :disabled="loading"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10 font-bold disabled:opacity-50"
        >
          <RefreshCw class="w-5 h-5" :class="{ 'animate-spin': loading }" />
          تحديث
        </button>
        <button
          @click="exportCsv"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10 font-bold"
          title="تصدير CSV"
        >
          <Download class="w-5 h-5" />
          تصدير
        </button>
      </div>
    </div>

    <!-- Metrics cards (Total Prepaid, Total Debt, Net Liquidity) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
      <!-- Total Receivables (رصيد مسبق لنا) -->
      <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-success/40 transition-all overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-success/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div class="p-4 bg-success/10 rounded-2xl text-success group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 shadow-lg shadow-success/20">
            <ArrowUpRight class="w-7 h-7" />
          </div>
          <span class="text-xs font-bold px-3 py-1 rounded-full bg-success/10 text-success border border-success/20">رصيد مسبق لنا</span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">إجمالي الرصيد المسبق (Receivables)</div>
          <div class="text-3xl font-bold font-mono text-success transition-colors">
            {{ formatCurrency(metrics.totalReceivables) }}
          </div>
          <div class="text-xs text-white/40 mt-1">
            {{ metrics.receivableCount }} ناقل برصيد مسبق
          </div>
        </div>
      </div>

      <!-- Total Payables (علينا للناقلين) -->
      <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-rose-400/40 transition-all overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-rose-400/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div class="p-4 bg-rose-500/10 rounded-2xl text-rose-400 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300 shadow-lg shadow-rose-500/20">
            <ArrowDownRight class="w-7 h-7" />
          </div>
          <span class="text-xs font-bold px-3 py-1 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20">علينا للناقلين</span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">إجمالي المديونيات (Payables)</div>
          <div class="text-3xl font-bold font-mono text-rose-400 transition-colors">
            {{ formatCurrency(metrics.totalPayables) }}
          </div>
          <div class="text-xs text-white/40 mt-1">
            {{ metrics.payableCount }} ناقل علينا له ديون
          </div>
        </div>
      </div>

      <!-- Net Position (صافي المركز) -->
      <div
        class="relative group p-6 bg-card-bg border rounded-3xl transition-all overflow-hidden"
        :class="metrics.netPosition >= 0 ? 'border-success/20 hover:border-success/40' : 'border-rose-500/20 hover:border-rose-500/40'"
      >
        <div
          class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-100 transition-opacity duration-500"
          :class="metrics.netPosition >= 0 ? 'from-success/10 to-transparent' : 'from-rose-500/10 to-transparent'"
        ></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div
            class="p-4 rounded-2xl group-hover:scale-110 transition-transform duration-300 shadow-lg"
            :class="metrics.netPosition >= 0 ? 'bg-success/10 text-success shadow-success/20' : 'bg-rose-500/10 text-rose-400 shadow-rose-500/20'"
          >
            <Scale class="w-7 h-7" />
          </div>
          <span
            class="text-xs font-bold px-3 py-1 rounded-full border"
            :class="metrics.netPosition >= 0 ? 'bg-success/10 text-success border-success/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'"
          >
            {{ metrics.netPosition >= 0 ? 'صافي إيجابي' : 'صافي سلبي' }}
          </span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">صافي المركز مع الناقلين</div>
          <div
            class="text-3xl font-bold font-mono transition-colors"
            :class="metrics.netPosition >= 0 ? 'text-success' : 'text-rose-400'"
          >
            {{ formatCurrency(metrics.netPosition) }}
          </div>
          <div class="text-xs text-white/40 mt-1">
            رصيد مسبق − مديونيات
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-card border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="md:col-span-2 lg:col-span-1">
          <label class="block text-sm font-medium text-muted mb-2">اسم الناقل أو الكود</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="البحث..."
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-400 transition-all text-white"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-muted mb-2">حالة الرصيد</label>
          <select
            v-model="filters.status"
            class="w-full px-4 py-2.5 bg-input border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 text-sm text-white cursor-pointer"
          >
            <option value="all">الكل</option>
            <option value="payable">علينا للناقل (سالب)</option>
            <option value="receivable">رصيد مسبق لنا (موجب)</option>
            <option value="zero">مسوّى</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-muted mb-2">العملة</label>
          <select
            v-model="filters.currency"
            class="w-full px-4 py-2.5 bg-input border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 text-sm text-white cursor-pointer"
          >
            <option value="all">كل العملات</option>
            <option v-for="cur in availableCurrencies" :key="cur" :value="cur">{{ cur }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <button
            type="button"
            @click="resetFilters"
            class="w-full px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-sm text-white/60 hover:text-white transition-all flex items-center justify-center gap-2"
          >
            <RotateCcw class="w-4 h-4" />
            إعادة تعيين
          </button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-card border border-white/10 rounded-2xl overflow-hidden shadow-xl">
      <div v-if="loading" class="flex flex-col items-center justify-center py-20">
        <Loader2 class="w-10 h-10 animate-spin text-sky-400 mb-4" />
        <span class="text-muted">جاري تحميل الناقلين...</span>
      </div>

      <div v-else-if="!records.length" class="flex flex-col items-center justify-center py-20">
        <CheckCircle2 class="w-16 h-16 text-success/50 mb-4" />
        <span class="text-muted text-lg">لا توجد ناقلين مطابقين للفلاتر</span>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-right text-sm">
          <thead class="bg-white/5 border-b border-white/10">
            <tr class="text-xs text-muted uppercase tracking-widest">
              <th class="px-6 py-4 font-bold">الناقل</th>
              <th class="px-6 py-4 font-bold">الكود</th>
              <th class="px-6 py-4 font-bold">العملة</th>
              <th class="px-6 py-4 font-bold">الرصيد الحالي</th>
              <th class="px-6 py-4 font-bold">حد الائتمان</th>
              <th class="px-6 py-4 font-bold">المتاح للخصم</th>
              <th class="px-6 py-4 font-bold text-center">الحالة</th>
              <th class="px-6 py-4 font-bold text-center">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <tr
              v-for="row in records"
              :key="row.id"
              class="hover:bg-white/[0.03] transition-colors group"
            >
              <!-- Name -->
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="p-2 bg-sky-500/10 rounded-lg text-sky-400">
                    <Plane class="w-4 h-4" />
                  </div>
                  <div>
                    <span class="font-bold text-white text-sm block">{{ row.name }}</span>
                    <span class="text-[10px] text-white/40 block mt-0.5">
                      {{ row.system?.name || 'بدون نظام' }}
                    </span>
                  </div>
                </div>
              </td>

              <!-- Code -->
              <td class="px-6 py-4 font-mono text-sm text-white/60">
                {{ row.code || '—' }}
              </td>

              <!-- Currency -->
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-white/5 text-white/80 border border-white/10">
                  {{ row.currency }}
                </span>
              </td>

              <!-- Balance -->
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <span
                    class="font-mono font-bold text-sm"
                    :class="balanceClass(row.balance)"
                  >
                    {{ formatMoney(row.balance, row.currency) }}
                  </span>
                  <span v-if="row.balance < 0" class="text-[10px] text-rose-400">(دين علينا)</span>
                  <span v-else-if="row.balance > 0" class="text-[10px] text-success">(رصيد مسبق)</span>
                </div>
              </td>

              <!-- Credit Limit -->
              <td class="px-6 py-4 font-mono text-sm text-white/70">
                {{ formatMoney(row.credit_limit, row.currency) }}
              </td>

              <!-- Available -->
              <td class="px-6 py-4">
                <span class="font-mono text-sm font-bold text-amber-400">
                  {{ formatMoney(row.available_balance, row.currency) }}
                </span>
              </td>

              <!-- Status Badge -->
              <td class="px-6 py-4 text-center">
                <span
                  v-if="Number(row.balance) > 0"
                  class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold bg-success/10 text-success border border-success/20"
                >
                  <ArrowUpRight class="w-3 h-3 ml-1" />
                  مستحق لنا
                </span>
                <span
                  v-else-if="Number(row.balance) < 0"
                  class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"
                >
                  <ArrowDownRight class="w-3 h-3 ml-1" />
                  مستحق للناقل
                </span>
                <span
                  v-else
                  class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold bg-white/5 text-white/60 border border-white/10"
                >
                  مسوّى
                </span>
              </td>

              <!-- Actions -->
              <td class="px-6 py-4 text-center">
                <button
                  type="button"
                  @click="viewCarrier(row.id)"
                  class="text-sky-400 hover:text-sky-300 transition-colors text-xs font-bold inline-flex items-center gap-1"
                >
                  <Eye class="w-3.5 h-3.5" />
                  التفاصيل
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Footer info -->
    <div class="text-center text-xs text-white/40 print:hidden">
      إجمالي {{ records.length }} ناقل • آخر تحديث: {{ lastUpdated }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import {
  Plane, Search, RotateCcw, Loader2, Eye,
  ArrowUpRight, ArrowDownRight, Scale, RefreshCw,
  CheckCircle2, Download,
} from 'lucide-vue-next';

const router = useRouter();

// State
const loading = ref(true);
const lastUpdated = ref(new Date().toLocaleString('ar-EG'));
const carriers = ref([]);

const filters = ref({
  search: '',
  status: 'all',
  currency: 'all',
});

// Computed: available currencies
const availableCurrencies = computed(() => {
  const set = new Set();
  carriers.value.forEach((c) => set.add(c.currency));
  return Array.from(set).sort();
});

// Computed: filtered records
const records = computed(() => {
  let list = [...carriers.value];

  // search filter
  if (filters.value.search) {
    const s = filters.value.search.toLowerCase();
    list = list.filter((c) =>
      c.name?.toLowerCase().includes(s) ||
      c.code?.toLowerCase().includes(s)
    );
  }

  // status filter
  if (filters.value.status !== 'all') {
    if (filters.value.status === 'payable') {
      list = list.filter((c) => Number(c.balance) < 0);
    } else if (filters.value.status === 'receivable') {
      list = list.filter((c) => Number(c.balance) > 0);
    } else if (filters.value.status === 'zero') {
      list = list.filter((c) => Number(c.balance) === 0);
    }
  }

  // currency filter
  if (filters.value.currency !== 'all') {
    list = list.filter((c) => c.currency === filters.value.currency);
  }

  return list;
});

// Metrics
const metrics = computed(() => {
  const totalReceivables = records.value
    .filter((c) => Number(c.balance) > 0)
    .reduce((sum, c) => sum + Number(c.balance) * getRate(c.currency), 0);

  const totalPayables = Math.abs(
    records.value
      .filter((c) => Number(c.balance) < 0)
      .reduce((sum, c) => sum + Number(c.balance) * getRate(c.currency), 0)
  );

  const receivableCount = records.value.filter((c) => Number(c.balance) > 0).length;
  const payableCount = records.value.filter((c) => Number(c.balance) < 0).length;

  return {
    totalReceivables: round(totalReceivables),
    totalPayables: round(totalPayables),
    netPosition: round(totalReceivables - totalPayables),
    receivableCount,
    payableCount,
  };
});

// Exchange rates cache
const ratesCache = ref({ EGP: 1 });

function getRate(currency) {
  if (currency === 'EGP') return 1;
  if (ratesCache.value[currency]) return ratesCache.value[currency];
  // Fallback approximate rates
  const fallback = { KWD: 157.5, SAR: 12.9, USD: 48.5, EUR: 52.3, AED: 13.2, GBP: 61.2 };
  return fallback[currency] || 1;
}

async function fetchExchangeRates() {
  try {
    const resp = await axios.get('/api/v1/finance/currencies', { params: { per_page: 100 } });
    const data = resp.data?.data?.items || resp.data?.data || resp.data || [];
    data.forEach((row) => {
      const cur = row.code || row.currency;
      const rate = row.rate_to_egp || row.rate || 1;
      if (cur) ratesCache.value[cur] = Number(rate);
    });
  } catch (e) {
    console.warn('Failed to fetch exchange rates, using fallbacks', e);
  }
}

async function fetchCarriers() {
  loading.value = true;
  try {
    const resp = await axios.get('/api/v1/flight/carriers', { params: { per_page: 200 } });
    const data = resp.data?.data || [];
    carriers.value = data.map((c) => ({
      ...c,
      available_balance: Number(c.balance || 0) + Number(c.credit_limit || 0),
    }));
    lastUpdated.value = new Date().toLocaleString('ar-EG');
  } catch (e) {
    console.error('Failed to fetch carriers', e);
    carriers.value = [];
  } finally {
    loading.value = false;
  }
}

function resetFilters() {
  filters.value = { search: '', status: 'all', currency: 'all' };
}

function balanceClass(bal) {
  const b = Number(bal);
  if (b > 0) return 'text-success';
  if (b < 0) return 'text-rose-400';
  return 'text-white/60';
}

function formatMoney(n, curr = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(n) || 0) + ' ' + curr;
}

function formatCurrency(n) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
    minimumFractionDigits: 0,
  }).format(Number(n) || 0);
}

function round(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function viewCarrier(id) {
  router.push({ name: 'flights.carriers.show', params: { id } });
}

function exportCsv() {
  const headers = ['Name', 'Code', 'Currency', 'Balance', 'Credit Limit', 'Available'];
  const rows = records.value.map((c) => [
    c.name, c.code, c.currency, c.balance, c.credit_limit, c.available_balance,
  ]);
  const csv = [headers, ...rows].map((r) => r.join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `flight-carriers-debt-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
}

onMounted(async () => {
  await fetchExchangeRates();
  await fetchCarriers();
});

defineExpose({ fetchCarriers });
</script>
