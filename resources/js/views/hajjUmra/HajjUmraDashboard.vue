<template>
  <div class="animate-in fade-in duration-500 pb-16">
    <!-- Hero Header -->
    <header class="relative overflow-hidden bg-gradient-to-br from-[#201a0a] via-[#1f1b11] to-[#111827] border-b border-white/5">
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-amber-500/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-yellow-500/10 rounded-full blur-3xl translate-y-1/2 -translate-x-1/3"></div>
      </div>
      <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div class="flex items-center gap-3 mb-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 border border-amber-500/30">
                <LibraryBigIcon class="h-5 w-5 text-amber-300" />
              </div>
              <span class="text-xs font-bold uppercase tracking-[0.2em] text-amber-300/80">الحج والعمرة</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">لوحة تحكم الحج والعمرة</h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
              نظرة شاملة على نشاط القسم وحركة الأموال — حجوزات، إيرادات، وأرصدة الحسابات (الخزائن والبنوك والمحافظ).
            </p>
          </div>
          <div class="flex shrink-0 items-center gap-3">
            <div class="text-left bg-white/5 border border-white/10 rounded-xl px-4 py-2 hidden sm:block">
              <p class="text-[10px] text-white/40 uppercase tracking-widest">آخر تحديث</p>
              <p class="text-sm font-bold text-white/80 mt-0.5">{{ lastUpdated }}</p>
            </div>
            <button
              @click="reload"
              :disabled="loading"
              class="inline-flex items-center gap-2 rounded-xl border border-amber-500/40 bg-amber-500/20 px-5 py-2.5 text-sm font-bold text-amber-100 transition hover:bg-amber-500/30 hover:scale-105 disabled:opacity-50"
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
              تحديث
            </button>
            <router-link
              :to="{ name: 'hajj.create' }"
              class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-bold text-black transition hover:bg-amber-400 hover:scale-105"
            >
              <Plus class="h-4 w-4" />
              حجز جديد
            </router-link>
          </div>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8 space-y-8">
      <div v-if="loading && !data" class="space-y-8">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-5">
          <div v-for="i in 5" :key="i" class="h-32 rounded-2xl bg-white/5 animate-pulse"></div>
        </div>
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
          <div class="lg:col-span-2 h-80 rounded-2xl bg-white/5 animate-pulse"></div>
          <div class="h-80 rounded-2xl bg-white/5 animate-pulse"></div>
        </div>
      </div>

      <template v-else-if="data">
        <!-- KPI Cards -->
        <section :class="['grid gap-5', isAdmin ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5' : 'grid-cols-1 sm:grid-cols-2']">
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-6 transition hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10">
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                  <TrendingUp class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">الشهر</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-emerald-400/70 mb-1">إيرادات الشهر</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">{{ fmt(data.stats.monthly_revenue) }}</p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <div class="group relative overflow-hidden rounded-2xl border border-indigo-500/20 bg-gradient-to-br from-indigo-500/10 to-transparent p-6 transition hover:border-indigo-500/40 hover:shadow-lg hover:shadow-indigo-500/10">
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400">
                  <Ticket class="h-5 w-5" />
                </div>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-indigo-400/70 mb-1">إجمالي الحجوزات</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">{{ fmt(data.stats.total_bookings) }}</p>
              <p class="text-[11px] text-white/30 mt-1">حجز</p>
            </div>
          </div>

          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-6 transition hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10">
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                  <Vault class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded-full">{{ data.stats.cashboxes.count }} خزينة</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-amber-400/70 mb-1">الخزائن النقدية</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">{{ fmt(data.stats.cashboxes.balance) }}</p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-6 transition hover:border-sky-500/40 hover:shadow-lg hover:shadow-sky-500/10">
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                  <Landmark class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-sky-500/20 text-sky-400 px-2 py-0.5 rounded-full">{{ data.stats.banks.count }} بنك</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-sky-400/70 mb-1">البنوك والبريد</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">{{ fmt(data.stats.banks.balance) }}</p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-teal-500/20 bg-gradient-to-br from-teal-500/10 to-transparent p-6 transition hover:border-teal-500/40 hover:shadow-lg hover:shadow-teal-500/10">
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400">
                  <Smartphone class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-teal-500/20 text-teal-400 px-2 py-0.5 rounded-full">{{ data.stats.wallets.count }} محفظة</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-teal-400/70 mb-1">المحافظ الرقمية</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">{{ fmt(data.stats.wallets.balance) }}</p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>
        </section>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
          <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <Clock class="w-5 h-5 text-amber-300" />
                أحدث الحجوزات
              </h2>
              <router-link :to="{ name: 'hajj.list' }" class="text-xs font-bold text-amber-300 hover:text-amber-200 transition flex items-center gap-1">
                عرض الكل
                <ArrowLeft class="w-3.5 h-3.5" />
              </router-link>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
              <table class="min-w-full text-right text-sm">
                <thead class="border-b border-white/5 bg-black/20">
                  <tr class="text-[11px] uppercase tracking-widest text-white/40">
                    <th class="px-5 py-4 font-bold">العميل</th>
                    <th class="px-5 py-4 font-bold">البرنامج</th>
                    <th class="px-5 py-4 font-bold">السعر</th>
                    <th v-if="isAdmin" class="px-5 py-4 font-bold">الربح</th>
                    <th class="px-5 py-4 font-bold">التاريخ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <tr
                    v-for="b in data.recent_bookings"
                    :key="b.id"
                    class="transition hover:bg-white/[0.03] group cursor-pointer"
                    @click="$router.push({ name: 'hajj.show', params: { id: b.id } })"
                  >
                    <td class="px-5 py-3.5 font-medium text-white/90 text-sm">{{ b.customer?.name || '—' }}</td>
                    <td class="px-5 py-3.5 text-xs text-white/60">{{ b.program?.program_name || '—' }}</td>
                    <td class="px-5 py-3.5 font-mono font-bold text-white text-sm tabular-nums">{{ fmt(b.selling_price || 0) }}</td>
                    <td v-if="isAdmin" class="px-5 py-3.5">
                      <span :class="['font-mono font-bold text-sm', (b.profit || 0) >= 0 ? 'text-emerald-400' : 'text-red-400']">
                        {{ (b.profit || 0) >= 0 ? '+' : '' }}{{ fmt(b.profit || 0) }}
                      </span>
                    </td>
                    <td class="px-5 py-3.5 text-xs text-white/40">{{ formatDt(b.created_at) }}</td>
                  </tr>
                  <tr v-if="!data.recent_bookings?.length">
                    <td colspan="5" class="px-5 py-16 text-center">
                      <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center">
                          <LibraryBigIcon class="w-7 h-7 text-white/10" />
                        </div>
                        <p class="text-white/30 text-sm">لا توجد حجوزات حديثة</p>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="space-y-6">
            <div v-if="isAdmin" class="rounded-2xl border border-amber-500/20 bg-gradient-to-b from-amber-950/50 to-transparent p-6 text-center relative overflow-hidden">
              <div class="absolute inset-0 bg-gradient-to-br from-amber-500/5 to-transparent pointer-events-none"></div>
              <div class="relative">
                <div class="flex items-center justify-center gap-2 mb-4">
                  <Activity class="w-4 h-4 text-amber-300" />
                  <h2 class="text-xs font-bold uppercase tracking-widest text-amber-300/80">إجمالي السيولة</h2>
                </div>
                <p class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-white to-amber-200 drop-shadow tabular-nums mb-2">
                  {{ fmt(data.liquidity?.total || 0) }}
                </p>
                <p class="text-xs text-white/30">يشمل جميع حسابات الحج والعمرة</p>
              </div>
            </div>

            <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-5">
              <h2 class="text-xs font-bold uppercase tracking-widest text-white/40 mb-4">وصول سريع</h2>
              <div class="grid grid-cols-2 gap-3">
                <router-link
                  :to="{ name: 'hajj.create' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-amber-500/10 bg-amber-500/5 p-4 transition hover:border-amber-500/30 hover:bg-amber-500/10"
                >
                  <PlusCircle class="h-6 w-6 text-amber-300 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-amber-100">حجز جديد</span>
                </router-link>
                <router-link
                  v-if="isAdmin"
                  :to="{ name: 'hajj.treasury' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-sky-500/10 bg-sky-500/5 p-4 transition hover:border-sky-500/30 hover:bg-sky-500/10"
                >
                  <Vault class="h-6 w-6 text-sky-300 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-sky-100">الخزنة</span>
                </router-link>
                <router-link
                  :to="{ name: 'hajj.executing-companies' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 p-4 transition hover:border-white/20 hover:bg-white/10"
                >
                  <Building2 class="h-6 w-6 text-white/70 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-white/80">الشركات</span>
                </router-link>
                <router-link
                  :to="{ name: 'hajj.list' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-indigo-500/10 bg-indigo-500/5 p-4 transition hover:border-indigo-500/30 hover:bg-indigo-500/10"
                >
                  <Ticket class="h-6 w-6 text-indigo-300 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-indigo-100">الحجوزات</span>
                </router-link>
              </div>
            </div>
          </div>
        </div>
      </template>

      <div v-else class="rounded-2xl border border-red-500/30 bg-red-500/10 p-16 text-center">
        <p class="text-red-400 font-bold">تعذر تحميل بيانات الداش بورد.</p>
        <button @click="reload" class="mt-4 px-6 py-2 bg-red-500 text-white rounded-xl hover:bg-red-400 transition">إعادة المحاولة</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';
import { useAuthStore } from '@/stores/authStore';
import {
  Activity,
  ArrowLeft,
  Building2,
  LibraryBigIcon,
  Clock,
  Landmark,
  Plus,
  PlusCircle,
  RefreshCw,
  Smartphone,
  Ticket,
  TrendingUp,
  Vault,
} from 'lucide-vue-next';

const loading = ref(true);
const authStore = useAuthStore();
const isAdmin = computed(() => authStore.isAdmin || authStore.user?.role === 'owner');
const data = ref(null);
const lastUpdated = ref('');

const fmt = (n) => Number(n || 0).toLocaleString('ar-EG');

const formatDt = (dt) => {
  try {
    return new Date(dt).toLocaleString('ar-EG');
  } catch {
    return dt || '—';
  }
};

const reload = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/v1/hajj-umra/dashboard');
    data.value = res.data?.data ?? null;
    lastUpdated.value = new Date().toLocaleString('ar-EG');
  } catch (e) {
    console.error('HajjUmraDashboard reload', e);
    data.value = null;
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

