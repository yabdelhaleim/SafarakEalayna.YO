<!--
  Online Service Create Page — `/online/execute`

  Hybrid design (Flight 7-step + Bus 4-step patterns blended):
  • Header gradient: sky-blue / slate (matches Flight)
  • 3-step stepper with progress wheel (Flight-style)
  • Main form 2/3 + sticky sidebar 1/3 (Bus-style)
  • Live financial card (Bus-style)
  • Service Type and Provider are now FREE-TEXT inputs (Fawry pattern).
    The master tables (online_service_types / online_service_providers) are
    kept only as optional lookups — the Create page no longer requires rows
    to exist for the page to render.
-->
<template>
  <div class="online-transaction-view mx-auto max-w-7xl space-y-8 pb-16">
    <!-- HEADER -->
    <header class="flight-hero relative">
      <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
          <router-link
            to="/online"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة لقائمة المعاملات"
          >
            <ArrowRight class="h-5 w-5 text-sky-300" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">
              الخدمات الإلكترونية
            </p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
              تنفيذ معاملة جديدة
            </h1>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-text-muted">
              سجّل بيانات العملية والتحصيل المالي بدقة لضمان توازن الحسابات.
            </p>
          </div>
        </div>

        <!-- Progress wheel -->
        <div class="flex shrink-0 items-center gap-4 rounded-2xl border border-white/10 bg-black/25 px-5 py-4 backdrop-blur-sm">
          <div class="text-left sm:text-right">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">التقدم</div>
            <div class="text-lg font-black text-gold">
              {{ currentStep }}<span class="text-text-muted"> / </span>{{ totalSteps }}
            </div>
          </div>
          <div class="relative h-14 w-14">
            <svg class="h-full w-full -rotate-90 transform">
              <circle cx="28" cy="28" r="22" stroke="currentColor" stroke-width="3" fill="transparent" class="text-white/10" />
              <circle
                cx="28" cy="28" r="22" stroke="currentColor" stroke-width="3" fill="transparent"
                stroke-linecap="round" class="text-sky-400 transition-all duration-500"
                :stroke-dasharray="circumferenceRing"
                :stroke-dashoffset="progressOffsetRing"
              />
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
              <span class="text-[11px] font-black text-text-main">{{ progressPct }}%</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Stepper -->
      <nav class="relative z-10 mt-8 grid grid-cols-3 gap-3" aria-label="خطوات المعاملة">
        <button
          v-for="step in totalSteps"
          :key="step"
          type="button"
          :disabled="step > currentStep"
          :class="[
            'flight-step flex items-center justify-center gap-3 rounded-2xl border px-4 py-3 transition-all',
            currentStep === step
              ? 'border-gold/40 bg-gold/10 text-text-main shadow-lg shadow-gold/10'
              : isStepComplete(step)
                ? 'border-success/30 bg-success/10 text-text-main'
                : 'border-white/10 bg-white/5 text-text-muted',
            step > currentStep && !isStepComplete(step) && 'opacity-40 cursor-not-allowed',
          ]"
          @click="goToStep(step)"
        >
          <span
            :class="[
              'flex h-7 w-7 items-center justify-center rounded-full text-xs font-black',
              currentStep === step
                ? 'bg-gold text-black'
                : isStepComplete(step)
                  ? 'bg-success/30 text-success'
                  : 'bg-white/10 text-text-muted',
            ]"
          >
            <Check v-if="isStepComplete(step)" class="h-3.5 w-3.5" />
            <span v-else>{{ step }}</span>
          </span>
          <span class="truncate text-sm font-bold">{{ getStepLabel(step) }}</span>
        </button>
      </nav>
    </header>

    <!-- MAIN GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Main form (2/3) -->
      <div class="lg:col-span-2 space-y-6">
        <!-- STEP 1 — الخدمة والمزود -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 1" class="flight-panel">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-violet-500/10 rounded-lg">
                <Globe class="w-5 h-5 text-sky-400" />
              </div>
              <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">Step 1</p>
                <h2 class="flight-panel__title !mb-0">الخدمة والمزود</h2>
              </div>
            </div>

            <div class="grid grid-cols-1 gap-6">
              <!-- نوع الخدمة — FREE-TEXT input (Fawry pattern) -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">
                  نوع الخدمة <span class="text-error">*</span>
                </label>
                <input
                  v-model="form.service_type_code"
                  type="text"
                  required
                  maxlength="80"
                  placeholder="مثال: طوابع وضرائب، تصديقات، تأشيرات"
                  list="known-service-types"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none text-text-main"
                />
                <datalist id="known-service-types">
                  <option v-for="t in store.serviceTypes" :key="t.code" :value="t.name_ar || t.code" />
                </datalist>
                <p class="text-[11px] text-text-muted">
                  اكتب نوع الخدمة كنص حر — يمكنك اختيار من الأنواع المسجلة أو كتابة نوع جديد.
                </p>
              </div>

              <!-- المزود — FREE-TEXT input -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">المزود (اختياري)</label>
                <input
                  v-model="form.provider_code"
                  type="text"
                  maxlength="80"
                  placeholder="مثال: شركة ممتاز، اعتماد، مسارات"
                  list="known-providers"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none text-text-main"
                />
                <datalist id="known-providers">
                  <option v-for="p in store.providers" :key="p.code" :value="p.name_ar || p.code" />
                </datalist>
                <p class="text-[11px] text-text-muted">
                  اختياري — اتركه فارغاً إذا لم يكن للخدمة مزود محدد.
                </p>
              </div>

              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">ملاحظات (اختياري)</label>
                <textarea
                  v-model="form.notes"
                  rows="2"
                  maxlength="2000"
                  placeholder="أي ملاحظات إضافية تظهر في كشف الحساب"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none text-text-main"
                />
              </div>
            </div>
          </div>
        </transition>

        <!-- STEP 2 — بيانات العميل -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 2" class="flight-panel">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-blue-500/10 rounded-lg">
                <User class="w-5 h-5 text-blue-400" />
              </div>
              <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">Step 2</p>
                <h2 class="flight-panel__title !mb-0">بيانات العميل</h2>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="space-y-2 md:col-span-2">
                <label class="text-xs font-bold text-text-muted">
                  العميل المسجّل (اختياري)
                </label>
                <select
                  v-model="form.customer_id"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none cursor-pointer text-text-main"
                  @change="onCustomerSelected"
                >
                  <option :value="null" class="bg-card-bg">— بدون اختيار (عميل جديد) —</option>
                  <option v-for="c in store.customers" :key="c.id" :value="c.id" class="bg-card-bg">
                    {{ c.name }} {{ c.phone ? `— ${c.phone}` : '' }}
                  </option>
                </select>
              </div>

              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">
                  اسم العميل <span v-if="!form.customer_id" class="text-error">*</span>
                </label>
                <input
                  v-model="form.customer_name"
                  type="text"
                  :readonly="!!form.customer_id"
                  :required="!form.customer_id"
                  maxlength="255"
                  placeholder="مثال: حسين علي"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none text-text-main read-only:opacity-50"
                />
              </div>

              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">
                  رقم التليفون <span v-if="!form.customer_id" class="text-error">*</span>
                </label>
                <input
                  v-model="form.customer_phone"
                  type="text"
                  :readonly="!!form.customer_id"
                  :required="!form.customer_id"
                  maxlength="64"
                  placeholder="مثال: 01024607766"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main read-only:opacity-50"
                />
              </div>

              <div class="space-y-2 md:col-span-2">
                <label class="text-xs font-bold text-text-muted">الموظف (اختياري)</label>
                <select
                  v-model="form.employee_id"
                  class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none cursor-pointer text-text-main"
                >
                  <option :value="null" class="bg-card-bg">— بدون موظف —</option>
                  <option v-for="e in store.employees" :key="e.id" :value="e.id" class="bg-card-bg">
                    {{ e.name }} {{ e.position ? `— ${e.position}` : '' }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </transition>

        <!-- STEP 3 — التسعير والتحصيل -->
        <transition
          enter-active-class="transition-all duration-500"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
        >
          <div v-show="currentStep === 3" class="space-y-6">
            <!-- Pricing -->
            <div class="flight-panel">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-emerald-500/10 rounded-lg">
                  <Banknote class="w-5 h-5 text-emerald-400" />
                </div>
                <div>
                  <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">Step 3a</p>
                  <h2 class="flight-panel__title !mb-0">التسعير والربح</h2>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">سعر الشراء (التكلفة) <span class="text-error">*</span></label>
                  <div class="relative">
                    <input
                      v-model.number="form.purchase_price"
                      type="number"
                      min="0"
                      step="0.01"
                      required
                      class="w-full pl-12 pr-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main"
                    />
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
                  </div>
                </div>

                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">سعر البيع <span class="text-error">*</span></label>
                  <div class="relative">
                    <input
                      v-model.number="form.selling_price"
                      type="number"
                      min="0"
                      step="0.01"
                      required
                      class="w-full pl-12 pr-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main"
                    />
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
                  </div>
                </div>

                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">الربح الصافي</label>
                  <div class="flex h-[46px] items-center justify-between rounded-xl border border-success/20 bg-success/10 px-4">
                    <span class="text-sm font-black text-success font-mono">{{ formatMoney(profit) }}</span>
                    <TrendingUp class="h-4 w-4 text-success" />
                  </div>
                </div>
              </div>
            </div>

            <!-- Collection -->
            <div class="flight-panel">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-amber-500/10 rounded-lg">
                  <CreditCard class="w-5 h-5 text-amber-400" />
                </div>
                <div>
                  <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-400/80">Step 3b</p>
                  <h2 class="flight-panel__title !mb-0">طريقة التحصيل</h2>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- طريقة الدفع — FREE-TEXT input (Fawry pattern).
                     Mirrors the way service_type_code + provider_code work:
                     free-text, validated only by the backend
                     PaymentMethodAccountType::resolve() helper. The dropdown
                     was dropped because production has zero rows in the
                     `payment_methods` master table, and free-text is what
                     the project owner asked for. -->
                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">
                    طريقة الدفع <span class="text-error">*</span>
                  </label>
                  <input
                    v-model="form.payment_method"
                    type="text"
                    required
                    maxlength="80"
                    placeholder="مثال: cash، bank_transfer، vodafone_cash"
                    list="known-payment-methods"
                    class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main"
                    @input="onPaymentMethodChange"
                  />
                  <datalist id="known-payment-methods">
                    <option v-for="m in store.paymentMethods" :key="m.code || m.value" :value="m.code || m.value">
                      {{ m.label || m.name_ar }}
                    </option>
                  </datalist>
                  <div v-if="form.payment_method" class="flex flex-wrap items-center gap-2 mt-1">
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/5 text-text-muted">
                      <CheckCircle2 class="w-3 h-3" />
                      {{ form.payment_method }}
                    </span>
                    <span
                      v-if="paymentMethodTypedType"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-300"
                    >
                      مرتبط بـ: {{ ACCOUNT_TYPE_LABELS[paymentMethodTypedType] }}
                    </span>
                    <span
                      v-else
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-300"
                    >
                      غير معروف — اختر نوع الحساب يدوياً
                    </span>
                  </div>
                  <p class="text-[11px] text-text-muted">
                    اكتب طريقة الدفع كنص حر — يمكنك اختيار من القائمة أو كتابة نوع جديد. النوع هنا يحدد الحساب تلقائياً، أو اختر نوع الحساب يدوياً من التابات أدناه.
                  </p>
                </div>

                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">حساب التحصيل <span class="text-error">*</span></label>
                  <select
                    v-model="form.account_id"
                    required
                    :disabled="!effectiveAccountType || filteredAccounts.length === 0"
                    class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-gold/50 outline-none cursor-pointer text-text-main disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    <option :value="null" class="bg-card-bg">{{ accountPlaceholder }}</option>
                    <option v-for="a in filteredAccounts" :key="a.id" :value="a.id" class="bg-card-bg">
                      {{ a.name }} — رصيد ({{ formatMoney(a.balance) }})
                    </option>
                  </select>
                  <p v-if="accountHelpText" class="text-[11px] font-bold text-amber-400">
                    {{ accountHelpText }}
                  </p>
                </div>

                <!-- نوع حساب التحصيل — manual type tabs (FawryCreate pattern).
                     Three big chips for cashbox / bank / wallet. Auto-selected
                     when the typed payment_method resolves cleanly; the user
                     can always override. Whatever tab is the "manualAccountType"
                     wins over the auto-detected one. -->
                <div class="space-y-2 md:col-span-2">
                  <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-text-muted">
                      نوع حساب التحصيل <span class="text-error">*</span>
                    </label>
                    <span
                      v-if="paymentMethodTypedType && !manualAccountType"
                      class="text-[10px] font-semibold text-emerald-300"
                    >
                      تم الاختيار تلقائياً من طريقة الدفع
                    </span>
                  </div>
                  <div class="grid grid-cols-3 gap-3">
                    <button
                      v-for="type in ACCOUNT_TYPE_OPTIONS"
                      :key="type"
                      type="button"
                      @click="pickAccountType(type)"
                      :disabled="filteredAccountsByType(type).length === 0 && !manualAccountType"
                      :class="[
                        'rounded-2xl border-2 px-4 py-3 text-center transition-all duration-200 disabled:opacity-40 disabled:cursor-not-allowed',
                        effectiveAccountType === type
                          ? 'border-gold bg-gold/10 shadow-lg shadow-gold/20'
                          : 'border-white/10 bg-white/[0.03] hover:border-white/30',
                      ]"
                    >
                      <div class="flex items-center justify-center gap-2 mb-1">
                        <component
                          :is="type === 'cashbox' ? Wallet : (type === 'bank' ? Landmark : Smartphone)"
                          class="w-4 h-4"
                          :class="effectiveAccountType === type ? 'text-gold' : 'text-text-muted'"
                        />
                        <span
                          class="font-black text-base"
                          :class="effectiveAccountType === type ? 'text-gold' : 'text-text-main'"
                        >
                          {{ ACCOUNT_TYPE_LABELS[type] }}
                        </span>
                      </div>
                      <div class="text-[10px] text-text-muted">
                        {{ filteredAccountsByType(type).length }} حساب متاح
                      </div>
                    </button>
                  </div>
                </div>

                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">المبلغ المدفوع حالياً <span class="text-error">*</span></label>
                  <div class="relative">
                    <input
                      v-model.number="form.amount_paid"
                      type="number"
                      min="0"
                      step="0.01"
                      required
                      class="w-full pl-12 pr-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main"
                    />
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
                  </div>
                  <div class="mt-2 flex gap-2">
                    <button
                      v-for="pct in [25, 50, 75, 100]"
                      :key="pct"
                      type="button"
                      @click="setPaidPercent(pct)"
                      class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-xs text-text-muted hover:border-gold/40 hover:text-gold transition"
                    >
                      {{ pct }}٪
                    </button>
                  </div>
                </div>

                <div class="space-y-2">
                  <label class="text-xs font-bold text-text-muted">رقم مرجع (اختياري)</label>
                  <input
                    v-model="form.reference_number"
                    type="text"
                    maxlength="255"
                    placeholder="مثال: رقم حوالة / باركود"
                    class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-gold/50 outline-none text-text-main"
                  />
                </div>
              </div>
            </div>
          </div>
        </transition>

        <!-- Step Navigation -->
        <div class="flex gap-3 pt-2">
          <button
            type="button"
            @click="prevStep"
            :disabled="currentStep === 1"
            class="rounded-2xl bg-white/5 px-6 py-4 text-sm font-bold text-text-main transition hover:bg-white/10 disabled:opacity-30 disabled:cursor-not-allowed"
          >
            ← السابق
          </button>
          <button
            v-if="currentStep < totalSteps"
            type="button"
            @click="nextStep"
            class="flex-1 rounded-2xl bg-sky-600 py-4 text-sm font-black text-white shadow-lg shadow-sky-600/20 transition-all hover:scale-[1.01] active:scale-[0.99]"
          >
            التالي →
          </button>
          <button
            v-else
            type="button"
            @click="submit"
            :disabled="store.loading.create"
            class="flex-1 rounded-2xl bg-gold py-4 text-sm font-black text-black shadow-lg shadow-gold/30 transition-all hover:scale-[1.01] active:scale-[0.99] disabled:opacity-50"
          >
            <Loader2 v-if="store.loading.create" class="mb-0.5 ml-2 inline h-5 w-5 animate-spin" />
            <CheckCircle v-else class="mb-0.5 ml-2 inline h-5 w-5" />
            {{ store.loading.create ? 'جاري التنفيذ...' : 'تأكيد وحفظ العملية' }}
          </button>
        </div>
      </div>

      <!-- Sidebar (1/3) -->
      <div class="lg:col-span-1">
        <div class="sticky top-6 space-y-4">
          <!-- Live Financial Card -->
          <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
            <h4 class="mb-4 text-[11px] font-bold uppercase tracking-widest text-white/40">ملخص مالي</h4>

            <div v-if="form.service_type_code" class="mb-3 flex items-center gap-2 rounded-xl bg-white/5 px-3 py-2.5">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-400/15 text-xs font-black text-sky-400">
                {{ (form.service_type_code || '?').charAt(0).toUpperCase() }}
              </div>
              <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-semibold text-white">{{ form.service_type_code || 'نوع الخدمة' }}</div>
                <div v-if="form.provider_code" class="truncate text-[10px] text-white/40">{{ form.provider_code }}</div>
              </div>
            </div>

            <div class="space-y-2 text-sm">
              <div class="flex justify-between text-white/50">
                <span>سعر الشراء</span>
                <span class="font-mono text-red-400">{{ formatMoney(form.purchase_price || 0) }}</span>
              </div>
              <div class="flex justify-between text-white/50">
                <span>سعر البيع</span>
                <span class="font-mono text-emerald-400">{{ formatMoney(form.selling_price || 0) }}</span>
              </div>
              <div class="flex justify-between border-t border-white/10 pt-2">
                <span class="text-white/70">الربح</span>
                <span class="font-mono font-black text-lg" :class="profit >= 0 ? 'text-gold' : 'text-red-400'">
                  {{ profit >= 0 ? '+' : '' }}{{ formatMoney(profit) }}
                </span>
              </div>
              <div class="flex justify-between text-white/50">
                <span>المدفوع</span>
                <span class="font-mono text-emerald-400">{{ formatMoney(form.amount_paid || 0) }}</span>
              </div>
              <div class="flex justify-between border-t border-white/10 pt-2">
                <span class="text-white/70">المتبقي (آجل)</span>
                <span class="font-mono font-bold text-red-400">{{ formatMoney(Math.max((form.selling_price || 0) - (form.amount_paid || 0), 0)) }}</span>
              </div>
            </div>
          </div>

          <!-- Steps Indicator -->
          <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
            <h4 class="mb-3 text-[11px] font-bold uppercase tracking-widest text-white/40">الخطوات</h4>
            <ol class="space-y-2 text-sm">
              <li v-for="step in totalSteps" :key="step" class="flex items-center gap-2" :class="{
                'text-white': currentStep === step,
                'text-success': isStepComplete(step) && currentStep !== step,
                'text-white/40': !isStepComplete(step) && currentStep !== step,
              }">
                <Check v-if="isStepComplete(step)" class="h-4 w-4 text-success" />
                <span v-else class="flex h-4 w-4 items-center justify-center rounded-full border border-current text-[10px] font-black">{{ step }}</span>
                <span>{{ getStepLabel(step) }}</span>
              </li>
            </ol>
          </div>

          <!-- Admin URL fallback -->
          <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4">
            <p class="text-[11px] font-bold text-amber-400 mb-2">إدارة موديول الخدمات الإلكترونية</p>
            <ul class="space-y-1 text-xs text-text-muted">
              <li>
                <a href="/admin/online-service-types" target="_blank" class="text-amber-300 hover:underline">
                  + إضافة نوع خدمة جديد من هنا
                </a>
              </li>
              <li>
                <a href="/admin/online-service-providers" target="_blank" class="text-amber-300 hover:underline">
                  + إضافة مزود جديد من هنا
                </a>
              </li>
              <li>
                <a href="/admin/payment-methods" target="_blank" class="text-amber-300 hover:underline">
                  + إدارة طرق الدفع من هنا
                </a>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
  ArrowRight,
  Globe,
  User,
  Banknote,
  CreditCard,
  CheckCircle,
  CheckCircle2,
  Loader2,
  TrendingUp,
  Check,
  Wallet,
  Landmark,
  Smartphone,
} from 'lucide-vue-next';
import { useOnlineStore } from '@/stores/onlineStore';

