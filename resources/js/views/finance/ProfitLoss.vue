<template>
  <div class="space-y-6 print:space-y-4">
    <!-- Professional Print Header (Visible only on print) -->
    <div class="hidden print:block print:mb-8">
      <div class="flex items-center justify-between border-b-2 border-black pb-4">
        <div>
          <h2 class="text-2xl font-black text-black">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا' }}</h2>
          <p class="text-xs font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
        </div>
        <div class="text-right">
          <h1 class="text-xl font-black text-black">بيان الأرباح والخسائر التفصيلي (P&L)</h1>
          <p class="text-[10px] font-bold text-black mt-1">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
      </div>
      
      <div class="mt-4 grid grid-cols-2 gap-4 text-xs text-black">
        <div>
          <p><span class="font-black">الفترة الزمنية:</span> من {{ filters.from || 'البداية' }} إلى {{ filters.to || 'اليوم' }}</p>
        </div>
        <div class="text-right">
          <p><span class="font-black">القسم المالي:</span> {{ filters.category === 'tourism' ? 'قسم السياحة فقط' : (filters.category === 'office' ? 'قسم المكتب فقط' : 'كل الأقسام والموديولات') }}</p>
          <p v-if="filters.module !== 'all'"><span class="font-black">الموديول المالي:</span> {{ filters.module === 'flight' ? 'الطيران' : (filters.module === 'hajj_umra' ? 'الحج والعمرة' : (filters.module === 'visa' ? 'التأشيرات' : (filters.module === 'bus' ? 'الباص' : (filters.module === 'fawry' ? 'فوري' : (filters.module === 'online' ? 'الخدمات الإلكترونية' : filters.module))))) }}</p>
        </div>
      </div>
    </div>

    <!-- Header -->
    <div v-if="globalError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-xl mb-4 print:hidden">
      {{ globalError }}
    </div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 print:hidden">
      <div>
        <h1 class="text-3xl font-display font-black text-transparent bg-clip-text bg-gradient-to-l from-indigo-400 to-blue-600 flex items-center gap-3">
          <BarChart class="w-10 h-10 text-indigo-500" />
          بيان الأرباح والخسائر
        </h1>
        <p class="text-sm text-text-muted mt-2 font-medium">
          تقرير مالي متكامل يوضح إيراداتك ومصروفاتك وصافي الربح للمدة المحددة (Income Statement).
        </p>
      </div>

      <!-- Date Range Filter -->
      <div class="flex flex-col sm:flex-row gap-2">
        <select 
          v-model="filters.category"
          @change="filters.module = 'all'; fetchReport()"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors"
        >
          <option value="all">كل الأقسام (شامل)</option>
          <option value="tourism">قسم السياحة فقط</option>
          <option value="office">قسم المكتب فقط</option>
        </select>
        
        <select 
          v-if="filters.category !== 'all'"
          v-model="filters.module"
          @change="fetchReport"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors shadow-inner"
        >
          <option value="all">كل الموديولات</option>
          <template v-if="filters.category === 'tourism'">
            <option value="flight">الطيران فقط</option>
            <option value="hajj_umra">الحج والعمرة فقط</option>
            <option value="visa">التأشيرات فقط</option>
          </template>
          <template v-if="filters.category === 'office'">
            <option value="bus">الباص فقط</option>
            <option value="fawry">فوري فقط</option>
            <option value="online">الخدمات الإلكترونية فقط</option>
            <option value="wallet">المحافظ والتحويلات فقط</option>
            <option value="general">الإدارة العامة فقط</option>
          </template>
        </select>
        
        <input 
          type="date" 
          v-model="filters.from"
          @change="fetchReport"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors"
        />
        <input 
          type="date" 
          v-model="filters.to"
          @change="fetchReport"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors"
        />
        <button 
          @click="printReport"
          class="bg-rose-600 hover:bg-rose-500 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-lg shadow-rose-600/20 transition-all flex items-center justify-center gap-2 print:hidden"
        >
          <FileText class="w-4 h-4" />
          تصدير PDF
        </button>
        <button 
          @click="printReport"
          class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-lg shadow-indigo-600/20 transition-all flex items-center justify-center gap-2 print:hidden"
        >
          <Printer class="w-4 h-4" />
          طباعة
        </button>
        <button 
          @click="fetchReport"
          :disabled="isLoading()"
          class="bg-indigo-500 hover:bg-indigo-400 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-lg shadow-indigo-500/20 transition-all flex items-center justify-center gap-2 disabled:opacity-50"
        >
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': isLoading() }" />
          تحديث
        </button>
      </div>
    </div>

    <!-- Main KPIs -->
    <div v-if="isLoading()" class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <KPICardSkeleton v-for="i in 3" :key="`pl-kpi-${i}`" />
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <!-- Total Revenues -->
      <div class="bg-card-bg border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-emerald-500/5 rounded-full blur-xl"></div>
        <div class="flex justify-between items-start mb-4 relative z-10">
          <div>
            <p class="text-sm font-medium text-text-muted">إجمالي الإيرادات (المبيعات)</p>
            <h3 class="text-2xl font-bold text-white mt-1">{{ formatCurrency(reportData.totalRevenues) }}</h3>
          </div>
          <div class="w-10 h-10 bg-emerald-500/10 rounded-xl flex items-center justify-center">
            <TrendingUp class="w-5 h-5 text-emerald-400" />
          </div>
        </div>
        <p class="text-xs text-text-muted relative z-10">إجمالي المبالغ المحصلة من الخدمات</p>
      </div>

      <!-- Total Expenses & COGS -->
      <div class="bg-card-bg border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-rose-500/5 rounded-full blur-xl"></div>
        <div class="flex justify-between items-start mb-4 relative z-10">
          <div>
            <p class="text-sm font-medium text-text-muted">إجمالي المصروفات والتكلفة</p>
            <h3 class="text-2xl font-bold text-white mt-1">{{ formatCurrency(reportData.totalExpenses + reportData.totalCogs) }}</h3>
          </div>
          <div class="w-10 h-10 bg-rose-500/10 rounded-xl flex items-center justify-center">
            <TrendingDown class="w-5 h-5 text-rose-400" />
          </div>
        </div>
        <p class="text-xs text-text-muted relative z-10">تكلفة المبيعات + مصروفات التشغيل</p>
      </div>

      <!-- Net Profit -->
      <div class="bg-gradient-to-br from-indigo-600 to-blue-700 p-6 rounded-2xl shadow-2xl relative overflow-hidden">
        <div class="absolute -right-8 -bottom-8 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
        <div class="flex justify-between items-start mb-4 relative z-10">
          <div>
            <p class="text-sm font-medium text-white/80">صافي الربح / الخسارة</p>
            <h3 class="text-3xl font-display font-black text-white mt-1">{{ formatCurrency(netProfit) }}</h3>
          </div>
          <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
            <Scale class="w-5 h-5 text-white" />
          </div>
        </div>
        <div class="relative z-10">
          <span 
            class="text-xs font-bold px-2 py-1 rounded-md"
            :class="netProfit >= 0 ? 'bg-emerald-400/20 text-emerald-100' : 'bg-rose-400/20 text-rose-100'"
          >
            {{ netProfit >= 0 ? 'ربح صافي ممتاز 🚀' : 'خسارة محققة ⚠️' }}
          </span>
        </div>
      </div>
    </div>

    <!-- Details View (Statement format) -->
    <div class="bg-card-bg border border-white/5 rounded-2xl shadow-xl overflow-hidden">
      <div class="p-6 border-b border-white/5">
        <h2 class="text-lg font-bold text-white">تفاصيل قائمة الدخل (Income Statement)</h2>
      </div>

      <div v-if="isLoading()" class="p-12 space-y-8">
        <TextLineSkeleton :lines="3" heightClass="h-24" gapClass="gap-4" />
      </div>

      <div v-else-if="state === 'error'" class="p-12 text-center text-rose-400 flex flex-col items-center justify-center">
        حدث خطأ في عرض التقرير.
      </div>

      <div v-else class="p-6 font-mono text-sm space-y-8">
        
        <!-- Revenues Section -->
        <div class="bg-gradient-to-br from-emerald-500/5 to-emerald-500/10 border border-emerald-500/20 rounded-2xl p-6 relative overflow-hidden">
          <div class="absolute -right-8 -top-8 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl"></div>
          <h3 class="text-emerald-400 font-bold mb-5 border-b border-emerald-500/20 pb-3 text-xl font-sans flex items-center gap-2 relative z-10">
            <TrendingUp class="w-6 h-6" />
            الإيرادات (Revenues)
          </h3>
          <div class="space-y-3 pr-4 relative z-10">
            <div v-for="(rev, index) in reportData.revenuesList" :key="index" class="flex justify-between items-center bg-white/5 p-3 rounded-xl border border-white/5 hover:border-emerald-500/30 transition-all">
              <span class="text-white/90 font-medium">{{ rev.name }}</span>
              <span class="text-emerald-400 font-bold font-display">{{ formatCurrency(rev.amount) }}</span>
            </div>
            <div v-if="reportData.revenuesList.length === 0" class="text-center py-4 text-emerald-400/50 text-sm">
              لا توجد إيرادات مسجلة في هذه الفترة
            </div>
          </div>
          <div class="flex justify-between font-black text-white mt-5 border-t border-emerald-500/30 pt-4 text-lg relative z-10">
            <span>إجمالي الإيرادات</span>
            <span class="text-emerald-400">{{ formatCurrency(reportData.totalRevenues) }}</span>
          </div>
        </div>

        <!-- COGS Section -->
        <div class="bg-gradient-to-br from-orange-500/5 to-orange-500/10 border border-orange-500/20 rounded-2xl p-6 relative overflow-hidden">
          <h3 class="text-orange-400 font-bold mb-5 border-b border-orange-500/20 pb-3 text-xl font-sans flex items-center gap-2 relative z-10">
            <Coins class="w-6 h-6" />
            تكلفة المبيعات (Cost of Goods Sold - COGS)
          </h3>
          <div class="space-y-3 pr-4 relative z-10">
            <div v-for="(cogs, index) in reportData.cogsList" :key="index" class="flex justify-between items-center bg-white/5 p-3 rounded-xl border border-white/5 hover:border-orange-500/30 transition-all">
              <span class="text-white/90 font-medium">{{ cogs.name }}</span>
              <span class="text-orange-400 font-bold font-display">{{ formatCurrency(cogs.amount) }}</span>
            </div>
            <div v-if="reportData.cogsList.length === 0" class="text-center py-4 text-orange-400/50 text-sm">
              لا توجد تكاليف مبيعات مسجلة في هذه الفترة
            </div>
          </div>
          <div class="flex justify-between font-black text-white mt-5 border-t border-orange-500/30 pt-4 text-lg relative z-10">
            <span>إجمالي تكلفة المبيعات</span>
            <span class="text-orange-400">{{ formatCurrency(reportData.totalCogs) }}</span>
          </div>
        </div>

        <!-- Gross Profit -->
        <div class="bg-indigo-500/10 border border-indigo-500/30 p-5 rounded-2xl flex justify-between items-center font-bold text-xl text-indigo-300 shadow-lg shadow-indigo-500/5">
          <div class="flex items-center gap-3">
            <div class="w-2 h-8 bg-indigo-500 rounded-full"></div>
            <span>مجمل الربح (Gross Profit)</span>
          </div>
          <span class="font-display font-black tracking-wide">{{ formatCurrency(grossProfit) }}</span>
        </div>

        <!-- Refunds Section -->
        <div
          v-if="reportData.refundsList?.length > 0"
          class="bg-gradient-to-br from-amber-500/5 to-amber-500/10 border border-amber-500/20 rounded-2xl p-6 relative overflow-hidden"
        >
          <h3 class="text-amber-400 font-bold mb-5 border-b border-amber-500/20 pb-3 text-xl font-sans">
            المردودات والإلغاءات (Refunds)
          </h3>
          <div class="space-y-3 pr-4">
            <div
              v-for="(item, index) in reportData.refundsList"
              :key="`refund-${index}`"
              class="flex justify-between items-center bg-white/5 p-3 rounded-xl border border-white/5"
            >
              <span class="text-white/90 font-medium">{{ item.name }}</span>
              <span class="text-amber-400 font-bold font-display">{{ formatCurrency(item.amount) }}</span>
            </div>
          </div>
          <div class="flex justify-between font-black text-white mt-5 border-t border-amber-500/30 pt-4 text-lg">
            <span>إجمالي المردودات</span>
            <span class="text-amber-400">{{ formatCurrency(reportData.totalRefunds) }}</span>
          </div>
        </div>

        <!-- Expenses Section -->
        <div class="bg-gradient-to-br from-rose-500/5 to-rose-500/10 border border-rose-500/20 rounded-2xl p-6 relative overflow-hidden">
          <div class="absolute -left-8 -bottom-8 w-32 h-32 bg-rose-500/10 rounded-full blur-2xl"></div>
          <h3 class="text-rose-400 font-bold mb-5 border-b border-rose-500/20 pb-3 text-xl font-sans flex items-center gap-2 relative z-10">
            <TrendingDown class="w-6 h-6" />
            المصروفات التشغيلية والعمومية (Operating Expenses)
          </h3>
          <div class="space-y-3 pr-4 relative z-10">
            <div v-for="(exp, index) in reportData.expensesList" :key="index" class="flex justify-between items-center bg-white/5 p-3 rounded-xl border border-white/5 hover:border-rose-500/30 transition-all">
              <span class="text-white/90 font-medium">{{ exp.name }}</span>
              <span class="text-rose-400 font-bold font-display">{{ formatCurrency(exp.amount) }}</span>
            </div>
            <div v-if="reportData.expensesList.length === 0" class="text-center py-4 text-rose-400/50 text-sm">
              لا توجد مصروفات مسجلة في هذه الفترة
            </div>
          </div>
          <div class="flex justify-between font-black text-white mt-5 border-t border-rose-500/30 pt-4 text-lg relative z-10">
            <span>إجمالي المصروفات</span>
            <span class="text-rose-400">{{ formatCurrency(reportData.totalExpenses) }}</span>
          </div>
        </div>

        <!-- Net Profit Final -->
        <div class="bg-gradient-to-r from-indigo-600 to-blue-700 p-8 rounded-2xl flex flex-col md:flex-row justify-between items-center font-bold text-2xl text-white mt-8 shadow-2xl shadow-indigo-500/20 border border-white/10 relative overflow-hidden">
          <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10"></div>
          <div class="flex items-center gap-4 relative z-10">
            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
              <Scale class="w-7 h-7 text-white" />
            </div>
            <span class="text-2xl">صافي الربح قبل الضرائب (Net Income)</span>
          </div>
          <div class="relative z-10 flex flex-col items-end mt-4 md:mt-0">
            <span class="text-4xl font-display font-black tracking-wide drop-shadow-md" :class="netProfit >= 0 ? 'text-emerald-300' : 'text-rose-300'">
              {{ formatCurrency(netProfit) }}
            </span>
            <span v-if="netProfit >= 0" class="text-xs text-emerald-100 bg-emerald-500/30 px-2 py-1 rounded mt-2">رصيد إيجابي 📈</span>
            <span v-else class="text-xs text-rose-100 bg-rose-500/30 px-2 py-1 rounded mt-2">عجز (خسارة) 📉</span>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const printSettingsStore = usePrintSettingsStore();
