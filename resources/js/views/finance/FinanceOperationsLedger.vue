<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          عمليات الشحن والتحويلات
        </h1>
        <p class="text-text-muted mt-1">
          شحن أنظمة الحجز (GDS) والناقلين وفوري، واستهلاك التكلفة، والتحويلات بين موديولات الخزينة
        </p>
      </div>
      <router-link
        to="/reports"
        class="px-5 py-3 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all text-sm font-bold"
      >
        مركز التقارير
      </router-link>
    </div>

    <div class="p-5 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-end gap-4 shadow-xl">
      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] font-bold text-text-muted uppercase px-1">نوع العملية</label>
        <select
          v-model="filters.operation_type"
          class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm min-w-[180px]"
          @change="fetchData"
        >
          <option value="all">جميع العمليات</option>
          <option value="recharge">شحن رصيد</option>
          <option value="cogs">استهلاك تكلفة (COGS)</option>
          <option value="module_transfer">تحويل بين موديولات</option>
        </select>
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] font-bold text-text-muted uppercase px-1">الموديول</label>
        <select
          v-model="filters.module"
          class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm min-w-[160px]"
          @change="fetchData"
        >
          <option value="all">الكل</option>
          <option value="flight">الطيران</option>
          <option value="fawry">فوري</option>
          <option value="general">عام</option>
        </select>
      </div>

      <div class="flex flex-col gap-1.5 flex-1 min-w-[200px]">
        <label class="text-[10px] font-bold text-text-muted uppercase px-1">بحث</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="وصف أو رقم المعاملة..."
          class="w-full px-4 py-3 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="debouncedFetch"
        />
      </div>

      <div class="flex items-center gap-2">
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] font-bold text-text-muted uppercase px-1">من</label>
          <input v-model="filters.from_date" type="date" class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl text-sm" @change="fetchData" />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] font-bold text-text-muted uppercase px-1">إلى</label>
          <input v-model="filters.to_date" type="date" class="px-4 py-3 bg-input-bg border border-white/5 rounded-xl text-sm" @change="fetchData" />
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="p-5 bg-card-bg border border-white/10 rounded-2xl border-r-4 border-r-blue-500">
        <p class="text-xs text-text-muted font-bold uppercase mb-1">إجمالي الشحن</p>
        <p class="text-2xl font-black text-blue-400">{{ formatCurrency(summary.total_recharges) }}</p>
        <p class="text-xs text-text-muted mt-1">{{ summary.count_recharges }} عملية</p>
      </div>
      <div class="p-5 bg-card-bg border border-white/10 rounded-2xl border-r-4 border-r-rose-500">
        <p class="text-xs text-text-muted font-bold uppercase mb-1">استهلاك COGS</p>
        <p class="text-2xl font-black text-rose-400">{{ formatCurrency(summary.total_cogs) }}</p>
        <p class="text-xs text-text-muted mt-1">{{ summary.count_cogs }} عملية</p>
      </div>
      <div class="p-5 bg-card-bg border border-white/10 rounded-2xl border-r-4 border-r-gold">
        <p class="text-xs text-text-muted font-bold uppercase mb-1">تحويلات الموديولات</p>
        <p class="text-2xl font-black text-gold">{{ formatCurrency(summary.total_module_transfers) }}</p>
        <p class="text-xs text-text-muted mt-1">{{ summary.count_module_transfers }} عملية</p>
      </div>
      <div class="p-5 bg-card-bg border border-white/10 rounded-2xl border-r-4 border-r-success">
        <p class="text-xs text-text-muted font-bold uppercase mb-1">إجمالي العمليات</p>
        <p class="text-2xl font-black text-success">{{ summary.total_operations }}</p>
      </div>
    </div>

    <div v-if="loadError" class="p-4 rounded-xl border border-rose-500/30 bg-rose-500/10 text-rose-300">
      {{ loadError }}
    </div>

    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-2xl relative">
      <div v-if="loading" class="absolute inset-0 bg-black/40 backdrop-blur-sm z-10 flex items-center justify-center">
        <Loader2 class="w-10 h-10 text-gold animate-spin" />
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-5 py-4 font-semibold">#</th>
              <th class="px-5 py-4 font-semibold">التاريخ</th>
              <th class="px-5 py-4 font-semibold">النوع</th>
              <th class="px-5 py-4 font-semibold">من</th>
              <th class="px-5 py-4 font-semibold">إلى</th>
              <th class="px-5 py-4 font-semibold">المبلغ</th>
              <th class="px-5 py-4 font-semibold">الموديول</th>
              <th class="px-5 py-4 font-semibold">الوصف</th>
              <th class="px-5 py-4 font-semibold">بواسطة</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && items.length === 0">
              <td colspan="9" class="px-5 py-12 text-center text-text-muted">لا توجد عمليات في هذه الفترة</td>
            </tr>
            <tr
              v-for="row in items"
              :key="row.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors"
            >
              <td class="px-5 py-4 font-mono text-gold text-sm">#{{ row.id }}</td>
              <td class="px-5 py-4 text-sm text-text-muted">{{ formatDate(row.date) }}</td>
              <td class="px-5 py-4">
                <span :class="badgeClass(row.operation_type)" class="text-xs font-bold px-2.5 py-1 rounded-full">
                  {{ row.operation_label }}
                </span>
              </td>
              <td class="px-5 py-4 text-sm">{{ row.from_account?.name || '—' }}</td>
              <td class="px-5 py-4 text-sm">{{ row.to_account?.name || '—' }}</td>
              <td class="px-5 py-4">
                <div class="flex flex-col font-mono text-sm">
                  <span class="font-bold text-text-main">
                    {{ formatCurrency(row.amount, row.currency) }}
                  </span>
                  <span v-if="row.transfer && row.transfer.from_currency !== row.transfer.to_currency" class="text-[11px] text-text-muted mt-0.5">
                    = {{ formatCurrency(row.transfer.converted_amount, row.transfer.to_currency) }}
                    (سعر الصرف: {{ row.transfer.exchange_rate }})
                  </span>
                </div>
              </td>
              <td class="px-5 py-4 text-sm">{{ row.module || '—' }}</td>
              <td class="px-5 py-4 text-sm text-text-muted max-w-xs truncate" :title="row.notes">{{ row.notes || '—' }}</td>
              <td class="px-5 py-4 text-sm">{{ row.created_by?.name || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.last_page > 1" class="flex items-center justify-between p-4 border-t border-white/10">
        <span class="text-sm text-text-muted">صفحة {{ pagination.current_page }} من {{ pagination.last_page }}</span>
        <div class="flex gap-2">
          <button
            type="button"
            class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-sm disabled:opacity-50"
            :disabled="pagination.current_page <= 1 || loading"
            @click="changePage(pagination.current_page - 1)"
          >
            السابق
          </button>
          <button
            type="button"
            class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-sm disabled:opacity-50"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            @click="changePage(pagination.current_page + 1)"
          >
            التالي
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { Loader2 } from 'lucide-vue-next';

const loading = ref(false);
const loadError = ref('');
const items = ref([]);
const summary = ref({
  total_recharges: 0,
  total_cogs: 0,
  total_module_transfers: 0,
  count_recharges: 0,
  count_cogs: 0,
  count_module_transfers: 0,
  total_operations: 0,
});
const pagination = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });

