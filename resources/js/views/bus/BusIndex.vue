<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          حجوزات الباصات
        </h1>
        <p class="text-text-muted mt-1">
          إدارة ومتابعة حجوزات السفر بالباصات
        </p>
      </div>
      <router-link
        :to="{ name: 'bus.create' }"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <Plus class="w-5 h-5" />
        حجز جديد
      </router-link>
    </div>

    <div
      v-if="store.errors?.fetch"
      class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-error/30 bg-error/10 px-4 py-3 text-sm text-error"
    >
      <span>{{ store.errors.fetch }}</span>
      <button type="button" class="font-bold text-gold hover:underline" @click="retryLoad">إعادة المحاولة</button>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-gold/10 rounded-xl text-gold group-hover:scale-110 transition-transform">
            <Bus class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-gold/10 text-gold">إجمالي</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الحجوزات</div>
          <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">{{ store.stats.total_bookings }}</div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <CheckCircle class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">مدفوع</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">الحجوزات المدفوعة</div>
          <div class="text-2xl font-bold font-mono group-hover:text-success transition-colors">{{ store.stats.paid_bookings }}</div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-warning/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-warning/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-warning/10 rounded-xl text-warning group-hover:scale-110 transition-transform">
            <Clock class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-warning/10 text-warning">معلق</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">حجوزات معلقة</div>
          <div class="text-2xl font-bold font-mono group-hover:text-warning transition-colors">{{ store.stats.pending_bookings }}</div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-blue-400/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-blue-400/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-blue-500/10 rounded-xl text-blue-500 group-hover:scale-110 transition-transform">
            <DollarSign class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-blue-500/10 text-blue-500">إيرادات</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الإيرادات</div>
          <div class="text-2xl font-bold font-mono group-hover:text-blue-400 transition-colors">
            {{ store.stats.total_revenue?.toLocaleString() || 0 }}
          </div>
          <div class="text-xs text-text-muted mt-1">جنيه</div>
        </div>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl grid grid-cols-1 sm:grid-cols-2 lg:flex lg:items-center gap-4">
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="store.filters.search"
          type="text"
          placeholder="بحث برقم الحجز أو العميل..."
          class="w-full pl-10 pr-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
          @input="onSearchInput"
        />
      </div>

      <select
        v-model="store.filters.status"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
        @change="onFilterChange"
      >
        <option value="">جميع الحالات</option>
        <option value="pending">معلق</option>
        <option value="paid">مدفوع</option>
        <option value="cancelled">ملغي</option>
      </select>

      <select
        v-model="store.filters.company_id"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
        @change="onFilterChange"
      >
        <option value="">جميع الشركات</option>
        <option v-for="company in store.companies" :key="company.id" :value="company.id">
          {{ company.name }}
        </option>
      </select>

      <input
        v-model="store.filters.date_from"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="onFilterChange"
      />

      <input
        v-model="store.filters.date_to"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="onFilterChange"
      />

      <!--
        Phase 6.2 — Bus #B-01 fix
        The Vue store declared `route_from` / `route_to` filters but the UI
        had no inputs to set them, so users had no way to narrow the list
        by path. Adding the inputs here mirrors the BusBookingService
        filter that the backend now honours (LIKE '%X%' against the
        inventory's `route` column).
      -->
      <input
        v-model="store.filters.route_from"
        type="text"
        placeholder="من (مثل: القاهرة)"
        dir="rtl"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm min-w-[140px]"
        @input="onSearchInput"
      />
      <input
        v-model="store.filters.route_to"
        type="text"
        placeholder="إلى (مثل: أسوان)"
        dir="rtl"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm min-w-[140px]"
        @input="onSearchInput"
      />

      <button @click="clearFilters" class="text-sm text-text-muted hover:text-gold transition-colors px-4 py-2">
        مسح الفلاتر
      </button>
    </div>

    <!-- Bookings Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">رقم الحجز</th>
              <th class="px-6 py-4 font-semibold">العميل</th>
              <th class="px-6 py-4 font-semibold">الرحلة</th>
              <th class="px-6 py-4 font-semibold">تاريخ السفر</th>
              <th class="px-6 py-4 font-semibold">مقاعد</th>
              <th class="px-6 py-4 font-semibold">السعر</th>
              <th class="px-6 py-4 font-semibold">الحالة</th>
              <th class="px-6 py-4 font-semibold">الدفعة</th>
              <th class="px-6 py-4 font-semibold text-right">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.bookings">
              <tr v-for="i in 10" :key="i" class="border-b border-white/5">
                <td v-for="j in 9" :key="j" class="px-6 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="filteredBookings.length > 0">
              <tr
                v-for="booking in filteredBookings"
                :key="booking.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors group"
              >
                <td class="px-6 py-4">
                  <span class="font-mono text-gold font-bold text-sm">{{ booking.booking_number }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col">
                    <span class="font-semibold text-sm">{{ booking.customer?.name }}</span>
                    <span class="text-xs text-text-muted">{{ booking.customer?.phone }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col">
                    <div class="flex items-center gap-1 font-semibold text-sm">
                      <MapPin class="w-3 h-3 text-gold" />
                      {{ booking.inventory?.route || '—' }}
                    </div>
                    <span class="text-xs text-text-muted mt-1">{{ booking.company?.name || '—' }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col">
                    <span class="text-sm font-semibold">{{ formatDate(booking.inventory?.travel_date) }}</span>
                    <span class="text-xs text-text-muted">{{ booking.inventory?.departure_time }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-1.5 text-sm">
                    <Users class="w-3 h-3 text-text-muted" />
                    <span class="font-semibold">{{ booking.quantity }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col">
                    <span class="font-mono font-bold text-sm">{{ booking.total_price?.toLocaleString() }}</span>
                    <span class="text-xs text-text-muted">جنيه</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div
                    :class="[
                      'inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                      statusStyles[booking.status] || 'bg-white/10 text-text-muted',
                    ]"
                  >
                    {{ statusLabels[booking.status] || booking.status }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-col">
                    <div class="flex items-center gap-2">
                      <span class="font-mono text-sm">{{ booking.paid_amount?.toLocaleString() }}</span>
                      <span class="text-text-muted">/</span>
                      <span class="font-mono text-sm">{{ booking.total_price?.toLocaleString() }}</span>
                    </div>
                    <div :class="['text-[10px] font-bold uppercase', paymentStatusStyles[booking.payment_status]]">
                      {{ paymentStatusLabels[booking.payment_status] }}
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <router-link
                      :to="{ name: 'bus.show', params: { id: booking.id } }"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-white transition-all"
                      title="عرض"
                    >
                      <Eye class="w-4 h-4" />
                    </router-link>
                    <button
                      v-if="booking.payment_status !== 'paid'"
                      @click="openPaymentModal(booking)"
                      class="p-2 hover:bg-success/10 rounded-lg text-text-muted hover:text-success transition-all"
                      title="تسديد دفعة"
                    >
                      <CreditCard class="w-4 h-4" />
                    </button>
                    <button
                      v-if="!booking.payments?.length && booking.status !== 'cancelled'"
                      type="button"
                      class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all"
                      title="إلغاء"
                      @click="confirmCancel(booking)"
                    >
                      <XCircle class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="9" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <Bus class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-text-main">لا توجد حجوزات</h3>
                    <p class="text-text-muted text-sm mt-1">ابدأ بإضافة حجز باص جديد</p>
                  </div>
                  <router-link
                    :to="{ name: 'bus.create' }"
                    class="mt-2 px-6 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all"
                  >
                    حجز جديد
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        class="flex flex-col gap-3 border-t border-white/10 bg-white/5 px-6 py-4 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between"
      >
        <div>
          <template v-if="store.pagination.total > 0">
            عرض {{ pageFrom }}–{{ pageTo }} من {{ store.pagination.total }} حجز
          </template>
          <template v-else>لا توجد نتائج</template>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="rounded-lg border border-white/10 px-3 py-1.5 font-semibold hover:border-gold/40 disabled:opacity-40"
            :disabled="store.pagination.current_page <= 1 || store.loading.bookings"
            @click="goPrevPage"
          >
            السابق
          </button>
          <span class="px-2 font-mono text-xs">
            {{ store.pagination.current_page }} / {{ store.pagination.last_page || 1 }}
          </span>
          <button
            type="button"
            class="rounded-lg border border-white/10 px-3 py-1.5 font-semibold hover:border-gold/40 disabled:opacity-40"
            :disabled="store.pagination.current_page >= store.pagination.last_page || store.loading.bookings"
            @click="goNextPage"
          >
            التالي
          </button>
          <select
            v-model.number="store.filters.per_page"
            class="rounded-lg border border-white/5 bg-input-bg px-3 py-2 text-sm focus:border-gold focus:outline-none"
            @change="onPerPageChange"
          >
            <option :value="10">10 / صفحة</option>
            <option :value="15">15 / صفحة</option>
            <option :value="25">25 / صفحة</option>
            <option :value="50">50 / صفحة</option>
          </select>
        </div>
      </div>
    </div>

    <!-- ✅ Payment Modal - account_id فقط -->
    <div v-if="showPaymentModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div class="bg-card-bg border border-white/10 rounded-2xl w-full max-w-md p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">تسديد دفعة</h3>

        <!-- بيانات الحجز -->
        <div class="space-y-3 mb-6">
          <div class="flex items-center justify-between p-3 bg-input-bg rounded-xl">
            <span class="text-sm text-text-muted">رقم الحجز</span>
            <span class="font-mono font-bold text-gold">{{ selectedBooking?.booking_number }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-input-bg rounded-xl">
            <span class="text-sm text-text-muted">إجمالي السعر</span>
            <span class="font-mono font-bold">{{ selectedBooking?.total_price?.toLocaleString() }} جنيه</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-input-bg rounded-xl">
            <span class="text-sm text-text-muted">المدفوع مسبقاً</span>
            <span class="font-mono font-bold text-success">{{ selectedBooking?.paid_amount?.toLocaleString() }} جنيه</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-input-bg rounded-xl">
            <span class="text-sm text-text-muted">المتبقي</span>
            <span class="font-mono font-bold text-error">{{ selectedBooking?.remaining_amount?.toLocaleString() }} جنيه</span>
          </div>
        </div>

        <!-- الفورم -->
        <form @submit.prevent="submitPayment" class="space-y-4">

          <!-- مبلغ الدفعة -->
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              مبلغ الدفعة * <span class="text-text-muted text-xs font-normal">(المتبقي: {{ selectedBooking?.remaining_amount?.toLocaleString() }} جنيه)</span>
            </label>
            <input
              v-model.number="paymentForm.amount"
              type="number"
              step="0.01"
              min="0.01"
              :max="selectedBooking?.remaining_amount || 999999"
              required
              placeholder="أدخل المبلغ..."
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>

          <!-- طريقة الدفع -->
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">طريقة الدفع *</label>
            <select
              v-model="paymentForm.payment_method"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            >
              <option value="cash">كاش</option>
              <option value="bank_transfer">تحويل بنكي</option>
              <option value="cash_wallet">محفظة كاش</option>
              <option value="postal_transfer">تحويل بريدي</option>
              <option value="office_safe">خزينة المكتب</option>
              <option value="office_drawer">درج المكتب</option>
            </select>
          </div>

          <!-- الحساب -->
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">طريقة الدفع *</label>
            <div class="flex flex-wrap gap-2 mb-4" dir="rtl">
              <button
                v-for="chip in settlementCategoryChips"
                :key="chip.id"
                type="button"
                @click="settlementCategoryUi = chip.id"
                :class="[
                  'flex items-center gap-2 px-3 py-2 rounded-xl border transition-all text-xs font-bold',
                  settlementCategoryUi === chip.id
                    ? 'bg-white/10 border-gold text-gold'
                    : 'bg-white/[0.02] border-white/10 text-text-muted hover:border-white/20'
                ]"
              >
                <component :is="chip.icon" :class="['h-3.5 w-3.5', chip.iconClass]" />
                {{ chip.label }}
              </button>
            </div>
            
            <label class="block text-sm font-semibold text-text-main mb-2">
              الحساب * <span class="text-text-muted text-xs font-normal">(سيُخصم منه المبلغ)</span>
            </label>
            <select
              v-model="paymentForm.account_id"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            >
              <option value="">اختر الحساب</option>
              <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }}
              </option>
            </select>
            <p v-if="filteredAccounts.length === 0 && !loadingAccounts" class="text-xs text-error mt-1">
              لا توجد حسابات متاحة في هذا التصنيف
            </p>
          </div>

          <!-- ملاحظات -->
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">ملاحظات</label>
            <input
              v-model="paymentForm.notes"
              type="text"
              placeholder="ملاحظات اختيارية..."
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="submit"
              :disabled="store.loading.payments || !paymentForm.account_id"
              class="flex-1 px-4 py-3 bg-success hover:bg-success/90 text-black rounded-xl font-bold transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ store.loading.payments ? 'جاري التسديد...' : 'تسديد' }}
            </button>
            <button
              type="button"
              @click="closePaymentModal"
              class="flex-1 px-4 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
            >
              إلغاء
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useBusStore } from '@/stores/busStore';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { fetchSettlementAccounts } from '@/composables/useTreasuryAccountGroups';
import {
  Plus, Search, Bus, CheckCircle, Clock, DollarSign,
  MapPin, Users, Eye, CreditCard, XCircle,
  Banknote, Wallet, Landmark,
} from 'lucide-vue-next';

const store = useBusStore();

// ─── Accounts ──────────────────────────────────────────────
const settlementCategoryUi = ref('cash');
const accounts = ref([]);
const loadingAccounts = ref(false);
const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

const filteredAccounts = computed(() => {
  if (settlementCategoryUi.value === 'cash') {
    return accounts.value.filter(a => a.type === 'cashbox' || a.type === 'treasury');
  }
  if (settlementCategoryUi.value === 'wallet') {
    return accounts.value.filter(a => a.type === 'wallet');
  }
  if (settlementCategoryUi.value === 'bank') {
    return accounts.value.filter(a => a.type === 'bank');
  }
  return accounts.value;
});

const fetchAccounts = async () => {
  loadingAccounts.value = true;
  try {
    accounts.value = await fetchSettlementAccounts(axios, { module: 'bus', includePost: false });
  } catch (error) {
    console.error('Failed to fetch accounts:', error);
    accounts.value = [];
  } finally {
    loadingAccounts.value = false;
  }
};

// ─── Payment Modal ─────────────────────────────────────────
const showPaymentModal = ref(false);
const selectedBooking = ref(null);
const paymentForm = ref({
  amount: '',
  payment_method: 'cash',
  account_id: '',
  notes: '',
});

const openPaymentModal = (booking) => {
  selectedBooking.value = booking;
  paymentForm.value = {
    amount: booking.remaining_amount || '',
    payment_method: 'cash',
    account_id: '',
    notes: '',
  };
  showPaymentModal.value = true;
};

const closePaymentModal = () => {
  showPaymentModal.value = false;
  selectedBooking.value = null;
  paymentForm.value = {
    amount: '',
    payment_method: 'cash',
    account_id: '',
    notes: '',
  };
};

const submitPayment = async () => {
  try {
    await store.payBooking(selectedBooking.value.id, {
      amount: paymentForm.value.amount,
      payment_method: paymentForm.value.payment_method,
      account_id: paymentForm.value.account_id,
      notes: paymentForm.value.notes || null,
    });
    store.addToast('تم تسديد الدفعة بنجاح');
    closePaymentModal();
    await Promise.all([store.fetchBookings(), store.fetchStats()]);
  } catch {
    store.addToast(store.errors?.message || 'فشل تسديد الدفعة', 'error');
  }
};

// ─── Status Labels & Styles ────────────────────────────────
const statusLabels = { pending: 'معلق', paid: 'مدفوع', cancelled: 'ملغي' };
const statusStyles = {
  pending: 'bg-warning/10 text-warning',
  paid: 'bg-success/10 text-success',
  cancelled: 'bg-error/10 text-error',
};
const paymentStatusLabels = { pending: 'معلق', partial: 'جزئي', paid: 'مدفوع', overdue: 'متأخر' };
const paymentStatusStyles = {
  pending: 'text-warning', partial: 'text-blue-500', paid: 'text-success', overdue: 'text-error',
};

// ─── Filters & pagination ───────────────────────────────────
const filteredBookings = computed(() => store.filteredBookings);

const pageFrom = computed(() => {
  if (!store.pagination.total) return 0;
  return (store.pagination.current_page - 1) * store.pagination.per_page + 1;
});

const pageTo = computed(() =>
  Math.min(store.pagination.current_page * store.pagination.per_page, store.pagination.total)
);

const runFetch = async () => {
  await store.fetchBookings();
};

const debouncedSearch = useDebounceFn(() => {
  store.filters.page = 1;
  runFetch();
}, 400);

const onSearchInput = () => {
  debouncedSearch();
};

const onFilterChange = async () => {
  store.filters.page = 1;
  await runFetch();
};

const onPerPageChange = async () => {
  store.filters.page = 1;
  await runFetch();
};

const goPrevPage = async () => {
  if (store.filters.page > 1) {
    store.filters.page -= 1;
    await runFetch();
  }
};

const goNextPage = async () => {
  if (store.filters.page < store.pagination.last_page) {
    store.filters.page += 1;
    await runFetch();
  }
};

const clearFilters = async () => {
  store.filters = {
    search: '',
    status: '',
    company_id: '',
    route_from: '',
    route_to: '',
    date_from: '',
    date_to: '',
    page: 1,
    per_page: 15,
  };
  await runFetch();
};

const retryLoad = async () => {
  store.errors = {};
  await Promise.all([runFetch(), store.fetchStats()]);
};

// ─── Helpers ───────────────────────────────────────────────
const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric', month: 'short', day: 'numeric',
  });
};

