<!--
  OperationDrilldownModal.vue
  ----------------------------
  Per-operation P&L drill-down modal.

  Opened by ProfitLoss.vue when the operator clicks any revenue / COGS /
  expense / refund line in the consolidated income statement. The modal
  reuses the same GL engine that powers the consolidated P&L
  (ProfitLossReportService via FinancialReportController::profitByOperation)
  so the row-level numbers reconcile to the cent with the amount that
  was clicked.

  Props:
    - show          (Boolean) — visibility toggle
    - module        (String)  — 'flight' | 'bus' | 'hajj_umra' | ... (may be '')
    - moduleLabel   (String)  — Arabic display name of the module
    - category      (String)  — 'revenue' | 'cogs' | 'expense' | 'refund'
    - categoryLabel (String)  — Arabic display name of the category
    - itemName      (String)  — exact line label that was clicked
    - itemAmount    (Number)  — the aggregated amount that was clicked
    - fromDate      (String)  — ISO date YYYY-MM-DD
    - toDate        (String)  — ISO date YYYY-MM-DD

  Emits:
    - close() — when the user dismisses the modal
-->
<template>
  <teleport to="body">
    <transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/75 p-4 backdrop-blur-md"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="`drilldown-heading-${uid}`"
        @click.self="close"
      >
        <transition
          enter-active-class="transition duration-300 ease-out"
          enter-from-class="opacity-0 scale-95"
          enter-to-class="opacity-100 scale-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="opacity-100 scale-100"
          leave-to-class="opacity-0 scale-95"
        >
          <div
            v-if="show"
            class="flight-panel max-h-[92vh] w-full max-w-5xl overflow-hidden !p-0 shadow-[0_0_60px_rgba(0,0,0,0.6)] border-white/10 flex flex-col"
            @click.stop
          >
            <!-- ─── HEADER ─────────────────────────────────────────────── -->
            <div class="flex items-start justify-between gap-3 border-b border-white/5 px-6 py-5">
              <div class="flex items-start gap-3 min-w-0 flex-1">
                <div
                  class="rounded-xl p-2.5 shrink-0"
                  :class="categoryIconBgClass"
                >
                  <component :is="categoryIcon" class="h-6 w-6" :class="categoryIconColorClass" />
                </div>
                <div class="min-w-0 flex-1">
                  <h3
                    :id="`drilldown-heading-${uid}`"
                    class="truncate text-xl font-black text-text-main"
                  >
                    {{ itemName || `${categoryLabel} — ${moduleLabel}` }}
                  </h3>
                  <p class="mt-1 text-xs font-bold text-text-muted flex items-center gap-2 flex-wrap">
                    <CalendarDays class="w-3.5 h-3.5" />
                    <span>الفترة: من {{ fromDate }} إلى {{ toDate }}</span>
                    <span class="text-text-muted/50">·</span>
                    <Database class="w-3.5 h-3.5" />
                    <span>مصدر البيانات: دليل الأستاذ (GL)</span>
                    <span v-if="module" class="text-text-muted/50">·</span>
                    <span v-if="module" class="inline-flex items-center gap-1 rounded-md bg-white/5 px-2 py-0.5">
                      الموديول: {{ moduleLabel }}
                    </span>
                  </p>
                </div>
              </div>
              <button
                @click="close"
                class="p-2 hover:bg-white/5 rounded-full transition-colors text-text-muted hover:text-text-main shrink-0"
                aria-label="إغلاق"
                type="button"
              >
                <X class="w-5 h-5" />
              </button>
            </div>

            <!-- ─── SUMMARY STRIP ──────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-2 border-b border-white/5 bg-white/[0.02] p-4 sm:grid-cols-4">
              <div class="rounded-xl bg-white/[0.03] p-3 border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                  عدد العمليات
                </p>
                <p class="mt-1 font-mono text-lg font-black text-text-main">
                  {{ rows.length.toLocaleString('en-US') }}
                </p>
              </div>
              <div class="rounded-xl p-3 border" :class="summaryTotalBg">
                <p class="text-[10px] font-bold uppercase tracking-wider" :class="summaryTotalLabel">
                  إجمالي المبلغ
                </p>
                <p class="mt-1 font-mono text-lg font-black" :class="summaryTotalText">
                  {{ formatCurrency(filteredRows.reduce((s, r) => s + r.amount, 0)) }}
                </p>
              </div>
              <div class="rounded-xl bg-white/[0.03] p-3 border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                  متوسط العملية
                </p>
                <p class="mt-1 font-mono text-lg font-black text-text-main">
                  {{ formatCurrency(averageAmount) }}
                </p>
              </div>
              <div class="rounded-xl bg-white/[0.03] p-3 border border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                  أكبر عملية
                </p>
                <p class="mt-1 font-mono text-lg font-black text-text-main">
                  {{ formatCurrency(maxAmount) }}
                </p>
              </div>
            </div>

            <!-- ─── IN-MODAL FILTERS ───────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-2 border-b border-white/5 bg-white/[0.01] p-4">
              <!-- Search input -->
              <div class="flex items-center gap-2 flex-1 min-w-[220px] bg-white/[0.03] border border-white/10 rounded-lg px-3 py-1.5 focus-within:border-gold/50 transition-colors">
                <Search class="w-4 h-4 text-text-muted shrink-0" />
                <input
                  v-model="searchQuery"
                  type="search"
                  placeholder="بحث في الوصف / رقم القيد / الحسابات..."
                  class="flex-1 bg-transparent text-sm font-bold text-text-main placeholder:text-text-muted/70 focus:outline-none"
                  @keydown.enter="refetch"
                />
                <button
                  v-if="searchQuery"
                  @click="searchQuery = ''"
                  type="button"
                  class="shrink-0 p-0.5 hover:bg-white/10 rounded transition-colors"
                  aria-label="مسح البحث"
                >
                  <X class="w-3.5 h-3.5 text-text-muted" />
                </button>
              </div>

              <!-- Custom category dropdown (replaces native <select> which renders gray in dark theme) -->
              <div class="relative" ref="categoryDropdownRef">
                <button
                  @click="categoryDropdownOpen = !categoryDropdownOpen"
                  type="button"
                  class="flex items-center justify-between gap-2 bg-white/[0.03] hover:bg-white/[0.06] border border-white/10 rounded-lg pl-3 pr-2 py-1.5 text-sm font-bold text-text-main focus:outline-none focus:border-gold/50 transition-colors min-w-[170px]"
                  :class="{ 'border-gold/50 bg-gold/5': categoryDropdownOpen }"
                >
                  <span class="flex items-center gap-2 min-w-0">
                    <Filter class="w-3.5 h-3.5 text-gold shrink-0" />
                    <span class="truncate">{{ selectedCategoryLabel }}</span>
                  </span>
                  <ChevronDown
                    class="w-4 h-4 text-text-muted transition-transform shrink-0"
                    :class="{ 'rotate-180 text-gold': categoryDropdownOpen }"
                  />
                </button>

                <transition
                  enter-active-class="transition duration-150 ease-out"
                  enter-from-class="opacity-0 -translate-y-1 scale-95"
                  enter-to-class="opacity-100 translate-y-0 scale-100"
                  leave-active-class="transition duration-100 ease-in"
                  leave-from-class="opacity-100 translate-y-0 scale-100"
                  leave-to-class="opacity-0 -translate-y-1 scale-95"
                >
                  <div
                    v-if="categoryDropdownOpen"
                    class="absolute top-full mt-1 left-0 z-[210] min-w-[200px] bg-[#1a1d2e] border border-white/10 rounded-xl shadow-2xl shadow-black/50 overflow-hidden"
                  >
                    <div class="p-1">
                      <button
                        v-for="opt in categoryOptions"
                        :key="opt.value || 'all'"
                        @click="selectCategory(opt.value)"
                        type="button"
                        class="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm font-bold rounded-lg text-text-main hover:bg-white/10 transition-colors text-right"
                        :class="{ 'bg-gold/10 text-gold': localCategory === opt.value }"
                      >
                        <span class="flex items-center gap-2">
                          <span
                            class="w-2 h-2 rounded-full shrink-0"
                            :class="opt.colorClass"
                          ></span>
                          <span>{{ opt.label }}</span>
                        </span>
                        <Check v-if="localCategory === opt.value" class="w-4 h-4 text-gold shrink-0" />
                      </button>
                    </div>
                  </div>
                </transition>
              </div>

              <!-- Date range -->
              <div class="flex items-center gap-1.5 bg-white/[0.03] border border-white/10 rounded-lg px-2 py-1 focus-within:border-gold/50 transition-colors">
                <input
                  v-model="localFrom"
                  type="date"
                  @change="refetch"
                  class="bg-transparent text-sm font-bold text-text-main focus:outline-none w-[130px]"
                />
                <ArrowRight class="w-3 h-3 text-text-muted" />
                <input
                  v-model="localTo"
                  type="date"
                  @change="refetch"
                  class="bg-transparent text-sm font-bold text-text-main focus:outline-none w-[130px]"
                />
              </div>

              <!-- Refresh button -->
              <button
                @click="refetch"
                :disabled="loading"
                type="button"
                class="bg-gold/10 hover:bg-gold/20 text-gold border border-gold/30 rounded-lg px-3 py-1.5 text-sm font-black transition-colors flex items-center gap-1.5 disabled:opacity-50"
              >
                <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
                تحديث
              </button>

              <!-- Reset button (only when filters are applied) -->
              <button
                v-if="searchQuery || localCategory"
                @click="resetFilters"
                type="button"
                class="bg-white/5 hover:bg-white/10 text-text-muted hover:text-text-main border border-white/10 rounded-lg px-3 py-1.5 text-sm font-bold transition-colors flex items-center gap-1.5"
                title="مسح كل الفلاتر"
              >
                <X class="w-3.5 h-3.5" />
                مسح
              </button>
            </div>

            <!-- ─── BODY ───────────────────────────────────────────────── -->
            <div class="flex-1 overflow-y-auto">
              <!-- LOADING -->
              <div v-if="loading" class="p-4 space-y-2">
                <div v-for="i in 8" :key="`sk-${i}`" class="flex gap-2">
                  <TextLineSkeleton class="h-9 w-16" />
                  <TextLineSkeleton class="h-9 flex-1" />
                  <TextLineSkeleton class="h-9 w-32" />
                  <TextLineSkeleton class="h-9 w-32" />
                  <TextLineSkeleton class="h-9 w-28" />
                  <TextLineSkeleton class="h-9 w-24" />
                </div>
              </div>

              <!-- ERROR -->
              <div
                v-else-if="error"
                class="p-8 text-center"
              >
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-error/10 mb-3">
                  <AlertCircle class="w-7 h-7 text-error" />
                </div>
                <p class="text-error font-bold mb-4">{{ error }}</p>
                <button
                  @click="refetch"
                  type="button"
                  class="bg-error/10 hover:bg-error/20 text-error border border-error/30 rounded-lg px-4 py-2 text-sm font-black transition-colors inline-flex items-center gap-2"
                >
                  <RefreshCw class="w-4 h-4" />
                  إعادة المحاولة
                </button>
              </div>

              <!-- EMPTY -->
              <div
                v-else-if="filteredRows.length === 0"
                class="p-12 text-center"
              >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/5 mb-4 ring-1 ring-white/10">
                  <Inbox class="w-8 h-8 text-text-muted" />
                </div>
                <p class="text-text-main font-black text-lg mb-2">
                  لا توجد عمليات تطابق الفلاتر المحددة
                </p>
                <p class="text-text-muted text-sm mb-4 max-w-md mx-auto">
                  <span v-if="rows.length > 0">
                    تم استرجاع
                    <span class="font-bold text-text-main">{{ rows.length.toLocaleString('en-US') }}</span>
                    عملية من الخادم، لكن البحث/الفلاتر تخفيهم.
                  </span>
                  <span v-else>
                    لا توجد عمليات من النوع المحدد في هذه الفترة. جرب توسيع نطاق التاريخ أو اختيار نوع آخر.
                  </span>
                </p>

                <!-- Show active filters as badges -->
                <div v-if="searchQuery || localCategory" class="flex items-center justify-center gap-2 mb-4 flex-wrap">
                  <span v-if="localCategory" class="inline-flex items-center gap-1.5 bg-gold/10 text-gold border border-gold/30 rounded-full px-3 py-1 text-xs font-bold">
                    <Filter class="w-3 h-3" />
                    {{ selectedCategoryLabel }}
                    <button @click="selectCategory('')" type="button" class="hover:bg-gold/20 rounded-full p-0.5">
                      <X class="w-3 h-3" />
                    </button>
                  </span>
                  <span v-if="searchQuery" class="inline-flex items-center gap-1.5 bg-white/10 text-text-main border border-white/20 rounded-full px-3 py-1 text-xs font-bold">
                    <Search class="w-3 h-3" />
                    "{{ searchQuery }}"
                    <button @click="searchQuery = ''" type="button" class="hover:bg-white/20 rounded-full p-0.5">
                      <X class="w-3 h-3" />
                    </button>
                  </span>
                </div>

                <button
                  v-if="searchQuery || localCategory"
                  @click="resetFilters"
                  type="button"
                  class="bg-gold/10 hover:bg-gold/20 text-gold border border-gold/30 rounded-lg px-4 py-2 text-sm font-black transition-colors inline-flex items-center gap-2"
                >
                  <X class="w-4 h-4" />
                  مسح كل الفلاتر
                </button>
              </div>

              <!-- TABLE -->
              <div v-else class="overflow-x-auto">
                <table class="w-full text-right border-collapse min-w-[820px]">
                  <thead class="sticky top-0 z-10 bg-[#1a1d2e]/95 backdrop-blur-sm">
                    <tr class="text-xs font-black uppercase tracking-wider text-text-muted border-b border-white/10">
                      <th class="px-3 py-3 text-center w-12">#</th>
                      <th class="px-3 py-3">التاريخ والوقت</th>
                      <th class="px-3 py-3">النوع</th>
                      <th class="px-3 py-3">من حساب</th>
                      <th class="px-3 py-3">إلى حساب</th>
                      <th class="px-3 py-3">الوصف</th>
                      <th class="px-3 py-3">الكيان المرتبط</th>
                      <th class="px-3 py-3 text-left">المبلغ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(row, idx) in filteredRows"
                      :key="row.transaction_id"
                      class="border-t border-white/5 hover:bg-white/[0.03] transition-colors group"
                    >
                      <td class="px-3 py-2.5 text-center font-mono text-xs text-text-muted">
                        {{ idx + 1 }}
                      </td>
                      <td class="px-3 py-2.5 font-mono text-xs font-bold text-text-main whitespace-nowrap">
                        {{ formatDateTime(row.date) }}
                      </td>
                      <td class="px-3 py-2.5">
                        <span
                          class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10px] font-black"
                          :class="classificationBadgeClass(row.classification)"
                        >
                          {{ classificationLabel(row.classification) }}
                        </span>
                      </td>
                      <td class="px-3 py-2.5 text-xs text-text-main">
                        <div class="font-bold">{{ row.from_account?.name || '—' }}</div>
                        <div class="text-[10px] text-text-muted mt-0.5">
                          {{ accountTypeLabel(row.from_account?.type) }}
                        </div>
                      </td>
                      <td class="px-3 py-2.5 text-xs text-text-main">
                        <div class="font-bold">{{ row.to_account?.name || '—' }}</div>
                        <div class="text-[10px] text-text-muted mt-0.5">
                          {{ accountTypeLabel(row.to_account?.type) }}
                        </div>
                      </td>
                      <td class="px-3 py-2.5 text-xs text-text-main max-w-[260px]">
                        <div class="truncate" :title="row.notes">
                          {{ row.notes || '—' }}
                        </div>
                      </td>
                      <td class="px-3 py-2.5">
                        <div v-if="row.related_type" class="inline-flex items-center gap-1.5 rounded-md bg-white/5 border border-white/10 px-2 py-1">
                          <Link2 class="w-3 h-3 text-gold shrink-0" />
                          <span class="text-[10px] font-black text-text-main">
                            {{ relatedTypeShortLabel(row.related_type) }}
                          </span>
                          <span class="text-[10px] font-mono text-text-muted">
                            #{{ row.related_id }}
                          </span>
                        </div>
                        <span v-else class="text-text-muted text-xs">—</span>
                      </td>
                      <td class="px-3 py-2.5 text-left whitespace-nowrap">
                        <div
                          class="font-mono text-sm font-black"
                          :class="row.amount >= 0 ? 'text-success' : 'text-error'"
                        >
                          {{ formatCurrency(row.amount) }}
                        </div>
                        <div
                          v-if="row.amount < 0"
                          class="text-[10px] font-bold text-error/70 mt-0.5"
                        >
                          (عكس / مرتجع)
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ─── FOOTER ─────────────────────────────────────────────── -->
            <div class="flex items-center justify-between gap-3 border-t border-white/5 bg-white/[0.02] px-6 py-3">
              <div class="text-xs font-bold text-text-muted flex items-center gap-3">
                <span>
                  عرض
                  <span class="text-text-main font-black">{{ filteredRows.length.toLocaleString('en-US') }}</span>
                  من
                  <span class="text-text-main font-black">{{ rows.length.toLocaleString('en-US') }}</span>
                  عملية
                </span>
                <span v-if="searchQuery || localCategory" class="inline-flex items-center gap-1 text-gold">
                  <Filter class="w-3 h-3" />
                  فلاتر مطبقة
                </span>
              </div>
              <div class="flex items-center gap-2">
                <button
                  @click="close"
                  type="button"
                  class="bg-white/5 hover:bg-white/10 text-text-main border border-white/10 rounded-lg px-4 py-1.5 text-sm font-black transition-colors"
                >
                  إغلاق
                </button>
              </div>
            </div>
          </div>
        </transition>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
  X,
  Search,
  RefreshCw,
  AlertCircle,
  Inbox,
  CalendarDays,
  Database,
  Filter,
  Link2,
  TrendingUp,
  TrendingDown,
  Coins,
  CircleDollarSign,
  ChevronDown,
  Check,
  ArrowRight,
} from 'lucide-vue-next';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';

