<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-12 print:space-y-6">
    <!-- Professional Print Header (Visible only on print) -->
    <div class="hidden print:block print:mb-8">
      <div class="flex items-center justify-between border-b-2 border-black pb-4">
        <div>
          <h2 class="text-2xl font-black text-black">{{ printSettingsStore.settings.company_name_ar || 'سفرك علينا' }}</h2>
          <p class="text-xs font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
        </div>
        <div class="text-right">
          <h1 class="text-xl font-black text-black">التقرير التفصيلي لحركات الطيران</h1>
          <p class="text-[10px] font-bold text-black mt-1">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
      </div>
      
      <div class="mt-4 text-xs text-black grid grid-cols-2 gap-4">
        <p><span class="font-black">نظام الحجز:</span> {{ getSystemLabel(filters.booking_system) }}</p>
        <p v-if="filters.from_date || filters.to_date">
          <span class="font-black">الفترة:</span> 
          من {{ filters.from_date || 'البداية' }} إلى {{ filters.to_date || 'اليوم' }}
        </p>
        <p v-if="filters.search"><span class="font-black">البحث عن:</span> {{ filters.search }}</p>
      </div>
    </div>

    <!-- Header & Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden print:hidden">
      <!-- Background Glow -->
      <div class="absolute top-0 right-0 w-64 h-64 bg-gold/10 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none"></div>
      
      <div class="relative z-10">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-l from-gold to-yellow-200 tracking-tight flex items-center gap-3">
          <Plane class="w-10 h-10 text-gold" />
          التقرير التفصيلي لحركات الطيران
        </h1>
        <p class="text-white/60 mt-2 font-medium text-lg">
          عرض تفصيلي شامل لكافة عمليات الحجز، الاسترجاع، وشحن الأرصدة للأنظمة والناقلين الجويين.
        </p>
      </div>

      <div class="flex items-center gap-3 relative z-10">
        <button 
          @click="printReport"
          class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10 font-bold"
          title="طباعة التقرير"
        >
          <Printer class="w-5 h-5" />
          <span>طباعة</span>
        </button>
      </div>
    </div>

    <!-- Filters Panel -->
    <div class="p-6 bg-card-bg border border-white/10 rounded-3xl space-y-6 print:hidden">
      <div class="flex items-center justify-between border-b border-white/5 pb-4">
        <h3 class="text-lg font-bold text-white flex items-center gap-2">
          <Filter class="w-5 h-5 text-gold" />
          خيارات الفلترة والبحث
        </h3>
        <button 
          @click="resetFilters"
          class="text-xs text-text-muted hover:text-gold transition-colors flex items-center gap-1 font-semibold"
        >
          <RotateCcw class="w-3.5 h-3.5" />
          إعادة تعيين الفلاتر
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <!-- Booking System Selection -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">نظام الحجز / الناقل</label>
          <div class="relative">
            <select 
              v-model="filters.booking_system"
              @change="fetchReport"
              class="w-full pl-10 pr-4 py-2.5 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white/80 transition-colors cursor-pointer appearance-none"
            >
              <option value="">جميع الأنظمة والناقلين</option>
              <optgroup label="أنظمة GDS / NDC">
                <option 
                  v-for="sys in flightSystems" 
                  :key="`sys_${sys.id}`" 
                  :value="`system_${sys.id}`"
                >
                  {{ sys.name }} ({{ sys.currency }})
                </option>
              </optgroup>
              <optgroup label="الناقلون الجويون">
                <option 
                  v-for="carrier in flightCarriers" 
                  :key="`carr_${carrier.id}`" 
                  :value="`carrier_${carrier.id}`"
                >
                  {{ carrier.name }} ({{ carrier.currency }})
                </option>
              </optgroup>
            </select>
            <ChevronDown class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/50 pointer-events-none" />
          </div>
        </div>

        <!-- Start Date -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">من تاريخ</label>
          <div class="relative">
            <input 
              v-model="filters.from_date"
              type="date"
              class="w-full pl-4 pr-10 py-2 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white transition-colors"
              @change="fetchReport"
            />
            <Calendar class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/40 pointer-events-none" />
          </div>
        </div>

        <!-- End Date -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">إلى تاريخ</label>
          <div class="relative">
            <input 
              v-model="filters.to_date"
              type="date"
              class="w-full pl-4 pr-10 py-2 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white transition-colors"
              @change="fetchReport"
            />
            <Calendar class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/40 pointer-events-none" />
          </div>
        </div>

        <!-- Search input -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">بحث سريع</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
            <input 
              v-model="filters.search"
              type="text"
              placeholder="رقم الحجز، PNR، الوجهة، العميل..."
              class="w-full pr-10 pl-4 py-2 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white transition-colors"
              @input="debouncedSearch"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="loadError" class="p-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 text-rose-300">
      {{ loadError }}
      <button class="mr-3 underline font-bold" @click="fetchReport">إعادة المحاولة</button>
    </div>

    <!-- Data Table Section -->
    <div class="bg-card-bg border border-white/10 rounded-3xl overflow-hidden shadow-xl">
      <!-- Loading State -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-20 gap-4">
        <Loader2 class="w-12 h-12 text-gold animate-spin" />
        <p class="text-gold font-bold animate-pulse">جاري جلب وتجميع حركات الطيران...</p>
      </div>

      <!-- Table Content -->
      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-right text-xs sm:text-sm report-table">
            <thead>
              <tr class="bg-white/5 text-[10px] sm:text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
                <th class="px-3 sm:px-4 py-4 font-bold text-right w-[9rem]">التاريخ</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نظام الحجز</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right w-[5.5rem]">الحالة</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right w-[5.5rem]">خصم</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right w-[5.5rem]">إيداع</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">رقم الحجز / PNR</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">الوجهة</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">تاريخ السفر</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">اسم العميل</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نوع العميل</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">الموظف</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نظام الدفع</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right w-[6rem]">رصيد المحفظة</th>
              </tr>
            </thead>

            <template v-if="groupedReportItems.length > 0">
              <tbody
                v-for="group in groupedReportItems"
                :key="group.key"
                class="report-booking-group"
                :class="group.isMulti ? 'report-booking-group--linked' : ''"
              >
                <tr
                  v-if="group.isMulti"
                  class="report-group-banner"
                >
                  <td colspan="13" class="px-4 py-2.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div class="flex items-center gap-2 min-w-0">
                        <span class="report-group-pill">عملية مرتبطة</span>
                        <span class="font-bold text-white truncate">
                          {{ group.primary.booking_number || group.primary.pnr || 'حركة مالية' }}
                        </span>
                        <span v-if="group.primary.route && group.primary.route !== '-'" class="text-white/50 text-[11px]">
                          {{ group.primary.route }}
                        </span>
                      </div>
                      <span class="text-[11px] font-mono text-gold/90">
                        {{ groupTotalsLabel(group) }}
                      </span>
                    </div>
                  </td>
                </tr>

                <tr
                  v-for="(item, index) in group.items"
                  :key="`${item.source_type}_${item.id}`"
                  class="report-movement-row hover:bg-white/[0.02] transition-colors"
                  :class="{
                    'report-movement-row--first': index === 0,
                    'report-movement-row--last': index === group.items.length - 1,
                    'report-movement-row--middle': group.isMulti && index > 0 && index < group.items.length - 1,
                  }"
                >
                  <td class="px-3 sm:px-4 py-3 whitespace-nowrap text-white/70 align-top">
                    <div class="flex items-start gap-2">
                      <span
                        v-if="group.isMulti"
                        class="report-step-dot"
                        :class="getStatusDotClass(item.type, item.status_ar)"
                      />
                      <span>{{ formatDate(item.created_at) }}</span>
                    </div>
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 font-bold text-white align-top bg-white/[0.015]"
                  >
                    {{ group.primary.system_name }}
                  </td>

                  <td class="px-3 sm:px-4 py-3 align-top">
                    <span
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                      :class="getStatusClass(item.type)"
                    >
                      {{ item.status_ar }}
                    </span>
                  </td>

                  <td class="px-3 sm:px-4 py-3 font-mono font-bold align-top" :class="item.debit > 0 ? 'text-rose-400' : 'text-white/30'">
                    {{ item.debit > 0 ? formatNumber(item.debit) : '—' }}
                  </td>

                  <td class="px-3 sm:px-4 py-3 font-mono font-bold align-top" :class="item.credit > 0 ? 'text-success' : 'text-white/30'">
                    {{ item.credit > 0 ? formatNumber(item.credit) : '—' }}
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 font-mono align-top bg-white/[0.015]"
                  >
                    <div class="flex flex-col gap-1">
                      <span class="text-white font-bold">{{ group.primary.pnr || '—' }}</span>
                      <span v-if="group.primary.booking_number" class="text-[10px] text-gold/80">
                        {{ group.primary.booking_number }}
                      </span>
                    </div>
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 text-white/80 align-top bg-white/[0.015]"
                  >
                    {{ group.primary.route }}
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 text-white/70 align-top bg-white/[0.015]"
                  >
                    {{ group.primary.departure_date }}
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 text-white font-semibold align-top bg-white/[0.015]"
                  >
                    {{ group.primary.customer_name }}
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 align-top bg-white/[0.015]"
                  >
                    <span
                      v-if="group.primary.customer_type !== '-'"
                      class="px-2 py-0.5 rounded text-[10px] font-semibold border"
                      :class="group.primary.customer_type === 'شركات' ? 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'"
                    >
                      {{ group.primary.customer_type }}
                    </span>
                    <span v-else class="text-white/30">—</span>
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 text-white/70 align-top bg-white/[0.015]"
                  >
                    {{ group.primary.employee_name }}
                  </td>

                  <td
                    v-if="index === 0"
                    :rowspan="group.items.length"
                    class="px-3 sm:px-4 py-3 text-white/70 align-top bg-white/[0.015]"
                  >
                    {{ group.primary.payment_system }}
                  </td>

                  <td class="px-3 sm:px-4 py-3 font-mono font-bold text-cyan-400 align-top">
                    {{ formatNumber(item.balance_after) }}
                  </td>
                </tr>
              </tbody>
            </template>

            <tbody v-else>
              <tr>
                <td colspan="13" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center justify-center gap-4">
                    <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center text-white/30">
                      <Plane class="w-8 h-8" />
                    </div>
                    <div>
                      <h4 class="text-lg font-bold text-white">لا توجد حركات طيران مطابقة للفلاتر</h4>
                      <p class="text-white/40 text-xs mt-1">قم بتغيير خيارات البحث والتاريخ أو إعادة تعيين الفلاتر.</p>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer -->
        <div v-if="pagination.last_page > 1" class="p-6 border-t border-white/10 flex items-center justify-between print:hidden">
          <div class="text-xs text-text-muted font-bold">
            عرض الصفحة {{ pagination.current_page }} من {{ pagination.last_page }} (إجمالي {{ pagination.total }} حركة)
          </div>
          <div class="flex items-center gap-2">
            <button 
              @click="changePage(pagination.current_page - 1)"
              :disabled="pagination.current_page === 1"
              class="px-3 py-1.5 rounded-lg border border-white/10 bg-white/5 text-xs font-bold text-white disabled:opacity-30 transition-colors"
            >
              السابق
            </button>
            <button 
              v-for="p in getPagesRange()" 
              :key="p"
              @click="changePage(p)"
              class="w-8 h-8 rounded-lg border text-xs font-bold transition-all"
              :class="pagination.current_page === p 
                ? 'bg-gold border-gold text-black shadow shadow-gold/25' 
                : 'border-white/10 bg-white/5 text-white hover:bg-white/10'"
            >
              {{ p }}
            </button>
            <button 
              @click="changePage(pagination.current_page + 1)"
              :disabled="pagination.current_page === pagination.last_page"
              class="px-3 py-1.5 rounded-lg border border-white/10 bg-white/5 text-xs font-bold text-white disabled:opacity-30 transition-colors"
            >
              التالي
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';
import {
  Plane,
  Printer,
  Filter,
  RotateCcw,
  Search,
  Calendar,
  Loader2,
  ChevronDown
} from 'lucide-vue-next';

