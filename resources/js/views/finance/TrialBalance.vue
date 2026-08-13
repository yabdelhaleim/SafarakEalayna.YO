<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-display font-black text-transparent bg-clip-text bg-gradient-to-l from-indigo-400 to-blue-600 flex items-center gap-3">
          <Scale class="w-10 h-10 text-indigo-500" />
          ميزان الحسابات
        </h1>
        <p class="text-sm text-text-muted mt-2 font-medium">
          جرد تفصيلي لكل حساب في قسم {{ division === 'office' ? 'المكتب' : 'السياحة' }}: إجمالي المدين (debit) والدائن (credit) والرصيد الصافي.
        </p>
      </div>

      <div class="flex flex-col sm:flex-row gap-2">
        <select
          v-model="division"
          @change="fetchReport"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors"
        >
          <option value="tourism">قسم السياحة</option>
          <option value="office">قسم المكتب</option>
        </select>
        <select
          v-model="accountTypeFilter"
          class="bg-input-bg border border-white/10 text-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-indigo-500 transition-colors"
        >
          <option value="">كل أنواع الحسابات</option>
          <option value="cashbox">خزائن</option>
          <option value="bank">بنوك</option>
          <option value="wallet">محافظ</option>
          <option value="supplier">موردين</option>
          <option value="customer">عملاء</option>
          <option value="expense">مصروف</option>
          <option value="revenue">إيراد</option>
          <option value="liability">التزام</option>
          <option value="owner">حقوق ملكية</option>
        </select>
        <label class="flex items-center gap-2 text-xs text-white/60 px-3">
          <input
            type="checkbox"
            v-model="hideZeroBalance"
            class="rounded border-white/20 bg-input-bg"
          />
          إخفاء الحسابات صفرية الرصيد
        </label>
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

    <!-- Error -->
    <div v-if="globalError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-xl">
      {{ globalError }}
    </div>

    <!-- Summary KPIs -->
    <div v-if="isLoading()" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <KPICardSkeleton v-for="i in 4" :key="`kpi-${i}`" />
    </div>
    <div v-else-if="report" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="bg-card-bg border border-white/5 p-5 rounded-2xl shadow-xl">
        <p class="text-xs font-medium text-text-muted">إجمالي الحسابات</p>
        <h3 class="text-2xl font-bold text-white mt-1 tabular-nums">{{ report.meta?.account_count ?? 0 }}</h3>
        <p class="text-[10px] text-text-muted mt-2">حساب في قسم {{ division === 'office' ? 'المكتب' : 'السياحة' }}</p>
      </div>
      <div class="bg-card-bg border border-white/5 p-5 rounded-2xl shadow-xl">
        <p class="text-xs font-medium text-text-muted">إجمالي المدين (Debit)</p>
        <h3 class="text-2xl font-bold text-rose-300 mt-1 tabular-nums">{{ formatCurrency(report.totals?.total_debit || 0) }}</h3>
        <p class="text-[10px] text-text-muted mt-2">مجموع كل الخصومات</p>
      </div>
      <div class="bg-card-bg border border-white/5 p-5 rounded-2xl shadow-xl">
        <p class="text-xs font-medium text-text-muted">إجمالي الدائن (Credit)</p>
        <h3 class="text-2xl font-bold text-emerald-300 mt-1 tabular-nums">{{ formatCurrency(report.totals?.total_credit || 0) }}</h3>
        <p class="text-[10px] text-text-muted mt-2">مجموع كل الإضافات</p>
      </div>
      <div
        class="border p-5 rounded-2xl shadow-xl"
        :class="report.totals?.balanced
          ? 'bg-emerald-500/10 border-emerald-500/30'
          : 'bg-rose-500/10 border-rose-500/30'"
      >
        <p class="text-xs font-medium text-white/70">الفرق (مدين − دائن)</p>
        <h3
          class="text-2xl font-bold mt-1 tabular-nums"
          :class="report.totals?.balanced ? 'text-emerald-300' : 'text-rose-300'"
        >
          {{ formatCurrency(Math.abs(report.totals?.difference || 0)) }}
        </h3>
        <p
          class="text-[10px] mt-2 font-bold"
          :class="report.totals?.balanced ? 'text-emerald-300' : 'text-rose-300'"
        >
          <span v-if="report.totals?.balanced">✅ الميزان متساوي</span>
          <span v-else>⚠️ يوجد عجز / زيادة</span>
        </p>
      </div>
    </div>

    <!-- Per-account breakdown table -->
    <div class="bg-card-bg border border-white/5 rounded-2xl shadow-xl overflow-hidden">
      <div class="p-4 border-b border-white/5 flex items-center justify-between flex-wrap gap-2">
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <BarChart2 class="w-4 h-4 text-indigo-400" />
          تفاصيل حسابات {{ division === 'office' ? 'المكتب' : 'السياحة' }}
        </h2>
        <span class="text-[10px] text-text-muted" v-if="report && filteredAccounts.length !== report.accounts.length">
          يعرض {{ filteredAccounts.length }} من {{ report.accounts.length }} حساب
        </span>
      </div>

      <div v-if="isLoading()" class="p-8 space-y-3">
        <div v-for="i in 5" :key="`row-${i}`" class="h-10 rounded bg-white/5 animate-pulse"></div>
      </div>
      <div v-else-if="filteredAccounts.length === 0" class="py-12 text-center text-white/20">
        لا توجد حسابات مطابقة للفلاتر.
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-right text-xs">
          <thead class="bg-white/5 text-white/50 uppercase tracking-wider">
            <tr>
              <th class="px-3 py-3">#</th>
              <th class="px-3 py-3">الحساب</th>
              <th class="px-3 py-3">النوع</th>
              <th class="px-3 py-3">موديول</th>
              <th class="px-3 py-3">العملة</th>
              <th class="px-3 py-3 text-rose-300">إجمالي مدين</th>
              <th class="px-3 py-3 text-emerald-300">إجمالي دائن</th>
              <th class="px-3 py-3">الرصيد الصافي</th>
              <th class="px-3 py-3">المخزن</th>
              <th class="px-3 py-3">عدد الحركات</th>
              <th class="px-3 py-3">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <tr
              v-for="row in filteredAccounts"
              :key="row.account_id"
              class="hover:bg-white/[0.02]"
              :class="{
                'bg-rose-500/5': row.account_balance != null && Math.abs(row.account_balance - (Number(row.total_debit) - Number(row.total_credit))) > 0.01,
              }"
            >
              <td class="px-3 py-2 font-mono text-white/40">#{{ row.account_id }}</td>
              <td class="px-3 py-2 font-bold text-white">{{ row.account_name }}</td>
              <td class="px-3 py-2">
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-white/5 text-white/70">
                  {{ accountTypeLabel(row.account_type) }}
                </span>
              </td>
              <td class="px-3 py-2 text-white/60">{{ row.account_module || '—' }}</td>
              <td class="px-3 py-2 font-mono text-white/50">{{ row.account_currency || 'EGP' }}</td>
              <td class="px-3 py-2 font-mono tabular-nums text-rose-300">
                {{ formatCurrency(row.total_debit) }}
              </td>
              <td class="px-3 py-2 font-mono tabular-nums text-emerald-300">
                {{ formatCurrency(row.total_credit) }}
              </td>
              <td
                class="px-3 py-2 font-mono font-bold tabular-nums"
                :class="Number(row.net_balance) >= 0 ? 'text-emerald-300' : 'text-rose-300'"
              >
                {{ formatCurrency(row.net_balance) }}
              </td>
              <td class="px-3 py-2 font-mono text-white/70">
                {{ formatCurrency(row.account_balance) }}
              </td>
              <td class="px-3 py-2 font-mono text-white/50 tabular-nums">{{ row.transaction_count }}</td>
              <td class="px-3 py-2">
                <router-link
                  v-if="row.account_type === 'cashbox' || row.account_type === 'bank' || row.account_type === 'wallet'"
                  :to="{ name: 'finance.accounts', query: { highlight: row.account_id } }"
                  class="text-[10px] font-bold text-sky-400 hover:text-sky-300"
                >
                  كشف الحساب ←
                </router-link>
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-indigo-500/10 border-t-2 border-indigo-500/30">
            <tr>
              <td colspan="5" class="px-3 py-3 text-indigo-200 font-bold text-right">الإجماليات</td>
              <td class="px-3 py-3 font-mono font-bold tabular-nums text-rose-200">
                {{ formatCurrency(filteredTotals.total_debit) }}
              </td>
              <td class="px-3 py-3 font-mono font-bold tabular-nums text-emerald-200">
                {{ formatCurrency(filteredTotals.total_credit) }}
              </td>
              <td
                class="px-3 py-3 font-mono font-bold tabular-nums"
                :class="filteredTotals.total_debit - filteredTotals.total_credit >= 0 ? 'text-emerald-200' : 'text-rose-200'"
              >
                {{ formatCurrency(filteredTotals.total_debit - filteredTotals.total_credit) }}
              </td>
              <td colspan="3" class="px-3 py-3 text-indigo-200 text-[10px]">
                {{ filteredAccounts.length }} حساب
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Math explanation -->
    <div v-if="report" class="bg-card-bg border border-white/5 rounded-2xl p-4 text-[10px] text-white/50 leading-relaxed">
      <strong class="text-white/70">تعريف الأعمدة:</strong>
      <span class="mx-2">•</span>
      <strong>إجمالي مدين:</strong> مجموع <code>account_entries.debit</code> لكل القيود على الحساب.
      <span class="mx-2">•</span>
      <strong>إجمالي دائن:</strong> مجموع <code>account_entries.credit</code>.
      <span class="mx-2">•</span>
      <strong>الرصيد الصافي:</strong> دائن − مدين (القاعدة المحاسبية: موجب = دائن، سالب = مدين).
      <span class="mx-2">•</span>
      <strong>المخزن:</strong> قيمة <code>accounts.balance</code> المحفوظة (للمطابقة).
      <br/>
      <strong class="text-white/70 mt-2 block">تحقق:</strong>
      لو الرصيد الصافي ≠ المخزن ← الـrow بيظهر بـ خلفية وردية خفيفة. ده معناه في فرق بين
      الـAccount.balance المحسوب يدوياً واللي في جدول الحسابات.
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import { useAsyncState } from '@/composables/useAsyncState';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import { Scale, RefreshCw, BarChart2 } from 'lucide-vue-next';

