<template>
  <div dir="rtl" class="max-w-4xl mx-auto bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl relative text-right">
    <div class="p-6 bg-white/5 border-b border-white/10 flex flex-col md:flex-row items-center justify-between gap-4">
      <div>
        <h3 class="text-xl font-bold text-white flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          استرداد حجز باص
        </h3>
        <span class="text-xs text-muted block mt-1">إلغاء الحجز مع خصم الشركة وعمولة الإلغاء — بدون تغيير تسجيل الحجوزات الجديدة بالآجل</span>
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

      <!-- الخطوة 1 -->
      <div v-if="currentStep === 1" class="space-y-6">
        <StepHeader :step="1" :total="totalSteps" title="معلومات الحجز" subtitle="مراجعة بيانات الحجز والمبالغ قبل احتساب الاسترداد." />

        <div v-if="initialBooking" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <InfoCard label="رقم الحجز" :value="'#' + (initialBooking.id || '—')" highlight />
          <InfoCard label="العميل" :value="customerName" />
          <InfoCard label="سعر البيع" :value="formatMoney(sellingPrice)" />
          <InfoCard label="تكلفة الشراء (آجل للشركة)" :value="formatMoney(purchaseCost)" />
          <InfoCard label="المدفوع من العميل" :value="formatMoney(totalPaidFromPayments)" highlight />
          <InfoCard label="شركة الباص" :value="companyName" />
        </div>

        <div v-if="payments.length" class="p-4 rounded-2xl bg-white/5 border border-white/10 space-y-2">
          <h5 class="text-xs font-bold text-gold">الدفعات المحصلة</h5>
          <div
            v-for="pay in payments"
            :key="pay.id"
            class="flex items-center justify-between text-xs font-mono p-2 rounded-xl bg-card border border-white/5"
          >
            <span class="text-success font-bold">{{ formatMoney(pay.amount) }}</span>
            <span class="text-muted">{{ pay.payment_method || 'دفعة' }}</span>
          </div>
        </div>
        <p v-else-if="initialBooking" class="text-xs text-warning bg-warning/10 border border-warning/20 rounded-xl p-3">
          لا توجد دفعات نقدية — سيُعدَّل رصيد مديونية العميل بعد الخصومات.
        </p>
      </div>

      <!-- الخطوة 2 -->
      <div v-if="currentStep === 2" class="space-y-6">
        <StepHeader :step="2" :total="totalSteps" title="خصم الشركة وعمولة الإلغاء" subtitle="أدخل الغرامات حسب كل حالة — القيم ليست ثابتة." />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div class="p-4 rounded-2xl bg-white/5 border border-white/5 flex justify-between">
            <span class="text-muted">تكلفة الشراء</span>
            <span class="font-mono font-bold text-white">{{ formatMoney(purchaseCost) }}</span>
          </div>
          <div class="p-4 rounded-2xl bg-white/5 border border-white/5 flex justify-between">
            <span class="text-muted">أساس حساب العميل</span>
            <span class="font-mono font-bold text-gold">{{ formatMoney(customerBasis) }}</span>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs text-muted mb-2 font-mono">خصم شركة الباص</label>
            <input
              v-model.number="form.companyPenalty"
              type="number"
              min="0"
              step="0.01"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl text-white text-right"
            />
            <p class="text-[10px] text-muted mt-1">يُخصم من تكلفة الشراء (الآجل)</p>
          </div>
          <div>
            <label class="block text-xs text-muted mb-2 font-mono">عمولة الإلغاء (المكتب)</label>
            <input
              v-model.number="form.officePenalty"
              type="number"
              min="0"
              step="0.01"
              class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl text-white text-right"
            />
          </div>
        </div>

        <div class="p-6 rounded-2xl bg-gold/5 border border-gold/20 space-y-3 font-mono text-sm">
          <div class="flex justify-between text-muted">
            <span>يُخفَّض دين الشركة بمقدار</span>
            <span class="text-white font-bold">{{ formatMoney(companyCreditAmount) }}</span>
          </div>
          <div class="flex justify-between text-error">
            <span>− خصم الشركة</span>
            <span>{{ formatMoney(form.companyPenalty) }}</span>
          </div>
          <div class="flex justify-between text-error">
            <span>− عمولة الإلغاء</span>
            <span>{{ formatMoney(form.officePenalty) }}</span>
          </div>
          <div class="border-t border-gold/20 pt-3 flex justify-between items-center">
            <span class="text-gold font-bold">المبلغ المرتجع للعميل (نقدي)</span>
            <span class="text-3xl font-extrabold text-success">{{ formatMoney(customerRefundAmount) }}</span>
          </div>
          <div v-if="totalPaidFromPayments <= 0" class="text-xs text-muted pt-1">
            تعديل المديونية: {{ formatMoney(debtAdjustmentAmount) }} من أصل {{ formatMoney(sellingPrice) }}
          </div>
        </div>

        <p v-if="penaltiesExceedBasis" class="text-xs text-error bg-error/10 border border-error/20 rounded-xl p-3">
          مجموع الخصومات يتجاوز المبلغ المسموح — راجع القيم المدخلة.
        </p>
        <p v-if="companyPenaltyExceedsCost" class="text-xs text-error bg-error/10 border border-error/20 rounded-xl p-3">
          خصم الشركة لا يمكن أن يتجاوز تكلفة الشراء.
        </p>
      </div>

      <!-- الخطوة 3 -->
      <div v-if="currentStep === 3" class="space-y-6">
        <StepHeader :step="3" :total="totalSteps" title="حساب صرف الاسترداد" subtitle="اختر حساب الصرف عند وجود مبلغ نقدي مرتجع للعميل." />

        <div v-if="customerRefundAmount > 0">
          <label class="block text-xs text-gold mb-3 uppercase tracking-widest font-mono">حساب الصرف *</label>

          <div v-if="settlementAccounts.length === 0" class="p-4 rounded-xl bg-error/10 border border-error/20 text-center">
            <p class="text-xs text-error font-mono">لا توجد حسابات تسوية متاحة.</p>
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
          لا يوجد مبلغ نقدي للعميل — يُكتفى بتعديل المديونية والشركة.
        </div>

        <div>
          <label class="block text-xs text-muted mb-2 font-mono">ملاحظات (اختياري)</label>
          <textarea
            v-model="form.notes"
            rows="2"
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none text-xs text-white text-right"
          ></textarea>
        </div>
      </div>

      <!-- الخطوة 4 -->
      <div v-if="currentStep === 4" class="space-y-6">
        <StepHeader :step="4" :total="totalSteps" title="مراجعة وتنفيذ" subtitle="تأكد من صحة الحسابات قبل التسجيل النهائي." />

        <div class="p-6 rounded-2xl bg-white/5 border border-white/10 space-y-3 font-mono text-sm">
          <SummaryRow label="رقم الحجز" :value="'#' + (initialBooking?.id || '—')" />
          <SummaryRow label="العميل" :value="customerName" />
          <SummaryRow label="خصم شركة الباص" :value="'− ' + formatMoney(form.companyPenalty)" value-class="text-error" />
          <SummaryRow label="عمولة الإلغاء" :value="'− ' + formatMoney(form.officePenalty)" value-class="text-error" />
          <SummaryRow label="يُخفَّض دين الشركة" :value="formatMoney(companyCreditAmount)" />
          <SummaryRow label="المبلغ المرتجع للعميل" :value="formatMoney(customerRefundAmount)" value-class="text-success font-bold text-lg" />
          <SummaryRow
            v-if="customerRefundAmount > 0"
            label="حساب الصرف"
            :value="selectedAccount?.name || '—'"
          />
        </div>
      </div>

      <div v-if="successResult" class="absolute inset-0 bg-card z-40 p-8 flex flex-col items-center justify-center text-center">
        <div class="w-16 h-16 bg-success/10 border border-success/20 rounded-full flex items-center justify-center mb-4 text-success text-2xl">✓</div>
        <h4 class="text-xl font-bold text-white">تم تسجيل الاسترداد بنجاح</h4>
        <div class="mt-6 p-4 rounded-xl bg-white/5 border border-white/5 w-full max-w-sm font-mono text-xs text-right space-y-2">
          <div class="flex justify-between"><span class="text-muted">رقم الاسترداد:</span><span class="text-gold font-bold">#{{ successResult.id }}</span></div>
          <div class="flex justify-between"><span class="text-muted">المبلغ المرتجع:</span><span class="text-success font-bold">{{ formatMoney(successResult.refund_amount) }}</span></div>
        </div>
        <button type="button" class="mt-8 px-6 py-2.5 bg-gold text-black font-bold text-xs rounded-xl" @click="$emit('completed', successResult)">إغلاق</button>
      </div>

      <div v-if="!successResult" class="pt-6 border-t border-white/5 flex items-center justify-between mt-8 flex-row-reverse">
        <button v-if="currentStep > 1" type="button" class="px-5 py-2.5 rounded-xl border border-white/10 text-xs font-mono text-muted hover:text-white" @click="prevStep">السابق ←</button>
        <div v-else></div>

        <button
          v-if="currentStep < totalSteps"
          type="button"
          :disabled="!canGoNext"
          class="px-6 py-2.5 rounded-xl bg-gold text-black text-xs font-mono font-bold disabled:opacity-40"
          @click="nextStep"
        >← التالي</button>
        <button
          v-else
          type="button"
          :disabled="loading || !canSubmit"
          class="px-8 py-3 rounded-xl bg-success text-white text-xs font-mono font-bold disabled:opacity-40"
          @click="submitRefund"
        >تأكيد الاسترداد</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue';
