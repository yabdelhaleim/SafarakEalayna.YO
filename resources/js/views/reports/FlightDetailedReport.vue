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
          <table class="w-full border-collapse text-right text-xs sm:text-sm">
            <thead>
              <tr class="bg-white/5 text-[10px] sm:text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
                <th class="px-3 sm:px-4 py-4 font-bold text-right">التاريخ</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نظام الحجز</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">الحالة</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">خصم</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">إيداع</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">رقم الحجز / PNR</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">الوجهة</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">تاريخ السفر</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">اسم العميل</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نوع العميل</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">الموظف</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">نظام الدفع</th>
                <th class="px-3 sm:px-4 py-4 font-bold text-right">رصيد المحفظة</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr 
                v-for="item in reportItems" 
                :key="`${item.source_type}_${item.id}`"
                class="hover:bg-white/[0.02] transition-colors group"
              >
                <!-- Date -->
                <td class="px-3 sm:px-4 py-4 whitespace-nowrap text-white/70">
                  {{ formatDate(item.created_at) }}
                </td>

                <!-- Booking System -->
                <td class="px-3 sm:px-4 py-4 font-bold text-white group-hover:text-gold transition-colors">
                  {{ item.system_name }}
                </td>

                <!-- Status -->
                <td class="px-3 sm:px-4 py-4">
                  <span 
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                    :class="getStatusClass(item.type)"
                  >
                    {{ item.status_ar }}
                  </span>
                </td>

                <!-- Debit -->
                <td class="px-3 sm:px-4 py-4 font-mono font-bold" :class="item.debit > 0 ? 'text-rose-400' : 'text-white/30'">
                  {{ item.debit > 0 ? formatNumber(item.debit) : '—' }}
                </td>

                <!-- Credit -->
                <td class="px-3 sm:px-4 py-4 font-mono font-bold" :class="item.credit > 0 ? 'text-success' : 'text-white/30'">
                  {{ item.credit > 0 ? formatNumber(item.credit) : '—' }}
                </td>

                <!-- PNR / Booking Number -->
                <td class="px-3 sm:px-4 py-4 font-mono">
                  <div class="flex flex-col">
                    <span class="text-white font-bold">{{ item.pnr || '—' }}</span>
                    <span v-if="item.booking_number" class="text-[10px] text-white/40">{{ item.booking_number }}</span>
                  </div>
                </td>

                <!-- Route -->
                <td class="px-3 sm:px-4 py-4 text-white/80">
                  {{ item.route }}
                </td>

                <!-- Departure Date -->
                <td class="px-3 sm:px-4 py-4 text-white/70">
                  {{ item.departure_date }}
                </td>

                <!-- Customer Name -->
                <td class="px-3 sm:px-4 py-4 text-white font-semibold">
                  {{ item.customer_name }}
                </td>

                <!-- Customer Type -->
                <td class="px-3 sm:px-4 py-4">
                  <span 
                    v-if="item.customer_type !== '-'"
                    class="px-2 py-0.5 rounded text-[10px] font-semibold border"
                    :class="item.customer_type === 'شركات' ? 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'"
                  >
                    {{ item.customer_type }}
                  </span>
                  <span v-else class="text-white/30">—</span>
                </td>

                <!-- Employee -->
                <td class="px-3 sm:px-4 py-4 text-white/70">
                  {{ item.employee_name }}
                </td>

                <!-- Payment System -->
                <td class="px-3 sm:px-4 py-4 text-white/70">
                  {{ item.payment_system }}
                </td>

                <!-- Wallet Balance -->
                <td class="px-3 sm:px-4 py-4 font-mono font-bold text-cyan-400">
                  {{ formatNumber(item.balance_after) }}
                </td>
              </tr>

              <!-- Empty State -->
              <tr v-if="reportItems.length === 0">
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
import { ref, onMounted, onBeforeUnmount } from 'vue';
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
