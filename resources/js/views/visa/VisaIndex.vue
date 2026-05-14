<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header & Actions -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-white tracking-tight">طلبات التأشيرات</h1>
        <p class="text-muted mt-1">إدارة ومراقبة جميع طلبات التأشيرات</p>
      </div>
      <router-link :to="{ name: 'visa.create' }"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]">
        <Plus class="w-5 h-5" /> طلب جديد
      </router-link>
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
          placeholder="البحث بالرقم، العميل، أو الدولة..."
          class="w-full pl-10 pr-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="onFilterChange"
        />
      </div>

      <select v-model="filters.status" @change="onFilterChange" class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[160px]">
        <option value="">جميع الحالات</option>
        <option v-for="s in (store.statuses?.visa || [])" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <select v-model="filters.country" @change="onFilterChange" class="px-4 py-2.5 bg-input border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]">
        <option value="">جميع الدول</option>
        <option v-for="country in uniqueCountries" :key="country" :value="country">
          {{ country }}
        </option>
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
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">رقم الطلب</th>
              <th class="px-6 py-4 font-semibold">العميل</th>
              <th class="px-6 py-4 font-semibold">الدولة</th>
              <th class="px-6 py-4 font-semibold">نوع التأشيرة</th>
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
              <tr v-for="(booking, idx) in filteredBookings" :key="booking.id || idx"
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
                  <div class="font-bold text-sm">{{ booking.visa_detail?.country }}</div>
                  <div class="text-xs text-muted">{{ booking.visa_detail?.visa_type_label || booking.visa_detail?.visa_type }}</div>
                </td>
                <td class="px-6 py-4">
                  <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-white/10 text-white">
                    {{ booking.visa_detail?.entry_type_label || booking.visa_detail?.entry_type || '—' }}
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
                    {{ booking.status_label || statusLabels[booking.status] || booking.status }}
                  </div>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <router-link :to="{ name: 'visa.show', params: { id: booking.id } }"
                      class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-white transition-all" title="عرض">
                      <Eye class="w-4 h-4" />
                    </router-link>
                    <router-link :to="{ name: 'visa.edit', params: { id: booking.id } }"
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
            <tr v-else>
              <td colspan="8" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <Stamp class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold">لم يتم العثور على طلبات</h3>
                    <p class="text-muted text-sm mt-1">جرب تعديل الفلاتر أو إنشاء طلب جديد للبدء.</p>
                  </div>
                  <router-link :to="{ name: 'visa.create' }" class="mt-2 text-gold font-bold hover:underline">
                    إنشاء طلب جديد
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
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
            <button @click="goToPage(store.pagination.currentPage - 1)" :disabled="store.pagination.currentPage === 1" class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30"><ChevronLeft class="w-4 h-4" /></button>
            <button v-for="page in visiblePages" :key="page"
              @click="goToPage(page)"
              :class="['w-8 h-8 flex items-center justify-center rounded-lg font-bold transition-colors', page === store.pagination.currentPage ? 'bg-gold text-black' : 'hover:bg-white/10']">
              {{ page }}
            </button>
            <button @click="goToPage(store.pagination.currentPage + 1)" :disabled="store.pagination.currentPage === store.pagination.lastPage" class="p-2 hover:bg-white/10 rounded-lg disabled:opacity-30"><ChevronRight class="w-4 h-4" /></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated } from 'vue';
import { useVisaStore } from '@/stores/visaStore';
import { useRoute, useRouter } from 'vue-router';
import {
  Plus, Search, Eye, Edit2, Trash2, ChevronLeft, ChevronRight,
  TrendingUp, TrendingDown, LayoutDashboard, DollarSign, Activity, Users, Stamp
} from 'lucide-vue-next';

const store = useVisaStore();
const route = useRoute();
const router = useRouter();

const filters = ref({
  search: route.query.search || '',
  status: route.query.status || '',
  country: route.query.country || '',
  visaType: route.query.visaType || '',
  dateFrom: route.query.dateFrom || '',
  dateTo: route.query.dateTo || ''
});

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