const store = useOnlineStore();
const router = useRouter();

const totalSteps = 3;
const currentStep = ref(1);

const initialForm = () => ({
  service_type_code: '',
  provider_code: '',
  customer_id: null,
  customer_name: '',
  customer_phone: '',
  employee_id: null,
  purchase_price: 0,
  selling_price: 0,
  amount_paid: 0,
  payment_method: '',
  account_id: null,
  reference_number: '',
  notes: '',
  status: '',
});

const form = ref(initialForm());

const STEP_LABELS = {
  1: 'الخدمة والمزود',
  2: 'بيانات العميل',
  3: 'التسعير والتحصيل',
};
const getStepLabel = (step) => STEP_LABELS[step] ?? '';

const isStep1Complete = computed(() =>
  !!String(form.value.service_type_code || '').trim()
);
const isStep2Complete = computed(() =>
  !!form.value.customer_id || !!String(form.value.customer_name || '').trim()
);
const isStep3Complete = computed(() =>
  Number(form.value.purchase_price) > 0
  && Number(form.value.selling_price) > 0
  && !!form.value.payment_method
  && !!form.value.account_id
);

const isStepComplete = (step) => {
  if (step === 1) return isStep1Complete.value;
  if (step === 2) return isStep2Complete.value;
  if (step === 3) return isStep3Complete.value;
  return false;
};