import {
  BarChart,
  RefreshCw,
  TrendingUp,
  TrendingDown,
  Scale,
  Printer,
  FileText
} from 'lucide-vue-next';
import axios from 'axios';
import { useAsyncState } from '@/composables/useAsyncState';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';

const globalError = ref('');
let fetchController = null;

const { state, setLoading, setSuccess, setEmpty, setError, isLoading, isSuccess, isEmpty } = useAsyncState('loading');

const filters = ref({
  from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0], // First day of current month
  to: new Date().toISOString().split('T')[0], // Today
  category: 'all',
  module: 'all'
});

// Mocked initial structure, awaiting backend data
const reportData = ref({
  totalRevenues: 0,
  totalCogs: 0,
  totalExpenses: 0,
  totalRefunds: 0,
  grossProfit: 0,
  netProfit: 0,
  revenuesList: [],
  cogsList: [],
  expensesList: [],
  refundsList: [],
});

const netProfit = computed(() => {
  if (typeof reportData.value.netProfit === 'number') {
    return reportData.value.netProfit;
  }
  return reportData.value.totalRevenues - reportData.value.totalCogs - reportData.value.totalExpenses;
});

const grossProfit = computed(() => {
  if (typeof reportData.value.grossProfit === 'number') {
    return reportData.value.grossProfit;
  }
  return reportData.value.totalRevenues - reportData.value.totalCogs;
});

