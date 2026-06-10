<template>
  <div dir="rtl" class="max-w-4xl mx-auto bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl relative text-right">
    <div class="p-6 bg-white/5 border-b border-white/10 flex flex-col md:flex-row items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-bold text-white flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          استرداد تذكرة طيران
        </h3>
        <span class="text-xs text-muted block mt-1">حساب المبلغ المرتجع للعميل بالجنيه المصري وتسجيل العملية محاسبياً</span>
      </div>

      <div class="flex items-center gap-1.5 flex-row-reverse">
        <div
          v-for="stepNum in totalSteps"
          :key="stepNum"
          :class="[
            'h-2 rounded-full transition-all duration-500',
            currentStep === stepNum ? 'w-8 bg-gold' : currentStep > stepNum ? 'w-3 bg-success' : 'w-3 bg-white/20',
          ]"
        ></div>
      </div>
    </div>

    <div class="p-8 relative min-h-[420px] flex flex-col justify-between">
      <div v-if="loading" class="absolute inset-0 bg-card/90 backdrop-blur-sm z-30 flex flex-col items-center justify-center">
        <div class="w-12 h-12 border-4 border-gold border-t-transparent rounded-full animate-spin mb-4"></div>
        <p class="text-sm font-mono tracking-widest text-gold animate-pulse">{{ loadingText }}</p>
      </div>

      <!-- الخطوة 1: معلومات الحجز -->
      <div v-if="currentStep === 1" class="space-y-6">
        <StepHeader :step="1" :total="totalSteps" title="معلومات الحجز" subtitle="مراجعة المبلغ المدفوع من العميل قبل احتساب الاسترداد." />

        <div v-if="!form.bookingId" class="space-y-3">
          <label class="block text-xs text-muted mb-2 uppercase tracking-widest font-mono">رقم الحجز</label>
          <input
            v-model="form.bookingNumber"
            type="text"
            placeholder="مثال: BK-2026-0001"
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-white uppercase text-lg text-right"
          />
        </div>

        <template v-if="form.bookingId">
          <div
            v-if="hasForeignPurchase"
            class="rounded-xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-xs leading-relaxed text-sky-100"
          >
            تكلفة الشراء على الحجز بعملة <span class="font-bold">{{ purchaseCurrency }}</span> —
            مبالغ العميل (الدفع والاسترداد والغرامات) تُسجَّل وتُعرض هنا <span class="font-bold text-sky-50">بالجنيه المصري (ج.م) فقط</span>.
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <InfoCard label="رقم الحجز" :value="displayBookingNumber" highlight />
            <InfoCard label="العميل" :value="customerName" />
            <InfoCard label="المبلغ المدفوع (ج.م)" :value="formatEgp(totalPaid)" highlight />
          </div>
        </template>

        <div v-if="payments.length" class="p-4 rounded-2xl bg-white/5 border border-white/10 space-y-2">
          <h5 class="text-xs font-bold text-gold">الدفعات المحصلة (ج.م)</h5>
          <div
            v-for="pay in payments"
            :key="pay.id"
            class="flex items-center justify-between text-xs font-mono p-2 rounded-xl bg-card border border-white/5"
          >
            <span class="text-success font-bold">{{ formatEgp(pay.amount) }}</span>
            <span class="text-muted">{{ pay.methodLabel || pay.paymentMethod || 'دفعة' }}</span>
          </div>
        </div>
        <p v-else-if="form.bookingId" class="text-xs text-warning bg-warning/10 border border-warning/20 rounded-xl p-3">
          لا توجد دفعات مسجلة — سيُستخدم سعر البيع كأساس للحساب إن وُجد.
        </p>
      </div>

      <!-- الخطوة 2: الخصومات -->
      <div v-if="currentStep === 2" class="space-y-6">
        <StepHeader
          :step="2"
          :total="totalSteps"
          title="خصم الطيران وعمولة الإلغاء"
          subtitle="أدخل الغرامات بالجنيه المصري — يُحسب منها المبلغ المرتجع للعميل."
        />

        <div class="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-3 text-xs leading-relaxed text-amber-100">
          <span class="font-bold text-amber-50">تنبيه:</span>
          خصم الطيران وعمولة الإلغاء والمبلغ المرتجع يُدخلون ويُحسبون
          <span class="font-bold">بالجنيه المصري (ج.م)</span>
          — حتى لو كانت تكلفة الشراء على التذكرة بالدينار أو غيره.
          إرجاع رصيد الساين يتم تلقائياً بعملة الخط وفق سعر صرف الحجز.
        </div>

        <div class="p-4 rounded-2xl bg-white/5 border border-white/10 flex justify-between items-center text-sm">
          <span class="text-muted">المبلغ الأصلي (ما دفعه العميل)</span>
          <span class="font-mono font-bold text-gold">{{ formatEgp(totalPaid) }}</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs text-muted mb-2 font-mono">خصم الطيران — ج.م</label>
            <div class="relative">
              <input
                v-model.number="form.airlinePenalty"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                class="w-full p-4 pl-14 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl text-white text-right"
              />
              <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-muted">ج.م</span>
            </div>
            <p class="mt-1.5 text-[10px] text-muted">غرامة شركة الطيران محوّلة للجنيه (ليس بالدينار/الدولار)</p>
          </div>
          <div>
            <label class="block text-xs text-muted mb-2 font-mono">عمولة الإلغاء — ج.م</label>
            <div class="relative">
              <input
                v-model.number="form.officePenalty"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                class="w-full p-4 pl-14 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl text-white text-right"
              />
              <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-muted">ج.م</span>
            </div>
          </div>
        </div>

        <div class="p-6 rounded-2xl bg-gold/5 border border-gold/20 space-y-3 font-mono text-sm">
          <div class="flex justify-between text-muted">
            <span>المبلغ الأصلي (ج.م)</span>
            <span>{{ formatEgp(totalPaid) }}</span>
          </div>
          <div class="flex justify-between text-error">
            <span>− خصم الطيران (ج.م)</span>
            <span>{{ formatEgp(form.airlinePenalty) }}</span>
          </div>
          <div class="flex justify-between text-error">
            <span>− عمولة الإلغاء (ج.م)</span>
            <span>{{ formatEgp(form.officePenalty) }}</span>
          </div>
          <div class="border-t border-gold/20 pt-3 flex justify-between items-center">
            <span class="text-gold font-bold">المبلغ المرتجع للعميل (ج.م)</span>
            <span class="text-3xl font-extrabold text-success">{{ formatEgp(customerRefundAmount) }}</span>
          </div>
        </div>

        <p v-if="penaltiesExceedPaid" class="text-xs text-error bg-error/10 border border-error/20 rounded-xl p-3">
          مجموع الخصومات يتجاوز المبلغ المدفوع — راجع القيم المدخلة.
        </p>
      </div>

      <!-- الخطوة 3: حساب الاسترداد -->
      <div v-if="currentStep === 3" class="space-y-6">
        <StepHeader
          :step="3"
          :total="totalSteps"
          title="حساب صرف الاسترداد"
          subtitle="اختر الحساب (بنك / خزينة) الذي يُصرف منه المبلغ المرتجع للعميل بالجنيه المصري."
        />

        <div v-if="customerRefundAmount > 0">
          <label class="block text-xs text-gold mb-3 uppercase tracking-widest font-mono">حساب الصرف *</label>

          <div v-if="settlementAccounts.length === 0" class="p-4 rounded-xl bg-error/10 border border-error/20 text-center">
            <p class="text-xs text-error font-mono">لا توجد حسابات تسوية متاحة. تأكد من إعداد الخزائن/المحافظ.</p>
          </div>

          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-56 overflow-y-auto pr-1">
            <button
              v-for="acc in settlementAccounts"
              :key="acc.id"
              type="button"
              :class="[
                'p-4 rounded-xl border-2 text-right transition-all',
                form.accountId === acc.id ? 'bg-gold/10 border-gold' : 'bg-white/5 border-white/10 hover:border-white/20',
              ]"
              @click="form.accountId = acc.id"
            >
              <div class="font-bold text-white text-sm">{{ acc.name }}</div>
              <div class="text-[11px] text-muted mt-1">{{ accountTypeLabel(acc.type) }} — {{ formatMoney(acc.balance, acc.currency) }}</div>
            </button>
          </div>
        </div>

        <div v-else class="p-4 rounded-xl bg-white/5 border border-white/10 text-center text-sm text-muted">
          لا يوجد مبلغ مرتجع للعميل — يمكن إتمام الإلغاء دون صرف نقدي.
        </div>

        <div>
          <label class="block text-xs text-muted mb-2 font-mono">ملاحظات (اختياري)</label>
          <textarea
            v-model="form.notes"
            rows="2"
            placeholder="سبب الإلغاء، مرجع شركة الطيران..."
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none text-xs text-white text-right"
          ></textarea>
        </div>
      </div>

      <!-- الخطوة 4: المراجعة -->
      <div v-if="currentStep === 4" class="space-y-6">
        <StepHeader :step="4" :total="totalSteps" title="مراجعة وتنفيذ الاسترداد" subtitle="تأكد من صحة الحسابات قبل التسجيل النهائي." />

        <div class="p-6 rounded-2xl bg-white/5 border border-white/10 space-y-3 font-mono text-sm">
          <SummaryRow label="رقم الحجز" :value="displayBookingNumber" />
          <SummaryRow label="العميل" :value="customerName" />
          <SummaryRow label="المبلغ الأصلي (ج.م)" :value="formatEgp(totalPaid)" />
          <SummaryRow label="خصم الطيران (ج.م)" :value="'− ' + formatEgp(form.airlinePenalty)" value-class="text-error" />
          <SummaryRow label="عمولة الإلغاء (ج.م)" :value="'− ' + formatEgp(form.officePenalty)" value-class="text-error" />
          <SummaryRow label="المبلغ المرتجع للعميل (ج.م)" :value="formatEgp(customerRefundAmount)" value-class="text-success font-bold text-lg" />
          <SummaryRow
            v-if="customerRefundAmount > 0"
            label="حساب الصرف"
            :value="selectedAccount?.name || '—'"
          />
        </div>
      </div>

      <!-- شاشة النجاح -->
      <div v-if="successResult" class="absolute inset-0 bg-card z-40 p-8 flex flex-col items-center justify-center text-center">
        <div class="w-16 h-16 bg-success/10 border border-success/20 rounded-full flex items-center justify-center mb-4 text-success text-2xl">
          ✓
        </div>
        <h4 class="text-xl font-bold text-white">تم تسجيل الاسترداد بنجاح</h4>
        <p class="text-xs text-muted mt-1 max-w-md">تم تحديث حالة الحجز وترحيل القيود المحاسبية.</p>

        <div class="mt-6 p-4 rounded-xl bg-white/5 border border-white/5 w-full max-w-sm font-mono text-xs text-right space-y-2">
          <div class="flex justify-between"><span class="text-muted">رقم الاسترداد:</span><span class="text-gold font-bold">#{{ successResult.id }}</span></div>
          <div class="flex justify-between"><span class="text-muted">المبلغ المرتجع (ج.م):</span><span class="text-success font-bold">{{ formatEgp(successResult.refund_amount ?? successResult.refundAmount) }}</span></div>
          <div class="flex justify-between"><span class="text-muted">خصم الطيران (ج.م):</span><span class="text-white">{{ formatEgp(successResult.airline_penalty ?? successResult.airlinePenalty) }}</span></div>
          <div class="flex justify-between"><span class="text-muted">عمولة الإلغاء (ج.م):</span><span class="text-white">{{ formatEgp(successResult.office_penalty ?? successResult.officePenalty) }}</span></div>
        </div>

        <button type="button" class="mt-8 px-6 py-2.5 bg-gold text-black font-bold text-xs rounded-xl hover:bg-gold/80 transition-colors" @click="$emit('completed', successResult)">
          إغلاق
        </button>
      </div>

      <div v-if="!successResult" class="pt-6 border-t border-white/5 flex items-center justify-between mt-8 flex-row-reverse">
        <button
          v-if="currentStep > 1"
          type="button"
          class="px-5 py-2.5 rounded-xl border border-white/10 text-xs font-mono text-muted hover:text-white transition-colors"
          @click="prevStep"
        >
          السابق ←
        </button>
        <div v-else></div>

        <button
          v-if="currentStep < totalSteps"
          type="button"
          :disabled="!canGoNext"
          class="px-6 py-2.5 rounded-xl bg-gold text-black text-xs font-mono font-bold hover:bg-gold/90 transition-colors shadow-lg disabled:opacity-40 disabled:cursor-not-allowed"
          @click="nextStep"
        >
          ← التالي
        </button>
        <button
          v-else
          type="button"
          :disabled="loading || !canSubmit"
          class="px-8 py-3 rounded-xl bg-success text-white text-xs font-mono font-bold hover:bg-success/90 transition-all shadow-xl disabled:opacity-40"
          @click="submitRefund"
        >
          تأكيد الاسترداد
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue';
import axios from 'axios';
import { useFlightStore } from '@/stores/flightStore';
import { fetchSettlementAccounts as fetchModuleSettlementAccounts } from '@/composables/useTreasuryAccountGroups';

