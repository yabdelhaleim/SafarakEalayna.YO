<template>
  <div v-if="loading" class="flex min-h-[50vh] flex-col items-center justify-center gap-4">
    <Loader2 class="h-12 w-12 animate-spin text-sky-400" />
    <p class="animate-pulse text-sm text-text-muted">جاري تحميل تفاصيل الحجز...</p>
  </div>

  <div v-else-if="!booking" class="py-20 text-center">
    <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-error/15 text-error">
      <AlertTriangle class="h-10 w-10" />
    </div>
    <h1 class="text-2xl font-black text-text-main">الحجز غير موجود</h1>
    <p class="mt-2 text-text-muted">الحجز الذي تبحث عنه غير موجود أو تم حذفه.</p>
    <router-link :to="{ name: 'flights.index' }" class="btn-airline-ghost mt-6 inline-flex px-6 py-2.5">
      العودة للقائمة
    </router-link>
  </div>

  <div v-else class="flight-booking flight-show mx-auto max-w-7xl space-y-8 pb-16">
    <header class="flight-hero relative no-print">
      <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
          <router-link :to="{ name: 'flights.index' }" class="btn-airline-ghost shrink-0 rounded-xl p-2.5">
            <ArrowRight class="h-5 w-5 text-sky-300" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">تفاصيل الحجز</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
              <h1 class="font-mono text-2xl font-black tracking-tight text-text-main sm:text-3xl">
                {{ booking.bookingNumber }}
              </h1>
              <div
                :class="[
                  'rounded-full border px-3 py-1 text-xs font-black uppercase tracking-wider',
                  statusStyles[booking.status],
                ]"
              >
                {{ getStatusLabel(booking.status) }}
              </div>
              <div
                v-if="booking.paymentStatusLabel"
                :class="[
                  'rounded-full border px-3 py-1 text-xs font-black tracking-wide',
                  paymentStatusBadgeClass(booking.paymentStatus),
                ]"
              >
                {{ booking.paymentStatusLabel }}
              </div>
            </div>
            <p class="mt-2 text-xs uppercase tracking-widest text-text-muted">
              تم الإنشاء في {{ formatDate(booking.createdAt) }}
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
              <span
                class="inline-flex min-h-[2.25rem] items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-3 py-1.5 text-xs font-bold text-text-muted"
              >
                <Users class="h-4 w-4 text-gold" />
                {{ booking.passengers?.length || 0 }} مسافر
              </span>
              <span
                class="inline-flex min-h-[2.25rem] items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-3 py-1.5 text-xs font-bold text-text-muted"
              >
                <Plane class="h-4 w-4 text-sky-300" />
                {{ ticketSegments.length }} مقطع
              </span>
              <span
                class="inline-flex min-h-[2.25rem] items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-3 py-1.5 text-xs font-bold text-text-muted"
              >
                <DollarSign class="h-4 w-4 text-success" />
                {{ formatCurrency(booking.pricing?.sellingPrice) }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:gap-3">
          <button
            type="button"
            class="btn-airline-ghost inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold sm:px-5"
            @click="openPrintDialog"
          >
            <Printer class="h-4 w-4" />
            طباعة / PDF
          </button>
          
          <button
            v-if="booking.status === 'pending'"
            type="button"
            class="bg-emerald-500 hover:bg-emerald-600 text-white inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-black shadow-lg shadow-emerald-500/20 transition-all scale-105 active:scale-95"
            @click="runConfirmBooking"
          >
            <CheckCircle class="h-4 w-4" />
            تأكيد الحجز الآن
          </button>
          <button
            v-if="booking.status !== 'cancelled' && booking.status !== 'refunded'"
            type="button"
            class="bg-amber-500 hover:bg-amber-600 text-white inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-black shadow-lg shadow-amber-500/20 transition-all scale-105 active:scale-95"
            @click="showRefundModal = true"
          >
            <RefreshCw class="h-4 w-4" />
            إصدار استرجاع للتذكرة
          </button>
          <button
            v-if="booking.status !== 'cancelled' && booking.status !== 'refunded'"
            type="button"
            class="bg-cyan-500 hover:bg-cyan-600 text-white inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-black shadow-lg shadow-cyan-500/20 transition-all scale-105 active:scale-95"
            @click="showModificationModal = true"
          >
            <Settings class="h-4 w-4" />
            طلب تعديل التذكرة
          </button>
          <router-link
            :to="{ name: 'flights.edit', params: { id: booking.id } }"
            class="bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold border border-slate-700 transition-all"
            title="تعديل البيانات النصية والمسافرين"
          >
            <Edit class="h-4 w-4" />
            تعديل البيانات
          </router-link>
          <button
            type="button"
            class="rounded-xl border border-transparent p-2.5 text-error transition-all hover:border-error/25 hover:bg-error/10"
            @click="confirmDelete"
          >
            <Trash2 class="h-5 w-5" />
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-8">
      <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
          <p class="no-print text-xs text-text-muted">
            معاينة التذكرة أدناه. للحصول على ملف PDF: من نافذة الطباعة اختر «Microsoft Print to PDF» أو «Save as PDF».
          </p>
          <div
            id="ticket-content"
            class="ticket-print-document overflow-hidden rounded-2xl bg-white text-slate-900 shadow-2xl print:rounded-none print:shadow-none"
            style="border: 2px solid #e2e8f0;"
          >
            <!-- ===== HEADER ===== -->
            <div class="ticket-header" style="background: linear-gradient(135deg, #0c1a2e 0%, #1a3a5c 50%, #0c1a2e 100%); padding: 22px 32px; display:flex; align-items:center; justify-content:space-between; gap:16px;">
              <div style="display:flex; align-items:center; gap:16px;">
                <div style="width:56px; height:56px; border-radius:14px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center;">
                  <Plane style="width:28px; height:28px; color:#d4a843;" />
                </div>
                <div>
                  <div style="font-size:10px; font-weight:700; letter-spacing:0.22em; text-transform:uppercase; color:#7dd3fc; margin-bottom:3px; font-family:'Segoe UI',Arial,sans-serif;">Electronic Ticket</div>
                  <div style="font-size:26px; font-weight:900; color:#ffffff; line-height:1.1; font-family:'Segoe UI',Arial,sans-serif;">تذكرة سفر</div>
                  <div style="font-family:'Courier New',monospace; font-size:13px; color:#bae6fd; margin-top:4px; letter-spacing:0.06em;">{{ booking.bookingNumber }}</div>
                </div>
              </div>
              <div v-if="printOptions.logo" style="text-align:left;">
                <div style="font-size:20px; font-weight:900; color:#d4a843; letter-spacing:-0.01em; font-family:'Segoe UI',Arial,sans-serif;">سفرك علينا</div>
                <div style="font-size:10px; font-weight:600; letter-spacing:0.18em; text-transform:uppercase; color:#93c5fd; margin-top:3px; font-family:'Segoe UI',Arial,sans-serif;">Safarak Ealayna</div>
              </div>
              <div class="ticket-barcode" style="height:44px; width:170px; border-radius:6px; border:1px solid rgba(255,255,255,0.2);" aria-hidden="true" />
            </div>

            <!-- ===== PNR STRIP ===== -->
            <div v-if="(booking.pnr || booking.tripType || booking.trip_type) && printOptions.tripDetails"
              style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px 28px; display:flex; align-items:center; gap:20px;">
              <div>
                <div style="font-size:9px; font-weight:700; letter-spacing:0.18em; text-transform:uppercase; color:#64748b;">PNR / مرجع</div>
                <div style="font-family:monospace; font-size:18px; font-weight:900; color:#0f172a; letter-spacing:0.05em;">{{ booking.pnr || '—' }}</div>
              </div>
              <div style="width:1px; height:36px; background:#cbd5e1;"></div>
              <div>
                <div style="font-size:9px; font-weight:700; letter-spacing:0.18em; text-transform:uppercase; color:#64748b;">نوع الرحلة</div>
                <div style="font-size:14px; font-weight:800; color:#0f172a;">{{ getTripTypeLabel(booking.tripType || booking.trip_type) }}</div>
              </div>
              <div style="width:1px; height:36px; background:#cbd5e1;"></div>
              <div>
                <div style="font-size:9px; font-weight:700; letter-spacing:0.18em; text-transform:uppercase; color:#64748b;">تاريخ الإصدار</div>
                <div style="font-size:14px; font-weight:800; color:#0f172a;">{{ formatDate(booking.createdAt) }}</div>
              </div>
            </div>

            <!-- ===== BODY ===== -->
            <div style="padding: 24px 28px;">

              <!-- ROUTE SECTION -->
              <div v-if="printOptions.tripDetails" style="margin-bottom:24px;">
                <div style="font-size:9px; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#64748b; margin-bottom:14px; padding-bottom:8px; border-bottom: 2px solid #e2e8f0;">✈ مسار الرحلة</div>
                <div style="display:flex; flex-direction:column; gap:18px;">
                  <div v-for="(segment, idx) in ticketSegments" :key="idx" class="ticket-segment break-inside-avoid"
                    style="border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden;">
                    <!-- segment header -->
                    <div style="background:#f1f5f9; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0;">
                      <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:28px; height:28px; border-radius:8px; background:#1a3a5c; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:900; color:#fff;">{{ idx + 1 }}</div>
                        <div>
                          <div style="font-weight:700; color:#0f172a; font-size:13px;">{{ segment.airline || segment.airline_name || '—' }}</div>
                          <div style="font-family:monospace; font-size:10px; color:#64748b;">{{ segment.flight_number || segment.flightNumber || '—' }}</div>
                        </div>
                      </div>
                      <div v-if="printOptions.flightDates" style="text-align:left;">
                        <div style="font-size:9px; font-weight:700; letter-spacing:0.15em; text-transform:uppercase; color:#64748b;">تاريخ الرحلة</div>
                        <div style="font-weight:800; color:#0f172a; font-size:13px;">{{ formatDate(segment.departureDate || segment.departure_date) }}</div>
                      </div>
                    </div>
                    <!-- airports row -->
                    <div style="padding:16px; display:grid; grid-template-columns:1fr auto 1fr; gap:12px; align-items:center;">
                      <!-- FROM -->
                      <div style="text-align:center; background:#f0f4ff; border-radius:12px; padding:16px 12px; border:1.5px solid #c7d7ff;">
                        <div style="font-family:'Courier New',monospace; font-size:44px; font-weight:900; color:#0f172a; letter-spacing:0.08em; line-height:1;">{{ segment.from || segment.from_airport || '—' }}</div>
                        <div style="font-size:10px; font-weight:800; letter-spacing:0.2em; text-transform:uppercase; color:#4b6bab; margin-top:6px; font-family:'Segoe UI',Arial,sans-serif;">مغادرة</div>
                        <div style="font-family:'Segoe UI',Arial,sans-serif; font-size:14px; font-weight:800; color:#1d4ed8; margin-top:6px;">
                          {{ (segment.departureTime && segment.departureTime !== '00:00' && segment.departureTime !== '00:00:00') ? segment.departureTime + ' • ' : '' }}{{ formatDate(segment.departureDate || segment.departure_date) }}
                        </div>
                      </div>
                      <!-- ARROW -->
                      <div style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:0 12px;">
                        <div style="width:2px; height:36px; background:linear-gradient(to bottom, transparent, #d4a843);"></div>
                        <Plane style="width:26px; height:26px; color:#d4a843; transform:rotate(90deg);" />
                        <div style="font-size:10px; font-weight:800; letter-spacing:0.15em; text-transform:uppercase; color:#94a3b8; font-family:'Segoe UI',Arial,sans-serif;">إلى</div>
                        <div style="width:2px; height:36px; background:linear-gradient(to top, transparent, #d4a843);"></div>
                      </div>
                      <!-- TO -->
                      <div style="text-align:center; background:#f0f4ff; border-radius:12px; padding:16px 12px; border:1.5px solid #c7d7ff;">
                        <div style="font-family:'Courier New',monospace; font-size:44px; font-weight:900; color:#0f172a; letter-spacing:0.08em; line-height:1;">{{ segment.to || segment.to_airport || '—' }}</div>
                        <div style="font-size:10px; font-weight:800; letter-spacing:0.2em; text-transform:uppercase; color:#4b6bab; margin-top:6px; font-family:'Segoe UI',Arial,sans-serif;">وصول</div>
                        <div style="font-family:'Segoe UI',Arial,sans-serif; font-size:14px; font-weight:800; color:#1d4ed8; margin-top:6px;">
                          {{ (segment.arrivalTime && segment.arrivalTime !== '00:00' && segment.arrivalTime !== '00:00:00') ? segment.arrivalTime + ' • ' : '' }}{{ segment.returnDate ? formatDate(segment.returnDate) : '' }}
                        </div>
                      </div>
                    </div>
                    <!-- details chips -->
                    <div style="padding:0 16px 18px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                      <div style="background:#f1f5f9; border-radius:10px; padding:10px; text-align:center; border:1.5px solid #e2e8f0;">
                        <div style="font-size:9px; font-weight:800; letter-spacing:0.18em; text-transform:uppercase; color:#475569; margin-bottom:5px; font-family:'Segoe UI',Arial,sans-serif;">الدرجة</div>
                        <div style="font-weight:900; color:#0f172a; font-size:14px; text-transform:uppercase; font-family:'Segoe UI',Arial,sans-serif;">{{ segment.flight_class_label || segment.flightClass || segment.flight_class || 'ECONOMY' }}</div>
                      </div>
                      <div v-if="printOptions.baggage" style="background:#f1f5f9; border-radius:10px; padding:10px; text-align:center; border:1.5px solid #e2e8f0;">
                        <div style="font-size:9px; font-weight:800; letter-spacing:0.18em; text-transform:uppercase; color:#475569; margin-bottom:5px; font-family:'Segoe UI',Arial,sans-serif;">الأمتعة</div>
                        <div style="font-weight:900; color:#0f172a; font-size:14px; font-family:'Segoe UI',Arial,sans-serif;">{{ formatSegmentBaggage(segment) }}</div>
                      </div>
                      <div style="background:#f1f5f9; border-radius:10px; padding:10px; text-align:center; border:1.5px solid #e2e8f0;">
                        <div style="font-size:9px; font-weight:800; letter-spacing:0.18em; text-transform:uppercase; color:#475569; margin-bottom:5px; font-family:'Segoe UI',Arial,sans-serif;">المسار</div>
                        <div style="font-weight:900; color:#0f172a; font-size:14px; font-family:'Courier New',monospace;">{{ (segment.from || segment.from_airport || '?') }} → {{ (segment.to || segment.to_airport || '?') }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- PASSENGERS -->
              <div v-if="printOptions.passengerInfo" class="break-inside-avoid" style="margin-bottom:24px;">
                <div style="font-size:9px; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#64748b; margin-bottom:14px; padding-bottom:8px; border-bottom:2px solid #e2e8f0;">👤 المسافرون</div>
                <template v-for="type in ['adult', 'child', 'infant']" :key="type">
                  <div v-if="getPassengersByType(type).length > 0" style="margin-bottom:12px; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                    <div style="background:#f1f5f9; padding:8px 14px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #e2e8f0;">
                      <Users style="width:14px; height:14px; color:#1d4ed8;" />
                      <span style="font-weight:700; color:#0f172a; font-size:12px;">{{ getPassengerTypeLabel(type) }}</span>
                      <span style="background:#1d4ed8; color:#fff; border-radius:999px; padding:1px 8px; font-size:10px; font-weight:900;">{{ getPassengersByType(type).length }}</span>
                    </div>
                    <div v-for="(passenger, idx) in getPassengersByType(type)" :key="passenger.id || idx"
                      style="padding:10px 14px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9;">
                      <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:34px; height:34px; border-radius:50%; background:#fef3c7; display:flex; align-items:center; justify-content:center;">
                          <User style="width:16px; height:16px; color:#d4a843;" />
                        </div>
                        <div>
                          <div style="font-weight:700; color:#0f172a; font-size:13px;">{{ passenger.name }}</div>
                          <div style="font-family:monospace; font-size:10px; color:#64748b;">{{ generateTicketNumber(type, idx) }}</div>
                        </div>
                      </div>
                      <div style="text-align:left; font-size:11px; color:#475569;">
                        <div v-if="passenger.passportNumber">جواز: {{ passenger.passportNumber }}</div>
                        <div v-if="passenger.dateOfBirth">م: {{ passenger.dateOfBirth }}</div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>

              <!-- PRICE -->
              <div v-if="printOptions.price" class="break-inside-avoid" style="margin-bottom:24px; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                <div style="background:#f1f5f9; padding:10px 16px; font-size:9px; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#64748b; border-bottom:1px solid #e2e8f0;">💰 تفاصيل السعر</div>
                <div style="padding:14px 16px; display:flex; flex-direction:column; gap:10px;">
                  <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
                    <span style="color:#475569; font-size:13px;">سعر الشراء</span>
                    <span style="font-weight:900; color:#0f172a; font-size:16px;">{{ formatCurrency(booking.pricing.purchasePrice) }}</span>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
                    <span style="color:#475569; font-size:13px;">سعر البيع</span>
                    <span style="font-weight:900; color:#1d4ed8; font-size:16px;">{{ formatCurrency(booking.pricing.sellingPrice) }}</span>
                  </div>
                  <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7); border-radius:10px; padding:14px; display:flex; justify-content:space-between; align-items:center; border:1px solid #bbf7d0;">
                    <div>
                      <div style="font-size:12px; color:#166534;">صافي الربح</div>
                      <div style="font-size:10px; color:#4ade80;">هامش: {{ profitPercentage }}٪</div>
                    </div>
                    <div :style="{ fontSize:'22px', fontWeight:'900', color: booking.pricing.profit >= 0 ? '#15803d' : '#dc2626' }">
                      {{ booking.pricing.profit >= 0 ? '+' : '' }}{{ formatCurrency(Math.abs(booking.pricing.profit)) }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- PAYMENTS -->
              <div v-if="printOptions.payments && booking.payments && booking.payments.length > 0" class="break-inside-avoid" style="margin-bottom:24px; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                <div style="background:#f1f5f9; padding:10px 16px; font-size:9px; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#64748b; border-bottom:1px solid #e2e8f0;">🧾 ملخص الدفع</div>
                <div style="padding:12px 16px; display:flex; flex-direction:column; gap:8px;">
                  <div v-for="(payment, idx) in booking.payments" :key="payment.id || idx"
                    style="background:#eff6ff; border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; border:1px solid #bfdbfe;">
                    <div>
                      <div style="font-weight:700; color:#0f172a; font-size:13px;">{{ payment.methodLabel || getPaymentMethodLabel(payment.paymentMethod || payment.payment_method) }}</div>
                      <div v-if="payment.account?.name" style="font-size:10px; color:#475569;">{{ payment.account.name }}</div>
                      <div style="font-size:10px; color:#64748b;">{{ formatDate(payment.createdAt, true) }}</div>
                    </div>
                    <div style="text-align:left;">
                      <div style="font-weight:900; color:#1e40af; font-size:18px;">{{ formatCurrency(payment.amount) }}</div>
                      <div style="font-size:9px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:#64748b;">مدفوع</div>
                    </div>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center; padding-top:10px; border-top:2px solid #e2e8f0;">
                    <span style="font-weight:700; color:#0f172a;">المتبقي</span>
                    <span :style="{ fontWeight:'900', fontSize:'18px', color: getRemainingBalance() > 0 ? '#b45309' : '#15803d' }">
                      {{ formatCurrency(Math.abs(getRemainingBalance())) }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- NOTES -->
              <div v-if="printOptions.notes && booking.notes" class="break-inside-avoid" style="margin-bottom:24px;">
                <div style="font-size:9px; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#64748b; margin-bottom:10px;">📝 ملاحظات</div>
                <div style="border-right:4px solid #f59e0b; background:#fffbeb; border-radius:8px; padding:14px; color:#292524; font-size:13px; line-height:1.6;">
                  {{ booking.notes }}
                </div>
              </div>

              <!-- FOOTER -->
              <div class="ticket-print-footer" style="border-top:2px dashed #e2e8f0; padding-top:16px; text-align:center;">
                <div style="font-weight:700; color:#475569; font-size:12px;">سفرك علينا — Safarak Ealayna</div>
                <div style="margin-top:4px; color:#64748b; font-size:10px;">وثيقة تذكرة إلكترونية — صالحة للطباعة والأرشفة PDF</div>
                <div style="margin-top:6px; font-family:monospace; font-size:14px; font-weight:900; color:#0f172a; letter-spacing:0.08em;">{{ booking.bookingNumber }}</div>
              </div>

            </div>
          </div>
        </div>

        <div class="no-print space-y-6">
          <!-- ملخص التعديلات السابقة -->
          <div v-if="booking.modifications?.length || booking.modificationCount > 0" class="flight-panel !p-6 border-cyan-500/30">
            <h3 class="flight-panel__title mb-3 flex items-center gap-2 text-cyan-400">
              <Settings class="h-5 w-5" />
              أرشيف التعديلات السابقة
              <span class="bg-cyan-500/20 text-cyan-300 px-2 py-0.5 rounded-full text-xs font-mono">
                {{ booking.modifications?.length || booking.modificationCount }}
              </span>
            </h3>
            <div class="space-y-3">
              <div v-for="mod in booking.modifications" :key="mod.id" class="p-3 rounded-xl bg-white/5 border border-white/5 space-y-1 text-xs">
                <div class="flex justify-between items-center">
                  <span class="font-bold text-white">{{ mod.modification_type === 'date_change' ? 'تعديل موعد' : mod.modification_type === 'destination_change' ? 'تعديل وجهة' : 'موعد ووجهة' }}</span>
                  <span :class="['px-2 py-0.5 rounded text-[10px]', mod.status === 'confirmed' ? 'bg-success/20 text-success font-bold' : 'bg-warning/20 text-warning']">{{ mod.status === 'confirmed' ? 'مؤكد' : 'مسودة' }}</span>
                </div>
                <div class="text-muted text-[11px] flex justify-between">
                  <span>الغرامة: {{ mod.airline_change_fee }}</span>
                  <span class="text-success font-bold">العمولة: {{ mod.agency_commission }}</span>
                </div>
                <div v-if="mod.new_departure_date || mod.new_destination" class="pt-1 border-t border-white/5 text-cyan-300 text-[11px]">
                  <span v-if="mod.new_departure_date">📅 {{ mod.new_departure_date }} </span>
                  <span v-if="mod.new_destination">✈️ {{ mod.new_destination }}</span>
                </div>
              </div>
              <p v-if="!booking.modifications?.length" class="text-xs text-muted">يوجد تعديلات مسجلة في الأرشيف المالي.</p>
            </div>
          </div>

          <div v-if="booking.flightSystem" class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-4 flex items-center gap-2">
              <Plane class="h-5 w-5 text-gold" />
              نظام الحجز والرصيد
            </h3>
            <div class="space-y-2 rounded-xl border border-white/10 bg-white/[0.05] p-4 text-sm">
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">النظام</span>
                <span class="text-left font-bold text-text-main">{{ booking.flightSystem.name }}</span>
              </div>
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">الرصيد الحالي</span>
                <span class="font-mono font-bold text-gold tabular-nums">
                  {{ formatCurrency(booking.flightSystem.balance, booking.flightSystem.currency) }}
                </span>
              </div>
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">حد الائتمان</span>
                <span class="font-mono text-text-main tabular-nums">
                  {{ formatCurrency(booking.flightSystem.creditLimit, booking.flightSystem.currency) }}
                </span>
              </div>
              <div class="flex items-center justify-between gap-2 border-t border-white/10 pt-2">
                <span class="font-bold text-sky-200">المتاح</span>
                <span class="font-mono text-lg font-black text-success tabular-nums">
                  {{ formatCurrency(booking.flightSystem.availableBalance, booking.flightSystem.currency) }}
                </span>
              </div>
            </div>
          </div>

          <div class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-1 flex items-center gap-2">
              <FileText class="h-5 w-5 text-gold" />
              ملخص سريع
            </h3>
            <p class="flight-panel__subtitle mb-5">مزامنة مع بيانات التذكرة</p>
            <div class="space-y-3">
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">المسافرون</span>
                <span class="font-mono font-bold text-text-main">{{ booking.passengers?.length || 0 }}</span>
              </div>
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">المقاطع</span>
                <span class="font-mono font-bold text-text-main">{{ ticketSegments.length }}</span>
              </div>
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">الحالة</span>
                <span
                  :class="[
                    'font-bold',
                    booking.status === 'confirmed' ? 'text-success' : 'text-warning',
                  ]"
                >
                  {{ getStatusLabel(booking.status) }}
                </span>
              </div>
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">التحصيل</span>
                <span class="font-bold text-sky-200">{{ booking.paymentStatusLabel || '—' }}</span>
              </div>
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">المدفوع</span>
                <span class="font-mono font-bold text-text-main tabular-nums">
                  {{ formatCurrency(booking.totalPaid) }}
                </span>
              </div>
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm">
                <span class="text-text-muted">المتبقي</span>
                <span
                  class="font-mono font-bold tabular-nums"
                  :class="getRemainingBalance() > 0.009 ? 'text-amber-300' : 'text-success'"
                >
                  {{ formatCurrency(Math.max(0, getRemainingBalance())) }}
                </span>
              </div>
            </div>
            
            <!-- Confirm Booking Call to Action -->
            <div v-if="booking.status === 'pending'" class="mt-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
              <p class="text-[10px] text-emerald-300 font-bold uppercase mb-2">إجراء مطلوب</p>
              <button
                type="button"
                class="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-black transition-all flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/10"
                @click="runConfirmBooking"
              >
                <CheckCircle class="h-4 w-4" />
                تأكيد الحجز الآن
              </button>
            </div>
          </div>

          <div v-if="showExtraPaymentForm" class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-1 flex items-center gap-2">
              <DollarSign class="h-5 w-5 text-gold" />
              إضافة دفعة
            </h3>
            <p class="flight-panel__subtitle mb-4 text-xs leading-relaxed">
              تُسجّل الدفعة على الحجز وتُضاف للحساب المختار وفق طريقة التحصيل (نقدي / بنك / محفظة).
            </p>
            <div class="space-y-3">
              <div>
                <label class="mb-1 block text-xs font-bold text-text-muted">طريقة التحصيل</label>
                <select
                  v-model="extraPayment.payment_method"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2.5 text-sm font-bold text-text-main outline-none focus:border-sky-500/50"
                >
                  <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
              </div>
              <div>
                <label class="mb-1 block text-xs font-bold text-text-muted">حساب التحصيل</label>
                <select
                  v-model.number="extraPayment.account_id"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2.5 text-sm font-bold text-text-main outline-none focus:border-sky-500/50"
                >
                  <option :value="null" disabled>اختر الحساب</option>
                  <option v-for="a in filteredSettlementForExtra" :key="a.id" :value="a.id">
                    {{ a.name }} — {{ accountTypeLabel(a.type) }}
                  </option>
                </select>
                <p v-if="!settlementAccounts.length" class="mt-2 text-xs text-warning">
                  لا توجد حسابات تحصيل. راجع خزينة الطيران أو إعدادات الحسابات.
                </p>
              </div>
              <div
                v-if="selectedExtraSettlementAccount"
                class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-text-muted">الحساب</span>
                  <span class="text-left font-bold text-text-main">{{ selectedExtraSettlementAccount.name }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                  <span class="text-text-muted">الرصيد الحالي</span>
                  <span class="flex flex-wrap items-center gap-2 font-mono font-black text-gold tabular-nums">
                    {{ formatMoney(selectedExtraSettlementAccount.balance, selectedExtraSettlementAccount.currency) }}
                    <span
                      v-if="settlementFlash && settlementFlash.accountId === selectedExtraSettlementAccount.id"
                      class="animate-pulse text-lg font-black text-success"
                    >
                      +{{ formatMoney(settlementFlash.amount, settlementFlash.currency) }}
                    </span>
                  </span>
                </div>
                <div
                  v-if="extraPaymentAmountNum > 0"
                  class="mt-2 flex items-center justify-between border-t border-white/10 pt-2 text-xs text-text-muted"
                >
                  <span>بعد الدفعة (تقديري)</span>
                  <span class="font-mono font-bold text-sky-200 tabular-nums">
                    {{
                      formatMoney(
                        Number(selectedExtraSettlementAccount.balance || 0) + extraPaymentAmountNum,
                        selectedExtraSettlementAccount.currency
                      )
                    }}
                  </span>
                </div>
              </div>
              <div>
                <label class="mb-1 block text-xs font-bold text-text-muted">المبلغ</label>
                <input
                  v-model="extraPayment.amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2.5 text-sm font-bold text-text-main outline-none focus:border-sky-500/50"
                  placeholder="0.00"
                />
              </div>
              <div>
                <label class="mb-1 block text-xs font-bold text-text-muted">ملاحظات (اختياري)</label>
                <input
                  v-model="extraPayment.notes"
                  type="text"
                  maxlength="500"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.06] px-3 py-2.5 text-sm text-text-main outline-none focus:border-sky-500/50"
                  placeholder="—"
                />
              </div>
              <button
                type="button"
                class="btn-airline flex w-full items-center justify-center gap-2 py-3 text-sm font-black disabled:opacity-50"
                :disabled="submittingExtraPayment || !canSubmitExtraPayment"
                @click="submitExtraPayment"
              >
                <Loader2 v-if="submittingExtraPayment" class="h-4 w-4 animate-spin" />
                <span>تسجيل الدفعة</span>
              </button>
            </div>
          </div>

          <div class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-4 flex items-center gap-2">
              <User class="h-5 w-5 text-gold" />
              بيانات العميل
            </h3>
            <div class="mb-4 flex items-center gap-4 rounded-xl border border-white/10 bg-white/[0.05] p-4">
              <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gold/15 text-lg font-black text-gold"
              >
                {{ booking.customer?.name?.charAt(0) || '?' }}
              </div>
              <div class="min-w-0">
                <div class="truncate font-bold text-text-main">{{ booking.customer?.name || '—' }}</div>
                <div class="text-xs text-text-muted">عميل #{{ booking.customer?.id || '—' }}</div>
              </div>
            </div>
            <div class="space-y-3 text-sm">
              <div class="flex items-center gap-3">
                <Phone class="h-4 w-4 shrink-0 text-sky-400/80" />
                <span class="text-text-muted">{{ booking.customer?.phone || '—' }}</span>
              </div>
              <div v-if="booking.customer?.email" class="flex items-center gap-3">
                <Mail class="h-4 w-4 shrink-0 text-sky-400/80" />
                <span class="truncate text-text-muted">{{ booking.customer.email }}</span>
              </div>
            </div>
          </div>

          <div class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-4 flex items-center gap-2">
              <Plane class="h-5 w-5 text-gold" />
              شركة الطيران
            </h3>
            <div class="space-y-3 rounded-xl border border-white/10 bg-white/[0.05] p-4 text-sm">
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">الشركة</span>
                <span class="text-left font-bold text-text-main">{{ booking.airlineName || '—' }}</span>
              </div>
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">النظام</span>
                <span class="text-left font-bold text-text-main">{{ booking.systemType }}</span>
              </div>
              <div class="flex items-center justify-between gap-2">
                <span class="text-text-muted">العملة</span>
                <span class="font-mono font-bold text-gold">{{ booking.pricing?.currency || 'EGP' }}</span>
              </div>
            </div>
          </div>

          <div class="flight-panel !p-6">
            <h3 class="flight-panel__title mb-4 flex items-center gap-2">
              <Settings class="h-5 w-5 text-gold" />
              إجراءات
            </h3>
            <div class="space-y-2">
              <button
                type="button"
                class="btn-airline-ghost flex w-full items-center justify-center gap-2 py-3 text-sm font-bold"
                @click="openPrintDialog"
              >
                <Printer class="h-4 w-4" />
                طباعة / PDF
              </button>
              <button
                type="button"
                class="btn-airline-ghost flex w-full items-center justify-center gap-2 py-3 text-sm font-bold"
                @click="openStatusMenu"
              >
                <RefreshCw class="h-4 w-4" />
                تغيير الحالة
              </button>
              <button
                v-if="booking.status !== 'cancelled' && booking.status !== 'refunded'"
                type="button"
                class="btn-airline-ghost flex w-full items-center justify-center gap-2 py-3 text-sm font-bold text-amber-400 hover:bg-amber-500/10"
                @click="showRefundModal = true"
              >
                <RefreshCw class="h-4 w-4" />
                معالجة استرجاع
              </button>
              <button
                type="button"
                :disabled="sendingTicketEmail"
                class="flex w-full items-center justify-center gap-2 rounded-xl border border-sky-500/30 bg-sky-500/10 py-3 text-sm font-bold text-sky-200 transition-all hover:bg-sky-500/20 disabled:opacity-50"
                @click="sendEmail"
              >
                <Loader2 v-if="sendingTicketEmail" class="h-4 w-4 animate-spin" />
                <Mail v-else class="h-4 w-4" />
                {{ sendingTicketEmail ? 'جاري الإرسال...' : 'إرسال بالبريد' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="showPrintModal"
      class="no-print fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    >
      <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-white/10 bg-slate-900/95 p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="flex items-center gap-2 text-xl font-black text-white">
            <Printer class="h-5 w-5 text-gold" />
            طباعة و PDF
          </h3>
          <button
            type="button"
            class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-white/10"
            @click="showPrintModal = false"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
        <p class="mb-5 rounded-lg border border-sky-500/25 bg-sky-500/10 p-3 text-xs leading-relaxed text-sky-100/90">
          <strong class="text-white">PDF:</strong>
          بعد الضغط على «فتح الطباعة» اختر الطابعة
          <span class="font-mono text-gold">Microsoft Print to PDF</span>
          أو
          <span class="font-mono text-gold">Save as PDF</span>
          ثم احفظ الملف.
        </p>

        <div class="space-y-3">
          <div
            v-for="option in printOptionList"
            :key="option.key"
            class="flex items-center justify-between rounded-xl bg-white/5 p-4"
          >
            <div class="flex items-center gap-3">
              <component :is="option.icon" class="h-5 w-5 shrink-0 text-gold" />
              <div>
                <div class="font-bold text-white">{{ option.label }}</div>
                <div class="text-xs text-gray-400">{{ option.description }}</div>
              </div>
            </div>
            <button
              type="button"
              :class="[
                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                printOptions[option.key] ? 'bg-gold' : 'bg-gray-600',
              ]"
              @click="printOptions[option.key] = !printOptions[option.key]"
            >
              <span
                :class="[
                  'inline-block h-4 w-4 transform rounded-full bg-white transition',
                  printOptions[option.key] ? 'translate-x-6' : 'translate-x-1',
                ]"
              />
            </button>
          </div>
        </div>

        <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:gap-3">
          <button
            type="button"
            class="btn-airline-ghost flex-1 py-3 font-bold"
            @click="showPrintModal = false"
          >
            إلغاء
          </button>
          <button
            type="button"
            class="btn-airline flex flex-1 items-center justify-center gap-2 py-3 font-black"
            @click="runPrintJob"
          >
            <Printer class="h-4 w-4" />
            فتح الطباعة / PDF
          </button>
        </div>
      </div>
    </div>

    <!-- تأكيد الحجز (معلق → مؤكد) -->
    <div
      v-if="showStatusModal && booking"
      class="no-print fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="status-modal-title"
    >
      <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900/95 p-6 shadow-2xl">
        <h3 id="status-modal-title" class="mb-2 text-xl font-black text-white">تغيير حالة الحجز</h3>
        <p v-if="booking.status === 'pending'" class="mb-6 text-sm leading-relaxed text-text-muted">
          تأكيد الحجز يثبّت الحالة ويُحدّث المحاسبة وفق إعدادات النظام. متابعة؟
        </p>
        <p v-else class="mb-6 text-sm text-warning">
          هذا الحجز ليس في حالة «معلق»، لذلك لا يمكن تأكيده من هذه الشاشة.
        </p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            class="btn-airline-ghost w-full px-4 py-2.5 sm:w-auto"
            :disabled="statusModalBusy"
            @click="closeStatusModal"
          >
            إغلاق
          </button>
          <button
            v-if="booking.status === 'pending'"
            type="button"
            class="btn-airline w-full px-4 py-2.5 sm:w-auto disabled:opacity-50"
            :disabled="statusModalBusy"
            @click="runConfirmBooking"
          >
            <span v-if="statusModalBusy" class="inline-flex items-center gap-2">
              <Loader2 class="h-4 w-4 animate-spin" />
              جاري التنفيذ...
            </span>
            <span v-else>تأكيد الحجز</span>
          </button>
        </div>
      </div>
    </div>

    <!-- نافذة معالجة الاسترجاع متعدد العملات -->
    <div
      v-if="showRefundModal && booking"
      class="no-print fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-md overflow-y-auto"
    >
      <div class="w-full max-w-4xl my-8 relative">
        <button
          @click="showRefundModal = false"
          class="absolute -top-12 right-0 text-white hover:text-gold text-lg font-bold bg-white/10 w-10 h-10 rounded-full flex items-center justify-center transition-colors z-50"
        >
          ✕
        </button>
        <RefundWizard
          :initial-booking="booking"
          @completed="onRefundCompleted"
        />
      </div>
    </div>

    <!-- نافذة معالجة تعديل التذاكر (تغيير الموعد أو الوجهة) -->
    <div
      v-if="showModificationModal && booking"
      class="no-print fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-md overflow-y-auto"
    >
      <div class="w-full max-w-4xl my-8 relative">
        <button
          @click="showModificationModal = false"
          class="absolute -top-12 right-0 text-white hover:text-cyan-400 text-lg font-bold bg-white/10 w-10 h-10 rounded-full flex items-center justify-center transition-colors z-50"
        >
          ✕
        </button>
        <ModificationWizard
          :initial-booking="booking"
          @completed="onModificationCompleted"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watchEffect } from 'vue';
import axios from 'axios';
import { useFlightStore } from '@/stores/flightStore';
import { useRoute, useRouter } from 'vue-router';
import RefundWizard from '@/components/flights/RefundWizard.vue';
import ModificationWizard from '@/components/flights/ModificationWizard.vue';
import {
  ArrowRight,
  Trash2,
  Loader2,
  AlertTriangle,
  Printer,
  Plane,
  Users,
  User,
  FileText,
  Phone,
  Mail,
  Settings,
  RefreshCw,
  X,
  DollarSign,
  Clock,
  Calendar,
  Briefcase,
  CheckCircle,
  Edit,
} from 'lucide-vue-next';

const props = defineProps(['id']);
const store = useFlightStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const showPrintModal = ref(false);
const showStatusModal = ref(false);
const showRefundModal = ref(false);
const showModificationModal = ref(false);
const statusModalBusy = ref(false);
const paymentMethods = ref([]);
const submittingExtraPayment = ref(false);
const sendingTicketEmail = ref(false);
/** @type {import('vue').Ref<null | { accountId: number|string, amount: number, currency: string }>} */
const settlementFlash = ref(null);
let settlementFlashTimer = null;

const extraPayment = ref({
  amount: '',
  payment_method: 'cash',
  account_id: null,
  notes: '',
});

/** مطابقة لـ FlightCreate: أنواع الحسابات المسموحة لكل طريقة تحصيل. */
const PAYMENT_METHOD_ACCOUNT_TYPES = {
  cash: ['cashbox', 'treasury'],
  office_drawer: ['cashbox', 'treasury'],
  office_safe: ['cashbox', 'treasury'],
  bank_transfer: ['bank'],
  vodafone_cash: ['wallet'],
  instapay: ['wallet'],
  cash_wallet: ['wallet'],
  postal_transfer: ['bank', 'treasury', 'cashbox'],
  mixed: ['cashbox', 'wallet', 'bank', 'treasury'],
};

const ACCOUNT_TYPE_LABELS = {
  cashbox: 'خزينة نقدي',
  wallet: 'محفظة إلكترونية',
  bank: 'حساب بنكي',
  treasury: 'خزينة عامة',
};

const settlementAccounts = computed(() => {
  const ov = store.treasuryOverview;
  const raw = ov?.settlement_accounts;
  return Array.isArray(raw) ? raw : [];
});

const allowedTypesForExtraPayment = computed(() => {
  const m = String(extraPayment.value.payment_method || 'cash');
  return PAYMENT_METHOD_ACCOUNT_TYPES[m] ?? PAYMENT_METHOD_ACCOUNT_TYPES.mixed;
});

const filteredSettlementForExtra = computed(() => {
  const allow = new Set(allowedTypesForExtraPayment.value);
  return settlementAccounts.value.filter((a) => {
    if (a.is_active === false) return false;
    return allow.has(String(a.type || ''));
  });
});

const selectedExtraSettlementAccount = computed(() => {
  const id = extraPayment.value.account_id;
  if (id == null || id === '') return null;
  return settlementAccounts.value.find((x) => String(x.id) === String(id)) ?? null;
});

const extraPaymentAmountNum = computed(() => {
  const n = parseFloat(String(extraPayment.value.amount || '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : 0;
});

// Print options
const printOptions = ref({
  logo: true,
  tripDetails: true,
  flightDates: true,
  passengerInfo: true,
  baggage: true,
  price: true,
  payments: true,
  notes: true,
});

const printOptionList = [
  { key: 'logo', label: 'الشعار', description: 'إظهار شعار الشركة', icon: Plane },
  { key: 'tripDetails', label: 'تفاصيل الرحلة', description: 'مسار الرحلة والتوقيتات', icon: Clock },
  { key: 'flightDates', label: 'تواريخ الرحلة', description: 'إظهار تاريخ المغادرة والوصول', icon: Calendar },
  { key: 'passengerInfo', label: 'معلومات المسافرين', description: 'أسماء وتفاصيل المسافرين', icon: Users },
  { key: 'baggage', label: 'الأمتعة', description: 'إظهار وزن الحقائب المسموح', icon: Briefcase },
  { key: 'price', label: 'تفاصيل السعر', description: 'سعر الشراء والبيع والربح', icon: DollarSign },
  { key: 'payments', label: 'ملخص الدفع', description: 'المدفوعات والمتبقي', icon: DollarSign },
  { key: 'notes', label: 'الملاحظات', description: 'أي ملاحظات إضافية', icon: FileText },
];

const booking = computed(() => store.currentBooking);

const getRemainingBalance = () => {
  const sellingPrice = booking.value?.pricing?.sellingPrice || 0;
  const fromBooking = parseFloat(booking.value?.totalPaid);
  const totalPaid = Number.isFinite(fromBooking)
    ? fromBooking
    : booking.value?.payments?.reduce((sum, p) => sum + (p.amount || 0), 0) || 0;
  return sellingPrice - totalPaid;
};

const showExtraPaymentForm = computed(() => getRemainingBalance() > 0.009);

const canSubmitExtraPayment = computed(() => {
  if (!extraPaymentAmountNum.value || extraPaymentAmountNum.value < 0.01) return false;
  if (!extraPayment.value.payment_method) return false;
  if (!filteredSettlementForExtra.value.length) return false;
  return extraPayment.value.account_id != null && extraPayment.value.account_id !== '';
});

watchEffect(() => {
  const list = filteredSettlementForExtra.value;
  if (!list.length) {
    extraPayment.value.account_id = null;
    return;
  }
  const id = extraPayment.value.account_id;
  if (id == null || !list.some((a) => String(a.id) === String(id))) {
    extraPayment.value.account_id = list[0].id;
  }
});

const ticketSegments = computed(() => {
  const segs = booking.value?.segments;
  const isRoundTrip = booking.value?.tripType === 'round_trip' || booking.value?.trip_type === 'round_trip';
  const returnDate = booking.value?.returnDate || booking.value?.return_date;

  if (Array.isArray(segs) && segs.length > 0) {
    return segs.map((s, idx) => ({
      ...s,
      from: s.from || s.from_airport || '—',
      to: s.to || s.to_airport || '—',
      airline: s.airline || s.airline_name || booking.value?.airlineName || '—',
      departureDate: s.departureDate || s.departure_date || booking.value?.departureDate || booking.value?.departure_date,
      departureTime: s.departureTime || s.departure_time,
      arrivalTime: s.arrivalTime || s.arrival_time,
      returnDate: (isRoundTrip && idx === segs.length - 1) ? returnDate : null,
      baggage: s.baggage || s.baggage_allowance || s.baggageAllowance || booking.value?.baggageAllowanceKg || booking.value?.baggage_allowance_kg || '',
    }));
  }

  // Fallback: use top-level booking data
  const seg = {
    airline: booking.value?.airlineName || '—',
    flightNumber: booking.value?.pnr || '—',
    from: booking.value?.from_airport || '—',
    to: booking.value?.to_airport || '—',
    departureDate: booking.value?.departureDate || booking.value?.departure_date,
    departureTime: booking.value?.departureTime || booking.value?.departure_time,
    arrivalTime: booking.value?.arrivalTime || booking.value?.arrival_time,
    returnDate: isRoundTrip ? returnDate : null,
    baggage: booking.value?.baggageAllowanceKg || booking.value?.baggage_allowance_kg || '',
    flightClass: 'economy',
  };
  return [seg];
});

const statusStyles = {
  pending: 'bg-warning/10 text-warning border border-warning/20',
  confirmed: 'bg-success/10 text-success border border-success/20 shadow-[0_0_15px_rgba(16,217,140,0.2)]',
  ticketed: 'bg-gold/10 text-gold border border-gold/20 shadow-[0_0_15px_rgba(212,168,67,0.2)]',
  cancelled: 'bg-error/10 text-error border border-error/20',
  refunded: 'bg-gray/10 text-gray border border-gray/20'
};

// Methods — all numbers use English (Western Arabic) digits for print clarity
const formatMoney = (amount, currencyCode = 'EGP') => {
  const n = Number(amount) || 0;
  const code = currencyCode || 'EGP';
  try {
    // Use en-US for Western digits, append Arabic currency label
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(n);
    const labels = { EGP: 'ج.م', USD: 'USD', SAR: 'ر.س', KWD: 'KWD', EUR: '€', GBP: '£' };
    return `${formatted} ${labels[code] || code}`;
  } catch {
    return `${n.toFixed(2)} ${currencyCode}`;
  }
};

const formatCurrency = (amount, currencyCode = 'EGP') => formatMoney(amount, currencyCode);

const paymentStatusBadgeClass = (status) => {
  const s = String(status || '').toLowerCase();
  if (s === 'paid') {
    return 'bg-success/15 text-success border border-success/30';
  }
  if (s === 'partial') {
    return 'bg-amber-500/15 text-amber-200 border border-amber-500/35';
  }
  return 'bg-white/[0.06] text-text-muted border border-white/15';
};

const accountTypeLabel = (type) => ACCOUNT_TYPE_LABELS[String(type)] || type || '—';

const formatDate = (date, withTime = false) => {
  if (!date || date === '—') return '—';
  try {
    const d = new Date(date);
    if (isNaN(d.getTime())) {
      const match = String(date).match(/\d{4}-\d{2}-\d{2}/);
      return match ? match[0] : String(date);
    }
    // English numerals, clear format
    if (withTime) return d.toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    const match = String(date).match(/\d{4}-\d{2}-\d{2}/);
    return match ? match[0] : String(date);
  }
};

const getStatusLabel = (status) => {
  const labels = {
    pending: 'معلق',
    confirmed: 'مؤكد',
    ticketed: 'مصدّر',
    cancelled: 'ملغي',
    refunded: 'مسترد',
  };
  return labels[status] || status;
};

const getPassengerTypeLabel = (type) => {
  const labels = {
    adult: 'بالغ',
    child: 'طفل',
    infant: 'رضيع',
  };
  return labels[type] || type;
};

const getPassengersByType = (type) => {
  return booking.value?.passengers?.filter(p => p.type === type) || [];
};

const generateTicketNumber = (type, indexInType) => {
  const bookingNum = booking.value?.bookingNumber || 'BK-000000';
  const prefix = String(type).charAt(0).toUpperCase();
  return `${bookingNum}-${prefix}${String(indexInType + 1).padStart(2, '0')}`;
};

const calculateDuration = (segment) => {
  // Simple estimation - in real app, calculate from departure/arrival times
  return '2.5';
};

const profitPercentage = computed(() => {
  if (!booking.value?.pricing) return '0';
  const { purchasePrice, profit } = booking.value.pricing;
  if (!purchasePrice || purchasePrice === 0) return '0';
  return ((profit / purchasePrice) * 100).toFixed(1);
});

const formatSegmentBaggage = (segment) => {
  const val = segment.baggage || segment.baggage_allowance || segment.baggageAllowance;
  if (val && val !== '0' && val !== 0) {
    return isNaN(val) ? val : `${val} KG`;
  }
  const kg = booking.value?.baggageAllowanceKg || booking.value?.baggage_allowance_kg;
  if (kg && kg !== '0' && kg !== 0) return `${kg} KG`;
  return 'بدون أمتعة';
};

const getTripTypeLabel = (type) => {
  const t = String(type || '').toLowerCase();
  const labels = {
    one_way: 'ذهاب فقط',
    round_trip: 'ذهاب وعودة',
    multi_city: 'وجهات متعددة',
  };
  return labels[t] || type || '—';
};

const getPaymentMethodLabel = (method) => {
  const m = String(method || '').toLowerCase();
  const labels = {
    cash: 'نقدي',
    bank_transfer: 'تحويل بنكي',
    credit_card: 'بطاقة ائتمان',
    wallet: 'محفظة إلكترونية',
    vodafone_cash: 'فودافون كاش',
    instapay: 'إنستاباي',
    office_drawer: 'درج المكتب',
    office_safe: 'خزينة المكتب',
    cash_wallet: 'محفظة كاش',
    postal_transfer: 'بريد',
    mixed: 'مختلط',
  };
  return labels[m] || method || '—';
};

const confirmDelete = async () => {
  if (confirm('هل أنت متأكد من حذف هذا الحجز؟ لا يمكن التراجع عن هذا الإجراء.')) {
    try {
      await store.deleteBooking(booking.value.id);
      store.addToast('تم حذف الحجز بنجاح');
      router.push({ name: 'flights.index' });
    } catch (error) {
      store.addToast('فشل حذف الحجز', 'error');
    }
  }
};

const closeStatusModal = () => {
  showStatusModal.value = false;
};

const openStatusMenu = () => {
  if (!booking.value) return;
  if (booking.value.status === 'pending') {
    showStatusModal.value = true;
    return;
  }
  store.addToast(
    'تأكيد الحجز من هنا متاح فقط للحالة «معلق». للحالات الأخرى استخدم لوحة الإدارة أو الإجراءات المعتمدة.',
    'warning',
  );
};

const runConfirmBooking = async () => {
  if (!booking.value?.id || statusModalBusy.value) return;
  statusModalBusy.value = true;
  try {
    await store.confirmBooking(booking.value.id);
    store.addToast('تم تأكيد الحجز بنجاح');
    closeStatusModal();
  } catch (e) {
    const msg =
      e.response?.data?.message ||
      e.response?.data?.errors?.status?.[0] ||
      e.message ||
      'تعذر تأكيد الحجز';
    store.addToast(String(msg), 'error');
  } finally {
    statusModalBusy.value = false;
  }
};

const sendEmail = async () => {
  const email = String(booking.value?.customer?.email || '').trim();
  if (!email) {
    store.addToast('لا يوجد بريد إلكتروني مسجّل لهذا العميل. أضف البريد في ملف العميل ثم أعد فتح الحجز.', 'error');
    return;
  }
  if (!booking.value?.id || sendingTicketEmail.value) return;
  sendingTicketEmail.value = true;
  try {
    await axios.post(`/api/v1/flight/bookings/${booking.value.id}/send-ticket-email`, {
      to_email: email,
    });
    store.addToast('تم جدولة إرسال التذكرة إلى البريد');
  } catch (e) {
    const msg = e.response?.data?.message || e.message || 'تعذر جدولة إرسال البريد';
    store.addToast(String(msg), 'error');
  } finally {
    sendingTicketEmail.value = false;
  }
};

const openPrintDialog = () => {
  showPrintModal.value = true;
};

const submitExtraPayment = async () => {
  if (!booking.value?.id || !canSubmitExtraPayment.value) return;
  submittingExtraPayment.value = true;
  const paidAmount = extraPaymentAmountNum.value;
  const accId = extraPayment.value.account_id;
  try {
    await store.addBookingPayment(booking.value.id, {
      amount: paidAmount,
      payment_method: extraPayment.value.payment_method,
      account_id: accId,
      notes: extraPayment.value.notes?.trim() || undefined,
    });
    if (settlementFlashTimer) {
      clearTimeout(settlementFlashTimer);
      settlementFlashTimer = null;
    }
    settlementFlash.value = {
      accountId: accId,
      amount: paidAmount,
      currency: selectedExtraSettlementAccount.value?.currency || 'EGP',
    };
    settlementFlashTimer = window.setTimeout(() => {
      settlementFlash.value = null;
      settlementFlashTimer = null;
    }, 4000);
    await store.fetchFlightTreasuryOverview();
    await store.fetchSystems();
    extraPayment.value.amount = '';
    extraPayment.value.notes = '';
    store.addToast('تم تسجيل الدفعة بنجاح');
  } catch (e) {
    const msg = e.response?.data?.message || e.response?.data?.errors?.amount?.[0] || e.message || 'تعذر تسجيل الدفعة';
    store.addToast(String(msg), 'error');
  } finally {
    submittingExtraPayment.value = false;
  }
};

const runPrintJob = async () => {
  showPrintModal.value = false;
  // Wait for Vue to remove toggled-off elements from the DOM
  await nextTick();
  await nextTick();
  // Extra delay to ensure browser has painted the updated DOM
  await new Promise(resolve => setTimeout(resolve, 350));

  const prevTitle = document.title;
  const refNum = booking.value?.bookingNumber || 'ticket';
  document.documentElement.classList.add('flight-print-active');
  document.title = `${refNum} — Safarak`;

  const cleanup = () => {
    document.documentElement.classList.remove('flight-print-active');
    document.title = prevTitle;
    window.removeEventListener('afterprint', cleanup);
  };
  window.addEventListener('afterprint', cleanup);
  window.setTimeout(() => {
    window.removeEventListener('afterprint', cleanup);
    document.documentElement.classList.remove('flight-print-active');
    document.title = prevTitle;
  }, 120000);

  window.print();
};

const onRefundCompleted = async (result) => {
  showRefundModal.value = false;
  store.addToast('تمت معالجة الاسترجاع وعزل الحسابات بنجاح.', 'success');
  if (booking.value?.id) {
    await store.fetchBookingById(booking.value.id);
  }
};

const onModificationCompleted = async (result) => {
  showModificationModal.value = false;
  store.addToast('تم اعتماد التعديل، تحديث اللقطة السعرية، وترحيل القيود المالية بنجاح.', 'success');
  if (booking.value?.id) {
    await store.fetchBookingById(booking.value.id);
  }
};

onMounted(async () => {
  loading.value = true;
  try {
    const bookingId = props.id || route.params.id;
    if (!bookingId) {
      store.addToast('رقم الحجز مفقود', 'error');
      await router.push({ name: 'flights.index' });
      return;
    }

    await Promise.all([
      store.fetchBookingById(bookingId),
      store.fetchFlightTreasuryOverview(),
      axios.get('/api/v1/settings/payment-methods').then((res) => {
        paymentMethods.value = res.data?.data || [];
        if (!extraPayment.value.payment_method && paymentMethods.value.length) {
          extraPayment.value.payment_method = paymentMethods.value[0].value;
        }
      }),
    ]);

    if (!store.currentBooking?.id) {
      store.addToast('الحجز غير موجود', 'error');
      await router.push({ name: 'flights.index' });
      return;
    }
  } catch (error) {
    console.error('Error loading booking:', error);
    store.addToast('فشل تحميل الحجز', 'error');
    await router.push({ name: 'flights.index' });
  } finally {
    loading.value = false;
    const p = route.query.print;
    if (p === '1' || p === 'true') {
      await nextTick();
      showPrintModal.value = true;
      const rest = { ...route.query };
      delete rest.print;
      router.replace({ query: rest });
    }
  }
});
</script>

<style scoped>
.ticket-print-document {
  print-color-adjust: exact;
  -webkit-print-color-adjust: exact;
}

</style>

<style>
/* Barcode strip + A4 print layout for flight ticket (#ticket-content) */
.ticket-barcode {
  min-height: 2.5rem;
  background: repeating-linear-gradient(
    90deg,
    #0f172a 0,
    #0f172a 3px,
    #f8fafc 3px,
    #f8fafc 5px
  );
}

@media print {
  @page {
    size: A4 portrait;
    margin: 10mm;
  }

  html.flight-print-active {
    background: #fff !important;
  }

  body * {
    visibility: hidden !important;
  }

  #ticket-content,
  #ticket-content * {
    visibility: visible !important;
  }

  #ticket-content {
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
    visibility: hidden !important;
  }

  .ticket-segment,
  .break-inside-avoid {
    break-inside: avoid;
    page-break-inside: avoid;
  }
}
</style>
