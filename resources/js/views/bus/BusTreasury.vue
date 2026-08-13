<template>
  <div class="finance-dashboard bus-booking animate-in pb-10 fade-in duration-700">
    <header class="bus-hero relative overflow-hidden bg-gradient-to-br from-[#0a1628] via-[#0d1f3c] to-[#111827] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/20 border border-blue-500/30">
              <Vault class="h-4 w-4 text-blue-400" />
            </div>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-blue-400/90">خزينة الباصات</p>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
            أرصدة التحصيل والشركات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
            نظرة شاملة على حسابات التحصيل (خزائن، بنوك، محافظ) وأرصدة شركات الباصات والمديونيات، مع متابعة آخر الحركات المالية للقسم.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <router-link
            :to="{ name: 'bus.list' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 transition hover:border-blue-500/40 hover:text-white"
          >
            <ArrowRight class="h-4 w-4 rotate-180" />
            الحجوزات
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-blue-500/30 bg-blue-500/15 px-4 py-2 text-sm font-bold text-blue-200 transition hover:bg-blue-500/25"
            @click="reload"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': store.loading.payments }" />
            تحديث
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-10 px-4 sm:px-6 lg:px-8 mt-8">
      <div v-if="store.loading.payments && !ov" class="rounded-2xl border border-white/10 bg-white/[0.03] py-24 text-center text-white/40">
        جاري تحميل بيانات الخزينة…
      </div>

      <template v-else-if="ov">
        <!-- حسابات التحصيل -->
        <section class="space-y-6">
          <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Activity class="w-5 h-5 text-blue-400" />
              حسابات التحصيل
            </h2>
          </div>

          <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <!-- Cashboxes -->
            <div v-if="cashboxAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-amber-400/80">الخزائن النقدية</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in cashboxAccounts" :key="acc.id" class="rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded-full">خزينة</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-400 hover:text-sky-300">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">{{ acc.name }}</p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ Number(acc.balance).toLocaleString('ar-EG') }}
                    <span class="text-xs font-normal text-white/40 mr-1">ج.م</span>
                  </p>
                </div>
              </div>
            </div>

            <!-- Banks -->
            <div v-if="bankAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-sky-400/80">البنوك والبريد</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in bankAccounts" :key="acc.id" class="rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-sky-500/20 text-sky-400 px-2 py-0.5 rounded-full">بنك</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-400 hover:text-sky-300">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">{{ acc.name }}</p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ Number(acc.balance).toLocaleString('ar-EG') }}
                    <span class="text-xs font-normal text-white/40 mr-1">ج.م</span>
                  </p>
                </div>
              </div>
            </div>

            <!-- Wallets -->
            <div v-if="walletAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-emerald-400/80">المحافظ الرقمية</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in walletAccounts" :key="acc.id" class="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">محفظة</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-400 hover:text-sky-300">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">{{ acc.name }} <span v-if="acc.wallet_number" class="text-xs font-normal text-white/40">({{ acc.wallet_number }})</span></p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ Number(acc.balance).toLocaleString('ar-EG') }}
                    <span class="text-xs font-normal text-white/40 mr-1">ج.م</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- أرصدة الشركات -->
        <section class="space-y-4">
          <h2 class="text-xl font-bold text-white flex items-center gap-2">
            <Building2 class="w-5 h-5 text-red-400" />
            أرصدة مديونيات الشركات
          </h2>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div
              v-for="c in ov.companies"
              :key="c.id"
              class="rounded-xl border border-white/5 bg-white/[0.02] p-5 transition hover:bg-white/[0.04]"
            >
              <div class="flex items-center justify-between mb-3">
                <p class="font-bold text-white text-sm">{{ c.name }}</p>
                <div :class="[Number(c.balance) < 0 ? 'bg-red-500/20 text-red-400' : 'bg-emerald-500/20 text-emerald-400', 'text-[10px] font-bold px-2 py-0.5 rounded-full']">
                  {{ Number(c.balance) < 0 ? 'مديونية' : 'رصيد' }}
                </div>
              </div>
              <p class="font-mono text-xl font-black text-white tabular-nums">
                {{ Number(Math.abs(c.balance)).toLocaleString('ar-EG') }}
                <span class="text-xs font-normal text-white/40 mr-1">ج.م</span>
              </p>
              <router-link :to="{ name: 'bus.companies' }" class="mt-4 block text-center py-2 rounded-lg border border-white/5 text-[11px] font-bold text-white/40 hover:text-white hover:border-white/10 transition">
                كشف الحساب
              </router-link>
            </div>
          </div>
        </section>

        <!-- آخر حركات الباص -->
        <section class="space-y-4">
          <h2 class="text-xl font-bold text-white flex items-center gap-2">
            <Clock class="w-5 h-5 text-blue-400" />
            آخر الحركات المالية للقسم
          </h2>
          <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
            <table class="min-w-full text-right text-sm">
              <thead class="border-b border-white/5 bg-black/20">
                <tr class="text-[11px] uppercase tracking-widest text-white/40">
                  <th class="px-5 py-4 font-bold">التاريخ</th>
                  <th class="px-5 py-4 font-bold">النوع</th>
                  <th class="px-5 py-4 font-bold">المبلغ</th>
                  <th class="px-5 py-4 font-bold">من</th>
                  <th class="px-5 py-4 font-bold">إلى</th>
                  <th class="px-5 py-4 font-bold">ملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr
                  v-for="tx in ov.recent_bus_transactions || []"
                  :key="tx.id"
                  class="transition hover:bg-white/[0.03]"
                >
                  <td class="px-5 py-3.5 font-mono text-xs text-white/40">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-5 py-3.5">
                    <span :class="[txTypeClass(tx.type), 'text-xs font-bold uppercase']">
                      {{ txTypeLabel(tx.type) }}
                    </span>
                  </td>
                  <td class="px-5 py-3.5 font-mono font-black text-white text-sm tabular-nums">{{ Number(tx.amount).toLocaleString('ar-EG') }}</td>
                  <td class="px-5 py-3.5 text-white/60 text-xs">{{ tx.from_account?.name || '—' }}</td>
                  <td class="px-5 py-3.5 text-white/60 text-xs">{{ tx.to_account?.name || '—' }}</td>
                  <td class="px-5 py-3.5 text-white/40 text-xs max-w-[200px] truncate">{{ tx.notes || '—' }}</td>
                </tr>
                <tr v-if="!ov.recent_bus_transactions?.length">
                  <td colspan="6" class="px-5 py-12 text-center text-white/20">لا توجد حركات مالية حديثة</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <div v-else class="rounded-2xl border border-red-500/30 bg-red-500/10 p-16 text-center">
        <p class="text-red-400 font-bold">تعذر تحميل بيانات الخزينة.</p>
        <button @click="reload" class="mt-4 px-6 py-2 bg-red-500 text-white rounded-xl hover:bg-red-400 transition">إعادة المحاولة</button>
      </div>
    </div>

    <!-- Modal: حركات حساب -->
    <Teleport to="body">
      <div
        v-if="modal.type === 'account' && modal.account"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="closeModal"
      >
        <div class="max-h-[85vh] w-full max-w-4xl overflow-hidden rounded-2xl border border-white/10 bg-[#0a111e] shadow-2xl">
          <div class="flex items-center justify-between border-b border-white/5 px-6 py-5">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-white/40">حركات القسم على الحساب</p>
              <h3 class="text-lg font-bold text-white">{{ modal.account.name }}</h3>
            </div>
            <button type="button" class="rounded-lg p-2 text-white/30 hover:bg-white/5 hover:text-white" @click="closeModal">✕</button>
          </div>
          <!-- Tab strip: switch between bus-only and full office view -->
          <div class="flex items-center gap-1 border-b border-white/5 bg-white/[0.02] px-4 py-2">
            <button
              type="button"
              class="rounded-lg px-3 py-1.5 text-xs font-bold transition"
              :class="accountTxScope === 'bus'
                ? 'bg-blue-500/20 text-blue-200 border border-blue-500/30'
                : 'text-white/40 hover:text-white/70 border border-transparent'"
              @click="setAccountTxScope('bus')"
            >عمليات الباص فقط</button>
            <button
              type="button"
              class="rounded-lg px-3 py-1.5 text-xs font-bold transition"
              :class="accountTxScope === 'office'
                ? 'bg-emerald-500/20 text-emerald-200 border border-emerald-500/30'
                : 'text-white/40 hover:text-white/70 border border-transparent'"
              @click="setAccountTxScope('office')"
            >كل عمليات المكتب</button>
            <span v-if="accountTxScope === 'office'" class="mr-auto text-[10px] text-emerald-300/70 font-bold">
              يشمل: فوري، أونلاين، محفظة، تحويلات، عام
            </span>
          </div>
          <div class="max-h-[60vh] overflow-auto p-6">
            <table class="min-w-full text-right text-xs">
              <thead class="sticky top-0 bg-[#0a111e] text-white/40 uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">التاريخ</th>
                  <th class="px-4 py-3">النوع</th>
                  <th v-if="accountTxScope === 'office'" class="px-4 py-3">الموديول</th>
                  <th class="px-4 py-3">المبلغ</th>
                  <th class="px-4 py-3">الطرف الآخر</th>
                  <th class="px-4 py-3">ملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr v-for="tx in accountTxRows" :key="tx.id" class="hover:bg-white/[0.02]">
                  <td class="px-4 py-3 font-mono text-white/40">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-4 py-3">
                    <span :class="txTypeClass(tx.type)" class="font-bold">
                      {{ txTypeLabel(tx.type) }}
                    </span>
                  </td>
                  <td v-if="accountTxScope === 'office'" class="px-4 py-3">
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-white/5" :class="moduleBadgeClass(tx.module)">
                      {{ moduleLabel(tx.module) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 font-mono font-bold text-white tabular-nums text-sm">{{ Number(tx.amount).toLocaleString('ar-EG') }}</td>
                  <td class="px-4 py-3 text-white/60">
                    {{ tx.from_account_id === modal.account.id ? (tx.to_account?.name || '—') : (tx.from_account?.name || '—') }}
                  </td>
                  <td class="max-w-[250px] truncate px-4 py-3 text-white/30">{{ tx.notes || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="accountTxLoading" class="py-12 text-center text-white/20">جاري التحميل…</div>
            <div v-if="accountTxError" class="py-6 text-center">
              <div class="inline-block rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm font-bold text-red-300">
                ⚠ {{ accountTxError }}
              </div>
            </div>
            <div v-if="!accountTxLoading && !accountTxError && !accountTxRows.length" class="py-12 text-center text-white/20">لا توجد حركات مسجلة.</div>
          </div>
          <div v-if="accountTxMeta && accountTxMeta.last_page > 1" class="flex items-center justify-center gap-4 border-t border-white/5 px-6 py-4">
            <button
              type="button"
              class="px-4 py-1.5 rounded-lg border border-white/5 text-xs font-bold text-white/60 hover:bg-white/5 disabled:opacity-20"
              :disabled="accountTxMeta.current_page <= 1"
              @click="loadAccountPage(accountTxMeta.current_page - 1)"
            >السابق</button>
            <span class="text-xs text-white/30">{{ accountTxMeta.current_page }} من {{ accountTxMeta.last_page }}</span>
            <button
              type="button"
              class="px-4 py-1.5 rounded-lg border border-white/5 text-xs font-bold text-white/60 hover:bg-white/5 disabled:opacity-20"
              :disabled="accountTxMeta.current_page >= accountTxMeta.last_page"
              @click="loadAccountPage(accountTxMeta.current_page + 1)"
            >التالي</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useBusStore } from '@/stores/busStore';
import { ArrowRight, RefreshCw, Wallet as Vault, Activity, Building2, Clock } from 'lucide-vue-next';

const store = useBusStore();
const ov = computed(() => store.treasuryOverview);

const cashboxAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'cashbox' || a.type === 'treasury'));
const bankAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'bank'));
const walletAccounts = computed(() => (ov.value?.settlement_accounts || []).filter(a => a.type === 'wallet'));

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return iso;
  }
};

