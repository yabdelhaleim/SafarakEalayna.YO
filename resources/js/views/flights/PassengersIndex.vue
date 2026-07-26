<template>
  <div class="passengers-page animate-in fade-in duration-700 pb-12">
    <header class="flight-hero">
      <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
          <div class="mb-3 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-2 rounded-full border border-sky-400/20 bg-sky-500/10 px-3 py-1 text-[11px] font-bold text-sky-300">
              <Users class="h-3.5 w-3.5" />
              عمليات المسافرين
            </span>
            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-bold text-text-muted">
              <span class="h-1.5 w-1.5 rounded-full bg-success" :class="{ 'animate-pulse': loading }"></span>
              {{ loading ? 'جاري التحديث' : `${pagination.total} مسافر` }}
            </span>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-text-main sm:text-4xl">دليل المسافرين</h1>
          <p class="mt-2 max-w-2xl text-sm leading-7 text-text-muted">
            ابحث في بيانات المسافرين، راجع خطوط السير ومواعيد المغادرة، واضبط تنبيهات السفر من شاشة تشغيل واحدة.
          </p>
        </div>

        <button type="button" class="btn-airline shrink-0 shadow-xl" @click="openSettingsModal">
          <BellRing class="h-5 w-5" />
          إعدادات تنبيهات السفر
        </button>
      </div>
    </header>

    <main class="mx-auto max-w-7xl space-y-6">
      <section class="flight-panel !p-4 sm:!p-5" aria-labelledby="passenger-filters-title">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 id="passenger-filters-title" class="flex items-center gap-2 text-sm font-extrabold text-text-main">
              <SlidersHorizontal class="h-4 w-4 text-gold" />
              البحث والتصفية
            </h2>
            <p class="mt-1 text-xs text-text-muted">استخدم اسماً أو وثيقة أو رقم PNR للوصول السريع.</p>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="activeFiltersCount" class="rounded-full border border-gold/25 bg-gold/10 px-3 py-1 text-[11px] font-bold text-gold">
              {{ activeFiltersCount }} فلتر نشط
            </span>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold text-text-muted transition hover:bg-white/5 hover:text-gold disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="!activeFiltersCount"
              @click="resetFilters"
            >
              <RotateCcw class="h-3.5 w-3.5" />
              إعادة التعيين
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-12">
          <label class="relative md:col-span-2 xl:col-span-5">
            <span class="sr-only">بحث عن مسافر</span>
            <Search class="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" />
            <Loader2 v-if="loading && filters.search" class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-sky-400" />
            <input
              v-model="filters.search"
              type="search"
              class="flight-input !py-3 pr-11 text-sm"
              placeholder="الاسم، الجواز، الرقم القومي، أو PNR..."
              @input="debounceSearch"
            />
          </label>

          <label class="xl:col-span-3">
            <span class="sr-only">حالة الرحلة</span>
            <select v-model="filters.trip_status" class="flight-select !py-3 text-sm" @change="fetchPassengers(1)">
              <option value="upcoming">المسافرون القادمون</option>
              <option value="past">المسافرون السابقون</option>
              <option value="all">كل الرحلات</option>
            </select>
          </label>

          <label class="xl:col-span-2">
            <span class="mb-1 block text-[10px] font-bold text-text-muted xl:hidden">المغادرة من</span>
            <input v-model="filters.departure_date_from" type="date" class="flight-input !py-3 text-sm" aria-label="تاريخ المغادرة من" @change="fetchPassengers(1)" />
          </label>

          <label class="xl:col-span-2">
            <span class="mb-1 block text-[10px] font-bold text-text-muted xl:hidden">المغادرة إلى</span>
            <input v-model="filters.departure_date_to" type="date" class="flight-input !py-3 text-sm" aria-label="تاريخ المغادرة إلى" @change="fetchPassengers(1)" />
          </label>
        </div>
      </section>

      <section class="flight-panel !overflow-hidden !p-0" aria-label="قائمة المسافرين">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
          <div>
            <h2 class="text-base font-extrabold text-text-main">سجل المسافرين</h2>
            <p class="mt-0.5 text-xs text-text-muted">كل صف يمثل مسافراً على خط سير محدد.</p>
          </div>
          <span v-if="!loading && !errorMessage" class="font-mono text-xs font-bold text-sky-300">
            {{ passengers.length }} نتيجة في الصفحة
          </span>
        </div>

        <div v-if="loading" aria-live="polite">
          <div class="hidden overflow-hidden md:block">
            <div v-for="row in 7" :key="row" class="grid grid-cols-6 gap-6 border-b border-white/5 px-6 py-5">
              <div v-for="cell in 6" :key="cell" class="h-4 animate-shimmer rounded-lg" :class="cell === 1 ? 'w-full' : 'w-3/4'"></div>
            </div>
          </div>
          <div class="divide-y divide-white/5 md:hidden">
            <div v-for="row in 5" :key="row" class="space-y-3 p-5">
              <div class="h-5 w-1/2 animate-shimmer rounded-lg"></div>
              <div class="h-4 w-3/4 animate-shimmer rounded-lg"></div>
              <div class="h-16 w-full animate-shimmer rounded-xl"></div>
            </div>
          </div>
        </div>

        <div v-else-if="errorMessage" class="flex flex-col items-center px-6 py-16 text-center">
          <div class="flex h-16 w-16 items-center justify-center rounded-2xl border border-error/20 bg-error/10 text-error">
            <WifiOff class="h-8 w-8" />
          </div>
          <h3 class="mt-5 text-xl font-black text-text-main">تعذر تحميل دليل المسافرين</h3>
          <p class="mt-2 max-w-md text-sm leading-6 text-text-muted">{{ errorMessage }}</p>
          <button type="button" class="btn-airline-ghost mt-6" @click="fetchPassengers(pagination.current_page)">
            <RefreshCw class="h-4 w-4" />
            إعادة المحاولة
          </button>
        </div>

        <div v-else-if="passengers.length === 0" class="flex flex-col items-center px-6 py-16 text-center">
          <div class="relative flex h-20 w-20 items-center justify-center rounded-3xl border border-white/10 bg-white/5">
            <UserSearch class="h-9 w-9 text-sky-300" />
            <span class="absolute -left-2 -top-2 h-5 w-5 rounded-full border-4 border-card-bg bg-gold"></span>
          </div>
          <h3 class="mt-5 text-xl font-black text-text-main">لا توجد نتائج مطابقة</h3>
          <p class="mt-2 max-w-md text-sm leading-6 text-text-muted">
            {{ emptyStateHint }}
          </p>
          <button v-if="activeFiltersCount" type="button" class="mt-5 text-sm font-bold text-gold hover:underline" @click="resetFilters">
            إعادة التعيين إلى الوضع الافتراضي
          </button>
        </div>

        <template v-else>
          <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[1100px] border-collapse text-right">
              <thead>
                <tr class="border-b border-white/10 bg-white/[0.035] text-[10px] font-bold uppercase tracking-wider text-text-muted">
                  <th class="px-5 py-4">المسافر</th>
                  <th class="px-5 py-4">الحجز والعميل</th>
                  <th class="px-5 py-4">المسار</th>
                  <th class="px-5 py-4">موعد السفر</th>
                  <th class="px-5 py-4">التبعية</th>
                  <th class="px-5 py-4">التشغيل</th>
                  <th class="px-5 py-4 text-center">الإجراء</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr v-for="(pax, index) in passengers" :key="passengerKey(pax, index)" class="group transition-colors hover:bg-white/[0.035]">
                  <td class="px-5 py-4">
                    <div class="flex min-w-[210px] items-center gap-3">
                      <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-sky-400/15 bg-sky-500/10 text-xs font-black uppercase text-sky-300">
                        {{ initials(pax) }}
                      </div>
                      <div class="min-w-0">
                        <button type="button" class="group/name flex max-w-[190px] items-center gap-1.5 text-right" title="نسخ اسم المسافر" @click="copyToClipboard(fullName(pax), 'تم نسخ اسم المسافر')">
                          <span class="truncate text-sm font-extrabold text-text-main">{{ fullName(pax) }}</span>
                          <Copy class="h-3 w-3 shrink-0 text-text-muted opacity-0 transition group-hover/name:opacity-100" />
                        </button>
                        <div class="mt-1 flex flex-wrap gap-x-2 text-[10px] text-text-muted">
                          <span>جواز: <b class="font-mono text-text-main/75">{{ pax.passport_number || '—' }}</b></span>
                          <span>قومي: <b class="font-mono text-text-main/75">{{ pax.national_id || '—' }}</b></span>
                        </div>
                      </div>
                    </div>
                  </td>

                  <td class="px-5 py-4">
                    <div class="min-w-[150px]">
                      <button v-if="pax.booking?.pnr" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gold/20 bg-gold/10 px-2 py-1 font-mono text-xs font-black text-gold transition hover:bg-gold/15" @click="copyToClipboard(pax.booking.pnr, 'تم نسخ PNR الحجز')">
                        {{ pax.booking.pnr }}
                        <Copy class="h-3 w-3" />
                      </button>
                      <span v-else class="text-xs text-text-muted">بدون PNR</span>
                      <p class="mt-1.5 max-w-[180px] truncate text-xs font-bold text-text-main">{{ pax.customer?.name || 'عميل غير محدد' }}</p>
                      <p class="mt-0.5 font-mono text-[10px] text-text-muted">{{ pax.booking?.booking_number || '—' }}</p>
                    </div>
                  </td>

                  <td class="px-5 py-4">
                    <div class="min-w-[150px]">
                      <div class="flex items-center gap-2 font-mono text-xs font-black text-text-main">
                        <span>{{ pax.booking?.from_airport || '—' }}</span>
                        <ArrowLeft class="h-3.5 w-3.5 text-sky-400" />
                        <span>{{ pax.booking?.to_airport || '—' }}</span>
                      </div>
                      <p class="mt-1.5 max-w-[170px] truncate text-[11px] text-text-muted">{{ pax.booking?.airline_name || 'شركة الطيران غير محددة' }}</p>
                    </div>
                  </td>

                  <td class="px-5 py-4">
                    <div class="min-w-[165px]">
                      <span class="inline-flex items-center gap-1.5 text-xs font-bold" :class="isUpcoming(pax.departure_date) ? 'text-success' : 'text-text-muted'">
                        <CalendarDays class="h-3.5 w-3.5" />
                        {{ formatDepartureDate(pax.departure_date) }}
                      </span>
                      <p class="mt-1.5 flex items-center gap-1.5 font-mono text-[11px] text-text-muted">
                        <Clock3 class="h-3 w-3" />
                        {{ formatTime(pax.departure_time) }}
                      </p>
                    </div>
                  </td>

                  <td class="px-5 py-4">
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-bold" :class="affiliationClass(pax)">{{ pax.affiliation || 'عميل فردي' }}</span>
                    <p v-if="pax.group_name && pax.group_name !== '—'" class="mt-1.5 max-w-[140px] truncate text-[10px] font-bold text-gold">{{ pax.group_name }}</p>
                  </td>

                  <td class="px-5 py-4">
                    <div class="min-w-[120px] text-xs">
                      <p class="font-bold text-text-main">{{ pax.employee_name || '—' }}</p>
                      <p class="mt-1 text-[10px] text-text-muted">حجز: {{ formatBookingDate(pax.booking_date) }}</p>
                      <p v-if="pax.booking_notes" class="mt-1 max-w-[145px] truncate text-[10px] text-warning" :title="pax.booking_notes">{{ pax.booking_notes }}</p>
                    </div>
                  </td>

                  <td class="px-5 py-4 text-center">
                    <router-link v-if="pax.booking?.id" :to="{ name: 'flights.show', params: { id: pax.booking.id } }" class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 p-2.5 text-text-muted transition hover:border-sky-400/30 hover:bg-sky-500/10 hover:text-sky-300" title="عرض الحجز" aria-label="عرض الحجز">
                      <Eye class="h-4 w-4" />
                    </router-link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="divide-y divide-white/5 md:hidden">
            <article v-for="(pax, index) in passengers" :key="passengerKey(pax, index)" class="space-y-4 p-5 transition hover:bg-white/[0.025]">
              <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                  <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-sky-400/15 bg-sky-500/10 text-xs font-black uppercase text-sky-300">{{ initials(pax) }}</div>
                  <div class="min-w-0">
                    <button type="button" class="flex max-w-full items-center gap-1.5 text-right" @click="copyToClipboard(fullName(pax), 'تم نسخ اسم المسافر')">
                      <span class="truncate text-sm font-black text-text-main">{{ fullName(pax) }}</span>
                      <Copy class="h-3 w-3 shrink-0 text-text-muted" />
                    </button>
                    <p class="mt-1 truncate text-[10px] text-text-muted">جواز: {{ pax.passport_number || '—' }} · قومي: {{ pax.national_id || '—' }}</p>
                  </div>
                </div>
                <span class="shrink-0 rounded-full border px-2 py-1 text-[9px] font-bold" :class="affiliationClass(pax)">{{ pax.affiliation || 'فردي' }}</span>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <button v-if="pax.booking?.pnr" type="button" class="rounded-xl border border-gold/20 bg-gold/10 p-3 text-right" @click="copyToClipboard(pax.booking.pnr, 'تم نسخ PNR الحجز')">
                  <span class="block text-[9px] font-bold text-text-muted">PNR</span>
                  <span class="mt-1 flex items-center gap-1 font-mono text-xs font-black text-gold"><Copy class="h-3 w-3" />{{ pax.booking.pnr }}</span>
                </button>
                <div class="rounded-xl border border-white/10 bg-white/[0.035] p-3">
                  <span class="block text-[9px] font-bold text-text-muted">موعد السفر</span>
                  <span class="mt-1 block text-xs font-bold" :class="isUpcoming(pax.departure_date) ? 'text-success' : 'text-text-main'">{{ formatShortDate(pax.departure_date) }}</span>
                  <span class="mt-0.5 block font-mono text-[10px] text-text-muted">{{ formatTime(pax.departure_time) }}</span>
                </div>
              </div>

              <div class="rounded-xl border border-white/5 bg-black/10 p-3">
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2 font-mono text-xs font-black text-text-main">
                    <MapPin class="h-3.5 w-3.5 text-sky-400" />
                    {{ pax.booking?.from_airport || '—' }}
                    <ArrowLeft class="h-3 w-3 text-text-muted" />
                    {{ pax.booking?.to_airport || '—' }}
                  </div>
                  <span class="shrink-0 rounded-full bg-white/5 px-2 py-1 text-[9px] font-bold text-text-muted">{{ pax.booking?.passenger_count || 1 }} مسافر</span>
                </div>
                <p class="mt-2 text-[10px] text-text-muted">{{ pax.booking?.airline_name || 'شركة الطيران غير محددة' }}</p>
              </div>

              <div class="flex items-center justify-between gap-3 border-t border-white/5 pt-3">
                <div class="min-w-0 text-[10px] text-text-muted">
                  <p class="truncate font-bold text-text-main">{{ pax.customer?.name || 'عميل غير محدد' }}</p>
                  <p class="mt-0.5 truncate">الموظف: {{ pax.employee_name || '—' }}</p>
                </div>
                <router-link v-if="pax.booking?.id" :to="{ name: 'flights.show', params: { id: pax.booking.id } }" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-sky-500/10 px-3 py-2 text-xs font-bold text-sky-300 transition hover:bg-sky-500/20">
                  <Eye class="h-3.5 w-3.5" />
                  عرض الحجز
                </router-link>
              </div>
            </article>
          </div>
        </template>

        <div v-if="!loading && !errorMessage && pagination.total > 0" class="flex flex-col gap-4 border-t border-white/10 bg-white/[0.025] px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <p class="text-center text-xs text-text-muted sm:text-right">
            الصفحة <b class="text-text-main">{{ pagination.current_page }}</b> من <b class="text-text-main">{{ pagination.last_page }}</b>
            <span class="mx-1 text-white/20">·</span>
            إجمالي <b class="text-text-main">{{ pagination.total }}</b> مسافر
          </p>
          <div class="flex items-center justify-center gap-2">
            <button type="button" class="pagination-button" :disabled="pagination.current_page === 1" aria-label="الصفحة السابقة" @click="fetchPassengers(pagination.current_page - 1)">
              <ChevronRight class="h-4 w-4" />
            </button>
            <span class="flex h-9 min-w-9 items-center justify-center rounded-lg bg-gold px-3 text-xs font-black text-black">{{ pagination.current_page }}</span>
            <button type="button" class="pagination-button" :disabled="!pagination.has_more" aria-label="الصفحة التالية" @click="fetchPassengers(pagination.current_page + 1)">
              <ChevronLeft class="h-4 w-4" />
            </button>
          </div>
        </div>
      </section>
    </main>

    <transition name="t-modal">
      <div v-if="isSettingsModalOpen" class="fixed inset-0 z-[150] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="alert-settings-title">
        <button type="button" class="absolute inset-0 cursor-default bg-black/75 backdrop-blur-sm" aria-label="إغلاق" @click="closeSettingsModal"></button>
        <div class="modal-card relative w-full max-w-lg overflow-hidden rounded-3xl border border-white/10 bg-card-bg shadow-2xl">
          <div class="relative border-b border-white/10 bg-gradient-to-l from-sky-950/50 to-card-bg p-6">
            <div class="flex items-start justify-between gap-4">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-600 text-white shadow-lg shadow-sky-500/20">
                  <BellRing class="h-6 w-6" />
                </div>
                <div>
                  <h2 id="alert-settings-title" class="text-xl font-black text-text-main">تنبيهات السفر</h2>
                  <p class="mt-1 text-xs text-text-muted">حدّد متى تريد استلام تذكير المغادرة.</p>
                </div>
              </div>
              <button type="button" class="rounded-xl p-2 text-text-muted transition hover:bg-white/10 hover:text-text-main" aria-label="إغلاق نافذة الإعدادات" @click="closeSettingsModal">
                <X class="h-5 w-5" />
              </button>
            </div>
          </div>

          <div class="space-y-5 p-6">
            <div class="flex gap-3 rounded-2xl border border-sky-400/15 bg-sky-500/[0.07] p-4 text-sm leading-6 text-sky-100">
              <Info class="mt-0.5 h-5 w-5 shrink-0 text-sky-400" />
              <p>ستظهر التنبيهات داخل جرس الإشعارات في لوحة التحكم للمسافرين الذين اقترب موعد مغادرتهم.</p>
            </div>

            <label class="block space-y-2">
              <span class="text-sm font-bold text-text-main">موعد إرسال التنبيه</span>
              <select v-model="alertSettings.travel_alert_days_before" class="flight-select">
                <option :value="0">في نفس يوم السفر</option>
                <option :value="1">قبل يوم من السفر</option>
                <option :value="2">قبل يومين من السفر</option>
                <option :value="3">قبل 3 أيام من السفر</option>
                <option :value="7">قبل أسبوع من السفر</option>
              </select>
            </label>

            <label class="block space-y-2">
              <span class="text-sm font-bold text-text-main">وقت إرسال التنبيه</span>
              <div class="relative">
                <Clock3 class="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" />
                <input v-model="alertSettings.travel_alert_time" type="time" class="flight-input pr-11 font-mono" />
              </div>
              <span class="block text-[11px] leading-5 text-text-muted">مثال: 09:00 لتلقي الإشعارات في بداية يوم العمل.</span>
            </label>
          </div>

          <div class="flex flex-col-reverse gap-3 border-t border-white/10 bg-white/[0.025] p-5 sm:flex-row sm:justify-end">
            <button type="button" class="btn-airline-ghost" :disabled="savingSettings" @click="closeSettingsModal">إلغاء</button>
            <button type="button" class="btn-airline min-w-36" :disabled="savingSettings" @click="saveAlertSettings">
              <Loader2 v-if="savingSettings" class="h-4 w-4 animate-spin" />
              <Save v-else class="h-4 w-4" />
              {{ savingSettings ? 'جاري الحفظ...' : 'حفظ الإعدادات' }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import {
  ArrowLeft,
  BellRing,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Copy,
  Eye,
  Info,
  Loader2,
  MapPin,
  RefreshCw,
  RotateCcw,
  Save,
  Search,
  SlidersHorizontal,
  Users,
  UserSearch,
  WifiOff,
  X,
} from 'lucide-vue-next';

const loading = ref(false);
const savingSettings = ref(false);
const isSettingsModalOpen = ref(false);
const passengers = ref([]);
const errorMessage = ref('');

const filters = reactive({
  search: '',
  trip_status: 'upcoming',
  departure_date_from: '',
  departure_date_to: '',
});

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
  has_more: false,
});