// ─── Props / Emits ─────────────────────────────────────────────
const props = defineProps({
  show:          { type: Boolean, default: false },
  module:        { type: String,  default: '' },
  moduleLabel:   { type: String,  default: 'عام' },
  category:      { type: String,  default: '' },
  categoryLabel: { type: String,  default: '' },
  itemName:      { type: String,  default: '' },
  itemAmount:    { type: Number,  default: 0 },
  fromDate:      { type: String,  default: '' },
  toDate:        { type: String,  default: '' },
});
const emit = defineEmits(['close']);

// ─── State ─────────────────────────────────────────────────────
const uid = Math.random().toString(36).slice(2, 9); // unique id for ARIA
const loading = ref(false);
const error = ref('');
const rows = ref([]);
const totals = ref({ income: 0, cogs: 0, expense: 0, profit: 0 });

const localFrom = ref(props.fromDate);
const localTo = ref(props.toDate);
const localCategory = ref(props.category || '');
const searchQuery = ref('');

// Custom dropdown state (replaces native <select> which renders gray in dark theme)
const categoryDropdownOpen = ref(false);
const categoryDropdownRef = ref(null);

const categoryOptions = [
  { value: '',        label: 'كل الأنواع',    colorClass: 'bg-text-muted' },
  { value: 'revenue', label: 'إيرادات فقط',   colorClass: 'bg-success' },
  { value: 'cogs',    label: 'تكاليف فقط',    colorClass: 'bg-amber-400' },
  { value: 'refund',  label: 'مرتجعات فقط',   colorClass: 'bg-amber-500' },
  { value: 'expense', label: 'مصروفات فقط',   colorClass: 'bg-error' },
];