// Map raw transaction.type (matches App\Enums\TransactionType) to a display label.
// Backend can legitimately return: income | expense | transfer | refund | writeoff.
const txTypeLabel = (type) => {
  switch (type) {
    case 'income':   return 'إيداع';
    case 'expense':  return 'سحب';
    case 'transfer': return 'تحويل';
    case 'refund':   return 'استرداد';
    case 'writeoff': return 'شطب';
    default:         return type || '—';
  }
};

const txTypeClass = (type) => {
  switch (type) {
    case 'income':   return 'text-emerald-400';
    case 'expense':  return 'text-red-400';
    case 'transfer': return 'text-sky-400';
    case 'refund':   return 'text-red-400';
    case 'writeoff': return 'text-amber-400';
    default:         return 'text-white/50';
  }
};

const reload = () => store.fetchBusTreasuryOverview();

onMounted(() => {
  reload();
});

const modal = ref({ type: 'idle', account: null });
const accountTxRows = ref([]);
const accountTxMeta = ref(null);
const accountTxLoading = ref(false);
const accountTxError = ref(null);

// 'bus' = original BusTreasuryController::accountBusTransactions endpoint,
//         filters by module=bus only.
// 'office' = new OfficeTreasuryController::accountTransactions endpoint,
//         returns ALL operations on the account (fawry, online, general, etc.).
//
// ALWAYS starts in 'bus' so opening a different account after the user
// toggled to 'office' doesn't leak the previous account's state. The
// reset is enforced in both `openAccountTx` and `closeModal` below.
const accountTxScope = ref('bus');