import axios from 'axios';
import { fetchSettlementAccounts } from '@/composables/useTreasuryAccountGroups';
import { useBusStore } from '@/stores/busStore';

const props = defineProps({
  initialBooking: { type: Object, default: null },
});

const emit = defineEmits(['completed']);

const store = useBusStore();
const totalSteps = 4;
const currentStep = ref(1);
const loading = ref(false);
const loadingText = ref('جاري معالجة الاسترداد...');
const successResult = ref(null);
const settlementAccounts = ref([]);

const form = ref({
  bookingId: null,
  companyPenalty: 0,
  officePenalty: 0,
  accountId: null,
  notes: '',
});

const payments = computed(() => {
  const b = props.initialBooking;
  return Array.isArray(b?.payments) ? b.payments : [];
});

const totalPaidFromPayments = computed(() =>
  payments.value.reduce((sum, p) => sum + (Number(p.amount) || 0), 0),
);

const sellingPrice = computed(() => Number(props.initialBooking?.total_price ?? props.initialBooking?.totalPrice ?? 0) || 0);

const purchaseCost = computed(() => {
  const inv = props.initialBooking?.inventory;
  const cost = Number(inv?.cost_per_ticket ?? inv?.costPerTicket ?? 0);
  const qty = Number(props.initialBooking?.quantity ?? 1);
  return cost * qty;
});

