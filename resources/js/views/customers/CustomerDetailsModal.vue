<template>
  <div v-if="isOpen" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm animate-in fade-in duration-300">
    <div class="bg-card w-full max-w-4xl border border-white/10 rounded-2xl h-[90vh] shadow-2xl animate-in zoom-in-95 duration-300 flex flex-col overflow-hidden" dir="rtl">
      <!-- Header -->
      <div class="p-6 border-b border-white/10 flex items-center justify-between bg-white/[0.02]">
        <h2 class="text-xl font-bold text-gold flex items-center gap-2">
          <UserCircle class="w-6 h-6" />
          ملف تفاصيل العميل المتكامل
        </h2>
        <button @click="close" class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-white transition-all">
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-6 space-y-6">
        <!-- Loading State -->
        <div v-if="loading" class="flex flex-col items-center justify-center py-20">
          <Loader2 class="w-10 h-10 text-gold animate-spin mb-4" />
          <p class="text-muted">جاري تحميل ملف العميل الشامل وتدقيق البيانات...</p>
        </div>

        <!-- Error State / No customer found -->
        <div v-else-if="!customerData && !loading" class="flex flex-col items-center justify-center py-20 text-center">
          <ShieldAlert class="w-12 h-12 text-error/60 mb-4 animate-bounce" />
          <h4 class="text-lg font-bold text-white mb-2">فشل تحميل الملف المالي للمستخدم</h4>
          <p class="text-muted text-xs max-w-sm mb-4">تعذر تحميل كشف حساب العميل من الخادم. يرجى التحقق من اتصالك بالشبكة أو المحاولة مرة أخرى.</p>
          <button @click="fetchCustomerDetails(customerId)" class="px-4 py-2 bg-gold/10 hover:bg-gold/20 border border-gold/30 text-gold rounded-lg text-xs font-bold transition-all cursor-pointer">
            إعادة محاولة التحميل
          </button>
        </div>

        <template v-else-if="customerData">
          <!-- Profile & Summary Header -->
          <div class="flex flex-col md:flex-row items-start md:items-center gap-6 p-6 bg-white/[0.02] rounded-2xl border border-white/5">
            <div class="w-20 h-20 rounded-2xl bg-gold/10 text-gold flex items-center justify-center font-black text-3xl flex-shrink-0 border border-gold/20 shadow-inner">
              {{ getInitials(customerData.full_name) }}
            </div>
            <div class="flex-1 space-y-2">
              <div class="flex items-center gap-3 flex-wrap">
                <h3 class="text-2xl font-black text-white">{{ customerData.full_name }}</h3>
                <span class="px-2.5 py-0.5 text-xs font-bold rounded-lg border border-gold/30 bg-gold/5 text-gold">
                  {{ customerData.type === 'counter' ? 'عميل كاونتر / شركة' : 'عميل فردي' }}
                </span>
                <span v-if="customerData.customer_tier" class="px-2.5 py-0.5 text-xs font-bold rounded-lg border border-indigo-500/30 bg-indigo-500/5 text-indigo-400">
                  فئة: {{ customerData.customer_tier }}
                </span>
              </div>
              <p class="text-sm text-muted flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="flex items-center gap-1.5"><Phone class="w-4 h-4 text-gold/80" /> {{ customerData.phone }}</span>
                <span v-if="customerData.whatsapp_number" class="flex items-center gap-1.5"><Phone class="w-4 h-4 text-green-400" /> واتساب: {{ customerData.whatsapp_number }}</span>
                <span v-if="customerData.email" class="flex items-center gap-1.5"><Mail class="w-4 h-4 text-gold/80" /> {{ customerData.email }}</span>
              </p>
            </div>
            <!-- Quick Financial Summary -->
            <div class="w-full md:w-auto p-4 rounded-xl border flex flex-col items-center justify-center text-center"
                 :class="customerData.balance > 0 ? 'bg-error/5 border-error/20 text-error' : (customerData.balance < 0 ? 'bg-green-500/5 border-green-500/20 text-green-400' : 'bg-white/5 border-white/10 text-muted')">
              <span class="text-xs text-muted block mb-1">الرصيد المالي الحالي</span>
              <span class="text-xl font-mono font-black">{{ formatMoney(Math.abs(customerData.balance)) }} EGP</span>
              <span class="text-[10px] font-bold mt-1">
                {{ customerData.balance > 0 ? 'مستحق عليه (مدين)' : (customerData.balance < 0 ? 'متبقي له (دائن)' : 'خالص الحساب') }}
              </span>
            </div>
          </div>

          <!-- Section: Personal Info Grid -->
          <div class="bg-white/[0.01] border border-white/5 rounded-2xl p-5 space-y-4">
            <h4 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/5 pb-2">
              <BadgeInfo class="w-4.5 h-4.5 text-gold" />
              المعلومات الشخصية والبيانات المسجلة
            </h4>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">الرقم القومي / الهوية</span>
                <span class="font-bold text-white">{{ customerData.national_id || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">رقم جواز السفر</span>
                <span class="font-bold text-white font-mono">{{ customerData.passport_number || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">انتهاء الجواز</span>
                <span class="font-bold text-white">{{ customerData.passport_expiry || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">تاريخ الميلاد</span>
                <span class="font-bold text-white">{{ customerData.date_of_birth || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">المدينة</span>
                <span class="font-bold text-white">{{ customerData.city || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">بلد السفر</span>
                <span class="font-bold text-white">{{ customerData.travel_country || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">الجهة التابع لها</span>
                <span class="font-bold text-white">{{ customerData.affiliation || '—' }}</span>
              </div>
              <div class="bg-input p-3 rounded-xl border border-white/5">
                <span class="text-muted block mb-1">سجل بواسطة</span>
                <span class="font-bold text-white">{{ customerData.created_by_name || 'النظام' }}</span>
              </div>
            </div>
            <div v-if="customerData.notes" class="bg-input p-3.5 rounded-xl border border-white/5 text-xs">
              <span class="text-muted block mb-1 font-bold">ملاحظات العميل العامة</span>
              <p class="text-white whitespace-pre-wrap leading-relaxed">{{ customerData.notes }}</p>
            </div>
          </div>

          <!-- Tab Bar -->
          <div class="flex border-b border-white/10 gap-6">
            <button @click="activeTab = 'bookings'"
                    class="pb-3 text-sm font-bold border-b-2 transition-all flex items-center gap-2 cursor-pointer"
                    :class="activeTab === 'bookings' ? 'text-gold border-gold' : 'text-muted border-transparent hover:text-white'">
              <Receipt class="w-4 h-4" />
              العمليات والحجوزات الفعالة ({{ allBookings.length }})
            </button>
            <button @click="activeTab = 'financial'"
                    class="pb-3 text-sm font-bold border-b-2 transition-all flex items-center gap-2 cursor-pointer"
                    :class="activeTab === 'financial' ? 'text-gold border-gold' : 'text-muted border-transparent hover:text-white'">
              <History class="w-4 h-4" />
              كشف الحساب التفصيلي والدفعات ({{ statementItems.length }})
            </button>
          </div>

          <!-- TAB CONTENT: BOOKINGS & MODULE ACTIVITIES -->
          <div v-if="activeTab === 'bookings'" class="space-y-4">

            <div v-if="allBookings.length === 0" class="text-center py-12 bg-white/[0.01] rounded-2xl border border-white/5">
              <Receipt class="w-12 h-12 text-muted mx-auto mb-3" />
              <p class="text-muted font-medium text-sm">لا توجد عمليات أو حجوزات نشطة مسجلة لهذا العميل.</p>
            </div>
            <div v-else class="space-y-4">
              <div v-for="booking in allBookings" :key="booking.unique_id">
                
                <!-- 1. FLIGHT BOOKING TEMPLATE -->
                <div v-if="booking.module === 'flight'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-blue-500/10 text-blue-400">
                        <Plane class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">حجز طيران - PNR: <span class="font-mono text-gold select-all">{{ booking.raw.pnr || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5 space-y-4">
                    <!-- Ticket Route layout -->
                    <div class="flex items-center justify-between border border-white/10 p-4 rounded-xl bg-black/25">
                      <div class="text-right">
                        <span class="text-[10px] text-muted block">مغادرة من</span>
                        <span class="text-lg font-black text-white font-mono">{{ booking.raw.from_airport || '—' }}</span>
                        <span class="text-[11px] text-muted/70 block mt-1" v-if="booking.raw.departure_date"><Calendar class="w-3 h-3 inline ml-1" />{{ booking.raw.departure_date }}</span>
                      </div>
                      <div class="flex flex-col items-center flex-1 mx-4">
                        <span class="text-xs text-muted mb-1 font-bold">{{ booking.raw.airline_name || 'طيران' }}</span>
                        <div class="relative w-full flex items-center justify-center">
                          <div class="w-full h-[1px] bg-white/15"></div>
                          <Plane class="w-4 h-4 text-gold absolute bg-card px-0.5" />
                        </div>
                        <span class="text-[10px] text-muted/70 mt-1" v-if="booking.raw.trip_type">{{ booking.raw.trip_type === 'round' ? 'ذهاب وعودة' : 'ذهاب فقط' }}</span>
                      </div>
                      <div class="text-left">
                        <span class="text-[10px] text-muted block">وصول إلى</span>
                        <span class="text-lg font-black text-white font-mono">{{ booking.raw.to_airport || '—' }}</span>
                        <span class="text-[11px] text-muted/70 block mt-1" v-if="booking.raw.return_date"><Calendar class="w-3 h-3 inline mr-1" />{{ booking.raw.return_date }}</span>
                      </div>
                    </div>
                    
                    <!-- Details Row -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">رقم الحجز</span>
                        <span class="font-bold text-white font-mono">{{ booking.raw.booking_number || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">عدد الركاب</span>
                        <span class="font-bold text-white">{{ booking.raw.passengers_count || 1 }} مسافر</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">سعر المبيعات</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">حالة السداد</span>
                        <span class="font-bold" :class="booking.raw.payment_status === 'paid' ? 'text-green-400' : (booking.raw.payment_status === 'partial' ? 'text-orange-400' : 'text-error')">
                          {{ booking.raw.payment_status_label }}
                        </span>
                      </div>
                    </div>
                    
                    <!-- Passengers List -->
                    <div v-if="booking.raw.passengers?.length" class="border-t border-white/5 pt-3">
                      <span class="text-xs text-muted font-bold block mb-2">أسماء الركاب المسجلين:</span>
                      <div class="flex flex-wrap gap-2">
                        <span v-for="p in booking.raw.passengers" :key="p.id" class="px-2.5 py-1 rounded bg-white/5 border border-white/10 text-xs text-white flex items-center gap-1.5">
                          <User class="w-3.5 h-3.5 text-muted" />
                          {{ p.name }}
                          <span class="text-[10px] text-gold/75" v-if="p.passenger_type_label">({{ p.passenger_type_label }})</span>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- 2. VISA BOOKING TEMPLATE -->
                <div v-else-if="booking.module === 'visa'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-purple-500/10 text-purple-400">
                        <FileText class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">تأشيرة سفر - الدولة: <span class="text-gold font-bold">{{ booking.raw.visa_detail?.country || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">نوع التأشيرة</span>
                        <span class="font-bold text-white">{{ booking.raw.visa_detail?.visa_type_label || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المدة المسجلة</span>
                        <span class="font-bold text-white">{{ booking.raw.visa_detail?.duration || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">فئة الدخول</span>
                        <span class="font-bold text-white">{{ booking.raw.visa_detail?.entry_type_label || '—' }}</span>
                      </div>
                      <div v-if="booking.raw.visa_detail?.visa_number">
                        <span class="text-muted block mb-0.5">رقم التأشيرة الصادرة</span>
                        <span class="font-bold font-mono text-white select-all">{{ booking.raw.visa_detail.visa_number }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">سعر التأشيرة (للعميل)</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.pricing?.selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">رسوم الخدمة</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.pricing?.service_fee) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المدفوع</span>
                        <span class="font-bold text-green-400 font-mono">{{ formatMoney(booking.raw.finance?.paid_amount) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المتبقي</span>
                        <span class="font-bold text-error font-mono">{{ formatMoney(booking.raw.finance?.remaining_amount) }} EGP</span>
                      </div>
                    </div>
                    
                    <div class="border-t border-white/5 pt-3 flex flex-wrap gap-x-6 gap-y-2 text-xs text-muted">
                      <span v-if="booking.raw.visa_detail?.submission_date">تاريخ التقديم: <strong class="text-white">{{ booking.raw.visa_detail.submission_date }}</strong></span>
                      <span v-if="booking.raw.visa_detail?.expected_result_date">النتيجة المتوقعة: <strong class="text-white">{{ booking.raw.visa_detail.expected_result_date }}</strong></span>
                      <span v-if="booking.raw.visa_detail?.executing_company">الجهة المنفذة: <strong class="text-white">{{ booking.raw.visa_detail.executing_company }}</strong></span>
                      <span v-if="booking.raw.visa_detail?.executing_agent">الوكيل المنفذ: <strong class="text-white">{{ booking.raw.visa_detail.executing_agent }}</strong></span>
                    </div>
                  </div>
                </div>

                <!-- 3. HAJJ & UMRAH BOOKING TEMPLATE -->
                <div v-else-if="booking.module === 'hajj_umra'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400">
                        <MapPin class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">حج وعمرة - البرنامج: <span class="text-gold font-bold">{{ booking.raw.program?.program_name || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5 space-y-4">
                    <!-- Hotel stays visual representation -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-black/25 p-4 rounded-xl border border-white/5">
                      <div class="flex items-center gap-3">
                        <span class="text-xs bg-gold/10 text-gold border border-gold/25 px-2.5 py-1 rounded-lg font-bold">مكة المكرمة</span>
                        <div>
                          <span class="text-xs font-bold text-white block">{{ booking.raw.program?.mecca_hotel_name || '—' }}</span>
                          <span class="text-[10px] text-muted">{{ booking.raw.program?.mecca_nights || 0 }} ليالي</span>
                        </div>
                      </div>
                      <div class="flex items-center gap-3 border-r border-white/5 pr-3">
                        <span class="text-xs bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 px-2.5 py-1 rounded-lg font-bold">المدينة المنورة</span>
                        <div>
                          <span class="text-xs font-bold text-white block">{{ booking.raw.program?.medina_hotel_name || '—' }}</span>
                          <span class="text-[10px] text-muted">{{ booking.raw.program?.medina_nights || 0 }} ليالي</span>
                        </div>
                      </div>
                    </div>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">نوع البرنامج</span>
                        <span class="font-bold text-white">{{ booking.raw.program?.program_type || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">الموسم</span>
                        <span class="font-bold text-white">{{ booking.raw.program?.season || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">خيارات الإقامة والتسكين</span>
                        <span class="font-bold text-white">{{ booking.raw.pricing?.accommodation_choice || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">حالة الحجز</span>
                        <span class="font-bold text-white">{{ booking.raw.status_label || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">السعر الأساسي</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.pricing?.selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">سعر المرافق</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.pricing?.companion_selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">إجمالي المبلغ الكلي</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.finance?.total_selling_price || booking.raw.total_selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المتبقي</span>
                        <span class="font-bold text-error font-mono">{{ formatMoney(booking.raw.finance?.remaining_amount) }} EGP</span>
                      </div>
                    </div>

                    <div class="border-t border-white/5 pt-3 flex flex-wrap gap-x-6 gap-y-2 text-xs text-muted">
                      <span v-if="booking.raw.companion"><User class="w-3.5 h-3.5 text-muted inline ml-1" />العميل المرافق: <strong class="text-white">{{ booking.raw.companion.full_name }}</strong></span>
                      <span v-if="booking.raw.program?.trip_supervisor"><User class="w-3.5 h-3.5 text-muted inline ml-1" />المشرف العام: <strong class="text-white">{{ booking.raw.program.trip_supervisor }}</strong></span>
                      <span v-if="booking.raw.program?.departure_date"><Calendar class="w-3.5 h-3.5 text-muted inline ml-1" />تاريخ السفر: <strong class="text-white">{{ booking.raw.program.departure_date }}</strong></span>
                    </div>
                  </div>
                </div>

                <!-- 4. BUS BOOKING TEMPLATE -->
                <div v-else-if="booking.module === 'bus'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-orange-500/10 text-orange-400">
                        <Bus class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">حجز أتوبيس - خط سير: <span class="text-gold font-bold">{{ booking.raw.inventory?.route || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">شركة النقل البري</span>
                        <span class="font-bold text-white">{{ booking.raw.company?.name || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">تاريخ وتوقيت الرحلة</span>
                        <span class="font-bold text-white">{{ booking.raw.inventory?.travel_date }} ({{ booking.raw.inventory?.departure_time || '—' }})</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">عدد التذاكر / المقاعد</span>
                        <span class="font-bold text-white">{{ booking.raw.quantity }} تذكرة</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">تكلفة التذكرة الفردية</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.unit_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">إجمالي القيمة المطلوبة</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.total_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المبلغ المدفوع</span>
                        <span class="font-bold text-green-400 font-mono">{{ formatMoney(booking.raw.paid_amount) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المتبقي المطلوب سداده</span>
                        <span class="font-bold text-error font-mono">{{ formatMoney(booking.raw.remaining_amount) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">حالة تحصيل الحساب</span>
                        <span class="font-bold" :class="booking.raw.payment_status === 'paid' ? 'text-green-400' : (booking.raw.payment_status === 'partial' ? 'text-orange-400' : 'text-error')">
                          {{ booking.raw.payment_status_label || booking.raw.payment_status }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- 5. FAWRY TRANSACTION TEMPLATE -->
                <div v-else-if="booking.module === 'fawry'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-yellow-500/10 text-yellow-400">
                        <CreditCard class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">مدفوعات فوري - مرجع المعاملة: <span class="font-mono text-gold select-all">{{ booking.raw.reference_number || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">نوع الخدمة / العملية</span>
                        <span class="font-bold text-white">{{ booking.raw.operation_type_label || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">وسيلة السداد</span>
                        <span class="font-bold text-white">{{ booking.raw.payment_method_label || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">القيمة الكلية المسددة</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.amount) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">المبلغ الأصلي قبل الإضافات</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.client_amount) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">تكلفة نظام فوري</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.fawry_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">تكلفة البيع المعتمدة</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.selling_price) }} EGP</span>
                      </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5 text-xs text-muted" v-if="booking.raw.notes">
                      ملاحظات التسوية: <span class="text-white">{{ booking.raw.notes }}</span>
                    </div>
                  </div>
                </div>

                <!-- 6. ONLINE SERVICE TEMPLATE -->
                <div v-else-if="booking.module === 'online'" class="border border-white/10 rounded-2xl bg-white/[0.02] overflow-hidden shadow-lg hover:border-gold/30 transition-all">
                  <div class="p-4 bg-white/[0.04] border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-indigo-500/10 text-indigo-400">
                        <Cpu class="w-4 h-4" />
                      </span>
                      <span class="text-sm font-bold text-white">خدمة إلكترونية - نوع الخدمة: <span class="text-gold font-bold">{{ booking.raw.service_type?.name || '—' }}</span></span>
                    </div>
                    <span class="text-xs text-muted">{{ formatDateString(booking.raw.created_at) }}</span>
                  </div>
                  <div class="p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                      <div>
                        <span class="text-muted block mb-0.5">الشركة المزودة</span>
                        <span class="font-bold text-white">{{ booking.raw.provider?.name || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">طريقة التحصيل / الدفع</span>
                        <span class="font-bold text-white">{{ booking.raw.payment_method_label || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">الرقم المرجعي للعملية</span>
                        <span class="font-bold font-mono text-white select-all">{{ booking.raw.reference_number || '—' }}</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">حالة المعاملة</span>
                        <span class="font-bold" :class="booking.raw.status === 'success' ? 'text-green-400' : (booking.raw.status === 'failed' ? 'text-error' : 'text-orange-400')">
                          {{ booking.raw.status_label || booking.raw.status }}
                        </span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">سعر الخدمة الإجمالي</span>
                        <span class="font-bold text-gold font-mono">{{ formatMoney(booking.raw.selling_price) }} EGP</span>
                      </div>
                      <div>
                        <span class="text-muted block mb-0.5">القيمة المدفوعة للآن</span>
                        <span class="font-bold text-white font-mono">{{ formatMoney(booking.raw.amount_paid) }} EGP</span>
                      </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5 text-xs text-muted flex flex-col gap-1" v-if="booking.raw.notes || booking.raw.failure_reason">
                      <div v-if="booking.raw.notes">تفاصيل المعاملة: <span class="text-white">{{ booking.raw.notes }}</span></div>
                      <div v-if="booking.raw.failure_reason" class="text-error font-bold">سبب فشل العملية: <span>{{ booking.raw.failure_reason }}</span></div>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <!-- TAB CONTENT: DETAILED LEDGER ACCOUNT STATEMENT -->
          <div v-if="activeTab === 'financial'" class="space-y-4">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <History class="w-4 h-4 text-gold" />
                تدقيق كشف حساب العميل والقيود المتربطة
              </h4>
            </div>

            <div v-if="statementItems.length === 0" class="text-center py-12 bg-white/[0.01] rounded-2xl border border-white/5">
              <History class="w-12 h-12 text-muted mx-auto mb-3" />
              <p class="text-muted font-medium text-sm">لا توجد أي قيود محاسبية أو حركات مالية مسجلة للعميل.</p>
            </div>
            
            <div v-else class="border border-white/10 rounded-xl overflow-hidden shadow-lg bg-black/20">
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr class="bg-white/[0.03] text-muted border-b border-white/10">
                      <th class="px-4 py-3.5 font-bold">التاريخ</th>
                      <th class="px-4 py-3.5 font-bold">القسم</th>
                      <th class="px-4 py-3.5 font-bold">نوع القيد</th>
                      <th class="px-4 py-3.5 font-bold">البيان والتفاصيل</th>
                      <th class="px-4 py-3.5 font-bold text-center">الموظف</th>
                      <th class="px-4 py-3.5 font-bold">مدين (+)</th>
                      <th class="px-4 py-3.5 font-bold">دائن (-)</th>
                      <th class="px-4 py-3.5 font-bold">الرصيد بعد القيد</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-white/[0.04] text-white/90">
                    <tr v-for="(item, idx) in statementItems" :key="idx" class="hover:bg-white/[0.02] transition-colors">
                      <td class="px-4 py-3 text-muted font-mono whitespace-nowrap">{{ formatDate(item.created_at) }}</td>
                      <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border border-white/10 bg-white/5">
                          {{ getModuleName(item.module) }}
                        </span>
                      </td>
                      <td class="px-4 py-3">
                        <span class="inline-block rounded px-2 py-0.5 text-[10px] font-bold border"
                              :class="item.debit > 0 ? 'bg-green-500/10 text-green-400 border-green-500/20' : 'bg-error/10 text-error border-error/20'">
                          {{ item.debit > 0 ? 'سند قبض / سداد' : 'مديونية / فاتورة مبيعات' }}
                        </span>
                      </td>
                      <td class="px-4 py-3 max-w-[240px] truncate" :title="item.description">{{ item.description }}</td>
                      <td class="px-4 py-3 text-center text-muted">{{ item.user_name || 'النظام' }}</td>
                      <td class="px-4 py-3 font-mono font-bold" :class="item.debit > 0 ? 'text-green-400' : 'text-white/40'">
                        {{ item.debit > 0 ? '+' + formatMoney(item.debit) : '—' }}
                      </td>
                      <td class="px-4 py-3 font-mono font-bold" :class="item.credit > 0 ? 'text-error' : 'text-white/40'">
                        {{ item.credit > 0 ? '-' + formatMoney(item.credit) : '—' }}
                      </td>
                      <td class="px-4 py-3 font-mono font-bold text-gold" dir="ltr">{{ formatMoney(item.balance_after) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </template>
      </div>

      <!-- Footer Buttons -->
      <div class="p-6 border-t border-white/10 flex justify-end bg-white/[0.02]">
        <button @click="close" class="px-6 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-bold text-white transition-colors text-xs cursor-pointer">
          إغلاق نافذة الملف
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { 
  X, UserCircle, Phone, Mail, BadgeInfo, History, Loader2, 
  ArrowDownLeft, ArrowUpRight, Receipt, Plane, FileText, Calendar, 
  User, MapPin, Bus, Cpu, CreditCard, ShieldAlert
} from 'lucide-vue-next';
import axios from 'axios';

const activeTab = ref('bookings');
const allBookings = ref([]);

const props = defineProps({
  isOpen: Boolean,
  customerId: Number,
});

const emit = defineEmits(['close']);

const loading = ref(false);
const customerData = ref(null);
const statementItems = ref([]);

const close = () => {
  emit('close');
};

const formatMoney = (val) => {
  if (val === undefined || val === null) return '0.00';
  const num = Number(val);
  return isNaN(num) ? '0.00' : num.toFixed(2);
};

const formatDate = (dateStr) => {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleString('ar-EG', { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  } catch (e) {
    return dateStr;
  }
};

const formatDateString = (dateStr) => {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
  } catch (e) {
    return dateStr;
  }
};

const fetchCustomerDetails = async (id) => {
  if (!id) return;
  loading.value = true;
  customerData.value = null;
  statementItems.value = [];
  allBookings.value = [];
  try {
    const { data } = await axios.get(`/api/v1/customers/${id}/statement`);
    customerData.value = data.data.customer;
    statementItems.value = data.data.items || [];
    
    // Process Bookings
    const b = data.data.bookings || {};
    let combined = [];
    
    if (b.flight?.length) {
      combined.push(...b.flight.map(x => ({
        unique_id: 'flight_' + x.id,
        module: 'flight',
        created_at: x.created_at,
        raw: x
      })));
    }
    if (b.visa?.length) {
      combined.push(...b.visa.map(x => ({
        unique_id: 'visa_' + x.id,
        module: 'visa',
        created_at: x.created_at,
        raw: x
      })));
    }
    if (b.hajj_umra?.length) {
      combined.push(...b.hajj_umra.map(x => ({
        unique_id: 'hajj_' + x.id,
        module: 'hajj_umra',
        created_at: x.created_at,
        raw: x
      })));
    }
    if (b.bus?.length) {
      combined.push(...b.bus.map(x => ({
        unique_id: 'bus_' + x.id,
        module: 'bus',
        created_at: x.created_at,
        raw: x
      })));
    }
    if (b.fawry?.length) {
      combined.push(...b.fawry.map(x => ({
        unique_id: 'fawry_' + x.id,
        module: 'fawry',
        created_at: x.created_at,
        raw: x
      })));
    }
    if (b.online?.length) {
      combined.push(...b.online.map(x => ({
        unique_id: 'online_' + x.id,
        module: 'online',
        created_at: x.created_at,
        raw: x
      })));
    }
    
    // Sort descending by created_at
    combined.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    allBookings.value = combined;
  } catch (error) {
    console.error('Failed to fetch customer details', error);
  } finally {
    loading.value = false;
  }
};

watch([() => props.isOpen, () => props.customerId], ([open, id]) => {
  if (open && id) {
    activeTab.value = 'bookings';
    fetchCustomerDetails(id);
  }
}, { immediate: true });

const getInitials = (name) => {
  if (!name) return '?';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

const getModuleName = (moduleKey) => {
  const map = {
    'flight': 'طيران',
    'bus': 'باصات',
    'hajj_umra': 'حج وعمرة',
    'visa': 'تأشيرات',
    'fawry': 'فوري',
    'online': 'أونلاين',
    'tourism': 'سياحة عامة'
  };
  return map[moduleKey] || moduleKey || 'عام';
};
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
</style>
