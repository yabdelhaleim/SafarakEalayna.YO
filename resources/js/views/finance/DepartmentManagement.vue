<template>
  <div class="dept-page" dir="rtl">
    <!-- Header -->
    <div class="dept-header">
      <div class="dept-header__info">
        <div :class="['dept-icon', isTourism ? 'dept-icon--green' : 'dept-icon--purple']">
          <LayoutDashboard v-if="isTourism" class="w-6 h-6" />
          <Briefcase v-else class="w-6 h-6" />
        </div>
        <div>
          <h1 class="dept-title">{{ title }}</h1>
          <p class="dept-subtitle">لوحة التحكم الشاملة لإدارة العمليات والديون والعملاء لقسم {{ departmentName }}</p>
        </div>
      </div>
      <div class="dept-header__actions">
        <button @click="refreshAll" :disabled="loading" class="btn-refresh" title="تحديث البيانات">
          <RefreshCw :class="['w-5 h-5', loading ? 'animate-spin' : '']" />
        </button>
        <router-link to="/finance/transactions/create" class="btn-add">
          <Plus class="w-5 h-5" />
          إضافة معاملة
        </router-link>
      </div>
    </div>

    <!-- Top KPI Bar -->
    <div class="kpi-bar">
      <div class="kpi-card kpi-card--green">
        <p class="kpi-label">إجمالي المستحقات لنا</p>
        <h3 class="kpi-value text-success">{{ formatCurrency(summary.total_receivables) }}</h3>
        <span class="kpi-sub">{{ receivableItems.length }} جهة</span>
      </div>
      <div class="kpi-card kpi-card--red">
        <p class="kpi-label">إجمالي المستحق علينا</p>
        <h3 class="kpi-value text-error">{{ formatCurrency(summary.total_payables) }}</h3>
        <span class="kpi-sub">{{ payableItems.length }} جهة</span>
      </div>
      <div class="kpi-card" :class="summary.net_balance >= 0 ? 'kpi-card--green' : 'kpi-card--red'">
        <p class="kpi-label">صافي الميزان</p>
        <h3 class="kpi-value" :class="summary.net_balance >= 0 ? 'text-success' : 'text-error'">
          {{ formatCurrency(Math.abs(summary.net_balance)) }}
        </h3>
        <span class="kpi-sub" :class="summary.net_balance >= 0 ? 'text-success' : 'text-error'">
          {{ summary.net_balance >= 0 ? 'لصالحنا' : 'علينا' }}
        </span>
      </div>
      <div class="kpi-card kpi-card--gold">
        <p class="kpi-label">إجمالي الإيرادات</p>
        <h3 class="kpi-value text-gold">{{ formatCurrency(moduleStats.total_income) }}</h3>
        <span class="kpi-sub">{{ props.modules.length }} موديولات</span>
      </div>
    </div>

    <!-- Balance Bar Indicator -->
    <div class="balance-bar-wrap">
      <div class="balance-bar-labels">
        <span class="text-error text-xs font-bold">← مستحق علينا ({{ formatCurrency(summary.total_payables) }})</span>
        <span class="text-success text-xs font-bold">({{ formatCurrency(summary.total_receivables) }}) مستحق لنا →</span>
      </div>
      <div class="balance-bar">
        <div class="balance-bar__payables" :style="{ width: payablesPercent + '%' }"></div>
        <div class="balance-bar__receivables" :style="{ width: receivablesPercent + '%' }"></div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tab-nav">
      <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
        :class="['tab-btn', activeTab === tab.id ? 'tab-btn--active' : '']">
        <component :is="tab.icon" class="w-4 h-4" />
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="tab-badge">{{ tab.count }}</span>
      </button>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="space-y-4">
      <div class="skeleton-row" v-for="i in 5" :key="i"></div>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="error-box">
      <AlertCircle class="w-6 h-6 text-error" />
      <div>
        <p class="font-bold text-error">فشل تحميل البيانات</p>
        <p class="text-xs text-muted">{{ error }}</p>
      </div>
      <button @click="refreshAll" class="btn-retry">إعادة المحاولة</button>
    </div>

    <template v-else>
      <!-- ============================================================
           TAB: المركز المالي
           ============================================================ -->
      <div v-if="activeTab === 'financials'" class="space-y-6">
        <!-- Module performance table -->
        <div class="data-card">
          <div class="data-card__header">
            <div>
              <h4 class="data-card__title"><BarChart2 class="w-5 h-5 text-gold" /> أداء الموديولات — الإيرادات والمصروفات</h4>
              <p class="data-card__sub">تحليل الحركة المالية لكل قسم فرعي في الفترة الحالية</p>
            </div>
          </div>
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>الموديول</th>
                  <th>إجمالي الإيرادات</th>
                  <th>إجمالي المصروفات</th>
                  <th>صافي الربح</th>
                  <th>معدل الأداء</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in moduleBreakdown" :key="m.module">
                  <td>
                    <div class="flex items-center gap-2">
                      <span :class="['module-dot', getModuleColor(m.module)]"></span>
                      <span class="font-bold text-white">{{ getModuleLabel(m.module) }}</span>
                    </div>
                  </td>
                  <td class="font-mono text-success font-bold">+{{ formatCurrency(m.income) }}</td>
                  <td class="font-mono text-error font-bold">-{{ formatCurrency(m.expense) }}</td>
                  <td>
                    <span :class="['font-mono font-black', m.profit >= 0 ? 'text-success' : 'text-error']">
                      {{ m.profit >= 0 ? '+' : '' }}{{ formatCurrency(m.profit) }}
                    </span>
                  </td>
                  <td>
                    <div class="perf-bar-wrap">
                      <div class="perf-bar">
                        <div :class="['perf-bar__fill', m.profit < 0 ? 'bg-error' : 'bg-success']"
                          :style="{ width: getMarginPct(m) + '%' }"></div>
                      </div>
                      <span class="perf-pct">{{ getMarginPct(m) }}%</span>
                    </div>
                  </td>
                </tr>
                <tr v-if="!moduleBreakdown.length">
                  <td colspan="5" class="empty-row">لا تتوفر بيانات في الفترة الحالية</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ============================================================
           TAB: المستحق علينا (الموردين والشركات)
           ============================================================ -->
      <div v-if="activeTab === 'payables'" class="space-y-4">
        <div class="data-card">
          <div class="data-card__header">
            <div>
              <h4 class="data-card__title"><TrendingDown class="w-5 h-5 text-error" /> المستحق علينا — مديونيات الموردين والشركات</h4>
              <p class="data-card__sub">الجهات التي علينا تسديد مبالغ لها (رصيدها سالب)</p>
            </div>
            <div class="text-left">
              <p class="text-[10px] text-muted uppercase font-bold">الإجمالي المستحق</p>
              <h3 class="text-2xl font-black text-error">{{ formatCurrency(summary.total_payables) }}</h3>
            </div>
          </div>

          <!-- Search -->
          <div class="search-bar">
            <Search class="w-4 h-4 text-muted" />
            <input v-model="searchQuery" type="text" placeholder="ابحث بالاسم أو الرقم..." class="search-input" />
          </div>

          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>الجهة</th>
                  <th>النوع</th>
                  <th>الموديول</th>
                  <th>رقم التواصل</th>
                  <th>المبلغ المستحق</th>
                  <th>كشف الحساب</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in filteredPayables" :key="`pay-${item.entity_type}-${item.id}`">
                  <td>
                    <div class="font-bold text-white">{{ item.name }}</div>
                    <div class="text-[10px] text-muted">{{ item.entity_type_label }}</div>
                  </td>
                  <td>
                    <span class="entity-badge entity-badge--supplier">{{ item.entity_type_label }}</span>
                  </td>
                  <td>
                    <span :class="['module-tag', getModuleColor(item.module)]">
                      {{ item.module_label }}
                    </span>
                  </td>
                  <td class="font-mono text-xs text-muted">{{ item.phone || '—' }}</td>
                  <td>
                    <span class="font-black text-error text-lg font-mono">{{ formatMoney(Math.abs(item.balance), item.currency) }}</span>
                  </td>
                  <td>
                    <router-link v-if="item.statement_url" :to="item.statement_url"
                      class="action-link action-link--blue">
                      <FileText class="w-3.5 h-3.5" />
                      كشف
                    </router-link>
                    <span v-else class="text-muted text-xs">—</span>
                  </td>
                </tr>
                <tr v-if="!filteredPayables.length">
                  <td colspan="6" class="empty-row">لا توجد مديونيات مستحقة علينا حالياً ✓</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ============================================================
           TAB: المستحق لنا (العملاء والشركات)
           ============================================================ -->
      <div v-if="activeTab === 'receivables'" class="space-y-4">
        <div class="data-card">
          <div class="data-card__header">
            <div>
              <h4 class="data-card__title"><TrendingUp class="w-5 h-5 text-success" /> المستحق لنا — مديونيات العملاء والجهات الدائنة</h4>
              <p class="data-card__sub">الجهات المدينة لنا بمبالغ (رصيدها موجب)</p>
            </div>
            <div class="text-left">
              <p class="text-[10px] text-muted uppercase font-bold">الإجمالي المستحق لنا</p>
              <h3 class="text-2xl font-black text-success">{{ formatCurrency(summary.total_receivables) }}</h3>
            </div>
          </div>

          <!-- Filter by entity type -->
          <div class="filter-row">
            <Search class="w-4 h-4 text-muted flex-shrink-0" />
            <input v-model="searchQuery" type="text" placeholder="ابحث بالاسم أو الرقم..." class="search-input" />
            <select v-model="entityFilter" class="filter-select">
              <option value="all">كل الجهات</option>
              <option value="customer">عملاء أفراد</option>
              <option value="flight_group">مجموعات طيران</option>
              <option value="airline_account">حسابات شركات الطيران</option>
              <option value="visa_agent">وكلاء تأشيرات</option>
              <option value="executing_company">شركات منفذة (حج)</option>
              <option value="bus_company">شركات باصات</option>
            </select>
          </div>

          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>الجهة</th>
                  <th>النوع</th>
                  <th>الموديول</th>
                  <th>رقم التواصل</th>
                  <th>المبلغ المستحق</th>
                  <th>كشف الحساب</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in filteredReceivables" :key="`rec-${item.entity_type}-${item.id}`">
                  <td>
                    <div class="font-bold text-white">{{ item.name }}</div>
                    <div class="text-[10px] text-muted">{{ item.department_label }}</div>
                  </td>
                  <td>
                    <span :class="['entity-badge', item.entity_type === 'customer' ? 'entity-badge--customer' : 'entity-badge--group']">
                      {{ item.entity_type_label }}
                    </span>
                  </td>
                  <td>
                    <span :class="['module-tag', getModuleColor(item.module)]">
                      {{ item.module_label }}
                    </span>
                  </td>
                  <td class="font-mono text-xs text-muted">{{ item.phone || '—' }}</td>
                  <td>
                    <span class="font-black text-success text-lg font-mono">{{ formatMoney(item.balance, item.currency) }}</span>
                  </td>
                  <td>
                    <router-link v-if="item.statement_url" :to="item.statement_url"
                      class="action-link action-link--green">
                      <FileText class="w-3.5 h-3.5" />
                      كشف
                    </router-link>
                    <span v-else class="text-muted text-xs">—</span>
                  </td>
                </tr>
                <tr v-if="!filteredReceivables.length">
                  <td colspan="6" class="empty-row">لا توجد مستحقات لنا حالياً</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
  LayoutDashboard, Briefcase, RefreshCw, Plus,
  TrendingUp, TrendingDown, FileText, Search,
  BarChart2, AlertCircle
} from 'lucide-vue-next';

