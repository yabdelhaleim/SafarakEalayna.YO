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
          <h1 class="text-xl font-black text-black">تقرير الديون والمديونيات الموحد</h1>
          <p class="text-[10px] font-bold text-black mt-1">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
      </div>
      
      <div class="mt-4 text-xs text-black grid grid-cols-2 gap-4">
        <p><span class="font-black">قسم الفلترة الرئيسي:</span> {{ getDepartmentLabel(filters.department) }}</p>
        <p><span class="font-black">الموديول / الفرع:</span> {{ getModuleLabel(filters.module) }}</p>
        <p><span class="font-black">نوع المطالبة:</span> {{ getDirectionLabel(filters.direction) }}</p>
        <p><span class="font-black">نوع الحساب / الكيان:</span> {{ getEntityTypeLabel(filters.entity_type) }}</p>
        <p v-if="filters.search"><span class="font-black">البحث عن:</span> {{ filters.search }}</p>
      </div>
    </div>

    <!-- Header & Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden print:hidden">
      <!-- Background Glow -->
      <div class="absolute top-0 right-0 w-64 h-64 bg-gold/10 rounded-full blur-3xl -mr-20 -mt-20 pointer-events-none"></div>
      
      <div class="relative z-10">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-l from-gold to-yellow-200 tracking-tight flex items-center gap-3">
          <Scale class="w-10 h-10 text-gold" />
          تقرير الديون والمديونيات الموحد
        </h1>
        <p class="text-white/60 mt-2 font-medium text-lg">
          متابعة مستحقات العملاء والموردين وشركات الطيران والباصات والوكلاء وحالة المديونيات.
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

    <!-- Metrics cards (Total Receivables, Total Payables, Net Balance) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
      <!-- Total Receivables (لي عند العملاء) -->
      <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-success/40 transition-all overflow-hidden cursor-default">
        <div class="absolute inset-0 bg-gradient-to-br from-success/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div class="p-4 bg-success/10 rounded-2xl text-success group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 shadow-lg shadow-success/20">
            <ArrowUpRight class="w-7 h-7" />
          </div>
          <span class="text-xs font-bold px-3 py-1 rounded-full bg-success/10 text-success border border-success/20">لنا عند الآخرين</span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">إجمالي المستحقات (Receivables)</div>
          <div class="text-3xl font-bold font-mono text-success transition-colors">
            {{ formatCurrency(reportData.total_receivables) }}
          </div>
        </div>
      </div>

      <!-- Total Payables (علي للغير) -->
      <div class="relative group p-6 bg-card-bg border border-white/10 rounded-3xl hover:border-rose-400/40 transition-all overflow-hidden cursor-default">
        <div class="absolute inset-0 bg-gradient-to-br from-rose-400/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div class="p-4 bg-rose-500/10 rounded-2xl text-rose-400 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300 shadow-lg shadow-rose-500/20">
            <ArrowDownRight class="w-7 h-7" />
          </div>
          <span class="text-xs font-bold px-3 py-1 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20">علينا للغير</span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">إجمالي الالتزامات (Payables)</div>
          <div class="text-3xl font-bold font-mono text-rose-400 transition-colors">
            {{ formatCurrency(reportData.total_payables) }}
          </div>
        </div>
      </div>

      <!-- Net Balance (صافي مركز الديون) -->
      <div 
        class="relative group p-6 bg-card-bg border rounded-3xl transition-all overflow-hidden cursor-default"
        :class="reportData.net_balance >= 0 ? 'border-success/20 hover:border-success/40' : 'border-rose-500/20 hover:border-rose-500/40'"
      >
        <div 
          class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-100 transition-opacity duration-500"
          :class="reportData.net_balance >= 0 ? 'from-success/10 to-transparent' : 'from-rose-500/10 to-transparent'"
        ></div>
        <div class="relative z-10 flex justify-between items-start mb-4">
          <div 
            class="p-4 rounded-2xl group-hover:scale-110 transition-transform duration-300 shadow-lg"
            :class="reportData.net_balance >= 0 ? 'bg-success/10 text-success shadow-success/20' : 'bg-rose-500/10 text-rose-400 shadow-rose-500/20'"
          >
            <TrendingUp v-if="reportData.net_balance >= 0" class="w-7 h-7" />
            <TrendingDown v-else class="w-7 h-7" />
          </div>
          <span 
            class="text-xs font-bold px-3 py-1 rounded-full border"
            :class="reportData.net_balance >= 0 ? 'bg-success/10 text-success border-success/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'"
          >
            صافي المركز المالي
          </span>
        </div>
        <div class="relative z-10">
          <div class="text-sm text-white/50 mb-1 font-bold">صافي الميزانية الدائنة/المدينة</div>
          <div 
            class="text-3xl font-bold font-mono transition-colors"
            :class="reportData.net_balance >= 0 ? 'text-success' : 'text-rose-400'"
          >
            {{ formatCurrency(reportData.net_balance) }}
          </div>
        </div>
      </div>
    </div>

    <!-- Filters Panel -->
    <div class="p-6 bg-card-bg border border-white/10 rounded-3xl space-y-6 print:hidden">
      <div class="flex items-center justify-between border-b border-white/5 pb-4">
        <h3 class="text-lg font-bold text-white flex items-center gap-2">
          <Filter class="w-5 h-5 text-gold" />
          خيارات الفلترة والعرض
        </h3>
        <button 
          @click="resetFilters"
          class="text-xs text-text-muted hover:text-gold transition-colors flex items-center gap-1 font-semibold"
        >
          <RotateCcw class="w-3.5 h-3.5" />
          إعادة تعيين الفلاتر
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Main Department Filter -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">القسم الرئيسي</label>
          <div class="grid grid-cols-3 gap-2">
            <button 
              v-for="dept in departments" 
              :key="dept.value"
              @click="setDepartment(dept.value)"
              class="py-2.5 px-3 rounded-xl border text-sm font-bold transition-all duration-300"
              :class="filters.department === dept.value 
                ? 'bg-gold border-gold text-black shadow-lg shadow-gold/20' 
                : 'bg-black/40 border-white/10 text-white/60 hover:text-white hover:border-white/20'"
            >
              {{ dept.label }}
            </button>
          </div>
        </div>

        <!-- Modules Filter (Cascading) -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">الموديول / الفرع</label>
          <div class="relative">
            <select 
              v-model="filters.module"
              @change="fetchDebts"
              class="w-full pl-10 pr-4 py-2.5 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white/80 transition-colors cursor-pointer appearance-none"
              :disabled="availableModules.length === 0"
            >
              <option value="">{{ filters.department ? 'جميع الموديولات' : 'اختر القسم الرئيسي أولاً' }}</option>
              <option 
                v-for="mod in availableModules" 
                :key="mod.value" 
                :value="mod.value"
              >
                {{ mod.label }}
              </option>
            </select>
            <ChevronDown class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/50 pointer-events-none" />
          </div>
        </div>

        <!-- Direction Filter -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">تصفية نوع الحساب</label>
          <div class="grid grid-cols-3 gap-2">
            <button 
              v-for="dir in directions" 
              :key="dir.value"
              @click="setDirection(dir.value)"
              class="py-2.5 px-3 rounded-xl border text-sm font-bold transition-all duration-300"
              :class="filters.direction === dir.value 
                ? 'bg-gold border-gold text-black shadow-lg shadow-gold/20' 
                : 'bg-black/40 border-white/10 text-white/60 hover:text-white hover:border-white/20'"
            >
              {{ dir.label }}
            </button>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
        <!-- Search bar -->
        <div class="md:col-span-2 relative">
          <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
          <input 
            v-model="filters.search"
            type="text"
            placeholder="بحث باسم الحساب أو رقم الهاتف..."
            class="w-full pr-10 pl-4 py-2.5 bg-black/40 border border-white/10 rounded-xl focus:border-gold outline-none text-sm text-white transition-colors"
            @input="debouncedSearch"
          />
        </div>

        <!-- Entity Type Filter -->
        <div class="space-y-2">
          <label class="text-sm font-bold text-white/80 block">نوع الكيان</label>
          <div class="grid grid-cols-3 gap-2">
            <button 
              v-for="type in entityTypes" 
              :key="type.value"
              @click="setEntityType(type.value)"
              class="py-2 px-2 rounded-xl border text-[11px] sm:text-xs font-bold transition-all duration-300"
              :class="filters.entity_type === type.value 
                ? 'bg-gold border-gold text-black shadow-lg shadow-gold/20' 
                : 'bg-black/40 border-white/10 text-white/60 hover:text-white hover:border-white/20'"
            >
              {{ type.label }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loadError" class="p-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 text-rose-300">
      {{ loadError }}
      <button class="mr-3 underline font-bold" @click="fetchDebts">إعادة المحاولة</button>
    </div>

    <!-- Data Table Section -->
    <div class="bg-card-bg border border-white/10 rounded-3xl overflow-hidden shadow-xl">
      <!-- Loading State -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-20 gap-4">
        <Loader2 class="w-12 h-12 text-gold animate-spin" />
        <p class="text-gold font-bold animate-pulse">جاري جرد الديون وتحديث الأرصدة...</p>
      </div>

      <!-- Table Content -->
      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-right">
            <thead>
              <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
                <th class="px-6 py-4 font-bold text-right">الاسم / الحساب</th>
                <th class="px-6 py-4 font-bold text-right">الهاتف</th>
                <th class="px-6 py-4 font-bold text-right">نوع الكيان</th>
                <th class="px-6 py-4 font-bold text-right">القسم الرئيسي</th>
                <th class="px-6 py-4 font-bold text-right">الموديول الفرعي</th>
                <th class="px-6 py-4 font-bold text-right">حالة الرصيد والإجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr 
                v-for="item in reportData.items" 
                :key="`${item.entity_type}_${item.id}`"
                class="hover:bg-white/[0.02] transition-colors group"
              >
                <!-- Name -->
                <td class="px-6 py-4">
                  <span class="font-bold text-white text-sm block group-hover:text-gold transition-colors">
                    {{ item.name }}
                  </span>
                  <span v-if="item.entity_type === 'airline_account'" class="text-[10px] text-white/40 block mt-0.5">
                    ساين
                  </span>
                </td>
                
                <!-- Phone -->
                <td class="px-6 py-4 font-mono text-sm text-white/60">
                  {{ item.phone || '—' }}
                </td>

                <!-- Entity Type -->
                <td class="px-6 py-4">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white/5 text-white/80 border border-white/10">
                    {{ item.entity_type_label }}
                  </span>
                </td>

                <!-- Department -->
                <td class="px-6 py-4">
                  <span class="text-sm font-semibold" :class="item.department === 'tourism' ? 'text-indigo-400' : 'text-blue-400'">
                    {{ item.department_label }}
                  </span>
                </td>

                <!-- Module -->
                <td class="px-6 py-4">
                  <span class="text-sm text-white/70">
                    {{ item.module_label }}
                  </span>
                </td>

                <!-- Balance & Actions -->
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <span
                      class="font-mono font-bold text-sm"
                      :class="debtBalanceView(item.balance).class"
                    >
                      {{ debtBalanceView(item.balance).amount }} EGP
                    </span>
                    <span
                      class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider"
                      :class="debtBalanceView(item.balance).direction === 'debit'
                        ? 'bg-error/10 text-error border border-error/20'
                        : debtBalanceView(item.balance).direction === 'credit'
                          ? 'bg-success/10 text-success border border-success/20'
                          : 'bg-white/5 text-muted border border-white/10'"
                    >
                      {{ debtBalanceView(item.balance).direction === 'debit' ? 'لنا (مدين)' : debtBalanceView(item.balance).direction === 'credit' ? 'له (دائن)' : 'مستوفى' }}
                    </span>
                  </div>
                  
                  <!-- Actions -->
                  <div class="mt-2 flex flex-wrap items-center gap-2 print:hidden">
                    <router-link 
                      v-if="item.statement_url"
                      :to="item.statement_url"
                      class="inline-flex items-center gap-1 text-[10px] font-bold text-gold hover:text-gold/80 transition-all bg-gold/10 px-2 py-1 rounded border border-gold/20"
                    >
                      <span>كشف الحساب</span>
                    </router-link>
                    <router-link
                      v-if="item.account_id"
                      :to="`/finance/transactions/create?account_id=${item.account_id}&type=${item.balance > 0 ? 'income' : 'expense'}`"
                      class="inline-flex items-center gap-1 text-[10px] font-bold transition-all px-2 py-1 rounded border"
                      :class="item.balance > 0 ? 'bg-success/10 text-success border-success/20 hover:text-success/80' : 'bg-rose-500/10 text-rose-400 border-rose-500/20 hover:text-rose-400/80'"
                    >
                      <span>{{ item.balance > 0 ? 'تحصيل دفعة' : 'تسديد دفعة' }}</span>
                    </router-link>
                  </div>
                </td>
              </tr>

              <!-- Empty State -->
              <tr v-if="reportData.items.length === 0">
                <td colspan="7" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center justify-center gap-4">
                    <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center text-white/30">
                      <Scale class="w-8 h-8" />
                    </div>
                    <div>
                      <h4 class="text-lg font-bold text-white">لا توجد ديون مطابقة للفلاتر</h4>
                      <p class="text-white/40 text-xs mt-1">قم بتغيير خيارات البحث والفلترة أو إعادة تعيينها.</p>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';
import { formatLedgerBalance } from '@/composables/useLedgerBalance';

const debtBalanceView = (balance) => {
  const view = formatLedgerBalance(balance);
  return {
    ...view,
    amount: view.text.replace(' جنيه', ''),
  };
};

const printSettingsStore = usePrintSettingsStore();
import {
  Scale,
  ArrowUpRight,
  ArrowDownRight,
  TrendingUp,
  TrendingDown,
  Filter,
  RotateCcw,
  Search,
  ExternalLink,
  Printer,
  Loader2,
  ChevronDown
} from 'lucide-vue-next';

// Filters State
const filters = ref({
  department: '', // '', 'tourism', 'office'
  module: '',     // '', 'flight', 'bus', 'hajj_umra', 'visa', 'fawry', 'online'
  direction: 'all', // 'all', 'receivables', 'payables'
  entity_type: 'all', // 'all', 'customer', 'supplier'
  search: ''
});

const loading = ref(true);
const loadError = ref('');
let fetchController = null;

const reportData = ref({
  total_receivables: 0,
  total_payables: 0,
  net_balance: 0,
  items: []
});

// Filters Lists
const departments = [
  { label: 'الكل', value: '' },
  { label: 'قسم مكتب', value: 'office' },
  { label: 'قسم سياحه', value: 'tourism' }
];

const directions = [
  { label: 'الكل', value: 'all' },
  { label: 'لينا (مدين)', value: 'receivables' },
  { label: 'علينا (دائن)', value: 'payables' }
];

const entityTypes = [
  { label: 'الكل', value: 'all' },
  { label: 'العملاء', value: 'customer' },
  { label: 'الموردين والشركات', value: 'supplier' }
];

// Modules list based on department selection (cascading filter)
const availableModules = computed(() => {
  if (filters.value.department === 'office') {
    return [
      { label: 'حجز باصات', value: 'bus' },
      { label: 'خدمات فوري', value: 'fawry' },
      { label: 'بوابة الدفع الإلكتروني', value: 'online' },
      { label: 'المحافظ والتحويلات', value: 'wallet' },
      { label: 'عام / كاش', value: 'general' }
    ];
  } else if (filters.value.department === 'tourism') {
    return [
      { label: 'حجز طيران', value: 'flight' },
      { label: 'حج وعمرة', value: 'hajj_umra' },
      { label: 'تأشيرات سياحية', value: 'visa' }
    ];
  }
  // If no department is selected, do not open modules filter (force select department first)
  return [];
});

// Watch department change to clean module selection if it's no longer compatible
const setDepartment = (deptValue) => {
  filters.value.department = deptValue;
  filters.value.module = ''; // Reset module on department change
  fetchDebts();
};

const setDirection = (dirValue) => {
  filters.value.direction = dirValue;
  fetchDebts();
};

const setEntityType = (typeValue) => {
  filters.value.entity_type = typeValue;
  fetchDebts();
};

const resetFilters = () => {
  filters.value.department = '';
  filters.value.module = '';
  filters.value.direction = 'all';
  filters.value.entity_type = 'all';
  filters.value.search = '';
  fetchDebts();
};

// Search debouncer to limit api hit
let searchTimeout = null;
const debouncedSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchDebts();
  }, 400);
};

