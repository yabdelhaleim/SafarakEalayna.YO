<template>
  <div class="bus-booking mx-auto max-w-6xl space-y-8 pb-16">
    <header class="flight-hero relative border-amber-500/10">
      <div class="relative z-10 flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
          <router-link
            to="/bus"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة لقائمة حجوزات الباص"
          >
            <ArrowRight class="h-5 w-5 text-amber-300/90" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-amber-400/90">
              نظام حجز الباص
            </p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
              حجز باص جديد
            </h1>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-text-muted">
              الرحلات والأسعار تُعرَّف من لوحة الإدارة (Filament). هنا تسجّل الحجز والتحصيل بنفس حسابات
              الخزينة والبنوك والمحافظ المفعّلة في النظام.
            </p>
          </div>
        </div>
        <div class="flex shrink-0 flex-col items-stretch gap-4 sm:flex-row sm:items-center">
          <div
            class="flex items-center gap-4 rounded-2xl border border-white/10 bg-black/25 px-5 py-4 backdrop-blur-sm"
          >
            <div class="text-left sm:text-right">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">التقدم</div>
              <div class="text-lg font-black text-gold">
                {{ currentStep }}
                <span class="text-text-muted">/</span>
                {{ totalSteps }}
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
                  class="text-amber-400 transition-all duration-500"
                  :stroke-dasharray="circumference"
                  :stroke-dashoffset="progressOffset"
                />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-[11px] font-black text-text-main">
                  {{ Math.round((currentStep / totalSteps) * 100) }}%
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <nav class="relative z-10 mt-8 flight-stepper" aria-label="خطوات حجز الباص">
        <button
          v-for="step in totalSteps"
          :key="step"
          type="button"
          :disabled="step > currentStep"
          :class="[
            'flight-step max-w-[140px] flex-1 justify-center sm:max-w-none',
            currentStep === step && 'flight-step--active',
            isStepDone(step) && 'flight-step--done',
            step > currentStep && !isStepDone(step) && 'opacity-40',
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
            <Check v-if="isStepDone(step)" class="h-3.5 w-3.5" />
            <span v-else>{{ step }}</span>
          </span>
          <span class="hidden truncate sm:inline">{{ stepLabels[step - 1] }}</span>
        </button>
      </nav>
    </header>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <!-- Step 1: Route -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 1" class="space-y-6">
            <div class="flight-panel">
              <div class="mb-6 flex items-center gap-3">
                <div class="dashboard-kpi__icon !h-12 !w-12 shrink-0 !from-amber-600 !to-amber-500 !shadow-amber-500/25">
                  <BusFront class="h-6 w-6" />
                </div>
                <div>
                  <h2 class="flight-panel__title">اختيار الرحلة</h2>
                  <p class="flight-panel__subtitle">الشركة والمخزون يُداران من Filament؛ يظهر هنا ما هو متاح للبيع فقط.</p>
                </div>
              </div>

              <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                  <label class="mb-2 block text-sm font-medium text-text-muted">شركة النقل <span class="text-error">*</span></label>
                  <select
                    v-model="form.company_id"
                    required
                    class="flight-select"
                    @change="onCompanyChange"
                  >
                    <option value="">— اختر الشركة —</option>
                    <option v-for="company in store.companies" :key="company.id" :value="company.id">
                      {{ company.name }}
                    </option>
                  </select>
                </div>

                <div v-if="form.company_id && availableInventory.length === 0 && !store.loading.inventory">
                  <p class="rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
                    لا توجد رحلات متاحة (مقاعد &gt; 0) لهذه الشركة. أضف مخزوناً من
                    <a
                      :href="adminBusInventoriesUrl"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="font-bold text-gold underline-offset-2 hover:underline"
                    >لوحة الإدارة</a>.
                  </p>
                </div>

                <div v-if="availableInventory.length > 0" class="md:col-span-2">
                  <label class="mb-2 block text-sm font-medium text-text-muted">الرحلة <span class="text-error">*</span></label>
                  <select v-model="form.inventory_id" required class="flight-select">
                    <option value="">— اختر الرحلة —</option>
                    <option v-for="item in availableInventory" :key="item.id" :value="item.id">
                      {{ item.route }} | {{ formatTime(item.departure_time) }} |
                      {{ formatDate(item.travel_date) }} | السعر: {{ formatMoney(item.selling_price) }} | متاح: {{ item.available_tickets }}
                    </option>
                  </select>
                </div>
              </div>

              <div v-if="selectedInventory" class="mt-6 rounded-xl border border-gold/25 bg-gold/5 p-4">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                  <div>
                    <p class="text-[10px] font-bold uppercase text-text-muted">الشركة</p>
                    <p class="text-sm font-semibold">{{ selectedInventory.bus_company?.name }}</p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase text-text-muted">المسار</p>
                    <p class="text-sm font-semibold">
                      {{ selectedInventory.route }}
                    </p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase text-text-muted">المغادرة</p>
                    <p class="text-sm font-semibold">{{ formatDate(selectedInventory.travel_date) }}</p>
                    <p class="text-xs text-text-muted">{{ formatTime(selectedInventory.departure_time) }}</p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase text-text-muted">سعر المقعد</p>
                    <p class="font-mono text-sm font-black text-gold">{{ seatPrice }} ج.م</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </transition>

        <!-- Step 2: Customer -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 2" class="space-y-6">
            <div class="flight-panel">
              <div class="mb-6 flex items-center gap-3">
                <div class="rounded-xl bg-sky-500/15 p-3 text-sky-300">
                  <UserCircle class="h-6 w-6" />
                </div>
                <div>
                  <h2 class="flight-panel__title">بيانات العميل</h2>
                  <p class="flight-panel__subtitle">يُنشأ سجل عميل تلقائياً بالهاتف إن لم يكن مسجّلاً.</p>
                </div>
              </div>
              <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                  <label class="mb-2 block text-sm font-medium text-text-muted">الاسم <span class="text-error">*</span></label>
                  <input
                    v-model="form.customer_name"
                    type="text"
                    required
                    class="flight-input"
                    placeholder="الاسم الكامل"
                  />
                </div>
                <div>
                  <div class="flex justify-between mb-2">
                    <label class="block text-sm font-medium text-text-muted">الهاتف <span class="text-error">*</span></label>
                    <span v-if="searchingCustomer" class="text-[10px] text-sky-400">جاري البحث...</span>
                  </div>
                  <input
                    v-model="form.customer_phone"
                    type="tel"
                    required
                    class="flight-input"
                    placeholder="مثال: 01xxxxxxxxx"
                    @input="onPhoneInput"
                  />
                </div>
              </div>
            </div>
          </div>
        </transition>

        <!-- Step 3: Seats & payment -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 3" class="space-y-6">
            <div class="flight-panel">
              <div class="mb-6 flex items-center gap-3">
                <div class="rounded-xl bg-gold/15 p-3 text-gold">
                  <Ticket class="h-6 w-6" />
                </div>
                <div>
                  <h2 class="flight-panel__title">المقاعد والتحصيل</h2>
                  <p class="flight-panel__subtitle">الإجمالي من سعر المقعد × العدد. التحصيل يمر على الحسابات المعرّفة في Filament.</p>
                </div>
              </div>

              <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div>
                  <label class="mb-2 block text-sm font-medium text-text-muted">عدد المقاعد <span class="text-error">*</span></label>
                  <input
                    v-model.number="form.seats_count"
                    type="number"
                    min="1"
                    :max="selectedInventory?.available_tickets || 1"
                    required
                    class="flight-input font-mono"
                  />
                  <p v-if="selectedInventory" class="mt-1 text-xs text-text-muted">
                    المتاح: {{ selectedInventory.available_tickets }}
                  </p>
                </div>
                <div class="md:col-span-2 flex flex-col justify-end">
                  <div class="rounded-xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                      <span class="text-text-muted">الإجمالي</span>
                      <span class="font-mono text-lg font-black text-gold">{{ formatMoney(totalPrice) }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-8 border-t border-white/10 pt-8">
                <div class="mb-4 flex items-center gap-3">
                  <div class="rounded-xl bg-warning/15 p-3 text-warning">
                    <CreditCard class="h-6 w-6" />
                  </div>
                  <div>
                    <h3 class="text-lg font-extrabold text-text-main">الدفع المبدئي</h3>
                    <p class="text-sm text-text-muted">إن وُجد مبلغ، يُسجَّل كإيراد على الحساب المختار (مثل حجز الطيران).</p>
                  </div>
                </div>

                <div class="mb-4">
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
                </div>

                <div class="space-y-4">
                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">{{ settlementPickerLabel }}</label>
                    <select
                      v-model="form.account_id"
                      class="flight-select"
                      :disabled="!settlementPickerOptions.length"
                    >
                      <option :value="null">{{ settlementPickerEmptyText }}</option>
                      <option v-for="account in settlementPickerOptions" :key="account.id" :value="account.id">
                        {{ formatSettlementPickerOption(account) }}
                      </option>
                    </select>
                    <p v-if="selectedPaymentMethodLabel && form.account_id" class="mt-1.5 text-[11px] text-text-muted">
                      تسجيل الطريقة:
                      <span class="font-semibold text-sky-200/90">{{ selectedPaymentMethodLabel }}</span>
                    </p>
                  </div>

                  <div
                    class="rounded-xl border border-sky-500/25 bg-sky-500/10 p-3 text-[11px] leading-relaxed text-sky-100/95"
                  >
                    <p class="font-bold text-sky-200">إدارة الحسابات من Filament</p>
                    <p class="mt-1 text-text-muted">
                      أنشئ حسابات بنكية أو محافظ أو خزائن من لوحة التحكم لتظهر في القائمة أعلاه.
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
                        محافظ
                      </a>
                      <a
                        :href="adminAccountsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 font-bold text-text-main transition hover:border-white/25"
                      >
                        <Landmark class="h-3.5 w-3.5" />
                        كل الحسابات
                      </a>
                    </div>
                  </div>

                  <p v-if="!settlementAccounts.length" class="text-xs text-warning">
                    لم تُحمَّل حسابات مالية. أضف حسابات من Filament (روابط أعلاه).
                  </p>
                  <p
                    v-else-if="settlementAccounts.length && !settlementPickerOptions.length"
                    class="text-xs text-warning"
                  >
                    لا يوجد حساب في هذا التصنيف — غيّر التصنيف أو أنشئ حساباً مطابقاً.
                  </p>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-text-muted">مبلغ التحصيل الآن</label>
                    <div class="relative max-w-md">
                      <input
                        v-model.number="form.paid_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :max="totalPrice"
                        class="flight-input pl-14 font-mono"
                      />
                      <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-text-muted">
                        ج.م
                      </span>
                    </div>
                    <p v-if="paymentAmountError" class="mt-2 text-xs text-error">{{ paymentAmountError }}</p>
                  </div>

                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="pct in [25, 50, 75, 100]"
                      :key="pct"
                      type="button"
                      class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-text-muted transition hover:border-gold/50 hover:bg-gold/15 hover:text-gold"
                      @click="form.paid_amount = roundMoney((totalPrice * pct) / 100)"
                    >
                      {{ pct }}٪
                    </button>
                    <button
                      type="button"
                      class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-text-muted transition hover:border-white/25"
                      @click="form.paid_amount = 0"
                    >
                      آجل (بدون سداد الآن)
                    </button>
                  </div>
                </div>
              </div>

              <div class="mt-8">
                <label class="mb-2 block text-sm font-medium text-text-muted">ملاحظات</label>
                <textarea
                  v-model="form.notes"
                  rows="3"
                  class="flight-input resize-none"
                  placeholder="اختياري"
                />
              </div>
            </div>
          </div>
        </transition>

        <!-- Step 4: Review -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 4" class="space-y-6">
            <div class="flight-panel">
              <div class="mb-6 flex items-center gap-3">
                <div class="rounded-xl bg-gold/20 p-3 text-gold">
                  <FileText class="h-6 w-6" />
                </div>
                <div>
                  <h2 class="flight-panel__title">مراجعة وتأكيد</h2>
                  <p class="flight-panel__subtitle">تأكد من الرحلة والعميل والمبلغ قبل الإرسال.</p>
                </div>
              </div>

              <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-xs text-text-muted">الرحلة</dt>
                  <dd class="mt-1 font-semibold">
                    <template v-if="selectedInventory">
                      {{ selectedInventory.route }} — {{ formatDate(selectedInventory.travel_date) }}
                    </template>
                    <template v-else>—</template>
                  </dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-xs text-text-muted">العميل</dt>
                  <dd class="mt-1 font-semibold">{{ form.customer_name || '—' }} — {{ form.customer_phone || '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-xs text-text-muted">المقاعد والإجمالي</dt>
                  <dd class="mt-1 font-semibold">
                    {{ form.seats_count }} × {{ formatMoney(seatPrice) }} =
                    <span class="text-gold">{{ formatMoney(totalPrice) }}</span>
                  </dd>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-xs text-text-muted">المدفوع الآن / المتبقي</dt>
                  <dd class="mt-1 font-semibold">
                    {{ formatMoney(form.paid_amount || 0) }} /
                    <span class="text-text-muted">{{ formatMoney(remainingAmount) }}</span>
                  </dd>
                </div>
                <div v-if="(form.paid_amount || 0) > 0" class="md:col-span-2 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                  <dt class="text-xs text-text-muted">حساب التحصيل</dt>
                  <dd class="mt-1 font-semibold">{{ selectedSettlementAccountDisplay || '—' }}</dd>
                </div>
              </dl>
            </div>
          </div>
        </transition>

        <!-- Nav buttons -->
        <div class="flex flex-wrap items-center justify-between gap-4">
          <button
            type="button"
            class="btn-airline-ghost"
            :disabled="currentStep <= 1"
            @click="previousStep"
          >
            السابق
          </button>
          <div class="flex gap-3">
            <router-link to="/bus" class="btn-airline-ghost">إلغاء</router-link>
            <button
              v-if="currentStep < totalSteps"
              type="button"
              class="inline-flex items-center gap-2 rounded-xl bg-gold px-6 py-3 font-bold text-black transition hover:bg-gold/90 disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="!canProceed"
              @click="nextStep"
            >
              التالي
            </button>
            <button
              v-else
              type="button"
              class="inline-flex items-center gap-2 rounded-xl bg-gold px-6 py-3 font-bold text-black transition hover:bg-gold/90 disabled:cursor-not-allowed disabled:opacity-40"
              :disabled="!canSubmit || submitting"
              @click="handleSubmit"
            >
              <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
              <CheckCircle v-else class="h-4 w-4" />
              {{ submitting ? 'جاري الحفظ…' : 'تأكيد الحجز' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <aside class="space-y-6 lg:col-span-1">
        <div class="flight-panel !p-5">
          <h4 class="mb-4 text-xs font-bold uppercase tracking-wider text-text-muted">ملخص سريع</h4>
          <div class="space-y-3 text-sm">
            <div class="flex justify-between gap-2 border-b border-white/10 pb-2">
              <span class="text-text-muted">الإجمالي</span>
              <span class="font-mono font-bold text-gold">{{ formatMoney(totalPrice) }}</span>
            </div>
            <div class="flex justify-between gap-2 border-b border-white/10 pb-2">
              <span class="text-text-muted">المحصّل</span>
              <span class="font-mono text-success">{{ formatMoney(form.paid_amount || 0) }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-text-muted">المتبقي</span>
              <span class="font-mono text-text-main">{{ formatMoney(remainingAmount) }}</span>
            </div>
          </div>
        </div>

        <div class="flight-panel !p-5">
          <h4 class="mb-1 text-xs font-bold uppercase tracking-wider text-text-muted">خطوات الحجز</h4>
          <p class="mb-4 text-[10px] text-text-muted/80">علامة الصح تعتمد على اكتمال البيانات.</p>
          <div class="space-y-3">
            <div
              v-for="step in totalSteps"
              :key="'side-' + step"
              :class="[
                'flex items-center gap-3 text-sm',
                currentStep === step ? 'text-gold' : isStepDone(step) ? 'text-success' : 'text-gray-500',
              ]"
            >
              <div
                :class="[
                  'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
                  currentStep === step
                    ? 'bg-gold text-black'
                    : isStepDone(step)
                      ? 'bg-success/25 text-success'
                      : 'bg-white/10 text-gray-500',
                ]"
              >
                <Check v-if="isStepDone(step)" class="h-4 w-4" />
                <span v-else>{{ step }}</span>
              </div>
              <span>{{ stepLabels[step - 1] }}</span>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-xs leading-relaxed text-text-muted">
          <p class="mb-1 font-bold text-amber-200">Filament ↔ التطبيق</p>
          <p>
            شركات الباص والمخزون من
            <a class="font-semibold text-gold hover:underline" :href="adminBusCompaniesUrl" target="_blank" rel="noopener"
              >شركات الباص</a>
            و
            <a class="font-semibold text-gold hover:underline" :href="adminBusInventoriesUrl" target="_blank" rel="noopener"
              >مخزون الرحلات</a>.
          </p>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import axios from 'axios';
import { useBusStore } from '@/stores/busStore';
import {
  ArrowRight,
  BusFront,
  UserCircle,
  Ticket,
  CreditCard,
  FileText,
  Check,
  CheckCircle,
  Loader2,
  Wallet,
  Landmark,
  Banknote,
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const store = useBusStore();

const totalSteps = 4;
const stepLabels = ['الرحلة', 'العميل', 'المقاعد والدفع', 'المراجعة'];
const currentStep = ref(1);
const submitting = ref(false);
const searchingCustomer = ref(false);
let phoneTimeout;

const onPhoneInput = () => {
  clearTimeout(phoneTimeout);
  if (form.value.customer_phone?.length >= 10) {
    phoneTimeout = setTimeout(searchCustomer, 500);
  }
};

const searchCustomer = async () => {
  if (!form.value.customer_phone || form.value.customer_phone.length < 10) return;
  searchingCustomer.value = true;
  try {
    const res = await axios.get('/api/v1/customers', { params: { search: form.value.customer_phone } });
    const customers = res.data?.data || [];
    if (customers.length > 0 && !form.value.customer_name) {
      form.value.customer_name = customers[0].full_name;
    }
  } catch (e) {
    console.error(e);
  } finally {
    searchingCustomer.value = false;
  }
};

const circumference = 2 * Math.PI * 22;
const progressOffset = computed(() => circumference * (1 - currentStep.value / totalSteps));

const form = ref({
  company_id: '',
  inventory_id: '',
  customer_name: '',
  customer_phone: '',
  seats_count: 1,
  paid_amount: 0,
  notes: '',
  account_id: null,
  payment_method: 'cash',
});

const settlementAccounts = ref([]);
const paymentMethods = ref([]);
const settlementCategoryUi = ref('cash');

const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-sky-400' },
];

const SETTLEMENT_CATEGORY_TYPES = {
  cash: ['cashbox', 'treasury'],
  wallet: ['wallet'],
  bank: ['bank'],
};

const PAYMENT_METHODS_FALLBACK = [
  { value: 'cash', label: 'نقدي' },
  { value: 'office_drawer', label: 'درج المكتب' },
  { value: 'office_safe', label: 'خزينة المكتب' },
  { value: 'bank_transfer', label: 'تحويل بنكي' },
  { value: 'vodafone_cash', label: 'فودافون كاش' },
  { value: 'instapay', label: 'إنستاباي' },
  { value: 'cash_wallet', label: 'محفظة كاش' },
  { value: 'postal_transfer', label: 'بريد' },
];

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

const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة نقدي',
  wallet: 'محفظة إلكترونية',
  bank: 'حساب بنكي',
  treasury: 'خزينة عامة',
};

const adminFilamentBankAccountsUrl = computed(() => `${window.location.origin}/admin/bus-banks/create`);
const adminFilamentWalletAccountsUrl = computed(() => `${window.location.origin}/admin/bus-wallets/create`);
const adminAccountsUrl = computed(() => `${window.location.origin}/admin/bus-treasuries/create`);
const adminBusCompaniesUrl = computed(() => `${window.location.origin}/admin/bus-companies`);
const adminBusInventoriesUrl = computed(() => `${window.location.origin}/admin/bus-inventories`);

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

const normalizeWalletProvider = (raw) => normalizeMethodCode(raw?.value ?? raw);

const sameId = (a, b) => a != null && b != null && String(a) === String(b);

const settlementPickerOptions = computed(() => {
  const types = SETTLEMENT_CATEGORY_TYPES[settlementCategoryUi.value];
  if (!types?.length) return [];
  const want = new Set(types);
  return settlementAccounts.value
    .filter((a) => a.is_active !== false && want.has(normalizeAccountType(a.type)))
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' }));
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

const paymentMethodFromWalletProvider = (raw) => {
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
};

const defaultPaymentMethodForCategory = (cat) => {
  if (cat === 'wallet') return 'vodafone_cash';
  if (cat === 'bank') return 'bank_transfer';
  return 'cash';
};

const syncPaymentMethodFromSelectedAccount = () => {
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
};

/** PayBusBookingRequest يسمح بقيم محددة؛ نطابقها عند المحفظة. */
const mapPaymentMethodForBusPayApi = (code) => {
  const c = normalizeMethodCode(code);
  const allowed = new Set([
    'cash',
    'bank_transfer',
    'cash_wallet',
    'postal_transfer',
    'office_safe',
    'office_drawer',
  ]);
  if (allowed.has(c)) return c;
  if (c === 'vodafone_cash' || c === 'instapay') return 'cash_wallet';
  return 'cash';
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

const selectedPaymentMethodLabel = computed(() => {
  const v = normalizeMethodCode(form.value.payment_method || '');
  const m = paymentMethods.value.find((x) => normalizeMethodCode(x.value) === v);
  return m?.label || PAYMENT_METHODS_FALLBACK.find((x) => x.value === v)?.label || v || '—';
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

const setSettlementCategory = (categoryId) => {
  settlementCategoryUi.value = categoryId;
  form.value.account_id = null;
};

const availableInventory = computed(() => store.availableInventory);
const selectedInventory = computed(() => {
  if (!form.value.inventory_id) return null;
  return store.inventory.find((i) => sameId(i.id, form.value.inventory_id));
});

const seatPrice = computed(() => Number(selectedInventory.value?.selling_price) || 0);
const totalPrice = computed(() => seatPrice.value * (Number(form.value.seats_count) || 0));
const remainingAmount = computed(() => Math.max(0, totalPrice.value - (Number(form.value.paid_amount) || 0)));

const paymentAmountError = computed(() => {
  const p = Number(form.value.paid_amount) || 0;
  if (p < 0) return 'المبلغ لا يمكن أن يكون سالباً';
  if (p > totalPrice.value + 0.0001) return 'المبلغ لا يتجاوز إجمالي الحجز';
  return '';
});

const paidPositive = computed(() => (Number(form.value.paid_amount) || 0) > 0.009);

const isStepDone = (step) => {
  switch (step) {
    case 1:
      return !!form.value.company_id && !!form.value.inventory_id;
    case 2:
      return (
        String(form.value.customer_name || '').trim().length > 0 &&
        String(form.value.customer_phone || '').trim().length > 0
      );
    case 3: {
      const seatsOk =
        (Number(form.value.seats_count) || 0) >= 1 &&
        selectedInventory.value &&
        (Number(form.value.seats_count) || 0) <= (selectedInventory.value.available_tickets || 0);
      if (!seatsOk || paymentAmountError.value) return false;
      if (paidPositive.value) {
        return !!form.value.account_id && settlementAccounts.value.length > 0;
      }
      return true;
    }
    case 4:
      return isStepDone(1) && isStepDone(2) && isStepDone(3);
    default:
      return false;
  }
};

const canProceed = computed(() => {
  switch (currentStep.value) {
    case 1:
      return !!form.value.company_id && !!form.value.inventory_id;
    case 2:
      return isStepDone(2);
    case 3:
      return isStepDone(3);
    case 4:
      return true;
    default:
      return false;
  }
});

const canSubmit = computed(() => isStepDone(4) && !paymentAmountError.value);

const nextStep = () => {
  if (canProceed.value && currentStep.value < totalSteps) currentStep.value += 1;
};

const previousStep = () => {
  if (currentStep.value > 1) currentStep.value -= 1;
};

const goToStep = (step) => {
  if (step >= 1 && step < currentStep.value) currentStep.value = step;
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

const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatTime = (timeString) => {
  if (!timeString) return '';
  return String(timeString).slice(0, 5);
};

const loadSettlementAccounts = async () => {
  try {
    const response = await axios.get('/api/v1/bus/treasury/overview');
    const raw = response.data?.data?.settlement_accounts;
    settlementAccounts.value = Array.isArray(raw) ? raw : [];
  } catch (e) {
    console.error('Failed to load bus treasury accounts:', e);
    settlementAccounts.value = [];
  }
};

const onCompanyChange = async () => {
  form.value.inventory_id = '';
  if (form.value.company_id) {
    await store.fetchInventory({ company_id: form.value.company_id });
  }
};

watch(
  () => form.value.account_id,
  () => {
    syncPaymentMethodFromSelectedAccount();
  }
);

watch(
  () => [settlementCategoryUi.value, settlementAccounts.value],
  () => {
    const opts = settlementPickerOptions.value;
    const cur = form.value.account_id;
    if (cur != null && cur !== '' && !opts.some((a) => sameId(a.id, cur))) {
      form.value.account_id = null;
    }
    syncPaymentMethodFromSelectedAccount();
  }
);

watch(totalPrice, (t) => {
  const p = Number(form.value.paid_amount) || 0;
  if (p > t) form.value.paid_amount = roundMoney(t);
});

const handleSubmit = async () => {
  if (!canSubmit.value || submitting.value) return;
  submitting.value = true;
  try {
    const booking = await store.createBooking({
      company_id: form.value.company_id,
      inventory_id: form.value.inventory_id,
      customer_name: form.value.customer_name,
      customer_phone: form.value.customer_phone,
      seats_count: form.value.seats_count,
      paid_amount: 0,
      notes: form.value.notes,
      total_price: totalPrice.value,
      remaining_amount: remainingAmount.value,
    });

    const id = booking?.id;
    const payAmt = roundMoney(Number(form.value.paid_amount) || 0);
    if (id && payAmt > 0 && form.value.account_id) {
      try {
        await store.payBooking(id, {
          amount: payAmt,
          payment_method: mapPaymentMethodForBusPayApi(form.value.payment_method),
          account_id: form.value.account_id,
          notes: form.value.notes || null,
        });
      } catch (payErr) {
        console.error(payErr);
        store.addToast(
          'تم إنشاء الحجز لكن فشل تسجيل الدفع. سجّل الدفع من صفحة الحجز.',
          'error',
        );
        router.push(`/bus/${id}`);
        return;
      }
    }

    store.addToast('تم إضافة الحجز بنجاح');
    router.push(`/bus/${id}`);
  } catch (error) {
    console.error(error);
    const api = error.response?.data;
    const errs = api?.errors;
    let detail = api?.message || error.message || 'فشل الحجز';
    if (errs && typeof errs === 'object') {
      detail +=
        '\n' +
        Object.entries(errs)
          .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(' — ') : v}`)
          .join('\n');
    }
    store.addToast(detail, 'error');
  } finally {
    submitting.value = false;
  }
};

onMounted(async () => {
  await store.fetchCompanies();
  await Promise.all([
    loadSettlementAccounts(),
    axios.get('/api/v1/settings/payment-methods').then((res) => {
      const raw = Array.isArray(res.data?.data) ? res.data.data : [];
      paymentMethods.value = raw.length
        ? raw.map((m) => ({ ...m, value: normalizeMethodCode(m.value) }))
        : PAYMENT_METHODS_FALLBACK;
    }).catch(() => {
      paymentMethods.value = PAYMENT_METHODS_FALLBACK;
    }),
  ]);

  const preInventoryId = route.query.inventory_id;
  if (preInventoryId) {
    try {
      const inv = await store.fetchInventoryItem(preInventoryId);
      const cid = inv?.bus_company_id ?? inv?.company_id;
      if (cid) {
        form.value.company_id = String(cid);
        await store.fetchInventory({ company_id: cid });
        form.value.inventory_id = String(inv.id);
      }
    } catch {
      /* deep link optional */
    }
  }

  syncPaymentMethodFromSelectedAccount();
});
</script>