const formatCurrency = (val) => {
  if (!val && val !== 0) return '0.00 EGP';
  return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' EGP';
};

const fetchReport = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();

  setLoading();
  globalError.value = '';
  reportData.value = {
    totalRevenues: 0,
    totalCogs: 0,
    totalExpenses: 0,
    totalRefunds: 0,
    grossProfit: 0,
    netProfit: 0,
    revenuesList: [],
    cogsList: [],
    expensesList: [],
    refundsList: [],
  };

  try {
    const params = {
      from_date: filters.value.from,
      to_date: filters.value.to,
      category: filters.value.category,
      module: filters.value.module,
      _t: Date.now(),
    };
    const res = await axios.get('/api/v1/reports/profit-loss', {
      params,
      signal: fetchController.signal,
    });

    if (res?.data?.data) {
      reportData.value = res.data.data;
      setSuccess();
    } else {
      throw new Error('Invalid response format');
    }
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    globalError.value = error.response?.data?.message
      || 'حدث خطأ في جلب تقرير الأرباح والخسائر. تأكد من صحة التواريخ.';
    setError(error);
  }
};

const printReport = () => {
  window.print();
};

onMounted(() => {
  fetchReport();
  printSettingsStore.fetch().catch(() => {});
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
});
</script>

<style scoped>
/* Scoped styles here if needed */
.bg-card-bg {
  background-color: var(--card-bg);
}
.bg-input-bg {
  background-color: var(--input-bg);
}
.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>