// Fetch Report Data from API
const fetchDebts = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      department: filters.value.department || undefined,
      module: filters.value.module || undefined,
      direction: filters.value.direction !== 'all' ? filters.value.direction : undefined,
      entity_type: filters.value.entity_type !== 'all' ? filters.value.entity_type : undefined,
      search: filters.value.search || undefined,
      _t: Date.now(),
    };

    const response = await axios.get('/api/v1/reports/debts', { params, signal });
    if (response.data?.success) {
      reportData.value = response.data.data;
    } else {
      throw new Error(response.data?.message || 'Failed to fetch debts');
    }
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch debts report:', error);
    loadError.value = error.response?.data?.message || 'فشل تحميل تقرير الديون والمديونيات';
    if (window.addToast) {
      window.addToast(loadError.value, 'error');
    }
  } finally {
    loading.value = false;
  }
};

// Print
const printReport = () => {
  window.print();
};

// Currency formatter
const formatCurrency = (val) => {
  const num = Number(val);
  if (!Number.isFinite(num)) return '0.00 EGP';
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' EGP';
};

// Dynamic labels for print page
const getDepartmentLabel = (val) => {
  return departments.find(d => d.value === val)?.label || 'الكل';
};

const getDirectionLabel = (val) => {
  return directions.find(d => d.value === val)?.label || 'الكل';
};

