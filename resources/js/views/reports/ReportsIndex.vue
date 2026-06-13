<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-12 print:space-y-6">
    <!-- Professional Print Header (Visible only on print) -->
    <div class="hidden print:block print:mb-8">
      <div class="flex items-center justify-between border-b-2 border-black pb-4">
        <div>
          <h2 class="text-2xl font-black text-black">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا' }}</h2>
          <p class="text-xs font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
        </div>
        <div class="text-right">
          <h1 class="text-xl font-black text-black">مركز التقارير والقيادة الموحد</h1>
          <p class="text-[10px] font-bold text-black mt-1">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
      </div>
      
      <div class="mt-4 text-xs text-black">
        <p><span class="font-black">الفترة الزمنية للتقرير:</span> من {{ filters.from_date || 'البداية' }} إلى {{ filters.to_date || 'اليوم' }}</p>
      </div>
    </div>

    <!-- Header with Date Filters -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden print:hidden">
      <!-- Background Glow -->
      <div class="absolute top-0 right-0 w-64 h-64 bg-gold/10 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none"></div>
      
      <div class="relative z-10">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-l from-gold to-yellow-200 tracking-tight flex items-center gap-3">
          <PieChart class="w-10 h-10 text-gold" />
          مركز التقارير والقيادة
        </h1>
        <p class="text-white/60 mt-2 font-medium text-lg">
          المدخل الرئيسي والوحيد للإدارة العليا، يتيح رؤية بانورامية لكل العمليات والأموال.
        </p>
      </div>

      <div class="flex items-center gap-3 relative z-10">
        <button 
          @click="printReport"
          class="p-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10"
          title="طباعة التقرير"
        >
          <Printer class="w-5 h-5" />
        </button>
        <input 
          type="date" 
          v-model="filters.from_date"
          @change="fetchDashboardData"
          class="bg-black/40 border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:border-gold transition-colors backdrop-blur-md"
        />
        <span class="text-white/40">إلى</span>
        <input 
          type="date" 
          v-model="filters.to_date"
          @change="fetchDashboardData"
          class="bg-black/40 border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:border-gold transition-colors backdrop-blur-md"
        />
      </div>
    </div>

    <div v-if="loadError" class="p-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 text-rose-300 print:hidden">
      {{ loadError }}
      <button class="mr-3 underline font-bold" @click="fetchDashboardData">إعادة المحاولة</button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-20">
      <div class="flex flex-col items-center gap-4">
        <Loader2 class="w-12 h-12 text-gold animate-spin" />
        <p class="text-gold font-bold animate-pulse">جاري تجميع البيانات الاستراتيجية...</p>
      </div>
    </div>

    <template v-else>
      <!-- Master KPIs (Top Level) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Net Profit -->
        <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-success/40 transition-all overflow-hidden cursor-default">
          <div class="absolute inset-0 bg-gradient-to-br from-success/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
          <div class="relative z-10 flex justify-between items-start mb-4">
            <div class="p-4 bg-success/10 rounded-2xl text-success group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 shadow-lg shadow-success/20">
              <TrendingUp class="w-7 h-7" />
            </div>
          </div>
          <div class="relative z-10">
            <div class="text-sm text-white/50 uppercase tracking-widest mb-1 font-bold">صافي الأرباح (Net Profit)</div>
            <div class="text-3xl font-bold font-mono text-white group-hover:text-success transition-colors">
              {{ formatCurrency(finData.net_profit) }}
            </div>
          </div>
        </div>

        <!-- Total Revenue -->
        <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-blue-400/40 transition-all overflow-hidden cursor-default">
          <div class="absolute inset-0 bg-gradient-to-br from-blue-400/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
          <div class="relative z-10 flex justify-between items-start mb-4">
            <div class="p-4 bg-blue-500/10 rounded-2xl text-blue-400 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 shadow-lg shadow-blue-500/20">
              <ArrowUpRight class="w-7 h-7" />
            </div>
          </div>
          <div class="relative z-10">
            <div class="text-sm text-white/50 uppercase tracking-widest mb-1 font-bold">إجمالي الإيرادات (Total Revenue)</div>
            <div class="text-3xl font-bold font-mono text-white group-hover:text-blue-400 transition-colors">
              {{ formatCurrency(finData.total_income) }}
            </div>
          </div>
        </div>

        <!-- Total Expenses -->
        <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-rose-400/40 transition-all overflow-hidden cursor-default">
          <div class="absolute inset-0 bg-gradient-to-br from-rose-400/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
          <div class="relative z-10 flex justify-between items-start mb-4">
            <div class="p-4 bg-rose-500/10 rounded-2xl text-rose-400 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300 shadow-lg shadow-rose-500/20">
              <ArrowDownRight class="w-7 h-7" />
            </div>
          </div>
          <div class="relative z-10">
            <div class="text-sm text-white/50 uppercase tracking-widest mb-1 font-bold">تكاليف ومصروفات (COGS + OpEx)</div>
            <div class="text-3xl font-bold font-mono text-white group-hover:text-rose-400 transition-colors">
              {{ formatCurrency(finData.total_expense) }}
            </div>
            <p v-if="finData.total_cogs" class="text-[11px] text-white/40 mt-2 font-mono">
              تكاليف: {{ formatCurrency(finData.total_cogs) }} · تشغيل: {{ formatCurrency(finData.total_operating_expenses) }}
            </p>
          </div>
        </div>

        <!-- Treasury Balance -->
        <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-gold/40 transition-all overflow-hidden cursor-default">
          <div class="absolute inset-0 bg-gradient-to-br from-gold/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
          <div class="relative z-10 flex justify-between items-start mb-4">
            <div class="p-4 bg-gold/10 rounded-2xl text-gold group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-gold/20">
              <Wallet class="w-7 h-7" />
            </div>
            <span class="text-xs font-bold px-3 py-1 rounded-full bg-gold/10 text-gold border border-gold/20">السيولة الحالية</span>
          </div>
          <div class="relative z-10">
            <div class="text-sm text-white/50 uppercase tracking-widest mb-1 font-bold">رصيد الخزائن والبنوك (Treasury)</div>
            <div class="text-3xl font-bold font-mono text-gold transition-colors drop-shadow-[0_0_8px_rgba(255,215,0,0.5)]">
              {{ formatCurrency(accountsData.grand_total) }}
            </div>
          </div>
        </div>
      </div>

      <!-- Main Hub Navigation (The Portals) -->
      <h2 class="text-2xl font-bold text-white mt-10 mb-6 flex items-center gap-2 print:hidden">
        <Compass class="w-6 h-6 text-gold" />
        مداخل التقارير التفصيلية
      </h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 print:hidden">
        
        <!-- P&L Portal -->
        <router-link to="/finance/profit-loss" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/80 to-blue-900/90 z-0"></div>
          <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCI+PHBhdGggZD0iTTAgMGg0MHY0MEgwem0yMCAyMGMxMSAwIDIwLTkgMjAtMjBoLTQwYzAgMTEgOSAyMCAyMCAyMHoiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4wMykiIGZpbGwtcnVsZT0iZXZlbm9kZCIvPjwvc3ZnPg==')] opacity-30 z-0 mix-blend-overlay"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <FileSpreadsheet class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-gold transition-colors">قائمة الدخل (P&L)</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                التقرير المالي الأكثر أهمية. يعرض تفاصيل الإيرادات، وتكلفة المبيعات، والأرباح لكل موديول.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-gold" />
          </div>
        </router-link>

        <!-- Expenses Portal -->
        <router-link to="/finance/expenses" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-rose-600/80 to-red-900/90 z-0"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <Receipt class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-rose-200 transition-colors">المصروفات</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                مراقبة دقيقة لكل المصروفات التشغيلية والعمومية وتصنيفاتها، وسحب الأموال.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-white" />
          </div>
        </router-link>

        <!-- Operations Ledger Portal -->
        <router-link to="/finance/operations/ledger" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-violet-600/80 to-purple-900/90 z-0"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <Activity class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-violet-200 transition-colors">شحن الأنظمة والتحويلات</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                تفاصيل شحن GDS والناقلين وفوري، استهلاك التكلفة، والتحويلات بين موديولات الخزينة.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-gold" />
          </div>
        </router-link>

        <!-- Treasury Portal -->
        <router-link to="/finance/treasury" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-emerald-600/80 to-teal-900/90 z-0"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <Landmark class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-emerald-200 transition-colors">الخزينة العامة</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                مراقبة حركة الأموال (كاش، بنك، محافظ) الداخلة والخارجة، والتحويلات الداخلية.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-white" />
          </div>
        </router-link>

        <!-- HR Portal -->
        <router-link to="/employees" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-amber-600/80 to-orange-900/90 z-0"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <Users class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-amber-200 transition-colors">تقارير الموارد البشرية</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                إنتاجية الموظفين، المكافآت، السلف، وسجلات الحضور والغياب.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-white" />
          </div>
        </router-link>

        <!-- Debts & Receivables Portal -->
        <router-link to="/reports/debts" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
          <div class="absolute inset-0 bg-gradient-to-br from-purple-600/80 to-fuchsia-900/90 z-0"></div>
          <div class="relative z-10 p-6 flex flex-col h-full justify-between">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 group-hover:scale-110 transition-transform shadow-xl">
              <Scale class="w-7 h-7 text-white" />
            </div>
            <div>
              <h3 class="text-2xl font-extrabold text-white mb-2 group-hover:text-purple-200 transition-colors">الديون والمديونيات</h3>
              <p class="text-white/70 text-sm font-medium leading-relaxed">
                متابعة أرصدة العملاء والموردين وشركات الطيران والباصات والوكلاء وتصفيتها.
              </p>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 opacity-0 group-hover:opacity-100 group-hover:-translate-x-2 transition-all">
            <ArrowLeft class="w-6 h-6 text-white" />
          </div>
        </router-link>

      </div>

      <!-- Quick Distribution Analytics -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6 print:grid-cols-1 print:gap-4 print:mt-4">
        <!-- Treasuries Mini-Breakdown -->
        <div class="bg-card-bg border border-white/10 rounded-3xl p-8 print:w-full print:border-black print:bg-white print:text-black print:p-6 print:rounded-2xl">
          <h3 class="text-lg font-bold text-white mb-6 flex items-center gap-2 print:text-black print:mb-4">
            <Building2 class="w-5 h-5 text-indigo-400 print:text-black" />
            توزيع السيولة المالية
          </h3>
          <div class="space-y-6 print:space-y-4">
            <div>
              <div class="flex justify-between text-sm mb-2 print:text-black">
                <span class="text-white/70 print:text-black">النقدية (Cashbox)</span>
                <span class="font-mono font-bold text-white print:text-black">{{ formatCurrency(accountsData.total_cashbox) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden print:bg-gray-200">
                <div class="h-full bg-emerald-500 rounded-full print:bg-black" :style="{ width: getPercentage(accountsData.total_cashbox, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2 print:text-black">
                <span class="text-white/70 print:text-black">البنوك (Banks)</span>
                <span class="font-mono font-bold text-white print:text-black">{{ formatCurrency(accountsData.total_bank) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden print:bg-gray-200">
                <div class="h-full bg-blue-500 rounded-full print:bg-black" :style="{ width: getPercentage(accountsData.total_bank, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2 print:text-black">
                <span class="text-white/70 print:text-black">المحافظ الإلكترونية (Wallets)</span>
                <span class="font-mono font-bold text-white print:text-black">{{ formatCurrency(accountsData.total_wallet) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden print:bg-gray-200">
                <div class="h-full bg-purple-500 rounded-full print:bg-black" :style="{ width: getPercentage(accountsData.total_wallet, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div v-if="accountsData.total_treasury">
              <div class="flex justify-between text-sm mb-2 print:text-black">
                <span class="text-white/70 print:text-black">خزائن عامة (Treasury)</span>
                <span class="font-mono font-bold text-white print:text-black">{{ formatCurrency(accountsData.total_treasury) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden print:bg-gray-200">
                <div class="h-full bg-amber-500 rounded-full print:bg-black" :style="{ width: getPercentage(accountsData.total_treasury, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div v-if="accountsData.total_post">
              <div class="flex justify-between text-sm mb-2 print:text-black">
                <span class="text-white/70 print:text-black">حسابات بريدية (Post)</span>
                <span class="font-mono font-bold text-white print:text-black">{{ formatCurrency(accountsData.total_post) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden print:bg-gray-200">
                <div class="h-full bg-cyan-500 rounded-full print:bg-black" :style="{ width: getPercentage(accountsData.total_post, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Notice / Empty state for future charts -->
        <div class="bg-gradient-to-br from-indigo-900/30 to-purple-900/20 border border-indigo-500/20 rounded-3xl p-8 flex flex-col items-center justify-center text-center print:hidden">
          <div class="w-20 h-20 rounded-full bg-indigo-500/10 flex items-center justify-center mb-6">
            <Activity class="w-10 h-10 text-indigo-400" />
          </div>
          <h3 class="text-xl font-bold text-white mb-3">جاهز للتوسع التحليلي</h3>
          <p class="text-white/60 max-w-sm leading-relaxed">
            هذه المساحة مخصصة لإضافة الرسوم البيانية التفاعلية (Charts) والمقارنات المتقدمة في التحديثات القادمة لتوضيح نمو الشركة بمرور الوقت.
          </p>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const printSettingsStore = usePrintSettingsStore();
import {
  PieChart,
  TrendingUp,
  ArrowUpRight,
  ArrowDownRight,
  Wallet,
  Compass,
  FileSpreadsheet,
  Receipt,
  Landmark,
  Users,
  Building2,
  Activity,
  Loader2,
  ArrowLeft,
  Printer,
  Scale
} from 'lucide-vue-next';

// Filters
const filters = ref({
  from_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
  to_date: new Date().toISOString().split('T')[0]
});

const loading = ref(true);
const loadError = ref('');
let fetchController = null;

// State Data
const finData = ref({
  total_income: 0,
  total_cogs: 0,
  total_operating_expenses: 0,
  total_expense: 0,
  net_profit: 0,
});

const accountsData = ref({
  grand_total: 0,
  total_cashbox: 0,
  total_bank: 0,
  total_wallet: 0,
  total_treasury: 0,
  total_post: 0,
});

// Format Currency
const formatCurrency = (val) => {
  if (!val && val !== 0) return '0.00 EGP';
  return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' EGP';
};

// Calculate percentage for progress bars
const getPercentage = (part, total) => {
  if (!total || total === 0) return 0;
  return Math.min(100, Math.max(0, (part / total) * 100));
};

// Fetch Dashboard Data directly
const fetchDashboardData = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      from_date: filters.value.from_date,
      to_date: filters.value.to_date,
      _t: Date.now(),
    };

    const [summaryRes, balanceRes] = await Promise.all([
      axios.get('/api/v1/reports/financial/summary', { params, signal }),
      axios.get('/api/v1/reports/financial/accounts-balance', {
        params: { _t: Date.now() },
        signal,
      }),
    ]);

    const summary = summaryRes.data?.data || {};
    finData.value = {
      total_income: Number(summary.total_income) || 0,
      total_cogs: Number(summary.total_cogs) || 0,
      total_operating_expenses: Number(summary.total_operating_expenses) || 0,
      total_expense: Number(summary.total_expense) || 0,
      net_profit: Number(summary.net_profit) || 0,
    };
    accountsData.value = balanceRes.data?.data || accountsData.value;
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to load dashboard data:', error);
    loadError.value = error.response?.data?.message || 'فشل تحميل بيانات التقارير';
    if (window.addToast) {
      window.addToast(loadError.value, 'error');
    }
  } finally {
    loading.value = false;
  }
};

const printReport = () => {
  window.print();
};

onMounted(() => {
  fetchDashboardData();
  printSettingsStore.fetch().catch(() => {});
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
});
</script>

<style scoped>
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

@media print {
  /* Scope local layout adjustments inside print */
  .grid {
    gap: 1.5rem !important;
  }
}
</style>

<style>
@media print {
  /* Global overrides for background and base layout during printing */
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

  /* Target the KPIs cards to be print-friendly */
  .grid-cols-4 > div,
  .grid-cols-1 > div {
    background: #ffffff !important;
    background-color: #ffffff !important;
    border: 1px solid #000000 !important;
    color: #000000 !important;
    box-shadow: none !important;
    border-radius: 12px !important;
    padding: 16px !important;
  }

  .grid-cols-4 > div *,
  .grid-cols-1 > div * {
    color: #000000 !important;
  }

  /* Specific color contrast overrides for printed values */
  .text-success,
  .text-emerald-500 {
    color: #166534 !important; /* Forest green */
    font-weight: bold !important;
  }
  
  .text-rose-400,
  .text-rose-500 {
    color: #991b1b !important; /* Crimson */
    font-weight: bold !important;
  }

  .text-blue-400,
  .text-blue-500 {
    color: #1e3a8a !important; /* Navy blue */
    font-weight: bold !important;
  }

  .text-gold {
    color: #b45309 !important; /* Dark amber/gold */
    font-weight: bold !important;
  }
}
</style>
