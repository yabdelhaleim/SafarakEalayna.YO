<template>
  <div class="animate-in fade-in duration-500 pb-16 wallet-module">
    <!-- Hero Header -->
    <header class="relative overflow-hidden bg-gradient-to-br from-[#0a192f] via-[#112240] to-[#1a365d] border-b border-white/5">
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl translate-y-1/2 -translate-x-1/3"></div>
      </div>
      <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div class="flex items-center gap-3 mb-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 border border-blue-500/30">
                <Wallet class="h-5 w-5 text-blue-400" />
              </div>
              <span class="text-xs font-bold uppercase tracking-[0.2em] text-blue-400/80">وحدة المحافظ والتحويلات</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
              لوحة تحكم المحافظ
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
              إحصائيات المعاملات، الأرباح اليومية، والسيولة المتاحة في الحسابات التابعة لموديول المحافظ والتحويلات.
            </p>
          </div>
          <div class="flex shrink-0 items-center gap-3">
            <button
              @click="reload"
              :disabled="loading"
              class="inline-flex items-center gap-2 rounded-xl border border-blue-500/40 bg-blue-500/20 px-5 py-2.5 text-sm font-bold text-blue-200 transition hover:bg-blue-500/30 hover:scale-105 disabled:opacity-50"
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
              تحديث
            </button>
            <router-link
              :to="{ name: 'wallet.create' }"
              class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-blue-500 hover:scale-105"
            >
              <Plus class="h-4 w-4" />
              عملية جديدة
            </router-link>
          </div>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8 space-y-8">
      <!-- Loading Skeleton -->
      <div v-if="loading && !data" class="space-y-8">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="i in 4" :key="i" class="h-32 rounded-2xl bg-white/5 animate-pulse"></div>
        </div>
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
          <div class="lg:col-span-2 h-80 rounded-2xl bg-white/5 animate-pulse"></div>
          <div class="h-80 rounded-2xl bg-white/5 animate-pulse"></div>
        </div>
      </div>

      <template v-else-if="data">
        <!-- KPI Cards -->
        <section :class="['grid gap-5', isAdmin ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4']">
          <!-- Today Volume -->
          <div class="group relative overflow-hidden rounded-2xl border border-blue-500/20 bg-gradient-to-br from-blue-500/10 to-transparent p-6 transition hover:border-blue-500/40 hover:shadow-lg hover:shadow-blue-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-blue-500/10 blur-2xl group-hover:bg-blue-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                  <TrendingUp class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold uppercase tracking-wider bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded-full">اليوم</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-blue-400/70 mb-1">حجم معاملات اليوم</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.daily.revenue) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Today Profit -->
          <div class="group relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-6 transition hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-emerald-500/10 blur-2xl group-hover:bg-emerald-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                  <BarChart3 class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">اليوم</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-emerald-400/70 mb-1">صافي ربح اليوم</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.daily.profit) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Total Count -->
          <div class="group relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-6 transition hover:border-sky-500/40 hover:shadow-lg hover:shadow-sky-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-sky-500/10 blur-2xl group-hover:bg-sky-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                  <CreditCard class="h-5 w-5" />
                </div>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-sky-400/70 mb-1">عدد العمليات اليوم</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.daily.count) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">معاملة</p>
            </div>
          </div>

          <!-- Total Balance -->
          <div class="group relative overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-6 transition hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-amber-500/10 blur-2xl group-hover:bg-amber-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                  <Vault class="h-5 w-5" />
                </div>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-amber-400/70 mb-1">إجمالي الرصيد المتاح</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.wallets.balance + data.stats.banks.balance + data.stats.cashboxes.balance + data.stats.treasury.balance) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Customers Debt -->
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-red-500/20 bg-gradient-to-br from-red-500/10 to-transparent p-6 transition hover:border-red-500/40 hover:shadow-lg hover:shadow-red-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-red-500/15 blur-2xl group-hover:bg-red-500/25 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/20 text-red-400">
                  <AlertTriangle class="h-5 w-5" />
                </div>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-red-400/70 mb-1">مديونيات العملاء</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.customers_debt) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>
        </section>

        <!-- Main Grid -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
          <!-- Recent Transactions Table -->
          <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <Clock class="w-5 h-5 text-blue-400" />
                آخر عمليات المحافظ
              </h2>
              <router-link :to="{ name: 'wallet.list' }" class="text-xs font-bold text-blue-400 hover:text-blue-300 transition flex items-center gap-1">
                عرض الكل
                <ArrowLeft class="w-3.5 h-3.5" />
              </router-link>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
              <table class="min-w-full text-right text-sm">
                <thead class="border-b border-white/5 bg-black/20">
                  <tr class="text-[11px] uppercase tracking-widest text-white/40">
                    <th class="px-5 py-4 font-bold">العميل</th>
                    <th class="px-5 py-4 font-bold">المحفظة</th>
                    <th class="px-5 py-4 font-bold">المبلغ</th>
                    <th class="px-5 py-4 font-bold">الربح</th>
                    <th class="px-5 py-4 font-bold">المسؤول</th>
                    <th class="px-5 py-4 font-bold">التاريخ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <tr
                    v-for="tx in data.recent_transactions"
                    :key="tx.id"
                    class="transition hover:bg-white/[0.03] group cursor-pointer"
                    @click="$router.push({ name: 'wallet.show', params: { id: tx.id } })"
                  >
                    <td class="px-5 py-3.5">
                      <!--
                        Backend returns WalletTransaction models directly; the field
                        on the model is `customer_name` (NOT `client_name`).
                        Some callers still expect `client_name`, so we accept both.
                      -->
                      <p class="font-bold text-white/90 text-sm">{{ tx.customer_name || tx.client_name || '—' }}</p>
                    </td>
                    <td class="px-5 py-3.5">
                      <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/5 text-white/60">
                        {{ tx.wallet_type?.name || '—' }}
                      </span>
                    </td>
                    <td class="px-5 py-3.5 font-mono font-bold text-white text-sm">
                      {{ fmt(tx.amount) }}
                    </td>
                    <td class="px-5 py-3.5 font-mono font-bold text-emerald-400 text-sm">
                      +{{ fmt(tx.service_fee) }}
                    </td>
                    <td class="px-5 py-3.5 text-xs text-white/60">{{ tx.employee?.name || '—' }}</td>
                    <td class="px-5 py-3.5 text-xs text-white/40">{{ formatDt(tx.created_at) }}</td>
                  </tr>
                  <tr v-if="!data.recent_transactions?.length">
                    <td colspan="6" class="px-5 py-16 text-center text-white/20">لا توجد عمليات حديثة</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Sidebar Column -->
          <div class="space-y-6">
            <!-- Total Liquidity Card -->
            <div v-if="isAdmin" class="rounded-2xl border border-blue-500/20 bg-gradient-to-b from-blue-950/60 to-transparent p-6 text-center relative overflow-hidden">
              <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent pointer-events-none"></div>
              <div class="relative">
                <div class="flex items-center justify-center gap-2 mb-4">
                  <Activity class="w-4 h-4 text-blue-400" />
                  <h2 class="text-xs font-bold uppercase tracking-widest text-blue-400/80">إجمالي السيولة</h2>
                </div>
                <p class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-white to-blue-300 drop-shadow tabular-nums mb-2">
                  {{ fmt(data.stats.total_liquidity) }}
                </p>
                <p class="text-xs text-white/30">يشمل جميع حسابات المحافظ (الخزائن، البنوك، والمحافظ)</p>
              </div>
            </div>

            <!-- Quick Actions -->
            <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-5">
              <h2 class="text-xs font-bold uppercase tracking-widest text-white/40 mb-4">وصول سريع</h2>
              <div class="grid grid-cols-2 gap-3">
                <router-link
                  :to="{ name: 'wallet.create' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-blue-500/10 bg-blue-500/5 p-4 transition hover:border-blue-500/30 hover:bg-blue-500/10"
                >
                  <PlusCircle class="h-6 w-6 text-blue-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-blue-200">عملية جديدة</span>
                </router-link>
                <router-link
                  :to="{ name: 'wallet.list' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-indigo-500/10 bg-indigo-500/5 p-4 transition hover:border-indigo-500/30 hover:bg-indigo-500/10"
                >
                  <ListOrdered class="h-6 w-6 text-indigo-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-indigo-200">العمليات</span>
                </router-link>
                <router-link
                  v-if="isAdmin"
                  :to="{ name: 'wallet.treasury' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-amber-500/10 bg-amber-500/5 p-4 transition hover:border-amber-500/30 hover:bg-amber-500/10"
                >
                  <Vault class="h-6 w-6 text-amber-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-amber-200">الخزينة</span>
                </router-link>
                <router-link
                  :to="{ name: 'wallet.customer-balances' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-red-500/10 bg-red-500/5 p-4 transition hover:border-red-500/30 hover:bg-red-500/10"
                  :class="isAdmin ? '' : 'col-span-2'"
                >
                  <Users class="h-6 w-6 text-red-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-red-200">مديونيات العملاء</span>
                </router-link>
              </div>
            </div>

            <!-- Liquidity Breakdown -->
            <div v-if="isAdmin" class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 space-y-3">
              <h2 class="text-xs font-bold uppercase tracking-widest text-white/40 mb-4">توزيع السيولة</h2>
              <div class="flex items-center justify-between py-2 border-b border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-blue-400"></div>
                  <span class="text-sm text-white/70">المحافظ</span>
                </div>
                <span class="font-mono font-bold text-sm text-blue-400">{{ fmt(data.stats.wallets.balance) }}</span>
              </div>
              <div class="flex items-center justify-between py-2 border-b border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-sky-400"></div>
                  <span class="text-sm text-white/70">البنوك</span>
                </div>
                <span class="font-mono font-bold text-sm text-sky-400">{{ fmt(data.stats.banks.balance) }}</span>
              </div>
              <div class="flex items-center justify-between py-2 border-b border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-amber-400"></div>
                  <span class="text-sm text-white/70">الخزائن</span>
                </div>
                <span class="font-mono font-bold text-sm text-amber-400">{{ fmt(data.stats.cashboxes.balance) }}</span>
              </div>
              <div class="flex items-center justify-between py-2">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-indigo-400"></div>
                  <span class="text-sm text-white/70">الخزينة العامة</span>
                </div>
                <span class="font-mono font-bold text-sm text-indigo-400">{{ fmt(data.stats.treasury.balance) }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Error State -->
      <div v-else class="flex flex-col items-center justify-center py-32 gap-5">
        <div class="w-20 h-20 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center">
          <AlertCircle class="w-10 h-10 text-red-400" />
        </div>
        <div class="text-center">
          <h3 class="text-xl font-bold text-white">تعذّر تحميل البيانات</h3>
          <p class="text-sm text-white/40 mt-1">فشل جلب إحصائيات داش بورد المحافظ</p>
        </div>
        <button @click="reload" class="px-6 py-2.5 bg-blue-500 text-white rounded-xl font-bold hover:bg-blue-400 transition">
          إعادة المحاولة
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useWalletStore } from '@/stores/walletStore';
import { useAuthStore } from '@/stores/authStore';
import {
  Wallet,
  RefreshCw,
  TrendingUp,
  BarChart3,
  CreditCard,
  Clock,
  PlusCircle,
  Plus,
  ArrowLeft,
  AlertCircle,
  AlertTriangle,
  Activity,
  ListOrdered,
  Users,
} from 'lucide-vue-next';
import { Wallet as Vault } from 'lucide-vue-next';

const store = useWalletStore();
const authStore = useAuthStore();
const isAdmin = computed(() => authStore.isAdmin || authStore.user?.role === 'owner');
const data = ref(null);
const loading = ref(true);

const fmt = (v) => Number(v || 0).toLocaleString('ar-EG');

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString('ar-EG', { dateStyle: 'medium' });
  } catch {
    return iso;
  }
};

const reload = async () => {
  loading.value = true;
  try {
    data.value = await store.fetchTransferDashboard();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
};

let pollingInterval = null;

onMounted(() => {
  reload();
  
  // Auto-refresh every 15 seconds to fetch new dashboard metrics without manual reload
  pollingInterval = setInterval(async () => {
    if (!loading.value) {
      await reload();
    }
  }, 15000);
});

onUnmounted(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval);
  }
});
</script>

<style scoped>
.wallet-module {
  --blue-glow: rgba(59, 130, 246, 0.1);
}
</style>