const alertSettings = reactive({
  travel_alert_days_before: 1,
  travel_alert_time: '09:00',
});

const activeFiltersCount = computed(() => [
  filters.search.trim(),
  filters.trip_status !== 'upcoming',
  filters.departure_date_from,
  filters.departure_date_to,
].filter(Boolean).length);

const emptyStateHint = computed(() => {
  if (filters.search.trim()) {
    return 'لا توجد نتائج لعبارة البحث الحالية. جرّب جزء من الاسم، أو رقم PNR، أو جواز السفر. لو الراكب له رحلة سابقة، بدّل "القادمون" إلى "كل الرحلات".';
  }
  if (filters.trip_status === 'past') {
    return 'لا توجد رحلات سابقة في النطاق المختار. وسّع الإطار الزمني أو بدّل إلى "كل الرحلات".';
  }
  if (filters.trip_status === 'upcoming') {
    return 'لا توجد رحلات قادمة في النطاق المختار. وسّع تاريخ المغادرة أو بدّل إلى "كل الرحلات".';
  }
  return 'لا توجد سجلات لهذا الفلتر. أزل نطاق التاريخ أو غيّر الحالة لرؤية كل المسافرين.';
});

let searchTimeout = null;

function debounceSearch() {
  window.clearTimeout(searchTimeout);
  searchTimeout = window.setTimeout(() => fetchPassengers(1), 405);
}

