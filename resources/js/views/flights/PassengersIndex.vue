<template>
  <div class="passengers-page space-y-6 pb-12">
    <!-- Header Page Section -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-bold tracking-tight text-white">دليل المسافرين</h1>
        <p class="text-slate-400 mt-1">عرض وإدارة جميع المسافرين على رحلات الطيران وتخصيص تنبيهات مواعيد السفر.</p>
      </div>
      <div>
        <button
          @click="isSettingsModalOpen = true"
          class="flex items-center gap-2 px-5 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-semibold rounded-xl shadow-lg shadow-primary-600/20 transition-all border border-primary-500/30"
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a9.001 9.001 0 01-11.962-9.412 8.903 8.903 0 000 13.046m11.962-3.634a9.001 9.001 0 00-11.962-9.412m11.962 9.412a8.902 8.902 0 001.272-4.757 8.902 8.902 0 00-1.272-4.756M13.857 17.082a9.008 9.008 0 01-1.273-4.757c0-1.748.498-3.38 1.357-4.757m0 9.514a8.997 8.997 0 012.272-4.757 8.997 8.997 0 01-2.272-4.757M8.684 10.748a3.075 3.075 0 11-1.034-4.836M12 12a3 3 0 100-6 3 3 0 000 6z" />
          </svg>
          تخصيص إعدادات التنبيهات
        </button>
      </div>
    </div>

    <!-- Search and Filters Box -->
    <div class="glass border border-slate-700/50 rounded-2xl p-6 shadow-xl space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- Search Input -->
        <div class="md:col-span-2 relative">
          <label for="search-input" class="sr-only">بحث عن مسافر</label>
          <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.637 10.637z" />
            </svg>
          </div>
          <input
            id="search-input"
            v-model="filters.search"
            type="text"
            placeholder="بحث باسم المسافر، رقم جواز السفر، الهوية الوطنية، أو PNR..."
            class="w-full pr-11 pl-4 py-3 bg-slate-900/60 border border-slate-700/60 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:border-primary-500 transition-colors"
            @input="debounceSearch"
          />
        </div>

        <!-- Trip Status Filter -->
        <div>
          <select
            v-model="filters.trip_status"
            class="w-full px-4 py-3 bg-slate-900/60 border border-slate-700/60 rounded-xl text-white focus:outline-none focus:border-primary-500 transition-colors"
            @change="fetchPassengers(1)"
          >
            <option value="all">كل الرحلات</option>
            <option value="upcoming">(موصى به) المسافرون قريباً / لم يسافر بعد</option>
            <option value="past">المسافرون السابقون</option>
          </select>
        </div>

        <!-- Reset Button -->
        <div class="flex items-end">
          <button
            @click="resetFilters"
            class="w-full py-3 px-4 bg-slate-800 hover:bg-slate-700 text-white font-medium rounded-xl border border-slate-700 transition-colors flex items-center justify-center gap-2"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            إعادة تعيين الفلاتر
          </button>
        </div>
      </div>

      <!-- Date Range Fields Row -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-slate-800/40">
        <div>
          <label class="block text-xs font-semibold text-slate-400 mb-1.5">تاريخ المغادرة من</label>
          <input
            v-model="filters.departure_date_from"
            type="date"
            class="w-full px-4 py-2.5 bg-slate-900/60 border border-slate-700/60 rounded-xl text-white focus:outline-none focus:border-primary-500 transition-colors"
            @change="fetchPassengers(1)"
          />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-400 mb-1.5">تاريخ المغادرة إلى</label>
          <input
            v-model="filters.departure_date_to"
            type="date"
            class="w-full px-4 py-2.5 bg-slate-900/60 border border-slate-700/60 rounded-xl text-white focus:outline-none focus:border-primary-500 transition-colors"
            @change="fetchPassengers(1)"
          />
        </div>
      </div>
    </div>

    <!-- Passengers Listing -->
    <div class="glass border border-slate-700/50 rounded-2xl shadow-xl overflow-hidden">
      <div v-if="loading" class="flex flex-col items-center justify-center py-20 space-y-4">
        <div class="w-12 h-12 border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
        <p class="text-slate-400 font-medium animate-pulse">جاري تحميل دليل المسافرين...</p>
      </div>

      <div v-else-if="passengers.length === 0" class="text-center py-20 space-y-4">
        <div class="w-16 h-16 bg-slate-800/50 rounded-3xl flex items-center justify-center mx-auto border border-slate-700/30">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-slate-400">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
          </svg>
        </div>
        <h3 class="text-xl font-bold text-white">لا يوجد مسافرون مطابِقون للبحث</h3>
        <p class="text-slate-400 max-w-sm mx-auto">تأكد من عدم وجود أخطاء إملائية في البحث، أو قم بإلغاء الفلاتر النشطة.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="border-b border-slate-800 bg-slate-900/40 text-slate-400 font-semibold text-xs uppercase">
              <th class="px-6 py-4">اسم المسافر</th>
              <th class="px-6 py-4">بيانات الهوية والاتصال</th>
              <th class="px-6 py-4">تفاصيل الحجز / الـ PNR</th>
              <th class="px-6 py-4">خط السفر والموعد</th>
              <th class="px-6 py-4 text-center">نوع التذكرة</th>
              <th class="px-6 py-4 text-center">أدوات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-for="pax in passengers" :key="pax.id" class="hover:bg-slate-900/20 transition-colors">
              <!-- Name & Gender/Type -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl bg-slate-800/80 border border-slate-700/50 flex items-center justify-center text-slate-300 font-semibold uppercase">
                    {{ pax.first_name[0] }}{{ pax.last_name[0] }}
                  </div>
                  <div>
                    <div class="font-bold text-white text-base">
                      {{ pax.first_name }} {{ pax.last_name }}
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                      <span class="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700/30">
                        {{ pax.relation_to_customer || 'مسافر رئيسي' }}
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Passports & IDs -->
              <td class="px-6 py-4">
                <div class="space-y-1 text-slate-300">
                  <div class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500 font-medium">جواز:</span>
                    <span class="font-mono text-slate-100">{{ pax.passport_number || 'غير متوفر' }}</span>
                  </div>
                  <div class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500 font-medium">القومي:</span>
                    <span class="font-mono text-slate-100">{{ pax.national_id || 'غير متوفر' }}</span>
                  </div>
                </div>
              </td>

              <!-- PNR & Booking Ref -->
              <td class="px-6 py-4">
                <div class="space-y-1">
                  <div class="flex items-center gap-2">
                    <span class="text-xs px-2 py-0.5 rounded font-mono font-bold bg-primary-950 text-primary-400 border border-primary-800/40">
                      {{ pax.booking?.pnr || 'بدون PNR' }}
                    </span>
                    <button
                      v-if="pax.booking?.pnr"
                      @click="copyToClipboard(pax.booking.pnr)"
                      class="text-slate-500 hover:text-slate-300 transition-colors p-1"
                      title="نسخ PNR"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H5.25m14.25 2.25V7.875c0-.621-.504-1.125-1.125-1.125H11.25a1.125 1.125 0 00-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125h12.75c0-.621.504-1.125 1.125-1.125V11.25a1.125 1.125 0 00-1.125-1.125z" />
                      </svg>
                    </button>
                  </div>
                  <div class="text-xs text-slate-500">
                    رقم الحجز: <span class="font-mono text-slate-400">{{ pax.booking?.booking_number }}</span>
                  </div>
                </div>
              </td>

              <!-- Flight Info -->
              <td class="px-6 py-4">
                <div class="space-y-1">
                  <div class="flex items-center gap-1.5 text-white font-medium">
                    <span>{{ pax.booking?.origin }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5 text-slate-500">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                    <span>{{ pax.booking?.destination }}</span>
                  </div>
                  <div class="text-xs flex items-center gap-2">
                    <span :class="isUpcoming(pax.booking?.departure_date) ? 'text-emerald-400 font-semibold' : 'text-slate-500'">
                      {{ formatDepartureDate(pax.booking?.departure_date) }}
                    </span>
                    <span class="text-slate-600">|</span>
                    <span class="text-slate-400 font-mono">{{ pax.booking?.departure_time }}</span>
                  </div>
                </div>
              </td>

              <!-- Passenger Type Badge -->
              <td class="px-6 py-4 text-center whitespace-nowrap">
                <span :class="getTypeBadgeClass(pax.type)" class="text-xs px-2.5 py-1 rounded-full font-bold uppercase">
                  {{ getTypeLabel(pax.type) }}
                </span>
              </td>

              <!-- Actions/Details Links -->
              <td class="px-6 py-4 text-center whitespace-nowrap">
                <router-link
                  v-if="pax.flight_booking_id"
                  :to="`/flights/${pax.flight_booking_id}`"
                  class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-lg border border-slate-700 text-xs transition-colors"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                  عرض الحجز
                </router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Block -->
      <div v-if="pagination.total > 0" class="flex flex-col sm:flex-row items-center justify-between gap-4 px-6 py-4 bg-slate-900/40 border-t border-slate-800/80">
        <div class="text-sm text-slate-400">
          عرض <span class="font-bold text-white">{{ passengers.length }}</span> من إجمالي <span class="font-bold text-white">{{ pagination.total }}</span> مسافر.
        </div>
        <div class="flex items-center gap-1.5">
          <!-- Prev Button -->
          <button
            @click="fetchPassengers(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="p-2 bg-slate-800 hover:bg-slate-700 disabled:opacity-45 disabled:hover:bg-slate-800 text-white border border-slate-700 rounded-lg transition-colors"
            aria-label="الصفحة السابقة"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
          </button>

          <!-- Pages -->
          <span class="text-sm text-slate-400 px-3">
            صفحة <span class="font-bold text-white">{{ pagination.current_page }}</span> من <span class="font-bold text-white">{{ pagination.last_page }}</span>
          </span>

          <!-- Next Button -->
          <button
            @click="fetchPassengers(pagination.current_page + 1)"
            :disabled="!pagination.has_more"
            class="p-2 bg-slate-800 hover:bg-slate-700 disabled:opacity-45 disabled:hover:bg-slate-800 text-white border border-slate-700 rounded-lg transition-colors"
            aria-label="الصفحة التالية"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Alert Settings Modal -->
    <transition name="t-modal">
      <div v-if="isSettingsModalOpen" class="fixed inset-0 z-[150] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @click="isSettingsModalOpen = false"></div>

        <!-- Modal Box -->
        <div class="relative w-full max-w-lg bg-slate-900 border border-slate-700/60 rounded-3xl overflow-hidden shadow-2xl p-6 space-y-6">
          <div class="flex items-center justify-between pb-3 border-b border-slate-800">
            <h2 class="text-2xl font-bold text-white">إعدادات الإشعارات وتنبيهات السفر</h2>
            <button @click="isSettingsModalOpen = false" class="text-slate-400 hover:text-slate-200 transition-colors p-1" aria-label="إغلاق">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="space-y-4">
            <div class="p-4 bg-primary-950/30 border border-primary-900/40 rounded-2xl text-primary-200 text-sm leading-relaxed text-right">
              <strong>تنبيهات السفر التلقائية:</strong> ستقوم لوحة التحكم بإرسال إشعارات داخل التطبيق (جرس التنبيهات في شريط العنوان) لمواعيد مغادرة المسافرين في التاريخ والساعة المحددة أدناه.
            </div>

            <!-- Days Before -->
            <div class="space-y-1.5">
              <label class="block text-sm font-semibold text-slate-300">موعد إرسال التنبيه</label>
              <select
                v-model="alertSettings.travel_alert_days_before"
                class="w-full px-4 py-2.5 bg-slate-850 border border-slate-700 rounded-xl text-white focus:outline-none focus:border-primary-500 transition-colors"
              >
                <option :value="0">في نفس يوم السفر (Same Day)</option>
                <option :value="1">قبل يوم من السفر (1 Day Before)</option>
                <option :value="2">قبل يومين من السفر (2 Days Before)</option>
                <option :value="3">قبل 3 أيام من السفر (3 Days Before)</option>
                <option :value="7">قبل أسبوع من السفر (1 Week Before)</option>
              </select>
            </div>

            <!-- Time of Day -->
            <div class="space-y-1.5">
              <label class="block text-sm font-semibold text-slate-300">توقيت إرسال التنبيه في اليوم</label>
              <input
                v-model="alertSettings.travel_alert_time"
                type="time"
                class="w-full px-4 py-2.5 bg-slate-850 border border-slate-700 rounded-xl text-white focus:outline-none focus:border-primary-500 transition-colors font-mono"
              />
              <span class="text-xs text-slate-500 block">مثال: 09:00 صباحاً لتلقي الإشعارات في بداية يوم العمل.</span>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
            <button
              @click="isSettingsModalOpen = false"
              class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-all border border-slate-700/60"
            >
              إلغاء
            </button>
            <button
              @click="saveAlertSettings"
              :disabled="savingSettings"
              class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-bold rounded-xl transition-all shadow-lg shadow-primary-600/10 flex items-center gap-2"
            >
              <span v-if="savingSettings" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
              حفظ الإعدادات
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';

const loading = ref(false);
const savingSettings = ref(false);
const isSettingsModalOpen = ref(false);
const passengers = ref([]);

const filters = reactive({
  search: '',
  trip_status: 'upcoming', // Recommended default to show upcoming travels first
  departure_date_from: '',
  departure_date_to: ''
});

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
  has_more: false
});