const getEntityTypeLabel = (val) => {
  return entityTypes.find(t => t.value === val)?.label || 'الكل';
};

const getModuleLabel = (val) => {
  if (!val) return 'جميع الفروع والموديولات';
  const allMods = [
    { label: 'حجز طيران', value: 'flight' },
    { label: 'حجز باصات', value: 'bus' },
    { label: 'حج وعمرة', value: 'hajj_umra' },
    { label: 'تأشيرات سياحية', value: 'visa' },
    { label: 'خدمات فوري', value: 'fawry' },
    { label: 'بوابة الدفع الإلكتروني', value: 'online' },
    { label: 'المحافظ والتحويلات', value: 'wallet' },
    { label: 'المحافظ والتحويلات', value: 'wallet_transfer' },
    { label: 'عام / كاش', value: 'general' }
  ];
  return allMods.find(m => m.value === val)?.label || val;
};

onMounted(() => {
  fetchDebts();
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
  /* Global page-wide settings override during print operations */
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

  /* Grid layout inside print */
  .grid {
    gap: 1.5rem !important;
  }

  /* Target the KPIs cards to be print-friendly */
  .grid-cols-3 > div {
    background: #ffffff !important;
    background-color: #ffffff !important;
    border: 1px solid #000000 !important;
    color: #000000 !important;
    box-shadow: none !important;
    border-radius: 12px !important;
    padding: 16px !important;
  }

  .grid-cols-3 > div * {
    color: #000000 !important;
  }

  /* Text color formatting for print */
  .text-success {
    color: #166534 !important; /* Forest green */
    font-weight: bold !important;
  }
  
  .text-rose-400 {
    color: #991b1b !important; /* Crimson red */
    font-weight: bold !important;
  }

  .text-indigo-400,
  .text-blue-400 {
    color: #1e3a8a !important; /* Royal Navy */
  }

  /* Table styling */
  table {
    width: 100% !important;
    border-collapse: collapse !important;
    border: 1px solid #000000 !important;
  }

  th {
    background-color: #f3f4f6 !important;
    color: #000000 !important;
    font-weight: bold !important;
    border: 1px solid #000000 !important;
    padding: 8px !important;
  }

  td {
    color: #000000 !important;
    border: 1px solid #000000 !important;
    padding: 8px !important;
  }

  td * {
    color: #000000 !important;
  }
}
</style>