const goToStep = (step) => {
  if (step <= currentStep.value || isStepComplete(step - 1)) {
    currentStep.value = step;
  }
};

const nextStep = () => {
  if (currentStep.value === 1 && !isStep1Complete.value) {
    store.addToast('اكتب نوع الخدمة أولاً', 'error');
    return;
  }
  if (currentStep.value === 2 && !isStep2Complete.value) {
    store.addToast('أدخل اسم العميل أو اختر عميلاً مسجلاً', 'error');
    return;
  }
  if (currentStep.value < totalSteps) {
    currentStep.value++;
  }
};

const prevStep = () => {
  if (currentStep.value > 1) currentStep.value--;
};

const progressPct = computed(() => Math.round((currentStep.value / totalSteps) * 100));
const circumferenceRing = 2 * Math.PI * 22;
const progressOffsetRing = computed(
  () => circumferenceRing - (currentStep.value / totalSteps) * circumferenceRing
);

const ACCOUNT_TYPE_COPY = {
  cashbox: { placeholder: '— اختر خزينة نقدية —', empty: 'لا توجد خزائن نقدية متاحة لقسم المكتب.' },
  bank: { placeholder: '— اختر حساباً بنكياً —', empty: 'لا توجد حسابات بنكية متاحة لقسم المكتب.' },
  wallet: { placeholder: '— اختر محفظة —', empty: 'لا توجد محافظ متاحة لقسم المكتب.' },
};