const alertSettings = reactive({
  travel_alert_days_before: 1,
  travel_alert_time: '09:00'
});

let searchTimeout = null;

function debounceSearch() {
  if (searchTimeout) clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchPassengers(1);
  }, 405);
}

async function fetchPassengers(page = 1) {
  loading.value = true;
  try {
    const response = await axios.get('/api/v1/flight/passengers', {
      params: {
        page,
        search: filters.search,
        trip_status: filters.trip_status,
        departure_date_from: filters.departure_date_from,
        departure_date_to: filters.departure_date_to
      }
    });

    passengers.value = response.data.data.items || [];
    const pag = response.data.data.pagination;
    if (pag) {
      pagination.current_page = pag.current_page;
      pagination.last_page = pag.last_page;
      pagination.total = pag.total;
      pagination.has_more = pag.has_more;
    }
  } catch (e) {
    console.error('Failed to load passengers', e);
    window.addToast?.('خطأ أثناء تحميل دليل المسافرين', 'error');
  } finally {
    loading.value = false;
  }
}

async function fetchAlertSettings() {
  try {
    const response = await axios.get('/api/v1/flight/passengers/alert-settings');
    if (response.data.success) {
      const data = response.data.data;
      alertSettings.travel_alert_days_before = data.travel_alert_days_before;
      
      // Trim seconds from time string (09:00:00 -> 09:00)
      if (data.travel_alert_time) {
        alertSettings.travel_alert_time = data.travel_alert_time.substring(0, 5);
      }
    }
  } catch (e) {
    console.error('Failed to load alert settings', e);
  }
}

