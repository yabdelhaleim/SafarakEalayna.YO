<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header & Actions -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-tight">حجوزات الحج والعمرة</h1>
        <p class="text-muted mt-1">إدارة ومراقبة جميع حجوزات الحج والعمرة</p>
      </div>
      <div class="flex gap-3">
        <router-link :to="{ name: 'hajj.programs' }"
          class="bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all">
          <Calendar class="w-5 h-5" /> إدارة البرامج
        </router-link>
        <router-link :to="{ name: 'hajj.create' }"
          class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]">
          <Plus class="w-5 h-5" /> حجز جديد
        </router-link>
      </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="(stat, idx) in statsCards" :key="idx"
        class="p-6 bg-card border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-white/5 rounded-xl text-gold group-hover:scale-110 transition-transform">
            <component :is="stat.icon" class="w-6 h-6" />
          </div>
          <span
            v-if="stat.trend"
            :class="['text-xs font-bold px-2 py-1 rounded-full', stat.trendColor]"
          >
            {{ stat.trend }}
          </span>
        </div>
        <div>
          <div class="text-sm text-muted uppercase tracking-widest mb-1">{{ stat.label }}</div>
          <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">{{ animatedStats[idx] }}</div>
        </div>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 bg-card border border-white/10 rounded-2xl flex flex-wrap items-center gap-4">
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
        <input
          v-model="filters.search"
          type="text"
          placeholder="البحث بالرقم، العميل، أو البرنامج..."
          class="w-full pl-10 pr-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="onFilterChange"
        />
      </div>

      <select v-model="filters.status" @change="onFilterChange" class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[160px]">
        <option value="">جميع الحالات</option>
        <option v-for="s in (store.statuses?.hajj_umra || [])" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <select v-model="filters.programType" @change="onFilterChange" class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]">
        <option value="">جميع الأنواع</option>
        <option value="hajj">🕋 حج</option>
        <option value="umra">🕋 عمرة</option>
      </select>

      <input
        v-model="filters.dateFrom"
        type="date"
        class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="onFilterChange"
      />

      <input
        v-model="filters.dateTo"
        type="date"
        class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="onFilterChange"
      />

      <button @click="clearFilters" class="text-sm text-muted hover:text-gold transition-colors px-4 py-2">
        مسح الفلاتر
      </button>
    </div>

    <!-- Data Table -->
    <div class="bg-card border border-white/10 rounded-2xl overflow-hidden shadow-2xl">
      <!-- Desktop Table View -->
      <div v-if="!isMobile" class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">رقم الحجز</th>
              <th class="px-6 py-4 font-semibold">العميل</th>
              <th class="px-6 py-4 font-semibold">البرنامج</th>
              <th class="px-6 py-4 font-semibold">نوع الرحلة</th>
              <th class="px-6 py-4 font-semibold">السعر / الربح</th>
              <th class="px-6 py-4 font-semibold">المدفوعات</th>
              <th class="px-6 py-4 font-semibold">الحالة</th>
              <th class="px-6 py-4 font-semibold text-right">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.list">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td v-for="j in 8" :key="j" class="px-6 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="filteredBookings.length > 0">
              <template v-for="(booking, idx) in filteredBookings" :key="booking.id || idx">
                <tr v-if="booking && booking.id"
                  class="border-b border-white/5 hover:bg-white/5 transition-colors group"
                  :style="{ animationDelay: `${idx * 50}ms` }">
                  <td class="px-6 py-4">
                    <span class="font-mono text-gold font-bold">{{ booking.id }}</span>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <span class="font-bold text-sm">{{ booking.customer?.full_name || booking.customer?.name }}</span>
                      <span class="text-xs text-muted">{{ booking.customer?.phone }}</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <span class="font-bold text-sm">{{ booking.program?.program_name }}</span>
                      <span class="text-xs text-muted">{{ booking.program?.total_nights }} ليلة</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
                      :class="booking.program?.program_type === 'hajj' ? 'bg-gold/10 text-gold' : 'bg-success/10 text-success'">
                      {{ booking.program?.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة' }}
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <span class="font-mono text-sm">{{ Number(booking.pricing?.selling_price || 0).toLocaleString() }} {{ booking.pricing?.currency || 'EGP' }}</span>
                      <div :class="['flex items-center gap-1 text-[10px] font-bold', (booking.pricing?.profit ?? 0) >= 0 ? 'text-success' : 'text-error']">
                        <TrendingUp v-if="(booking.pricing?.profit ?? 0) >= 0" class="w-3 h-3" />
                        <TrendingDown v-else class="w-3 h-3" />
                        {{ Number(booking.pricing?.profit || 0).toLocaleString() }}
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <div class="w-24 bg-white/10 rounded-full h-2 mb-1">
                        <div class="h-2 rounded-full transition-all"
                          :class="paymentProgressClass(booking)"
                          :style="{ width: `${paymentPercentage(booking)}%` }">
                        </div>
                      </div>
                      <span class="text-[10px] text-muted">
                        {{ Number(booking.finance?.paid_amount || 0).toLocaleString() }} / {{ Number(booking.pricing?.selling_price || 0).toLocaleString() }}
                      </span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div :class="['inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider', statusStyles[booking.status] || 'bg-white/10 text-white']">
                      <span v-if="booking.status === 'confirmed'" class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                      {{ booking.status_label || statusLabels[booking.status] || booking.status }}
                    </div>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                      <router-link :to="{ name: 'hajj.show', params: { id: booking.id } }"
                        class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-white transition-all" title="عرض">
                        <Eye class="w-4 h-4" />
                      </router-link>
                      <router-link :to="{ name: 'hajj.edit', params: { id: booking.id } }"
                        class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-gold transition-all" title="تعديل">
                        <Edit2 class="w-4 h-4" />
                      </router-link>
                      <button @click="confirmDelete(booking)"
                        class="p-2 hover:bg-error/10 rounded-lg text-muted hover:text-error transition-all" title="حذف">
                        <Trash2 class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              </template>
            </template>
            <tr v-else>
              <td colspan="8" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <Calendar class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold">لم يتم العثور على حجوزات</h3>
                    <p class="text-muted text-sm mt-1">جرب تعديل الفلاتر أو إنشاء حجز جديد للبدء.</p>
                  </div>
                  <router-link :to="{ name: 'hajj.create' }" class="mt-2 text-gold font-bold hover:underline">
                    إنشاء حجز جديد
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile Card View -->
      <div v-else class="divide-y divide-white/5">
        <template v-if="!store.loading.list && filteredBookings.length > 0">
          <div v-for="(booking, idx) in filteredBookings" :key="booking.id || idx"
            class="p-4 space-y-3 hover:bg-white/5 transition-colors">
            <div class="flex items-center justify-between">
              <span class="font-mono text-gold font-bold text-sm">{{ booking.id }}</span>
              <div :class="['px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider', statusStyles[booking.status] || 'bg-white/10 text-white']">
                {{ booking.status_label || statusLabels[booking.status] || booking.status }}
              </div>
            </div>
            <div class="space-y-2">
              <div class="font-bold text-sm">{{ booking.customer?.full_name || booking.customer?.name }}</div>
              <div class="text-sm text-muted">{{ booking.program?.program_name }}</div>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-muted">{{ booking.program?.total_nights }} ليلة</span>
              <span :class="['font-bold font-mono', (booking.pricing?.profit ?? 0) >= 0 ? 'text-success' : 'text-error']">
                {{ (booking.pricing?.profit ?? 0) >= 0 ? '+' : '' }}{{ Number(booking.pricing?.profit || 0).toLocaleString() }}
              </span>
            </div>
            <div class="flex gap-2 pt-2 border-t border-white/5">
              <router-link :to="{ name: 'hajj.show', params: { id: booking.id } }"
                class="flex-1 py-2 text-center bg-white/5 hover:bg-white/10 rounded-lg text-sm font-medium transition-colors">
                عرض
              </router-link>
              <router-link :to="{ name: 'hajj.edit', params: { id: booking.id } }"
                class="flex-1 py-2 text-center bg-gold/10 hover:bg-gold/20 text-gold rounded-lg text-sm font-medium transition-colors">
                تعديل
              </router-link>
            </div>
          </div>
        </template>
        <div v-else-if="!store.loading.list" class="p-8 text-center">
          <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
            <Calendar class="w-8 h-8 text-white/10" />
          </div>
          <h3 class="text-lg font-bold mb-2">لم يتم العثور على حجوزات</h3>
          <p class="text-muted text-sm mb-4">جرب تعديل الفلاتر أو إنشاء حجز جديد.</p>
          <router-link :to="{ name: 'hajj.create' }" class="text-gold font-bold hover:underline">
            إنشاء حجز جديد
          </router-link>
        </div>
      </div>

      <!-- Pagination -->
      <div class="px-6 py-4 bg-white/5 border-t border-white/10 flex items-center justify-between text-sm text-muted">
        <div>عرض {{ (store.pagination.currentPage - 1) * store.pagination.perPage + 1 }} - {{ Math.min(store.pagination.currentPage * store.pagination.perPage, filteredBookings.length) }} من {{ filteredBookings.length }} نتيجة</div>
        <div class="flex items-center gap-2">
          <select v-model="store.filters.perPage" @change="onPerPageChange" class="px-3 py-2 bg-input border border-white/5 rounded-lg focus:border-gold outline-none text-sm">
            <option :value="10">10 لكل صفحة</option>
            <option :value="15">15 لكل صفحة</option>
            <option :value="25">25 لكل صفحة</option>
            <option :value="50">50 لكل صفحة</option>
          </select>
          <div class="flex items-center gap-1">
            <button @click="goToPage(store.pagination.currentPage - 1)" :disabled="store.pagination.currentPage === 1" class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30 disabled:hover:bg-transparent"><ChevronLeft class="w-4 h-4" /></button>
            <button v-for="page in visiblePages" :key="page"
              @click="goToPage(page)"
              :class="['w-8 h-8 flex items-center justify-center rounded-lg font-bold transition-colors', page === store.pagination.currentPage ? 'bg-gold text-black' : 'hover:bg-white/10']">
              {{ page }}
            </button>
            <button @click="goToPage(store.pagination.currentPage + 1)" :disabled="store.pagination.currentPage === store.pagination.lastPage" class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30 disabled:hover:bg-transparent"><ChevronRight class="w-4 h-4" /></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated } from 'vue';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useRoute, useRouter } from 'vue-router';
import { useMediaQuery } from '@vueuse/core';
import {
  Plus, Search, Eye, Edit2, Trash2, Calendar, ChevronLeft, ChevronRight,
  TrendingUp, TrendingDown, LayoutDashboard, DollarSign, Activity, Users
} from 'lucide-vue-next';

const store = useHajjUmraStore();
const route = useRoute();
const router = useRouter();

const filters = ref({
  search: route.query.search || '',
  status: route.query.status || '',
  programType: route.query.programType || '',
  dateFrom: route.query.dateFrom || '',
  dateTo: route.query.dateTo || ''
});

const isMobile = useMediaQuery('(max-width: 768px)');

const totalSource = ref(0);
const revenueSource = ref(0);
const profitSource = ref(0);
const activeSource = ref(0);

const totalOutput = ref(0);
const revenueOutput = ref(0);
const profitOutput = ref(0);
const activeOutput = ref(0);

const animateStats = () => {
  const stats = store.bookingStats;
  totalSource.value = stats.total;
  revenueSource.value = Math.floor(stats.revenue);
  profitSource.value = Math.floor(stats.profit);
  activeSource.value = stats.active;
};

// Simple animation
setInterval(() => {
  totalOutput.value = totalSource.value;
  revenueOutput.value = revenueSource.value;
  profitOutput.value = profitSource.value;
  activeOutput.value = activeSource.value;
}, 100);

const statsCards = computed(() => {
  return [
    { label: 'إجمالي الحجوزات', value: totalOutput.value.toLocaleString(), icon: LayoutDashboard },
    { label: 'الإيرادات', value: `${revenueOutput.value.toLocaleString()} ج.م`, icon: DollarSign },
    { label: 'إجمالي الربح', value: `${profitOutput.value.toLocaleString()} ج.م`, icon: Activity },
    { label: 'الرحلات النشطة', value: activeOutput.value.toLocaleString(), icon: Users },
  ];
});

const animatedStats = computed(() => {
  return [
    totalOutput.value.toLocaleString(),
    `${revenueOutput.value.toLocaleString()} EGP`,
    `${profitOutput.value.toLocaleString()} EGP`,
    activeOutput.value.toLocaleString()
  ];
});

const statusStyles = {
  pending: 'bg-white/10 text-white',
  confirmed: 'bg-success/10 text-success shadow-[0_0_15px_rgba(16,217,140,0.2)]',
  in_progress: 'bg-blue-500/10 text-blue-400',
  completed: 'bg-gold/10 text-gold',
  cancelled: 'bg-error/10 text-error',
  refunded: 'bg-muted/10 text-muted',
};

const statusLabels = computed(() => {
  const map = {};
  (store.statuses?.hajj_umra || []).forEach((s) => { map[s.value] = s.label; });
  return map;
});

const filteredBookings = computed(() => store.filteredBookings(filters.value));

const visiblePages = computed(() => {
  const current = store.pagination.currentPage;
  const last = store.pagination.lastPage;
  const delta = 2;
  const range = [];
  const rangeWithDots = [];

  for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
    range.push(i);
  }

  if (current - delta > 2) {
    rangeWithDots.push(1, '...');
  } else {
    rangeWithDots.push(1);
  }

  rangeWithDots.push(...range);

  if (current + delta < last - 1) {
    rangeWithDots.push('...', last);
  } else if (last > 1) {
    rangeWithDots.push(last);
  }

  return rangeWithDots;
});

const paymentPercentage = (booking) => {
  const sp = booking.pricing?.selling_price ?? booking.selling_price ?? 0;
  if (!sp) return 0;
  const paid = booking.finance?.paid_amount ?? booking.total_paid ?? 0;
  return Math.min(100, Math.round((paid / sp) * 100));
};

const paymentProgressClass = (booking) => {
  const percentage = paymentPercentage(booking);
  if (percentage >= 100) return 'bg-success';
  if (percentage >= 50) return 'bg-gold';
  return 'bg-warning';
};

const onFilterChange = () => {
  const apiFilters = {
    per_page: store.filters.perPage,
    page: 1
  };

  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.programType) apiFilters.program_type = filters.value.programType;
  if (filters.value.dateFrom) apiFilters.from_date = filters.value.dateFrom;
  if (filters.value.dateTo) apiFilters.to_date = filters.value.dateTo;

  store.filters = { ...filters.value, page: 1 };
  router.replace({ query: { ...filters.value } });
  store.fetchBookings(apiFilters);
};