async function fetchPassengers(page = 1) {
  loading.value = true;
  errorMessage.value = '';

  try {
    const response = await axios.get('/api/v1/flight/passengers', {
      params: {
        page,
        search: filters.search,
        trip_status: filters.trip_status,
        departure_date_from: filters.departure_date_from,
        departure_date_to: filters.departure_date_to,
      },
    });

    // Respect the API's success flag instead of blindly reading data.data.items.
    // Otherwise validation errors (e.g. 422) and 500s return success:false with
    // data:null, and the page silently showed "0 results" instead of the issue.
    if (response.data?.success === false) {
      errorMessage.value = response.data?.message || 'تعذر تحميل دليل المسافرين.';
      passengers.value = [];
      pagination.current_page = 1;
      pagination.last_page = 1;
      pagination.total = 0;
      pagination.has_more = false;
      return;
    }

    const payload = response.data?.data ?? {};
    passengers.value = Array.isArray(payload.items) ? payload.items : [];
    const pag = payload.pagination;

    if (pag) {
      pagination.current_page = pag.current_page;
      pagination.last_page = pag.last_page;
      pagination.total = pag.total;
      pagination.has_more = pag.has_more;
    }
  } catch (error) {
    console.error('Failed to load passengers', error);
    const apiMessage = error?.response?.data?.message;
    errorMessage.value = apiMessage || 'حدث خطأ أثناء الاتصال بالخادم. تحقق من الاتصال ثم أعد المحاولة.';
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
      if (data.travel_alert_time) {
        alertSettings.travel_alert_time = data.travel_alert_time.substring(0, 5);
      }
    }
  } catch (error) {
    console.error('Failed to load alert settings', error);
  }
}

