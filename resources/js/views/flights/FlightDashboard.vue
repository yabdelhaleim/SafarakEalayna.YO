<template>
  <div class="animate-in fade-in duration-500 pb-16">

    <!-- Hero Header -->
    <header class="relative overflow-hidden bg-gradient-to-br from-[#0a1628] via-[#0d1f3c] to-[#111827] border-b border-white/5">
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-sky-500/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl translate-y-1/2 -translate-x-1/3"></div>
      </div>
      <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div class="flex items-center gap-3 mb-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 border border-sky-500/30">
                <Plane class="h-5 w-5 text-sky-400" />
              </div>
              <span class="text-xs font-bold uppercase tracking-[0.2em] text-sky-400/80">وحدة الطيران</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
              لوحة تحكم الطيران
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
              نظرة عامة على نشاط قسم الطيران وحركة الأموال — إيرادات، حجوزات، وأرصدة الحسابات من مكان واحد.
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
              class="inline-flex items-center gap-2 rounded-xl border border-sky-500/40 bg-sky-500/20 px-5 py-2.5 text-sm font-bold text-sky-200 transition hover:bg-sky-500/30 hover:scale-105 disabled:opacity-50"
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
              تحديث
            </button>
            <router-link
              :to="{ name: 'flights.create' }"
              class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-sky-400 hover:scale-105"
            >
              <Plus class="h-4 w-4" />
              حجز جديد
            </router-link>
          </div>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8 space-y-8">

      <!-- Loading Skeleton -->
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

          <!-- Monthly Revenue -->
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-6 transition hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-emerald-500/10 blur-2xl group-hover:bg-emerald-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                  <TrendingUp class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">الشهر</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-emerald-400/70 mb-1">إيرادات الشهر</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.monthly_revenue) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Total Bookings -->
          <div class="group relative overflow-hidden rounded-2xl border border-indigo-500/20 bg-gradient-to-br from-indigo-500/10 to-transparent p-6 transition hover:border-indigo-500/40 hover:shadow-lg hover:shadow-indigo-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-indigo-500/10 blur-2xl group-hover:bg-indigo-500/20 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400">
                  <Ticket class="h-5 w-5" />
                </div>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-indigo-400/70 mb-1">إجمالي الحجوزات</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.total_bookings) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">تذكرة</p>
            </div>
          </div>

          <!-- Cashboxes -->
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-6 transition hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-amber-500/15 blur-2xl group-hover:bg-amber-500/25 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                  <Vault class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded-full">{{ data.stats.cashboxes.count }} خزينة</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-amber-400/70 mb-1">الخزائن النقدية</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.cashboxes.balance) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Banks -->
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-6 transition hover:border-sky-500/40 hover:shadow-lg hover:shadow-sky-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-sky-500/15 blur-2xl group-hover:bg-sky-500/25 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                  <Landmark class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-sky-500/20 text-sky-400 px-2 py-0.5 rounded-full">{{ data.stats.banks.count }} بنك</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-sky-400/70 mb-1">البنوك</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.banks.balance) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

          <!-- Wallets -->
          <div v-if="isAdmin" class="group relative overflow-hidden rounded-2xl border border-teal-500/20 bg-gradient-to-br from-teal-500/10 to-transparent p-6 transition hover:border-teal-500/40 hover:shadow-lg hover:shadow-teal-500/10">
            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-teal-500/15 blur-2xl group-hover:bg-teal-500/25 transition"></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400">
                  <Smartphone class="h-5 w-5" />
                </div>
                <span class="text-[10px] font-bold bg-teal-500/20 text-teal-400 px-2 py-0.5 rounded-full">{{ data.stats.wallets.count }} محفظة</span>
              </div>
              <p class="text-xs font-bold uppercase tracking-wider text-teal-400/70 mb-1">المحافظ الرقمية</p>
              <p class="font-mono text-2xl font-black text-white tabular-nums">
                {{ fmt(data.stats.wallets.balance) }}
              </p>
              <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
            </div>
          </div>

        </section>

        <!-- Main Grid -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">

          <!-- Recent Bookings Table (2/3) -->
          <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <Clock class="w-5 h-5 text-sky-400" />
                أحدث الحجوزات
              </h2>
              <router-link :to="{ name: 'flights.list' }" class="text-xs font-bold text-sky-400 hover:text-sky-300 transition flex items-center gap-1">
                عرض الكل
                <ArrowLeft class="w-3.5 h-3.5" />
              </router-link>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
              <table class="min-w-full text-right text-sm">
                <thead class="border-b border-white/5 bg-black/20">
                  <tr class="text-[11px] uppercase tracking-widest text-white/40">
                    <th class="px-5 py-4 font-bold">رقم الحجز</th>
                    <th class="px-5 py-4 font-bold">العميل</th>
                    <th class="px-5 py-4 font-bold">المسار</th>
                    <th class="px-5 py-4 font-bold">السعر</th>
                    <th v-if="isAdmin" class="px-5 py-4 font-bold">الربح</th>
                    <th class="px-5 py-4 font-bold">التاريخ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <tr
                    v-for="booking in data.recent_bookings"
                    :key="booking.id"
                    class="transition hover:bg-white/[0.03] group cursor-pointer"
                    @click="$router.push({ name: 'flights.show', params: { id: booking.id } })"
                  >
                    <td class="px-5 py-3.5">
                      <span class="font-mono font-bold text-sky-400 text-xs">{{ booking.booking_number }}</span>
                    </td>
                    <td class="px-5 py-3.5 font-medium text-white/90 text-sm">{{ booking.customer?.name || '—' }}</td>
                    <td class="px-5 py-3.5">
                      <span v-if="booking.from_airport && booking.to_airport" class="font-mono text-xs text-white/60 flex items-center gap-1">
                        {{ booking.from_airport }}
                        <span class="text-white/20">→</span>
                        {{ booking.to_airport }}
                      </span>
                      <span v-else class="text-white/30 text-xs">—</span>
                    </td>
                    <td class="px-5 py-3.5 font-mono font-bold text-white text-sm">
                      {{ fmt(booking.pricing?.sellingPrice || 0) }}
                    </td>
                    <td v-if="isAdmin" class="px-5 py-3.5">
                      <span :class="['font-mono font-bold text-sm', (booking.pricing?.profit || 0) >= 0 ? 'text-emerald-400' : 'text-red-400']">
                        {{ (booking.pricing?.profit || 0) >= 0 ? '+' : '' }}{{ fmt(booking.pricing?.profit || 0) }}
                      </span>
                    </td>
                    <td class="px-5 py-3.5 text-xs text-white/40">{{ formatDt(booking.created_at) }}</td>
                  </tr>
                  <tr v-if="!data.recent_bookings?.length">
                    <td :colspan="isAdmin ? 6 : 5" class="px-5 py-16 text-center">
                      <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center">
                          <Plane class="w-7 h-7 text-white/10 -rotate-45" />
                        </div>
                        <p class="text-white/30 text-sm">لا توجد حجوزات حديثة</p>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Sidebar Column (1/3) -->
          <div class="space-y-6">

            <!-- Total Liquidity Card -->
            <div v-if="isAdmin" class="rounded-2xl border border-sky-500/20 bg-gradient-to-b from-sky-950/60 to-transparent p-6 text-center relative overflow-hidden">
              <div class="absolute inset-0 bg-gradient-to-br from-sky-500/5 to-transparent pointer-events-none"></div>
              <div class="relative">
                <div class="flex items-center justify-center gap-2 mb-4">
                  <Activity class="w-4 h-4 text-sky-400" />
                  <h2 class="text-xs font-bold uppercase tracking-widest text-sky-400/80">إجمالي السيولة</h2>
                </div>
                <p class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-white to-sky-300 drop-shadow tabular-nums mb-2">
                  {{ fmt(data.liquidity?.total || 0) }}
                </p>
                <p class="text-xs text-white/30">يشمل جميع حسابات الطيران</p>
              </div>
            </div>

            <!-- Quick Actions -->
            <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-5">
              <h2 class="text-xs font-bold uppercase tracking-widest text-white/40 mb-4">وصول سريع</h2>
              <div class="grid grid-cols-2 gap-3">
                <router-link
                  :to="{ name: 'flights.create' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-sky-500/10 bg-sky-500/5 p-4 transition hover:border-sky-500/30 hover:bg-sky-500/10"
                >
                  <PlusCircle class="h-6 w-6 text-sky-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-sky-200">حجز جديد</span>
                </router-link>
                <router-link
                  :to="{ name: 'flights.list' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-indigo-500/10 bg-indigo-500/5 p-4 transition hover:border-indigo-500/30 hover:bg-indigo-500/10"
                >
                  <ListOrdered class="h-6 w-6 text-indigo-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-indigo-200">الحجوزات</span>
                </router-link>
                <router-link
                  v-if="isAdmin"
                  :to="{ name: 'flights.treasury' }"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-amber-500/10 bg-amber-500/5 p-4 transition hover:border-amber-500/30 hover:bg-amber-500/10"
                >
                  <Vault class="h-6 w-6 text-amber-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-amber-200">الخزينة</span>
                </router-link>
                <a
                  :href="'/admin?token=' + authStore.token"
                  target="_blank"
                  class="group flex flex-col items-center justify-center gap-2 rounded-xl border border-teal-500/10 bg-teal-500/5 p-4 transition hover:border-teal-500/30 hover:bg-teal-500/10"
                >
                  <Settings class="h-6 w-6 text-teal-400 transition group-hover:scale-110" />
                  <span class="text-xs font-bold text-teal-200">الإعدادات</span>
                </a>
              </div>
            </div>

            <!-- Balance Breakdown -->
            <div v-if="isAdmin" class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 space-y-3">
              <h2 class="text-xs font-bold uppercase tracking-widest text-white/40 mb-4">توزيع الأرصدة</h2>

              <div class="flex items-center justify-between py-2 border-b border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-amber-400"></div>
                  <span class="text-sm text-white/70">الخزائن ({{ data.stats.cashboxes.count }})</span>
                </div>
                <span class="font-mono font-bold text-sm text-amber-400">{{ fmt(data.stats.cashboxes.balance) }}</span>
              </div>

              <div class="flex items-center justify-between py-2 border-b border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-sky-400"></div>
                  <span class="text-sm text-white/70">البنوك ({{ data.stats.banks.count }})</span>
                </div>
                <span class="font-mono font-bold text-sm text-sky-400">{{ fmt(data.stats.banks.balance) }}</span>
              </div>

              <div class="flex items-center justify-between py-2">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full bg-teal-400"></div>
                  <span class="text-sm text-white/70">المحافظ ({{ data.stats.wallets.count }})</span>
                </div>
                <span class="font-mono font-bold text-sm text-teal-400">{{ fmt(data.stats.wallets.balance) }}</span>
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
          <p class="text-sm text-white/40 mt-1">تحقق من الاتصال أو سجّل دخولك مجدداً</p>
        </div>
        <button @click="reload" class="px-6 py-2.5 bg-sky-500 text-white rounded-xl font-bold hover:bg-sky-400 transition">
          إعادة المحاولة
        </button>
      </div>

    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import { useAuthStore } from '@/stores/authStore';
import {
  Plane,
  RefreshCw,
  TrendingUp,
  Ticket,
  Landmark,
  Smartphone,
  Clock,
  PlusCircle,
  Plus,
  ArrowLeft,
  Activity,
  ListOrdered,
  Settings,
  AlertCircle,
} from 'lucide-vue-next';

// Vault might not exist in older lucide — use Wallet as fallback
import { Wallet as Vault } from 'lucide-vue-next';

const store = useFlightStore();
const authStore = useAuthStore();
const isAdmin = computed(() => authStore.isAdmin || authStore.user?.role === 'owner');
const data = ref(null);
const loading = ref(true);
const lastUpdated = ref('—');

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
  data.value = await store.fetchFlightDashboard();
  loading.value = false;
  lastUpdated.value = new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
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