const customerBasis = computed(() => {
  if (totalPaidFromPayments.value > 0) return totalPaidFromPayments.value;
  return sellingPrice.value;
});

const totalPenalties = computed(
  () => (Number(form.value.companyPenalty) || 0) + (Number(form.value.officePenalty) || 0),
);

const companyCreditAmount = computed(() =>
  Math.max(0, roundMoney(purchaseCost.value - (Number(form.value.companyPenalty) || 0))),
);

const customerRefundAmount = computed(() =>
  Math.max(0, roundMoney(totalPaidFromPayments.value - totalPenalties.value)),
);

const debtAdjustmentAmount = computed(() =>
  Math.max(0, roundMoney(sellingPrice.value - Math.max(totalPaidFromPayments.value, totalPenalties.value))),
);

const penaltiesExceedBasis = computed(() => totalPenalties.value > customerBasis.value + 0.001);

const companyPenaltyExceedsCost = computed(
  () => (Number(form.value.companyPenalty) || 0) > purchaseCost.value + 0.001,
);

const customerName = computed(
  () => props.initialBooking?.customer?.full_name ?? props.initialBooking?.customer?.name ?? '—',
);

const companyName = computed(
  () => props.initialBooking?.inventory?.company?.name ?? props.initialBooking?.company?.name ?? '—',
);

const selectedAccount = computed(() =>
  settlementAccounts.value.find((a) => Number(a.id) === Number(form.value.accountId)) ?? null,
);

const canGoNext = computed(() => {
  if (currentStep.value === 2) {
    return !penaltiesExceedBasis.value && !companyPenaltyExceedsCost.value;
  }
  if (currentStep.value === 3) {
    if (customerRefundAmount.value <= 0) return true;
    return Boolean(form.value.accountId);
  }
  return true;
});

const canSubmit = computed(() => {
  if (!form.value.bookingId) return false;
  if (penaltiesExceedBasis.value || companyPenaltyExceedsCost.value) return false;
  if (customerRefundAmount.value > 0 && !form.value.accountId) return false;
  return true;
});

function roundMoney(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function formatMoney(amount, curr = 'EGP') {
  const n = Number(amount) || 0;
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(n);
  } catch {
    return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2 })} ${curr}`;
  }
}

function accountTypeLabel(type) {
  const map = { cashbox: 'خزينة', wallet: 'محفظة', bank: 'بنك', treasury: 'خزينة عامة', supplier: 'مورد' };
  return map[type] || type || '';
}

function setupFromBooking(booking) {
  if (!booking?.id) return;
  form.value.bookingId = booking.id;
  form.value.companyPenalty = 0;
  form.value.officePenalty = 0;
  form.value.accountId = booking.account?.id ?? booking.account_id ?? booking.payments?.[0]?.account_id ?? null;
  form.value.notes = '';
}

async function loadSettlementAccounts() {
  try {
    settlementAccounts.value = await fetchSettlementAccounts(axios, {
      module: 'bus',
      includePost: false,
    });
    if (!form.value.accountId && settlementAccounts.value.length === 1) {
      form.value.accountId = settlementAccounts.value[0].id;
    }
  } catch {
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
      company_penalty: roundMoney(form.value.companyPenalty),
      office_penalty: roundMoney(form.value.officePenalty),
      notes: form.value.notes?.trim() || null,
    };
    if (customerRefundAmount.value > 0) {
      payload.account_id = Number(form.value.accountId);
    }

    const updated = await store.cancelBooking(form.value.bookingId, payload);
    const refund = updated?.refund;
    successResult.value = {
      id: refund?.id ?? updated?.id,
      refund_amount: refund?.refund_amount ?? refund?.refundAmount ?? customerRefundAmount.value,
      company_penalty: refund?.company_penalty ?? payload.company_penalty,
      office_penalty: refund?.office_penalty ?? payload.office_penalty,
    };
  } catch (err) {
    const msg = err.response?.data?.message || store.errors?.message || 'تعذر تنفيذ الاسترداد';
    store.addToast?.(msg, 'error') ?? alert(msg);
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
    h('span', { class: ['text-xl font-extrabold font-mono mt-1 block', p.highlight ? 'text-gold' : 'text-white'] }, p.value),
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