const printSettingsStore = usePrintSettingsStore();

// Filters State
const filters = ref({
  booking_system: '',
  from_date: '',
  to_date: '',
  search: ''
});

const loading = ref(true);
const loadError = ref('');
const reportItems = ref([]);
const flightSystems = ref([]);
const flightCarriers = ref([]);
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0
});

let fetchController = null;
let searchTimeout = null;

const MOVEMENT_ORDER = { debit: 1, credit: 2, refund: 3 };

const detailedFlightGroupKey = (item) => {
  if (item.group_key) {
    return item.group_key;
  }
  if (item.booking_number) {
    return `booking:${item.booking_number}|${item.system_name || ''}`;
  }
  if (item.pnr) {
    return `pnr:${item.pnr}|${item.system_name || ''}`;
  }
  return `tx:${item.source_type}_${item.id}`;
};

const movementSortKey = (item) => {
  if (item.type === 'debit') return 1;
  if (item.type === 'credit' && item.status_ar === 'سداد') return 2;
  if (item.type === 'credit') return 3;
  if (item.type === 'refund') return 4;
  return 9;
};

const groupedReportItems = computed(() => {
  const map = new Map();

  for (const item of reportItems.value) {
    const key = detailedFlightGroupKey(item);
    if (!map.has(key)) {
      map.set(key, []);
    }
    map.get(key).push(item);
  }

  return Array.from(map.entries())
    .map(([key, items]) => {
      const sorted = [...items].sort((a, b) => {
        const priorityDiff = movementSortKey(a) - movementSortKey(b);
        if (priorityDiff !== 0) return priorityDiff;
        return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
      });

      const latest = Math.max(...sorted.map((row) => new Date(row.created_at).getTime()));
      const primary = sorted[0];
      const totalDebit = sorted.reduce((sum, row) => sum + Number(row.debit || 0), 0);
      const totalCredit = sorted.reduce((sum, row) => sum + Number(row.credit || 0), 0);

      return {
        key,
        items: sorted,
        primary,
        isMulti: sorted.length > 1,
        latest,
        totalDebit,
        totalCredit,
      };
    })
    .sort((a, b) => b.latest - a.latest);
});