const selectedCategoryLabel = computed(() => {
  const opt = categoryOptions.find((o) => o.value === localCategory.value);
  return opt?.label || 'كل الأنواع';
});

function selectCategory(value) {
  localCategory.value = value;
  categoryDropdownOpen.value = false;
  refetch();
}

// Sync local filters when the modal is reopened with fresh props
watch(
  () => [props.show, props.fromDate, props.toDate, props.category],
  ([isShow, from, to, cat]) => {
    if (isShow) {
      localFrom.value = from;
      localTo.value = to;
      localCategory.value = cat || '';
      searchQuery.value = '';
      fetchOperations();
    }
  }
);

// ─── Constants ─────────────────────────────────────────────────
// Mirrors the backend whitelist at FinancialReportController.php:34-36
// Anything outside this set would get HTTP 422 from
// /api/v1/reports/profit-by-operation, so we block it client-side
// with a friendly Arabic message instead of letting it 422.
const PROFIT_DRILLDOWN_MODULES = [
  'flight', 'bus', 'hajj_umra', 'visa', 'fawry', 'online', 'wallet',
];

const isModuleWhitelisted = computed(() => {
  if (!props.module) return true; // no module = cross-module query, allowed
  return PROFIT_DRILLDOWN_MODULES.includes(props.module);
});