const onPerPageChange = () => {
  const apiFilters = {
    per_page: store.filters.perPage,
    page: 1
  };

  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.programType) apiFilters.program_type = filters.value.programType;
  if (filters.value.dateFrom) apiFilters.from_date = filters.value.dateFrom;
  if (filters.value.dateTo) apiFilters.to_date = filters.value.dateTo;

  store.filters.page = 1;
  store.fetchBookings(apiFilters);
};

const goToPage = (page) => {
  if (page < 1 || page > store.pagination.lastPage || page === '...') return;

  const apiFilters = {
    per_page: store.filters.perPage,
    page: page
  };

  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.programType) apiFilters.program_type = filters.value.programType;
  if (filters.value.dateFrom) apiFilters.from_date = filters.value.dateFrom;
  if (filters.value.dateTo) apiFilters.to_date = filters.value.dateTo;

  store.filters.page = page;
  store.fetchBookings(apiFilters);
};

const clearFilters = () => {
  filters.value = { search: '', status: '', programType: '', dateFrom: '', dateTo: '' };
  store.filters = { page: 1, perPage: 15 };
  router.replace({ query: {} });
  store.fetchBookings({ per_page: 15, page: 1 });
};

const confirmDelete = async (booking) => {
  if (confirm(`هل أنت متأكد من أنك تريد حذف الحجز ${booking.id}؟`)) {
    try {
      await store.deleteBooking(booking.id, '');
      store.addToast(`تم حذف الحجز ${booking.id} بنجاح`);
      await store.fetchBookings();
    } catch (error) {
      store.addToast('فشل حذف الحجز', 'error');
    }
  }
};

const fetchData = async () => {
  const apiFilters = {
    per_page: store.filters.perPage,
    page: 1
  };

  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.programType) apiFilters.program_type = filters.value.programType;
  if (filters.value.dateFrom) apiFilters.from_date = filters.value.dateFrom;
  if (filters.value.dateTo) apiFilters.to_date = filters.value.dateTo;

  await store.fetchBookings(apiFilters);
  await store.fetchSettings();
  animateStats();
};

onMounted(async () => {
  if (route.query.search) filters.value.search = route.query.search;
  if (route.query.status) filters.value.status = route.query.status;
  if (route.query.programType) filters.value.programType = route.query.programType;
  if (route.query.dateFrom) filters.value.dateFrom = route.query.dateFrom;
  if (route.query.dateTo) filters.value.dateTo = route.query.dateTo;

  await fetchData();
});

onActivated(async () => {
  await fetchData();
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
</style>