const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة',
  bank: 'بنك',
  wallet: 'محفظة',
};

const normalizeAccountType = (type) => String(type?.value ?? type ?? '').trim().toLowerCase();

/** Mirror of `PaymentMethodAccountType::resolve()` on the backend — pure,
 *  free-text mapping from a typed code to an AccountType enum. Keeps the UI
 *  in sync with the server's validation rule (so the local select filters
 *  the right accounts and the user gets an immediate green light if their
 *  pick would have been accepted).
 *
 *  Order matters: wallet is the most specific (anything with "wallet"
 *  substring), then bank (contains bank/card/postal), then cashbox
 *  (the safe default). */
function resolvePaymentMethodType(code) {
  const normalized = String(code ?? '').toLowerCase().trim();
  if (!normalized) return null;
  if (normalized.includes('wallet') || normalized.includes('cash_vodafone') || normalized.includes('instapay')) return 'wallet';
  if (
    normalized.includes('bank')
    || normalized.includes('card')
    || normalized.includes('postal')
    || normalized.includes('post_office')
    || normalized.includes('transfer')
  ) return 'bank';
  if (
    normalized === 'cash'
    || normalized === 'cash_egp'
    || normalized === 'cashbox'
    || normalized === 'office_safe'
    || normalized === 'office_drawer'
    || normalized === 'cod'
  ) return 'cashbox';
  return null;
}