const filters = ref({
  operation_type: 'all',
  module: 'all',
  search: '',
  from_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
  to_date: new Date().toISOString().split('T')[0],
});

let debounceTimer = null;

const formatCurrency = (val, currency = 'EGP') => {
  const n = Number(val) || 0;
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (currency || 'EGP');
};

const formatDate = (iso) => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' });
};

const badgeClass = (type) => {
  if (type === 'recharge') return 'bg-blue-500/15 text-blue-400 border border-blue-500/20';
  if (type === 'cogs') return 'bg-rose-500/15 text-rose-400 border border-rose-500/20';
  if (type === 'module_transfer') return 'bg-gold/15 text-gold border border-gold/20';
  return 'bg-white/10 text-text-muted';
};

const fetchData = async (page = 1) => {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page,
      per_page: 25,
      from_date: filters.value.from_date,
      to_date: filters.value.to_date,
      _t: Date.now(),
    };
    if (filters.value.operation_type !== 'all') params.operation_type = filters.value.operation_type;
    if (filters.value.module !== 'all') params.module = filters.value.module;
    if (filters.value.search.trim()) params.search = filters.value.search.trim();

    const { data } = await axios.get('/api/v1/reports/finance/operations', { params });
    const payload = data?.data || {};
    items.value = payload.items || [];
    summary.value = payload.summary || summary.value;
    pagination.value = payload.pagination || pagination.value;
  } catch (e) {
    loadError.value = e.response?.data?.message || 'فشل تحميل سجل العمليات';
  } finally {
    loading.value = false;
  }
};

const debouncedFetch = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchData(1), 400);
};

const changePage = (page) => fetchData(page);

onMounted(() => fetchData());
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.bg-input-bg { background-color: var(--input-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
</style>
