<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        :to="`/fawry/${transaction?.id}`"
        class="p-2 hover:bg-white/10 rounded-lg transition-colors"
      >
        <ArrowRight class="w-6 h-6" />
      </router-link>
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          تعديل المعاملة
        </h1>
        <p class="text-text-muted mt-1">
          تعديل معاملة #{{ transaction?.id }}
        </p>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="store.loading.transaction && !transaction" class="flex items-center justify-center py-16">
      <Loader2 class="w-8 h-8 animate-spin text-gold" />
    </div>

    <div v-else-if="!transaction" class="space-y-6 max-w-4xl mx-auto">
      <div class="text-center py-16 text-text-muted">
        لم يتم العثور على المعاملة أو لا يمكن تحميلها.
      </div>
      <FawryApiResponsePanel
        :envelope="store.lastApiEnvelope"
        @clear="store.clearLastApiEnvelope()"
      />
    </div>

    <!-- Form -->
    <form v-else @submit.prevent="handleSubmit" class="max-w-4xl mx-auto space-y-8">
      <!-- Customer Information -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-gold/10 rounded-lg">
            <User class="w-5 h-5 text-gold" />
          </div>
          <h2 class="text-xl font-bold text-text-main">بيانات العميل</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Client Name -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              اسم العميل <span class="text-error">*</span>
            </label>
            <input
              v-model="form.client_name"
              type="text"
              required
              placeholder="أدخل اسم العميل"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
            />
          </div>

          <!-- Reference Number -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              رقم المرجع
            </label>
            <input
              v-model="form.reference_number"
              type="text"
              placeholder="رقم العملية (اختياري)"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
            />
          </div>
        </div>
      </div>

      <!-- Operation Details -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-info/10 rounded-lg">
            <CreditCard class="w-5 h-5 text-info" />
          </div>
          <h2 class="text-xl font-bold text-text-main">تفاصيل العملية</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Operation Type -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              نوع العملية <span class="text-error">*</span>
            </label>
            <input
              v-model.trim="form.operation_type"
              type="text"
              required
              maxlength="50"
              placeholder="مثال: دفع فاتورة كهرباء، شحن رصيد..."
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
            />
          </div>

        </div>
      </div>

      <!-- Pricing -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-success/10 rounded-lg">
            <Banknote class="w-5 h-5 text-success" />
          </div>
          <div>
            <h2 class="text-xl font-bold text-text-main">التسعير</h2>
            <p class="text-xs text-text-muted mt-0.5 leading-relaxed max-w-2xl">
              <span class="font-semibold text-text-main/90">تكلفة فوري</span>
              = المبلغ على المكتب.
              <span class="font-semibold text-text-main/90">سعر البيع</span>
              = ما يدفعه العميل.
              <span class="font-semibold text-success/90">الربح</span>
              = البيع − التكلفة.
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              تكلفة فوري (المبلغ عليّ) <span class="text-error">*</span>
            </label>
            <div class="relative">
              <input
                v-model.number="form.fawry_price"
                type="number"
                step="0.01"
                required
                min="0"
                placeholder="0.00"
                class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">ج.م</span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              سعر البيع للعميل <span class="text-error">*</span>
            </label>
            <div class="relative">
              <input
                v-model.number="form.selling_price"
                type="number"
                step="0.01"
                required
                min="0"
                placeholder="0.00"
                class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">ج.م</span>
            </div>
          </div>
        </div>

        <div class="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
          <p class="mb-2 text-xs font-semibold text-text-muted">
            تسعير سريع — هامش على التكلفة (يعدّل سعر البيع)
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="pct in sellMarkupPercents"
              :key="`mk-${pct}`"
              type="button"
              class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-text-muted transition hover:border-gold/50 hover:bg-gold/15 hover:text-gold"
              @click="applySellMarkupPercent(pct)"
            >
              +{{ pct }}٪
            </button>
          </div>
        </div>

        <div
          class="mt-6 p-4 rounded-xl border"
          :class="
            calculatedProfit >= 0
              ? 'bg-success/10 border-success/20'
              : 'bg-error/10 border-error/25'
          "
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span
              class="font-semibold"
              :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
            >
              صافي الربح
            </span>
            <span
              class="text-2xl font-bold font-mono tabular-nums"
              :class="calculatedProfit >= 0 ? 'text-success' : 'text-error'"
            >
              {{ formatCurrency(calculatedProfit) }}
            </span>
          </div>
          <p v-if="marginOnCostPercent !== null" class="mt-2 text-xs text-text-muted">
            نسبة الربح على التكلفة:
            <span class="font-mono font-semibold text-text-main">{{ marginOnCostPercent }}٪</span>
          </p>
        </div>
      </div>

      <!-- Payment -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-purple/10 rounded-lg">
            <Wallet class="w-5 h-5 text-purple" />
          </div>
          <h2 class="text-xl font-bold text-text-main">بيانات الدفع</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              طريقة الدفع <span class="text-error">*</span>
            </label>
            <select
              v-model="form.payment_method"
              required
              class="form-select-dark"
            >
              <option value="">اختر طريقة الدفع</option>
              <option v-for="method in store.paymentMethods" :key="method.value" :value="method.value">
                {{ method.label }}
              </option>
            </select>
          </div>

          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-text-muted mb-2">
              المبلغ المدفوع الآن <span class="text-error">*</span>
            </label>
            <div class="relative max-w-md">
              <input
                v-model.number="form.amount"
                type="number"
                step="0.01"
                required
                min="0"
                :max="Number(form.selling_price) || undefined"
                placeholder="0.00"
                class="w-full px-4 py-3 pl-14 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">ج.م</span>
            </div>
            <p class="mt-2 text-xs text-text-muted">
              نسبة من سعر البيع (كحجز الطيران / الباص).
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="pct in paidAmountPercents"
                :key="`pay-${pct}`"
                type="button"
                class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-text-muted transition hover:border-gold/50 hover:bg-gold/15 hover:text-gold"
                @click="setPaidPercentOfSelling(pct)"
              >
                {{ pct }}٪
              </button>
              <button
                type="button"
                class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-text-muted transition hover:border-white/25"
                @click="form.amount = 0"
              >
                تصفير
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Additional Information -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-purple/10 rounded-lg">
            <FileText class="w-5 h-5 text-purple" />
          </div>
          <h2 class="text-xl font-bold text-text-main">معلومات إضافية</h2>
        </div>

        <div class="space-y-6">
          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              ملاحظات
            </label>
            <textarea
              v-model="form.notes"
              rows="4"
              placeholder="أضف ملاحظات هنا (اختياري)"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all resize-none"
            ></textarea>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-between gap-4">
        <router-link
          :to="`/fawry/${transaction.id}`"
          class="px-8 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
        >
          إلغاء
        </router-link>
        <button
          type="submit"
          :disabled="store.loading.update"
          class="px-8 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <Loader2 v-if="store.loading.update" class="w-5 h-5 animate-spin" />
          <Check v-else class="w-5 h-5" />
          {{ store.loading.update ? 'جاري الحفظ...' : 'حفظ التغييرات' }}
        </button>
      </div>
    </form>

    <div v-if="transaction" class="max-w-4xl mx-auto">
      <FawryApiResponsePanel
        :envelope="store.lastApiEnvelope"
        @clear="store.clearLastApiEnvelope()"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useFawryStore } from '@/stores/fawryStore';