const { setLoading, setSuccess, setError, isLoading } = useAsyncState('loading');

const division = ref('office');
const accountTypeFilter = ref('');
const hideZeroBalance = ref(false);
const report = ref(null);
const globalError = ref('');
let fetchController = null;

const filteredAccounts = computed(() => {
  if (!report.value?.accounts) return [];
  let rows = report.value.accounts;
  if (accountTypeFilter.value) {
    rows = rows.filter((r) => r.account_type === accountTypeFilter.value);
  }
  if (hideZeroBalance.value) {
    rows = rows.filter((r) => Math.abs(Number(r.net_balance) || 0) > 0.01);
  }
  return rows;
});

const filteredTotals = computed(() => {
  let total_debit = 0;
  let total_credit = 0;
  for (const r of filteredAccounts.value) {
    total_debit += Number(r.total_debit) || 0;
    total_credit += Number(r.total_credit) || 0;
  }
  return { total_debit, total_credit };
});

const accountTypeLabel = (t) => {
  const map = {
    cashbox: 'خزينة',
    bank: 'بنك',
    wallet: 'محفظة',
    supplier: 'مورد',
    customer: 'عميل',
    expense: 'مصروف',
    revenue: 'إيراد',
    liability: 'التزام',
    owner: 'حقوق ملكية',
    treasury: 'خزينة',
  };
  return map[t] || t || '—';
};

const formatCurrency = (val) => {
  if (val == null || val === '') return '0.00';
  const num = Number(val);
  if (!Number.isFinite(num)) return '0.00';
  return num.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }) + ' ج.م';
};

const fetchReport = async () => {
  if (fetchController) fetchController.abort();
  fetchController = new AbortController();
  setLoading();
  globalError.value = '';
  report.value = null;
  try {
    const params = {
      division: division.value,
      _t: Date.now(),
    };
    const res = await axios.get('/api/v1/reports/trial-balance-detailed', {
      params,
      signal: fetchController.signal,
    });
    if (res?.data?.data) {
      report.value = res.data.data;
      setSuccess();
    } else {
      throw new Error('استجابة غير متوقعة من السيرفر.');
    }
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') return;
    globalError.value = error.response?.data?.message
      || error.message
      || 'فشل تحميل ميزان الحسابات.';
    setError(error);
  }
};

onMounted(() => {
  fetchReport();
});

onBeforeUnmount(() => {
  if (fetchController) fetchController.abort();
});
</script>

<style scoped>
/* Scoped styles — no global rules to leak. */
</style>