const props = defineProps({
  type: { type: String, required: true },
  modules: { type: Array, required: true, default: () => [] },
  title: { type: String, required: true },
  departmentName: { type: String, required: true }
});

const isTourism = computed(() => props.type === 'tourism');

// State
const loading = ref(false);
const error = ref(null);
const activeTab = ref('financials');
const searchQuery = ref('');
const entityFilter = ref('all');
let fetchController = null;

const period = ref({
  from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
  to: new Date().toISOString().slice(0, 10),
});

// Data
const allItems = ref([]);        // from debts report
const moduleBreakdown = ref([]); // from profit-by-module
const summary = ref({ total_receivables: 0, total_payables: 0, net_balance: 0 });
const moduleStats = ref({ total_income: 0, total_expense: 0 });

// Derived lists
const receivableItems = computed(() => allItems.value.filter(i => i.balance > 0));
const payableItems = computed(() => allItems.value.filter(i => i.balance < 0));

const filteredReceivables = computed(() => {
  let list = receivableItems.value;
  if (entityFilter.value !== 'all') list = list.filter(i => i.entity_type === entityFilter.value);
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i => i.name?.toLowerCase().includes(q) || i.phone?.toLowerCase().includes(q));
  }
  return list;
});

const filteredPayables = computed(() => {
  let list = payableItems.value;
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(i => i.name?.toLowerCase().includes(q) || i.phone?.toLowerCase().includes(q));
  }
  return list;
});