const groupTotalsLabel = (group) => {
  const parts = [];
  if (group.totalDebit > 0) {
    parts.push(`خصم ${formatNumber(group.totalDebit)}`);
  }
  if (group.totalCredit > 0) {
    parts.push(`إيداع ${formatNumber(group.totalCredit)}`);
  }
  return parts.join(' · ');
};

// Debounced Search
const debouncedSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    pagination.value.current_page = 1;
    fetchReport();
  }, 400);
};

// Reset Filters
const resetFilters = () => {
  filters.value.booking_system = '';
  filters.value.from_date = '';
  filters.value.to_date = '';
  filters.value.search = '';
  pagination.value.current_page = 1;
  fetchReport();
};

// Change Page
const changePage = (page) => {
  if (page < 1 || page > pagination.value.last_page) return;
  pagination.value.current_page = page;
  fetchReport();
};

// Generate pagination range
const getPagesRange = () => {
  const current = pagination.value.current_page;
  const last = pagination.value.last_page;
  const range = [];
  const start = Math.max(1, current - 2);
  const end = Math.min(last, current + 2);
  for (let i = start; i <= end; i++) {
    range.push(i);
  }
  return range;
};

// Fetch list of Systems & Carriers to populate the dropdown
const fetchDropdownOptions = async () => {
  try {
    const [sysRes, carrRes] = await Promise.all([
      axios.get('/api/v1/flight/systems'),
      axios.get('/api/v1/flight/carriers')
    ]);
    if (sysRes.data?.success) {
      // Filter only active GDS systems if possible, otherwise list all
      flightSystems.value = sysRes.data.data || [];
    }
    if (carrRes.data?.success) {
      flightCarriers.value = carrRes.data.data || [];
    }
  } catch (err) {
    console.error('Failed to load system/carrier dropdown options:', err);
  }
};

