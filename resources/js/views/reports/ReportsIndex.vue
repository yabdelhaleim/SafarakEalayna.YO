<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-12">
    <!-- Header with Date Filters -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden">
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
            <div class="text-sm text-white/50 uppercase tracking-widest mb-1 font-bold">إجمالي المصروفات (Total Expenses)</div>
            <div class="text-3xl font-bold font-mono text-white group-hover:text-rose-400 transition-colors">
              {{ formatCurrency(finData.total_expense) }}
            </div>
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
      <h2 class="text-2xl font-bold text-white mt-10 mb-6 flex items-center gap-2">
        <Compass class="w-6 h-6 text-gold" />
        مداخل التقارير التفصيلية
      </h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        
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

        <!-- Treasury Portal -->
        <router-link to="/treasury" class="block relative group rounded-3xl overflow-hidden aspect-[4/3]">
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

      </div>

      <!-- Quick Distribution Analytics -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <!-- Treasuries Mini-Breakdown -->
        <div class="bg-card-bg border border-white/10 rounded-3xl p-8">
          <h3 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
            <Building2 class="w-5 h-5 text-indigo-400" />
            توزيع السيولة المالية
          </h3>
          <div class="space-y-6">
            <div>
              <div class="flex justify-between text-sm mb-2">
                <span class="text-white/70">النقدية (Cashbox)</span>
                <span class="font-mono font-bold text-white">{{ formatCurrency(accountsData.total_cashbox) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full" :style="{ width: getPercentage(accountsData.total_cashbox, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2">
                <span class="text-white/70">البنوك (Banks)</span>
                <span class="font-mono font-bold text-white">{{ formatCurrency(accountsData.total_bank) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden">
                <div class="h-full bg-blue-500 rounded-full" :style="{ width: getPercentage(accountsData.total_bank, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2">
                <span class="text-white/70">المحافظ الإلكترونية (Wallets)</span>
                <span class="font-mono font-bold text-white">{{ formatCurrency(accountsData.total_wallet) }}</span>
              </div>
              <div class="h-3 bg-white/5 rounded-full overflow-hidden">
                <div class="h-full bg-purple-500 rounded-full" :style="{ width: getPercentage(accountsData.total_wallet, accountsData.grand_total) + '%' }"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Notice / Empty state for future charts -->
        <div class="bg-gradient-to-br from-indigo-900/30 to-purple-900/20 border border-indigo-500/20 rounded-3xl p-8 flex flex-col items-center justify-center text-center">
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
import { ref, onMounted } from 'vue';
import axios from 'axios';
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
  ArrowLeft
} from 'lucide-vue-next';

// Filters
const filters = ref({
  from_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
  to_date: new Date().toISOString().split('T')[0]
});

const loading = ref(true);

// State Data
const finData = ref({
  total_income: 0,
  total_expense: 0,
  net_profit: 0
});

const accountsData = ref({
  grand_total: 0,
  total_cashbox: 0,
  total_bank: 0,
  total_wallet: 0
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
  loading.value = true;
  try {
    const params = {
      from_date: filters.value.from_date,
      to_date: filters.value.to_date
    };

    const [summaryRes, balanceRes] = await Promise.all([
      axios.get('/api/v1/reports/financial/summary', { params }),
      axios.get('/api/v1/reports/financial/accounts-balance') // Balance doesn't need date
    ]);

    finData.value = summaryRes.data?.data || finData.value;
    accountsData.value = balanceRes.data?.data || accountsData.value;
    
  } catch (error) {
    console.error('Failed to load dashboard data:', error);
    if(window.addToast) {
      window.addToast('فشل تحميل بعض البيانات', 'error');
    }
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchDashboardData();
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
</style>
