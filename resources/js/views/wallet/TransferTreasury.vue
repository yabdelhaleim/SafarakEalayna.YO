<template>
  <div class="finance-dashboard wallet-module animate-in pb-10 fade-in duration-700">
    <header class="wallet-hero relative overflow-hidden bg-gradient-to-br from-[#0a192f] via-[#112240] to-[#1a365d] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/20 border border-blue-500/30">
              <Vault class="h-4 w-4 text-blue-400" />
            </div>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-blue-400/90">خزينة المحافظ والتحويلات</p>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
            أرصدة المحافظ والتحويلات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
            متابعة حسابات موديول المحافظ (محافظ، بنوك، بريد، خزائن نقدي) وإجمالي السيولة المتاحة.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <router-link
            :to="{ name: 'wallet.list' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 transition hover:border-blue-500/40 hover:text-white"
          >
            <ArrowRight class="h-4 w-4 rotate-180" />
            المعاملات
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-blue-500/30 bg-blue-500/15 px-4 py-2 text-sm font-bold text-blue-200 transition hover:bg-blue-500/25"
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
        <!-- الحسابات مجمعة -->
        <section class="space-y-8">
          <!-- Wallets -->
          <div v-if="ov.wallets?.length" class="space-y-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Smartphone class="w-5 h-5 text-purple-400" />
              المحافظ الإلكترونية
            </h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div v-for="acc in ov.wallets" :key="acc.id" class="rounded-2xl border border-purple-500/20 bg-gradient-to-br from-purple-500/10 to-transparent p-6 group transition hover:border-purple-500/40">
                <div class="flex items-center justify-between mb-4">
                  <span class="text-[10px] font-bold bg-purple-500/20 text-purple-400 px-3 py-1 rounded-full uppercase tracking-widest">{{ acc.wallet_provider || 'محفظة' }}</span>
                  <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-purple-400 hover:text-purple-300">عرض العمليات</button>
                </div>
                <p class="text-lg font-bold text-white mb-1">{{ acc.name }}</p>
                <p v-if="acc.wallet_number" class="text-xs text-white/40 mb-4 font-mono">{{ acc.wallet_number }}</p>
                <p class="font-mono text-3xl font-black text-white tabular-nums">
                  {{ Number(acc.balance).toLocaleString('ar-EG') }}
                  <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency }}</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Banks -->
          <div v-if="ov.banks?.length" class="space-y-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Landmark class="w-5 h-5 text-sky-400" />
              البنوك والبريد
            </h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div v-for="acc in ov.banks" :key="acc.id" class="rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-6 group transition hover:border-sky-500/40">
                <div class="flex items-center justify-between mb-4">
                  <span class="text-[10px] font-bold bg-sky-500/20 text-sky-400 px-3 py-1 rounded-full">بنك / بريد</span>
                  <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-sky-400 hover:text-sky-300">عرض العمليات</button>
                </div>
                <p class="text-lg font-bold text-white mb-4">{{ acc.name }}</p>
                <p class="font-mono text-3xl font-black text-white tabular-nums">
                  {{ Number(acc.balance).toLocaleString('ar-EG') }}
                  <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency }}</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Cashboxes -->
          <div v-if="ov.cashboxes?.length" class="space-y-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Banknote class="w-5 h-5 text-amber-400" />
              الخزائن النقدية
            </h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div v-for="acc in ov.cashboxes" :key="acc.id" class="rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-6 group transition hover:border-amber-500/40">
                <div class="flex items-center justify-between mb-4">
                  <span class="text-[10px] font-bold bg-amber-500/20 text-amber-400 px-3 py-1 rounded-full">نقدي</span>
                  <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-amber-400 hover:text-amber-300">عرض العمليات</button>
                </div>
                <p class="text-lg font-bold text-white mb-4">{{ acc.name }}</p>
                <p class="font-mono text-3xl font-black text-white tabular-nums">
                  {{ Number(acc.balance).toLocaleString('ar-EG') }}
                  <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency }}</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Treasuries -->
          <div v-if="ov.treasury?.length" class="space-y-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
              <Briefcase class="w-5 h-5 text-rose-400" />
              الخزائن العامة
            </h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              <div v-for="acc in ov.treasury" :key="acc.id" class="rounded-2xl border border-rose-500/20 bg-gradient-to-br from-rose-500/10 to-transparent p-6 group transition hover:border-rose-500/40">
                <div class="flex items-center justify-between mb-4">
                  <span class="text-[10px] font-bold bg-rose-500/20 text-rose-400 px-3 py-1 rounded-full">خزينة عامة</span>
                  <button @click="openAccountTx(acc)" class="text-[11px] font-bold text-rose-400 hover:text-rose-300">عرض العمليات</button>
                </div>
                <p class="text-lg font-bold text-white mb-4">{{ acc.name }}</p>
                <p class="font-mono text-3xl font-black text-white tabular-nums">
                  {{ Number(acc.balance).toLocaleString('ar-EG') }}
                  <span class="text-xs font-normal text-white/40 mr-1">{{ acc.currency }}</span>
                </p>
              </div>
            </div>
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
        v-if="modal.show && modal.account"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        @click.self="closeModal"
      >
        <div class="max-h-[85vh] w-full max-w-4xl overflow-hidden rounded-2xl border border-white/10 bg-[#0a111e] shadow-2xl animate-in zoom-in-95 duration-200">
          <div class="flex items-center justify-between border-b border-white/5 px-6 py-5">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-white/40">حركات موديول المحافظ على الحساب</p>
              <h3 class="text-lg font-bold text-white">{{ modal.account.name }}</h3>
            </div>
            <button type="button" class="rounded-lg p-2 text-white/30 hover:bg-white/5 hover:text-white" @click="closeModal">✕</button>
          </div>
          <div class="max-h-[60vh] overflow-y-auto p-6">
            <table class="min-w-full text-right text-xs">
              <thead class="sticky top-0 bg-[#0a111e] text-white/40 uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">التاريخ</th>
                  <th class="px-4 py-3">المبلغ</th>
                  <th class="px-4 py-3">ملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr v-for="tx in accountTxRows" :key="tx.id" class="hover:bg-white/[0.02]">
                  <td class="px-4 py-3 font-mono text-white/40">{{ formatDt(tx.created_at) }}</td>
                  <td class="px-4 py-3 font-mono font-bold text-white tabular-nums text-sm">{{ Number(tx.amount).toLocaleString('ar-EG') }}</td>
                  <td class="max-w-[250px] truncate px-4 py-3 text-white/30">{{ tx.notes || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="accountTxLoading" class="py-12 text-center text-white/20">جاري التحميل…</div>
            <div v-if="!accountTxLoading && !accountTxRows.length" class="py-12 text-center text-white/20">لا توجد حركات مسجلة لقسم المحافظ على هذا الحساب.</div>
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
import { onMounted, ref } from 'vue';
import { useWalletStore } from '@/stores/walletStore';
import { ArrowRight, RefreshCw, Wallet as Vault, Smartphone, Landmark, Banknote, Briefcase, Clock } from 'lucide-vue-next';

const store = useWalletStore();
const ov = ref(null);
const loading = ref(false);

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return iso;
  }
};

const reload = async () => {
  loading.value = true;
  try {
    ov.value = await store.fetchTransferTreasury();
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  reload();
});

const modal = ref({ show: false, account: null });
const accountTxRows = ref([]);
const accountTxMeta = ref(null);
const accountTxLoading = ref(false);

const closeModal = () => {
  modal.value = { show: false, account: null };
  accountTxRows.value = [];
  accountTxMeta.value = null;
};

const openAccountTx = async (acc) => {
  modal.value = { show: true, account: acc };
  await loadAccountPage(1);
};

const loadAccountPage = async (page) => {
  if (!modal.value.account) return;
  accountTxLoading.value = true;
  try {
    const data = await store.fetchAccountTransactions(modal.value.account.id, { page, per_page: 25 });
    accountTxRows.value = data?.data || [];
    accountTxMeta.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
    };
  } catch {
    accountTxRows.value = [];
    accountTxMeta.value = null;
  } finally {
    accountTxLoading.value = false;
  }
};
</script>

<style scoped>
.wallet-hero {
  background-image: radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 50%);
}
</style>