// Fetch Report Data from API
const fetchReport = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      booking_system: filters.value.booking_system || undefined,
      from_date: filters.value.from_date || undefined,
      to_date: filters.value.to_date || undefined,
      search: filters.value.search || undefined,
      page: pagination.value.current_page,
      per_page: 25, // Showing 25 per page in detailed list
    };

    const response = await axios.get('/api/v1/reports/flights/detailed', { params, signal });
    if (response.data?.success) {
      const resData = response.data.data;
      reportItems.value = resData.data || [];
      pagination.value = {
        current_page: resData.current_page,
        last_page: resData.last_page,
        per_page: resData.per_page,
        total: resData.total
      };
    } else {
      throw new Error(response.data?.message || 'Failed to fetch flight report');
    }
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch detailed flight report:', error);
    loadError.value = error.response?.data?.message || 'فشل تحميل تقرير حركات الطيران التفصيلي';
    if (window.addToast) {
      window.addToast(loadError.value, 'error');
    }
  } finally {
    loading.value = false;
  }
};

// Format Date
const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  return date.toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

// Format Numbers
const formatNumber = (val) => {
  const num = Number(val);
  if (!Number.isFinite(num)) return '0.00';
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// Get status tag color classes
const getStatusClass = (type) => {
  switch (type) {
    case 'debit':
      return 'bg-error/10 text-error border border-error/20';
    case 'refund':
      return 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20';
    case 'credit':
      return 'bg-success/10 text-success border border-success/20';
    default:
      return 'bg-white/5 text-muted border border-white/10';
  }
};

const getStatusDotClass = (type, statusAr) => {
  if (type === 'debit') return 'report-step-dot--debit';
  if (type === 'refund') return 'report-step-dot--refund';
  if (statusAr === 'سداد') return 'report-step-dot--payment';
  return 'report-step-dot--credit';
};

// Dynamic labels for print page
const getSystemLabel = (val) => {
  if (!val) return 'جميع الأنظمة والناقلين';
  if (val.startsWith('system_')) {
    const id = parseInt(val.replace('system_', ''));
    const sys = flightSystems.value.find(s => s.id === id);
    return sys ? `نظام: ${sys.name}` : val;
  }
  if (val.startsWith('carrier_')) {
    const id = parseInt(val.replace('carrier_', ''));
    const carr = flightCarriers.value.find(c => c.id === id);
    return carr ? `ناقل: ${carr.name}` : val;
  }
  return val;
};

// Print
const printReport = () => {
  window.print();
};

onMounted(() => {
  fetchDropdownOptions().then(() => {
    fetchReport();
  });
  printSettingsStore.fetch().catch(() => {});
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}
.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

.report-booking-group {
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.report-booking-group--linked {
  background: linear-gradient(90deg, rgba(212, 168, 67, 0.06) 0%, rgba(255, 255, 255, 0.01) 28%);
  box-shadow: inset 3px 0 0 rgba(212, 168, 67, 0.55);
}

.report-group-banner {
  background: rgba(212, 168, 67, 0.08);
  border-bottom: 1px dashed rgba(212, 168, 67, 0.25);
}

.report-group-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 9999px;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #d4a843;
  background: rgba(212, 168, 67, 0.12);
  border: 1px solid rgba(212, 168, 67, 0.25);
  white-space: nowrap;
}

.report-movement-row--middle td,
.report-movement-row--last td {
  border-top: 1px dashed rgba(255, 255, 255, 0.06);
}

.report-step-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 9999px;
  margin-top: 0.35rem;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.06);
}

.report-step-dot--debit {
  background: #fb7185;
}

.report-step-dot--payment {
  background: #34d399;
}

.report-step-dot--credit {
  background: #60a5fa;
}

.report-step-dot--refund {
  background: #a78bfa;
}
</style>

<style>
@media print {
  body, html, #app, .app-shell, .main-zone, .page-body {
    height: auto !important;
    min-height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    position: static !important;
    display: block !important;
    width: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #000000 !important;
  }
  
  .sidebar, .top-bar, .toast-rack, .backdrop {
    display: none !important;
  }

  * {
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
  }

  table {
    width: 100% !important;
    border-collapse: collapse !important;
    border: 1px solid #000000 !important;
    font-size: 10px !important; /* Smaller size to fit all columns on A4 landscape */
  }

  th {
    background-color: #f3f4f6 !important;
    color: #000000 !important;
    font-weight: bold !important;
    border: 1px solid #000000 !important;
    padding: 6px 4px !important;
  }

  td {
    color: #000000 !important;
    border: 1px solid #000000 !important;
    padding: 6px 4px !important;
  }

  td * {
    color: #000000 !important;
  }
}
</style>