const paymentMethodTypedType = computed(() =>
  resolvePaymentMethodType(form.value.payment_method),
);

/** Manually selected type tab. Always wins over the auto-detected one
 *  (the user may want to route a "cash" payment through the bank account
 *  if their office is set up that way). Once the user picks a tab
 *  themselves we stop auto-jumping so we don't fight them. */
const manualAccountType = ref(null);

const effectiveAccountType = computed(() =>
  manualAccountType.value ?? paymentMethodTypedType.value
);

const selectedPaymentMethodRow = computed(() => {
  const selectedCode = String(form.value.payment_method || '');
  return store.paymentMethods.find(
    (method) => String(method.code ?? method.value ?? '') === selectedCode,
  ) ?? null;
});

const filteredAccounts = computed(() => {
  if (!effectiveAccountType.value) return [];
  return store.accounts.filter(
    (account) =>
      account.is_active !== false
      && normalizeAccountType(account.type) === effectiveAccountType.value,
  );
});

const accountPlaceholder = computed(() => {
  if (!form.value.payment_method) return '— اكتب طريقة الدفع أولاً —';
  if (!effectiveAccountType.value) return '— اختر نوع الحساب من التابات أعلاه —';
  if (filteredAccounts.value.length === 0) return '— لا توجد حسابات متاحة —';
  return ACCOUNT_TYPE_COPY[effectiveAccountType.value]?.placeholder ?? '— اختر حساب التحصيل —';
});