async function saveAlertSettings() {
  savingSettings.value = true;
  try {
    const response = await axios.put('/api/v1/flight/passengers/alert-settings', {
      travel_alert_days_before: alertSettings.travel_alert_days_before,
      travel_alert_time: alertSettings.travel_alert_time,
    });

    if (response.data.success) {
      window.addToast?.('تم حفظ إعدادات تنبيهات السفر بنجاح', 'success');
      closeSettingsModal();
    }
  } catch (error) {
    console.error('Failed to save alert settings', error);
    window.addToast?.('فشل في حفظ إعدادات التنبيهات', 'error');
  } finally {
    savingSettings.value = false;
  }
}

function resetFilters() {
  window.clearTimeout(searchTimeout);
  filters.search = '';
  filters.trip_status = 'upcoming';
  filters.departure_date_from = '';
  filters.departure_date_to = '';
  fetchPassengers(1);
}

function openSettingsModal() {
  isSettingsModalOpen.value = true;
}

function closeSettingsModal() {
  if (!savingSettings.value) {
    isSettingsModalOpen.value = false;
  }
}

function handleKeydown(event) {
  if (event.key === 'Escape' && isSettingsModalOpen.value) {
    closeSettingsModal();
  }
}

function fullName(pax) {
  return [pax.first_name, pax.last_name].filter(Boolean).join(' ') || 'مسافر بدون اسم';
}

