<!-- Updated: 2026-05-09 13:10 (Decoupled Architecture Labels) -->
<template>
  <div class="flight-booking mx-auto max-w-7xl space-y-8 pb-16">
    <header class="flight-hero relative">
      <div class="relative z-10 flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
          <router-link
            to="/flights"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة لقائمة الحجوزات"
          >
            <ArrowRight class="h-5 w-5 text-sky-300" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">
              نظام حجز الطيران
            </p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
              {{ pageTitle }}
            </h1>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-text-muted">
              {{ pageSubtitle }}
            </p>
          </div>
        </div>
        <div class="flex shrink-0 flex-col items-stretch gap-4 sm:flex-row sm:items-center">
          <div
            class="flex items-center gap-4 rounded-2xl border border-white/10 bg-black/25 px-5 py-4 backdrop-blur-sm"
          >
            <div class="text-left sm:text-right">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">
                التقدم
              </div>
              <div class="text-lg font-black text-gold">
                {{ currentStep }}
                <span class="text-text-muted">/</span>
                7
              </div>
            </div>
            <div class="relative h-14 w-14">
              <svg class="h-full w-full -rotate-90 transform">
                <circle
                  cx="28"
                  cy="28"
                  r="22"
                  stroke="currentColor"
                  stroke-width="3"
                  fill="transparent"
                  class="text-white/10"
                />
                <circle
                  cx="28"
                  cy="28"
                  r="22"
                  stroke="currentColor"
                  stroke-width="3"
                  fill="transparent"
                  stroke-linecap="round"
                  class="text-sky-400 transition-all duration-500"
                  :stroke-dasharray="circumferenceWide"
                  :stroke-dashoffset="progressOffsetWide"
                />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-[11px] font-black text-text-main">
                  {{ Math.round((currentStep / 7) * 100) }}%
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <nav class="relative z-10 mt-8 flight-stepper" aria-label="خطوات الحجز">
        <button
          v-for="step in 7"
          :key="step"
          type="button"
          :disabled="step > currentStep"
            :class="[
              'flight-step max-w-[140px] flex-1 justify-center sm:max-w-none',
              currentStep === step && 'flight-step--active',
              isBookingStepComplete(step) && 'flight-step--done',
              step > currentStep && !isBookingStepComplete(step) && 'opacity-40',
            ]"
          @click="goToStep(step)"
        >
          <span
            :class="[
              'flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-black',
              currentStep === step
                ? 'bg-gold text-black'
                : currentStep > step
                  ? 'bg-success/20 text-success'
                  : 'bg-white/10 text-text-muted',
            ]"
          >
            <Check v-if="isBookingStepComplete(step)" class="h-3.5 w-3.5" />
            <span v-else>{{ step }}</span>
          </span>
          <span class="hidden truncate sm:inline">{{ getStepLabel(step) }}</span>
        </button>
      </nav>
    </header>

    <div>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Form (Left 2/3) -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Step 1: Trip Type Selection -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 1" class="space-y-6">
              <div class="flight-panel">
                <div class="flex items-center gap-3 mb-6">
                  <div class="dashboard-kpi__icon !h-12 !w-12 shrink-0">
                    <Plane class="h-6 w-6" />
                  </div>
                  <div>
                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">
                      Trip selection
                    </p>
                    <h2 class="flight-panel__title">نوع الرحلة</h2>
                    <p class="flight-panel__subtitle">اختر نوع الرحلة المناسبة</p>
                  </div>
                </div>

                <div
                  class="flex flex-wrap gap-2 rounded-2xl border border-white/10 bg-black/25 p-2"
                  role="tablist"
                  aria-label="نوع الرحلة"
                >
                  <button
                    v-for="tripType in tripTypeOptions"
                    :key="tripType.value"
                    type="button"
                    role="tab"
                    :aria-selected="form.trip_type === tripType.value"
                    @click="selectTripType(tripType.value)"
                    :class="[
                      'flex min-w-[7.5rem] flex-1 items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold transition-all',
                      form.trip_type === tripType.value
                        ? 'bg-gold text-black shadow-lg shadow-gold/25 ring-1 ring-gold/40'
                        : 'bg-white/5 text-text-muted hover:bg-white/10 hover:text-white',
                    ]"
                  >
                    <component :is="getTripTypeIcon(tripType.value)" class="h-4 w-4 shrink-0" />
                    {{ tripType.label }}
                  </button>
                </div>
                <p v-if="selectedTripTypeDescription" class="mt-4 text-center text-sm text-text-muted">
                  {{ selectedTripTypeDescription }}
                </p>
              </div>
            </div>
          </transition>

          <!-- Step 2: Route Selection -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 2" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-sky-500/15 p-3 text-sky-300">
                    <MapPin class="h-6 w-6" />
                  </div>
                  <div>
                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">
                      Route selection
                    </p>
                    <h2 class="flight-panel__title">تحديد المسار</h2>
                    <p class="flight-panel__subtitle">
                      اختر المطارات وتواريخ السفر — القائمة من المطارات المفعّلة في النظام؛ اكتب حرفاً واحداً على الأقل للتصفية.
                    </p>
                  </div>
                </div>

                <p v-if="!form.trip_type" class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-100">
                  اختر نوع الرحلة في الخطوة السابقة أولاً.
                </p>

                <!-- ذهاب فقط / ذهاب وعودة -->
                <div v-else-if="form.trip_type !== 'multi_city'" class="space-y-6">
                  <div
                    v-if="form.from_airport && form.to_airport"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3"
                  >
                    <div class="flex items-center gap-2 text-sm text-text-muted">
                      <span class="font-mono font-bold text-gold">{{ form.from_airport.iata_code }}</span>
                      <span class="text-text-muted">→</span>
                      <span class="font-mono font-bold text-gold">{{ form.to_airport.iata_code }}</span>
                      <span class="mr-2 text-xs text-text-muted/80">
                        {{ form.from_airport.city_name_ar || form.from_airport.city_name_en }} —
                        {{ form.to_airport.city_name_ar || form.to_airport.city_name_en }}
                      </span>
                    </div>
                    <button
                      type="button"
                      class="btn-airline-ghost inline-flex items-center gap-2 px-3 py-1.5 text-xs"
                      @click="swapRouteAirports"
                    >
                      <ArrowLeftRight class="h-3.5 w-3.5" />
                      تبديل المسار
                    </button>
                  </div>

                  <AirportSearchInput
                    v-model="form.from_airport"
                    label="من"
                    placeholder="مثال: CAI، JED، DXB..."
                    :required="true"
                    :exclude-iata="form.to_airport?.iata_code || null"
                  />

                  <AirportSearchInput
                    v-model="form.to_airport"
                    label="إلى"
                    placeholder="مثال: CAI، JED، DXB..."
                    :required="true"
                    :exclude-iata="form.from_airport?.iata_code || null"
                  />

                  <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                      <label class="mb-2 block text-sm font-medium text-gray-300">
                        تاريخ المغادرة <span class="text-error">*</span>
                      </label>
                      <input
                        v-model="form.departure_date"
                        type="date"
                        :min="minDate"
                        class="flight-input"
                      />
                    </div>
                    <div v-if="form.trip_type === 'round_trip'">
                      <label class="mb-2 block text-sm font-medium text-gray-300">
                        تاريخ العودة <span class="text-error">*</span>
                      </label>
                      <input
                        v-model="form.return_date"
                        type="date"
                        :min="form.departure_date || minDate"
                        class="flight-input"
                      />
                    </div>
                  </div>

                  <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                    <h3 class="mb-4 text-sm font-bold text-white">رحلة الذهاب</h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">
                          وقت المغادرة <span class="text-error">*</span>
                        </label>
                        <TimePicker
                          v-model="form.departure_time"
                          required
                        />
                      </div>
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">
                          وقت الوصول <span class="text-error">*</span>
                        </label>
                        <TimePicker
                          v-model="form.arrival_time"
                          required
                        />
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="form.trip_type === 'round_trip'"
                    class="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4"
                  >
                    <h3 class="mb-4 text-sm font-bold text-sky-200">رحلة العودة</h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">
                          وقت المغادرة <span class="text-error">*</span>
                        </label>
                        <TimePicker
                          v-model="form.return_time"
                          required
                        />
                      </div>
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">
                          وقت الوصول <span class="text-error">*</span>
                        </label>
                        <TimePicker
                          v-model="form.return_arrival_time"
                          required
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <!-- وجهات متعددة -->
                <div v-else class="space-y-4">
                  <p class="text-sm text-text-muted">
                    أضف من 2 إلى 5 قطع رحلة. كل قطعة تحتاج مطار المغادرة والوصول والتاريخ والأوقات.
                  </p>

                  <div
                    v-for="(leg, legIndex) in form.legs"
                    :key="leg.uid"
                    class="relative rounded-2xl border border-white/10 bg-white/[0.03] p-5"
                  >
                    <div class="mb-4 flex items-center justify-between gap-3">
                      <h3 class="text-sm font-bold text-gold">
                        القطعة {{ legIndex + 1 }}
                      </h3>
                      <button
                        v-if="form.legs.length > 2"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-error hover:bg-error/10"
                        @click="removeMultiCityLeg(legIndex)"
                      >
                        <Trash2 class="h-3.5 w-3.5" />
                        حذف
                      </button>
                    </div>

                    <div class="space-y-4">
                      <AirportSearchInput
                        v-model="leg.from_airport"
                        label="من"
                        placeholder="مثال: CAI"
                        :required="true"
                        :exclude-iata="leg.to_airport?.iata_code || null"
                      />
                      <AirportSearchInput
                        v-model="leg.to_airport"
                        label="إلى"
                        placeholder="مثال: DXB"
                        :required="true"
                        :exclude-iata="leg.from_airport?.iata_code || null"
                      />
                      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                          <label class="mb-2 block text-sm font-medium text-gray-300">
                            التاريخ <span class="text-error">*</span>
                          </label>
                          <input
                            v-model="leg.departure_date"
                            type="date"
                            :min="legIndex === 0 ? minDate : (form.legs[legIndex - 1]?.departure_date || minDate)"
                            class="flight-input"
                          />
                        </div>
                        <div>
                          <label class="mb-2 block text-sm font-medium text-gray-300">
                            وقت المغادرة <span class="text-error">*</span>
                          </label>
                          <TimePicker
                            v-model="leg.departure_time"
                            required
                          />
                        </div>
                        <div>
                          <label class="mb-2 block text-sm font-medium text-gray-300">
                            وقت الوصول <span class="text-error">*</span>
                          </label>
                          <TimePicker
                            v-model="leg.arrival_time"
                            required
                          />
                        </div>
                      </div>
                    </div>
                  </div>

                  <button
                    v-if="form.legs.length < 5"
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-white/15 py-4 text-sm font-bold text-text-muted transition-all hover:border-gold/40 hover:text-gold"
                    @click="addMultiCityLeg"
                  >
                    <Plus class="h-4 w-4" />
                    إضافة قطعة رحلة
                  </button>
                </div>
                </div>
              </div>
          </transition>

          <!-- Step 3: Flight System Selection -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 3" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-violet-500/15 p-3 text-violet-300">
                    <Settings class="h-6 w-6" />
                  </div>
                  <div>
                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">
                      Booking Source
                    </p>
                    <h2 class="flight-panel__title">مصدر الحجز</h2>
                    <p class="flight-panel__subtitle">
                      سيستم أو مجموعة أو ساين — خط الطيران يُختار لاحقاً كبيانات فقط؛ الخصم من رصيد الساين في حجز الساين فقط
                    </p>
                  </div>
                </div>

                <!-- Booking Type Toggle -->
                <div class="mb-8 p-4 bg-white/5 rounded-2xl border border-white/10">
                  <label class="block text-sm font-bold text-gray-400 mb-4 text-center">نوع مصدر الحجز</label>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <button 
                      type="button"
                      @click="form.booking_source = 'system'; form.purchase_balance_source = 'system'; form.flight_system_id = null; form.flight_carrier_id = null;"
                      class="flex flex-col items-center gap-3 p-4 rounded-xl border transition-all"
                      :class="form.booking_source === 'system' ? 'border-violet-500 bg-violet-500/20 text-white shadow-lg shadow-violet-500/20' : 'border-white/10 bg-white/5 text-gray-400 hover:bg-white/10'"
                    >
                      <Settings class="h-6 w-6" />
                      <span class="font-bold">حجز سيستم (System)</span>
                    </button>
                    <button 
                      type="button"
                      @click="form.booking_source = 'direct'; form.purchase_balance_source = 'carrier'; form.flight_system_id = null; form.flight_carrier_id = null;"
                      class="flex flex-col items-center gap-3 p-4 rounded-xl border transition-all"
                      :class="form.booking_source === 'direct' ? 'border-info/50 bg-info/20 text-white shadow-lg shadow-info/20' : 'border-white/10 bg-white/5 text-gray-400 hover:bg-white/10'"
                    >
                      <Plane class="h-6 w-6" />
                      <span class="font-bold">ساين (Airline)</span>
                    </button>
                    <button 
                      type="button"
                      @click="form.booking_source = 'group'; form.purchase_balance_source = 'group'; form.flight_system_id = null; form.flight_carrier_id = null; loadAllGroups();"
                      class="flex flex-col items-center gap-3 p-4 rounded-xl border transition-all"
                      :class="form.booking_source === 'group' ? 'border-amber-500 bg-amber-500/20 text-white shadow-lg shadow-amber-500/20' : 'border-white/10 bg-white/5 text-gray-400 hover:bg-white/10'"
                    >
                      <Users class="h-6 w-6" />
                      <span class="font-bold">حجز مجموعة (Group)</span>
                    </button>
                  </div>
                </div>

                <div class="space-y-6">
                  <!-- Case 1: System Booking -->
                  <div v-if="form.booking_source === 'system'" class="space-y-6">
                    <div>
                      <label class="block text-sm font-medium text-gray-300 mb-2">اختر النظام</label>
                      <select v-model="form.flight_system_id" @change="onSystemChange" class="flight-select">
                        <option value="">— اختر النظام —</option>
                        <option v-for="system in store.systems" :key="system.id" :value="system.id">{{ system.name }}</option>
                      </select>
                    </div>

                    <div v-if="form.flight_system_id" class="p-4 rounded-xl border border-violet-500/25 bg-violet-500/10 flex justify-between items-center">
                      <span class="text-sm text-gray-300">متاح النظام ({{ selectedFlightSystemName }}):</span>
                      <span class="text-lg font-bold text-violet-200">{{ formatMoney(selectedFlightSystemAvailable, selectedFlightSystem?.currency) }}</span>
                    </div>
                    <p class="text-xs text-text-muted text-center">
                      خط الطيران يُسجَّل في الخطوة التالية كمعلومة فقط — التكلفة تُخصم من رصيد النظام وليس من الساين.
                    </p>
                  </div>

                  <!-- Case 2: Direct Booking -->
                  <div v-if="form.booking_source === 'direct'" class="space-y-6">
                    <p class="text-sm text-text-muted text-center">
                      خط الطيران في الخطوة التالية — تكلفة الشراء تُخصم من رصيد الساين (حجز الساين الوحيد).
                    </p>
                  </div>

                  <!-- Case 3: Group Booking -->
                  <div v-if="form.booking_source === 'group'" class="space-y-6">
                    <div>
                      <label class="block text-sm font-medium text-gray-300 mb-2">اختر المجموعة / الشركة بالأجل <span class="text-error">*</span></label>
                      <select v-model="form.flight_group_id" :disabled="loadingGroups" class="flight-select">
                        <option value="">— اختر المجموعة —</option>
                        <option v-for="group in availableGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                      </select>
                    </div>
                    <p class="text-xs text-text-muted text-center">
                      خط الطيران يُسجَّل لاحقاً كمعلومة فقط — المديونية على حساب المجموعة وليس رصيد الساين.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </transition>

          <!-- Step 4: Passenger Details -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 4" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-success/15 p-3 text-success">
                    <Users class="h-6 w-6" />
                  </div>
                  <div>
                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">
                      Passenger repeater
                    </p>
                    <h2 class="flight-panel__title">العميل والمسافرون</h2>
                    <p class="flight-panel__subtitle">
                      {{
                        form.customer_type === 'counter'
                          ? 'جهة التعاقد (الشركة) للحسابات — أسماء المسافرين الإنجليزية للتذكرة في قسم منفصل'
                          : 'بيانات العميل العربية للحسابات — أسماء المسافرين الإنجليزية للتذكرة فقط'
                      }}
                    </p>
                  </div>
                </div>

                <!-- نوع العميل -->
                <div class="mb-6">
                  <label class="mb-2 block text-sm font-medium text-text-muted">
                    نوع العميل <span class="text-error">*</span>
                  </label>
                  <div class="grid grid-cols-2 gap-4">
                    <button
                      type="button"
                      @click="form.customer_type = 'regular'; form.customer = null;"
                      class="flex items-center justify-center gap-2 p-3 rounded-xl border transition-all"
                      :class="form.customer_type === 'regular' ? 'border-gold bg-gold/10 text-gold' : 'border-white/10 bg-white/5 text-text-muted'"
                    >
                      <User class="h-4 w-4" />
                      <span class="text-sm font-bold">عميل كوانتر</span>
                    </button>
                    <button
                      type="button"
                      @click="form.customer_type = 'counter'; form.customer = null;"
                      class="flex items-center justify-center gap-2 p-3 rounded-xl border transition-all"
                      :class="form.customer_type === 'counter' ? 'border-sky-500 bg-sky-500/10 text-sky-400' : 'border-white/10 bg-white/5 text-text-muted'"
                    >
                      <Building2 class="h-4 w-4" />
                      <span class="text-sm font-bold">عميل شركة</span>
                    </button>
                  </div>
                </div>

                <!-- ── القسم 1: بيانات العميل (للحسابات) ── -->
                <div
                  class="mb-6 space-y-4 rounded-2xl border p-6"
                  :class="form.customer_type === 'counter'
                    ? 'border-sky-500/30 bg-sky-500/5'
                    : 'border-gold/30 bg-gold/5'"
                >
                  <div class="flex items-start gap-3">
                    <div
                      class="rounded-xl p-3"
                      :class="form.customer_type === 'counter' ? 'bg-sky-500/15 text-sky-300' : 'bg-gold/15 text-gold'"
                    >
                      <Building2 v-if="form.customer_type === 'counter'" class="h-5 w-5" />
                      <User v-else class="h-5 w-5" />
                    </div>
                    <div>
                      <h3 class="text-base font-black text-white">
                        {{ form.customer_type === 'counter' ? 'جهة التعاقد (الشركة)' : 'بيانات العميل (للحسابات والمتابعة)' }}
                      </h3>
                      <p class="mt-1 text-xs leading-relaxed text-text-muted">
                        {{
                          form.customer_type === 'counter'
                            ? 'اسم الشركة أو المكتب المتعاقد — تُسجَّل عليه مديونية التذكرة. هذا ليس اسم المسافر.'
                            : 'الاسم الرباعي العربي يظهر في كشف الحساب — مختلف تماماً عن أسماء التذكرة الإنجليزية أدناه.'
                        }}
                      </p>
                    </div>
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">
                      {{ form.customer_type === 'counter' ? 'اختر الشركة *' : 'بحث عن العميل أو إضافة جديد *' }}
                    </label>
                    <CustomerSelect v-model="form.customer" :type="form.customer_type" />
                  </div>

                  <div
                    v-if="form.customer && form.customer_type === 'counter'"
                    class="rounded-xl border border-sky-500/25 bg-sky-500/10 p-4"
                  >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-text-muted block mb-1">اسم جهة التعاقد</span>
                        <span class="text-lg font-black text-sky-300">{{ form.customer.name || form.customer.full_name }}</span>
                        <span v-if="form.customer.phone" class="mt-1 block text-xs font-mono text-text-muted" dir="ltr">{{ form.customer.phone }}</span>
                      </div>
                      <div class="rounded-lg border border-sky-500/20 bg-black/20 px-3 py-2 text-[11px] text-sky-200/90 max-w-xs">
                        المديونية تُقيد على حساب هذه الشركة — وليس على اسم المسافر أدناه
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="form.customer && form.customer_type === 'regular'"
                    class="space-y-4 rounded-xl border border-gold/25 bg-gold/10 p-4"
                  >
                    <p class="text-[11px] text-gold/90">
                      أكمل أو عدّل بيانات العميل — ستُستخدم في كشف الحساب والمتابعة
                    </p>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-gray-300">الاسم الرباعي بالعربي <span class="text-error">*</span></label>
                        <input v-model="customerProfile.full_name" type="text" class="flight-input text-right" placeholder="مثال: ياسر محمود أحمد نوح" />
                      </div>
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">رقم التليفون <span class="text-error">*</span></label>
                        <input v-model="customerProfile.phone" type="text" class="flight-input" dir="ltr" placeholder="01xxxxxxxxx" />
                      </div>
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">الرقم القومي <span class="text-error">*</span></label>
                        <input v-model="customerProfile.national_id" type="text" maxlength="14" class="flight-input" dir="ltr" placeholder="14 رقم" />
                      </div>
                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">البلد <span class="text-error">*</span></label>
                        <input v-model="customerProfile.travel_country" type="text" class="flight-input text-right" placeholder="مثال: مصر" />
                      </div>
                    </div>
                  </div>
                </div>

                <!-- ── القسم 2: مرجع الحجز ── -->
                <div class="mb-6 space-y-4 rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                  <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-violet-500/15 p-3 text-violet-300">
                      <Plane class="h-5 w-5" />
                    </div>
                    <div>
                      <h3 class="text-base font-black text-white">مرجع الحجز</h3>
                      <p class="mt-0.5 text-xs text-text-muted">PNR والناقل — مشترك بين الشركة والمسافرين</p>
                    </div>
                  </div>

                  <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">
                      رقم الحجز (PNR) <span class="text-error">*</span>
                    </label>
                    <input
                      v-model="form.pnr"
                      type="text"
                      maxlength="50"
                      placeholder="مثال: S5TFR54"
                      class="flight-input"
                    />
                  </div>
                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">
                      خط الطيران (الناقل) <span class="text-error">*</span>
                    </label>
                    <select
                      v-if="form.booking_source === 'direct'"
                      v-model="form.flight_carrier_id"
                      :disabled="loadingCarriers"
                      class="flight-select"
                      @change="onCarrierChange"
                    >
                      <option value="">— اختر الخط —</option>
                      <option v-for="carrier in availableCarriers" :key="carrier.id" :value="carrier.id">
                        {{ carrier.name }}{{ form.booking_source === 'direct' ? ` (${carrier.currency})` : '' }}
                      </option>
                    </select>
                    <input
                      v-else
                      v-model="form.airline_name"
                      type="text"
                      class="flight-input"
                      placeholder="أدخل خط الطيران يدويًا (مثال: مصر للطيران)"
                    />
                  </div>
                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">موظف مسؤول</label>
                    <select v-model="form.employee_id" class="flight-select">
                      <option value="">— بدون —</option>
                      <option v-for="e in employeesList" :key="e.id" :value="e.id">
                        {{ employeeOptionLabel(e) }}
                      </option>
                    </select>
                  </div>
                </div>
                </div>

                <div
                  v-if="form.booking_source === 'direct' && resolvedCarrier"
                  class="mb-6 p-4 rounded-xl border border-info/30 bg-info/10 flex justify-between items-center"
                >
                  <span class="text-sm text-gray-300">الرصيد المتاح ({{ resolvedCarrier.name }}):</span>
                  <span class="text-lg font-bold text-info">{{ formatMoney(selectedCarrierAvailable, resolvedCarrier.currency) }}</span>
                </div>

                <!-- ── القسم 3: المسافرون على التذكرة ── -->
                <div class="space-y-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
                  <div class="flex items-start gap-3">
                    <div class="rounded-xl bg-emerald-500/15 p-3 text-emerald-300">
                      <Users class="h-5 w-5" />
                    </div>
                    <div>
                      <h3 class="text-base font-black text-white">المسافرون على التذكرة</h3>
                      <p class="mt-1 text-xs leading-relaxed text-text-muted">
                        أسماء إنجليزية للتذكرة فقط (أول + أخير + النوع) — بدون رقم قومي أو تاريخ ميلاد
                      </p>
                    </div>
                  </div>

                  <div
                    v-if="form.customer_type === 'counter' && form.customer"
                    class="rounded-lg border border-amber-500/25 bg-amber-500/10 px-4 py-3 text-xs text-amber-100/90"
                  >
                    <span class="font-bold">تذكير:</span>
                    جهة التعاقد =
                    <span class="font-black text-amber-200">{{ form.customer.name || form.customer.full_name }}</span>
                    — المسافر = الاسم الذي تدخله في البطاقات أدناه
                  </div>

                <!-- Passenger repeater -->
                <div class="space-y-6">
                  <div
                    v-if="store.passengerTypes?.length"
                    class="mb-6 flex flex-wrap gap-2 rounded-xl border border-white/10 bg-white/[0.03] p-3"
                  >
                    <span class="w-full text-[11px] font-semibold uppercase tracking-wide text-text-muted">
                      ملخص الأنواع
                    </span>
                    <span
                      v-for="pt in store.passengerTypes"
                      :key="pt.value"
                      class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-3 py-1.5 text-xs text-text-muted"
                    >
                      <span class="font-bold text-white">{{ pt.label }}</span>
                      <span class="rounded bg-black/25 px-1.5 py-0.5 font-mono text-gold">
                        {{ form.passengers.filter((p) => p.type === pt.value).length }}
                      </span>
                    </span>
                  </div>

                  <!-- Passenger repeater -->
                  <div v-if="!form.passengers.length" class="rounded-xl border border-dashed border-emerald-500/30 bg-black/20 p-8 text-center">
                    <Users class="mx-auto mb-3 h-10 w-10 text-emerald-400/60" />
                    <p class="text-sm font-bold text-white">أدخل أسماء المسافرين على التذكرة</p>
                    <p class="mt-1 text-xs text-text-muted">
                      الاسم الأول والأخير بالإنجليزي — مختلف عن بيانات العميل أعلاه
                    </p>
                    <button
                      type="button"
                      class="btn-airline-ghost mt-4 inline-flex items-center gap-2 px-5 py-2.5 text-sm"
                      @click="addPassenger('adult')"
                    >
                      <Plus class="h-4 w-4" />
                      إضافة مسافر (بالغ)
                    </button>
                  </div>

                  <div v-else class="space-y-4">
                    <div
                      v-for="(passenger, index) in form.passengers"
                      :key="passenger.uid"
                      class="space-y-4 rounded-xl border border-white/10 bg-white/5 p-6"
                    >
                      <div class="flex items-center justify-between gap-3">
                        <div>
                          <h4 class="font-bold text-white">
                            مسافر {{ index + 1 }}
                            <span class="mr-2 text-xs font-normal text-emerald-300/80">
                              ({{ getPassengerTypeLabel(passenger.type) }})
                            </span>
                          </h4>
                          <p v-if="form.customer_type === 'counter'" class="text-[10px] text-text-muted mt-0.5">
                            اسم على التذكرة — ليس اسم الشركة
                          </p>
                        </div>
                        <button
                          type="button"
                          class="rounded-lg p-2 transition-colors hover:bg-error/20"
                          @click="removePassenger(passenger)"
                        >
                          <Trash2 class="h-5 w-5 text-error" />
                        </button>
                      </div>

                      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                          <div>
                            <label class="mb-2 block text-sm font-medium text-gray-300">
                              الاسم الأول بالإنجليزي
                              <span class="text-error">*</span>
                            </label>
                            <input
                              v-model="passenger.first_name"
                              type="text"
                              placeholder="YASSER"
                              class="flight-input uppercase"
                              dir="ltr"
                            />
                          </div>
                          <div>
                            <label class="mb-2 block text-sm font-medium text-gray-300">
                              الاسم الأخير بالإنجليزي
                              <span class="text-error">*</span>
                            </label>
                            <input
                              v-model="passenger.last_name"
                              type="text"
                              placeholder="MOHAMED"
                              class="flight-input uppercase"
                              dir="ltr"
                            />
                          </div>
                        </div>

                        <div>
                          <label class="mb-2 block text-sm font-medium text-gray-300">
                            النوع <span class="text-error">*</span>
                          </label>
                          <select v-model="passenger.type" class="flight-select">
                            <option
                              v-for="pt in store.passengerTypes"
                              :key="pt.value"
                              :value="pt.value"
                            >
                              {{ pt.label }}
                            </option>
                          </select>
                        </div>

                        <div>
                          <label class="mb-2 block text-sm font-medium text-gray-300">حد الأمتعة (كجم)</label>
                          <input 
                            v-model.number="passenger.baggage_allowance_kg" 
                            type="number" 
                            min="0" 
                            placeholder="0"
                            class="flight-input" 
                            @wheel="$event.target.blur()"
                          />
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button
                      v-for="pt in store.passengerTypes"
                      :key="'add-' + pt.value"
                      type="button"
                      class="btn-airline-ghost inline-flex flex-1 items-center justify-center gap-2 px-4 py-3 text-sm sm:min-w-[140px]"
                      @click="addPassenger(pt.value)"
                    >
                      <Plus class="h-4 w-4" />
                      إضافة {{ pt.label }}
                    </button>
                  </div>
                </div>
                </div>
              </div>
            </div>
          </transition>

          <!-- Step 5: Pricing -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 5" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-gold/15 p-3 text-gold">
                    <DollarSign class="h-6 w-6" />
                  </div>
                  <div>
                    <h2 class="flight-panel__title">التسعير</h2>
                    <p class="flight-panel__subtitle">تحديد أسعار التذاكر</p>
                  </div>
                </div>

                <div class="space-y-6">
                  <!-- Currency Selection -->
                  <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">
                      عملة الشراء من المورد
                    </label>
                    <select
                      v-model="form.currency"
                      class="flight-select"
                      @change="onCurrencyChange"
                    >
                      <option
                        v-for="c in pricingCurrencyOptions"
                        :key="c.code"
                        :value="c.code"
                      >
                        {{ c.name }} ({{ c.code }})
                      </option>
                    </select>
                    <p class="mt-2 text-xs leading-relaxed text-text-muted">
                      اختر العملة التي اشتريت بها من المورد؛ سيتم التحويل تلقائياً للجنيه المصري.
                    </p>
                  </div>

                  <!-- Foreign Currency Input (Simpler) -->
                  <div v-if="form.currency !== 'EGP'" class="p-5 bg-white/5 border border-white/10 rounded-xl space-y-4">
                    <div class="flex items-center justify-between">
                      <h4 class="font-bold text-sky-300 text-sm">تكلفة الشراء ({{ form.currency }})</h4>
                    </div>

                    <div>
                      <input
                        v-model.number="form.purchase_price_foreign"
                        type="number"
                        step="0.01"
                        min="0"
                        :placeholder="'أدخل المبلغ بـ ' + form.currency"
                        class="flight-input text-lg font-bold text-center"
                        @wheel="$event.target.blur()"
                      />
                    </div>

                    <div class="flex items-center justify-between bg-black/20 p-3 rounded-lg border border-white/5">
                      <span class="text-xs text-gray-400">إجمالي التكلفة بالجنيه المصري:</span>
                      <span class="text-lg font-black text-gold">
                        {{ formatCurrency(form.purchase_price_egp) }}
                      </span>
                    </div>
                  </div>

                  <!-- EGP Pricing Section (Only if EGP selected) -->
                  <div v-if="form.currency === 'EGP'" class="space-y-4">
                    <div>
                      <label class="block text-sm font-medium text-gray-300 mb-2">
                        سعر الشراء (EGP) <span class="text-error">*</span>
                      </label>
                      <div class="relative">
                        <input
                          v-model.number="form.purchase_price_egp"
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
                          class="w-full rounded-xl border border-white/10 bg-white/5 py-3 pl-14 pr-4 text-white placeholder-gray-500 transition-all focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/50"
                          @wheel="$event.target.blur()"
                        />
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gray-400">ج.م</span>
                      </div>
                    </div>
                  </div>

                  <!-- Selling Price (Always shown) -->
                  <div class="space-y-4">
                    <div>
                      <label class="block text-sm font-medium text-gray-300 mb-2">
                        سعر البيع للعميل (EGP) <span class="text-error">*</span>
                      </label>
                      <div class="relative">
                        <input
                          v-model.number="form.selling_price"
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
                          class="w-full rounded-xl border border-white/10 bg-white/5 py-3 pl-14 pr-4 text-white placeholder-gray-500 transition-all focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/50"
                          @wheel="$event.target.blur()"
                        />
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gray-400">ج.م</span>
                      </div>
                    </div>
                  </div>

                  <!-- Profit breakdown (real-time) -->
                  <div
                    class="space-y-4 rounded-xl border p-6"
                    :class="
                      calculatedProfit >= 0
                        ? 'border-success/25 bg-success/10'
                        : 'border-error/25 bg-error/10'
                    "
                  >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <h4 class="font-bold" :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'">
                          تفصيل الربح (لحظي)
                        </h4>
                        <p class="mt-1 text-sm text-gray-400">يتحدث مع كل تعديل على الشراء أو البيع</p>
                      </div>
                      <div class="text-left sm:text-right">
                        <div
                          class="text-3xl font-black tabular-nums"
                          :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
                        >
                          <span v-if="calculatedProfit < 0">−</span>{{ formatCurrency(Math.abs(calculatedProfit)) }}
                        </div>
                        <div class="text-xs text-gray-400">صافي الربح بالجنيه</div>
                      </div>
                    </div>

                    <div class="space-y-2 rounded-lg border border-white/10 bg-black/20 p-4 text-sm">
                      <div class="flex justify-between gap-4">
                        <span class="text-gray-400">سعر الشراء (EGP)</span>
                        <span class="font-bold text-white tabular-nums">{{ formatCurrency(form.purchase_price_egp) }}</span>
                      </div>
                      <div class="flex justify-between gap-4">
                        <span class="text-gray-400">سعر البيع (EGP)</span>
                        <span class="font-bold text-white tabular-nums">{{ formatCurrency(form.selling_price) }}</span>
                      </div>
                      <div class="my-2 h-px bg-white/10"></div>
                      <div class="flex justify-between gap-4">
                        <span class="text-gray-400">الفرق</span>
                        <span
                          class="font-bold tabular-nums"
                          :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
                        >
                          {{ calculatedProfit >= 0 ? '+' : '−' }}{{ formatCurrency(Math.abs(calculatedProfit)) }}
                        </span>
                      </div>
                      <div v-if="profitMarginOnCost != null" class="flex justify-between gap-4">
                        <span class="text-gray-400">هامش على التكلفة</span>
                        <span class="font-mono font-bold text-gold">{{ profitMarginOnCost.toFixed(1) }}٪</span>
                      </div>
                      <div v-if="profitMarginOnSale != null" class="flex justify-between gap-4">
                        <span class="text-gray-400">هامش على البيع</span>
                        <span class="font-mono font-bold text-gold">{{ profitMarginOnSale.toFixed(1) }}٪</span>
                      </div>
                    </div>
                  </div>

                  <!-- Per Passenger Breakdown -->
                  <div v-if="form.passengers.length > 0" class="space-y-3 rounded-xl border border-white/10 bg-white/5 p-6">
                    <h4 class="font-bold text-white">حصة كل مسافر (بالجنيه)</h4>
                    <div class="space-y-2 text-sm">
                      <div class="flex items-center justify-between">
                        <span class="text-gray-400">شراء / مسافر</span>
                        <span class="font-bold text-white tabular-nums">
                          {{ formatCurrency(form.purchase_price_egp / pricingPassengerCount) }}
                        </span>
                      </div>
                      <div class="flex items-center justify-between">
                        <span class="text-gray-400">بيع / مسافر</span>
                        <span class="font-bold text-white tabular-nums">
                          {{ formatCurrency(form.selling_price / pricingPassengerCount) }}
                        </span>
                      </div>
                      <div class="flex items-center justify-between">
                        <span class="text-gray-400">ربح / مسافر</span>
                        <span
                          class="font-bold tabular-nums"
                          :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
                        >
                          {{ formatCurrency(calculatedProfit / pricingPassengerCount) }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </transition>

          <!-- Step 6: Payment & Account -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 6" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-warning/15 p-3 text-warning">
                    <CreditCard class="h-6 w-6" />
                  </div>
                  <div>
                    <h2 class="flight-panel__title">الدفع والحساب</h2>
                    <p class="flight-panel__subtitle">اختر حساب التحصيل إن وُجد، أو أكمل للحجز بالآجل</p>
                  </div>
                </div>

                <div class="space-y-6">
                  <!-- Regular Cash/Bank/Wallet Payment -->
                  <div v-if="form.customer_type !== 'counter'" class="space-y-6">
                    <div>
                      <p class="mb-3 text-xs font-semibold text-text-muted">نوع التحصيل</p>
                      <div class="flex flex-wrap gap-2">
                        <button
                          v-for="chip in settlementCategoryChips"
                          :key="chip.id"
                          type="button"
                          class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold transition"
                          :class="
                            settlementCategoryUi === chip.id
                              ? 'border-gold/50 bg-gold/15 text-gold'
                              : 'border-white/10 bg-white/[0.04] text-text-muted hover:border-white/20 hover:text-text-main'
                          "
                          @click="setSettlementCategory(chip.id)"
                        >
                          <component :is="chip.icon" class="h-3.5 w-3.5" :class="chip.iconClass" />
                          {{ chip.label }}
                        </button>
                      </div>

                      <label class="mb-2 mt-5 block text-sm font-medium text-gray-300">
                        {{ settlementPickerLabel }} <span v-if="form.initial_payment > 0" class="text-error">*</span>
                      </label>
                      <select
                        v-model="form.account_id"
                        class="flight-select"
                        :disabled="!settlementPickerOptions.length"
                      >
                        <option value="">
                          {{ settlementPickerEmptyText }}
                        </option>
                        <option
                          v-for="account in settlementPickerOptions"
                          :key="account.id"
                          :value="account.id"
                        >
                          {{ formatSettlementPickerOption(account) }}
                        </option>
                      </select>
                      <p v-if="selectedPaymentMethodLabel && form.account_id" class="mt-1.5 text-[11px] text-text-muted">
                        تسجيل الطريقة في النظام:
                        <span class="font-semibold text-sky-200/90">{{ selectedPaymentMethodLabel }}</span>
                      </p>
                      <p class="mt-2 text-xs leading-relaxed text-sky-200/80">
                        {{ paymentMethodSettlementHint }}
                      </p>
                      <div
                        class="mt-3 rounded-xl border border-sky-500/25 bg-sky-500/10 p-3 text-[11px] leading-relaxed text-sky-100/95"
                      >
                        <p class="font-bold text-sky-200">إضافة حسابات من لوحة الإدارة (Filament)</p>
                        <p class="mt-1 text-text-muted">
                          كل محفظة مسجّلة كحساب مستقل تظهر هنا بالاسم ورقم المحفظة. أنشئ حسابات بنكية أو محافظ مع
                          <span class="font-semibold text-white">وحدة العمل = سياحة</span>.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                          <a
                            :href="adminFilamentBankAccountsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 font-bold text-gold transition hover:border-gold/40 hover:bg-gold/10"
                          >
                            <Landmark class="h-3.5 w-3.5" />
                            حسابات بنوك
                          </a>
                          <a
                            :href="adminFilamentWalletAccountsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 font-bold text-sky-200 transition hover:border-sky-400/40 hover:bg-sky-500/15"
                          >
                            <Wallet class="h-3.5 w-3.5" />
                            محافظ إلكترونية
                          </a>
                        </div>
                      </div>

                      <p v-if="!settlementAccounts.length" class="mt-3 text-xs text-warning">
                        لم يتم تحميل أي حسابات مالية. أضف حسابات من Filament (روابط أعلاه).
                      </p>
                      <p
                        v-else-if="settlementAccounts.length && !settlementPickerOptions.length"
                        class="mt-3 text-xs text-warning"
                      >
                        لا يوجد حساب من هذا النوع. غيّر التصنيف أعلاه أو أنشئ حساباً مطابقاً من Filament.
                      </p>

                      <div
                        v-if="selectedSettlementAccount"
                        class="mt-4 space-y-2 rounded-xl border border-gold/25 bg-gold/10 p-4 text-sm"
                      >
                        <div class="text-[10px] font-bold uppercase tracking-wider text-gold/90">رصيد حساب التحصيل</div>
                        <div class="flex justify-between gap-2 text-text-muted">
                          <span>الرصيد الحالي</span>
                          <span class="font-mono font-bold text-white tabular-nums">
                            {{ formatMoney(settlementBalancePreview.current, settlementBalancePreview.currency) }}
                          </span>
                        </div>
                        <div
                          v-if="settlementBalancePreview.delta > 0"
                          class="flex justify-between gap-2 border-t border-white/10 pt-2"
                        >
                          <span class="flex items-center gap-1 text-success">
                            <ArrowUpRight class="h-4 w-4" />
                            بعد تسجيل الحجز (+ المدفوع)
                          </span>
                          <span class="font-mono text-base font-black text-success tabular-nums">
                            {{ formatMoney(settlementBalancePreview.after, settlementBalancePreview.currency) }}
                          </span>
                        </div>
                        <p v-else-if="!isEditMode" class="border-t border-white/10 pt-2 text-[11px] text-text-muted">
                          أدخل مبلغاً في «الدفع المبدئي» ليظهر تقدير الرصيد بعد الزيادة.
                        </p>
                        <p v-else class="border-t border-white/10 pt-2 text-[11px] text-text-muted">
                          عند التعديل تُسجَّل الدفعات من شاشة عرض الحجز، وليس من هنا.
                        </p>
                      </div>
                    </div>

                    <!-- Initial Payment -->
                    <div
                      v-if="!isEditMode"
                      class="space-y-4 rounded-xl border border-white/10 bg-white/5 p-6"
                    >
                      <h4 class="font-bold text-white">الدفع المبدئي (اختياري)</h4>
                      <p class="text-xs text-text-muted">
                        يُسجَّل بنفس طريقة التحصيل أعلاه. اترك المبلغ ٠ إن لم يتم استلام دفعة الآن.
                      </p>

                      <div>
                        <label class="mb-2 block text-sm font-medium text-gray-300">مبلغ الدفع</label>
                        <div class="relative">
                          <input
                            v-model.number="form.initial_payment"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 pl-14 text-white placeholder-gray-500 transition-all focus:border-gold focus:outline-none focus:ring-2 focus:ring-gold/50"
                            @wheel="$event.target.blur()"
                          />
                          <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gray-400">{{ settlementAccountCurrencySymbol }}</span>
                        </div>
                      </div>

                      <!-- Quick Amount Buttons -->
                      <div class="flex flex-wrap gap-2">
                        <button
                          v-for="percent in [25, 50, 75, 100]"
                          :key="percent"
                          type="button"
                          class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-gray-400 transition-all hover:border-gold/50 hover:bg-gold/20 hover:text-gold"
                          @click="form.initial_payment = (form.selling_price * percent) / 100"
                        >
                          {{ percent }}%
                        </button>
                      </div>
                    </div>

                    <!-- Regular customer ledger preview -->
                    <div
                      v-if="form.customer"
                      class="space-y-3 rounded-2xl border border-white/10 bg-white/5 p-6"
                    >
                      <div class="flex items-center gap-3">
                        <div class="rounded-xl bg-gold/10 p-3 text-gold">
                          <CreditCard class="h-5 w-5" />
                        </div>
                        <div>
                          <h4 class="font-bold text-white">رصيد العميل في الدفتر</h4>
                          <p class="text-xs text-text-muted">يُضاف قيمة التذكرة ويُخصم الدفع المبدئي عند الحفظ.</p>
                        </div>
                      </div>
                      <div class="divide-y divide-white/5 space-y-3">
                        <div class="flex justify-between items-center pt-1">
                          <span class="text-xs text-text-muted">الرصيد الحالي:</span>
                          <span :class="['font-mono text-sm', customerCurrentLedger.class]">
                            {{ customerCurrentLedger.text }}
                            <span v-if="customerCurrentLedger.label" class="text-[11px] font-sans mr-1">{{ customerCurrentLedger.label }}</span>
                          </span>
                        </div>
                        <div class="flex justify-between items-center pt-3">
                          <span class="text-xs text-text-muted">قيمة هذا الحجز:</span>
                          <span class="font-mono text-sm font-bold text-gold">
                            {{ Number(sellingPriceEgp || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} ج.م
                          </span>
                        </div>
                        <div v-if="form.initial_payment > 0" class="flex justify-between items-center pt-3">
                          <span class="text-xs text-text-muted">الدفع المبدئي:</span>
                          <span class="font-mono text-sm font-bold text-success">
                            − {{ Number(initialPaymentEgp || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} ج.م
                          </span>
                        </div>
                        <div class="flex justify-between items-center border-t border-white/10 pt-3">
                          <span class="text-xs font-bold text-white">الرصيد المتوقع بعد الحجز:</span>
                          <span :class="['font-mono text-base font-black', customerProjectedLedger.class]">
                            {{ customerProjectedLedger.text }}
                            <span v-if="customerProjectedLedger.label" class="text-[11px] font-sans mr-1">{{ customerProjectedLedger.label }}</span>
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- B2B credit details -->
                  <div v-else class="space-y-4">
                    <div class="rounded-2xl border border-sky-500/20 bg-sky-500/5 p-6 space-y-4">
                      <div class="flex items-center gap-3">
                        <div class="rounded-xl bg-sky-500/10 p-3 text-sky-400">
                          <Building2 class="h-6 w-6" />
                        </div>
                        <div>
                          <h4 class="font-bold text-white">البيع الآجل (حساب جهة التعاقد)</h4>
                          <p class="text-xs text-text-muted">سيتم قيد مديونية التذكرة مباشرة على رصيد الشركة.</p>
                        </div>
                      </div>

                      <div v-if="form.customer" class="divide-y divide-white/5 space-y-3 animate-in fade-in duration-200">
                        <div class="flex justify-between items-center pt-3">
                          <span class="text-xs text-text-muted">اسم جهة التعاقد:</span>
                          <span class="text-sm font-black text-sky-400">{{ form.customer.name }}</span>
                        </div>
                        <div class="flex justify-between items-center pt-3">
                          <span class="text-xs text-text-muted">الرصيد الحالي (المديونية):</span>
                          <span :class="['font-mono text-sm', counterCurrentLedger.class]">
                            {{ counterCurrentLedger.text }}
                            <span v-if="counterCurrentLedger.label" class="text-[11px] font-sans mr-1">{{ counterCurrentLedger.label }}</span>
                          </span>
                        </div>
                        <div class="flex justify-between items-center pt-3">
                          <span class="text-xs text-text-muted">قيمة الحجز الحالي:</span>
                          <span class="font-mono text-sm font-bold text-gold">
                            {{ Number(form.selling_price || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} ج.م
                          </span>
                        </div>
                        <div class="flex justify-between items-center pt-3 border-t border-white/10">
                          <span class="text-xs font-bold text-white">المديونية المتوقعة بعد الحجز:</span>
                          <span :class="['font-mono text-base font-black', counterProjectedLedger.class]">
                            {{ counterProjectedLedger.text }}
                            <span v-if="counterProjectedLedger.label" class="text-[11px] font-sans mr-1">{{ counterProjectedLedger.label }}</span>
                          </span>
                        </div>
                      </div>
                      <div v-else class="text-sm text-error font-medium">
                        * يرجى اختيار جهة التعاقد (الشركة) أولاً لرؤية الرصيد.
                      </div>
                    </div>
                  </div>

                  <!-- Notes -->
                  <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">
                      ملاحظات
                    </label>
                    <textarea
                      v-model="form.notes"
                      rows="4"
                      placeholder="أضف ملاحظات هنا (اختياري)"
                      class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all resize-none text-white placeholder-gray-500"
                    ></textarea>
                  </div>
                </div>
              </div>
            </div>
          </transition>

          <!-- Step 7: Review & Confirm -->
          <transition
            enter-active-class="transition-all duration-500"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
          >
            <div v-show="currentStep === 7" class="space-y-6">
              <div class="flight-panel">
                <div class="mb-6 flex items-center gap-3">
                  <div class="rounded-xl bg-gold/20 p-3 text-gold">
                    <FileText class="h-6 w-6" />
                  </div>
                  <div>
                    <h2 class="flight-panel__title">مراجعة الحجز</h2>
                    <p class="flight-panel__subtitle">راجع جميع البيانات قبل التأكيد</p>
                  </div>
                </div>

                <div class="space-y-6">
                  <!-- 1) الشركة / العميل — جهة البيع -->
                  <div
                    class="rounded-xl border p-6 space-y-4"
                    :class="form.customer_type === 'counter'
                      ? 'bg-sky-500/10 border-sky-500/25'
                      : 'bg-gold/10 border-gold/20'"
                  >
                    <h4 class="font-bold flex items-center gap-2" :class="form.customer_type === 'counter' ? 'text-sky-300' : 'text-gold'">
                      <Building2 v-if="form.customer_type === 'counter'" class="w-5 h-5" />
                      <User v-else class="w-5 h-5" />
                      {{ form.customer_type === 'counter' ? 'الشركة التي نبيع لها التذكرة' : 'بيانات العميل (للحسابات)' }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                      <div class="rounded-lg bg-black/20 p-3">
                        <span class="text-gray-400 block text-xs mb-1">
                          {{ form.customer_type === 'counter' ? 'اسم الشركة' : 'الاسم الرباعي بالعربي' }}
                        </span>
                        <span class="font-bold text-white text-base">
                          {{ form.customer_type === 'counter'
                            ? (form.customer?.name || form.customer?.full_name || '—')
                            : (customerProfile.full_name || '—') }}
                        </span>
                      </div>
                      <div class="rounded-lg bg-black/20 p-3">
                        <span class="text-gray-400 block text-xs mb-1">الهاتف</span>
                        <span class="font-mono font-bold text-white" dir="ltr">{{ customerProfile.phone || form.customer?.phone || '—' }}</span>
                      </div>
                      <div v-if="form.customer_type === 'regular'" class="rounded-lg bg-black/20 p-3">
                        <span class="text-gray-400 block text-xs mb-1">الرقم القومي</span>
                        <span class="font-mono font-bold text-white" dir="ltr">{{ customerProfile.national_id || '—' }}</span>
                      </div>
                      <div v-if="form.customer_type === 'regular'" class="rounded-lg bg-black/20 p-3">
                        <span class="text-gray-400 block text-xs mb-1">البلد</span>
                        <span class="font-bold text-white">{{ customerProfile.travel_country || '—' }}</span>
                      </div>
                      <div v-if="form.customer_type === 'counter'" class="sm:col-span-2 text-xs text-sky-200/80">
                        * المديونية تُسجَّل على حساب هذه الشركة — وليس على اسم المسافر أدناه
                      </div>
                      <div v-else class="sm:col-span-2 text-xs text-gold/80">
                        * هذا الاسم العربي يظهر في كشف الحساب — مختلف عن أسماء التذكرة الإنجليزية أدناه
                      </div>
                    </div>
                  </div>

                  <!-- 2) مصدر الشراء (مجموعة / سيستم / مباشر) -->
                  <div class="p-6 bg-white/5 border border-white/10 rounded-xl space-y-3">
                    <h4 class="font-bold text-white flex items-center gap-2">
                      <Settings class="w-5 h-5 text-violet-400" />
                      مصدر الحجز والشراء
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                      <div>
                        <span class="text-gray-400 block text-xs">طريقة الحجز</span>
                        <span class="text-white font-bold">{{ bookingSourceSummaryLabel }}</span>
                      </div>
                      <div v-if="form.booking_source === 'group' && selectedGroupName">
                        <span class="text-gray-400 block text-xs">مجموعة الشراء (بالأجل)</span>
                        <span class="text-sky-300 font-bold">{{ selectedGroupName }}</span>
                      </div>
                      <div v-if="form.booking_source === 'system' && selectedFlightSystemName">
                        <span class="text-gray-400 block text-xs">نظام الحجز</span>
                        <span class="text-white font-bold">{{ selectedFlightSystemName }}</span>
                      </div>
                      <div v-if="resolvedCarrier || form.airline_name">
                        <span class="text-gray-400 block text-xs">خط الطيران (الناقل)</span>
                        <span class="text-white font-bold">{{ resolvedCarrier ? resolvedCarrier.name : form.airline_name }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- 3) بيانات الرحلة -->
                  <div class="p-6 bg-white/5 border border-white/10 rounded-xl space-y-4">
                    <h4 class="font-bold text-white flex items-center gap-2">
                      <Plane class="w-5 h-5 text-gold" />
                      بيانات الرحلة
                    </h4>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <span class="text-gray-400 block text-xs">نوع الرحلة</span>
                        <span class="text-white font-bold">{{ getTripTypeLabel(form.trip_type) }}</span>
                      </div>
                      <div>
                        <span class="text-gray-400 block text-xs">رقم الحجز (PNR)</span>
                        <span class="font-mono font-bold text-white">{{ form.pnr || '—' }}</span>
                      </div>
                      <div v-if="form.trip_type !== 'multi_city'">
                        <span class="text-gray-400 block text-xs">من</span>
                        <span class="text-white font-bold">{{ form.from_airport?.iata_code || '—' }}</span>
                      </div>
                      <div v-if="form.trip_type !== 'multi_city'">
                        <span class="text-gray-400 block text-xs">إلى</span>
                        <span class="text-white font-bold">{{ form.to_airport?.iata_code || '—' }}</span>
                      </div>
                      <div v-if="form.trip_type !== 'multi_city'">
                        <span class="text-gray-400 block text-xs">تاريخ المغادرة</span>
                        <span class="text-white font-bold">{{ form.departure_date || '—' }}</span>
                      </div>
                      <div v-if="form.trip_type !== 'multi_city' && form.departure_time">
                        <span class="text-gray-400">وقت الذهاب:</span>
                        <span class="mr-2 font-mono font-bold text-white" dir="ltr">
                          {{ form.departure_time }} → {{ form.arrival_time || '-' }}
                        </span>
                      </div>
                      <div v-if="form.trip_type === 'round_trip'">
                        <span class="text-gray-400 block text-xs">تاريخ العودة</span>
                        <span class="text-white font-bold">{{ form.return_date || '—' }}</span>
                      </div>
                      <div v-if="form.trip_type === 'round_trip' && form.return_time">
                        <span class="text-gray-400">وقت العودة:</span>
                        <span class="mr-2 font-mono font-bold text-white" dir="ltr">
                          {{ form.return_time }} → {{ form.return_arrival_time || '-' }}
                        </span>
                      </div>
                      <div v-if="form.trip_type === 'multi_city'" class="col-span-2 space-y-2">
                        <span class="text-gray-400 block text-xs">قطع الرحلة:</span>
                        <div
                          v-for="(leg, idx) in form.legs"
                          :key="leg.uid"
                          class="rounded-lg border border-white/10 bg-black/20 p-3 text-xs"
                        >
                          <div class="font-bold text-gold">القطعة {{ idx + 1 }}</div>
                          <div class="mt-1 font-mono text-white" dir="ltr">
                            {{ leg.from_airport?.iata_code || '?' }} → {{ leg.to_airport?.iata_code || '?' }}
                          </div>
                          <div class="mt-1 text-text-muted">
                            {{ leg.departure_date || '-' }}
                            ·
                            {{ leg.departure_time || '-' }} → {{ leg.arrival_time || '-' }}
                          </div>
                        </div>
                      </div>
                      <div v-if="form.employee_id">
                        <span class="text-gray-400 block text-xs">الموظف المسؤول</span>
                        <span class="font-bold text-white">{{ selectedEmployeeLabel }}</span>
                      </div>
                      <div v-if="form.passengers.reduce((sum, p) => sum + (Number(p.baggage_allowance_kg) || 0), 0) > 0">
                        <span class="text-gray-400">إجمالي الأمتعة:</span>
                        <span class="mr-2 font-bold text-white">{{ form.passengers.reduce((sum, p) => sum + (Number(p.baggage_allowance_kg) || 0), 0) }} كجم</span>
                      </div>
                    </div>
                  </div>

                  <!-- 4) المسافرون — أسماء على التذكرة -->
                  <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-6 space-y-4">
                    <h4 class="font-bold text-emerald-300 flex items-center gap-2">
                      <Users class="w-5 h-5" />
                      {{ form.customer_type === 'counter' ? 'المسافرون على التذكرة' : 'المسافرون' }}
                      ({{ form.passengers.length }})
                    </h4>
                    <p v-if="form.customer_type === 'counter'" class="text-xs text-emerald-200/70">
                      هؤلاء هم من سيسافرون فعلاً — مختلفون عن شركة
                      «{{ form.customer?.name || form.customer?.full_name }}»
                    </p>
                    <CompactPassengerList :passengers="form.passengers" />
                  </div>

                  <!-- Pricing Summary -->
                  <div class="space-y-4 rounded-xl border border-success/25 bg-success/10 p-6">
                    <h4 class="flex items-center gap-2 font-bold text-success">
                      <DollarSign class="h-5 w-5" />
                      ملخص التسعير والربح
                    </h4>
                    <div v-if="form.currency !== 'EGP'" class="rounded-lg border border-white/10 bg-black/15 p-3 text-xs text-gray-300">
                      <div class="flex justify-between gap-2">
                        <span>شراء المورد</span>
                        <span class="font-mono font-bold text-white" dir="ltr">
                          {{ formatMoney(form.purchase_price_foreign, form.currency) }}
                        </span>
                      </div>
                      <div class="mt-1 flex justify-between gap-2">
                        <span>سعر الصرف → EGP</span>
                        <span class="font-mono text-gold" dir="ltr">{{ Number(form.exchange_rate) || 0 }}</span>
                      </div>
                    </div>
                    <div class="space-y-2 text-sm">
                      <div class="flex items-center justify-between">
                        <span class="text-gray-300">سعر الشراء (EGP)</span>
                        <span class="font-bold text-white tabular-nums">{{ formatCurrency(form.purchase_price_egp) }}</span>
                      </div>
                      <div class="flex items-center justify-between">
                        <span class="text-gray-300">سعر البيع (EGP)</span>
                        <span class="font-bold text-white tabular-nums">{{ formatCurrency(form.selling_price) }}</span>
                      </div>
                      <div class="my-2 h-px bg-white/10"></div>
                      <div class="flex items-center justify-between">
                        <span class="text-gray-300">صافي الربح</span>
                        <span
                          class="text-lg font-black tabular-nums"
                          :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
                        >
                          <span v-if="calculatedProfit < 0">−</span>{{ formatCurrency(Math.abs(calculatedProfit)) }}
                        </span>
                      </div>
                      <div v-if="profitMarginOnCost != null" class="flex justify-between text-xs text-gray-400">
                        <span>هامش على التكلفة</span>
                        <span class="font-mono text-gold">{{ profitMarginOnCost.toFixed(1) }}٪</span>
                      </div>
                      <div v-if="profitMarginOnSale != null" class="flex justify-between text-xs text-gray-400">
                        <span>هامش على البيع</span>
                        <span class="font-mono text-gold">{{ profitMarginOnSale.toFixed(1) }}٪</span>
                      </div>
                    </div>
                  </div>

                  <!-- Payment/Debt Summary -->
                  <div class="p-6 rounded-xl space-y-4" :class="(initialPaymentEgp || 0) >= sellingPriceEgp ? 'bg-success/10 border border-success/20' : 'bg-warning/10 border border-warning/20'">
                    <h4 class="font-bold flex items-center gap-2" :class="(initialPaymentEgp || 0) >= sellingPriceEgp ? 'text-success' : 'text-warning'">
                      <CreditCard class="w-5 h-5" />
                      {{ (initialPaymentEgp || 0) <= 0 ? 'حجز بالآجل (مديونية كاملة)' : ((initialPaymentEgp || 0) < sellingPriceEgp ? 'دفع جزئي' : 'دفع كامل') }}
                    </h4>
                    <div class="space-y-2">
                      <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-300">إجمالي المطلوب EGP:</span>
                        <span class="text-white font-bold">{{ formatCurrency(sellingPriceEgp) }}</span>
                      </div>
                      <div v-if="form.initial_payment > 0" class="flex items-center justify-between text-sm">
                        <span class="text-gray-300">المبلغ المدفوع الآن:</span>
                        <span class="text-success font-bold">
                          {{ formatCurrency(initialPaymentEgp) }}
                          <span v-if="selectedSettlementAccount && selectedSettlementAccount.currency !== 'EGP'" class="text-xs text-gray-400">
                            ({{ Number(form.initial_payment).toFixed(2) }} {{ selectedSettlementAccount.currency }})
                          </span>
                        </span>
                      </div>
                      <div class="flex items-center justify-between text-sm pt-2 border-t border-white/5">
                        <span class="text-gray-300">
                          {{ form.customer_type === 'counter' ? 'المتبقي (مديونية على الشركة):' : 'المتبقي (مديونية على العميل):' }}
                        </span>
                        <span class="text-error font-black text-lg">
                          {{ formatCurrency(sellingPriceEgp - (initialPaymentEgp || 0)) }}
                          <span v-if="selectedSettlementAccount && selectedSettlementAccount.currency !== 'EGP'" class="text-xs text-gray-400">
                            ({{ (Number(form.selling_price) - Number(form.initial_payment)).toFixed(2) }} {{ selectedSettlementAccount.currency }})
                          </span>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </transition>

          <div
            v-if="currentStep < 7 && stepMissingFields.length"
            class="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-3 text-sm text-amber-100"
          >
            <p class="mb-1 font-bold">بيانات ناقصة قبل المتابعة:</p>
            <ul class="list-inside list-disc space-y-0.5 text-xs text-amber-100/90">
              <li v-for="item in stepMissingFields" :key="item">{{ item }}</li>
            </ul>
          </div>

          <!-- Navigation Buttons -->
          <div class="flex flex-col gap-4 border-t border-white/10 pt-8 sm:flex-row sm:items-center sm:justify-between">
            <button
              v-if="currentStep > 1"
              type="button"
              @click="previousStep"
              :disabled="loading"
              class="btn-airline-ghost order-2 px-8 py-3 sm:order-1"
            >
              <ArrowRight class="h-5 w-5" />
              السابق
            </button>

            <div v-else class="hidden sm:block sm:order-1"></div>

            <button
              v-if="currentStep < 7"
              type="button"
              @click="nextStep"
              :disabled="loading"
              :class="[
                'btn-airline order-1 w-full px-8 py-3 sm:order-2 sm:w-auto',
                !canProceed && 'opacity-45',
              ]"
            >
              <span v-if="loading" class="flex items-center gap-2">
                <Loader2 class="h-5 w-5 animate-spin" />
                جاري التحميل...
              </span>
              <span v-else class="flex items-center gap-2">
                التالي
                <ArrowLeft class="h-5 w-5" />
              </span>
            </button>

            <button
              v-else
              type="button"
              @click="submitBooking"
              :disabled="loading || !canSubmit"
              class="order-1 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-success to-emerald-500 px-8 py-3.5 font-black text-black shadow-lg shadow-success/25 transition-all hover:from-success hover:to-emerald-400 disabled:cursor-not-allowed disabled:opacity-45 sm:order-2 sm:w-auto"
            >
              <span v-if="loading" class="flex items-center gap-2">
                <Loader2 class="h-5 w-5 animate-spin" />
                {{ submitLoadingLabel }}
              </span>
              <span v-else class="flex items-center gap-2">
                <Check class="h-5 w-5" />
                {{ submitActionLabel }}
              </span>
            </button>

            <!-- Missing steps hint (shown when form is incomplete on Step 7) -->
            <div v-if="currentStep === 7 && !canSubmit && !loading" class="order-3 w-full sm:w-auto">
              <div class="rounded-xl border border-error/30 bg-error/10 px-4 py-3 text-sm text-error">
                <p class="font-bold mb-1">⚠️ يوجد بيانات ناقصة:</p>
                <ul class="list-disc list-inside space-y-0.5 text-xs text-error/90">
                  <li v-if="!isBookingStepComplete(1)">نوع الرحلة غير محدد</li>
                  <li v-if="!isBookingStepComplete(2)">المسار أو التواريخ ناقصة</li>
                  <li v-if="!isBookingStepComplete(3)">مصدر الحجز غير محدد</li>
                  <template v-if="!isBookingStepComplete(4)">
                    <li v-for="item in getStep4MissingFields()" :key="'s4-' + item">{{ item }}</li>
                    <li v-if="!getStep4MissingFields().length">بيانات العميل أو المسافرين أو مرجع الحجز ناقصة</li>
                  </template>
                  <li v-if="!isBookingStepComplete(5)">التسعير (شراء / بيع) غير مكتمل</li>
                  <li v-if="!isBookingStepComplete(6)">بيانات الدفع غير مكتملة</li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <!-- Sticky Summary Panel (Right 1/3) -->
        <div class="lg:col-span-1">
          <div class="sticky top-24 space-y-6">
            <!-- Live Summary Card -->
            <div class="flight-panel !p-6">
              <h3 class="mb-4 flex items-center gap-2 text-lg font-bold text-text-main">
                <FileText class="w-5 h-5 text-gold" />
                ملخص الحجز
              </h3>

              <!-- Live state panel (synced with form + step gates) -->
              <div class="mb-4 space-y-2 rounded-xl border border-sky-500/30 bg-gradient-to-br from-sky-500/15 to-transparent p-4">
                <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-sky-300/90">
                  <Activity class="h-3.5 w-3.5 animate-pulse" />
                  تحديث لحظي
                </div>
                <div class="flex items-center justify-between gap-2 text-sm">
                  <span class="text-text-muted">الخطوة</span>
                  <span class="font-bold text-sky-100">{{ getStepLabel(currentStep) }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 text-sm border-t border-white/5 pt-2">
                  <span class="text-text-muted">مصدر الحجز</span>
                  <span class="font-bold text-sky-100 text-left">{{ bookingSourceSummaryLabel }}</span>
                </div>
              </div>

              <div
                v-if="form.booking_source === 'system' || form.booking_source === 'group' || resolvedCarrier"
                class="space-y-4"
              >
                <div
                  v-if="
                    (form.booking_source === 'system' && form.flight_system_id) ||
                    (form.booking_source === 'direct' && resolvedCarrier) ||
                    (form.booking_source === 'group' && form.flight_group_id)
                  "
                  class="p-4 bg-gold/10 border border-gold/30 rounded-xl space-y-2 text-sm"
                >
                  <div v-if="form.booking_source === 'group'" class="flex justify-between gap-2">
                    <span class="text-gray-400 shrink-0">المجموعة</span>
                    <span class="text-left font-bold text-white">{{ selectedGroupName || '—' }}</span>
                  </div>
                  <div v-if="form.booking_source === 'system' && store.systems?.length" class="flex justify-between gap-2">
                    <span class="text-gray-400 shrink-0">نظام الحجز</span>
                    <span class="text-left font-bold text-white">{{ selectedFlightSystemName || '—' }}</span>
                  </div>
                  <template v-if="selectedFlightSystem && form.booking_source === 'system'">
                    <div class="flex justify-between gap-2 border-t border-white/10 pt-2">
                      <span class="text-gray-400 shrink-0">رصيد النظام</span>
                      <span class="font-mono font-bold text-white tabular-nums">
                        {{ Number(selectedFlightSystem.balance ?? 0).toLocaleString() }}
                        {{ selectedFlightSystem.currency }}
                      </span>
                    </div>
                    <div class="flex justify-between gap-2">
                      <span class="text-gray-400 shrink-0">حد ائتمان النظام</span>
                      <span class="font-mono text-white tabular-nums">
                        {{ Number(selectedFlightSystem.credit_limit ?? 0).toLocaleString() }}
                        {{ selectedFlightSystem.currency }}
                      </span>
                    </div>
                    <div class="flex justify-between gap-2">
                      <span class="text-gray-400 shrink-0">متاح النظام</span>
                      <span class="font-mono font-bold text-gold tabular-nums">
                        {{ selectedFlightSystemAvailable.toLocaleString() }}
                        {{ selectedFlightSystem.currency }}
                      </span>
                    </div>
                    <div
                      v-if="form.purchase_balance_source === 'system' && systemPurchaseDebitPreview > 0 && systemCurrencyMatchesBooking"
                      class="flex justify-between gap-2 border-t border-white/10 pt-2 text-[11px]"
                    >
                      <span class="text-gray-400 shrink-0">شراء على المصدر</span>
                      <span class="font-mono text-white tabular-nums" dir="ltr">
                        −{{ Number(systemPurchaseDebitPreview).toLocaleString('ar-EG') }}
                        {{ selectedFlightSystem.currency }}
                      </span>
                    </div>
                    <div
                      v-if="form.purchase_balance_source === 'system' && projectedSystemAvailableAfterBooking != null"
                      class="flex justify-between gap-2 text-[11px]"
                    >
                      <span class="text-gray-400 shrink-0">متاح النظام بعد الحجز</span>
                      <span
                        class="font-mono font-bold tabular-nums"
                        :class="projectedSystemAvailableAfterBooking < 0 ? 'text-error' : 'text-success'"
                      >
                        {{ Number(projectedSystemAvailableAfterBooking).toLocaleString('ar-EG') }}
                        {{ selectedFlightSystem.currency }}
                      </span>
                    </div>
                  </template>
                  <div v-if="resolvedCarrier || form.airline_name" class="flex justify-between">
                    <span class="text-gray-400">{{ form.booking_source === 'direct' ? 'الساين' : 'خط الطيران' }}</span>
                    <span class="text-white font-bold">{{ resolvedCarrier ? resolvedCarrier.name : form.airline_name }}</span>
                  </div>
                  <div v-if="resolvedCarrier && form.booking_source === 'direct'" class="flex justify-between">
                    <span class="text-gray-400">الرصيد / العملة</span>
                    <span class="text-gold font-mono font-bold">{{ finiteNum(selectedCarrierAvailable).toLocaleString() }} {{ resolvedCarrier.currency || 'EGP' }}</span>
                  </div>
                  <div
                    v-if="form.purchase_balance_source === 'carrier' && resolvedCarrier && carrierPurchaseDebitPreview > 0 && carrierCurrencyMatchesBooking"
                    class="flex justify-between gap-2 border-t border-white/10 pt-2 text-[11px]"
                  >
                    <span class="text-gray-400 shrink-0">شراء على الساين</span>
                    <span class="font-mono text-white tabular-nums" dir="ltr">
                      −{{ Number(carrierPurchaseDebitPreview).toLocaleString('ar-EG') }}
                      {{ resolvedCarrier.currency }}
                    </span>
                  </div>
                  <div
                    v-if="form.purchase_balance_source === 'carrier' && projectedCarrierAvailableAfterBooking != null"
                    class="flex justify-between gap-2 text-[11px]"
                  >
                    <span class="text-gray-400 shrink-0">متاح الساين بعد الحجز</span>
                    <span
                      class="font-mono font-bold tabular-nums"
                      :class="projectedCarrierAvailableAfterBooking < 0 ? 'text-error' : 'text-success'"
                    >
                      {{ Number(projectedCarrierAvailableAfterBooking).toLocaleString('ar-EG') }}
                      {{ resolvedCarrier.currency }}
                    </span>
                  </div>
                </div>
                <!-- Trip Info -->
                <div class="p-4 bg-white/5 rounded-xl space-y-2">
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-400">نوع الرحلة:</span>
                    <span class="text-white font-bold">{{ getTripTypeLabel(form.trip_type) }}</span>
                  </div>
                  <div v-if="routeSummaryLabel" class="flex items-center justify-between text-sm">
                    <span class="text-gray-400">المسار:</span>
                    <span class="font-bold font-mono text-white" dir="ltr">{{ routeSummaryLabel }}</span>
                  </div>
                  <div
                    v-if="form.departure_date || (form.trip_type === 'multi_city' && form.legs[0]?.departure_date)"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-gray-400">المغادرة:</span>
                    <span class="text-white font-bold">
                      {{ formatDate(form.trip_type === 'multi_city' ? form.legs[0]?.departure_date : form.departure_date) }}
                    </span>
                  </div>
                  <div
                    v-if="form.trip_type !== 'multi_city' && form.departure_time"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-gray-400">وقت الذهاب:</span>
                    <span class="font-bold font-mono text-white" dir="ltr">
                      {{ form.departure_time }} → {{ form.arrival_time || '—' }}
                    </span>
                  </div>
                  <div
                    v-if="form.trip_type === 'round_trip' && form.return_date"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-gray-400">العودة:</span>
                    <span class="text-white font-bold">{{ formatDate(form.return_date) }}</span>
                  </div>
                  <div
                    v-if="form.trip_type === 'round_trip' && form.return_time"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-gray-400">وقت العودة:</span>
                    <span class="font-bold font-mono text-white" dir="ltr">
                      {{ form.return_time }} → {{ form.return_arrival_time || '—' }}
                    </span>
                  </div>
                  <div
                    v-if="form.trip_type === 'multi_city' && form.legs.length"
                    class="space-y-1 rounded-lg border border-white/10 bg-black/20 p-3 text-xs"
                  >
                    <div class="font-bold text-gold">قطع الرحلة ({{ form.legs.length }})</div>
                    <div
                      v-for="(leg, idx) in form.legs"
                      :key="leg.uid"
                      class="flex justify-between gap-2 text-text-muted"
                    >
                      <span>{{ idx + 1 }}.</span>
                      <span class="font-mono text-white" dir="ltr">
                        {{ leg.from_airport?.iata_code || '?' }} → {{ leg.to_airport?.iata_code || '?' }}
                      </span>
                    </div>
                  </div>
                </div>

                <div
                  v-if="selectedCustomerDisplay"
                  class="flex items-center justify-between gap-2 rounded-xl border p-3 text-sm"
                  :class="form.customer_type === 'counter'
                    ? 'border-sky-500/25 bg-sky-500/10'
                    : 'border-white/10 bg-white/[0.04]'"
                >
                  <span class="text-gray-400 shrink-0">
                    {{ form.customer_type === 'counter' ? 'جهة التعاقد' : 'العميل' }}
                  </span>
                  <span
                    class="truncate text-left font-bold"
                    :class="form.customer_type === 'counter' ? 'text-sky-300' : 'text-white'"
                    :title="selectedCustomerDisplay"
                  >
                    {{ selectedCustomerDisplay }}
                  </span>
                </div>

                <div
                  v-if="form.passengers.some((p) => (p.first_name || p.last_name))"
                  class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3"
                >
                  <div class="mb-2 text-xs font-bold text-emerald-300">المسافرون</div>
                  <CompactPassengerList
                    :passengers="form.passengers.filter((x) => x.first_name || x.last_name)"
                    :show-header="false"
                  />
                </div>

                <div
                  v-if="form.payment_method || form.account_id"
                  class="space-y-2 rounded-xl border border-warning/25 bg-warning/10 p-3 text-xs"
                >
                  <div class="font-bold text-warning">التحصيل</div>
                  <div class="flex justify-between gap-2 text-text-muted">
                    <span>الطريقة</span>
                    <span class="font-bold text-white">{{ selectedPaymentMethodLabel }}</span>
                  </div>
                  <div v-if="selectedSettlementAccountDisplay" class="flex justify-between gap-2 text-text-muted">
                    <span>الحساب</span>
                    <span class="max-w-[65%] truncate text-left font-bold text-white" dir="rtl">
                      {{ selectedSettlementAccountDisplay }}
                    </span>
                  </div>
                  <div
                    v-if="settlementBalancePreview && selectedSettlementAccount"
                    class="space-y-1 border-t border-white/10 pt-2"
                  >
                    <div class="flex justify-between gap-2 text-text-muted">
                      <span>رصيد الحساب</span>
                      <span class="font-mono font-bold text-white tabular-nums">
                        {{ formatMoney(settlementBalancePreview.current, settlementBalancePreview.currency) }}
                      </span>
                    </div>
                    <div
                      v-if="settlementBalancePreview.delta > 0"
                      class="flex justify-between gap-2 text-success"
                    >
                      <span class="inline-flex items-center gap-0.5 font-bold">
                        <span class="text-base leading-none">+</span>
                        بعد التسجيل
                      </span>
                      <span class="font-mono font-bold tabular-nums">
                        {{ formatMoney(settlementBalancePreview.after, settlementBalancePreview.currency) }}
                      </span>
                    </div>
                  </div>
                </div>

                <div
                  v-if="customerCollectionStatus && sellingPriceEgp > 0"
                  class="rounded-xl border p-3 text-xs"
                  :class="{
                    'border-success/30 bg-success/10 text-success': customerCollectionStatus.variant === 'success',
                    'border-info/30 bg-info/10 text-info': customerCollectionStatus.variant === 'info',
                    'border-warning/30 bg-warning/10 text-warning': customerCollectionStatus.variant === 'warning',
                  }"
                >
                  <div class="font-bold">{{ customerCollectionStatus.label }}</div>
                  <p v-if="customerCollectionStatus.hint" class="mt-1 text-[11px] opacity-90">
                    {{ customerCollectionStatus.hint }}
                  </p>
                </div>

                <!-- Passengers Count -->
                <div class="p-4 bg-white/5 rounded-xl">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-400 text-sm">عدد المسافرين:</span>
                    <span class="text-white font-bold text-lg">{{ form.passengers.length }}</span>
                  </div>
                  <div class="flex flex-wrap gap-2 text-xs">
                    <span
                      v-for="pt in store.passengerTypes"
                      :key="pt.value"
                      v-show="form.passengers.filter(p => p.type === pt.value).length > 0"
                      class="px-2 py-1 bg-white/10 rounded-lg text-gray-400"
                    >
                      {{ form.passengers.filter(p => p.type === pt.value).length }} {{ pt.label }}
                    </span>
                  </div>
                </div>

                <!-- Pricing live (sidebar) -->
                <div
                  v-if="form.purchase_price_egp > 0 || sellingPriceEgp > 0"
                  class="space-y-3 rounded-xl border border-success/20 bg-success/10 p-4"
                >
                  <p class="text-[10px] font-semibold uppercase tracking-wider text-success/90">حساب لحظي</p>
                  <div v-if="form.currency !== 'EGP' && (form.purchase_price_foreign > 0 || form.exchange_rate > 0)" class="text-xs text-gray-400">
                    <div class="flex justify-between gap-2">
                      <span>شراء ({{ form.currency }})</span>
                      <span class="font-mono text-white" dir="ltr">
                        {{ formatMoney(form.purchase_price_foreign, form.currency) }}
                      </span>
                    </div>
                    <div class="mt-1 flex justify-between gap-2">
                      <span>سعر الصرف</span>
                      <span class="font-mono text-gold" dir="ltr">{{ Number(form.exchange_rate) || 0 }}</span>
                    </div>
                  </div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">شراء EGP</span>
                    <span class="font-bold tabular-nums text-white">{{ formatCurrency(form.purchase_price_egp) }}</span>
                  </div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">بيع EGP</span>
                    <span class="font-bold tabular-nums text-white">{{ formatCurrency(sellingPriceEgp) }}</span>
                  </div>
                  <div class="h-px bg-success/20"></div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300 font-bold">الربح</span>
                    <span
                      class="text-lg font-black tabular-nums"
                      :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
                    >
                      <span v-if="calculatedProfit < 0">−</span>{{ formatCurrency(Math.abs(calculatedProfit)) }}
                    </span>
                  </div>
                  <div
                    v-if="profitMarginOnSale != null && sellingPriceEgp > 0"
                    class="flex justify-between text-[11px] text-gray-400"
                  >
                    <span>هامش / البيع</span>
                    <span class="font-mono text-gold">{{ profitMarginOnSale.toFixed(1) }}٪</span>
                  </div>
                </div>

                <!-- Payment Status -->
                <div
                  v-if="!isEditMode && form.initial_payment > 0"
                  class="space-y-2 rounded-xl border border-info/20 bg-info/10 p-4"
                >
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">المدفوع:</span>
                    <span class="font-bold text-info">
                      {{ formatCurrency(initialPaymentEgp) }}
                      <span v-if="selectedSettlementAccount && selectedSettlementAccount.currency !== 'EGP'" class="text-[11px] text-gray-400">
                        ({{ Number(form.initial_payment).toFixed(2) }} {{ selectedSettlementAccount.currency }})
                      </span>
                    </span>
                  </div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">المتبقي:</span>
                    <span class="font-bold text-white">
                      {{ formatCurrency(sellingPriceEgp - initialPaymentEgp) }}
                      <span v-if="selectedSettlementAccount && selectedSettlementAccount.currency !== 'EGP'" class="text-[11px] text-gray-400">
                        ({{ (Number(form.selling_price) - Number(form.initial_payment)).toFixed(2) }} {{ selectedSettlementAccount.currency }})
                      </span>
                    </span>
                  </div>
                </div>
                <div
                  v-else-if="isEditMode && editTotalPaid > 0"
                  class="space-y-2 rounded-xl border border-info/20 bg-info/10 p-4"
                >
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-info/90">مدفوعات مسجّلة</p>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">المدفوع:</span>
                    <span class="font-bold text-info">{{ formatCurrency(editTotalPaid) }}</span>
                  </div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-300">المتبقي:</span>
                    <span class="font-bold text-white">{{ formatCurrency(Math.max(0, sellingPriceEgp - editTotalPaid)) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Progress checklist (sidebar) -->
            <div class="flight-panel !p-5">
              <h4 class="mb-1 text-xs font-bold uppercase tracking-wider text-text-muted">خطوات الحجز</h4>
              <p class="mb-4 text-[10px] text-text-muted/80">علامة الصح تعتمد على البيانات وليس ترتيب التنقل فقط.</p>
              <div class="space-y-3">
                <div
                  v-for="step in 7"
                  :key="step"
                  :class="[
                    'flex items-center gap-3 text-sm',
                    currentStep === step ? 'text-gold' : isBookingStepComplete(step) ? 'text-success' : 'text-gray-500',
                  ]"
                >
                  <div
                    :class="[
                      'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                      currentStep === step
                        ? 'bg-gold text-black'
                        : isBookingStepComplete(step)
                          ? 'bg-success/25 text-success'
                          : 'bg-white/10 text-gray-500',
                    ]"
                  >
                    <Check v-if="isBookingStepComplete(step)" class="h-4 w-4" />
                    <span v-else>{{ step }}</span>
                  </div>
                  <span>{{ getStepLabel(step) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useFlightStore } from '@/stores/flightStore';
import axios from 'axios';
import AirportSearchInput from '@/components/flights/AirportSearchInput.vue';
import TimePicker from '@/components/flights/TimePicker.vue';
import CustomerSelect from '@/components/flights/CustomerSelect.vue';
import CompactPassengerList from '@/components/flights/CompactPassengerList.vue';
import { passengerFirstName, passengerLastName } from '@/utils/flightPassengerDisplay';
import { fetchSettlementAccounts as fetchModuleSettlementAccounts } from '@/composables/useTreasuryAccountGroups';
import { formatLedgerBalance, projectedLedgerBalance } from '@/composables/useLedgerBalance';
import {
  ArrowRight,
  ArrowLeft,
  Plane,
  MapPin,
  Settings,
  Users,
  User,
  Building2,
  DollarSign,
  CreditCard,
  FileText,
  Check,
  X,
  Plus,
  Trash2,
  Search,
  Loader2,
  ArrowUpRight,
  ArrowLeftRight,
  Wallet,
  Landmark,
  Banknote,
  Activity,
} from 'lucide-vue-next';

const router = useRouter();
const store = useFlightStore();

const props = defineProps({
  isEdit: { type: Boolean, default: false },
  bookingId: { type: [String, Number], default: null },
});

const isEditMode = computed(() => Boolean(props.isEdit && props.bookingId));

const pageTitle = computed(() => (isEditMode.value ? 'تعديل حجز طيران' : 'حجز رحلة جديدة'));

const pageSubtitle = computed(() =>
  isEditMode.value
    ? 'حدّث بيانات الحجز ثم احفظ التغييرات. تسجيل دفعات جديدة يتم من شاشة عرض الحجز.'
    : 'أكمل الخطوات التالية لتسجيل حجز بصيغة احترافية مع جاهزية للطباعة والمحاسبة.',
);

const submitActionLabel = computed(() => (isEditMode.value ? 'حفظ التعديلات' : 'تأكيد الحجز'));

const submitLoadingLabel = computed(() =>
  isEditMode.value ? 'جاري حفظ التعديلات...' : 'جاري إنشاء الحجز...',
);

// State
const currentStep = ref(1);
const loading = ref(false);
const availableCarriers = computed(() => store.carriers || []);
const availableGroups = ref([]);
const selectedCarrier = ref(null);
const loadingCarriers = ref(false);
const loadingGroups = ref(false);
const employeesList = ref([]);
/** إجمالي مدفوعات الحجز (وضع التعديل) لعرض الملخص الجانبي. */
const editTotalPaid = ref(0);
/** Active finance accounts (خزائن / بنوك / محافظ) loaded for settlement. */
const settlementAccounts = ref([]);
const paymentMethods = ref([]);
/** تصنيف واجهة التحصيل: يحدد طرق الدفع المعروضة ثم الحسابات المتوافقة. */
const settlementCategoryUi = ref('cash');

const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

const normalizeMethodCode = (raw) =>
  String(raw ?? '')
    .trim()
    .toLowerCase()
    .replace(/-/g, '_');

const normalizeAccountType = (raw) => {
  if (raw == null || raw === '') return '';
  if (typeof raw === 'string') return raw.trim().toLowerCase();
  if (typeof raw === 'object' && raw && 'value' in raw && raw.value != null) {
    return String(raw.value).trim().toLowerCase();
  }
  return String(raw).trim().toLowerCase();
};

/** إذا لم تُرجع لوحة الإعدادات طرقاً؛ نستخدم قائمة متوافقة مع الحجز. */
const PAYMENT_METHODS_FALLBACK = [
  { value: 'cash', label: 'نقدي مصري' },
  { value: 'office_drawer', label: 'درج المكتب' },
  { value: 'office_safe', label: 'خزينة المكتب' },
  { value: 'bank_transfer', label: 'تحويل بنكي' },
  { value: 'vodafone_cash', label: 'فودافون كاش' },
  { value: 'instapay', label: 'إنستاباي' },
  { value: 'cash_wallet', label: 'محفظة كاش' },
  { value: 'postal_transfer', label: 'بريد' },
  { value: 'mixed', label: 'مختلط' },
];

const SETTLEMENT_CATEGORY_TYPES = {
  cash: ['cashbox', 'treasury'],
  wallet: ['wallet'],
  bank: ['bank'],
};

let searchDebounceFrom = null;
let searchDebounceTo = null;

function createDefaultForm() {
  return {
    trip_type: '',
    from_airport: null,
    to_airport: null,
    departure_date: '',
    return_date: '',
    /** نوع الحجز الأساسي: system | direct */
    booking_source: 'direct',
    flight_system_id: null,
    flight_carrier_id: null,
    airline_name: '',
    flight_group_id: null,
    /** مصدر خصم تكلفة الشراء على الخادم: carrier | system */
    purchase_balance_source: 'carrier',
    passengers: [],
    currency: 'EGP',
    purchase_price_foreign: 0,
    exchange_rate: 0,
    purchase_price_egp: 0,
    selling_price: 0,
    account_id: null,
    customer: null,
    customer_id: '',
    customer_type: 'regular',
    pnr: '',
    employee_id: '',
    departure_time: '',
    arrival_time: '',
    return_time: '',
    return_arrival_time: '',
    legs: [],
    initial_payment: 0,
    payment_method: 'cash',
    notes: '',
  };
}

// Form data
const form = ref(createDefaultForm());

const settlementAccountCurrencySymbol = computed(() => {
  const symbolMap = { EGP: 'ج.م', KWD: 'د.ك', SAR: 'ر.س', USD: '$', EUR: '€' };
  const currency = selectedSettlementAccount.value?.currency || 'EGP';
  return symbolMap[currency] || currency;
});

const initialPaymentEgp = computed(() => {
  const pay = Number(form.value.initial_payment) || 0;
  if (form.value.currency === 'EGP') {
    return pay;
  }
  const account = selectedSettlementAccount.value;
  const payCurrency = account ? account.currency : 'EGP';
  if (payCurrency === 'EGP') {
    return pay;
  }
  const rate = Number(form.value.exchange_rate) || 1.0;
  return pay * rate;
});

const customerCurrentLedger = computed(() =>
  formatLedgerBalance(form.value.customer?.balance ?? 0, 'customer')
);

const customerProjectedLedger = computed(() =>
  formatLedgerBalance(
    projectedLedgerBalance(
      form.value.customer?.balance ?? 0,
      sellingPriceEgp.value,
      initialPaymentEgp.value
    ),
    'customer'
  )
);

const counterCurrentLedger = computed(() =>
  formatLedgerBalance(form.value.customer?.balance ?? 0, 'customer')
);

const counterProjectedLedger = computed(() =>
  formatLedgerBalance(
    projectedLedgerBalance(form.value.customer?.balance ?? 0, sellingPriceEgp.value, 0),
    'customer'
  )
);

const customerProfile = ref({
  full_name: '',
  phone: '',
  national_id: '',
  travel_country: '',
});

function syncCustomerProfileFromSelection(customer) {
  if (!customer) {
    customerProfile.value = {
      full_name: '',
      phone: '',
      national_id: '',
      travel_country: '',
    };
    return;
  }
  customerProfile.value = {
    full_name: customer.full_name || customer.name || '',
    phone: customer.phone || '',
    national_id: customer.national_id || '',
    travel_country: customer.travel_country || '',
  };
}

function getMergedCustomerProfile() {
  const c = form.value.customer || {};
  const p = customerProfile.value;
  return {
    full_name: String(p.full_name || c.full_name || c.name || '').trim(),
    phone: String(p.phone || c.phone || '').trim(),
    national_id: String(p.national_id || c.national_id || '').trim(),
    travel_country: String(p.travel_country || c.travel_country || '').trim(),
  };
}

function isCustomerProfileComplete() {
  if (form.value.customer_type === 'counter') return true;
  const p = getMergedCustomerProfile();
  return (
    p.full_name.length > 0 &&
    p.phone.length > 0 &&
    p.national_id.length > 0 &&
    p.travel_country.length > 0
  );
}

function getStep4MissingFields() {
  const missing = [];

  if (!(form.value.customer?.id || form.value.customer_id)) {
    missing.push(form.value.customer_type === 'counter' ? 'اختيار الشركة' : 'اختيار العميل');
  } else if (form.value.customer_type === 'regular') {
    const p = getMergedCustomerProfile();
    if (!p.full_name) missing.push('الاسم الرباعي للعميل');
    if (!p.phone) missing.push('هاتف العميل');
    if (!p.national_id) missing.push('الرقم القومي للعميل');
    if (!p.travel_country) missing.push('بلد العميل');
  }

  if (form.value.booking_source === 'direct') {
    if (!form.value.flight_carrier_id) {
      missing.push('خط الطيران (الناقل)');
    }
  } else {
    if (!String(form.value.airline_name || '').trim()) {
      missing.push('خط الطيران (الناقل)');
    }
  }
  if (!String(form.value.pnr || '').trim()) {
    missing.push('رقم الحجز (PNR)');
  }
  if (!form.value.passengers.length) {
    missing.push('مسافر واحد على الأقل');
  } else {
    form.value.passengers.forEach((passenger, index) => {
      if (!passengerFirstName(passenger)) {
        missing.push(`الاسم الأول للمسافر ${index + 1}`);
      }
      if (!passengerLastName(passenger)) {
        missing.push(`الاسم الأخير للمسافر ${index + 1}`);
      }
    });
  }

  return missing;
}

async function persistCustomerProfile() {
  if (form.value.customer_type !== 'regular' || !form.value.customer?.id) return;

  const c = form.value.customer;
  const p = getMergedCustomerProfile();
  const payload = {
    full_name: p.full_name,
    phone: p.phone,
    national_id: p.national_id,
    travel_country: p.travel_country,
  };

  const unchanged =
    (c.full_name || c.name || '') === payload.full_name &&
    (c.phone || '') === payload.phone &&
    (c.national_id || '') === payload.national_id &&
    (c.travel_country || '') === payload.travel_country;

  if (unchanged) return;

  const updated = await store.updateCustomer(c.id, payload);
  form.value.customer = updated;
  syncCustomerProfileFromSelection(updated);
}

function resetBookingForm() {
  if (isEditMode.value) return;

  currentStep.value = 1;
  loading.value = false;
  availableGroups.value = [];
  selectedCarrier.value = null;
  loadingCarriers.value = false;
  loadingGroups.value = false;
  editTotalPaid.value = 0;
  settlementCategoryUi.value = 'cash';
  form.value = createDefaultForm();
  syncCustomerProfileFromSelection(null);
  airportSearch.value = { from: '', to: '' };
  airportSearchResults.value = { from: [], to: [] };
}

function initCreateFormState() {
  if (!isEditMode.value) {
    resetBookingForm();
  }
}

/** Account `type` values allowed per payment method (settlement ledger). */
const PAYMENT_METHOD_ACCOUNT_TYPES = {
  cash: ['cashbox', 'treasury'],
  office_drawer: ['cashbox', 'treasury'],
  office_safe: ['cashbox', 'treasury'],
  bank_transfer: ['bank'],
  vodafone_cash: ['wallet'],
  instapay: ['wallet'],
  cash_wallet: ['wallet'],
  postal_transfer: ['bank', 'treasury', 'cashbox', 'post'],
  mixed: ['cashbox', 'wallet', 'bank', 'treasury', 'post'],
};

const WALLET_PROVIDER_AR = {
  vodafone_cash: 'فودافون كاش',
  instapay: 'إنستاباي',
  cash_wallet: 'محفظة كاش',
  etisalat_cash: 'اتصالات كاش',
  orange_cash: 'أورانج كاش',
  we_pay: 'WE Pay',
  paymob: 'Paymob',
  postal: 'بريد / مصاري',
  other: 'أخرى',
};

const normalizeWalletProvider = (raw) => normalizeMethodCode(raw?.value ?? raw);

function paymentMethodFromWalletProvider(raw) {
  const p = normalizeWalletProvider(raw);
  const map = {
    vodafone_cash: 'vodafone_cash',
    instapay: 'instapay',
    cash_wallet: 'cash_wallet',
    etisalat_cash: 'cash_wallet',
    orange_cash: 'cash_wallet',
    we_pay: 'cash_wallet',
    paymob: 'cash_wallet',
    postal: 'postal_transfer',
    other: 'cash_wallet',
  };
  return map[p] || 'cash_wallet';
}

function defaultPaymentMethodForCategory(cat) {
  if (cat === 'wallet') return 'vodafone_cash';
  if (cat === 'bank') return 'bank_transfer';
  return 'cash';
}

function syncPaymentMethodFromSelectedAccount() {
  const id = form.value.account_id;
  if (id == null || id === '') {
    form.value.payment_method = defaultPaymentMethodForCategory(settlementCategoryUi.value);
    return;
  }
  const acc = settlementAccounts.value.find((x) => sameId(x.id, id));
  if (!acc) return;
  const t = normalizeAccountType(acc.type);
  if (t === 'wallet') {
    form.value.payment_method = paymentMethodFromWalletProvider(acc.wallet_provider ?? acc.walletProvider);
  } else if (t === 'bank') {
    form.value.payment_method = 'bank_transfer';
  } else {
    form.value.payment_method = 'cash';
  }
}

// Airport search
const airportSearch = ref({
  from: '',
  to: '',
});
const airportSearchResults = ref({
  from: [],
  to: [],
});

/** إذا كان `/api/v1/settings/currencies` فارغاً أو فشل؛ يبقى اختيار عملة المورد يعمل. */
const PRICING_CURRENCY_FALLBACK = [
  { code: 'EGP', name: 'جنيه مصري', exchangeRate: 1 },
  { code: 'USD', name: 'دولار أمريكي', exchangeRate: 48.5 },
  { code: 'KWD', name: 'دينار كويتي', exchangeRate: 157.5 },
  { code: 'SAR', name: 'ريال سعودي', exchangeRate: 12.9 },
  { code: 'EUR', name: 'يورو', exchangeRate: 52.3 },
  { code: 'GBP', name: 'جنيه إسترليني', exchangeRate: 61.2 },
];

// Computed
const circumferenceWide = computed(() => 2 * Math.PI * 22);
const progressOffsetWide = computed(() => {
  const progress = currentStep.value / 7;
  return circumferenceWide.value * (1 - progress);
});

const minDate = computed(() => {
  const today = new Date();
  return today.toISOString().split('T')[0];
});

const DEFAULT_TRIP_TYPES = [
  { value: 'one_way', label: 'ذهاب فقط', description: 'رحلة في اتجاه واحد مع أوقات المغادرة والوصول.' },
  { value: 'round_trip', label: 'ذهاب وعودة', description: 'رحلة ذهاب وعودة مع تواريخ وأوقات كاملة.' },
  { value: 'multi_city', label: 'وجهات متعددة', description: 'من 2 إلى 5 قطع رحلة بمسارات مختلفة.' },
];

const tripTypeOptions = computed(() =>
  Array.isArray(store.tripTypes) && store.tripTypes.length ? store.tripTypes : DEFAULT_TRIP_TYPES,
);

const selectedTripTypeDescription = computed(() => {
  const match = tripTypeOptions.value.find((t) => t.value === form.value.trip_type);
  return match?.description || '';
});

const createMultiCityLeg = () => ({
  uid: crypto.randomUUID(),
  from_airport: null,
  to_airport: null,
  departure_date: '',
  departure_time: '',
  arrival_time: '',
});

const ensureMultiCityLegs = () => {
  if (form.value.legs.length < 2) {
    form.value.legs = [createMultiCityLeg(), createMultiCityLeg()];
  }
};

const addMultiCityLeg = () => {
  if (form.value.legs.length >= 5) return;
  form.value.legs.push(createMultiCityLeg());
};

const removeMultiCityLeg = (index) => {
  if (form.value.legs.length <= 2) return;
  form.value.legs.splice(index, 1);
};

const selectTripType = (value) => {
  form.value.trip_type = value;
  if (value === 'multi_city') {
    ensureMultiCityLegs();
  }
};

const isMultiCityLegComplete = (leg) =>
  !!leg?.from_airport &&
  !!leg?.to_airport &&
  !!String(leg.departure_date || '').trim() &&
  !!String(leg.departure_time || '').trim() &&
  !!String(leg.arrival_time || '').trim();

const isRouteStepComplete = () => {
  const tripType = form.value.trip_type;
  if (!tripType) return false;

  if (tripType === 'multi_city') {
    return form.value.legs.length >= 2 && form.value.legs.every(isMultiCityLegComplete);
  }

  const hasBaseRoute =
    !!form.value.from_airport &&
    !!form.value.to_airport &&
    !!String(form.value.departure_date || '').trim() &&
    !!String(form.value.departure_time || '').trim() &&
    !!String(form.value.arrival_time || '').trim();

  if (tripType === 'round_trip') {
    return (
      hasBaseRoute &&
      !!String(form.value.return_date || '').trim() &&
      !!String(form.value.return_time || '').trim() &&
      !!String(form.value.return_arrival_time || '').trim()
    );
  }

  return hasBaseRoute;
};

const sliceTimeValue = (value) => {
  if (!value) return '';
  const raw = String(value);
  if (raw.includes('T')) return raw.split('T')[1].slice(0, 5);
  return raw.slice(0, 5);
};

const airportFromCode = (code, id = null) =>
  code
    ? {
        id: id || 0,
        iata_code: code,
        city_name_ar: '',
        city_name_en: '',
        airport_name_ar: '',
      }
    : null;

const buildRouteSegments = () => {
  const tripType = form.value.trip_type;

  if (tripType === 'multi_city') {
    return form.value.legs.map((leg) => ({
      from_airport: leg.from_airport?.iata_code || null,
      to_airport: leg.to_airport?.iata_code || null,
      departure_date: leg.departure_date || null,
      departure_time: leg.departure_time || null,
      arrival_time: leg.arrival_time || null,
    }));
  }

  const outbound = {
    from_airport: form.value.from_airport?.iata_code || null,
    to_airport: form.value.to_airport?.iata_code || null,
    departure_date: form.value.departure_date || null,
    departure_time: form.value.departure_time || null,
    arrival_time: form.value.arrival_time || null,
  };

  if (tripType === 'round_trip') {
    return [
      outbound,
      {
        from_airport: form.value.to_airport?.iata_code || null,
        to_airport: form.value.from_airport?.iata_code || null,
        departure_date: form.value.return_date || null,
        departure_time: form.value.return_time || null,
        arrival_time: form.value.return_arrival_time || null,
      },
    ];
  }

  return [outbound];
};

const resolveRouteBookingFields = () => {
  if (form.value.trip_type === 'multi_city' && form.value.legs.length > 0) {
    const first = form.value.legs[0];
    const last = form.value.legs[form.value.legs.length - 1];
    return {
      from_airport_id: first.from_airport?.id || null,
      to_airport_id: last.to_airport?.id || null,
      from_airport: first.from_airport?.iata_code || null,
      to_airport: last.to_airport?.iata_code || null,
      departure_date: first.departure_date || null,
      departure_time: first.departure_time || null,
      arrival_time: first.arrival_time || null,
      return_date: null,
      return_time: null,
    };
  }

  return {
    from_airport_id: form.value.from_airport?.id || null,
    to_airport_id: form.value.to_airport?.id || null,
    from_airport: form.value.from_airport?.iata_code || null,
    to_airport: form.value.to_airport?.iata_code || null,
    departure_date: form.value.departure_date || null,
    departure_time: form.value.departure_time || null,
    arrival_time: form.value.arrival_time || null,
    return_date: form.value.trip_type === 'round_trip' ? form.value.return_date || null : null,
    return_time: form.value.trip_type === 'round_trip' ? form.value.return_time || null : null,
  };
};

const routeSummaryLabel = computed(() => {
  if (form.value.trip_type === 'multi_city' && form.value.legs.length > 0) {
    const codes = form.value.legs
      .flatMap((leg, index) => {
        const from = leg.from_airport?.iata_code;
        const to = leg.to_airport?.iata_code;
        if (index === 0) return [from, to].filter(Boolean);
        return to ? [to] : [];
      })
      .filter(Boolean);
    return codes.join(' → ');
  }
  if (form.value.from_airport && form.value.to_airport) {
    return `${form.value.from_airport.iata_code} → ${form.value.to_airport.iata_code}`;
  }
  return '';
});

const pricingCurrencyOptions = computed(() => {
  const list = store.currencies;
  return Array.isArray(list) && list.length > 0 ? list : PRICING_CURRENCY_FALLBACK;
});

const sellingPriceEgp = computed(() => {
  const sell = Number(form.value.selling_price) || 0;
  if (form.value.currency === 'EGP') {
    return sell;
  }
  const rate = Number(form.value.exchange_rate) || 1.0;
  return sell * rate;
});

const calculatedProfit = computed(() => {
  const sell = sellingPriceEgp.value;
  const buy = Number(form.value.purchase_price_egp) || 0;
  return sell - buy;
});

/** Used for per-passenger lines when the wizard reached pricing (passengers required on prior step). */
const pricingPassengerCount = computed(() => {
  const n = form.value.passengers?.length ?? 0;
  return n > 0 ? n : 1;
});

const profitMarginOnCost = computed(() => {
  const c = Number(form.value.purchase_price_egp) || 0;
  if (c <= 0) return null;
  return (calculatedProfit.value / c) * 100;
});

const profitMarginOnSale = computed(() => {
  const s = sellingPriceEgp.value;
  if (s <= 0) return null;
  return (calculatedProfit.value / s) * 100;
});

/** حسابات التصنيف الحالي (محافظ فعلية بأرقامها، أو بنوك، أو خزائن). */
const settlementPickerOptions = computed(() => {
  const types = SETTLEMENT_CATEGORY_TYPES[settlementCategoryUi.value];
  if (!types?.length) return [];
  const want = new Set(types);
  const rows = settlementAccounts.value.filter(
    (a) => a.is_active !== false && want.has(normalizeAccountType(a.type)),
  );
  return [...rows].sort((a, b) =>
    String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' }),
  );
});

const settlementPickerLabel = computed(() => {
  if (settlementCategoryUi.value === 'wallet') return 'محفظة التحصيل';
  if (settlementCategoryUi.value === 'bank') return 'حساب التحصيل (بنك)';
  return 'حساب التحصيل (نقدي / خزينة)';
});

const settlementPickerEmptyText = computed(() => {
  if (!settlementAccounts.value.length) return 'جاري التحميل أو لا توجد حسابات…';
  if (!settlementPickerOptions.value.length) return 'لا يوجد حساب في هذا التصنيف';
  return '— اختر الحساب —';
});

const settlementAccountRequired = computed(() => (Number(form.value.initial_payment) > 0) && settlementPickerOptions.value.length > 0);

const sameId = (a, b) => a != null && b != null && String(a) === String(b);

function employeeOptionLabel(e) {
  return e?.personal_info?.full_name || e?.user?.name || `#${e?.id ?? ''}`;
}

const selectedEmployeeLabel = computed(() => {
  const id = form.value.employee_id;
  if (id === '' || id == null) return '—';
  const e = employeesList.value.find((x) => sameId(x.id, id));
  return e ? employeeOptionLabel(e) : `#${id}`;
});

const selectedFlightSystemName = computed(() => {
  const id = form.value.flight_system_id;
  const s = store.systems?.find((x) => sameId(x.id, id));
  return s?.name || '';
});

const selectedFlightSystem = computed(() => {
  const id = form.value.flight_system_id;
  if (id === '' || id == null) return null;
  return store.systems?.find((x) => sameId(x.id, id)) ?? null;
});

const selectedFlightSystemAvailable = computed(() => {
  const s = selectedFlightSystem.value;
  if (!s) return 0;
  const n = (v) => {
    const x = Number(v);
    return Number.isFinite(x) ? x : 0;
  };
  if (s.available_balance != null && s.available_balance !== '') {
    return n(s.available_balance);
  }
  return n(s.balance) + n(s.credit_limit);
});

const resolvedCarrier = computed(() => {
  if (selectedCarrier.value) return selectedCarrier.value;
  const id = form.value.flight_carrier_id;
  if (!id) return null;
  return availableCarriers.value.find((c) => Number(c.id) === Number(id)) ?? null;
});

const selectedGroupName = computed(() => {
  const id = form.value.flight_group_id;
  if (!id) return '';
  return availableGroups.value.find((g) => sameId(g.id, id))?.name || '';
});

const bookingSourceTypeLabel = computed(() => {
  if (form.value.booking_source === 'system') return 'حجز سيستم';
  if (form.value.booking_source === 'group') return 'حجز مجموعة';
  return 'حجز ساين';
});

/** يعرض نوع الحجز + المصدر الفعلي (نظام / ساين / مجموعة) في الملخص. */
const bookingSourceSummaryLabel = computed(() => {
  const type = bookingSourceTypeLabel.value;
  if (form.value.booking_source === 'system') {
    const name = selectedFlightSystemName.value;
    return name ? `${type} — ${name}` : type;
  }
  if (form.value.booking_source === 'group') {
    const name = selectedGroupName.value;
    return name ? `${type} — ${name}` : type;
  }
  const carrier = resolvedCarrier.value;
  return carrier?.name ? `${type} — ${carrier.name}` : type;
});

/** متاح شركة الطيران (الـ API أحياناً بدون available_balance قبل إلحاقه في الموديل). */
const selectedCarrierAvailable = computed(() => {
  const c = resolvedCarrier.value;
  if (!c) return 0;
  const n = (v) => {
    const x = Number(v);
    return Number.isFinite(x) ? x : 0;
  };
  if (c.available_balance != null && c.available_balance !== '') {
    return n(c.available_balance);
  }
  return n(c.balance) + n(c.credit_limit);
});

const selectedCustomerDisplay = computed(() => {
  if (form.value.customer) {
    if (form.value.customer_type === 'regular') {
      return String(customerProfile.value.full_name || form.value.customer.full_name || form.value.customer.name || '').trim();
    }
    return String(form.value.customer.full_name || form.value.customer.name || '').trim();
  }
  const id = form.value.customer_id;
  if (id === '' || id == null) return '';
  const c = store.customers?.find((x) => sameId(x.id, id));
  if (!c) return '';
  return String(c.full_name || c.name || '').trim() || `عميل #${id}`;
});

const selectedPaymentMethodLabel = computed(() => {
  const v = normalizeMethodCode(form.value.payment_method || '');
  const m = paymentMethods.value.find((x) => normalizeMethodCode(x.value) === v);
  return m?.label || v || '—';
});

const selectedSettlementAccountDisplay = computed(() => {
  const id = form.value.account_id;
  if (id == null || id === '') return '';
  const a = settlementAccounts.value.find((x) => sameId(x.id, id));
  if (!a) return '';
  let base = `${a.name} (${getAccountTypeLabel(a.type)})`;
  if (normalizeAccountType(a.type) === 'wallet') {
    const n = String(a.wallet_number ?? a.walletNumber ?? '').trim();
    if (n) base += ` — ${n}`;
  }
  return base;
});

const adminFilamentBankAccountsUrl = computed(() => {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  return `${base}/admin/bank-accounts/create`;
});

const adminFilamentWalletAccountsUrl = computed(() => {
  const base = typeof window !== 'undefined' ? window.location.origin : '';
  return `${base}/admin/wallet-accounts/create`;
});

/** Per-step completion by form state (stays correct if user goes back a step). */
const isBookingStepComplete = (step) => {
  switch (step) {
    case 1:
      return !!form.value.trip_type;
    case 2:
      return isRouteStepComplete();
    case 3:
      if (form.value.booking_source === 'group') {
        return form.value.flight_group_id !== null && form.value.flight_group_id !== '';
      }
      if (form.value.booking_source === 'system') {
        return form.value.flight_system_id !== null && form.value.flight_system_id !== '';
      }
      return true;
    case 4:
      return getStep4MissingFields().length === 0;
    case 5:
      return Number(form.value.purchase_price_egp) > 0 && sellingPriceEgp.value > 0;
    case 6: {
      const pMethod = String(form.value.payment_method || '').trim();
      if (Number(form.value.initial_payment) <= 0) return true;
      if (!pMethod) return false;
      if (settlementAccountRequired.value) {
        return !!form.value.account_id;
      }
      return true;
    }
    case 7:
      return (
        isBookingStepComplete(1) &&
        isBookingStepComplete(2) &&
        isBookingStepComplete(3) &&
        isBookingStepComplete(4) &&
        isBookingStepComplete(5) &&
        isBookingStepComplete(6)
      );
    default:
      return false;
  }
};

const getStepLabel = (step) => {
  const labels = {
    1: 'نوع الرحلة',
    2: 'المسار',
    3: 'مصدر الحجز',
    4: 'المسافرون',
    5: 'التسعير',
    6: 'الدفع',
    7: 'التأكيد',
  };
  return labels[step];
};

/** أول خطوة ناقصة ضمن النطاق 1..maxStep (null = الكل مكتمل). */
const findFirstIncompleteStep = (maxStep = 7) => {
  const limit = Math.min(Math.max(maxStep, 1), 7);
  for (let i = 1; i <= limit; i++) {
    if (!isBookingStepComplete(i)) return i;
  }
  return null;
};

/** Full payload readiness (sidebar + submit). */
const bookingFormComplete = computed(() => isBookingStepComplete(7));

const canProceed = computed(() => {
  if (currentStep.value >= 7) return false;
  return findFirstIncompleteStep(currentStep.value) === null;
});

const stepMissingFields = computed(() => {
  if (canProceed.value || currentStep.value >= 7) return [];
  const blocking = findFirstIncompleteStep(currentStep.value);
  if (blocking === 4) return getStep4MissingFields();
  if (blocking !== null) return [`أكمل: ${getStepLabel(blocking)}`];
  return [];
});

const liveStepHint = computed(() => {
  if (canProceed.value) {
    if (currentStep.value < 7) {
      return 'يمكنك الانتقال للخطوة التالية.';
    }
    return bookingFormComplete.value
      ? 'جميع البيانات مكتملة — يمكن تأكيد الحجز.'
      : 'راجع الخطوات السابقة أو أكمل الحقول الناقصة في المراجعة.';
  }
  const blocking = findFirstIncompleteStep(currentStep.value);
  if (blocking !== null && blocking !== currentStep.value) {
    return `يجب إكمال «${getStepLabel(blocking)}» قبل المتابعة.`;
  }
  switch (currentStep.value) {
    case 1:
      return 'اختر نوع الرحلة.';
    case 2:
      return 'أكمل المسار والتواريخ وأوقات المغادرة والوصول (والعودة إن وُجدت).';
    case 3:
      return form.value.booking_source === 'system'
        ? 'اختر نظام الحجز.'
        : form.value.booking_source === 'group'
          ? 'اختر المجموعة / الشركة بالأجل.'
          : 'حدد طريقة الخصم المالي.';
    case 4: {
      const missing = getStep4MissingFields();
      if (missing.length) {
        return `ناقص: ${missing.join(' — ')}`;
      }
      return 'يمكنك الانتقال للتسعير.';
    }
    case 5:
      return 'أدخل سعر الشراء وسعر البيع بالجنيه.';
    case 6:
      return 'اختر تصنيف التحصيل ثم الحساب (المحافظ تظهر بأرقامها).';
    default:
      return '';
  }
});

/** When true, purchase EGP is driven by foreign amount × rate in real time. */
const purchaseEgpIsAuto = computed(() => {
  if (form.value.currency === 'EGP') return false;
  const f = Number(form.value.purchase_price_foreign) || 0;
  const r = Number(form.value.exchange_rate) || 0;
  return f > 0 && r > 0;
});

const canSubmit = computed(() => bookingFormComplete.value);

// Methods
const finiteNum = (v, fallback = 0) => {
  const x = Number(v);
  return Number.isFinite(x) ? x : fallback;
};

const formatMoney = (amount, currencyCode = 'EGP') => {
  const n = Number(amount) || 0;
  const code = currencyCode || 'EGP';
  try {
    return new Intl.NumberFormat('ar-EG', {
      style: 'currency',
      currency: code,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(n);
  } catch {
    return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${code}`;
  }
};

const formatCurrency = (amount) => formatMoney(amount, 'EGP');

/** مبلغ الشراء الذي يُخصم من رصيد نظام الحجز (مطابق لمنطق الخادم). */
const systemPurchaseDebitPreview = computed(() => {
  const ccy = String(form.value.currency || 'EGP');
  if (ccy === 'EGP') {
    return Number(form.value.purchase_price_egp) || 0;
  }
  return Number(form.value.purchase_price_foreign) || 0;
});

const systemCurrencyMatchesBooking = computed(() => {
  const sys = selectedFlightSystem.value;
  if (!sys) return false;
  return String(form.value.currency || 'EGP') === String(sys.currency || 'EGP');
});

const carrierCurrencyMatchesBooking = computed(() => {
  const c = resolvedCarrier.value;
  if (!c) return false;
  return String(form.value.currency || 'EGP') === String(c.currency || 'EGP');
});

const carrierPurchaseDebitPreview = computed(() => {
  const ccy = String(form.value.currency || 'EGP');
  if (ccy === 'EGP') {
    return Number(form.value.purchase_price_egp) || 0;
  }
  return Number(form.value.purchase_price_foreign) || 0;
});

const projectedSystemAvailableAfterBooking = computed(() => {
  if (form.value.purchase_balance_source !== 'system') {
    return null;
  }
  if (!selectedFlightSystem.value || !systemCurrencyMatchesBooking.value) {
    return null;
  }
  const debit = systemPurchaseDebitPreview.value;
  if (debit <= 0) {
    return null;
  }
  return selectedFlightSystemAvailable.value - debit;
});

const projectedCarrierAvailableAfterBooking = computed(() => {
  if (form.value.purchase_balance_source !== 'carrier') {
    return null;
  }
  if (!resolvedCarrier.value || !carrierCurrencyMatchesBooking.value) {
    return null;
  }
  const debit = carrierPurchaseDebitPreview.value;
  if (debit <= 0) {
    return null;
  }
  return selectedCarrierAvailable.value - debit;
});

const selectedSettlementAccount = computed(() => {
  const id = form.value.account_id;
  if (id == null || id === '') return null;
  return settlementAccounts.value.find((x) => sameId(x.id, id)) ?? null;
});

const settlementBalancePreview = computed(() => {
  const a = selectedSettlementAccount.value;
  if (!a) return null;
  const cur = Number(a.balance) || 0;
  const add = Number(form.value.initial_payment) || 0;
  return {
    current: cur,
    after: cur + add,
    delta: add,
    currency: a.currency || 'EGP',
  };
});

const customerCollectionStatus = computed(() => {
  const sell = sellingPriceEgp.value;
  const paid = isEditMode.value
    ? Number(editTotalPaid.value) || 0
    : initialPaymentEgp.value;
  if (sell <= 0) return null;
  if (paid <= 0) {
    return {
      label: 'لم يُدفع بعد',
      variant: 'warning',
      hint: 'يمكن تسجيل دفعات لاحقاً من شاشة الحجز',
    };
  }
  if (paid + 0.001 >= sell) {
    return { label: 'مدفوع بالكامل', variant: 'success', hint: null };
  }
  return {
    label: 'تحصيل جزئي',
    variant: 'info',
    hint: `متبقي على العميل: ${formatCurrency(sell - paid)}`,
  };
});

const formatDate = (dateString) => {
  if (!dateString) return '-';
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const getTripTypeLabel = (type) => {
  return store.tripTypes.find(t => t.value === type)?.label || type;
};

const getTripTypeIcon = (type) => {
  const icons = {
    one_way: ArrowUpRight,
    round_trip: ArrowLeft,
    multi_city: Plane,
  };
  return icons[type] || Plane;
};

const getPassengerTypeLabel = (type) =>
  store.passengerTypes.find((p) => p.value === type)?.label || type;

const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة نقدي',
  wallet: 'محفظة إلكترونية',
  bank: 'حساب بنكي',
  treasury: 'خزينة عامة',
};

const getAccountTypeLabel = (type) => ACCOUNT_TYPE_LABELS[normalizeAccountType(type)] || type;

const formatSettlementPickerOption = (account) => {
  const t = normalizeAccountType(account.type);
  const bal = formatMoney(account.balance ?? 0, account.currency || 'EGP');
  if (t === 'wallet') {
    const prov = normalizeWalletProvider(account.wallet_provider ?? account.walletProvider);
    const pl = WALLET_PROVIDER_AR[prov] || (prov ? prov : 'محفظة');
    const num = String(account.wallet_number ?? account.walletNumber ?? '').trim();
    const line = num ? `${pl} — ${num}` : pl;
    return `${account.name} — ${line} — ${bal}`;
  }
  return `${account.name} — ${getAccountTypeLabel(account.type)} — ${bal}`;
};

const setSettlementCategory = (categoryId) => {
  settlementCategoryUi.value = categoryId;
  form.value.account_id = null;
};

const paymentMethodSettlementHint = computed(() => {
  if (settlementCategoryUi.value === 'wallet') {
    return 'تظهر كل محفظة مسجّلة في النظام (اسم + نوع المزود + رقم المحفظة). يُحدَّث نوع التحصيل في الحجز تلقائياً حسب نوع المحفظة على الحساب.';
  }
  if (settlementCategoryUi.value === 'bank') {
    return 'اختر الحساب البنكي الذي يستلم التحصيل.';
  }
  return 'اختر الخزينة النقدية أو الخزينة العامة التي يُسجَّل فيها التحصيل.';
});

const debounceSearchFromAirport = () => {
  clearTimeout(searchDebounceFrom);
  searchDebounceFrom = setTimeout(searchFromAirport, 200);
};

const debounceSearchToAirport = () => {
  clearTimeout(searchDebounceTo);
  searchDebounceTo = setTimeout(searchToAirport, 200);
};

/** توحيد شكل المطار من الـ API (snake_case) لاستخدامه في النموذج. */
const normalizeAirport = (raw) => {
  if (!raw || raw.id == null) return null;
  return {
    id: raw.id,
    iata_code: raw.iata_code ?? raw.iataCode ?? '',
    icao_code: raw.icao_code ?? raw.icaoCode ?? '',
    city_name_ar: raw.city_name_ar ?? raw.cityNameAr ?? '',
    city_name_en: raw.city_name_en ?? raw.cityNameEn ?? '',
    airport_name_ar: raw.airport_name_ar ?? raw.airportNameAr ?? '',
    airport_name_en: raw.airport_name_en ?? raw.airportNameEn ?? '',
    country_code: raw.country_code ?? raw.countryCode ?? '',
    country_name_ar: raw.country_name_ar ?? raw.countryNameAr ?? '',
    country_name_en: raw.country_name_en ?? raw.countryNameEn ?? '',
  };
};

const MAX_AIRPORT_DROPDOWN = 60;

/**
 * تصفية محلية على كل المطارات النشطة المحمّلة من السيرفر (نفس مصدر Filament).
 */
const filterAirportsLocal = (query, { excludeId = null } = {}) => {
  const q = String(query || '').trim().toLowerCase();
  if (!q.length) return [];

  const list = Array.isArray(store.airports) ? store.airports : [];
  const out = [];

  for (const raw of list) {
    if (out.length >= MAX_AIRPORT_DROPDOWN) break;
    const a = normalizeAirport(raw);
    if (!a || (excludeId != null && String(a.id) === String(excludeId))) continue;

    const hay = [
      a.iata_code,
      a.icao_code,
      a.city_name_ar,
      a.city_name_en,
      a.airport_name_ar,
      a.airport_name_en,
      a.country_code,
      a.country_name_ar,
      a.country_name_en,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    if (hay.includes(q)) {
      out.push(a);
    }
  }

  out.sort((x, y) => String(x.city_name_ar || x.city_name_en || '').localeCompare(String(y.city_name_ar || y.city_name_en || ''), 'ar'));

  return out;
};

const ensureAirportsCatalogLoaded = async () => {
  if (Array.isArray(store.airports) && store.airports.length > 0) return;
  await store.fetchAirports();
};

const onAirportInputFocus = () => {
  ensureAirportsCatalogLoaded();
};

const swapRouteAirports = () => {
  const a = form.value.from_airport;
  const b = form.value.to_airport;
  form.value.from_airport = b;
  form.value.to_airport = a;
  airportSearch.value.from = '';
  airportSearch.value.to = '';
  airportSearchResults.value.from = [];
  airportSearchResults.value.to = [];
};

const searchFromAirport = async () => {
  if (airportSearch.value.from.length < 1) {
    airportSearchResults.value.from = [];
    return;
  }
  await ensureAirportsCatalogLoaded();
  const excludeTo = form.value.to_airport?.id ?? null;
  airportSearchResults.value.from = filterAirportsLocal(airportSearch.value.from, { excludeId: excludeTo });
};

const searchToAirport = async () => {
  if (airportSearch.value.to.length < 1) {
    airportSearchResults.value.to = [];
    return;
  }
  await ensureAirportsCatalogLoaded();
  const excludeFrom = form.value.from_airport?.id ?? null;
  airportSearchResults.value.to = filterAirportsLocal(airportSearch.value.to, { excludeId: excludeFrom });
};

const selectFromAirport = (airport) => {
  form.value.from_airport = normalizeAirport(airport) || airport;
  airportSearch.value.from = '';
  airportSearchResults.value.from = [];
};

const selectToAirport = (airport) => {
  form.value.to_airport = normalizeAirport(airport) || airport;
  airportSearch.value.to = '';
  airportSearchResults.value.to = [];
};

const onSystemChange = async () => {
  // We no longer clear availableCarriers because they are now independent.
  // But we might want to reset the selection if it doesn't make sense.
  
  if (form.value.booking_source === 'system') {
    form.value.purchase_balance_source = 'system';
  }
  
  // المجموعات مرتبطة بالناقل فقط خارج حجز المجموعة
  if (!form.value.flight_carrier_id && form.value.booking_source !== 'group') {
    availableGroups.value = [];
    form.value.flight_group_id = null;
  }
};

const onCarrierChange = async () => {
  if (form.value.booking_source !== 'group') {
    form.value.flight_group_id = null;
  }
  availableGroups.value = [];
  selectedCarrier.value = null;
  loadingGroups.value = false;

  if (!form.value.flight_carrier_id) {
    form.value.airline_name = '';
    return;
  }

  selectedCarrier.value = availableCarriers.value.find(
    (c) => Number(c.id) === Number(form.value.flight_carrier_id)
  );
  if (selectedCarrier.value) {
    form.value.airline_name = selectedCarrier.value.name;
  }
  loadingGroups.value = true;
  try {
    availableGroups.value = await store.fetchGroupsByCarrier(form.value.flight_carrier_id);
  } finally {
    loadingGroups.value = false;
  }
};

const loadAllGroups = async () => {
  form.value.flight_group_id = null;
  availableGroups.value = [];
  loadingGroups.value = true;
  try {
    availableGroups.value = await store.fetchGroups();
  } finally {
    loadingGroups.value = false;
  }
};

const onCurrencyChange = () => {
  if (form.value.currency === 'EGP') {
    form.value.purchase_price_foreign = 0;
    form.value.exchange_rate = 0;
    return;
  }
  const cur = pricingCurrencyOptions.value.find((x) => String(x.code) === String(form.value.currency));
  const rate = finiteNum(cur?.exchangeRate ?? cur?.exchange_rate, 0);
  if (rate > 0) {
    form.value.exchange_rate = rate;
  }
};

const syncPurchaseEgpFromForeign = () => {
  if (form.value.currency === 'EGP') {
    return;
  }
  const f = Number(form.value.purchase_price_foreign) || 0;
  const r = Number(form.value.exchange_rate) || 0;
  if (f > 0 && r > 0) {
    form.value.purchase_price_egp = Math.round(f * r * 100) / 100;
  }
};

const newPassengerUid = () =>
  typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `p-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;

const addPassenger = (type) => {
  form.value.passengers.push({
    uid: newPassengerUid(),
    first_name: '',
    last_name: '',
    type: type || 'adult',
    baggage_allowance_kg: 0,
  });
};

const removePassenger = (passenger) => {
  if (form.value.customer_type === 'counter' && form.value.passengers.length <= 1) {
    store.addToast('يجب إدخال مسافر واحد على الأقل (الاسم الأول والأخير)', 'warning');
    return;
  }
  const index = form.value.passengers.indexOf(passenger);
  if (index > -1) {
    form.value.passengers.splice(index, 1);
  }
};

const nextStep = () => {
  const incomplete = findFirstIncompleteStep(currentStep.value);
  if (incomplete !== null) {
    const detail =
      incomplete === 4
        ? getStep4MissingFields().join('، ')
        : getStepLabel(incomplete);
    store.addToast(`يرجى إكمال: ${detail}`, 'warning');
    if (incomplete < currentStep.value) {
      currentStep.value = incomplete;
    }
    return;
  }
  if (currentStep.value < 7) {
    currentStep.value++;
  }
};

const previousStep = () => {
  if (currentStep.value > 1) {
    currentStep.value--;
  }
};

const goToStep = (step) => {
  if (step < 1 || step > 7) return;

  // الرجوع لأي خطوة سابقة مسموح دائماً
  if (step <= currentStep.value) {
    currentStep.value = step;
    return;
  }

  // للأمام: لا تخطّ خطوة ناقصة (نادراً — الأزرار الأمامية معطّلة في الهيدر)
  const blocking = findFirstIncompleteStep(step - 1);
  if (blocking !== null) {
    currentStep.value = blocking;
    store.addToast(`يرجى إكمال: ${getStepLabel(blocking)}`, 'warning');
    return;
  }

  currentStep.value = step;
};

const loadSettlementAccounts = async () => {
  try {
    settlementAccounts.value = await fetchModuleSettlementAccounts(axios, { module: 'flight' });
  } catch (error) {
    console.error('Failed to load settlement accounts:', error);
    settlementAccounts.value = [];
  }
};

const hydrateForEdit = async (id) => {
  if (!id) return;
  loading.value = true;
  try {
    const { data: body } = await axios.get(`/api/v1/flight/bookings/${id}`);
    if (!body?.status || !body.data) {
      store.addToast('تعذر تحميل الحجز', 'error');
      return;
    }
    const raw = body.data;
    store.currentBooking = store.mapBooking(raw);
    editTotalPaid.value = parseFloat(raw.total_paid ?? raw.totalPaid ?? 0) || 0;

    form.value.trip_type = raw.trip_type || '';
    form.value.departure_date = raw.departure_date ? String(raw.departure_date).slice(0, 10) : '';
    form.value.return_date = raw.return_date ? String(raw.return_date).slice(0, 10) : '';
    form.value.notes = raw.notes || '';
    form.value.pnr = raw.pnr || '';
    form.value.baggage_allowance_kg = Number(raw.baggage_allowance_kg) || 0;
    form.value.customer = raw.customer || null;
    const rawType = raw.customer?.type;
    form.value.customer_type = (rawType === 'company' || rawType === 'counter') ? 'counter' : 'regular';
    form.value.customer_id = raw.customer_id != null ? String(raw.customer_id) : '';
    form.value.employee_id = raw.employee_id != null ? String(raw.employee_id) : '';
    form.value.currency = String(raw.currency || 'EGP').toUpperCase().slice(0, 3);
    form.value.purchase_price_egp = parseFloat(raw.purchase_price_egp ?? raw.purchase_price ?? 0) || 0;
    form.value.purchase_price_foreign = parseFloat(raw.purchase_price_foreign ?? 0) || 0;
    form.value.exchange_rate = parseFloat(raw.exchange_rate ?? 0) || 0;
    form.value.selling_price = parseFloat(raw.original_amount ?? raw.selling_price ?? 0) || 0;
    form.value.initial_payment = 0;
    form.value.account_id = raw.account_id != null ? Number(raw.account_id) : null;
    form.value.flight_system_id = raw.flight_system_id || null;
    form.value.flight_carrier_id = raw.flight_carrier_id || null;
    form.value.airline_name = raw.airline_name || '';
    form.value.flight_group_id = raw.flight_group_id || null;
    form.value.purchase_balance_source = raw.purchase_balance_source || 'carrier';
    if (form.value.purchase_balance_source === 'group') {
      form.value.booking_source = 'group';
    } else if (form.value.purchase_balance_source === 'system') {
      form.value.booking_source = 'system';
    } else {
      form.value.booking_source = 'direct';
    }

    const fromId = raw.from_airport_id;
    const toId = raw.to_airport_id;
    const list = store.airports || [];
    form.value.from_airport =
      (fromId && list.find((a) => Number(a.id) === Number(fromId))) ||
      (raw.from_airport
        ? {
            id: fromId || 0,
            iata_code: raw.from_airport,
            city_name_ar: '',
            airport_name_ar: '',
          }
        : null);
    form.value.to_airport =
      (toId && list.find((a) => Number(a.id) === Number(toId))) ||
      (raw.to_airport
        ? {
            id: toId || 0,
            iata_code: raw.to_airport,
            city_name_ar: '',
            airport_name_ar: '',
          }
        : null);

    form.value.departure_time = sliceTimeValue(raw.departure_time);
    form.value.arrival_time = sliceTimeValue(raw.arrival_time);
    form.value.return_time = sliceTimeValue(raw.return_time);
    form.value.return_arrival_time = '';

    const segments = raw.segments || [];
    if (form.value.trip_type === 'multi_city' && segments.length >= 2) {
      form.value.legs = segments.map((seg) => ({
        uid: crypto.randomUUID(),
        from_airport: airportFromCode(seg.from_airport || seg.from, seg.from_airport_id),
        to_airport: airportFromCode(seg.to_airport || seg.to, seg.to_airport_id),
        departure_date: seg.departure_date ? String(seg.departure_date).slice(0, 10) : '',
        departure_time: sliceTimeValue(seg.departure_time),
        arrival_time: sliceTimeValue(seg.arrival_time),
      }));
    } else if (form.value.trip_type === 'round_trip' && segments.length >= 2) {
      const returnSeg = segments[1];
      form.value.return_time = sliceTimeValue(returnSeg.departure_time) || form.value.return_time;
      form.value.return_arrival_time = sliceTimeValue(returnSeg.arrival_time);
    } else {
      form.value.legs = [];
    }

    form.value.passengers = (raw.passengers || []).map((p) => ({
      uid: newPassengerUid(),
      first_name: p.first_name || passengerFirstName(p) || '',
      last_name: p.last_name || passengerLastName(p) || '',
      type: String(p.passenger_type || p.type || 'adult').toLowerCase(),
      baggage_allowance_kg: Number(p.baggage_allowance_kg) || 0,
    }));
    if (!form.value.passengers.length) {
      form.value.passengers.push({
        uid: newPassengerUid(),
        first_name: '',
        last_name: '',
        type: 'adult',
        baggage_allowance_kg: 0,
      });
    }

    syncCustomerProfileFromSelection(form.value.customer);

    if (form.value.flight_system_id) {
      loadingCarriers.value = true;
      try {
        availableCarriers.value = await store.fetchCarriersBySystem(form.value.flight_system_id);
      } finally {
        loadingCarriers.value = false;
      }
      selectedCarrier.value =
        availableCarriers.value.find((c) => Number(c.id) === Number(form.value.flight_carrier_id)) ||
        null;
    }

    if (form.value.booking_source === 'group') {
      loadingGroups.value = true;
      try {
        availableGroups.value = await store.fetchGroups();
      } finally {
        loadingGroups.value = false;
      }
    } else if (form.value.flight_carrier_id) {
      loadingGroups.value = true;
      try {
        availableGroups.value = await store.fetchGroupsByCarrier(form.value.flight_carrier_id);
      } finally {
        loadingGroups.value = false;
      }
    }
  } catch (e) {
    console.error(e);
    store.addToast('فشل تحميل الحجز للتعديل', 'error');
  } finally {
    loading.value = false;
  }
};

const submitBooking = async () => {
  if (loading.value) return;
  if (!canSubmit.value) {
    const missing = [];
    if (!isBookingStepComplete(1)) missing.push('نوع الرحلة');
    if (!isBookingStepComplete(2)) missing.push('المسار والتواريخ');
    if (!isBookingStepComplete(3)) missing.push('مصدر الحجز');
    if (!isBookingStepComplete(4)) missing.push('بيانات العميل والمسافرين');
    if (!isBookingStepComplete(5)) missing.push('التسعير');
    if (!isBookingStepComplete(6)) missing.push('الدفع');
    store.addToast(`يرجى إكمال: ${missing.join('، ')}`, 'error');
    return;
  }

  loading.value = true;
  try {
    const customerId = form.value.customer?.id || form.value.customer_id;
    if (!customerId) {
      store.addToast('يرجى اختيار العميل', 'error');
      loading.value = false;
      return;
    }

    const employeeIdVal = form.value.employee_id;
    const employee_id =
      employeeIdVal !== '' && employeeIdVal != null
        ? parseInt(String(employeeIdVal), 10)
        : null;
    
    // Date validation
    if (form.value.trip_type === 'round_trip' && form.value.departure_date && form.value.return_date) {
      if (new Date(form.value.return_date) < new Date(form.value.departure_date)) {
        store.addToast('تاريخ العودة لا يمكن أن يكون قبل تاريخ المغادرة', 'error');
        loading.value = false;
        return;
      }
    }

    if (form.value.customer_type === 'regular') {
      if (!isCustomerProfileComplete()) {
        store.addToast('يرجى إكمال بيانات العميل (الاسم، الهاتف، الرقم القومي، البلد)', 'error');
        loading.value = false;
        return;
      }
      await persistCustomerProfile();
    }

    const routeFields = resolveRouteBookingFields();
    const payload = {
      customer_id: parseInt(String(customerId), 10),
      trip_type: form.value.trip_type,
      from_airport_id: routeFields.from_airport_id,
      to_airport_id: routeFields.to_airport_id,
      from_airport: routeFields.from_airport,
      to_airport: routeFields.to_airport,
      departure_date: routeFields.departure_date,
      return_date: routeFields.return_date,
      return_time: routeFields.return_time,
      flight_system_id: form.value.flight_system_id,
      flight_carrier_id: form.value.booking_source === 'direct' ? form.value.flight_carrier_id : null,
      flight_group_id: form.value.flight_group_id,
      airline_name: form.value.airline_name || null,
      booking_source: form.value.booking_source,
      purchase_balance_source: form.value.purchase_balance_source || 'carrier',
      currency: form.value.currency,
      purchase_price_foreign: form.value.purchase_price_foreign || null,
      exchange_rate: form.value.exchange_rate || null,
      purchase_price_egp: form.value.purchase_price_egp,
      selling_price: form.value.selling_price,
      account_id: form.value.account_id ? parseInt(String(form.value.account_id), 10) : null,
      passengers: form.value.passengers.map(({ first_name, last_name, type, baggage_allowance_kg }) => ({
        first_name: String(first_name || '').trim(),
        last_name: String(last_name || '').trim(),
        name: `${first_name} ${last_name}`.trim(),
        type,
        baggage_allowance_kg: Number(baggage_allowance_kg) || 0,
      })),
      notes: form.value.notes,
      payment_method: form.value.payment_method || 'cash',
      pnr: String(form.value.pnr || '').trim() || null,
      employee_id,
      baggage_allowance_kg: form.value.passengers.reduce(
        (sum, p) => sum + (Number(p.baggage_allowance_kg) || 0),
        0
      ),
      departure_time: routeFields.departure_time || null,
      arrival_time: routeFields.arrival_time || null,
      segments: buildRouteSegments(),
      initial_payment: isEditMode.value ? 0 : Number(form.value.initial_payment || 0),
    };

    const result = isEditMode.value
      ? await store.updateBooking(props.bookingId, payload)
      : await store.createBooking(payload);

    await store.fetchSystems();
    await loadSettlementAccounts();

    const rid = result?.id ?? props.bookingId;
    router.push(`/flights/${rid}`);
  } catch (error) {
    const label = isEditMode.value ? 'Failed to update booking:' : 'Failed to create booking:';
    console.error(label, error);
    const api = error.response?.data;
    const errs = api?.errors;
    let detail = api?.message || error.message || '';
    if (errs && typeof errs === 'object') {
      const lines = Object.entries(errs).map(([k, v]) => {
        const msg = Array.isArray(v) ? v.join(' — ') : String(v);
        return `${k}: ${msg}`;
      });
      if (lines.length) {
        detail = `${detail} ${lines.join(' ')}`;
      }
    }
    const prefix = isEditMode.value ? 'فشل حفظ التعديلات: ' : 'فشل إنشاء الحجز: ';
    store.addToast(prefix + detail, 'error');
  } finally {
    loading.value = false;
  }
};

// Lifecycle
onMounted(async () => {
  initCreateFormState();

  // Load all necessary data with individual error handling to prevent blocking
  const fetchData = async (fn, label) => {
    try {
      await fn();
    } catch (e) {
      console.warn(`Failed to fetch ${label}:`, e);
    }
  };

  await Promise.all([
    fetchData(() => store.fetchTripTypes(), 'trip types'),
    fetchData(() => store.fetchSystems(), 'systems'),
    fetchData(() => store.fetchCarriers(), 'carriers'),
    fetchData(() => store.fetchPopularAirports(10), 'popular airports'),
    fetchData(() => store.fetchAirports(), 'airports'),
    fetchData(() => store.fetchCustomers({ per_page: 500 }), 'customers'),
    fetchData(() => store.fetchCurrencies(), 'currencies'),
    fetchData(() => store.fetchFlightBookingReference(), 'reference data'),
  ]);

  // Load settlement accounts (cashbox / wallet / bank / treasury) and payment methods
  try {
    const [methodsRes] = await Promise.all([
      axios.get('/api/v1/settings/payment-methods'),
      loadSettlementAccounts(),
    ]);
    const rawMethods = Array.isArray(methodsRes.data?.data) ? methodsRes.data.data : [];
    paymentMethods.value = rawMethods.length
      ? rawMethods.map((m) => ({
          ...m,
          value: normalizeMethodCode(m.value),
        }))
      : PAYMENT_METHODS_FALLBACK;
  } catch (error) {
    console.error('Failed to load accounts/payment methods:', error);
    paymentMethods.value = PAYMENT_METHODS_FALLBACK;
  }

  if (store.currencies?.length) {
    const codes = store.currencies.map((c) => c.code);
    if (!codes.includes(form.value.currency)) {
      form.value.currency = codes[0];
    }
  }
  form.value.passengers.forEach((p) => {
    if (!p.uid) {
      p.uid = newPassengerUid();
    }
  });

  try {
    const er = await axios.get('/api/v1/flight/booking-form/employees');
    const raw = er.data?.data;
    employeesList.value = Array.isArray(raw) ? raw : [];
  } catch {
    employeesList.value = [];
  }

  if (isEditMode.value) {
    await hydrateForEdit(props.bookingId);
  }
});

onActivated(() => {
  initCreateFormState();
});

watch(
  () => [props.isEdit, props.bookingId],
  async ([edit, bid]) => {
    if (edit && bid) {
      await hydrateForEdit(bid);
    }
  },
);

watch(
  () => form.value.trip_type,
  (t) => {
    if (t !== 'round_trip') {
      form.value.return_date = '';
      form.value.return_time = '';
      form.value.return_arrival_time = '';
    }
    if (t === 'multi_city') {
      ensureMultiCityLegs();
    }
  },
);

watch(
  () => [form.value.currency, form.value.purchase_price_foreign, form.value.exchange_rate],
  () => syncPurchaseEgpFromForeign(),
  { immediate: true }
);

watch(
  () => [settlementCategoryUi.value, settlementAccounts.value],
  () => {
    const opts = settlementPickerOptions.value;
    if (!opts.length) {
      form.value.account_id = null;
      form.value.payment_method = defaultPaymentMethodForCategory(settlementCategoryUi.value);
      return;
    }
    const cur = form.value.account_id;
    if (cur != null && cur !== '' && opts.some((a) => sameId(a.id, cur))) {
      syncPaymentMethodFromSelectedAccount();
      return;
    }
    form.value.account_id = opts[0].id;
  },
  { immediate: true },
);

watch(
  () => form.value.account_id,
  () => {
    syncPaymentMethodFromSelectedAccount();
  },
);

watch(
  () => [Number(form.value.selling_price) || 0, Number(form.value.initial_payment) || 0],
  ([sell, pay]) => {
    if (pay < 0) {
      form.value.initial_payment = 0;
      return;
    }
    if (sell > 0 && pay > sell) {
      form.value.initial_payment = Math.round(sell * 100) / 100;
    }
  }
);

watch(
  () => form.value.customer,
  (customer) => {
    syncCustomerProfileFromSelection(customer);
    if (!customer) return;
    if (!form.value.passengers.length) {
      addPassenger('adult');
    }
  }
);
watch(
  () => form.value.customer_type,
  (type) => {
    if (type === 'counter') {
      form.value.initial_payment = 0;
      form.value.account_id = '';
      form.value.agent_name = '';
    }
    form.value.customer = null;
    syncCustomerProfileFromSelection(null);
    form.value.passengers = [];
    addPassenger('adult');
  }
);

watch(
  () => currentStep.value,
  (step) => {
    if (step === 4 && !form.value.passengers.length) {
      addPassenger('adult');
    }
  }
);

watch(
  () => form.value.flight_group_id,
  (groupId) => {
    if (form.value.booking_source === 'group' && groupId) {
      const selectedGroup = availableGroups.value.find(g => sameId(g.id, groupId));
      if (selectedGroup && selectedGroup.carrier) {
        form.value.currency = selectedGroup.carrier.currency || 'EGP';
      }
    }
  }
);
</script>