// Bar percentages
const payablesPercent = computed(() => {
  const pay = Math.abs(summary.value.total_payables || 0);
  const rec = Math.abs(summary.value.total_receivables || 0);
  const total = pay + rec;
  if (!total) return 50;
  return Math.round((pay / total) * 100);
});
const receivablesPercent = computed(() => 100 - payablesPercent.value);

// Tabs
const tabs = computed(() => [
  { id: 'financials', label: 'المركز المالي', icon: BarChart2 },
  { id: 'receivables', label: 'المستحق لنا', icon: TrendingUp, count: receivableItems.value.length },
  { id: 'payables', label: 'المستحق علينا', icon: TrendingDown, count: payableItems.value.length },
]);

const moduleMatches = (moduleKey) => {
  const aliases = {
    wallet: ['wallet', 'wallet_transfer', 'wallets'],
    wallet_transfer: ['wallet', 'wallet_transfer', 'wallets'],
  };
  const accepted = aliases[moduleKey] || [moduleKey];
  return accepted;
};

const filterModulesForDepartment = (byModule) =>
  byModule.filter((m) => props.modules.some((wanted) => moduleMatches(wanted).includes(m.module)));

// API calls
const fetchDebts = async (signal) => {
  const res = await axios.get('/api/v1/reports/debts', {
    params: {
      department: props.type,
      direction: 'all',
      _t: Date.now(),
    },
    signal,
  });
  const data = res.data?.data || {};
  allItems.value = data.items || [];
  summary.value = {
    total_receivables: data.total_receivables || 0,
    total_payables: data.total_payables || 0,
    net_balance: data.net_balance || 0,
  };
};