const confirmCancel = async (booking) => {
  if (booking.payments?.length) {
    store.addToast('لا يمكن الإلغاء بعد تسجيل دفعات.', 'error');
    return;
  }
  if (!confirm(`هل أنت متأكد من إلغاء حجز ${booking.booking_number}؟`)) return;
  try {
    await store.cancelBooking(booking.id);
    store.addToast('تم إلغاء الحجز بنجاح');
    await Promise.all([store.fetchBookings(), store.fetchStats()]);
  } catch {
    store.addToast(store.errors?.message || 'فشل إلغاء الحجز', 'error');
  }
};

// ─── Init ──────────────────────────────────────────────────
onMounted(async () => {
  // Reset filters to defaults on load to avoid showing stale cached filters
  store.filters.search = '';
  store.filters.status = '';
  store.filters.company_id = '';
  store.filters.route_from = '';
  store.filters.route_to = '';
  store.filters.date_from = '';
  store.filters.date_to = '';
  store.filters.page = 1;
  await Promise.all([store.fetchBookings(), store.fetchCompanies(), store.fetchStats(), fetchAccounts()]);
});
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.bg-input-bg { background-color: var(--input-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning); }
.text-blue-500 { color: #4F8EF7; }
.bg-success { background-color: var(--success); }
.bg-error { background-color: var(--error); }
.bg-warning { background-color: var(--warning); }
.font-mono { font-family: 'IBM Plex Sans Arabic', sans-serif; }
.font-display { font-family: 'IBM Plex Sans Arabic', sans-serif; }
</style>