const accountHelpText = computed(() => {
  if (!form.value.payment_method) return 'اختر نوع الحساب وطريقة الدفع لتظهر لك الحسابات المتاحة.';
  if (!effectiveAccountType.value) return 'اختر نوع الحساب من التابات أعلاه لعرض الحسابات المتاحة.';
  if (filteredAccounts.value.length === 0) {
    return ACCOUNT_TYPE_COPY[effectiveAccountType.value]?.empty
      ?? 'لا توجد حسابات متاحة لهذا النوع في قسم المكتب.';
  }
  return '';
});

/** Reset the manual tab override when the user clears the payment method. */
watch(() => form.value.payment_method, (newVal) => {
  if (!newVal) {
    manualAccountType.value = null;
    form.value.account_id = null;
  }
});

function onPaymentMethodChange() {
  form.value.account_id = null;
}

function pickAccountType(type) {
  manualAccountType.value = type;
  form.value.account_id = null;
}

/** Count-only helper used by the tab chips to show "X account available"
 *  per type without depending on the user having picked a tab. Used by
 *  the chips themselves (so each chip knows how many accounts of its
 *  type exist) AND by the auto-disabled state when zero accounts are
 *  available. */
function filteredAccountsByType(type) {
  return store.accounts.filter(
    (account) =>
      account.is_active !== false && normalizeAccountType(account.type) === type,
  );
}