// Tracks the most recent in-flight request so older responses cannot
// overwrite newer state (handles scope-switch + account-switch races).
let accountTxRequestSeq = 0;

const closeModal = () => {
  // Abort any in-flight request implicitly by bumping the sequence —
  // its callback will see a stale seq and bail out.
  accountTxRequestSeq += 1;
  modal.value = { type: 'idle', account: null };
  accountTxRows.value = [];
  accountTxMeta.value = null;
  accountTxError.value = null;
  accountTxLoading.value = false;
  // Reset to bus scope so the NEXT opened account starts in bus mode.
  // Per PHASE 3 spec: "Open Account B → MUST start in Bus mode".
  accountTxScope.value = 'bus';
};

const openAccountTx = async (acc) => {
  // Per PHASE 3 spec: opening a new account must reset scope to bus and
  // clear stale rows/pagination/loading state from a previous open.
  accountTxScope.value = 'bus';
  accountTxRows.value = [];
  accountTxMeta.value = null;
  accountTxError.value = null;
  accountTxLoading.value = false;
  modal.value = { type: 'account', account: acc };
  await loadAccountPage(1);
};

const setAccountTxScope = (scope) => {
  if (accountTxScope.value === scope) return;
  if (scope !== 'bus' && scope !== 'office') return; // defensive
  accountTxScope.value = scope;
  // Refresh from page 1 whenever the user flips the tab.
  loadAccountPage(1);
};

