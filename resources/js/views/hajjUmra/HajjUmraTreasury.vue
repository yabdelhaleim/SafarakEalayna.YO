<template>
  <div class="finance-dashboard hajj-umra animate-in pb-10 fade-in duration-700">
    <header class="relative overflow-hidden bg-gradient-to-br from-[#201a0a] via-[#1f1b11] to-[#111827] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/20 border border-amber-500/30">
              <Vault class="h-4 w-4 text-amber-300" />
            </div>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-amber-300/90">مالية الحج والعمرة</p>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
            الخزائن والبنوك والمحافظ والعمليات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
            متابعة كل حسابات القسم (خزائن، بنوك، محافظ) مع آخر العمليات المالية على القسم. كما يمكنك الدخول لصفحة الشركات المنفذة للسحب/السداد.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <router-link
            :to="{ name: 'hajj.list' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 transition hover:border-amber-500/40 hover:text-white"
          >
            <ArrowRight class="h-4 w-4 rotate-180" />
            الحجوزات
          </router-link>
          <router-link
            :to="{ name: 'hajj.executing-companies' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 transition hover:border-white/20 hover:text-white"
          >
            <Building2 class="h-4 w-4" />
            الشركات المنفذة
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/15 px-4 py-2 text-sm font-bold text-amber-100 transition hover:bg-amber-500/25"
            @click="reload"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
            تحديث
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-10 px-4 sm:px-6 lg:px-8 mt-8">
      <div v-if="loading && !ov" class="rounded-2xl border border-white/10 bg-white/[0.03] py-24 text-center text-white/40">
        جاري تحميل بيانات الخزينة…
      </div>

      <template v-else-if="ov">
        <!-- Accounts -->
        <section class="space-y-6">
          <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Activity class="w-5 h-5 text-amber-300" />
              حسابات القسم
            </h2>
          </div>

          <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <div v-if="cashboxAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-amber-400/80">الخزائن النقدية</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in cashboxAccounts" :key="'cash-' + acc.id" class="rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded-full">خزينة</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-300 hover:text-sky-200">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">{{ acc.name }}</p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ fmt(acc.balance) }}
                    <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency || 'EGP' }}</span>
                  </p>
                </div>
              </div>
            </div>

            <div v-if="bankAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-sky-400/80">البنوك والبريد</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in bankAccounts" :key="'bank-' + acc.id" class="rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-sky-500/20 text-sky-400 px-2 py-0.5 rounded-full">بنك/بريد</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-300 hover:text-sky-200">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">{{ acc.name }}</p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ fmt(acc.balance) }}
                    <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency || 'EGP' }}</span>
                  </p>
                </div>
              </div>
            </div>

            <div v-if="walletAccounts.length" class="space-y-3 lg:col-span-3">
              <h3 class="text-xs font-bold uppercase tracking-widest text-emerald-400/80">المحافظ الرقمية</h3>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="acc in walletAccounts" :key="'wallet-' + acc.id" class="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-5">
                  <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">محفظة</span>
                    <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-300 hover:text-sky-200">العمليات</button>
                  </div>
                  <p class="text-sm font-bold text-white mb-1">
                    {{ acc.name }}
                    <span v-if="acc.wallet_number" class="text-xs font-normal text-white/40">({{ acc.wallet_number }})</span>
                  </p>
                  <p class="font-mono text-2xl font-black text-white tabular-nums">
                    {{ fmt(acc.balance) }}
                    <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency || 'EGP' }}</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Recent transactions -->
        <section class="space-y-4">
          <h2 class="text-xl font-bold text-white flex items-center gap-2">
            <Clock class="w-5 h-5 text-amber-300" />
            آخر الحركات المالية للقسم
          </h2>
          <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
            <table class="min-w-full text-right text-sm">
              <thead class="border-b border-white/5 bg-black/20">
                <tr class="text-[11px] uppercase tracking-widest text-white/40">
                  <th class="px-5 py-4 font-bold">التاريخ</th>
                  <th class="px-5 py-4 font-bold">المبلغ</th>
                  <th class="px-5 py-4 font-bold">من</th>
                  <th class="px-5 py-4 font-bold">إلى</th>
                  <th class="px-5 py-4 font-bold">ملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr v-for="tx in (ov.recent_hajj_umra_transactions || [])" :key="tx.id" class="transition hover:bg-white/[0.03]">
                  <td class="px-5 py-3.5 font-mono text-xs text-white/40">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-5 py-3.5 font-mono font-black text-white text-sm tabular-nums">{{ fmt(tx.amount) }}</td>
                  <td class="px-5 py-3.5 text-white/60 text-xs">{{ tx.from_account?.name || '—' }}</td>
                  <td class="px-5 py-3.5 text-white/60 text-xs">{{ tx.to_account?.name || '—' }}</td>
                  <td class="px-5 py-3.5 text-white/40 text-xs max-w-[280px] truncate">{{ tx.notes || '—' }}</td>
                </tr>
                <tr v-if="!(ov.recent_hajj_umra_transactions || []).length">
                  <td colspan="5" class="px-5 py-12 text-center text-white/20">لا توجد حركات مالية حديثة</td>
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

    <!-- Modal: account transactions -->
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
          <div class="max-h-[60vh] overflow-auto p-6">
            <table class="min-w-full text-right text-xs">
              <thead class="sticky top-0 bg-[#0a111e] text-white/40 uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">التاريخ</th>
                  <th class="px-4 py-3">النوع</th>
                  <th class="px-4 py-3">المبلغ</th>
                  <th class="px-4 py-3">الطرف الآخر</th>
                  <th class="px-4 py-3">ملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr v-for="tx in accountTxRows" :key="tx.id" class="hover:bg-white/[0.02]">
                  <td class="px-4 py-3 font-mono text-white/40">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-4 py-3">
                    <span :class="tx.type === 'income' ? 'text-emerald-400' : (tx.type === 'expense' ? 'text-red-400' : 'text-sky-300')" class="font-bold">
                      {{ tx.type }}
                    </span>
                  </td>
                  <td class="px-4 py-3 font-mono font-bold text-white tabular-nums text-sm">{{ fmt(tx.amount) }}</td>
                  <td class="px-4 py-3 text-white/60">{{ tx.from_account_id === modal.account.id ? (tx.to_account?.name || '—') : (tx.from_account?.name || '—') }}</td>
                  <td class="max-w-[250px] truncate px-4 py-3 text-white/30">{{ tx.notes || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="accountTxLoading" class="py-12 text-center text-white/20">جاري التحميل…</div>
            <div v-if="!accountTxLoading && !accountTxRows.length" class="py-12 text-center text-white/20">لا توجد حركات مسجلة.</div>
          </div>
          <div v-if="accountTxMeta && accountTxMeta.last_page > 1" class="flex items-center justify-center gap-4 border-t border-white/5 px-6 py-4">
            <button type="button" class="px-4 py-1.5 rounded-lg border border-white/5 text-xs font-bold text-white/60 hover:bg-white/5 disabled:opacity-20" :disabled="accountTxMeta.current_page <= 1" @click="loadAccountPage(accountTxMeta.current_page - 1)">السابق</button>
            <span class="text-xs text-white/30">{{ accountTxMeta.current_page }} من {{ accountTxMeta.last_page }}</span>
            <button type="button" class="px-4 py-1.5 rounded-lg border border-white/5 text-xs font-bold text-white/60 hover:bg-white/5 disabled:opacity-20" :disabled="accountTxMeta.current_page >= accountTxMeta.last_page" @click="loadAccountPage(accountTxMeta.current_page + 1)">التالي</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { Activity, ArrowRight, Building2, Clock, RefreshCw, Vault } from 'lucide-vue-next';

const ov = ref(null);
const loading = ref(false);

const modal = reactive({ type: null, account: null });
const accountTxRows = ref([]);
const accountTxMeta = ref(null);
const accountTxLoading = ref(false);

const fmt = (n) => Number(n || 0).toLocaleString('ar-EG');
const formatDt = (dt) => {
  try {
    return new Date(dt).toLocaleString('ar-EG');
  } catch {
    return dt || '—';
  }
};

const settlementAccounts = computed(() => ov.value?.settlement_accounts || []);
const cashboxAccounts = computed(() => settlementAccounts.value.filter((a) => a.type === 'cashbox'));
const bankAccounts = computed(() => settlementAccounts.value.filter((a) => a.type === 'bank'));
const walletAccounts = computed(() => settlementAccounts.value.filter((a) => a.type === 'wallet'));

const reload = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/v1/hajj-umra/treasury/overview');
    ov.value = res.data?.data ?? null;
  } catch (e) {
    console.error('HajjUmraTreasury reload', e);
    ov.value = null;
  } finally {
    loading.value = false;
  }
};

const openAccountTx = async (acc) => {
  modal.type = 'account';
  modal.account = acc;
  await loadAccountPage(1);
};

const loadAccountPage = async (page = 1) => {
  if (!modal.account?.id) return;
  accountTxLoading.value = true;
  try {
    const res = await axios.get(`/api/v1/hajj-umra/treasury/accounts/${modal.account.id}/transactions`, { params: { per_page: 30, page } });
    const payload = res.data?.data || {};
    accountTxRows.value = payload.data || payload.items || [];
    accountTxMeta.value = payload.meta || payload.pagination || {
      current_page: payload.current_page,
      last_page: payload.last_page,
    };
  } catch (e) {
    console.error('loadAccountPage', e);
    accountTxRows.value = [];
    accountTxMeta.value = null;
  } finally {
    accountTxLoading.value = false;
  }
};

const closeModal = () => {
  modal.type = null;
  modal.account = null;
  accountTxRows.value = [];
  accountTxMeta.value = null;
};

onMounted(reload);
</script>