const ACCOUNT_TYPE_OPTIONS = ['cashbox', 'bank', 'wallet'];

const profit = computed(() => {
  const purchase = Number(form.value.purchase_price) || 0;
  const selling = Number(form.value.selling_price) || 0;
  return Math.max(selling - purchase, -Infinity);
});

const formatMoney = (value) =>
  new Intl.NumberFormat('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    value ?? 0,
  ) + ' ج.م';

const setPaidPercent = (pct) => {
  const tot = Number(form.value.selling_price) || 0;
  if (tot <= 0) {
    store.addToast('يرجى تحديد سعر البيع أولاً', 'error');
    return;
  }
  form.value.amount_paid = Math.round((tot * pct) / 100 * 100) / 100;
};

watch(() => form.value.selling_price, (newVal) => {
  if (!form.value.amount_paid || form.value.amount_paid === 0) {
    form.value.amount_paid = newVal;
  }
});

watch(filteredAccounts, (accounts) => {
  if (
    form.value.account_id
    && !accounts.some((account) => Number(account.id) === Number(form.value.account_id))
  ) {
    form.value.account_id = null;
  }
});

const onCustomerSelected = () => {
  if (!form.value.customer_id) return;
  const customer = store.customers.find((c) => c.id === form.value.customer_id);
  if (customer) {
    form.value.customer_name = customer.name;
    form.value.customer_phone = customer.phone ?? '';
  }
};