const loadAccountPage = async (page) => {
  if (!modal.value.account) return;
  // Bump the sequence so any in-flight older request is treated as stale.
  const mySeq = ++accountTxRequestSeq;
  accountTxLoading.value = true;
  accountTxError.value = null;
  try {
    const fetcher = accountTxScope.value === 'office'
      ? store.fetchOfficeAccountTransactions
      : store.fetchAccountBusTransactions;
    const data = await fetcher(modal.value.account.id, { page, per_page: 25 });
    // Stale response guard — only apply if THIS request is still the latest.
    if (mySeq !== accountTxRequestSeq) return;
    accountTxRows.value = data?.data || [];
    accountTxMeta.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
    };
  } catch (err) {
    if (mySeq !== accountTxRequestSeq) return; // ignore stale failures
    accountTxRows.value = [];
    accountTxMeta.value = null;
    // Distinguish API failure from a successful empty result, per PHASE 10.
    accountTxError.value = err?.response?.data?.message
      || err?.message
      || 'فشل تحميل المعاملات. حاول مرة أخرى.';
  } finally {
    if (mySeq === accountTxRequestSeq) {
      accountTxLoading.value = false;
    }
  }
};

// Module badge label + color for the new "office" scope column.
const moduleLabel = (m) => {
  switch (m) {
    case 'bus':            return 'باص';
    case 'fawry':          return 'فوري';
    case 'online':         return 'اونلاين';
    case 'wallet_transfer':return 'محفظة';
    case 'general':        return 'عام';
    case 'office':         return 'مكتب';
    default:               return m || '—';
  }
};

const moduleBadgeClass = (m) => {
  switch (m) {
    case 'bus':            return 'text-blue-300';
    case 'fawry':          return 'text-orange-300';
    case 'online':         return 'text-violet-300';
    case 'wallet_transfer':return 'text-emerald-300';
    case 'general':        return 'text-white/60';
    case 'office':         return 'text-sky-200';
    default:               return 'text-white/40';
  }
};
</script>

<style scoped>
.bus-hero {
  background-image: radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 50%);
}
</style>