setInterval(() => {
  totalOutput.value = totalSource.value;
  revenueOutput.value = revenueSource.value;
  profitOutput.value = profitSource.value;
  activeOutput.value = activeSource.value;
}, 100);

const statsCards = computed(() => {
  return [
    { label: 'إجمالي الطلبات', value: totalOutput.value.toLocaleString(), icon: LayoutDashboard },
    { label: 'الإيرادات', value: `${revenueOutput.value.toLocaleString()} ج.م`, icon: DollarSign },
    { label: 'إجمالي الربح', value: `${profitOutput.value.toLocaleString()} ج.م`, icon: Activity },
    { label: 'الطلبات النشطة', value: activeOutput.value.toLocaleString(), icon: Users },
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
  draft: 'bg-white/10 text-muted',
  submitted: 'bg-blue-500/10 text-blue-400',
  under_review: 'bg-warning/10 text-warning',
  approved: 'bg-success/10 text-success shadow-[0_0_15px_rgba(16,217,140,0.2)]',
  rejected: 'bg-error/10 text-error',
  issued: 'bg-success/10 text-success',
  cancelled: 'bg-muted/10 text-muted',
  refunded: 'bg-muted/10 text-muted',
  pending: 'bg-warning/10 text-warning',
};

const statusLabels = computed(() => {
  const m = {};
  (store.statuses?.visa || []).forEach((s) => { m[s.value] = s.label; });
  return m;
});

const buildApiFilters = (page = 1) => {
  const api = { per_page: store.filters.perPage, page };
  if (filters.value.search) api.search = filters.value.search;
  if (filters.value.status) api.status = filters.value.status;
  if (filters.value.country) api.country = filters.value.country;
  if (filters.value.visaType) api.visa_type = filters.value.visaType;
  if (filters.value.dateFrom) api.from_date = filters.value.dateFrom;
  if (filters.value.dateTo) api.to_date = filters.value.dateTo;
  return api;
};

const filteredBookings = computed(() => {
  const { country, visaType, ...rest } = filters.value;
  return store.filteredBookings(rest);
});

const uniqueCountries = computed(() => {
  const countries = new Set();
  store.bookings.forEach(booking => {
    if (booking.visa_detail?.country) {
      countries.add(booking.visa_detail.country);
    }
  });
  return Array.from(countries).sort();
});

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
  store.filters = { ...filters.value, page: 1 };
  router.replace({ query: { ...filters.value } });
  store.fetchBookings(buildApiFilters(1));
};

const onPerPageChange = () => {
  store.filters.page = 1;
  store.fetchBookings(buildApiFilters(1));
};

const goToPage = (page) => {
  if (page < 1 || page > store.pagination.lastPage || page === '...') return;
  store.filters.page = page;
  store.fetchBookings(buildApiFilters(page));
};

const clearFilters = () => {
  filters.value = { search: '', status: '', country: '', visaType: '', dateFrom: '', dateTo: '' };
  store.filters = { page: 1, perPage: 15 };
  router.replace({ query: {} });
  store.fetchBookings({ per_page: 15, page: 1 });
};

const confirmDelete = async (booking) => {
  if (confirm(`هل أنت متأكد من أنك تريد حذف الطلب ${booking.id}؟`)) {
    try {
      await store.deleteBooking(booking.id, '');
      store.addToast(`تم حذف الطلب ${booking.id} بنجاح`);
      await store.fetchBookings(buildApiFilters(1));
    } catch (error) {
      store.addToast('فشل حذف الطلب', 'error');
    }
  }
};

const fetchData = async () => {
  await store.fetchSettings();
  await store.fetchBookings(buildApiFilters(1));
  animateStats();
};

onMounted(async () => {
  if (route.query.search) filters.value.search = route.query.search;
  if (route.query.status) filters.value.status = route.query.status;
  if (route.query.country) filters.value.country = route.query.country;
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
.text-warning { color: var(--warning); }
.bg-blue { background-color: #3b82f6; }
.text-blue { color: #3b82f6; }
</style>