const submit = async () => {
  if (!form.value.service_type_code) {
    store.addToast('اكتب نوع الخدمة.', 'error');
    return;
  }
  if (!form.value.customer_id && !String(form.value.customer_name || '').trim()) {
    store.addToast('أدخل اسم العميل أو اختر عميلاً من القائمة.', 'error');
    return;
  }
  if (!form.value.payment_method) {
    store.addToast('اكتب طريقة الدفع.', 'error');
    return;
  }
  if (!effectiveAccountType.value) {
    store.addToast('اختر نوع حساب التحصيل من التابات.', 'error');
    return;
  }
  if (!form.value.account_id) {
    store.addToast('اختر حساب التحصيل.', 'error');
    return;
  }

  try {
    const payload = {
      ...form.value,
      service_type_code: String(form.value.service_type_code ?? '').trim(),
      provider_code: String(form.value.provider_code ?? '').trim() || null,
      account_id: Number(form.value.account_id),
      purchase_price: Number(form.value.purchase_price),
      selling_price: Number(form.value.selling_price),
      amount_paid: Number(form.value.amount_paid),
      customer_name: String(form.value.customer_name ?? '').trim(),
      payment_method: String(form.value.payment_method ?? '').trim(),
    };
    if (form.value.employee_id != null) {
      payload.employee_id = Number(form.value.employee_id);
    }
    if (payload.employee_id === undefined || !payload.employee_id) {
      delete payload.employee_id;
    }
    if (!payload.status) {
      delete payload.status;
    }

    await store.createTransaction(payload);
    router.push('/online');
  } catch (error) {
    /* toast handled in store */
  }
};

function applyDefaultsFromApi() {
  if (!form.value.status && store.statuses.length) {
    const completed = store.statuses.find((s) => s.value === 'completed');
    form.value.status = completed?.value ?? store.statuses[0].value;
  }
}

watch(() => store.statuses, () => applyDefaultsFromApi(), { deep: true });

onMounted(async () => {
  form.value = initialForm();
  currentStep.value = 1;
  await Promise.all([
    store.fetchAllSettings(),
    store.fetchCustomers(),
    store.fetchEmployees(),
  ]);
  applyDefaultsFromApi();
});
</script>

<style scoped>
.online-transaction-view {
  --gold: #D4A843;
  --sky: #38BDF8;
  --card-bg: #111827;
  --text-main: #f9fafb;
  --text-muted: #9ca3af;
  --success: #10b981;
  --error: #ef4444;
}

.flight-hero {
  background: linear-gradient(135deg, rgba(12, 74, 110, 0.85) 0%, rgba(15, 23, 42, 0.85) 100%);
  padding: 2.5rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(56, 189, 248, 0.15);
  overflow: hidden;
}

.flight-hero::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -20%;
  width: 140%;
  height: 200%;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.06) 0%, transparent 70%);
  pointer-events: none;
}

.flight-panel {
  background: var(--card-bg);
  border: 1px solid rgba(255, 255, 255, 0.05);
  border-radius: 1.5rem;
  padding: 1.5rem;
  transition: all 0.3s ease;
}

.flight-panel:hover {
  border-color: rgba(212, 168, 67, 0.2);
}

.flight-panel__title {
  font-size: 1rem;
  font-weight: 800;
  color: var(--text-main);
}

.flight-step {
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-airline-ghost {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text-main);
  transition: all 0.2s ease;
}

.btn-airline-ghost:hover {
  background: rgba(56, 189, 248, 0.1);
  border-color: rgba(56, 189, 248, 0.3);
  color: var(--sky);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', monospace;
}
</style>