const fetchModuleStats = async (signal) => {
  const res = await axios.get('/api/v1/reports/profit-by-module', {
    params: {
      category: props.type,
      from_date: period.value.from,
      to_date: period.value.to,
      _t: Date.now(),
    },
    signal,
  });
  const byModule = res.data?.data?.by_module || [];
  moduleBreakdown.value = filterModulesForDepartment(byModule);
  moduleStats.value.total_income = moduleBreakdown.value.reduce((s, m) => s + (m.income || 0), 0);
  moduleStats.value.total_expense = moduleBreakdown.value.reduce((s, m) => s + (m.expense || 0), 0);
};

const refreshAll = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  loading.value = true;
  error.value = null;
  try {
    await Promise.all([fetchDebts(signal), fetchModuleStats(signal)]);
  } catch (err) {
    if (axios.isCancel?.(err) || err?.code === 'ERR_CANCELED') {
      return;
    }
    console.error(err);
    error.value = err.response?.data?.message || err.message || 'حدث خطأ أثناء تحميل البيانات';
  } finally {
    loading.value = false;
  }
};

// Helpers
const formatCurrency = (val) =>
  (parseFloat(val) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' جنيه';

const formatMoney = (val, currency = 'EGP') => {
  const currencyLabels = { EGP: 'جنيه', USD: '$', SAR: 'ر.س', KWD: 'د.ك' };
  const label = currencyLabels[currency] || currency;
  return (parseFloat(val) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + label;
};

const getModuleLabel = (mod) => {
  const map = {
    flight: 'طيران',
    bus: 'باص',
    hajj_umra: 'حج وعمرة',
    visa: 'تأشيرات',
    fawry: 'فوري',
    online: 'خدمات إلكترونية',
    wallet: 'محفظة وتحويلات',
    wallet_transfer: 'محفظة وتحويلات',
    general: 'عام',
  };
  return map[mod] || mod;
};

const getModuleColor = (mod) => {
  const map = { flight: 'module-dot--blue', bus: 'module-dot--orange', hajj_umra: 'module-dot--green', visa: 'module-dot--purple', fawry: 'module-dot--teal', online: 'module-dot--sky', wallet: 'module-dot--teal', general: 'module-dot--gray' };
  return map[mod] || 'module-dot--gray';
};

const getMarginPct = (m) => {
  if (!m.income) return 0;
  return Math.min(100, Math.max(0, Math.round((Math.abs(m.profit) / m.income) * 100)));
};

onMounted(() => refreshAll());

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
});
</script>

<style scoped>
/* =========================================
   PAGE LAYOUT
   ========================================= */
.dept-page { direction: rtl; display: flex; flex-direction: column; gap: 1.5rem; }

/* =========================================
   HEADER
   ========================================= */