<style>
@media print {
  /* Reset layout constraints and color for printing */
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

  .grid-cols-3 > div,
  .bg-card-bg {
    background: #ffffff !important;
    background-color: #ffffff !important;
    border: 1px solid #000000 !important;
    color: #000000 !important;
    box-shadow: none !important;
    border-radius: 12px !important;
  }

  /* Force white background and black text on all KPI cards */
  .grid-cols-3 > div *,
  .bg-card-bg * {
    color: #000000 !important;
  }

  .text-emerald-400,
  .text-emerald-300 {
    color: #166534 !important; /* Forest green */
  }

  .text-rose-400,
  .text-rose-300 {
    color: #991b1b !important; /* Crimson */
  }

  .text-orange-400 {
    color: #b45309 !important; /* Amber/gold */
  }

  .text-indigo-400,
  .text-indigo-300 {
    color: #1e3a8a !important; /* Navy */
  }

  /* List entries container */
  .space-y-8 > div {
    background: #ffffff !important;
    border: 1px solid #000000 !important;
    padding: 16px !important;
    border-radius: 12px !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
  }

  .space-y-8 > div h3 {
    color: #000000 !important;
    border-bottom: 2px solid #000000 !important;
  }
  
  .space-y-8 > div h3 * {
    color: #000000 !important;
  }

  .bg-white\/5 {
    background-color: #f3f4f6 !important; /* light grey for print */
    border: 1px solid #d1d5db !important;
  }

  .bg-indigo-500\/10 {
    background-color: #f3f4f6 !important;
    border: 1px solid #000000 !important;
  }

  .bg-gradient-to-r {
    background: #f3f4f6 !important;
    color: #000000 !important;
    border: 2px solid #000000 !important;
  }

  .bg-gradient-to-r * {
    color: #000000 !important;
  }
}
</style>