function initials(pax) {
  const first = pax.first_name?.trim()?.[0] || '';
  const last = pax.last_name?.trim()?.[0] || '';
  return `${first}${last}` || 'P';
}

function passengerKey(pax, index) {
  return `${pax.passenger_id}-${pax.departure_date || 'date'}-${pax.leg_number || index}`;
}

function affiliationClass(pax) {
  return pax.affiliation === 'عميل مجموعات'
    ? 'border-warning/25 bg-warning/10 text-warning'
    : 'border-white/10 bg-white/5 text-text-muted';
}

function formatDepartureDate(dateStr) {
  if (!dateStr) return 'غير محدد';
  try {
    return new Date(`${dateStr}T00:00:00`).toLocaleDateString('ar-EG', {
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatShortDate(dateStr) {
  if (!dateStr) return 'غير محدد';
  try {
    return new Date(`${dateStr}T00:00:00`).toLocaleDateString('ar-EG', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatBookingDate(dateStr) {
  if (!dateStr) return '—';
  try {
    return new Date(dateStr).toLocaleDateString('ar-EG', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatTime(time) {
  return time ? String(time).substring(0, 5) : 'غير محدد';
}

function isUpcoming(dateStr) {
  if (!dateStr) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return new Date(`${dateStr}T00:00:00`) >= today;
}

async function copyToClipboard(text, successMessage = 'تم النسخ بنجاح') {
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    window.addToast?.(successMessage, 'success');
  } catch (error) {
    console.error('Could not copy text', error);
    window.addToast?.('تعذر نسخ النص', 'error');
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown);
  fetchPassengers();
  fetchAlertSettings();
});

onBeforeUnmount(() => {
  window.clearTimeout(searchTimeout);
  window.removeEventListener('keydown', handleKeydown);
});
</script>

<style scoped>
.pagination-button {
  display: inline-flex;
  height: 2.25rem;
  width: 2.25rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.5rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.04);
  color: var(--text-main);
  transition: 180ms ease;
}

.pagination-button:hover:not(:disabled) {
  border-color: rgba(56, 189, 248, 0.3);
  background: rgba(56, 189, 248, 0.1);
  color: #7dd3fc;
}

.pagination-button:disabled {
  cursor: not-allowed;
  opacity: 0.3;
}

.t-modal-enter-active,
.t-modal-leave-active {
  transition: opacity 240ms ease;
}

.t-modal-enter-active .modal-card,
.t-modal-leave-active .modal-card {
  transition: transform 240ms ease, opacity 240ms ease;
}

.t-modal-enter-from,
.t-modal-leave-to {
  opacity: 0;
}

.t-modal-enter-from .modal-card,
.t-modal-leave-to .modal-card {
  opacity: 0;
  transform: translateY(12px) scale(0.97);
}
</style>
