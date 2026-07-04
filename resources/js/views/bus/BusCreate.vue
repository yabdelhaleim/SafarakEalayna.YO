<template>
  <div class="bus-booking mx-auto max-w-5xl space-y-8 pb-20" dir="rtl">

    <!-- Header -->
    <header class="relative overflow-hidden rounded-3xl border border-amber-500/20 bg-gradient-to-br from-[#1a1200] via-[#1c1500] to-[#0d0d0d] p-8 shadow-2xl">
      <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(245,158,11,0.12),_transparent_60%)]" />
      <div class="relative z-10 flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
          <router-link
            to="/bus"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-amber-300 transition hover:border-amber-400/40 hover:bg-amber-400/10"
          >
            <ArrowRight class="h-5 w-5" />
          </router-link>
          <div>
            <p class="text-[11px] font-bold uppercase tracking-widest text-amber-400/80">نظام حجز الباص</p>
            <h1 class="mt-0.5 text-2xl font-black text-white">حجز تذاكر جديد</h1>
            <p class="mt-1 text-sm text-white/50">اختر الشركة — حدد المسار والأسعار — سجّل العميل</p>
          </div>
        </div>
        <!-- Progress -->
        <div class="flex items-center gap-4">
          <div class="text-center">
            <div class="text-xs text-white/40">الخطوة</div>
            <div class="text-2xl font-black text-amber-400">{{ currentStep }}<span class="text-sm text-white/30">/{{ totalSteps }}</span></div>
          </div>
          <div class="relative h-16 w-16">
            <svg class="h-full w-full -rotate-90">
              <circle cx="32" cy="32" r="26" stroke="rgba(255,255,255,0.08)" stroke-width="4" fill="none"/>
              <circle cx="32" cy="32" r="26" stroke="#f59e0b" stroke-width="4" fill="none"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="progressOffset"
                class="transition-all duration-700"/>
            </svg>
            <div class="absolute inset-0 flex items-center justify-center text-xs font-black text-white">
              {{ Math.round((currentStep / totalSteps) * 100) }}%
            </div>
          </div>
        </div>
      </div>

      <!-- Step tabs -->
      <nav class="relative z-10 mt-6 flex gap-2">
        <button
          v-for="(label, i) in stepLabels" :key="i"
          type="button"
          :disabled="i + 1 > currentStep"
          @click="goToStep(i + 1)"
          class="flex flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-xs font-semibold transition-all"
          :class="[
            currentStep === i + 1 ? 'bg-amber-500 text-black shadow-lg shadow-amber-500/30'
              : isStepDone(i + 1) ? 'bg-green-500/20 text-green-400 border border-green-500/30'
              : 'bg-white/5 text-white/40 border border-white/10',
          ]"
        >
          <Check v-if="isStepDone(i + 1) && currentStep !== i + 1" class="h-3.5 w-3.5"/>
          <span v-else class="flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-black"
            :class="currentStep === i + 1 ? 'bg-black/30' : 'bg-white/10'">{{ i + 1 }}</span>
          <span class="hidden sm:inline">{{ label }}</span>
        </button>
      </nav>
    </header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">

        <!-- ══════════════════════════════════════
             STEP 1: Company + Route + Prices
        ══════════════════════════════════════ -->
        <transition enter-active-class="transition-all duration-400" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0">
          <div v-show="currentStep === 1" class="space-y-5">

            <!-- Company Cards -->
            <div class="rounded-2xl border border-white/10 bg-[#111111] p-6">
              <div class="mb-5 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                  <BusFront class="h-6 w-6"/>
                </div>
                <div>
                  <h2 class="text-base font-bold text-white">شركة النقل</h2>
                  <p class="text-xs text-white/40">اختر الشركة — المسار والأسعار في الخطوة التالية</p>
                </div>
              </div>

              <div v-if="store.loading.companies" class="flex items-center justify-center py-8 gap-3">
                <Loader2 class="h-6 w-6 animate-spin text-amber-400"/>
                <span class="text-sm text-white/50">جاري تحميل الشركات...</span>
              </div>

              <div v-else-if="!store.companies.length" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-300">
                لا توجد شركات باص مفعّلة. أضف شركات من
                <a :href="adminBusCompaniesUrl" target="_blank" class="font-bold underline">لوحة الإدارة</a>.
              </div>

              <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <button
                  v-for="company in store.companies" :key="company.id"
                  type="button"
                  @click="selectCompany(company)"
                  class="group relative flex flex-col items-center gap-2 rounded-xl border p-4 text-center transition-all duration-200"
                  :class="form.company_id === company.id
                    ? 'border-amber-400/60 bg-amber-400/10 shadow-lg shadow-amber-400/10'
                    : 'border-white/10 bg-white/[0.03] hover:border-white/20 hover:bg-white/[0.06]'"
                >
                  <div
                    class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-black"
                    :class="form.company_id === company.id ? 'bg-amber-400 text-black' : 'bg-white/10 text-white/70'"
                  >
                    {{ company.name.charAt(0) }}
                  </div>
                  <span class="text-xs font-semibold leading-tight"
                    :class="form.company_id === company.id ? 'text-amber-300' : 'text-white/70'">
                    {{ company.name }}
                  </span>
                  <Check v-if="form.company_id === company.id" class="absolute left-2 top-2 h-4 w-4 text-amber-400"/>
                </button>
              </div>
            </div>

            <!-- Route + Prices (shows after company selected) -->
            <transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
              <div v-if="form.company_id" class="rounded-2xl border border-white/10 bg-[#111111] p-6 space-y-6">
                <div class="flex items-center gap-3">
                  <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-500/15 text-sky-400">
                    <Map class="h-5 w-5"/>
                  </div>
                  <div>
                    <h3 class="text-sm font-bold text-white">المسار والأسعار</h3>
                    <p class="text-[11px] text-white/40">من أين إلى أين — سعر الشراء = مديونيتك للشركة</p>
                  </div>
                </div>

                <!-- From → To -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <label class="mb-2 block text-xs font-semibold text-white/60">من <span class="text-red-400">*</span></label>
                    <div class="relative">
                      <input
                        v-model="form.route_from"
                        type="text"
                        placeholder="المدينة / المحطة"
                        class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 pr-10 text-sm text-white placeholder-white/30 outline-none transition focus:border-sky-400/50 focus:ring-1 focus:ring-sky-400/20"
                        @input="fetchFromSuggestions"
                      />
                      <MapPin class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-sky-400/60"/>
                      <!-- Suggestions dropdown -->
                      <div v-if="fromSuggestions.length" class="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-white/15 bg-[#1a1a1a] shadow-2xl">
                        <button v-for="s in fromSuggestions" :key="s" type="button"
                          class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-white/80 hover:bg-white/10"
                          @click="form.route_from = s; fromSuggestions = []">
                          <MapPin class="h-3.5 w-3.5 text-amber-400/60 shrink-0"/>{{ s }}
                        </button>
                      </div>
                    </div>
                  </div>

                  <div>
                    <label class="mb-2 block text-xs font-semibold text-white/60">إلى <span class="text-red-400">*</span></label>
                    <div class="relative">
                      <input
                        v-model="form.route_to"
                        type="text"
                        placeholder="المدينة / المحطة"
                        class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 pr-10 text-sm text-white placeholder-white/30 outline-none transition focus:border-sky-400/50 focus:ring-1 focus:ring-sky-400/20"
                        @input="fetchToSuggestions"
                      />
                      <Flag class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-400/60"/>
                      <div v-if="toSuggestions.length" class="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-white/15 bg-[#1a1a1a] shadow-2xl">
                        <button v-for="s in toSuggestions" :key="s" type="button"
                          class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-white/80 hover:bg-white/10"
                          @click="form.route_to = s; toSuggestions = []">
                          <Flag class="h-3.5 w-3.5 text-emerald-400/60 shrink-0"/>{{ s }}
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Route preview -->
                <div v-if="routeLabel" class="flex items-center gap-3 rounded-xl border border-sky-500/20 bg-sky-500/5 px-4 py-3">
                  <MapPin class="h-4 w-4 shrink-0 text-sky-400"/>
                  <span class="font-mono text-sm font-semibold text-sky-200">{{ routeLabel }}</span>
                </div>

                <!-- Prices row -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <!-- Cost price (owed to company) -->
                  <div class="rounded-xl border border-red-500/20 bg-red-500/5 p-4">
                    <label class="mb-2 flex items-center gap-2 text-xs font-bold text-red-400">
                      <TrendingDown class="h-3.5 w-3.5"/> سعر الشراء (الآجل) <span class="text-red-500">*</span>
                    </label>
                    <p class="mb-3 text-[11px] text-white/40">المبلغ اللي ستدفعه للشركة عن كل تذكرة</p>
                    <div class="relative">
                      <input
                        v-model.number="form.cost_price"
                        type="number" min="0" step="0.5" placeholder="0.00"
                        class="w-full rounded-xl border border-red-500/30 bg-black/40 py-3 pr-4 pl-14 font-mono text-lg font-bold text-red-300 outline-none transition focus:border-red-400/60 focus:ring-1 focus:ring-red-400/20"
                      />
                      <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-red-400/60">ج.م</span>
                    </div>
                  </div>

                  <!-- Selling price (charged to customer) -->
                  <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                    <label class="mb-2 flex items-center gap-2 text-xs font-bold text-emerald-400">
                      <TrendingUp class="h-3.5 w-3.5"/> سعر البيع (للعميل) <span class="text-red-400">*</span>
                    </label>
                    <p class="mb-3 text-[11px] text-white/40">المبلغ اللي ستتقاضاه من العميل</p>
                    <div class="relative">
                      <input
                        v-model.number="form.selling_price"
                        type="number" min="0" step="0.5" placeholder="0.00"
                        class="w-full rounded-xl border border-emerald-500/30 bg-black/40 py-3 pr-4 pl-14 font-mono text-lg font-bold text-emerald-300 outline-none transition focus:border-emerald-400/60 focus:ring-1 focus:ring-emerald-400/20"
                      />
                      <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-emerald-400/60">ج.م</span>
                    </div>
                  </div>
                </div>

                <!-- Profit preview -->
                <div v-if="form.cost_price && form.selling_price" class="flex items-center justify-between rounded-xl border px-4 py-3 transition-colors"
                  :class="profitPerTicket >= 0 ? 'border-amber-500/20 bg-amber-500/5' : 'border-red-500/20 bg-red-500/5'">
                  <span class="text-xs text-white/50">الربح لكل تذكرة</span>
                  <span class="font-mono text-base font-black" :class="profitPerTicket >= 0 ? 'text-amber-400' : 'text-red-400'">
                    {{ profitPerTicket >= 0 ? '+' : '' }}{{ formatMoney(profitPerTicket) }}
                  </span>
                </div>

                <!-- Travel date (optional) -->
                <div>
                  <label class="mb-2 block text-xs font-semibold text-white/50">تاريخ السفر <span class="text-white/25">(اختياري)</span></label>
                  <input
                    v-model="form.travel_date"
                    type="date"
                    class="rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-400/50"
                  />
                </div>
              </div>
            </transition>

            <!-- Company Debt Summary -->
            <transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0" enter-to-class="opacity-100">
              <div v-if="form.company_id && companyStats" class="rounded-2xl border p-5 space-y-4"
                :class="companyStats.totalDebt > 0 ? 'border-red-500/25 bg-red-950/30' : 'border-green-500/25 bg-green-950/20'">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl"
                      :class="companyStats.totalDebt > 0 ? 'bg-red-500/15 text-red-400' : 'bg-green-500/15 text-green-400'">
                      <TrendingDown v-if="companyStats.totalDebt > 0" class="h-5 w-5"/>
                      <CheckCircle v-else class="h-5 w-5"/>
                    </div>
                    <div>
                      <p class="text-xs font-bold" :class="companyStats.totalDebt > 0 ? 'text-red-400' : 'text-green-400'">
                        مديونيتك الحالية لـ {{ selectedCompany?.name }}
                      </p>
                      <p class="text-[10px] text-white/40">إجمالي الحجوزات السابقة (آجل)</p>
                    </div>
                  </div>
                  <div class="text-left">
                    <div class="font-mono text-xl font-black" :class="companyStats.totalDebt > 0 ? 'text-red-400' : 'text-green-400'">
                      {{ formatMoney(companyStats.totalDebt) }}
                    </div>
                    <div class="text-[10px] text-white/40 text-left">{{ companyStats.totalTickets }} تذكرة محجوزة</div>
                  </div>
                </div>

                <!-- Stats row -->
                <div class="grid grid-cols-3 gap-3 border-t border-white/8 pt-4">
                  <div class="text-center">
                    <div class="text-[10px] text-white/40">إجمالي التذاكر</div>
                    <div class="font-mono text-lg font-black text-white">{{ companyStats.totalTickets }}</div>
                  </div>
                  <div class="text-center border-x border-white/8">
                    <div class="text-[10px] text-white/40">المدفوع للشركة</div>
                    <div class="font-mono text-lg font-black text-emerald-400">{{ formatMoney(companyStats.paidToCompany) }}</div>
                  </div>
                  <div class="text-center">
                    <div class="text-[10px] text-white/40">المتبقي عليك</div>
                    <div class="font-mono text-lg font-black text-red-400">{{ formatMoney(companyStats.totalDebt) }}</div>
                  </div>
                </div>
              </div>
            </transition>

          </div>
        </transition>

        <!-- ══════════════════════════════════════
             STEP 2: Customer
        ══════════════════════════════════════ -->
        <transition enter-active-class="transition-all duration-400" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0">
          <div v-show="currentStep === 2">
            <div class="rounded-2xl border border-white/10 bg-[#111111] p-6">
              <div class="mb-5 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-500/15 text-sky-400">
                  <UserCircle class="h-6 w-6"/>
                </div>
                <div>
                  <h2 class="text-base font-bold text-white">بيانات العميل</h2>
                  <p class="text-xs text-white/40">يُنشأ تلقائياً إن لم يكن مسجّلاً من قبل</p>
                </div>
              </div>

              <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                  <label class="mb-2 block text-xs font-semibold text-white/60">الاسم الكامل <span class="text-red-400">*</span></label>
                  <input
                    v-model="form.customer_name" type="text" placeholder="اسم العميل"
                    class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-white/30 outline-none transition focus:border-sky-400/50 focus:ring-1 focus:ring-sky-400/20"
                  />
                </div>
                <div>
                  <div class="mb-2 flex items-center justify-between">
                    <label class="text-xs font-semibold text-white/60">رقم الهاتف <span class="text-red-400">*</span></label>
                    <span v-if="searchingCustomer" class="flex items-center gap-1 text-[10px] text-sky-400">
                      <Loader2 class="h-3 w-3 animate-spin"/> جاري البحث...
                    </span>
                    <span v-else-if="customerFound" class="flex items-center gap-1 text-[10px] text-green-400">
                      <Check class="h-3 w-3"/> عميل موجود
                    </span>
                  </div>
                  <input
                    v-model="form.customer_phone" type="tel" placeholder="01xxxxxxxxx"
                    inputmode="numeric"
                    maxlength="11"
                    class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-white/30 outline-none transition focus:border-sky-400/50"
                    :class="customerFound ? 'border-green-500/30 bg-green-500/5' : (phoneValidationError ? 'border-red-500/50' : '')"
                    @input="onPhoneInput"
                    @blur="onPhoneBlurBus"
                  />
                  <p v-if="phoneValidationError" class="mt-1 text-xs text-red-400">{{ phoneValidationError }}</p>
                </div>
              </div>
            </div>
          </div>
        </transition>

        <!-- ══════════════════════════════════════
             STEP 3: Tickets + Payment
        ══════════════════════════════════════ -->
        <transition enter-active-class="transition-all duration-400" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0">
          <div v-show="currentStep === 3" class="space-y-5">

            <!-- Ticket counter -->
            <div class="rounded-2xl border border-white/10 bg-[#111111] p-6">
              <div class="mb-5 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                  <Ticket class="h-6 w-6"/>
                </div>
                <div>
                  <h2 class="text-base font-bold text-white">عدد التذاكر</h2>
                  <p class="text-xs text-white/40">
                    أخذت من {{ selectedCompany?.name }} إجمالي
                    <strong class="text-amber-400">{{ (companyStats?.totalTickets || 0) + form.seats_count }}</strong>
                    تذكرة (شاملاً هذا الحجز)
                  </p>
                </div>
              </div>

              <!-- Stepper -->
              <div class="flex items-center gap-4 mb-5">
                <button type="button"
                  @click="form.seats_count = Math.max(1, form.seats_count - 1)"
                  class="flex h-12 w-12 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-white transition hover:border-white/30 hover:bg-white/10 active:scale-95">
                  <Minus class="h-4 w-4"/>
                </button>
                <input
                  v-model.number="form.seats_count" type="number" min="1" max="500"
                  class="w-24 rounded-xl border border-amber-400/30 bg-amber-400/5 py-3 text-center font-mono text-2xl font-black text-amber-400 outline-none focus:border-amber-400/60"
                />
                <button type="button"
                  @click="form.seats_count = Math.min(500, form.seats_count + 1)"
                  class="flex h-12 w-12 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-white transition hover:border-white/30 hover:bg-white/10 active:scale-95">
                  <Plus class="h-4 w-4"/>
                </button>
                <div class="mr-2">
                  <div class="text-xs text-white/40">إجمالي الحجز</div>
                  <div class="font-mono text-2xl font-black text-amber-400">{{ formatMoney(sellingTotal) }}</div>
                </div>
              </div>

              <!-- Quick presets -->
              <div class="flex flex-wrap gap-2">
                <button v-for="n in [1,2,3,4,5,10,15,20]" :key="n" type="button"
                  @click="form.seats_count = n"
                  class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                  :class="form.seats_count === n ? 'border-amber-400/50 bg-amber-400/15 text-amber-400' : 'border-white/10 bg-white/5 text-white/50 hover:border-white/20'">
                  {{ n }}
                </button>
              </div>

              <!-- Financial breakdown for this booking -->
              <div class="mt-5 grid grid-cols-3 gap-3 rounded-xl border border-white/8 bg-white/[0.02] p-4">
                <div class="text-center">
                  <div class="text-[10px] text-white/40">سعر البيع × {{ form.seats_count }}</div>
                  <div class="font-mono text-base font-black text-emerald-400">{{ formatMoney(sellingTotal) }}</div>
                  <div class="text-[10px] text-emerald-400/60">إيرادك من العميل</div>
                </div>
                <div class="text-center border-x border-white/8">
                  <div class="text-[10px] text-white/40">سعر الشراء × {{ form.seats_count }}</div>
                  <div class="font-mono text-base font-black text-red-400">{{ formatMoney(costTotal) }}</div>
                  <div class="text-[10px] text-red-400/60">مديونيتك للشركة</div>
                </div>
                <div class="text-center">
                  <div class="text-[10px] text-white/40">الربح الصافي</div>
                  <div class="font-mono text-base font-black" :class="profit >= 0 ? 'text-amber-400' : 'text-red-400'">
                    {{ profit >= 0 ? '+' : '' }}{{ formatMoney(profit) }}
                  </div>
                  <div class="text-[10px] text-white/40">لكل {{ form.seats_count }} تذكرة</div>
                </div>
              </div>
            </div>

            <!-- Payment from customer -->
            <div class="rounded-2xl border border-white/10 bg-[#111111] p-6">
              <div class="mb-5 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
                  <CreditCard class="h-6 w-6"/>
                </div>
                <div>
                  <h2 class="text-base font-bold text-white">تحصيل العميل</h2>
                  <p class="text-xs text-white/40">المبلغ المحصّل من العميل الآن (صفر = آجل على العميل)</p>
                </div>
              </div>

              <!-- Category chips -->
              <div class="mb-4 flex flex-wrap gap-2">
                <button v-for="chip in settlementCategoryChips" :key="chip.id" type="button"
                  @click="setSettlementCategory(chip.id)"
                  class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                  :class="settlementCategoryUi === chip.id ? 'border-amber-400/50 bg-amber-400/15 text-amber-400' : 'border-white/10 bg-white/[0.04] text-white/50 hover:border-white/20'">
                  <component :is="chip.icon" class="h-3.5 w-3.5"/>{{ chip.label }}
                </button>
              </div>

              <!-- Account picker -->
              <div class="mb-4">
                <label class="mb-2 block text-xs font-semibold text-white/60">{{ settlementPickerLabel }}</label>
                <select v-model="form.account_id"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-400/50"
                  :disabled="!settlementPickerOptions.length">
                  <option :value="null">{{ settlementPickerEmptyText }}</option>
                  <option v-for="acc in settlementPickerOptions" :key="acc.id" :value="acc.id">
                    {{ formatSettlementPickerOption(acc) }}
                  </option>
                </select>
              </div>

              <!-- Amount input -->
              <div>
                <label class="mb-2 block text-xs font-semibold text-white/60">المبلغ المحصّل الآن</label>
                <div class="relative max-w-sm mb-3">
                  <input v-model.number="form.paid_amount" type="number" step="0.01" min="0" :max="sellingTotal"
                    class="w-full rounded-xl border border-white/15 bg-white/[0.06] py-3 pr-4 pl-14 font-mono text-sm text-white outline-none transition focus:border-emerald-400/50"/>
                  <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/40">ج.م</span>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button v-for="pct in [25, 50, 75, 100]" :key="pct" type="button"
                    @click="form.paid_amount = roundMoney((sellingTotal * pct) / 100)"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white/50 transition hover:border-amber-400/40 hover:bg-amber-400/10 hover:text-amber-400">
                    {{ pct }}٪
                  </button>
                  <button type="button" @click="form.paid_amount = 0"
                    class="rounded-lg border border-orange-500/30 bg-orange-500/10 px-3 py-1.5 text-xs font-semibold text-orange-400 transition hover:bg-orange-500/20">
                    آجل كامل
                  </button>
                </div>
                <p v-if="paymentAmountError" class="mt-2 text-xs text-red-400">{{ paymentAmountError }}</p>
              </div>
            </div>

            <!-- Notes -->
            <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
              <label class="mb-2 block text-xs font-semibold text-white/60">ملاحظات <span class="text-white/25">(اختياري)</span></label>
              <textarea v-model="form.notes" rows="3" placeholder="أي ملاحظات..."
                class="w-full resize-none rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-white/30 outline-none transition focus:border-white/30"/>
            </div>
          </div>
        </transition>

        <!-- ══════════════════════════════════════
             STEP 4: Review
        ══════════════════════════════════════ -->
        <transition enter-active-class="transition-all duration-400" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0">
          <div v-show="currentStep === 4">
            <div class="rounded-2xl border border-amber-500/20 bg-[#111111] p-6">
              <div class="mb-5 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                  <FileText class="h-6 w-6"/>
                </div>
                <div>
                  <h2 class="text-base font-bold text-white">مراجعة الحجز</h2>
                  <p class="text-xs text-white/40">تأكد من كل البيانات قبل التأكيد النهائي</p>
                </div>
              </div>

              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-[10px] font-bold uppercase tracking-wider text-white/40">الشركة</dt>
                  <dd class="mt-1 font-semibold text-white">{{ selectedCompany?.name || '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-[10px] font-bold uppercase tracking-wider text-white/40">المسار</dt>
                  <dd class="mt-1 font-semibold text-white">{{ routeLabel || '—' }}</dd>
                  <dd v-if="form.travel_date" class="text-xs text-white/40">{{ formatDate(form.travel_date) }}</dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-[10px] font-bold uppercase tracking-wider text-white/40">العميل</dt>
                  <dd class="mt-1 font-semibold text-white">{{ form.customer_name || '—' }}</dd>
                  <dd class="text-xs text-white/40">{{ form.customer_phone || '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-[10px] font-bold uppercase tracking-wider text-white/40">التذاكر</dt>
                  <dd class="mt-1 font-semibold text-white">
                    {{ form.seats_count }} تذكرة
                    <span class="text-white/40">× {{ formatMoney(form.selling_price || 0) }}</span>
                  </dd>
                </div>

                <!-- Full financial summary -->
                <div class="col-span-full rounded-xl border border-white/10 bg-white/[0.02] p-5">
                  <dt class="mb-4 text-[10px] font-bold uppercase tracking-wider text-white/40">الحسابات التفصيلية</dt>
                  <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between">
                      <span class="text-white/50">إجمالي البيع (للعميل)</span>
                      <span class="font-mono font-bold text-emerald-400">{{ formatMoney(sellingTotal) }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-white/50">مدفوع من العميل</span>
                      <span class="font-mono font-bold text-white">{{ formatMoney(form.paid_amount || 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-white/50">آجل على العميل</span>
                      <span class="font-mono font-bold text-orange-400">{{ formatMoney(customerRemainder) }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-white/50">مديونية للشركة (آجل)</span>
                      <span class="font-mono font-bold text-red-400">{{ formatMoney(costTotal) }}</span>
                    </div>
                    <div class="col-span-full border-t border-white/10 pt-3 flex justify-between">
                      <span class="font-semibold text-white/70">الربح الصافي من الحجز</span>
                      <span class="font-mono text-lg font-black" :class="profit >= 0 ? 'text-amber-400' : 'text-red-400'">
                        {{ profit >= 0 ? '+' : '' }}{{ formatMoney(profit) }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="costTotal > 0" class="mt-4 flex items-start gap-3 rounded-xl border border-red-500/20 bg-red-500/5 p-4">
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-red-400"/>
                <p class="text-xs text-red-200/80">
                  سيُضاف <strong class="text-red-400">{{ formatMoney(costTotal) }}</strong>
                  كمديونية آجل على حساب <strong>{{ selectedCompany?.name }}</strong>.
                  يمكن تسديدها لاحقاً من صفحة الشركات.
                </p>
              </div>
            </div>
          </div>
        </transition>

        <!-- Navigation -->
        <div class="flex items-center justify-between gap-4">
          <button type="button" :disabled="currentStep <= 1" @click="previousStep"
            class="flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white/70 transition hover:border-white/25 hover:text-white disabled:cursor-not-allowed disabled:opacity-30">
            <ChevronRight class="h-4 w-4"/> السابق
          </button>
          <div class="flex items-center gap-3">
            <router-link to="/bus" class="rounded-xl border border-white/10 px-5 py-3 text-sm text-white/50 transition hover:text-white">
              إلغاء
            </router-link>
            <button v-if="currentStep < totalSteps" type="button" :disabled="!canProceed" @click="nextStep"
              class="flex items-center gap-2 rounded-xl bg-amber-500 px-6 py-3 text-sm font-bold text-black shadow-lg shadow-amber-500/25 transition hover:bg-amber-400 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40">
              التالي <ChevronLeft class="h-4 w-4"/>
            </button>
            <button v-else type="button" :disabled="!canSubmit || submitting" @click="handleSubmit"
              class="flex items-center gap-2 rounded-xl bg-amber-500 px-7 py-3 text-sm font-bold text-black shadow-lg shadow-amber-500/30 transition hover:bg-amber-400 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40">
              <Loader2 v-if="submitting" class="h-4 w-4 animate-spin"/>
              <CheckCircle v-else class="h-4 w-4"/>
              {{ submitting ? 'جاري الحفظ...' : 'تأكيد الحجز' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ═══ SIDEBAR ═══ -->
      <aside class="space-y-5 lg:col-span-1">
        <div class="sticky top-6 space-y-4">

          <!-- Live financial card -->
          <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
            <h4 class="mb-4 text-[11px] font-bold uppercase tracking-widest text-white/40">ملخص مالي</h4>

            <div v-if="selectedCompany" class="mb-3 flex items-center gap-2 rounded-xl bg-white/5 px-3 py-2.5">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-400/15 text-xs font-black text-amber-400">
                {{ selectedCompany.name.charAt(0) }}
              </div>
              <div class="min-w-0">
                <div class="truncate text-xs font-semibold text-white">{{ selectedCompany.name }}</div>
                <div class="text-[10px] text-white/40">{{ routeLabel || 'لم يُحدَّد المسار' }}</div>
              </div>
            </div>

            <div class="space-y-2 text-sm">
              <div class="flex justify-between text-white/50">
                <span>سعر الشراء</span>
                <span class="font-mono text-red-400">{{ formatMoney(form.cost_price || 0) }}</span>
              </div>
              <div class="flex justify-between text-white/50">
                <span>سعر البيع</span>
                <span class="font-mono text-emerald-400">{{ formatMoney(form.selling_price || 0) }}</span>
              </div>
              <div class="flex justify-between text-white/50">
                <span>عدد التذاكر</span>
                <span class="font-mono text-white">× {{ form.seats_count }}</span>
              </div>
              <div class="flex justify-between border-t border-white/10 pt-2 text-white/50">
                <span>إجمالي البيع</span>
                <span class="font-mono font-black text-emerald-400">{{ formatMoney(sellingTotal) }}</span>
              </div>
              <div class="flex justify-between text-white/50">
                <span>مديونية الشركة</span>
                <span class="font-mono font-bold text-red-400">{{ formatMoney(costTotal) }}</span>
              </div>
              <div class="flex justify-between border-t border-white/10 pt-2">
                <span class="text-white/70">الربح</span>
                <span class="font-mono font-black text-lg" :class="profit >= 0 ? 'text-amber-400' : 'text-red-400'">
                  {{ profit >= 0 ? '+' : '' }}{{ formatMoney(profit) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Steps -->
          <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
            <h4 class="mb-3 text-[11px] font-bold uppercase tracking-widest text-white/40">الخطوات</h4>
            <div class="space-y-2">
              <div v-for="(label, i) in stepLabels" :key="i"
                class="flex items-center gap-3 text-sm"
                :class="isStepDone(i+1) ? 'text-emerald-400' : currentStep === i+1 ? 'text-amber-400' : 'text-white/30'">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                  :class="isStepDone(i+1) ? 'bg-emerald-500/20' : currentStep === i+1 ? 'bg-amber-500 text-black' : 'bg-white/8'">
                  <Check v-if="isStepDone(i+1)" class="h-3.5 w-3.5"/>
                  <span v-else>{{ i+1 }}</span>
                </div>
                {{ label }}
              </div>
            </div>
          </div>

          <!-- Deferred info -->
          <div class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-4 text-xs leading-relaxed">
            <p class="mb-1 font-bold text-orange-400">📋 نظام الآجل</p>
            <p class="text-white/50">سعر الشراء = ما تدينه للشركة. تُسجَّل كمديونية تُسدَّد من
              <router-link to="/bus/companies" class="text-amber-400 underline">صفحة الشركات</router-link>.
            </p>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useBusStore } from '@/stores/busStore';
import { enforcePhoneInput, validateEgyptianPhone } from '@/utils/phoneValidation';
import {
  ArrowRight, BusFront, UserCircle, Ticket, CreditCard, FileText,
  Check, CheckCircle, Loader2, Wallet, Landmark, Banknote,
  ChevronRight, ChevronLeft, Map, MapPin, Flag, Minus, Plus,
  TrendingDown, TrendingUp, AlertCircle,
} from 'lucide-vue-next';

const router = useRouter();
const store  = useBusStore();

// ─── Constants ─────────────────────────────────────────────────────────────
const totalSteps    = 4;
const stepLabels    = ['الشركة والمسار', 'بيانات العميل', 'التذاكر والدفع', 'المراجعة'];
const circumference = 2 * Math.PI * 26;

// ─── State ─────────────────────────────────────────────────────────────────
const currentStep      = ref(1);
const submitting       = ref(false);
const searchingCustomer = ref(false);
const customerFound    = ref(false);
const companyStats     = ref(null);
const fromSuggestions  = ref([]);
const toSuggestions    = ref([]);
let phoneTimeout, suggTimeout;
const phoneValidationError = ref('');

function createDefaultForm() {
  return {
    company_id:     null,
    route_from:     '',
    route_to:       '',
    cost_price:     null,   // سعر الشراء — مديونية الشركة
    selling_price:  null,   // سعر البيع — ما يدفعه العميل
    travel_date:    '',
    customer_name:  '',
    customer_phone: '',
    seats_count:    1,
    paid_amount:    0,
    notes:          '',
    account_id:     null,
    payment_method: 'cash',
  };
}

const form = ref(createDefaultForm());

function resetBookingForm() {
  currentStep.value = 1;
  submitting.value = false;
  searchingCustomer.value = false;
  customerFound.value = false;
  companyStats.value = null;
  fromSuggestions.value = [];
  toSuggestions.value = [];
  settlementCategoryUi.value = 'cash';
  form.value = createDefaultForm();
}

const settlementAccounts   = ref([]);
const settlementCategoryUi = ref('cash');

// ─── Settlement chips ──────────────────────────────────────────────────────
const settlementCategoryChips = [
  { id: 'cash',   label: 'نقدي / خزينة', icon: Banknote  },
  { id: 'wallet', label: 'محافظ',          icon: Wallet    },
  { id: 'bank',   label: 'بنك',            icon: Landmark  },
];

const SETTLEMENT_CATEGORY_TYPES = {
  cash:   ['cashbox', 'treasury'],
  wallet: ['wallet'],
  bank:   ['bank', 'post'],
};

const WALLET_PROVIDER_AR = {
  vodafone_cash: 'فودافون كاش', instapay: 'إنستاباي', cash_wallet: 'محفظة كاش',
  etisalat_cash: 'اتصالات كاش', orange_cash: 'أورانج كاش',
  we_pay: 'WE Pay', paymob: 'Paymob', postal: 'بريد', other: 'أخرى',
};

const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة نقدي', wallet: 'محفظة', bank: 'حساب بنكي', treasury: 'خزينة عامة', post: 'بريد',
};

// ─── Admin URLs ────────────────────────────────────────────────────────────
const adminBusCompaniesUrl = computed(() => `${window.location.origin}/admin/bus-companies`);

// ─── Computed ──────────────────────────────────────────────────────────────
const progressOffset  = computed(() => circumference * (1 - currentStep.value / totalSteps));
const selectedCompany = computed(() => store.companies.find(c => String(c.id) === String(form.value.company_id)));
const routeLabel      = computed(() => {
  const from = (form.value.route_from || '').trim();
  const to   = (form.value.route_to   || '').trim();
  if (!from && !to) return '';
  if (!to) return from;
  if (!from) return to;
  return `${from} ← ${to}`;
});
const fullRoute = computed(() => {
  const from = (form.value.route_from || '').trim();
  const to   = (form.value.route_to   || '').trim();
  if (!from && !to) return '';
  return `${from} - ${to}`;
});

const profitPerTicket = computed(() =>
  (Number(form.value.selling_price) || 0) - (Number(form.value.cost_price) || 0)
);
const sellingTotal = computed(() =>
  (Number(form.value.selling_price) || 0) * (Number(form.value.seats_count) || 0)
);
const costTotal = computed(() =>
  (Number(form.value.cost_price) || 0) * (Number(form.value.seats_count) || 0)
);
const profit = computed(() => sellingTotal.value - costTotal.value);

const customerRemainder = computed(() =>
  Math.max(0, sellingTotal.value - (Number(form.value.paid_amount) || 0))
);
const paymentAmountError = computed(() => {
  const p = Number(form.value.paid_amount) || 0;
  if (p < 0) return 'المبلغ لا يمكن أن يكون سالباً';
  if (p > sellingTotal.value + 0.001) return 'المبلغ لا يتجاوز إجمالي الحجز';
  return '';
});

// ─── Normalization helpers ─────────────────────────────────────────────────
const normalizeAccountType    = (raw) => {
  if (raw == null) return '';
  if (typeof raw === 'string') return raw.trim().toLowerCase();
  if (typeof raw === 'object' && 'value' in raw) return String(raw.value).trim().toLowerCase();
  return String(raw).trim().toLowerCase();
};
const normalizeMethodCode     = (raw) => String(raw ?? '').trim().toLowerCase().replace(/-/g, '_');
const normalizeWalletProvider = (raw) => normalizeMethodCode(raw?.value ?? raw);
const sameId = (a, b)         => a != null && b != null && String(a) === String(b);

// ─── Settlement picker ─────────────────────────────────────────────────────
const settlementPickerOptions = computed(() => {
  const types = SETTLEMENT_CATEGORY_TYPES[settlementCategoryUi.value];
  if (!types?.length) return [];
  const want = new Set(types);
  return settlementAccounts.value
    .filter(a => a.is_active !== false && want.has(normalizeAccountType(a.type)))
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' }));
});
const settlementPickerLabel = computed(() => {
  if (settlementCategoryUi.value === 'wallet') return 'محفظة التحصيل';
  if (settlementCategoryUi.value === 'bank')   return 'حساب التحصيل (بنك)';
  return 'حساب التحصيل (نقدي / خزينة)';
});
const settlementPickerEmptyText = computed(() => {
  if (!settlementAccounts.value.length) return 'لا توجد حسابات محملة…';
  if (!settlementPickerOptions.value.length) return 'لا يوجد حساب في هذا التصنيف';
  return '— اختر الحساب —';
});
const formatSettlementPickerOption = (account) => {
  const t   = normalizeAccountType(account.type);
  const bal = formatMoney(account.balance ?? 0);
  if (t === 'wallet') {
    const prov = normalizeWalletProvider(account.wallet_provider ?? account.walletProvider);
    const pl   = WALLET_PROVIDER_AR[prov] || 'محفظة';
    const num  = String(account.wallet_number ?? '').trim();
    return `${account.name} — ${pl}${num ? ' — ' + num : ''} — ${bal}`;
  }
  return `${account.name} — ${ACCOUNT_TYPE_LABELS[t] || t} — ${bal}`;
};

// ─── Step validation ───────────────────────────────────────────────────────
const isStepDone = (step) => {
  switch (step) {
    case 1:
      return !!form.value.company_id
        && String(form.value.route_from || '').trim().length > 0
        && String(form.value.route_to   || '').trim().length > 0
        && (Number(form.value.cost_price)    || 0) > 0
        && (Number(form.value.selling_price) || 0) > 0;
    case 2:
      return String(form.value.customer_name  || '').trim().length > 0
          && String(form.value.customer_phone || '').trim().length > 0;
    case 3: {
      const seatsOk = (Number(form.value.seats_count) || 0) >= 1;
      if (!seatsOk || paymentAmountError.value) return false;
      if ((Number(form.value.paid_amount) || 0) > 0.009) return !!form.value.account_id;
      return true;
    }
    case 4: return isStepDone(1) && isStepDone(2) && isStepDone(3);
    default: return false;
  }
};
const canProceed = computed(() => {
  switch (currentStep.value) {
    case 1: return isStepDone(1);
    case 2: return isStepDone(2);
    case 3: return isStepDone(3);
    default: return true;
  }
});
const canSubmit = computed(() => isStepDone(4) && !paymentAmountError.value);

// ─── Navigation ────────────────────────────────────────────────────────────
const nextStep     = () => { if (canProceed.value && currentStep.value < totalSteps) currentStep.value++; };
const previousStep = () => { if (currentStep.value > 1) currentStep.value--; };
const goToStep     = (s) => { if (s >= 1 && s < currentStep.value) currentStep.value = s; };

// ─── Formatting ────────────────────────────────────────────────────────────
const formatMoney = (amount) => {
  const n = Number(amount) || 0;
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 2 }).format(n);
  } catch { return `${n.toFixed(2)} ج.م`; }
};
const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;
const formatDate  = (d) => {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });
};

// ─── Company selection + stats ─────────────────────────────────────────────
const selectCompany = async (company) => {
  form.value.company_id = company.id;
  companyStats.value    = null;
  fromSuggestions.value = [];
  toSuggestions.value   = [];

  try {
    // Fetch all company bookings (fallback for ticket count — primary debt source is account balance below)
    const res = await axios.get('/api/v1/bus/bookings', {
      params: { company_id: company.id, per_page: 500 },
    });
    const items = res.data?.data?.items || res.data?.data || [];
    let totalTickets  = 0;
    let totalCost     = 0;
    let paidToCompany = 0;

    items.forEach(b => {
      if (b.status !== 'cancelled') {
        totalTickets  += Number(b.quantity || b.seats_count || 0);
        totalCost     += Number(b.total_price || 0);
        paidToCompany += Number(b.paid_amount || 0);
      }
    });

    // Also try to get company account balance for real debt figure
    try {
      const compRes = await axios.get(`/api/v1/bus/companies/${company.id}`);
      const balance  = compRes.data?.data?.account?.balance ?? null;
      if (balance !== null) {
        // Company account balance in our ledger: negative = we owe them
        companyStats.value = {
          totalTickets,
          totalDebt:     Math.max(0, -Number(balance)),
          paidToCompany: Math.max(0, Number(balance)),
        };
        return;
      }
    } catch { /* fallback below */ }

    companyStats.value = {
      totalTickets,
      totalDebt:     Math.max(0, totalCost - paidToCompany),
      paidToCompany,
    };
  } catch {
    companyStats.value = { totalTickets: 0, totalDebt: 0, paidToCompany: 0 };
  }
};

// ─── Route suggestions ─────────────────────────────────────────────────────
const buildRouteSuggestions = async (field, query, target) => {
  if (!form.value.company_id || query.length < 2) { target.value = []; return; }
  clearTimeout(suggTimeout);
  suggTimeout = setTimeout(async () => {
    try {
      const res = await axios.get('/api/v1/bus/inventories', {
        params: { company_id: form.value.company_id, per_page: 50 },
      });
      const items = res.data?.data?.items || res.data?.data || [];
      const seen  = new Set();
      target.value = items
        .map(i => {
          const parts = String(i.route || '').split('-');
          return field === 'from' ? parts[0]?.trim() : parts[1]?.trim();
        })
        .filter(r => r && r.includes(query) && !seen.has(r) && seen.add(r))
        .slice(0, 5);
    } catch { target.value = []; }
  }, 300);
};

const fetchFromSuggestions = () =>
  buildRouteSuggestions('from', form.value.route_from.trim(), fromSuggestions);
const fetchToSuggestions   = () =>
  buildRouteSuggestions('to',   form.value.route_to.trim(),   toSuggestions);

// ─── Customer search ───────────────────────────────────────────────────────
const onPhoneInput = () => {
  // Enforce digits-only and max 11 chars
  form.value.customer_phone = enforcePhoneInput(form.value.customer_phone);
  phoneValidationError.value = '';
  clearTimeout(phoneTimeout);
  customerFound.value = false;
  if ((form.value.customer_phone || '').length >= 10)
    phoneTimeout = setTimeout(searchCustomer, 500);
};
const onPhoneBlurBus = () => {
  phoneValidationError.value = validateEgyptianPhone(form.value.customer_phone);
};
const searchCustomer = async () => {
  if (!form.value.customer_phone || form.value.customer_phone.length < 10) return;
  searchingCustomer.value = true;
  try {
    const res = await axios.get('/api/v1/customers', { params: { search: form.value.customer_phone } });
    const customers = res.data?.data || [];
    if (customers.length > 0) {
      if (!form.value.customer_name) form.value.customer_name = customers[0].full_name;
      customerFound.value = true;
    }
  } catch { /* silent */ }
  finally { searchingCustomer.value = false; }
};

// ─── Settlement ────────────────────────────────────────────────────────────
const setSettlementCategory = (id) => {
  settlementCategoryUi.value = id;
  form.value.account_id = null;
};

const syncPaymentMethod = () => {
  const acc = settlementAccounts.value.find(x => sameId(x.id, form.value.account_id));
  if (!acc) { form.value.payment_method = 'cash'; return; }
  const t = normalizeAccountType(acc.type);
  if (t === 'wallet') {
    const p = normalizeWalletProvider(acc.wallet_provider);
    form.value.payment_method = { vodafone_cash: 'vodafone_cash', instapay: 'instapay' }[p] || 'cash_wallet';
  } else if (t === 'bank') { form.value.payment_method = 'bank_transfer'; }
  else if (t === 'post') { form.value.payment_method = 'postal_transfer'; }
  else { form.value.payment_method = 'cash'; }
};

const mapPaymentMethodForApi = (code) => {
  const c = normalizeMethodCode(code);
  const allowed = new Set(['cash','bank_transfer','cash_wallet','postal_transfer','office_safe','office_drawer']);
  if (allowed.has(c)) return c;
  if (['vodafone_cash','instapay'].includes(c)) return 'cash_wallet';
  return 'cash';
};

// ─── Data loading ──────────────────────────────────────────────────────────
const loadSettlementAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/bus/treasury/overview');
    const raw = res.data?.data?.settlement_accounts;
    settlementAccounts.value = Array.isArray(raw) ? raw : [];
  } catch { settlementAccounts.value = []; }
};

// ─── Submit ────────────────────────────────────────────────────────────────
const handleSubmit = async () => {
  if (!canSubmit.value || submitting.value) return;
  submitting.value = true;

  try {
    const booking = await store.createBooking({
      // Manual mode: no inventory_id
      company_id:    form.value.company_id,
      route:         fullRoute.value,          // "القاهرة - أسوان"
      selling_price: form.value.selling_price, // سعر البيع للعميل
      cost_price:    form.value.cost_price,    // سعر الشراء = مديونية الشركة
      travel_date:   form.value.travel_date || null,
      // Customer
      customer_name:  form.value.customer_name,
      customer_phone: form.value.customer_phone,
      // Booking
      quantity:       form.value.seats_count,
      notes:          form.value.notes || null,
      total_price:    sellingTotal.value,
    });

    const id     = booking?.id;
    const payAmt = roundMoney(Number(form.value.paid_amount) || 0);

    if (id && payAmt > 0 && form.value.account_id) {
      try {
        await store.payBooking(id, {
          amount:         payAmt,
          payment_method: mapPaymentMethodForApi(form.value.payment_method),
          account_id:     form.value.account_id,
          notes:          form.value.notes || null,
        });
      } catch (payErr) {
        store.addToast('تم إنشاء الحجز لكن فشل تسجيل الدفع. سجّل الدفع من صفحة الحجز.', 'warning');
        router.push(`/bus/${id}`);
        return;
      }
    }

    store.addToast('تم إضافة الحجز بنجاح ✓', 'success');
    router.push(`/bus/${id}`);

  } catch (error) {
    const api  = error.response?.data;
    const errs = api?.errors;
    let detail  = api?.message || error.message || 'فشل الحجز';
    if (errs && typeof errs === 'object') {
      detail += '\n' + Object.entries(errs).map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(' — ') : v}`).join('\n');
    }
    store.addToast(detail, 'error');
  } finally {
    submitting.value = false;
  }
};

// ─── Watchers ──────────────────────────────────────────────────────────────
watch(() => form.value.account_id, syncPaymentMethod);

watch(
  () => [settlementCategoryUi.value, settlementAccounts.value],
  () => {
    const opts = settlementPickerOptions.value;
    if (form.value.account_id && !opts.some(a => sameId(a.id, form.value.account_id)))
      form.value.account_id = null;
    syncPaymentMethod();
  }
);

watch(sellingTotal, (t) => {
  if ((Number(form.value.paid_amount) || 0) > t)
    form.value.paid_amount = roundMoney(t);
});

// ─── Also update the backend request to pass cost_price ───────────────────
// We need to also update the store to pass cost_price through
// The backend will use cost_price as cost_per_ticket in the auto-inventory

// ─── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(async () => {
  resetBookingForm();
  await store.fetchCompanies();
  await loadSettlementAccounts();
});

onActivated(() => {
  resetBookingForm();
});
</script>
