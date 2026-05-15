<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
      <div>
        <div class="flex items-center gap-3 mb-2">
          <div :class="['p-2 rounded-lg', isTourism ? 'bg-success/10 text-success' : 'bg-purple/10 text-purple-400']">
            <LayoutDashboard v-if="isTourism" class="w-6 h-6" />
            <Briefcase v-else class="w-6 h-6" />
          </div>
          <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
            {{ title }}
          </h1>
        </div>
        <p class="text-text-muted">
          لوحة التحكم الشاملة لإدارة العمليات والديون والعملاء لقسم {{ departmentName }}
        </p>
      </div>

      <!-- Quick Actions -->
      <div class="flex items-center gap-3">
        <button
          @click="refreshAll"
          class="p-3 bg-white/5 border border-white/10 rounded-xl hover:border-gold transition-all"
          title="تحديث البيانات"
        >
          <RefreshCw :class="['w-5 h-5', loading ? 'animate-spin' : '']" />
        </button>
        <router-link
          to="/finance/transactions/create"
          class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20"
        >
          <Plus class="w-5 h-5" />
          إضافة معاملة
        </router-link>
      </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex items-center gap-2 p-1 bg-white/5 border border-white/10 rounded-2xl w-fit">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        @click="activeTab = tab.id"
        :class="[
          'px-6 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2',
          activeTab === tab.id ? 'bg-gold text-black shadow-lg' : 'text-text-muted hover:text-white hover:bg-white/5'
        ]"
      >
        <component :is="tab.icon" class="w-4 h-4" />
        {{ tab.label }}
      </button>
    </div>

    <!-- Tab Content: Financials -->
    <div v-if="activeTab === 'financials'" class="space-y-6">
      <!-- Mini Stats -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-card-bg border border-white/10 rounded-2xl p-5 shadow-lg border-r-4 border-r-success">
          <p class="text-[10px] font-bold text-text-muted uppercase mb-1">إجمالي المقبوضات</p>
          <h3 class="text-xl font-black text-success">{{ formatCurrency(financeStore.stats.total_income) }}</h3>
        </div>
        <div class="bg-card-bg border border-white/10 rounded-2xl p-5 shadow-lg border-r-4 border-r-error">
          <p class="text-[10px] font-bold text-text-muted uppercase mb-1">إجمالي المصروفات</p>
          <h3 class="text-xl font-black text-error">{{ formatCurrency(financeStore.stats.total_expense) }}</h3>
        </div>
        <div class="bg-card-bg border border-white/10 rounded-2xl p-5 shadow-lg border-r-4 border-r-gold">
          <p class="text-[10px] font-bold text-text-muted uppercase mb-1">صافي التدفق</p>
          <h3 class="text-xl font-black text-gold">{{ formatCurrency(financeStore.stats.net_profit) }}</h3>
        </div>
        <div class="bg-card-bg border border-white/10 rounded-2xl p-5 shadow-lg border-r-4 border-r-blue-400">
          <p class="text-[10px] font-bold text-text-muted uppercase mb-1">مديونيات الموردين</p>
          <h3 class="text-xl font-black text-blue-400">{{ formatCurrency(supplierDebtsTotal) }}</h3>
        </div>
      </div>

      <!-- Transactions Filter & List -->
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-4 border-b border-white/10 flex items-center justify-between bg-white/5">
          <h4 class="font-bold flex items-center gap-2">
            <ListTree class="w-4 h-4 text-gold" />
            آخر المعاملات المالية
          </h4>
          <div class="flex items-center gap-3">
            <select
              v-model="financeFilters.module"
              @change="fetchTransactions"
              class="bg-input-bg border border-white/10 rounded-lg px-3 py-1.5 text-xs outline-none focus:border-gold"
            >
              <option :value="modules">كل موديولات {{ departmentName }}</option>
              <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="text-[10px] text-text-muted uppercase bg-white/5 border-b border-white/5 whitespace-nowrap">
                <th class="px-6 py-4">التاريخ</th>
                <th class="px-6 py-4">الموديول</th>
                <th class="px-6 py-4">الحساب</th>
                <th class="px-6 py-4">الوصف</th>
                <th class="px-6 py-4">المبلغ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in financeStore.transactions" :key="t.id" class="border-b border-white/5 hover:bg-white/5 transition-colors">
                <td class="px-6 py-3 text-xs text-text-muted">{{ formatDate(t.created_at) }}</td>
                <td class="px-6 py-3">
                  <span class="text-[10px] font-bold bg-white/5 px-2 py-0.5 rounded text-sky-200">{{ getModuleLabel(t.module) }}</span>
                </td>
                <td class="px-6 py-3 text-xs font-bold">{{ t.from_account_name || t.to_account_name }}</td>
                <td class="px-6 py-3 text-xs text-text-muted truncate max-w-[200px]">{{ t.notes || '-' }}</td>
                <td class="px-6 py-3">
                  <span :class="['font-mono font-bold text-sm', t.type === 'income' ? 'text-success' : 'text-error']">
                    {{ t.type === 'income' ? '+' : '-' }}{{ formatNumber(t.amount) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab Content: Suppliers -->
    <div v-if="activeTab === 'suppliers'" class="space-y-6">
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/5">
          <div>
            <h4 class="text-lg font-bold">مستحقات الموردين</h4>
            <p class="text-xs text-text-muted">المبالغ المطلوب سدادها لموردي قسم {{ departmentName }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-text-muted uppercase font-bold">إجمالي المديونية</p>
            <h3 class="text-2xl font-black text-error">{{ formatCurrency(supplierDebtsTotal) }}</h3>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="text-[11px] text-text-muted uppercase bg-white/5 border-b border-white/10 whitespace-nowrap">
                <th class="px-6 py-5">المورد</th>
                <th class="px-6 py-5">التصنيف</th>
                <th class="px-6 py-5">رصيد الحساب</th>
                <th class="px-6 py-5">سقف الائتمان</th>
                <th class="px-6 py-5">المديونية الحالية</th>
                <th class="px-6 py-5">الحالة</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in supplierDebts" :key="s.supplier_id" class="border-b border-white/5 hover:bg-white/5 transition-colors group">
                <td class="px-6 py-4 font-bold text-white">{{ s.supplier_name }}</td>
                <td class="px-6 py-4 text-xs">{{ s.type }}</td>
                <td class="px-6 py-4 font-mono text-sm" :class="s.account_balance < 0 ? 'text-error' : 'text-success'">
                  {{ formatNumber(s.account_balance) }}
                </td>
                <td class="px-6 py-4 text-xs text-text-muted">{{ formatNumber(s.credit_limit) }}</td>
                <td class="px-6 py-4 font-black text-error">{{ formatNumber(s.current_debt) }}</td>
                <td class="px-6 py-4">
                  <span v-if="s.is_over_limit" class="px-2 py-1 rounded-lg bg-error/10 text-error text-[10px] font-black uppercase tracking-tighter">
                    تجاوز الحد
                  </span>
                  <span v-else class="px-2 py-1 rounded-lg bg-success/10 text-success text-[10px] font-black uppercase tracking-tighter">
                    مستقر
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab Content: Customers & Companies -->
    <div v-if="activeTab === 'customers'" class="space-y-8">
      <!-- Regular Customers Section -->
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/5 border-r-4 border-r-gold">
          <div>
            <h4 class="text-lg font-bold flex items-center gap-2">
              <UserCircle class="w-5 h-5 text-gold" />
              العملاء الأفراد (عادي)
            </h4>
            <p class="text-xs text-text-muted">قائمة العملاء المباشرين ومديونياتهم المعلقة</p>
          </div>
          <div class="text-right">
            <p class="text-[10px] text-text-muted uppercase font-bold">مستحقات الأفراد</p>
            <h3 class="text-xl font-black text-gold">{{ formatCurrency(regularCustomerDebtsTotal) }}</h3>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="text-[11px] text-text-muted uppercase bg-white/5 border-b border-white/10 whitespace-nowrap">
                <th class="px-6 py-5">العميل</th>
                <th class="px-6 py-5">رقم الهاتف</th>
                <th class="px-6 py-5">العمليات المعلقة</th>
                <th class="px-6 py-5">المديونية</th>
                <th class="px-6 py-5 text-left">التفاصيل</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in regularCustomerDebts" :key="c.customer_id" class="border-b border-white/5 hover:bg-white/5 transition-colors group">
                <td class="px-6 py-4">
                  <div class="font-bold text-white">{{ c.customer_name }}</div>
                  <div class="text-[10px] text-text-muted">{{ c.affiliation || 'عميل حجز مباشر' }}</div>
                </td>
                <td class="px-6 py-4 text-xs font-mono">{{ c.phone || '-' }}</td>
                <td class="px-6 py-4">
                  <span class="px-2 py-1 bg-white/5 rounded text-xs">{{ c.pending_bookings_count }} حجوزات</span>
                </td>
                <td class="px-6 py-4 font-black text-error text-lg">{{ formatNumber(c.total_debt) }}</td>
                <td class="px-6 py-4 text-left">
                  <button class="p-2 hover:bg-gold/10 text-text-muted hover:text-gold rounded-lg transition-all">
                    <FileText class="w-4 h-4" />
                  </button>
                </td>
              </tr>
              <tr v-if="!regularCustomerDebts.length" class="text-center italic text-text-muted">
                <td colspan="5" class="py-10 text-sm">لا يوجد مديونيات عملاء أفراد حالياً</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Counter Customers (Companies) Section -->
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/5 border-r-4 border-r-blue-500">
          <div>
            <h4 class="text-lg font-bold flex items-center gap-2">
              <Building2 class="w-5 h-5 text-blue-400" />
              عملاء الكوانتر والشركات
            </h4>
            <p class="text-xs text-text-muted">مستحقات الشركات والمكاتب المتعاقدة (B2B)</p>
          </div>
          <div class="text-right">
            <p class="text-[10px] text-text-muted uppercase font-bold">مستحقات الكوانتر</p>
            <h3 class="text-xl font-black text-blue-400">{{ formatCurrency(counterCustomerDebtsTotal) }}</h3>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="text-[11px] text-text-muted uppercase bg-white/5 border-b border-white/10">
                <th class="px-6 py-5">الشركة / المكتب</th>
                <th class="px-6 py-5">رقم التواصل</th>
                <th class="px-6 py-5">حجوزات معلقة</th>
                <th class="px-6 py-5">إجمالي المديونية</th>
                <th class="px-6 py-5 text-left">الحساب</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in counterCustomerDebts" :key="c.customer_id" class="border-b border-white/5 hover:bg-white/5 transition-colors group">
                <td class="px-6 py-4">
                  <div class="font-bold text-white">{{ c.customer_name }}</div>
                  <div class="text-[10px] text-blue-300 font-bold uppercase tracking-widest">Counter Partner</div>
                </td>
                <td class="px-6 py-4 text-xs font-mono">{{ c.phone || '-' }}</td>
                <td class="px-6 py-4">
                  <span class="px-2 py-1 bg-blue-500/10 text-blue-300 rounded text-xs font-bold">{{ c.pending_bookings_count }} عملية</span>
                </td>
                <td class="px-6 py-4 font-black text-error text-lg">{{ formatNumber(c.total_debt) }}</td>
                <td class="px-6 py-4 text-left">
                  <button class="p-2 hover:bg-blue-500/10 text-text-muted hover:text-blue-400 rounded-lg transition-all">
                    <TrendingUp class="w-4 h-4" />
                  </button>
                </td>
              </tr>
              <tr v-if="!counterCustomerDebts.length" class="text-center italic text-text-muted">
                <td colspan="5" class="py-10 text-sm">لا يوجد مديونيات عملاء كوانتر حالياً</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { useFinanceStore } from '@/stores/financeStore';
import axios from 'axios';
import {
  LayoutDashboard,
  Briefcase,
  RefreshCw,
  Plus,
  TrendingUp,
  TrendingDown,
  DollarSign,
  ListTree,
  UserCircle,
  FileText,
  Building2
} from 'lucide-vue-next';

const props = defineProps({
  type: { type: String, required: true }, // 'tourism' or 'office'
  modules: { type: Array, required: true }, // e.g. ['flight', 'hajj_umra', 'visa']
  title: { type: String, required: true },
  departmentName: { type: String, required: true }
});

const isTourism = computed(() => props.type === 'tourism');
const financeStore = useFinanceStore();

const activeTab = ref('financials');
const loading = ref(false);

const tabs = [
  { id: 'financials', label: 'المالية والعمليات', icon: LayoutDashboard },
  { id: 'suppliers', label: 'مديونيات الموردين', icon: TrendingDown },
  { id: 'customers', label: 'العملاء والشركات', icon: UserCircle },
];

const financeFilters = reactive({
  module: props.modules,
  per_page: 10
});

// Data State
const supplierDebts = ref([]);
const regularCustomerDebts = ref([]);
const counterCustomerDebts = ref([]);

const supplierDebtsTotal = computed(() => supplierDebts.value.reduce((acc, s) => acc + s.current_debt, 0));
const regularCustomerDebtsTotal = computed(() => regularCustomerDebts.value.reduce((acc, c) => acc + c.total_debt, 0));
const counterCustomerDebtsTotal = computed(() => counterCustomerDebts.value.reduce((acc, c) => acc + c.total_debt, 0));

// Options
const moduleOptions = computed(() => {
  return financeStore.transactionModules.filter(m => props.modules.includes(m.value));
});

// Actions
const refreshAll = async () => {
  loading.value = true;
  try {
    await Promise.all([
      fetchTransactions(),
      fetchSupplierDebts(),
      fetchCustomerDebts()
    ]);
  } finally {
    loading.value = false;
  }
};

const fetchTransactions = async () => {
  await Promise.all([
    financeStore.fetchTransactions({ ...financeFilters }),
    financeStore.fetchStats({ module: financeFilters.module })
  ]);
};

const fetchSupplierDebts = async () => {
  const res = await axios.get('/api/v1/reports/financial/supplier-debts', {
    params: { module_type: props.type }
  });
  supplierDebts.value = res.data.data.debts;
};

const fetchCustomerDebts = async () => {
  const [reg, count] = await Promise.all([
    axios.get('/api/v1/reports/financial/customer-debts', {
      params: { module: props.modules, customer_type: 'regular' }
    }),
    axios.get('/api/v1/reports/financial/customer-debts', {
      params: { module: props.modules, customer_type: 'counter' }
    })
  ]);
  regularCustomerDebts.value = reg.data.data.debts;
  counterCustomerDebts.value = count.data.data.debts;
};

// Utils
const formatCurrency = (val) => {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP'
  }).format(val || 0);
};

const formatNumber = (val) => {
  return Number(val || 0).toLocaleString('ar-EG');
};

const formatDate = (date) => {
  return date ? new Date(date).toLocaleDateString('ar-EG') : '-';
};

const getModuleLabel = (val) => {
  return financeStore.transactionModules.find(m => m.value === val)?.label || val;
};

onMounted(() => {
  financeStore.fetchSettingsMeta();
  refreshAll();
});
</script>