const props = defineProps({
  initialBooking: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['completed']);

const store = useFlightStore();
const totalSteps = 4;
const currentStep = ref(1);
const loading = ref(false);
const loadingText = ref('جاري معالجة الاسترداد...');
const successResult = ref(null);
const settlementAccounts = ref([]);

const form = ref({
  bookingId: null,
  bookingNumber: '',
  airlinePenalty: 0,
  officePenalty: 0,
  accountId: null,
  notes: '',
});

/** عملة تكلفة الشراء على الحجز (قد تكون KWD/USD) — للعرض التوضيحي فقط */
const purchaseCurrency = computed(() => {
  const b = props.initialBooking;
  return String(b?.currency ?? b?.pricing?.currency ?? 'EGP').toUpperCase();
});

const hasForeignPurchase = computed(() => purchaseCurrency.value !== 'EGP');

const payments = computed(() => {
  const b = props.initialBooking;
  return Array.isArray(b?.payments) ? b.payments : [];
});

const totalPaid = computed(() => {
  const b = props.initialBooking;
  if (!b) return 0;
  const fromPayments = payments.value.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
  if (fromPayments > 0) return fromPayments;
  return Number(b.totalPaid ?? b.total_paid ?? b.pricing?.sellingPrice ?? b.selling_price ?? 0) || 0;
});

const customerRefundAmount = computed(() =>
  Math.max(0, roundMoney(totalPaid.value - (Number(form.value.airlinePenalty) || 0) - (Number(form.value.officePenalty) || 0))),
);

const penaltiesExceedPaid = computed(
  () => (Number(form.value.airlinePenalty) || 0) + (Number(form.value.officePenalty) || 0) > totalPaid.value + 0.001,
);

const displayBookingNumber = computed(
  () => props.initialBooking?.bookingNumber ?? props.initialBooking?.booking_number ?? form.value.bookingNumber ?? '—',
);

const customerName = computed(
  () => props.initialBooking?.customer?.name ?? props.initialBooking?.customer?.full_name ?? '—',
);

const selectedAccount = computed(() =>
  settlementAccounts.value.find((a) => Number(a.id) === Number(form.value.accountId)) ?? null,
);

const canGoNext = computed(() => {
  if (currentStep.value === 1) {
    return Boolean(form.value.bookingId || form.value.bookingNumber?.trim());
  }
  if (currentStep.value === 2) {
    return !penaltiesExceedPaid.value;
  }
  if (currentStep.value === 3) {
    if (customerRefundAmount.value <= 0) return true;
    return Boolean(form.value.accountId);
  }
  return true;
});

const canSubmit = computed(() => {
  if (!form.value.bookingId) return false;
  if (penaltiesExceedPaid.value) return false;
  if (customerRefundAmount.value > 0 && !form.value.accountId) return false;
  return true;
});

function roundMoney(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

/** مبالغ العميل والاسترداد والغرامات — دائماً بالجنيه */
function formatEgp(amount) {
  return formatMoney(amount, 'EGP');
}

function formatMoney(amount, curr = 'EGP') {
  const n = Number(amount) || 0;
  const code = curr || 'EGP';
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: code }).format(n);
  } catch {
    return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2 })} ${code}`;
  }
}

function accountTypeLabel(type) {
  const map = { cashbox: 'خزينة', wallet: 'محفظة', bank: 'بنك', treasury: 'خزينة عامة' };
  return map[type] || type || '';
}

function setupFromBooking(booking) {
  if (!booking?.id) return;
  form.value.bookingId = booking.id;
  form.value.bookingNumber = booking.bookingNumber ?? booking.booking_number ?? '';
  form.value.airlinePenalty = 0;
  form.value.officePenalty = 0;
  form.value.accountId = booking.account?.id ?? booking.account_id ?? booking.payments?.[0]?.account?.id ?? null;
  form.value.notes = '';
}

async function loadSettlementAccounts() {
  try {
    settlementAccounts.value = await fetchModuleSettlementAccounts(axios, { module: 'flight' });
    if (!form.value.accountId && settlementAccounts.value.length === 1) {
      form.value.accountId = settlementAccounts.value[0].id;
    }
  } catch (err) {
    console.error('Failed to load settlement accounts:', err);
    settlementAccounts.value = [];
  }
}

function nextStep() {
  if (!canGoNext.value) return;
  if (currentStep.value < totalSteps) currentStep.value++;
}

function prevStep() {
  if (currentStep.value > 1) currentStep.value--;
}

async function submitRefund() {
  if (!canSubmit.value || loading.value) return;

  loading.value = true;
  loadingText.value = 'جاري تسجيل الاسترداد وترحيل القيود...';

  try {
    const payload = {
      airline_penalty: roundMoney(form.value.airlinePenalty),
      office_penalty: roundMoney(form.value.officePenalty),
      notes: form.value.notes?.trim() || null,
    };
    if (customerRefundAmount.value > 0) {
      payload.account_id = Number(form.value.accountId);
    }

    const result = await store.cancelBooking(form.value.bookingId, payload);
    const refund = result?.refund;
    successResult.value = refund
      ? {
          id: refund.id,
          refund_amount: refund.refund_amount ?? refund.refundAmount ?? customerRefundAmount.value,
          airline_penalty: refund.airline_penalty ?? refund.airlinePenalty ?? payload.airline_penalty,
          office_penalty: refund.office_penalty ?? refund.officePenalty ?? payload.office_penalty,
        }
      : {
          refund_amount: customerRefundAmount.value,
          airline_penalty: payload.airline_penalty,
          office_penalty: payload.office_penalty,
        };
  } catch (err) {
    const msg = err.response?.data?.message || store.errors?.message || err.message || 'تعذر تنفيذ الاسترداد';
    store.addToast(msg, 'error');
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  if (props.initialBooking) {
    setupFromBooking(props.initialBooking);
    currentStep.value = 1;
  }
  loadSettlementAccounts();
});

const StepHeader = (p) =>
  h('div', { class: 'border-r-2 border-gold pr-4' }, [
    h('span', { class: 'text-xs font-mono text-gold uppercase tracking-widest block' }, `الخطوة ${p.step} من ${p.total}`),
    h('h4', { class: 'text-lg font-bold text-white mt-1' }, p.title),
    h('p', { class: 'text-xs text-muted mt-0.5' }, p.subtitle),
  ]);
StepHeader.props = ['step', 'total', 'title', 'subtitle'];

const InfoCard = (p) =>
  h('div', { class: 'p-4 rounded-2xl bg-white/5 border border-white/5' }, [
    h('span', { class: 'text-[10px] text-muted uppercase tracking-widest block font-mono' }, p.label),
    h('span', { class: ['text-2xl font-extrabold font-mono mt-1 block', p.highlight ? 'text-gold' : 'text-white'] }, p.value),
  ]);
InfoCard.props = ['label', 'value', 'highlight'];

const SummaryRow = (p) =>
  h('div', { class: 'flex justify-between pb-2 border-b border-white/5 last:border-0' }, [
    h('span', { class: 'text-muted' }, p.label),
    h('span', { class: ['font-bold', p.valueClass || 'text-white'] }, p.value),
  ]);
SummaryRow.props = ['label', 'value', 'valueClass'];
</script>

<style scoped>
.bg-card { background-color: var(--card-bg, #1e293b); }
.bg-input { background-color: var(--input-bg, rgba(255, 255, 255, 0.05)); }
.text-muted { color: var(--text-muted, #9ca3af); }
.text-gold { color: var(--gold, #d97706); }
.bg-gold { background-color: var(--gold, #d97706); }
.text-success { color: var(--success, #10b981); }
.bg-success { background-color: var(--success, #10b981); }
.text-error { color: var(--error, #ef4444); }
.text-warning { color: var(--warning, #f59e0b); }
</style>