.dept-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.dept-header__info { display: flex; align-items: center; gap: 1rem; }
.dept-icon { width: 3rem; height: 3rem; border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.dept-icon--green { background: rgba(34,197,94,.1); color: #22c55e; }
.dept-icon--purple { background: rgba(168,85,247,.1); color: #a855f7; }
.dept-title { font-size: 2rem; font-weight: 900; color: white; line-height: 1.2; }
.dept-subtitle { font-size: 0.8125rem; color: var(--text-muted, #94a3b8); margin-top: 0.25rem; }
.dept-header__actions { display: flex; align-items: center; gap: 0.75rem; }
.btn-refresh { padding: 0.75rem; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-radius: 0.75rem; color: white; cursor: pointer; transition: all .2s; }
.btn-refresh:hover { border-color: var(--gold, #d4a843); }
.btn-refresh:disabled { opacity: .5; cursor: not-allowed; }
.btn-add { display: flex; align-items: center; gap: 0.5rem; background: var(--gold, #d4a843); color: #000; font-weight: 700; padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-size: 0.875rem; text-decoration: none; transition: filter .2s; }
.btn-add:hover { filter: brightness(1.1); }

/* =========================================
   KPI BAR
   ========================================= */
.kpi-bar { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
@media (min-width: 768px) { .kpi-bar { grid-template-columns: repeat(4, 1fr); } }
.kpi-card { background: var(--card-bg, #1e293b); border: 1px solid rgba(255,255,255,.08); border-radius: 1rem; padding: 1.25rem; position: relative; overflow: hidden; border-right: 4px solid transparent; }
.kpi-card--green { border-right-color: #22c55e; }
.kpi-card--red { border-right-color: #ef4444; }
.kpi-card--gold { border-right-color: var(--gold, #d4a843); }
.kpi-label { font-size: 0.6875rem; font-weight: 700; color: var(--text-muted, #94a3b8); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 0.375rem; }
.kpi-value { font-size: 1.125rem; font-weight: 900; font-variant-numeric: tabular-nums; }
.kpi-sub { font-size: 0.6875rem; color: var(--text-muted, #94a3b8); }

/* =========================================
   BALANCE BAR
   ========================================= */
.balance-bar-wrap { space-y: 0.5rem; }
.balance-bar-labels { display: flex; justify-content: space-between; margin-bottom: 0.375rem; }
.balance-bar { width: 100%; height: 0.5rem; background: rgba(255,255,255,.05); border-radius: 9999px; display: flex; overflow: hidden; }
.balance-bar__payables { background: #ef4444; transition: width 1s ease; }
.balance-bar__receivables { background: #22c55e; transition: width 1s ease; }

/* =========================================
   TABS
   ========================================= */
.tab-nav { display: flex; gap: 0.5rem; padding: 0.25rem; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-radius: 1rem; width: fit-content; flex-wrap: wrap; }
.tab-btn { display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; transition: all .2s; cursor: pointer; background: transparent; border: none; color: var(--text-muted, #94a3b8); }
.tab-btn:hover { color: white; background: rgba(255,255,255,.05); }
.tab-btn--active { background: var(--gold, #d4a843); color: #000; }
.tab-badge { font-size: 0.6875rem; background: rgba(255,255,255,.2); border-radius: 9999px; padding: 0 0.375rem; font-weight: 900; }

/* =========================================
   DATA CARD
   ========================================= */
.data-card { background: var(--card-bg, #1e293b); border: 1px solid rgba(255,255,255,.08); border-radius: 1rem; overflow: hidden; }
.data-card__header { display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; background: rgba(255,255,255,.03); border-bottom: 1px solid rgba(255,255,255,.08); }
.data-card__title { font-size: 1rem; font-weight: 800; color: white; display: flex; align-items: center; gap: 0.5rem; }
.data-card__sub { font-size: 0.75rem; color: var(--text-muted, #94a3b8); margin-top: 0.25rem; }

/* =========================================
   TABLE
   ========================================= */
.table-scroll { overflow-x: auto; }
.data-table { width: 100%; text-align: right; border-collapse: collapse; }
.data-table thead tr { background: rgba(255,255,255,.03); border-bottom: 1px solid rgba(255,255,255,.08); }
.data-table th { padding: 0.875rem 1.25rem; font-size: 0.6875rem; font-weight: 700; color: var(--text-muted, #94a3b8); text-transform: uppercase; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid rgba(255,255,255,.04); transition: background .15s; }
.data-table tbody tr:hover { background: rgba(255,255,255,.03); }
.data-table td { padding: 0.875rem 1.25rem; font-size: 0.875rem; color: rgba(255,255,255,.8); }
.empty-row { text-align: center; padding: 2.5rem 1rem !important; color: var(--text-muted, #94a3b8); font-style: italic; }

/* =========================================
   SEARCH / FILTER
   ========================================= */
.search-bar, .filter-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,.06); }
.search-input { flex: 1; background: transparent; border: none; outline: none; color: white; font-size: 0.875rem; }
.search-input::placeholder { color: var(--text-muted, #94a3b8); }
.filter-select { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-radius: 0.5rem; color: white; padding: 0.375rem 0.75rem; font-size: 0.75rem; outline: none; }

/* =========================================
   BADGES / TAGS
   ========================================= */
.entity-badge { padding: 0.25rem 0.625rem; border-radius: 0.375rem; font-size: 0.6875rem; font-weight: 700; white-space: nowrap; }
.entity-badge--customer { background: rgba(212,168,67,.1); color: var(--gold, #d4a843); }
.entity-badge--group { background: rgba(59,130,246,.1); color: #60a5fa; }
.entity-badge--supplier { background: rgba(239,68,68,.1); color: #f87171; }
.module-tag { display: inline-block; padding: 0.25rem 0.625rem; border-radius: 0.375rem; font-size: 0.6875rem; font-weight: 700; }
.module-dot { width: 0.625rem; height: 0.625rem; border-radius: 50%; display: inline-block; }
.module-dot--blue, .module-tag.module-dot--blue { background: rgba(59,130,246,.15); color: #60a5fa; }
.module-dot--orange, .module-tag.module-dot--orange { background: rgba(249,115,22,.15); color: #fb923c; }
.module-dot--green, .module-tag.module-dot--green { background: rgba(34,197,94,.15); color: #4ade80; }
.module-dot--purple, .module-tag.module-dot--purple { background: rgba(168,85,247,.15); color: #c084fc; }
.module-dot--teal, .module-tag.module-dot--teal { background: rgba(20,184,166,.15); color: #2dd4bf; }
.module-dot--sky, .module-tag.module-dot--sky { background: rgba(14,165,233,.15); color: #38bdf8; }
.module-dot--gray, .module-tag.module-dot--gray { background: rgba(255,255,255,.08); color: #94a3b8; }

/* =========================================
   PERFORMANCE BAR
   ========================================= */
.perf-bar-wrap { display: flex; align-items: center; gap: 0.5rem; }
.perf-bar { width: 5rem; height: 0.375rem; background: rgba(255,255,255,.08); border-radius: 9999px; overflow: hidden; }
.perf-bar__fill { height: 100%; border-radius: 9999px; transition: width .5s; }
.perf-pct { font-size: 0.6875rem; font-weight: 700; color: var(--text-muted, #94a3b8); }

/* =========================================
   ACTION LINK
   ========================================= */
.action-link { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all .2s; }
.action-link--green { background: rgba(34,197,94,.1); color: #22c55e; border: 1px solid rgba(34,197,94,.2); }
.action-link--green:hover { background: rgba(34,197,94,.2); }
.action-link--blue { background: rgba(59,130,246,.1); color: #60a5fa; border: 1px solid rgba(59,130,246,.2); }
.action-link--blue:hover { background: rgba(59,130,246,.2); }

/* =========================================
   SKELETON & ERROR
   ========================================= */
.skeleton-row { height: 3rem; border-radius: 0.75rem; background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 50%, rgba(255,255,255,.04) 75%); background-size: 200% 100%; animation: skeleton-shimmer 1.5s infinite; }
@keyframes skeleton-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
.error-box { display: flex; align-items: center; gap: 1rem; padding: 1.5rem; background: rgba(239,68,68,.05); border: 1px solid rgba(239,68,68,.2); border-radius: 1rem; }
.btn-retry { margin-right: auto; padding: 0.5rem 1rem; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 0.5rem; color: #f87171; font-weight: 700; font-size: 0.8125rem; cursor: pointer; transition: all .2s; }
.btn-retry:hover { background: rgba(239,68,68,.2); }

/* Utilities */
.text-success { color: #22c55e; }
.text-error { color: #ef4444; }
.text-gold { color: var(--gold, #d4a843); }
.text-muted { color: var(--text-muted, #94a3b8); }
</style>