async function saveAlertSettings() {
  savingSettings.value = true;
  try {
    const response = await axios.put('/api/v1/flight/passengers/alert-settings', {
      travel_alert_days_before: alertSettings.travel_alert_days_before,
      travel_alert_time: alertSettings.travel_alert_time
    });

    if (response.data.success) {
      window.addToast?.('تم حفظ إعدادات تنبيهات السفر بنجاح', 'success');
      isSettingsModalOpen.value = false;
      
      // Trigger a window custom event to update notifications state on the layout instantly
      window.dispatchEvent(new CustomEvent('show-toast', {
        detail: { message: 'تم تحديث التنبيهات بنجاح', type: 'success' }
      }));
    }
  } catch (e) {
    console.error('Failed to save alert settings', e);
    window.addToast?.('فشل في حفظ إعدادات التنبيهات', 'error');
  } finally {
    savingSettings.value = false;
  }
}

function resetFilters() {
  filters.search = '';
  filters.trip_status = 'all';
  filters.departure_date_from = '';
  filters.departure_date_to = '';
  fetchPassengers(1);
}

function formatDepartureDate(dateStr) {
  if (!dateStr) return '';
  try {
    const date = new Date(dateStr);
    return date.toLocaleDateString('ar-EG', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  } catch (e) {
    return dateStr;
  }
}

function isUpcoming(dateStr) {
  if (!dateStr) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const depDate = new Date(dateStr);
  return depDate >= today;
}

function getTypeLabel(type) {
  const labels = {
    adult: 'بالغ',
    child: 'طفل',
    infant: 'رضيع'
  };
  return labels[type] || type;
}

function getTypeBadgeClass(type) {
  const classes = {
    adult: 'bg-indigo-950/40 text-indigo-400 border border-indigo-900/30',
    child: 'bg-emerald-950/40 text-emerald-400 border border-emerald-900/30',
    infant: 'bg-amber-950/40 text-amber-400 border border-amber-900/30'
  };
  return classes[type] || 'bg-slate-800 text-slate-300';
}

function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(() => {
    window.addToast?.('تم نسخ الرمز PNR بنجاح', 'success');
  }).catch(err => {
    console.error('Could not copy PNR', err);
  });
}

onMounted(() => {
  fetchPassengers();
  fetchAlertSettings();
});
</script>

<style scoped>
.glass {
  background: rgba(14, 21, 37, 0.6);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.bg-slate-850 {
  background-color: #111a2e;
}

/* Modal animation */
.t-modal-enter-active,
.t-modal-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.t-modal-enter-from,
.t-modal-leave-to {
  opacity: 0;
}

.t-modal-enter-from .relative,
.t-modal-leave-to .relative {
  transform: scale(0.95);
}
</style>
