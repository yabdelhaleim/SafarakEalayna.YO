<template>
  <div dir="rtl" class="max-w-4xl mx-auto bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl relative text-right">
    <!-- Wizard Header -->
    <div class="p-6 bg-white/5 border-b border-white/10 flex flex-col md:flex-row items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-bold text-white flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
          </svg>
          نظام تعديل تذاكر الطيران وتحديث خط السير
        </h3>
        <span class="text-xs text-muted block mt-1">تطبيق قواعد العزل المحاسبي الصارمة، القفل الحصري، وتحديث اللقطة السعرية</span>
      </div>

      <!-- Step Indicators -->
      <div class="flex items-center gap-1.5 flex-row-reverse">
        <div v-for="stepNum in 6" :key="stepNum" :class="[
          'h-2 rounded-full transition-all duration-500',
          currentStep === stepNum ? 'w-8 bg-cyan-400' : currentStep > stepNum ? 'w-3 bg-success' : 'w-3 bg-white/20'
        ]"></div>
      </div>
    </div>

    <!-- Wizard Body -->
    <div class="p-8 relative min-h-[440px] flex flex-col justify-between">
      <!-- Loading Overlay -->
      <div v-if="loading" class="absolute inset-0 bg-card/90 backdrop-blur-sm z-30 flex flex-col items-center justify-center">
        <div class="w-12 h-12 border-4 border-cyan-400 border-t-transparent rounded-full animate-spin mb-4"></div>
        <p class="text-sm font-mono tracking-widest text-cyan-400 animate-pulse">{{ loadingText }}</p>
      </div>

      <!-- STEP 1: Select Type -->
      <div v-if="currentStep === 1" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 1 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">تحديد نطاق التعديل المطلوب</h4>
          <p class="text-xs text-muted mt-0.5">اختر نوع التغيير المطلوب على بيانات التذكرة التشغيلية.</p>
        </div>

        <div v-if="initialBooking" class="p-4 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-between">
          <div>
            <span class="text-xs text-muted block">الحجز المستهدف</span>
            <span class="text-base font-bold font-mono text-cyan-400">{{ initialBooking.booking_number || initialBooking.booking_reference }}</span>
            <span class="text-xs text-white block mt-1">{{ initialBooking.customer?.full_name }}</span>
          </div>
          <span class="px-3 py-1 bg-cyan-400/20 text-cyan-400 text-xs font-mono rounded-full font-bold">
            جاهز للتعديل
          </span>
        </div>

        <!-- Source Account & Payments Panel -->
        <div v-if="initialBooking" class="mt-3 p-4 rounded-2xl bg-white/5 border border-white/10 space-y-3">
          <h5 class="text-xs font-bold text-cyan-400 flex items-center gap-2">
            <span>ℹ️</span> تفاصيل الحسابات والدفعات المصدرية للحجز
          </h5>
          
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs font-mono">
            <div>
              <span class="text-muted block">الحساب المالي الرئيسي:</span>
              <span class="text-white font-bold">{{ initialBooking.account?.name || 'غير محدد' }}</span>
            </div>
            <div>
              <span class="text-muted block">مصدر رصيد الشراء:</span>
              <span class="text-white font-bold">
                {{ initialBooking.purchase_balance_source === 'system' ? ('رصيد النظام: ' + (initialBooking.flightSystem?.name || initialBooking.flight_system?.name || 'غير محدد')) : initialBooking.purchase_balance_source === 'carrier' ? ('رصيد الطيران: ' + (initialBooking.flightCarrier?.name || initialBooking.flight_carrier?.name || 'غير محدد')) : (initialBooking.purchase_balance_source || 'غير محدد') }}
              </span>
            </div>
          </div>

          <!-- Payments breakdown -->
          <div v-if="initialBooking.payments && initialBooking.payments.length > 0" class="pt-2 border-t border-white/5">
            <span class="text-[10px] text-muted block mb-2">الدفعات المحصلة وتوجيهها المالي:</span>
            <div class="space-y-1.5 max-h-28 overflow-y-auto pr-1">
              <div v-for="pay in initialBooking.payments" :key="pay.id" class="p-2 rounded-xl bg-card border border-white/5 flex items-center justify-between text-xs font-mono">
                <div>
                  <span class="text-success font-bold">{{ pay.amount }} {{ initialBooking.currency || 'SAR' }}</span>
                  <span class="text-[10px] text-muted mr-2">({{ pay.method_label }})</span>
                </div>
                <span class="text-white bg-white/5 px-2 py-0.5 rounded text-[11px]">{{ pay.account?.name || 'درج نقدي عام' }}</span>
              </div>
            </div>
          </div>
          <div v-else class="pt-2 border-t border-white/5 text-[10px] text-muted">
            لا توجد دفعات مسجلة مسبقاً لهذا الحجز.
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4">
          <div :class="[
            'p-5 rounded-2xl border-2 cursor-pointer transition-all duration-300 text-center',
            form.modificationType === 'date_change' ? 'bg-cyan-400/10 border-cyan-400 shadow-lg' : 'bg-white/5 border-white/5 hover:border-white/20'
          ]" @click="form.modificationType = 'date_change'">
            <span class="text-2xl block mb-2">📅</span>
            <h5 class="text-sm font-bold text-white">تغيير الموعد فقط</h5>
            <p class="text-xs text-muted mt-1">تحديث تاريخ الإقلاع أو العودة دون المساس بخط السير.</p>
          </div>

          <div :class="[
            'p-5 rounded-2xl border-2 cursor-pointer transition-all duration-300 text-center',
            form.modificationType === 'destination_change' ? 'bg-cyan-400/10 border-cyan-400 shadow-lg' : 'bg-white/5 border-white/5 hover:border-white/20'
          ]" @click="form.modificationType = 'destination_change'">
            <span class="text-2xl block mb-2">✈️</span>
            <h5 class="text-sm font-bold text-white">تغيير الوجهة فقط</h5>
            <p class="text-xs text-muted mt-1">تغيير مطارات الإقلاع أو الوصول لخط سير مختلف.</p>
          </div>

          <div :class="[
            'p-5 rounded-2xl border-2 cursor-pointer transition-all duration-300 text-center',
            form.modificationType === 'both' ? 'bg-cyan-400/10 border-cyan-400 shadow-lg' : 'bg-white/5 border-white/5 hover:border-white/20'
          ]" @click="form.modificationType = 'both'">
            <span class="text-2xl block mb-2">🔄</span>
            <h5 class="text-sm font-bold text-white">تغيير الموعد والوجهة</h5>
            <p class="text-xs text-muted mt-1">تعديل شامل يشمل التواريخ ومسار الرحلات معاً.</p>
          </div>
        </div>
      </div>

      <!-- STEP 2: Input New Values -->
      <div v-if="currentStep === 2" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 2 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">إدخال القيم التشغيلية الجديدة</h4>
          <p class="text-xs text-muted mt-0.5">سيتم استبدال القيم القديمة في التقارير فور الاعتماد مع بقائها في أرشيف التدقيق.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="involvesDate">
            <label class="block text-xs text-muted mb-2">تاريخ المغادرة الجديد</label>
            <input v-model="form.newDepartureDate" type="date"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-lg font-mono text-right" />
          </div>

          <div v-if="involvesDestination">
            <label class="block text-xs text-muted mb-2">الوجهة الجديدة (المطار / المدينة)</label>
            <input v-model="form.newDestination" type="text" placeholder="مثال: DXB - Dubai"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-lg text-right" />
          </div>

          <div class="md:col-span-2">
            <label class="block text-xs text-muted mb-2">رقم الرحلة الجديد (اختياري)</label>
            <input v-model="form.newFlightNumber" type="text" placeholder="مثال: MS 771"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-lg font-mono text-right" />
          </div>
        </div>
      </div>

      <!-- STEP 3: Financial Pricing -->
      <div v-if="currentStep === 3" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 3 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">التسعير المحاسبي وغرامات الطيران</h4>
          <p class="text-xs text-muted mt-0.5">تطبيق قاعدة الخصم الثابتة: الغرامة تخصم حصراً من حساب الطيران وتضاف عمولة الوكالة لتحديد إجمالي العميل.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
          <div>
            <label class="block text-xs text-warning mb-2">غرامة الطيران (تكلفة فعلية)</label>
            <input v-model.number="form.airlineChangeFee" type="number" min="0" step="1"
              class="w-full p-4 bg-input border border-warning/30 rounded-2xl focus:border-warning outline-none text-white text-xl font-mono text-right" />
            <span class="text-[10px] text-muted block mt-1">تخصم من رصيد الطيران المسبق</span>
          </div>

          <div>
            <label class="block text-xs text-success mb-2">عمولة الوكالة (الربح)</label>
            <input v-model.number="form.agencyCommission" type="number" min="0" step="1"
              class="w-full p-4 bg-input border border-success/30 rounded-2xl focus:border-success outline-none text-white text-xl font-mono text-right" />
            <span class="text-[10px] text-muted block mt-1">تسجل في حساب أرباح العمولات</span>
          </div>

          <div class="p-6 rounded-2xl bg-cyan-400/5 border border-cyan-400/20 text-center">
            <span class="text-xs text-cyan-400 block">الإجمالي المطلوب من العميل</span>
            <span class="text-3xl font-extrabold font-mono text-white mt-2 block">
              {{ computedTotalCharged.toLocaleString() }}
            </span>
            <span class="text-xs text-muted block mt-1 font-mono">{{ form.currency === 'EGP' ? 'ج.م' : form.currency }}</span>
          </div>
        </div>

        <div class="p-4 rounded-xl bg-warning/10 border border-warning/20 flex items-center gap-3">
          <span class="text-xl">⚠️</span>
          <p class="text-xs text-warning leading-relaxed">
            تنبيه: لا يتاح تغيير حساب الطيران المصدري. سيتم ترحيل قيد يومية تلقائي يعكس المبالغ المحصلة والالتزامات المستحقة لضمان توازن دفتر الأستاذ.
          </p>
        </div>
      </div>

      <!-- STEP 4: Currency & Collection Method -->
      <div v-if="currentStep === 4" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 4 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">طريقة التحصيل والعملة</h4>
          <p class="text-xs text-muted mt-0.5">حدد العملة الفعلية المحصل بها وتوجيه الخزينة.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-muted mb-2">عملة التعديل</label>
            <input v-model="form.currency" type="text" maxlength="3"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-lg font-mono uppercase text-right" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">طريقة الدفع / التحصيل</label>
            <select v-model="form.paymentMethod"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-lg text-right">
              <option value="cash" class="bg-card">نقدي (Cash)</option>
              <option value="bank_transfer" class="bg-card">تحويل بنكي (Bank Transfer)</option>
              <option value="wallet" class="bg-card">محفظة إلكترونية (Wallet)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- STEP 5: Reason & Context -->
      <div v-if="currentStep === 5" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 5 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">سبب التعديل والملاحظات الإدارية</h4>
          <p class="text-xs text-muted mt-0.5">توثيق مسببات التعديل لمتطلبات التدقيق والمراجعة.</p>
        </div>

        <div>
          <label class="block text-xs text-muted mb-2">سبب التغيير (بناءً على طلب العميل / ظرف طارئ)</label>
          <input v-model="form.reasonForChange" type="text" placeholder="مثال: طلب العميل تأجيل السفر لظروف صحية..."
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-base text-right" />
        </div>

        <div>
          <label class="block text-xs text-muted mb-2">ملاحظات إضافية</label>
          <textarea v-model="form.notes" rows="3" placeholder="أي مرجع أو موافقة خاصة من شركة الطيران..."
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-cyan-400 outline-none text-white text-xs text-right"></textarea>
        </div>
      </div>

      <!-- STEP 6: Review & Final Post -->
      <div v-if="currentStep === 6" class="space-y-6 animate-fade-in">
        <div class="border-r-2 border-cyan-400 pr-4">
          <span class="text-xs font-mono text-cyan-400 uppercase tracking-widest block">الخطوة 6 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">المراجعة النهائية والترحيل المحاسبي</h4>
          <p class="text-xs text-muted mt-0.5">الاعتماد سيقوم بتجميد اللقطة السعرية وإصدار قيود المحاسبة المزدوجة نهائياً.</p>
        </div>

        <div class="p-6 rounded-2xl bg-white/5 border border-white/10 space-y-3 text-xs font-mono">
          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">نوع التعديل:</span>
            <span class="text-cyan-400 font-bold">{{ typeLabel }}</span>
          </div>

          <div v-if="involvesDate" class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">الموعد الجديد:</span>
            <span class="text-white">{{ form.newDepartureDate || 'غير محدد' }}</span>
          </div>

          <div v-if="involvesDestination" class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">الوجهة الجديدة:</span>
            <span class="text-white">{{ form.newDestination || 'غير محدد' }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">غرامة الطيران (مخصومة):</span>
            <span class="text-warning font-bold">{{ form.airlineChangeFee }} {{ form.currency === 'EGP' ? 'ج.م' : form.currency }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">أرباح الوكالة الصافية:</span>
            <span class="text-success font-bold">{{ form.agencyCommission }} {{ form.currency === 'EGP' ? 'ج.م' : form.currency }}</span>
          </div>

          <div class="flex justify-between pt-1 font-bold text-sm">
            <span class="text-muted">إجمالي تحصيل العميل:</span>
            <span class="text-white">{{ computedTotalCharged }} {{ form.currency === 'EGP' ? 'ج.م' : form.currency }}</span>
          </div>
        </div>
      </div>

      <!-- Success Screen -->
      <div v-if="successResult" class="absolute inset-0 bg-card z-40 p-8 flex flex-col items-center justify-center text-center animate-fade-in">
        <div class="w-16 h-16 bg-success/10 border border-success/20 rounded-full flex items-center justify-center mb-4 text-success animate-scale">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h4 class="text-xl font-bold text-white">تم اعتماد التعديل وترحيل القيود بنجاح</h4>
        <p class="text-xs text-muted mt-1 max-w-md">تم تحديث بيانات التذكرة التشغيلية، خصم رصيد الطيران، وتسجيل القيود المحاسبية المعزولة.</p>

        <div class="mt-6 p-4 rounded-xl bg-white/5 border border-white/5 w-full max-w-sm text-xs text-right space-y-2 font-mono">
          <div class="flex justify-between"><span class="text-muted">معرف التعديل:</span><span class="text-cyan-400 font-bold">#{{ successResult.id }}</span></div>
          <div class="flex justify-between"><span class="text-muted">الحالة المحاسبية:</span><span class="text-success font-bold">مؤكد ومرحل (Confirmed)</span></div>
          <div class="flex justify-between"><span class="text-muted">إجمالي المحصل:</span><span class="text-white">{{ successResult.total_charged_to_customer }} {{ successResult.currency === 'EGP' ? 'ج.م' : successResult.currency }}</span></div>
        </div>

        <button @click="$emit('completed', successResult)" class="mt-8 px-6 py-2.5 bg-cyan-400 text-slate-900 font-mono text-xs font-bold rounded-xl hover:bg-cyan-300 transition-colors">
          العودة لعرض تفاصيل الحجز
        </button>
      </div>

      <!-- Navigation Buttons -->
      <div class="pt-6 border-t border-white/5 flex items-center justify-between mt-8 flex-row-reverse">
        <button v-if="currentStep > 1" @click="prevStep" type="button"
          class="px-5 py-2.5 rounded-xl border border-white/10 text-xs font-mono text-muted hover:text-white transition-colors">
          السابق ←
        </button>
        <div v-else></div>

        <button v-if="currentStep < 6" @click="nextStep" type="button"
          class="px-6 py-2.5 rounded-xl bg-cyan-400 text-slate-900 text-xs font-mono font-bold hover:bg-cyan-300 transition-colors shadow-lg">
          ← التالي
        </button>
        <button v-else @click="submitModification" type="button"
          class="px-8 py-3 rounded-xl bg-success text-white text-xs font-mono font-bold hover:bg-success/90 transition-all shadow-xl animate-pulse flex items-center gap-2">
          <span>⚡</span> تأكيد وترحيل التعديل نهائياً
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  initialBooking: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['completed']);

const currentStep = ref(1);
const loading = ref(false);
const loadingText = ref('جاري التحقق من المعاملات...');
const successResult = ref(null);

const form = ref({
  bookingId: null,
  modificationType: 'date_change',
  newDepartureDate: '',
  newDestination: '',
  newFlightNumber: '',
  airlineChangeFee: 0,
  agencyCommission: 0,
  currency: 'EGP',
  paymentMethod: 'cash',
  reasonForChange: '',
  notes: ''
});

onMounted(() => {
  if (props.initialBooking) {
    form.value.bookingId = props.initialBooking.id;
    form.value.currency = props.initialBooking.currency || 'EGP';
  }
});

const involvesDate = computed(() => ['date_change', 'both'].includes(form.value.modificationType));
const involvesDestination = computed(() => ['destination_change', 'both'].includes(form.value.modificationType));

const typeLabel = computed(() => {
  switch (form.value.modificationType) {
    case 'date_change': return 'تغيير الموعد فقط';
    case 'destination_change': return 'تغيير الوجهة فقط';
    case 'both': return 'تغيير الموعد والوجهة';
    default: return '';
  }
});

const computedTotalCharged = computed(() => {
  return (Number(form.value.airlineChangeFee) || 0) + (Number(form.value.agencyCommission) || 0);
});

const nextStep = () => {
  if (currentStep.value === 1 && !form.value.bookingId) {
    alert('لم يتم تحديد الحجز المستهدف للتعديل.');
    return;
  }
  if (currentStep.value === 2) {
    if (involvesDate.value && !form.value.newDepartureDate) {
      alert('يرجى إدخال تاريخ المغادرة الجديد.');
      return;
    }
    if (involvesDestination.value && !form.value.newDestination) {
      alert('يرجى إدخال الوجهة الجديدة.');
      return;
    }
  }
  currentStep.value++;
};

const prevStep = () => {
  currentStep.value--;
};

const submitModification = async () => {
  loadingText.value = 'جاري إنشاء مسودة طلب التعديل...';
  loading.value = true;
  try {
    // 1. Create Quote/Draft request
    const createRes = await axios.post('/api/v1/flight/modifications', {
      booking_id: form.value.bookingId,
      modification_type: form.value.modificationType,
      new_departure_date: form.value.newDepartureDate || null,
      new_destination: form.value.newDestination || null,
      new_flight_number: form.value.newFlightNumber || null,
      airline_change_fee: form.value.airlineChangeFee,
      agency_commission: form.value.agencyCommission,
      currency: form.value.currency,
      payment_method: form.value.paymentMethod,
      reason_for_change: form.value.reasonForChange,
      notes: form.value.notes
    });

    const mod = createRes.data?.data || createRes.data;

    if (mod?.id) {
      // 2. Advance state to Approved then trigger direct financial GL Confirmation
      loadingText.value = 'جاري تطبيق قواعد الخصم المالي المزدوج وتحديث التذكرة...';
      const confirmRes = await axios.post(`/api/v1/flight/modifications/${mod.id}/confirm`);
      successResult.value = confirmRes.data?.data || confirmRes.data || mod;
    } else {
      successResult.value = mod;
    }
  } catch (err) {
    alert(err.response?.data?.message || err.message || 'حدث خطأ أثناء معالجة الترحيل المالي لطلب التعديل.');
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.bg-card { background-color: var(--card-bg, #0f172a); }
.bg-input { background-color: rgba(255, 255, 255, 0.04); }
.text-muted { color: #94a3b8; }
.text-warning { color: #f59e0b; }
.text-success { color: #10b981; }
.border-warning { border-color: #f59e0b; }
.border-success { border-color: #10b981; }

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
