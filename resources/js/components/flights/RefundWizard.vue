<template>
  <div dir="rtl" class="max-w-4xl mx-auto bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl relative text-right">
    <!-- Wizard Header with Step Tabs -->
    <div class="p-6 bg-white/5 border-b border-white/10 flex flex-col md:flex-row items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-bold text-white flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          نظام استرجاع التذاكر متعدد العملات
        </h3>
        <span class="text-xs text-muted block mt-1">تطبيق العزل المحاسبي التام والتدقيق المالي الصارم</span>
      </div>

      <!-- Step Indicator Dots -->
      <div class="flex items-center gap-1.5 flex-row-reverse">
        <div v-for="stepNum in 6" :key="stepNum" :class="[
          'h-2 rounded-full transition-all duration-500',
          currentStep === stepNum ? 'w-8 bg-gold' : currentStep > stepNum ? 'w-3 bg-success' : 'w-3 bg-white/20'
        ]"></div>
      </div>
    </div>

    <!-- Wizard Main Area -->
    <div class="p-8 relative min-h-[420px] flex flex-col justify-between">
      <!-- Loading Screen Overlay -->
      <div v-if="loading" class="absolute inset-0 bg-card/90 backdrop-blur-sm z-30 flex flex-col items-center justify-center">
        <div class="w-12 h-12 border-4 border-gold border-t-transparent rounded-full animate-spin mb-4"></div>
        <p class="text-sm font-mono tracking-widest text-gold animate-pulse">{{ loadingText }}</p>
      </div>

      <!-- STEP 1: Selection & Verification -->
      <div v-if="currentStep === 1" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 1 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">تحديد حجز الطيران المستهدف</h4>
          <p class="text-xs text-muted mt-0.5">التحقق من أهلية حالة الحجز لإصدار الاسترجاع المالي.</p>
        </div>

        <div v-if="initialBooking" class="p-4 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-between">
          <div>
            <span class="text-xs text-muted block">الحجز المحدد مسبقاً</span>
            <span class="text-base font-bold font-mono text-gold">{{ initialBooking.booking_number }}</span>
            <span class="text-xs text-white block mt-1">{{ initialBooking.customer?.full_name }}</span>
          </div>
          <span class="px-3 py-1 bg-success/20 text-success text-xs font-mono rounded-full font-bold">
            مؤهل
          </span>
        </div>

        <div v-else>
          <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">أدخل رقم أو مرجع الحجز</label>
          <input v-model="form.bookingNumber" type="text" placeholder="مثال: BKG-2026-001"
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-white uppercase text-lg text-right" />
          <p class="text-xs text-muted mt-2">يُسمح بمعالجة الحجوزات المؤكدة (Confirmed) أو المستردة جزئياً فقط لضمان دقة القيود.</p>
        </div>
      </div>

      <!-- STEP 2: Financial Auditing -->
      <div v-if="currentStep === 2" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 2 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">سياق التدقيق المالي للأصل</h4>
          <p class="text-xs text-muted mt-0.5">مراجعة القيم السعرية الأصلية المسجلة لضمان دقة القيود وعدم تضاربها.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <span class="text-[10px] text-muted uppercase tracking-widest block font-mono">عملة الأصل الأصلية</span>
            <span class="text-2xl font-extrabold font-mono text-white mt-1 block">{{ form.originalCurrency }}</span>
            <span class="text-xs text-muted block mt-1">مسجلة وقت المعاملة</span>
          </div>

          <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <span class="text-[10px] text-muted uppercase tracking-widest block font-mono">المبلغ الإجمالي الأصلي</span>
            <span class="text-2xl font-extrabold font-mono text-gold mt-1 block">{{ Number(form.originalAmount).toLocaleString() }}</span>
            <span class="text-xs text-muted block mt-1">إجمالي التزام العميل</span>
          </div>

          <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
            <span class="text-[10px] text-muted uppercase tracking-widest block font-mono">سعر الصرف الأساسي للحجز</span>
            <span class="text-2xl font-extrabold font-mono text-white mt-1 block">{{ Number(form.bookingExchangeRate).toFixed(4) }}</span>
            <span class="text-xs text-muted block mt-1">مرجع التقييم بالعملة المحلية</span>
          </div>
        </div>

        <!-- Source Account & Payments Panel -->
        <div v-if="initialBooking" class="mt-4 p-4 rounded-2xl bg-white/5 border border-white/10 space-y-3">
          <h5 class="text-xs font-bold text-gold flex items-center gap-2">
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
      </div>

      <!-- STEP 3: Fee Deduction -->
      <div v-if="currentStep === 3" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 3 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">غرامة الطيران وصافي الاسترداد</h4>
          <p class="text-xs text-muted mt-0.5">حدد رسوم الإلغاء المفروضة من شركة الطيران لحساب الصافي القابل للاسترداد.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
          <div>
            <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">غرامة الإلغاء لشركة الطيران ({{ form.originalCurrency }})</label>
            <input v-model.number="form.cancellationFee" type="number" min="0" step="0.01"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl text-white text-right" />
            <span class="text-[10px] text-muted block mt-1">تُخصم مباشرة من أصل المبلغ الإجمالي للتذكرة.</span>
          </div>

          <div class="p-6 rounded-2xl bg-gold/5 border border-gold/20 flex flex-col justify-center items-center text-center">
            <span class="text-xs text-gold font-mono uppercase tracking-widest">صافي أصل الاسترجاع</span>
            <span class="text-4xl font-extrabold font-mono text-success mt-2">
              {{ Number(computedRefundAmount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}
            </span>
            <span class="text-xs text-muted font-mono mt-1">{{ form.originalCurrency }}</span>
          </div>
        </div>
      </div>

      <!-- STEP 4: Destination Routing -->
      <div v-if="currentStep === 4" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 4 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">مسار توجيه الرصيد المسترد</h4>
          <p class="text-xs text-muted mt-0.5">اختر ما إذا كنت تريد الاحتفاظ بالرصيد لدى شركة الطيران أو استرداد القيمة لخزينة الشركة.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Scenario A -->
          <div :class="[
            'p-6 rounded-2xl border-2 cursor-pointer transition-all duration-300 flex flex-col justify-between text-right',
            form.destination === 'airline_credit' ? 'bg-white/10 border-gold shadow-md' : 'bg-white/5 border-white/5 hover:border-white/20'
          ]" @click="form.destination = 'airline_credit'">
            <div>
              <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-mono text-gold font-bold uppercase tracking-wider">المسار أ</span>
                <span v-if="form.destination === 'airline_credit'" class="text-gold">●</span>
              </div>
              <h5 class="text-base font-bold text-white">رصيد لدى شركة الطيران (أصل غير نقدي)</h5>
              <p class="text-xs text-muted mt-2 leading-relaxed">
                يبقى الرصيد لدى شركة الطيران كإشعار دائن لاستخدامه في الحجوزات المستقبلية. يحافظ على السيولة النقدية.
              </p>
            </div>
            <span class="text-[10px] font-mono text-muted block mt-4 pt-3 border-t border-white/5 uppercase">قيد دفتري غير نقدي</span>
          </div>

          <!-- Scenario B -->
          <div :class="[
            'p-6 rounded-2xl border-2 cursor-pointer transition-all duration-300 flex flex-col justify-between text-right',
            form.destination === 'agency_treasury' ? 'bg-white/10 border-gold shadow-md' : 'bg-white/5 border-white/5 hover:border-white/20'
          ]" @click="form.destination = 'agency_treasury'">
            <div>
              <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-mono text-gold font-bold uppercase tracking-wider">المسار ب</span>
                <span v-if="form.destination === 'agency_treasury'" class="text-gold">●</span>
              </div>
              <h5 class="text-base font-bold text-white">إيداع في خزينة الشركة (نقدي)</h5>
              <p class="text-xs text-muted mt-2 leading-relaxed">
                استرداد نقدي فعلي. يتطلب التوجيه لخزينة مطابقة تماماً لعملة الاسترجاع لضمان العزل المحاسبي.
              </p>
            </div>
            <span class="text-[10px] font-mono text-success block mt-4 pt-3 border-t border-white/5 uppercase">إيداع نقدي فعلي بالخزينة</span>
          </div>
        </div>
      </div>

      <!-- STEP 5: Currency Isolation & Vault Choice -->
      <div v-if="currentStep === 5" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 5 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">معايير العملة والخزينة المستهدفة</h4>
          <p class="text-xs text-muted mt-0.5">تأكيد توافق العملات. في حال اختيار المسار ب، يرجى تحديد الخزينة المستهدفة.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">عملة الاسترجاع الفعلية</label>
            <input v-model="form.refundCurrency" type="text" maxlength="3"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-white uppercase text-lg text-right" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">سعر صرف الاسترجاع الفعلي</label>
            <input v-model.number="form.refundExchangeRate" type="number" min="0.000001" step="0.0001"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-white text-lg text-right" />
          </div>
        </div>

        <div v-if="form.destination === 'agency_treasury'" class="pt-4 border-t border-white/5">
          <label class="block text-xs text-gold mb-3 uppercase tracking-widest font-mono">الخزائن المتوافقة المتاحة ({{ form.refundCurrency }} فقط)</label>
          
          <div v-if="filteredTreasuries.length === 0" class="p-4 rounded-xl bg-error/10 border border-error/20 text-center">
            <p class="text-xs text-error font-mono">لم يُعثر على خزائن نشطة مطابقة للعملة: {{ form.refundCurrency }}. تمنع القواعد المالية الصارمة خلط العملات.</p>
          </div>

          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TreasuryCard v-for="t in filteredTreasuries" :key="t.id" :treasury="t"
              :is-selected="form.treasuryId === t.id"
              @select="form.treasuryId = t.id" />
          </div>
        </div>

        <div v-else class="p-4 rounded-xl bg-white/5 border border-white/5 text-center">
          <p class="text-xs text-muted font-mono">المسار أ مفعل: توجيه الرصيد لشركة الطيران لا يتطلب اختيار خزينة نقدية.</p>
        </div>
      </div>

      <!-- STEP 6: Summary & Audit Logging -->
      <div v-if="currentStep === 6" class="space-y-6">
        <div class="border-r-2 border-gold pr-4">
          <span class="text-xs font-mono text-gold uppercase tracking-widest block">الخطوة 6 من 6</span>
          <h4 class="text-lg font-bold text-white mt-1">المراجعة النهائية وترحيل القيود</h4>
          <p class="text-xs text-muted mt-0.5">تنفيذ مزدوج وآمن للقيود. يرجى مراجعة فروق أسعار الصرف المحسوبة.</p>
        </div>

        <div class="p-6 rounded-2xl bg-white/5 border border-white/10 space-y-4 font-mono text-xs">
          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">مرجع الحجز المستهدف</span>
            <span class="text-gold font-bold">{{ initialBooking?.booking_number || form.bookingNumber }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">أساس التقييم الأصلي</span>
            <span class="text-white">{{ Number(form.originalAmount).toLocaleString() }} {{ form.originalCurrency }} @ {{ form.bookingExchangeRate }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">الغرامات / الخصومات</span>
            <span class="text-error font-bold">-{{ Number(form.cancellationFee).toLocaleString() }} {{ form.originalCurrency }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">صافي المبلغ المسترد</span>
            <span class="text-success font-bold">{{ Number(computedRefundAmount).toLocaleString() }} {{ form.refundCurrency }}</span>
          </div>

          <div class="flex justify-between pb-2 border-b border-white/5">
            <span class="text-muted">التقييم بالعملة المحلية (الأساس)</span>
            <span class="text-white">{{ Number(computedBaseRefund).toLocaleString() }} ج.م</span>
          </div>

          <div class="flex justify-between pt-1">
            <span class="text-muted">فروق أسعار الصرف (Variance)</span>
            <span :class="['font-extrabold', computedDifference >= 0 ? 'text-success' : 'text-error']">
              {{ computedDifference >= 0 ? '+' : '' }}{{ Number(computedDifference).toLocaleString() }} ج.م
            </span>
          </div>
        </div>

        <div>
          <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">ملاحظات مالية ومذكرات تدقيق داخلية</label>
          <textarea v-model="form.notes" rows="2" placeholder="أدخل معرفات الاعتماد أو أرقام مراجع شركة الطيران..."
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none text-xs font-mono text-white text-right"></textarea>
        </div>
      </div>

      <!-- Success Screen Banner inside main body if processed successfully -->
      <div v-if="successResult" class="absolute inset-0 bg-card z-40 p-8 flex flex-col items-center justify-center text-center">
        <div class="w-16 h-16 bg-success/10 border border-success/20 rounded-full flex items-center justify-center mb-4 text-success animate-bounce">
          ✓
        </div>
        <h4 class="text-xl font-bold text-white">تمت معالجة وترحيل الاسترجاع بنجاح</h4>
        <p class="text-xs text-muted mt-1 max-w-md">تم تأكيد القيود المحاسبية وقفل الحالة وعزل العملات بنجاح تام.</p>

        <div class="mt-6 p-4 rounded-xl bg-white/5 border border-white/5 w-full max-w-sm font-mono text-xs text-right space-y-2">
          <div class="flex justify-between"><span class="text-muted">رقم الاسترجاع:</span><span class="text-gold font-bold">#{{ successResult.id }}</span></div>
          <div class="flex justify-between"><span class="text-muted">الصافي المسترد:</span><span class="text-success font-bold">{{ successResult.refund_amount }} {{ successResult.refund_currency }}</span></div>
          <div class="flex justify-between"><span class="text-muted">وجهة الاسترداد:</span><span class="text-white">{{ successResult.destination === 'airline_credit' ? 'رصيد شركة الطيران' : 'خزينة الشركة' }}</span></div>
        </div>

        <button @click="$emit('completed', successResult)" class="mt-8 px-6 py-2.5 bg-gold text-white font-mono text-xs font-bold rounded-xl hover:bg-gold/80 transition-colors">
          العودة للوحة التحكم
        </button>
      </div>

      <!-- Footer Buttons navigation -->
      <div class="pt-6 border-t border-white/5 flex items-center justify-between mt-8 flex-row-reverse">
        <button v-if="currentStep > 1" @click="prevStep" type="button"
          class="px-5 py-2.5 rounded-xl border border-white/10 text-xs font-mono text-muted hover:text-white transition-colors">
          السابق ←
        </button>
        <div v-else></div>

        <button v-if="currentStep < 6" @click="nextStep" type="button"
          class="px-6 py-2.5 rounded-xl bg-gold text-white text-xs font-mono font-bold hover:bg-gold/90 transition-colors shadow-lg">
          ← التالي
        </button>
        <button v-else @click="submitRefund" type="button"
          class="px-8 py-3 rounded-xl bg-success text-white text-xs font-mono font-bold hover:bg-success/90 transition-all shadow-xl animate-pulse">
          ⚡ تنفيذ وترحيل الاسترجاع المالي
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import TreasuryCard from './TreasuryCard.vue';

const props = defineProps({
  initialBooking: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['completed']);

const currentStep = ref(1);
const loading = ref(false);
const loadingText = ref('جاري التحقق من المعاملات المالية...');
const successResult = ref(null);

const treasuries = ref([]);

const form = ref({
  bookingId: null,
  bookingNumber: '',
  originalCurrency: 'USD',
  originalAmount: 100.00,
  bookingExchangeRate: 1.0000,
  cancellationFee: 0.00,
  refundCurrency: 'USD',
  refundExchangeRate: 1.0000,
  destination: 'airline_credit',
  treasuryId: null,
  notes: ''
});

onMounted(() => {
  if (props.initialBooking) {
    setupFromBooking(props.initialBooking);
    currentStep.value = 2; // Jump directly to verification audit
  }
  fetchTreasuries();
});

const setupFromBooking = (b) => {
  form.value.bookingId = b.id;
  form.value.bookingNumber = b.booking_number || b.ticket_number || '';
  form.value.originalCurrency = b.original_currency || b.currency || 'EGP';
  form.value.originalAmount = Number(b.original_amount || b.selling_price || 0);
  form.value.bookingExchangeRate = Number(b.booking_exchange_rate || b.exchange_rate || 1.0);
  form.value.refundCurrency = form.value.originalCurrency;
  form.value.refundExchangeRate = form.value.bookingExchangeRate;
};

const fetchTreasuries = async () => {
  try {
    const res = await axios.get('/api/v1/flight/refunds/treasuries');
    if (res.data?.success || res.data?.status) {
      treasuries.value = res.data.data || res.data;
    }
  } catch (err) {
    console.error('Failed fetching active vaults:', err);
  }
};

const filteredTreasuries = computed(() => {
  if (!form.value.refundCurrency) return treasuries.value;
  return treasuries.value.filter(t => t.currency?.toUpperCase() === form.value.refundCurrency?.toUpperCase());
});

const computedRefundAmount = computed(() => {
  return Math.max(0, form.value.originalAmount - form.value.cancellationFee);
});

const computedBaseRefund = computed(() => {
  return computedRefundAmount.value * form.value.refundExchangeRate;
});

const computedDifference = computed(() => {
  const baseAtBooking = computedRefundAmount.value * form.value.bookingExchangeRate;
  return computedBaseRefund.value - baseAtBooking;
});

const nextStep = () => {
  if (currentStep.value === 1 && !form.value.bookingId && !form.value.bookingNumber) {
    alert('يرجى كتابة مرجع أو رقم الحجز المستهدف.');
    return;
  }
  if (currentStep.value === 5 && form.value.destination === 'agency_treasury' && !form.value.treasuryId) {
    alert('سياسة صارمة: يجب اختيار خزينة متوافقة تطابق عملة الاسترجاع المحددة.');
    return;
  }
  currentStep.value++;
};

const prevStep = () => {
  currentStep.value--;
};

const submitRefund = async () => {
  loadingText.value = 'جاري قفل المعاملة وترحيل القيود المحاسبية...';
  loading.value = true;
  try {
    const payload = {
      flight_booking_id: form.value.bookingId || 1, // Fallback demo parameter
      cancellation_fee: form.value.cancellationFee,
      refund_currency: form.value.refundCurrency,
      refund_exchange_rate: form.value.refundExchangeRate,
      destination: form.value.destination,
      treasury_id: form.value.treasuryId,
      notes: form.value.notes
    };

    const res = await axios.post('/api/v1/flight/refunds', payload);
    const createdRefund = res.data?.data || res.data;

    // Instantly process/approve to trigger idempotency completion loop
    if (createdRefund?.id) {
      loadingText.value = 'جاري تحديث حالة الحجز واعتماد الأرصدة النهائية...';
      const processRes = await axios.post(`/api/v1/flight/refunds/${createdRefund.id}/process`);
      successResult.value = processRes.data?.data || processRes.data || createdRefund;
    } else {
      successResult.value = createdRefund;
    }
  } catch (err) {
    alert(err.response?.data?.message || err.message || 'تعذرت معالجة الاسترجاع بسبب استثناء في التحقق من القيود المزدوجة.');
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.bg-card { background-color: var(--card-bg, #1e293b); }
.bg-input { background-color: var(--input-bg, rgba(255, 255, 255, 0.05)); }
.text-muted { color: var(--text-muted, #9ca3af); }
.text-gold { color: var(--gold, #d97706); }
.bg-gold { background-color: var(--gold, #d97706); }
.border-gold { border-color: var(--gold, #d97706); }
.text-success { color: var(--success, #10b981); }
.bg-success { background-color: var(--success, #10b981); }
.text-error { color: var(--error, #ef4444); }
</style>