// ─── Computeds ─────────────────────────────────────────────────
const filteredRows = computed(() => {
  let list = rows.value;
  if (localCategory.value) {
    list = list.filter((r) => r.classification === localCategory.value);
  }
  const q = searchQuery.value.trim().toLowerCase();
  if (q) {
    list = list.filter((r) => {
      const haystack = [
        r.notes,
        r.transaction_id,
        r.from_account?.name,
        r.to_account?.name,
        r.related_type,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      return haystack.includes(q);
    });
  }
  return list;
});

const totalAmount = computed(() =>
  filteredRows.value.reduce((sum, r) => sum + (Number(r.amount) || 0), 0)
);

const averageAmount = computed(() =>
  filteredRows.value.length === 0
    ? 0
    : totalAmount.value / filteredRows.value.length
);

const maxAmount = computed(() =>
  filteredRows.value.length === 0
    ? 0
    : filteredRows.value.reduce(
        (m, r) => Math.max(m, Math.abs(Number(r.amount) || 0)),
        0
      )
);

const categoryIcon = computed(() => {
  switch (props.category) {
    case 'revenue':
      return TrendingUp;
    case 'cogs':
      return Coins;
    case 'refund':
      return CircleDollarSign;
    case 'expense':
      return TrendingDown;
    default:
      return CircleDollarSign;
  }
});

const categoryIconBgClass = computed(() => {
  switch (props.category) {
    case 'revenue':
      return 'bg-success/10';
    case 'cogs':
      return 'bg-amber-500/10';
    case 'refund':
      return 'bg-amber-500/10';
    case 'expense':
      return 'bg-error/10';
    default:
      return 'bg-white/5';
  }
});

const categoryIconColorClass = computed(() => {
  switch (props.category) {
    case 'revenue':
      return 'text-success';
    case 'cogs':
      return 'text-amber-400';
    case 'refund':
      return 'text-amber-400';
    case 'expense':
      return 'text-error';
    default:
      return 'text-text-muted';
  }
});

const summaryTotalBg = computed(() => {
  const total = totalAmount.value;
  if (total >= 0) return 'bg-success/5 border-success/20';
  return 'bg-error/5 border-error/20';
});

const summaryTotalLabel = computed(() =>
  totalAmount.value >= 0 ? 'text-success/80' : 'text-error/80'
);

const summaryTotalText = computed(() =>
  totalAmount.value >= 0 ? 'text-success' : 'text-error'
);

// ─── API call ──────────────────────────────────────────────────
async function fetchOperations() {
  if (!isModuleWhitelisted.value && props.module !== '') {
    error.value = `التفصيل غير متاح لهذا القسم («${props.module}»). البيانات موجودة في كشف الحساب. الأقسام المدعومة: ${PROFIT_DRILLDOWN_MODULES.join(' / ')}.`;
    rows.value = [];
    return;
  }

  loading.value = true;
  error.value = '';
  try {
    const params = {
      from_date: localFrom.value,
      to_date: localTo.value,
      limit: 1000,
      _t: Date.now(),
    };
    if (props.module) params.module = props.module;
    if (localCategory.value) params.category = localCategory.value;

    const { data } = await axios.get('/api/v1/reports/profit-by-operation', {
      params,
    });
    const payload = data?.data ?? data;
    rows.value = Array.isArray(payload?.rows) ? payload.rows : [];
    totals.value = payload?.totals ?? { income: 0, cogs: 0, expense: 0, profit: 0 };
  } catch (e) {
    error.value =
      e?.response?.data?.message || e?.message || 'فشل تحميل التفاصيل';
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function refetch() {
  fetchOperations();
}

function resetFilters() {
  searchQuery.value = '';
  localCategory.value = '';
  refetch();
}

// ─── Helpers ───────────────────────────────────────────────────
function formatCurrency(value) {
  const num = Number(value) || 0;
  return (
    num.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }) + ' EGP'
  );
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return dateStr;
    const date = d.toLocaleDateString('en-GB', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    });
    const time = d.toLocaleTimeString('en-GB', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });
    return `${date} ${time}`;
  } catch {
    return dateStr;
  }
}