import FawryApiResponsePanel from '@/views/fawry/FawryApiResponsePanel.vue';
import {
  ArrowRight,
  User,
  CreditCard,
  Banknote,
  Wallet,
  FileText,
  Check,
  Loader2,
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const store = useFawryStore();

const transaction = computed(() => {
  return store.transactions.find(t => t.id === parseInt(route.params.id));
});

// Form data
const form = ref({
  client_name: '',
  operation_type: '',
  fawry_price: 0,
  selling_price: 0,
  payment_method: '',
  amount: 0,
  reference_number: '',
  notes: '',
});

const sellMarkupPercents = [20, 50, 100];
const paidAmountPercents = [20, 25, 50, 75, 100];
const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

// Watch transaction changes to update form
watch(transaction, (newTransaction) => {
  if (newTransaction) {
    form.value = {
      client_name: newTransaction.client_name || '',
      operation_type: newTransaction.operation_type || '',
      fawry_price: newTransaction.fawry_price || 0,
      selling_price: newTransaction.selling_price || 0,
      payment_method: newTransaction.payment_method || '',
      amount: newTransaction.amount || 0,
      reference_number: newTransaction.reference_number || '',
      notes: newTransaction.notes || '',
    };
  }
}, { immediate: true });

// Computed
const calculatedProfit = computed(() => {
  return roundMoney((form.value.selling_price || 0) - (form.value.fawry_price || 0));
});

const marginOnCostPercent = computed(() => {
  const c = Number(form.value.fawry_price) || 0;
  if (c <= 0) {
    return null;
  }
  return roundMoney((calculatedProfit.value / c) * 100);
});

// Methods
const formatCurrency = (amount) => {
  const n = Number(amount) || 0;
  return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ج.م`;
};

const applySellMarkupPercent = (pct) => {
  const c = Number(form.value.fawry_price) || 0;
  if (c <= 0) {
    store.addToast('أدخل تكلفة فوري أولاً', 'error');
    return;
  }
  form.value.selling_price = roundMoney(c * (1 + pct / 100));
};

const setPaidPercentOfSelling = (pct) => {
  const sp = Number(form.value.selling_price) || 0;
  if (sp <= 0) {
    store.addToast('أدخل سعر البيع أولاً', 'error');
    return;
  }
  form.value.amount = roundMoney((sp * pct) / 100);
};

const handleSubmit = async () => {
  try {
    const sp = roundMoney(Number(form.value.selling_price) || 0);
    await store.updateTransaction(transaction.value.id, {
      ...form.value,
      selling_price: sp,
      client_amount: sp,
    });
    router.push(`/fawry/${transaction.value.id}`);
  } catch (error) {
    console.error('Failed to update transaction:', error);
  }
};

// Lifecycle
onMounted(async () => {
  const id = parseInt(route.params.id, 10);
  if (!Number.isFinite(id)) {
    return;
  }
  await store.fetchSettings().catch(() => {});
  try {
    await store.fetchTransactionById(id);
  } catch {
    /* يُعرض Not found + لوحة الاستجابة */
  }
});
</script>
