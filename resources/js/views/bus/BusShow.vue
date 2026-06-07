<template>
  <div class="bus-booking mx-auto max-w-5xl space-y-8 pb-16">
    <header class="flight-hero relative">
      <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <router-link
            :to="{ name: 'bus.list' }"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة للقائمة"
          >
            <ArrowRight class="h-5 w-5 text-amber-300/90" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-amber-400/90">حجز باص</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">تفاصيل الحجز</h1>
            <p class="mt-2 text-sm text-text-muted">رقم الحجز: <span class="font-mono text-gold">#{{ id }}</span></p>
          </div>
        </div>
      </div>
    </header>

    <div v-if="loadError" class="flight-panel !p-8 text-center">
      <p class="text-error font-semibold">{{ loadError }}</p>
      <router-link :to="{ name: 'bus.list' }" class="btn-airline-ghost mt-4 inline-flex">العودة للقائمة</router-link>
    </div>

    <div v-else-if="loadingDetail" class="flex justify-center py-20">
      <Loader2 class="h-10 w-10 animate-spin text-gold" />
    </div>

    <template v-else-if="booking">
      <div class="flight-panel">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
          <h2 class="flight-panel__title !mb-0">بيانات الحجز</h2>
          <span
            :class="[
              'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-bold uppercase',
              statusStyles[booking.status] || 'bg-white/10 text-text-muted',
            ]"
          >
            {{ statusLabels[booking.status] || booking.status }}
          </span>
        </div>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-text-muted">تاريخ الحجز</dt>
            <dd class="mt-1 font-semibold">{{ formatDate(booking.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">حالة الدفع</dt>
            <dd class="mt-1 font-semibold" :class="paymentStatusStyles[booking.payment_status]">
              {{ paymentStatusLabels[booking.payment_status] || booking.payment_status }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">الموظف</dt>
            <dd class="mt-1 font-semibold">{{ booking.employee?.name || '—' }}</dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4">العميل</h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-text-muted">الاسم</dt>
            <dd class="mt-1 font-semibold">{{ booking.customer?.name || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">الهاتف</dt>
            <dd class="mt-1 font-mono">{{ booking.customer?.phone || '—' }}</dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4">الرحلة</h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs text-text-muted">الشركة</dt>
            <dd class="mt-1 font-semibold">{{ booking.inventory?.bus_company?.name || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">المسار</dt>
            <dd class="mt-1 flex items-center gap-2 font-semibold">
              <MapPin class="h-4 w-4 text-gold" />
              {{ booking.inventory?.route || '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">تاريخ السفر</dt>
            <dd class="mt-1 font-semibold">{{ formatDate(booking.travel_date || booking.inventory?.travel_date) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">المغادرة</dt>
            <dd class="mt-1 font-mono">{{ formatTime(booking.inventory?.departure_time) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">المقاعد</dt>
            <dd class="mt-1 font-mono font-bold">{{ booking.quantity || booking.seats_count }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">سعر المقعد</dt>
            <dd class="mt-1 font-mono font-bold text-gold">{{ formatMoney(booking.unit_price || booking.inventory?.selling_price) }}</dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4">الدفع</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <p class="text-xs text-text-muted">الإجمالي</p>
            <p class="mt-1 font-mono text-lg font-bold">{{ formatMoney(booking.total_price) }}</p>
          </div>
          <div class="rounded-xl border border-success/20 bg-success/10 p-4">
            <p class="text-xs text-text-muted">المدفوع</p>
            <p class="mt-1 font-mono text-lg font-bold text-success">{{ formatMoney(booking.paid_amount) }}</p>
          </div>
          <div class="rounded-xl border border-error/20 bg-error/10 p-4">
            <p class="text-xs text-text-muted">المتبقي</p>
            <p class="mt-1 font-mono text-lg font-bold text-error">{{ formatMoney(booking.remaining_amount) }}</p>
          </div>
        </div>

        <div v-if="booking.payments?.length" class="mt-6 space-y-2">
          <h3 class="text-sm font-bold text-text-main">سجل الدفعات</h3>
          <div
            v-for="p in booking.payments"
            :key="p.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-input-bg px-4 py-3 text-sm"
          >
            <span class="font-mono font-bold text-gold">{{ formatMoney(p.amount) }}</span>
            <span class="text-text-muted">{{ getPaymentMethodLabel(p.payment_method) }}</span>
            <span class="text-xs text-text-muted">{{ p.created_at }}</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 sm:flex-nowrap">
          <div class="flex flex-wrap gap-3">
            <button
              type="button"
              class="btn-airline-ghost"
              @click="openPrintOptions"
            >
              <Printer class="mb-0.5 ml-2 inline h-4 w-4" />
              خيارات الطباعة
            </button>
            <button
              v-if="booking.payment_status !== 'paid' && booking.status !== 'cancelled'"
              type="button"
              class="rounded-xl bg-success px-6 py-2.5 font-bold text-black transition hover:bg-success/90"
              @click="openPaymentModal"
            >
              <CreditCard class="mb-0.5 ml-2 inline h-4 w-4" />
              تسديد دفعة
            </button>
            <button
              v-if="booking.status !== 'cancelled' && booking.status !== 'refunded'"
              type="button"
              class="btn-airline-ghost border-gold/30 text-gold"
              @click="showRefundModal = true"
            >
              <RotateCcw class="mb-0.5 ml-2 inline h-4 w-4" />
              استرجاع مالي
            </button>
          </div>
        </div>
      </div>

      <p class="no-print text-xs text-text-muted">
        معاينة وصل الحجز أدناه. للـ PDF: من نافذة الطباعة اختر «Microsoft Print to PDF» أو «Save as PDF».
      </p>
      <div
        id="bus-ticket-content"
        dir="rtl"
        class="bus-print-document overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-2xl print:rounded-none print:border-0 print:shadow-none"
      >
        <div
          class="relative flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 bg-gradient-to-l from-[#1a1208] via-[#2d1f0a] to-[#1a1208] px-6 py-5 text-white print:border-slate-300"
        >
          <div class="flex min-w-0 items-center gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
              <BusFront class="h-7 w-7 text-amber-300" />
            </div>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-200/90">Bus booking voucher</p>
              <h2 class="text-xl font-black tracking-tight sm:text-2xl">وصل حجز باص</h2>
              <p class="mt-0.5 font-mono text-sm text-amber-100/90">#{{ bookingRef }}</p>
            </div>
          </div>
          <div class="text-left" style="min-width: 170px;">
            <PrintCompanyBranding module="bus" document-type="ticket" variant="dark" position="header" />
            <template v-if="!printSettingsStore.shouldShow('bus', 'ticket') || !printSettingsStore.hasCompanyInfo">
              <div class="text-lg font-black text-amber-300">سفرك علينا</div>
              <div class="text-[10px] font-semibold uppercase tracking-wider text-amber-200/80">Safarak Ealayna</div>
            </template>
          </div>
          <div
            class="bus-print-barcode mt-2 h-10 w-full max-w-[280px] rounded border border-white/20 bg-white/95 sm:mt-0 sm:w-auto sm:max-w-[200px] print:max-w-full"
            aria-hidden="true"
          />
        </div>

        <div class="bg-white px-6 py-6 sm:px-8">
          <div class="mb-6 grid grid-cols-1 gap-3 border-b border-dashed border-slate-200 pb-6 sm:grid-cols-2">
            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/80">
              <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">حالة الحجز</div>
              <div class="mt-1 text-lg font-black text-slate-900">{{ statusLabels[booking.status] || booking.status }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/80">
              <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">حالة الدفع</div>
              <div class="mt-1 text-lg font-black text-slate-900">
                {{ paymentStatusLabels[booking.payment_status] || booking.payment_status }}
              </div>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/80 sm:col-span-2">
              <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">تاريخ إصدار الوصل</div>
              <div class="mt-1 font-mono text-sm font-bold text-slate-800">{{ printTimestamp || '—' }}</div>
            </div>
          </div>

          <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">العميل</h3>
          <div class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="break-inside-avoid rounded-lg border border-slate-200 bg-slate-50/80 p-4">
              <div class="text-xs font-bold text-slate-500">الاسم</div>
              <div class="mt-1 text-base font-bold text-slate-900">{{ booking.customer?.name || '—' }}</div>
            </div>
            <div class="break-inside-avoid rounded-lg border border-slate-200 bg-slate-50/80 p-4">
              <div class="text-xs font-bold text-slate-500">الهاتف</div>
              <div class="mt-1 font-mono text-base font-bold text-slate-900">{{ booking.customer?.phone || '—' }}</div>
            </div>
          </div>

          <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">الرحلة</h3>
          <div class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">شركة النقل</div>
              <div class="mt-1 font-bold text-slate-900">{{ booking.inventory?.bus_company?.name || '—' }}</div>
            </div>
            <div class="break-inside-avoid rounded-lg border border-slate-200 p-4 sm:col-span-2">
              <div class="text-xs font-bold text-slate-500">المسار</div>
              <div class="mt-1 flex flex-wrap items-center gap-2 text-lg font-black text-slate-900">
                <span class="font-mono">{{ booking.inventory?.route || '—' }}</span>
              </div>
            </div>
            <div v-if="printOptions.flightDates" class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">تاريخ السفر</div>
              <div class="mt-1 font-bold text-slate-900">{{ formatDate(booking.travel_date || booking.inventory?.travel_date) }}</div>
            </div>
            <div v-if="printOptions.flightDates" class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">وقت المغادرة</div>
              <div class="mt-1 font-mono font-bold text-slate-900">{{ formatTime(booking.inventory?.departure_time) }}</div>
            </div>
            <div class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">عدد المقاعد</div>
              <div class="mt-1 font-mono text-2xl font-black text-amber-700">{{ booking.quantity || booking.seats_count }}</div>
            </div>
            <div v-if="printOptions.price" class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">سعر المقعد</div>
              <div class="mt-1 font-mono font-bold text-slate-900">{{ formatMoney(booking.unit_price || booking.inventory?.selling_price) }}</div>
            </div>
            <div v-if="printOptions.baggage" class="break-inside-avoid rounded-lg border border-slate-200 p-4">
              <div class="text-xs font-bold text-slate-500">الأمتعة</div>
              <div class="mt-1 font-bold text-slate-900">{{ booking.baggage || 'أمتعة عادية' }}</div>
            </div>
          </div>

          <div v-if="printOptions.price" class="mb-8">
            <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">المبالغ</h3>
            <table class="w-full border-collapse overflow-hidden rounded-xl border border-slate-200 text-sm">
            <thead class="bg-slate-100 text-slate-600">
              <tr>
                <th class="border-b border-slate-200 px-4 py-2 text-right font-bold">البند</th>
                <th class="border-b border-slate-200 px-4 py-2 text-left font-mono font-bold">المبلغ</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b border-slate-100">
                <td class="px-4 py-3 font-semibold text-slate-700">الإجمالي</td>
                <td class="px-4 py-3 text-left font-mono font-bold text-slate-900">{{ formatMoney(booking.total_price) }}</td>
              </tr>
              <tr class="border-b border-slate-100">
                <td class="px-4 py-3 font-semibold text-slate-700">المدفوع</td>
                <td class="px-4 py-3 text-left font-mono font-bold text-emerald-700">{{ formatMoney(booking.paid_amount) }}</td>
              </tr>
              <tr>
                <td class="px-4 py-3 font-semibold text-slate-700">المتبقي</td>
                <td class="px-4 py-3 text-left font-mono font-bold text-rose-700">{{ formatMoney(booking.remaining_amount) }}</td>
              </tr>
            </tbody>
          </table>
          </div>

          <div v-if="booking.payments?.length" class="mb-8 break-inside-avoid">
            <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">سجل الدفعات</h3>
            <table class="w-full border-collapse rounded-xl border border-slate-200 text-sm">
              <thead class="bg-slate-100 text-slate-600">
                <tr>
                  <th class="border-b border-slate-200 px-3 py-2 text-right font-bold">المبلغ</th>
                  <th class="border-b border-slate-200 px-3 py-2 text-right font-bold">الطريقة</th>
                  <th class="border-b border-slate-200 px-3 py-2 text-right font-bold">التاريخ</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in booking.payments" :key="'pv-' + p.id" class="border-b border-slate-100 last:border-0">
                  <td class="px-3 py-2 font-mono font-bold">{{ formatMoney(p.amount) }}</td>
                  <td class="px-3 py-2 text-slate-700">{{ getPaymentMethodLabel(p.payment_method) }}</td>
                  <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ p.created_at }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="booking.notes" class="mb-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
            <span class="font-bold text-slate-500">ملاحظات: </span>{{ booking.notes }}
          </div>

          <PrintCompanyBranding
            module="bus"
            document-type="ticket"
            position="footer"
            :balance-due="booking.remaining_amount > 0.009 ? formatMoney(booking.remaining_amount) : null"
            balance-label="المستحق لنا"
          />
          <div class="border-t border-slate-200 pt-4 text-center text-[10px] leading-relaxed text-slate-500">
            وثيقة إعلامية — يُرجى التحقق من موعد المغادرة مع شركة النقل. للاستفسار: تواصل مع مكتب {{ printSettingsStore.settings.company_name_ar || 'سفرك علينا' }}.
          </div>
        </div>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4">إجراءات</h2>
        <div class="flex flex-wrap gap-3">
          <router-link :to="{ name: 'bus.list' }" class="btn-airline-ghost flex-1 justify-center sm:flex-none">
            القائمة
          </router-link>
          <button
            type="button"
            class="btn-airline-ghost flex-1 border-gold/30 text-gold sm:flex-none"
            @click="runPrintJob"
          >
            <Printer class="mb-0.5 ml-2 inline h-4 w-4" />
            طباعة
          </button>
          <button
            v-if="booking.status !== 'cancelled' && !booking.payments?.length"
            type="button"
            class="flex-1 rounded-xl border border-error/40 bg-error/10 px-4 py-3 font-bold text-error transition hover:bg-error/20 sm:flex-none"
            @click="confirmCancel"
          >
            <XCircle class="mb-0.5 ml-2 inline h-4 w-4" />
            إلغاء الحجز
          </button>
          <button
            v-if="booking.status !== 'cancelled' && booking.status !== 'refunded'"
            type="button"
            class="flex-1 rounded-xl border border-gold/40 bg-gold/10 px-4 py-3 font-bold text-gold transition hover:bg-gold/20 sm:flex-none"
            @click="showRefundModal = true"
          >
            <RotateCcw class="mb-0.5 ml-2 inline h-4 w-4" />
            استرجاع مالي
          </button>
        </div>
        <p v-if="booking.payments?.length && booking.status !== 'cancelled' && booking.status !== 'refunded'" class="mt-3 text-xs text-text-muted">
          لا يمكن إلغاء الحجز بعد تسجيل دفعات؛ تعديل مالي يتم من الإدارة.
        </p>
      </div>
    </template>

    <div
      v-if="showPaymentModal && booking"
      class="no-print fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      @click.self="closePaymentModal"
    >
      <div class="flight-panel max-h-[90vh] w-full max-w-md overflow-y-auto !p-6">
        <h3 class="flight-panel__title !text-xl">تسديد دفعة</h3>
        <p class="flight-panel__subtitle mb-4">المتبقي: {{ formatMoney(booking.remaining_amount) }}</p>
        <form class="space-y-4" @submit.prevent="submitPayment">
          <div>
            <label class="mb-2 block text-sm text-text-muted">المبلغ</label>
            <input
              v-model.number="paymentForm.amount"
              type="number"
              step="0.01"
              min="0.01"
              :max="booking.remaining_amount"
              required
              class="flight-input font-mono"
            />
          </div>
          <div>
            <label class="mb-2 block text-sm text-text-muted text-right">طريقة الدفع</label>
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
            <select v-model="paymentForm.payment_method" required class="flight-select">
              <option value="cash">نقدي</option>
              <option value="bank_transfer">تحويل بنكي</option>
              <option value="cash_wallet">محفظة</option>
              <option value="postal_transfer">بريد</option>
              <option value="office_safe">خزينة مكتب</option>
              <option value="office_drawer">درج مكتب</option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm text-text-muted text-right">حساب التحصيل</label>
            <select v-model="paymentForm.account_id" required class="flight-select">
              <option value="">— اختر الحساب —</option>
              <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }}
              </option>
            </select>
            <p v-if="!filteredAccounts.length" class="mt-2 text-xs text-warning text-right">
              لا توجد حسابات متوفرة في هذا القسم.
            </p>
          </div>
          <div>
            <label class="mb-2 block text-sm text-text-muted">ملاحظات</label>
            <textarea v-model="paymentForm.notes" rows="2" class="flight-input resize-none" />
          </div>
          <div class="flex gap-3">
            <button
              type="submit"
              class="flex-1 rounded-xl bg-success py-3 font-bold text-black disabled:opacity-50"
              :disabled="store.loading.payments || !paymentForm.account_id"
            >
              {{ store.loading.payments ? 'جاري التسديد…' : 'تسديد' }}
            </button>
            <button type="button" class="btn-airline-ghost flex-1" @click="closePaymentModal">إلغاء</button>
          </div>
        </form>
      </div>
    </div>
    <div
      v-if="showPrintModal"
      class="no-print fixed inset-0 z-[60] flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
      @click.self="closePrintModal"
    >
      <div class="flight-panel w-full max-w-md !p-0 overflow-hidden">
        <div class="bg-gradient-to-r from-gold/20 to-gold/5 px-6 py-4 border-b border-white/10">
          <h3 class="text-xl font-black text-text-main">إعدادات الطباعة</h3>
          <p class="text-xs text-text-muted mt-1 text-gold/80 uppercase tracking-widest font-bold">Print Configuration</p>
        </div>
        <div class="p-6 space-y-5">
          <div class="grid grid-cols-1 gap-4">
            <div v-for="(val, key) in printOptions" :key="key" class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03] border border-white/10">
              <span class="font-bold text-sm text-text-muted">
                {{
                  key === 'logo' ? 'شعار الشركة' :
                  key === 'tripDetails' ? 'تفاصيل المسار' :
                  key === 'flightDates' ? 'التواريخ والأوقات' :
                  key === 'passengers' ? 'بيانات الركاب' :
                  key === 'baggage' ? 'بيانات الأمتعة' :
                  key === 'price' ? 'تفاصيل السعر' :
                  key === 'notes' ? 'الملاحظات' : key
                }}
              </span>
              <button
                @click="printOptions[key] = !printOptions[key]"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                  printOptions[key] ? 'bg-success' : 'bg-white/10'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                    printOptions[key] ? 'translate-x-5' : 'translate-x-0'
                  ]"
                />
              </button>
            </div>
          </div>
          
          <div class="flex gap-3 pt-2">
            <button
              @click="runPrintJob"
              class="flex-1 rounded-xl bg-gold py-3.5 font-bold text-black transition hover:bg-gold/90 flex items-center justify-center gap-2"
            >
              <Printer class="h-5 w-5" />
              تأكيد والطباعة
            </button>
            <button @click="closePrintModal" class="btn-airline-ghost px-6">إلغاء</button>
          </div>
        </div>
      </div>
    </div>
    <div
      v-if="showRefundModal && booking"
      class="no-print fixed inset-0 z-[70] flex items-center justify-center bg-black/80 backdrop-blur-sm p-4"
      @click.self="showRefundModal = false"
    >
      <div class="w-full max-w-4xl max-h-[95vh] overflow-y-auto">
        <BusRefundWizard 
          :initial-booking="booking" 
          @completed="onRefundCompleted" 
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { useBusStore } from '@/stores/busStore';
import {
  ArrowRight,
  Loader2,
  MapPin,
  CreditCard,
  Printer,
  XCircle,
  RotateCcw,
  BusFront,
  Banknote,
  Wallet,
  Landmark,
} from 'lucide-vue-next';
import BusRefundWizard from '@/components/bus/BusRefundWizard.vue';
import PrintCompanyBranding from '@/components/print/PrintCompanyBranding.vue';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const route = useRoute();
const store = useBusStore();
const printSettingsStore = usePrintSettingsStore();

const id = computed(() => route.params.id);
const booking = ref(null);
const loadError = ref('');
const loadingDetail = ref(true);
const showPaymentModal = ref(false);
const showRefundModal = ref(false);
const settlementAccounts = ref([]);

const printOptions = ref({
  logo: true,
  tripDetails: true,
  flightDates: true,
  passengers: true,
  baggage: true,
  price: true,
  notes: true,
});

const showPrintModal = ref(false);

const paymentForm = ref({
  amount: 0,
  payment_method: 'cash',
  account_id: '',
  notes: '',
});

/** تاريخ/وقت يظهر على وصل الطباعة فقط */
const printTimestamp = ref('');

const bookingRef = computed(() => {
  if (!booking.value) return '';
  return String(booking.value.booking_number ?? booking.value.id ?? '');
});

const statusLabels = {
  pending: 'معلق',
  paid: 'مدفوع',
  cancelled: 'ملغي',
  refunded: 'مسترد',
  partially_refunded: 'مسترد جزئياً',
};

const statusStyles = {
  pending: 'bg-warning/15 text-warning',
  paid: 'bg-success/15 text-success',
  cancelled: 'bg-error/15 text-error',
  refunded: 'bg-gold/15 text-gold',
  partially_refunded: 'bg-blue-400/15 text-blue-400',
};

const paymentStatusLabels = {
  pending: 'معلق',
  partial: 'جزئي',
  paid: 'مدفوع',
  overdue: 'متأخر',
};

const paymentStatusStyles = {
  pending: 'text-warning',
  partial: 'text-blue-400',
  paid: 'text-success',
  overdue: 'text-error',
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const formatTime = (t) => {
  if (!t) return '';
  return String(t).slice(0, 5);
};

const formatMoney = (n) => {
  const x = Number(n) || 0;
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP' }).format(x);
  } catch {
    return `${x.toLocaleString('ar-EG')} ج.م`;
  }
};

const getPaymentMethodLabel = (method) => {
  const labels = {
    cash: 'نقدي',
    bank_transfer: 'تحويل بنكي',
    cash_wallet: 'محفظة',
    postal_transfer: 'بريد',
    office_safe: 'خزينة',
    office_drawer: 'درج',
  };
  return labels[method] || method;
};

const settlementCategoryUi = ref('cash');
const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

const filteredAccounts = computed(() => {
  if (settlementCategoryUi.value === 'cash') {
    return settlementAccounts.value.filter(a => a.type === 'cashbox' || a.type === 'treasury');
  }
  if (settlementCategoryUi.value === 'wallet') {
    return settlementAccounts.value.filter(a => a.type === 'wallet');
  }
  if (settlementCategoryUi.value === 'bank') {
    return settlementAccounts.value.filter(a => a.type === 'bank');
  }
  return settlementAccounts.value;
});

const loadAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/finance/accounts', {
      params: { 
        per_page: 100, 
        types: 'cashbox,wallet,bank,treasury', 
        is_active: 1,
        module: 'bus'
      },
    });
    let raw = res.data?.data;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      if (Array.isArray(raw.items)) {
        raw = raw.items;
      } else if (raw.items && Array.isArray(raw.items.data)) {
        raw = raw.items.data;
      } else if (Array.isArray(raw.data)) {
        raw = raw.data;
      }
    }
    settlementAccounts.value = Array.isArray(raw) ? raw : [];
  } catch (e) {
    console.error(e);
    settlementAccounts.value = [];
  }
};

const load = async () => {
  loadError.value = '';
  loadingDetail.value = true;
  try {
    const b = await store.fetchBooking(id.value);
    booking.value = b;
  } catch {
    loadError.value = store.errors?.fetch || 'تعذر تحميل الحجز.';
    booking.value = null;
  } finally {
    loadingDetail.value = false;
  }
};

const openPaymentModal = async () => {
  await loadAccounts();
  paymentForm.value = {
    amount: booking.value?.remaining_amount || 0,
    payment_method: 'cash',
    account_id: settlementAccounts.value[0]?.id ?? '',
    notes: '',
  };
  showPaymentModal.value = true;
};

const closePaymentModal = () => {
  showPaymentModal.value = false;
};

const submitPayment = async () => {
  try {
    await store.payBooking(booking.value.id, {
      ...paymentForm.value,
      account_id:
        paymentForm.value.account_id === '' ? null : parseInt(String(paymentForm.value.account_id), 10),
    });
    store.addToast('تم تسديد الدفعة بنجاح');
    closePaymentModal();
    await load();
  } catch {
    const msg = store.errors?.message || store.errors?.amount?.[0] || 'فشل تسديد الدفعة';
    store.addToast(typeof msg === 'string' ? msg : 'فشل تسديد الدفعة', 'error');
  }
};

const openPrintOptions = () => {
  showPrintModal.value = true;
};

const closePrintModal = () => {
  showPrintModal.value = false;
};

const runPrintJob = async () => {
  printTimestamp.value = new Date().toLocaleString('ar-EG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
  closePrintModal();
  await nextTick();
  const prevTitle = document.title;
  const refStr = bookingRef.value || 'bus';
  document.documentElement.classList.add('bus-print-active');
  document.title = `${refStr} — وصل باص — Safarak`;

  const cleanup = () => {
    document.documentElement.classList.remove('bus-print-active');
    document.title = prevTitle;
    window.removeEventListener('afterprint', cleanup);
  };
  window.addEventListener('afterprint', cleanup);
  window.setTimeout(() => {
    window.removeEventListener('afterprint', cleanup);
    document.documentElement.classList.remove('bus-print-active');
    document.title = prevTitle;
  }, 120_000);

  window.print();
};

const confirmCancel = async () => {
  if (!confirm(`إلغاء حجز #${booking.value?.booking_number}؟`)) return;
  try {
    await store.cancelBooking(booking.value.id);
    store.addToast('تم إلغاء الحجز');
    await load();
  } catch {
    store.addToast(store.errors?.message || 'فشل الإلغاء', 'error');
  }
};

const onRefundCompleted = async () => {
  showRefundModal.value = false;
  store.addToast('تمت معالجة الاسترجاع بنجاح');
  await load();
};

onMounted(() => {
  load();
  printSettingsStore.fetch().catch(() => {});
});
</script>

<style scoped>
.bus-print-document {
  print-color-adjust: exact;
  -webkit-print-color-adjust: exact;
}
</style>

<style>
.bus-print-barcode {
  min-height: 2.5rem;
  background: repeating-linear-gradient(
    90deg,
    #292524 0,
    #292524 3px,
    #fef3c7 3px,
    #fef3c7 5px
  );
}

@media print {
  @page {
    size: A4 portrait;
    margin: 10mm;
  }

  html.bus-print-active {
    background: #fff !important;
  }

  body * {
    visibility: hidden !important;
  }

  #bus-ticket-content,
  #bus-ticket-content * {
    visibility: visible !important;
  }

  #bus-ticket-content {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    max-width: 100%;
    margin: 0;
    padding: 0;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
    overflow: visible !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }

  .no-print {
    display: none !important;
  }

  .break-inside-avoid {
    break-inside: avoid;
    page-break-inside: avoid;
  }
}
</style>