function classificationLabel(cls) {
  return (
    {
      revenue: 'إيراد',
      refund: 'مرتجع',
      cogs: 'تكلفة',
      expense: 'مصروف',
    }[cls] || cls
  );
}

function classificationBadgeClass(cls) {
  return (
    {
      revenue: 'bg-success/15 text-success border border-success/30',
      refund: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
      cogs: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
      expense: 'bg-error/15 text-error border border-error/30',
    }[cls] || 'bg-white/5 text-text-muted border border-white/10'
  );
}

function accountTypeLabel(type) {
  return (
    {
      cashbox: 'خزينة نقدي',
      wallet: 'محفظة إلكترونية',
      bank: 'حساب بنكي',
      treasury: 'خزينة عامة',
      asset: 'أصل',
      liability: 'التزام',
      revenue: 'إيراد',
      expense: 'مصروف',
      income: 'إيراد (مقاصة)',
      contra: 'حساب وسيط',
      clearing: 'حساب إقفال',
    }[type] || type || ''
  );
}

function relatedTypeShortLabel(fqcn) {
  if (!fqcn) return '';
  // Map FQCNs to short Arabic labels
  const map = {
    'App\\Models\\Flight\\FlightBooking': 'حجز طيران',
    'App\\Models\\Bus\\BusBooking': 'حجز باص',
    'App\\Models\\HajjUmraBooking': 'حجز حج/عمرة',
    'App\\Models\\VisaBooking': 'حجز تأشيرة',
    'App\\Models\\Fawry\\FawryTransaction': 'عملية فوري',
    'App\\Models\\Online\\OnlineTransaction': 'عملية إلكترونية',
    'App\\Models\\Wallet\\WalletTransaction': 'تحويل محفظة',
    'App\\Models\\Customer': 'عميل',
    'App\\Models\\Supplier': 'مورد',
    'App\\Models\\Account': 'حساب',
  };
  return map[fqcn] || fqcn.split('\\').pop() || fqcn;
}

// ─── Keyboard handling ─────────────────────────────────────────
function onKeydown(e) {
  if (e.key === 'Escape' && props.show) {
    if (categoryDropdownOpen.value) {
      categoryDropdownOpen.value = false;
    } else {
      close();
    }
  }
}

function closeDropdownsOnClickOutside(e) {
  if (categoryDropdownRef.value && !categoryDropdownRef.value.contains(e.target)) {
    categoryDropdownOpen.value = false;
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('mousedown', closeDropdownsOnClickOutside);
  }
});

onBeforeUnmount(() => {
  if (typeof document !== 'undefined') {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('mousedown', closeDropdownsOnClickOutside);
  }
});

function close() {
  emit('close');
}
</script>

<style scoped>
/* Smooth scrolling inside modal body */
.overflow-y-auto {
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
}

.overflow-y-auto::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.25);
}

/* Hide the modal from print */
@media print {
  [role='dialog'] {
    display: none !important;
  }
}
</style>