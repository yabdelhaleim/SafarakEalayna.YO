<template>
  <div class="finance-dashboard flight-booking animate-in pb-10 fade-in duration-700">
    <!-- Header & Actions -->
    <header class="flight-hero relative overflow-hidden">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-sky-400/90">عمليات الطيران</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">
            حجوزات الرحلات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">
            لوحة تشغيل لمراقبة الحجوزات، الأرصدة، والمسارات بنفس تجربة أنظمة الـ GDS الحديثة.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-3">
          <router-link
            :to="{ name: 'flights.treasury' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-bold text-sky-200 shadow-lg transition hover:border-sky-400/40 hover:bg-sky-500/10"
          >
            <Landmark class="h-5 w-5" />
            أرصدة وخزينة الطيران
          </router-link>
          <router-link
            :to="{ name: 'flights.create' }"
            class="btn-airline gap-2 shadow-xl"
          >
            <Plus class="h-5 w-5" />
            حجز جديد
          </router-link>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 mt-8">
    <!-- Filters Bar -->
    <div class="flight-panel !p-4 sm:!p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <div class="flex h-9 w-9 items-center justify-center rounded-xl border border-sky-400/20 bg-sky-500/10 text-sky-300">
            <SlidersHorizontal class="h-4 w-4" />
          </div>
          <div>
            <h2 class="text-sm font-extrabold text-text-main">البحث والتصفية</h2>
            <p class="text-[11px] text-text-muted">حدد نطاق البحث للوصول السريع للحجوزات</p>
          </div>
        </div>
        <button @click="clearFilters" class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-text-muted transition hover:border-gold/40 hover:text-gold">
          <RotateCcw class="h-3.5 w-3.5" />
          مسح الفلاتر
        </button>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
        <!-- Search -->
        <label class="relative xl:col-span-2">
          <Search class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" />
          <input
            v-model="filters.search"
            type="text"
            placeholder="البحث برقم الحجز، العميل، أو PNR..."
            class="flight-input !py-2.5 pr-10 pl-3 text-sm"
            @input="onFilterChange"
          />
        </label>

        <!-- Trip Type Filter -->
        <select v-model="filters.tripType" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل الرحلات</option>
          <option v-for="t in store.tripTypes" :key="t.value" :value="t.value">
            {{ t.label }}
          </option>
        </select>

        <!-- Status Filter -->
        <select v-model="filters.status" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل الحالات</option>
          <option v-for="s in store.bookingStatuses" :key="s.value" :value="s.value">
            {{ s.label }}
          </option>
        </select>

        <!-- Currency Filter -->
        <select v-model="filters.currency" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل العملات</option>
          <option v-for="c in store.currencies" :key="c.code" :value="c.code">
            {{ c.name }} ({{ c.code }})
          </option>
        </select>

        <!-- System Filter -->
        <select v-model="filters.flightSystemId" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل الأنظمة</option>
          <option v-for="system in store.systems" :key="system.id" :value="String(system.id)">
            {{ system.name }}
          </option>
        </select>

        <!-- Carrier Filter -->
        <select v-model="filters.flightCarrierId" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل الشركات</option>
          <option v-for="carrier in store.carriers" :key="carrier.id" :value="carrier.id">
            {{ carrier.name }}
          </option>
        </select>

        <!-- Customer Filter -->
        <select v-model="filters.customerId" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">كل العملاء</option>
          <option v-for="customer in store.customers" :key="customer.id" :value="customer.id">
            {{ customer.full_name }}
          </option>
        </select>

        <select v-model="filters.paymentStatus" @change="onFilterChange" class="flight-select !py-2.5 text-sm">
          <option value="">الدفع (الكل)</option>
          <option v-for="p in store.paymentFilterStatuses" :key="p.value" :value="p.value">
            {{ p.label }}
          </option>
        </select>

        <!-- Date Range -->
        <input
          v-model="filters.departureDateFrom"
          type="date"
          placeholder="من تاريخ السفر"
          class="flight-input !py-2.5 text-sm"
          @change="onFilterChange"
        />

        <input
          v-model="filters.departureDateTo"
          type="date"
          placeholder="إلى تاريخ السفر"
          class="flight-input !py-2.5 text-sm"
          @change="onFilterChange"
        />
      </div>
    </div>

    <!-- Data Table -->
    <div class="flight-panel !overflow-hidden !rounded-2xl !p-0">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-xl border border-gold/20 bg-gold/10 text-gold">
            <Ticket class="h-4 w-4" />
          </div>
          <div>
            <h2 class="text-sm font-extrabold text-text-main">سجل الحجوزات</h2>
            <p class="text-[11px] text-text-muted">كل صف يمثل حجز طيران مسجل</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span v-if="!store.loading.list && !store.errors.fetch" class="inline-flex items-center gap-1.5 rounded-full border border-sky-400/20 bg-sky-500/10 px-3 py-1 text-[11px] font-bold text-sky-300">
            <span class="h-1.5 w-1.5 rounded-full bg-sky-400"></span>
            {{ store.pagination.total || filteredBookings.length }} حجز
          </span>
          <span class="font-mono text-xs font-bold text-text-muted">
            صفحة {{ store.pagination.currentPage }} / {{ store.pagination.lastPage }}
          </span>
        </div>
      </div>

      <!-- Desktop Table View -->
      <div v-if="!isMobile" class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/[0.035] text-[10px] font-bold uppercase tracking-widest text-text-muted border-b border-white/10">
              <th class="px-5 py-4">رقم الحجز</th>
              <th class="px-5 py-4">العميل</th>
              <th class="px-5 py-4">المسار</th>
              <th class="px-5 py-4">المسافرون</th>
              <th class="px-5 py-4">السيستم</th>
              <th class="px-5 py-4">الموظف</th>
              <th class="px-5 py-4">{{ isAdmin ? 'السعر / الربح' : 'السعر' }}</th>
              <th class="px-5 py-4">الحالة</th>
              <th class="px-5 py-4 text-center">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.list">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td v-for="j in 9" :key="j" class="px-5 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="filteredBookings.length > 0">
              <template v-for="(booking, idx) in filteredBookings" :key="booking.id || idx">
                <tr v-if="booking && booking.id"
                  class="border-b border-white/5 transition-colors hover:bg-white/[0.035] group"
                  :style="{ animationDelay: `${idx * 50}ms` }">
                <td class="px-5 py-4">
                  <div class="flex items-center gap-2 relative min-w-[160px]">
                    <div class="flex flex-col min-w-0">
                      <span
                        v-if="booking.pnr"
                        class="font-mono text-gold font-bold text-sm cursor-pointer hover:underline underline-offset-4 decoration-gold/30"
                        :title="'PNR — انقر للنسخ'"
                        @click="copyToClipboard(booking.pnr)"
                      >
                        {{ booking.pnr }}
                      </span>
                      <span
                        class="font-mono cursor-pointer hover:underline underline-offset-4 truncate max-w-[140px]"
                        :class="booking.pnr ? 'text-[10px] text-muted mt-0.5' : 'text-gold font-bold decoration-gold/30'"
                        :title="booking.pnr ? 'مرجع المكتب — انقر للنسخ' : 'انقر للنسخ'"
                        @click="copyToClipboard(booking.bookingNumber)"
                      >
                        {{ booking.bookingNumber }}
                      </span>
                    </div>
                    <Copy class="w-3 h-3 text-muted opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer shrink-0" />
                    <div
                      v-if="copiedTooltip === booking.pnr || copiedTooltip === booking.bookingNumber"
                      class="absolute -top-8 left-1/2 -translate-x-1/2 px-2 py-1 bg-gold text-black text-[10px] font-bold rounded whitespace-nowrap animate-in fade-in zoom-in-95 z-10"
                    >
                      تم النسخ!
                    </div>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="flex items-center gap-3 min-w-[150px]">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-xs font-black text-text-main">
                      {{ customerInitials(booking) }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate font-bold text-sm text-text-main">{{ booking.customer?.name || 'عميل غير محدد' }}</p>
                      <p class="truncate text-[11px] text-text-muted">{{ booking.customer?.phone || '—' }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="min-w-[200px]">
                    <span v-if="booking.tripType" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[9px] font-black mb-1.5" :class="tripTypeBadgeClass(booking.tripType)">
                      <component :is="tripTypeIcon(booking.tripType)" class="h-2.5 w-2.5" />
                      {{ tripTypeLabel(booking.tripType) }}
                    </span>
                    <div class="flex items-center gap-2">
                      <span class="rounded-md bg-white/5 px-2 py-1 font-mono text-[11px] font-black text-text-main">{{ booking.fromAirportCity || '—' }}</span>
                      <div class="relative flex flex-1 items-center justify-center">
                        <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gradient-to-l from-sky-400/60 via-sky-400/20 to-transparent"></div>
                        <Plane class="relative h-3 w-3 -rotate-12 text-sky-400" />
                      </div>
                      <span class="rounded-md bg-white/5 px-2 py-1 font-mono text-[11px] font-black text-text-main">{{ booking.toAirportCity || '—' }}</span>
                    </div>
                    <p v-if="booking.segments?.length > 1" class="mt-1.5 inline-flex items-center gap-1 rounded bg-white/5 px-1.5 py-0.5 text-[10px] text-text-muted">
                      <Route class="h-2.5 w-2.5" />
                      {{ booking.segments.length }} محطات · {{ booking.segments.length - 1 }} توقف
                    </p>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="flex items-center gap-2 text-xs min-w-[90px]">
                    <div class="flex h-7 w-7 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted">
                      <Users class="h-3.5 w-3.5" />
                    </div>
                    <div class="flex flex-col">
                      <span class="font-bold text-text-main">{{ booking.passengersCount }}</span>
                      <span v-if="booking.passengers?.length" class="text-[10px] text-text-muted">{{ paxBreakdown(booking.passengers) }}</span>
                    </div>
                  </div>
                </td>
                <!-- GDS/System name (or Carrier name if booked directly from carrier) -->
                <td class="px-5 py-4">
                  <div class="flex items-center gap-2 min-w-[120px]">
                    <div class="flex h-7 w-7 items-center justify-center rounded-md border border-sky-400/15 bg-sky-500/10 text-sky-300">
                      <Building2 class="h-3.5 w-3.5" />
                    </div>
                    <span class="text-xs font-semibold text-text-main truncate max-w-[140px]">
                      {{ booking.systemDisplay || booking.flightSystem?.name || booking.flightCarrier?.name || booking.systemTypeLabel || '—' }}
                    </span>
                  </div>
                </td>
                <!-- Creator/Employee name -->
                <td class="px-5 py-4">
                  <div class="flex items-center gap-2 min-w-[100px]">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full border border-white/10 bg-white/5 text-[10px] font-black text-text-main">
                      {{ employeeInitials(booking) }}
                    </div>
                    <span class="truncate text-xs text-text-main max-w-[100px]">
                      {{ booking.employee?.name || booking.createdByName || '—' }}
                    </span>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="flex flex-col min-w-[110px]">
                    <span class="font-mono text-sm font-black text-text-main">{{ (booking.pricing?.sellingPrice ?? 0).toLocaleString() }} {{ booking.pricing?.currency || 'EGP' }}</span>
                    <div v-if="isAdmin" :class="['mt-0.5 inline-flex items-center gap-1 text-[10px] font-bold', (booking.pricing?.profit ?? 0) >= 0 ? 'text-success' : 'text-error']">
                      <span class="rounded bg-current/10 px-1.5 py-0.5">
                        <TrendingUp v-if="(booking.pricing?.profit ?? 0) >= 0" class="inline h-2.5 w-2.5" />
                        <TrendingDown v-else class="inline h-2.5 w-2.5" />
                        {{ (booking.pricing?.profit ?? 0).toLocaleString() }} ربح
                      </span>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-black', statusStyles[booking.status]?.borderClass]">
                    <span :class="['h-1.5 w-1.5 rounded-full', statusStyles[booking.status]?.dotClass, { 'animate-pulse': booking.status === 'confirmed' }]"></span>
                    {{ getStatusLabelAr(booking.status) }}
                  </span>
                </td>
                <td class="px-5 py-4">
                  <div class="flex items-center justify-center gap-1">
                    <button
                      @click="printTicket(booking)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-gold/40 hover:bg-gold/10 hover:text-gold"
                      title="طباعة التذكرة"
                      aria-label="طباعة التذكرة"
                    >
                      <Printer class="h-3.5 w-3.5" />
                    </button>
                    <router-link :to="{ name: 'flights.show', params: { id: booking.id } }"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-sky-400/40 hover:bg-sky-500/15 hover:text-sky-300"
                      title="عرض التفاصيل"
                      aria-label="عرض التفاصيل">
                      <Eye class="h-3.5 w-3.5" />
                    </router-link>
                    <router-link :to="{ name: 'flights.edit', params: { id: booking.id } }"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-gold/40 hover:bg-gold/10 hover:text-gold"
                      title="تعديل الحجز"
                      aria-label="تعديل الحجز">
                      <Edit2 class="h-3.5 w-3.5" />
                    </router-link>
                    <button @click="confirmDelete(booking)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-error/40 hover:bg-error/15 hover:text-error"
                      title="حذف الحجز"
                      aria-label="حذف الحجز">
                      <Trash2 class="h-3.5 w-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
              </template>
            </template>
            <tr v-else-if="store.errors.fetch">
              <td colspan="9" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-error/10 text-error rounded-full flex items-center justify-center">
                    <AlertCircle class="w-10 h-10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-error">فشل تحميل الحجوزات</h3>
                    <p class="text-muted text-sm mt-1">{{ store.errors.fetch }}</p>
                  </div>
                  <button @click="store.fetchBookings()" class="mt-2 px-6 py-2 bg-error/10 text-error rounded-xl hover:bg-error/20 transition-colors font-bold">
                    إعادة المحاولة
                  </button>
                </div>
              </td>
            </tr>
            <tr v-else>
              <td colspan="9" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <Plane class="w-10 h-10 text-white/10 -rotate-45" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold">لم يتم العثور على حجوزات</h3>
                    <p class="text-muted text-sm mt-1">جرب تعديل الفلاتر أو إنشاء حجز جديد للبدء.</p>
                  </div>
                  <router-link :to="{ name: 'flights.create' }" class="mt-2 text-gold font-bold hover:underline">
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
        <!-- Mobile Skeleton Loading -->
        <template v-if="store.loading.list">
          <div v-for="i in 8" :key="i" class="p-4 space-y-3">
            <div class="h-4 animate-shimmer rounded w-1/2"></div>
            <div class="h-3 animate-shimmer rounded w-3/4"></div>
            <div class="h-3 animate-shimmer rounded w-1/3"></div>
          </div>
        </template>

        <!-- Mobile Cards -->
        <template v-else-if="filteredBookings.length > 0">
          <template v-for="(booking, idx) in filteredBookings" :key="booking.id || idx">
            <div
              v-if="booking && booking.id"
              class="p-4 space-y-3 hover:bg-white/[0.035] transition-colors"
              :style="{ animationDelay: `${idx * 40}ms` }"
            >
            <!-- Top Row: Booking # + Status + Trip Type -->
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0 flex-1">
                <span
                  v-if="booking.pnr"
                  class="font-mono text-gold font-bold text-sm cursor-pointer hover:underline block"
                  @click="copyToClipboard(booking.pnr)"
                >
                  {{ booking.pnr }}
                </span>
                <span
                  class="font-mono cursor-pointer hover:underline block truncate"
                  :class="booking.pnr ? 'text-[10px] text-muted' : 'text-gold font-bold text-sm'"
                  @click="copyToClipboard(booking.bookingNumber)"
                >
                  {{ booking.bookingNumber }}
                </span>
                <span v-if="booking.tripType" class="mt-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[9px] font-black" :class="tripTypeBadgeClass(booking.tripType)">
                  <component :is="tripTypeIcon(booking.tripType)" class="h-2.5 w-2.5" />
                  {{ tripTypeLabel(booking.tripType) }}
                </span>
              </div>
              <span :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-black', statusStyles[booking.status]?.borderClass]">
                <span :class="['h-1.5 w-1.5 rounded-full', statusStyles[booking.status]?.dotClass, { 'animate-pulse': booking.status === 'confirmed' }]"></span>
                {{ getStatusLabelAr(booking.status) }}
              </span>
            </div>

            <!-- Middle: Customer + Route -->
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-[10px] font-black text-text-main">
                  {{ customerInitials(booking) }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="truncate font-bold text-sm text-text-main">{{ booking.customer?.name || 'عميل غير محدد' }}</p>
                  <p class="truncate text-[11px] text-text-muted">{{ booking.customer?.phone || '—' }}</p>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="rounded-md bg-white/5 px-2 py-1 font-mono text-[11px] font-black text-text-main">{{ booking.fromAirportCity || '—' }}</span>
                <div class="relative flex flex-1 items-center justify-center">
                  <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gradient-to-l from-sky-400/60 via-sky-400/20 to-transparent"></div>
                  <Plane class="relative h-3 w-3 -rotate-12 text-sky-400" />
                </div>
                <span class="rounded-md bg-white/5 px-2 py-1 font-mono text-[11px] font-black text-text-main">{{ booking.toAirportCity || '—' }}</span>
              </div>
            </div>

            <!-- Bottom: System + PAX + Price -->
            <div class="flex items-center justify-between gap-3 rounded-lg border border-white/5 bg-white/[0.02] p-2.5 text-xs">
              <div class="flex items-center gap-2 text-text-muted">
                <Building2 class="h-3 w-3" />
                <span class="truncate max-w-[100px]">{{ booking.systemDisplay || booking.flightSystem?.name || booking.flightCarrier?.name || booking.systemTypeLabel || '—' }}</span>
              </div>
              <div class="flex items-center gap-3">
                <div class="flex items-center gap-1 text-text-muted">
                  <Users class="h-3 w-3" />
                  {{ booking.passengersCount }}
                </div>
                <div class="font-mono font-black text-text-main">
                  {{ (booking.pricing?.sellingPrice ?? 0).toLocaleString() }} {{ booking.pricing?.currency || 'EGP' }}
                </div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2 pt-1">
              <button
                @click="printTicket(booking)"
                class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 text-xs font-bold text-text-muted transition hover:border-gold/40 hover:text-gold"
                title="طباعة"
              >
                <Printer class="h-3.5 w-3.5" />
              </button>
              <router-link
                :to="{ name: 'flights.show', params: { id: booking.id } }"
                class="flex-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-sky-400/20 bg-sky-500/10 text-xs font-bold text-sky-300 transition hover:bg-sky-500/20"
              >
                <Eye class="h-3.5 w-3.5" />
                عرض
              </router-link>
              <router-link
                :to="{ name: 'flights.edit', params: { id: booking.id } }"
                class="flex-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-gold/30 bg-gold/10 text-xs font-bold text-gold transition hover:bg-gold/20"
              >
                <Edit2 class="h-3.5 w-3.5" />
                تعديل
              </router-link>
              <button
                @click="confirmDelete(booking)"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-error/30 bg-error/10 text-error transition hover:bg-error/20"
                title="حذف"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
              </div>
            </div>
          </template>
        </template>

        <!-- Mobile Empty/Error States -->
        <div v-else-if="store.errors.fetch" class="p-8 text-center">
          <div class="w-16 h-16 bg-error/10 text-error rounded-full flex items-center justify-center mx-auto mb-4">
            <AlertCircle class="w-8 h-8" />
          </div>
          <h3 class="text-lg font-bold text-error mb-2">فشل تحميل الحجوزات</h3>
          <p class="text-muted text-sm mb-4">{{ store.errors.fetch }}</p>
          <button @click="store.fetchBookings()" class="px-6 py-2 bg-error/10 text-error rounded-xl hover:bg-error/20 transition-colors font-bold">
            إعادة المحاولة
          </button>
        </div>

        <div v-else class="p-8 text-center">
          <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
            <Plane class="w-8 h-8 text-white/10 -rotate-45" />
          </div>
          <h3 class="text-lg font-bold mb-2">لم يتم العثور على حجوزات</h3>
          <p class="text-muted text-sm mb-4">جرب تعديل الفلاتر أو إنشاء حجز جديد.</p>
          <router-link :to="{ name: 'flights.create' }" class="text-gold font-bold hover:underline">
            إنشاء حجز جديد
          </router-link>
        </div>
      </div>

      <!-- Pagination -->
      <div class="px-5 py-4 bg-white/[0.025] border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-text-muted">
        <div class="flex items-center gap-2">
          <span>عرض</span>
          <b class="text-text-main">{{ (store.pagination.currentPage - 1) * store.pagination.perPage + 1 }}</b>
          <span>إلى</span>
          <b class="text-text-main">{{ Math.min(store.pagination.currentPage * store.pagination.perPage, store.pagination.total || filteredBookings.length) }}</b>
          <span>من</span>
          <b class="text-text-main">{{ store.pagination.total || filteredBookings.length }}</b>
          <span>نتيجة</span>
        </div>
        <div class="flex items-center gap-2">
          <select v-model="store.filters.perPage" @change="onPerPageChange" class="px-3 py-2 bg-input border border-white/10 rounded-lg focus:border-gold outline-none text-sm text-text-main">
            <option :value="10">10 / صفحة</option>
            <option :value="15">15 / صفحة</option>
            <option :value="25">25 / صفحة</option>
            <option :value="50">50 / صفحة</option>
          </select>
          <div class="flex items-center gap-1">
            <button @click="goToPage(store.pagination.currentPage - 1)" :disabled="store.pagination.currentPage === 1" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-gold/40 hover:text-gold disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:border-white/10 disabled:hover:text-text-muted"><ChevronLeft class="w-4 h-4" /></button>
            <button v-for="page in visiblePages" :key="page"
              @click="goToPage(page)"
              :class="['inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-black transition-colors', page === store.pagination.currentPage ? 'border border-gold/40 bg-gold text-black shadow-[0_0_15px_rgba(212,168,67,0.25)]' : 'border border-white/10 bg-white/5 text-text-main hover:border-sky-400/40 hover:text-sky-300']">
              {{ page }}
            </button>
            <button @click="goToPage(store.pagination.currentPage + 1)" :disabled="store.pagination.currentPage === store.pagination.lastPage" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-text-muted transition hover:border-gold/40 hover:text-gold disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:border-white/10 disabled:hover:text-text-muted"><ChevronRight class="w-4 h-4" /></button>
          </div>
        </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, watch, nextTick } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import { useRoute, useRouter } from 'vue-router';
import { useDebounceFn, useTransition, useMediaQuery } from '@vueuse/core';
import {
  Plus, Search, Copy, ArrowRight, Users, TrendingUp, TrendingDown,
  Eye, Edit2, Trash2, Plane, ChevronLeft, ChevronRight, AlertCircle,
  LayoutDashboard, CreditCard, DollarSign, Activity, Printer, Percent, Ticket,
  Calendar, MapPin, Building2, Landmark, RotateCcw, SlidersHorizontal, Route,
  PlaneTakeoff, PlaneLanding, ArrowRightLeft
} from 'lucide-vue-next';

const store = useFlightStore();
const route = useRoute();
const router = useRouter();

const filters = ref({
  search: route.query.search || '',
  status: route.query.status || '',
  tripType: route.query.tripType || '',
  currency: route.query.currency || '',
  flightSystemId: route.query.flightSystemId || '',
  flightCarrierId: route.query.flightCarrierId || '',
  customerId: route.query.customerId || '',
  dateFrom: route.query.dateFrom || '',
  dateTo: route.query.dateTo || '',
  departureDateFrom: route.query.departureDateFrom || '',
  departureDateTo: route.query.departureDateTo || '',
  paymentStatus: route.query.paymentStatus || '',
});

const copiedTooltip = ref('');
const showAddCreditModal = ref(false);
const selectedCarrier = ref(null);
const isMobile = useMediaQuery('(max-width: 768px)');

// Animated stats with count-up effect
const totalSource = ref(0);
const revenueSource = ref(0);
const profitSource = ref(0);
const activeSource = ref(0);
const marginPctSource = ref(0);
const avgTicketSource = ref(0);

const totalOutput = useTransition(totalSource, { duration: 1500 });
const revenueOutput = useTransition(revenueSource, { duration: 1500 });
const profitOutput = useTransition(profitSource, { duration: 1500 });
const activeOutput = useTransition(activeSource, { duration: 1500 });
const marginPctOutput = useTransition(marginPctSource, { duration: 1500 });
const avgTicketOutput = useTransition(avgTicketSource, { duration: 1500 });

const animateStats = () => {
  const stats = store.bookingStats;
  totalSource.value = stats.total;
  revenueSource.value = Math.floor(stats.revenue);
  profitSource.value = Math.floor(stats.profit);
  activeSource.value = stats.active;
  const marginPct = stats.revenue > 0 ? (stats.profit / stats.revenue) * 100 : 0;
  marginPctSource.value = Math.round(marginPct * 10) / 10;
  avgTicketSource.value = stats.total > 0 ? Math.floor(stats.revenue / stats.total) : 0;
};

const statsCards = computed(() => {
  return [
    { label: 'إجمالي الحجوزات', icon: LayoutDashboard },
    { label: 'الإيرادات', icon: DollarSign },
    { label: 'إجمالي الربح', icon: CreditCard },
    { label: 'الرحلات النشطة', icon: Activity },
    { label: 'هامش الربح', icon: Percent },
    { label: 'متوسط قيمة الحجز', icon: Ticket },
  ];
});

// Animated stats for display
const animatedStats = computed(() => {
  const marginRounded = Math.round(marginPctOutput.value * 10) / 10;
  return [
    Math.floor(totalOutput.value).toLocaleString(),
    `${Math.floor(revenueOutput.value).toLocaleString()} ج.م`,
    `${Math.floor(profitOutput.value).toLocaleString()} ج.م`,
    Math.floor(activeOutput.value).toLocaleString(),
    `${Number.isFinite(marginRounded) ? marginRounded.toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) : '0'}%`,
    `${Math.floor(avgTicketOutput.value).toLocaleString()} ج.م`,
  ];
});

const statusStyles = {
  pending: {
    borderClass: 'border-white/15 bg-white/5 text-text-muted',
    dotClass: 'bg-text-muted',
  },
  confirmed: {
    borderClass: 'border-success/30 bg-success/10 text-success shadow-[0_0_15px_rgba(16,217,140,0.15)]',
    dotClass: 'bg-success',
  },
  ticketed: {
    borderClass: 'border-gold/30 bg-gold/10 text-gold shadow-[0_0_15px_rgba(212,168,67,0.15)]',
    dotClass: 'bg-gold',
  },
  cancelled: {
    borderClass: 'border-error/30 bg-error/10 text-error',
    dotClass: 'bg-error',
  },
  refunded: {
    borderClass: 'border-white/10 bg-white/5 text-text-muted',
    dotClass: 'bg-text-muted',
  },
};

const statusLabelsAr = {
  pending: 'قيد الانتظار',
  confirmed: 'مؤكد',
  ticketed: 'صدرت التذكرة',
  cancelled: 'ملغي',
  refunded: 'مُسترد'
};

const getStatusLabelAr = (status) => statusLabelsAr[status] || status;

const filteredBookings = computed(() => store.filteredBookings(filters.value));

const uniqueAirlines = computed(() => {
  const airlines = new Set();
  store.bookings.forEach(booking => {
    if (booking.airlineName) {
      airlines.add(booking.airlineName);
    }
    if (booking.segments && booking.segments.length > 0) {
      booking.segments.forEach(segment => {
        if (segment.airline) {
          airlines.add(segment.airline);
        }
      });
    }
  });
  return Array.from(airlines).sort();
});

// Filtered carriers by system
const filteredCarriers = computed(() => {
  if (!store.carriers || store.carriers.length === 0) return [];

  if (!filters.value.flightSystemId) {
    return store.carriers;
  }

  return store.carriers.filter(carrier =>
    carrier.flight_system_id === parseInt(filters.value.flightSystemId)
  );
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

const onFilterChange = useDebounceFn(() => {
  // Build filters object, excluding empty values
  const apiFilters = {
    per_page: store.filters.perPage,
    page: 1
  };

  // Add all filters
  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.tripType) apiFilters.trip_type = filters.value.tripType;
  if (filters.value.currency) apiFilters.currency = filters.value.currency;
  if (filters.value.flightSystemId) apiFilters.flight_system_id = filters.value.flightSystemId;
  if (filters.value.flightCarrierId) apiFilters.flight_carrier_id = filters.value.flightCarrierId;
  if (filters.value.customerId) apiFilters.customer_id = filters.value.customerId;
  if (filters.value.departureDateFrom) apiFilters.departure_date_from = filters.value.departureDateFrom;
  if (filters.value.departureDateTo) apiFilters.departure_date_to = filters.value.departureDateTo;
  if (filters.value.paymentStatus) apiFilters.payment_status = filters.value.paymentStatus;

  store.filters = { ...filters.value, page: 1 };
  router.replace({ query: { ...filters.value } });
  store.fetchBookings(apiFilters);
}, 400);

const filterCarriersBySystem = () => {
  // Reload carriers when system filter changes
  if (filters.value.flightSystemId) {
    store.fetchCarriers({ flight_system_id: filters.value.flightSystemId });
  } else {
    store.fetchCarriers();
  }
};

const selectCarrier = (carrier) => {
  selectedCarrier.value = carrier;
  filters.value.flightCarrierId = carrier.id;
  filters.value.flightSystemId = carrier.flight_system_id;
  onFilterChange();
};

const printTicket = (booking) => {
  if (!booking?.id) return;
  store.setCurrentBooking(booking);
  router.push({
    name: 'flights.show',
    params: { id: String(booking.id) },
    query: { print: '1' },
  });
};

// Helper to build full API filters object from current filter state
const buildApiFilters = (overrides = {}) => {
  const apiFilters = {
    per_page: store.filters.perPage,
    page: 1,
    ...overrides
  };
  if (filters.value.search) apiFilters.search = filters.value.search;
  if (filters.value.status) apiFilters.status = filters.value.status;
  if (filters.value.tripType) apiFilters.trip_type = filters.value.tripType;
  if (filters.value.currency) apiFilters.currency = filters.value.currency;
  if (filters.value.flightSystemId) apiFilters.flight_system_id = filters.value.flightSystemId;
  if (filters.value.flightCarrierId) apiFilters.flight_carrier_id = filters.value.flightCarrierId;
  if (filters.value.customerId) apiFilters.customer_id = filters.value.customerId;
  if (filters.value.departureDateFrom) apiFilters.departure_date_from = filters.value.departureDateFrom;
  if (filters.value.departureDateTo) apiFilters.departure_date_to = filters.value.departureDateTo;
  if (filters.value.paymentStatus) apiFilters.payment_status = filters.value.paymentStatus;
  return apiFilters;
};

const onPerPageChange = () => {
  store.filters.page = 1;
  store.fetchBookings(buildApiFilters({ page: 1 }));
};

const goToPage = (page) => {
  if (page < 1 || page > store.pagination.lastPage || page === '...') return;
  store.filters.page = page;
  router.replace({ query: { ...filters.value, page } });
  store.fetchBookings(buildApiFilters({ page }));
};

const clearFilters = () => {
  filters.value = {
    search: '',
    status: '',
    tripType: '',
    currency: '',
    flightSystemId: '',
    flightCarrierId: '',
    customerId: '',
    dateFrom: '',
    dateTo: '',
    departureDateFrom: '',
    departureDateTo: '',
    paymentStatus: '',
  };
  store.filters = { page: 1, perPage: 15 };
  selectedCarrier.value = null;
  router.replace({ query: {} });
  store.fetchBookings({ per_page: 15, page: 1 });
};

const paxBreakdown = (passengers) => {
  const a = passengers.filter(p => p.type === 'adult').length;
  const c = passengers.filter(p => p.type === 'child').length;
  const i = passengers.filter(p => p.type === 'infant').length;
  let parts = [];
  if (a) parts.push(`${a}A`);
  if (c) parts.push(`${c}C`);
  if (i) parts.push(`${i}I`);
  return parts.join(' ');
};

const customerInitials = (booking) => {
  const name = booking?.customer?.name || booking?.customer?.full_name || '';
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return 'C';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
};

const employeeInitials = (booking) => {
  const name = booking?.employee?.name || booking?.createdByName || '';
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '—';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
};

const tripTypeLabel = (tripType) => {
  const map = {
    one_way: 'ذهاب فقط',
    round_trip: 'ذهاب وعودة',
    multi_city: 'متعدد المحطات',
  };
  return map[tripType] || tripType || '—';
};

const tripTypeBadgeClass = (tripType) => {
  if (tripType === 'round_trip') return 'border-gold/30 bg-gold/10 text-gold';
  if (tripType === 'one_way') return 'border-sky-400/30 bg-sky-500/10 text-sky-300';
  if (tripType === 'multi_city') return 'border-violet-400/30 bg-violet-500/10 text-violet-300';
  return 'border-white/10 bg-white/5 text-text-muted';
};

const tripTypeIcon = (tripType) => {
  if (tripType === 'round_trip') return ArrowRightLeft;
  if (tripType === 'one_way') return PlaneTakeoff;
  if (tripType === 'multi_city') return Route;
  return Plane;
};

const copyToClipboard = async (text) => {
  await navigator.clipboard.writeText(text);
  copiedTooltip.value = text;
  setTimeout(() => {
    copiedTooltip.value = '';
  }, 2000);
};

const formatDate = (date) => {
  if (!date) return '';
  const d = new Date(date);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const confirmDelete = async (booking) => {
  if (confirm(`هل أنت متأكد من أنك تريد حذف الحجز ${booking.bookingNumber}؟`)) {
    try {
      await store.deleteBooking(booking.id);
      store.addToast(`تم حذف الحجز ${booking.bookingNumber} بنجاح`);

      // Build filters object, excluding empty values
      const apiFilters = {
        per_page: store.filters.perPage,
        page: store.pagination.currentPage
      };

      // Only add non-empty filters
      if (filters.value.search) apiFilters.search = filters.value.search;
      if (filters.value.status) apiFilters.status = filters.value.status;
      if (filters.value.dateFrom) apiFilters.from_date = filters.value.dateFrom;
      if (filters.value.dateTo) apiFilters.to_date = filters.value.dateTo;

      await store.fetchBookings(apiFilters);
    } catch (error) {
      store.addToast('فشل حذف الحجز', 'error');
    }
  }
};

const fetchData = async () => {
  const page = route.query.page ? parseInt(route.query.page) : 1;
  const apiFilters = buildApiFilters({ page });
  await store.fetchBookings(apiFilters);
  animateStats();
};

onMounted(async () => {
  // Fetch all required data
  await Promise.all([
    store.fetchSystems(),
    store.fetchCarriers(),
    store.fetchCustomers(),
    store.fetchTripTypes(),
    store.fetchCurrencies(),
    store.fetchFlightBookingReference(),
  ]);

  // Populate filters from URL on mount
  if (route.query.search) filters.value.search = route.query.search;
  if (route.query.status) filters.value.status = route.query.status;
  if (route.query.tripType) filters.value.tripType = route.query.tripType;
  if (route.query.currency) filters.value.currency = route.query.currency;
  if (route.query.flightSystemId) filters.value.flightSystemId = route.query.flightSystemId;
  if (route.query.flightCarrierId) filters.value.flightCarrierId = route.query.flightCarrierId;
  if (route.query.customerId) filters.value.customerId = route.query.customerId;
  if (route.query.dateFrom) filters.value.dateFrom = route.query.dateFrom;
  if (route.query.dateTo) filters.value.dateTo = route.query.dateTo;
  if (route.query.departureDateFrom) filters.value.departureDateFrom = route.query.departureDateFrom;
  if (route.query.departureDateTo) filters.value.departureDateTo = route.query.departureDateTo;

  await fetchData();
});

// Fetch fresh data when component is activated (navigation)
onActivated(async () => {
  await nextTick();
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

/* Row stagger animation (desktop) */
@keyframes rowFadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

tbody tr {
  animation: rowFadeIn 0.4s ease-out forwards;
  opacity: 0;
}

/* Card slide-up animation (mobile) */
@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.divide-y > div[class*="space-y-3"] {
  animation: slideUp 0.3s ease-out forwards;
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  tbody tr,
  .divide-y > div {
    animation: none;
    opacity: 1;
  }
}
</style>
