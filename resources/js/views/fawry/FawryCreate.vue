<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        to="/fawry"
        class="p-2 hover:bg-white/10 rounded-lg transition-colors"
      >
        <ArrowRight class="w-6 h-6" />
      </router-link>
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          معاملة فورية جديدة
        </h1>
        <p class="text-text-muted mt-1">
          إنشاء معاملة فورية جديدة
        </p>
      </div>
    </div>

    <!-- Form -->
    <form @submit.prevent="handleSubmit" class="max-w-4xl mx-auto space-y-8">
      <!-- Customer Information -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-gold/10 rounded-lg">
            <User class="w-5 h-5 text-gold" />
          </div>
          <h2 class="text-xl font-bold text-text-main">بيانات العميل</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Customer Selection -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              العميل المسجّل
            </label>
            <select
              v-model="form.client_id"
              class="form-select-dark"
            >
              <option value="">— بدون اختيار (عميل جديد) —</option>
              <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                {{ customer.full_name }}
              </option>
            </select>
            <p class="text-xs text-text-muted mt-1">
              <router-link to="/customers" class="text-gold hover:underline">إدارة العملاء</router-link>
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              اسم العميل <span class="text-error">*</span>
              <span v-if="form.client_id" class="text-text-muted font-normal text-xs">(يُحدَّث من القائمة)</span>
            </label>
            <input
              v-model="form.client_name"
              type="text"
              :readonly="!!form.client_id"
              :required="!form.client_id"
              placeholder="اسم العميل كما سيظهر في السجل"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all read-only:opacity-70"
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
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-text-muted mb-2">
              نوع العملية <span class="text-error">*</span>
            </label>
            <input
              v-model.trim="form.operation_type"
              type="text"
              required
              maxlength="50"
              placeholder="مثال: دفع فاتورة كهرباء، شحن رصيد، تحويل..."
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
            />
            <p class="text-xs text-text-muted mt-2">أدخل نوع العملية يدوياً كما تظهر في الإيصال أو العملية الفعلية.</p>
          </div>

          <!-- Fawry Machine -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-text-muted mb-2">
              ماكينة الشحن فوري / أمان <span class="text-error">*</span>
            </label>
            <select
              v-model="form.fawry_machine_id"
              required
              class="form-select-dark"
            >
              <option value="">اختر الماكينة</option>
              <option v-for="mach in store.machines" :key="mach.id" :value="mach.id">
                {{ mach.name }} ({{ formatMachineType(mach.type) }}) — رصيدها الحالي: {{ formatMoney(mach.balance) }}
              </option>
            </select>
            <p v-if="selectedMachine" class="text-xs text-text-muted mt-2">
              نوع الشبكة: <span class="font-bold text-text-main">{{ formatMachineType(selectedMachine.type) }}</span> · 
              الرصيد المتوقع بعد العملية: 
              <span 
                :class="selectedMachine.balance - form.fawry_price >= 0 ? 'text-success font-mono font-bold' : 'text-error font-mono font-bold'"
              >
                {{ formatMoney(selectedMachine.balance - form.fawry_price) }}
              </span>
            </p>
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
              هو ما يُستحق على المكتب لفوري (التكلفة).
              <span class="font-semibold text-text-main/90">سعر البيع</span>
              هو ما يدفعه العميل لك.
              <span class="font-semibold text-success/90">الربح</span>
              = سعر البيع − تكلفة فوري (يُحسب تلقائياً ويُسجَّل محاسبياً على هذا الأساس).
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
            تسعير سريع — هامش ربح على التكلفة (يعدّل «سعر البيع» فقط)
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
          <p class="mt-2 text-[11px] leading-relaxed text-text-muted">
            مثال: تكلفة 100 ج.م و +50٪ → بيع 150 ج.م. زر +100٪ يضاعف سعر البيع مقابل التكلفة (هامش 100٪ على التكلفة).
          </p>
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

      <!-- Payment Details -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-purple/10 rounded-lg">
            <Wallet class="w-5 h-5 text-purple" />
          </div>
          <h2 class="text-xl font-bold text-text-main">بيانات الدفع</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Payment Method -->
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              طريقة الدفع <span class="text-error">*</span>
            </label>
            <select
              v-model="form.payment_method"
              required
              @change="onPaymentMethodChange"
              class="form-select-dark"
            >
              <option value="">اختر طريقة الدفع</option>
              <option v-for="method in store.paymentMethods" :key="method.value" :value="method.value">
                {{ method.label }}
              </option>
            </select>
          </div>

          <!-- Amount -->
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
              نسبة من سعر البيع (مثل حجز الطيران / الباص): تُملأ «المبلغ المدفوع» بحسب ما دفعه العميل فعلياً.
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

        <!-- Payment Summary Breakdown (Credit/Debt) -->
        <div v-if="form.client_id" class="mt-6 p-5 rounded-2xl border border-white/10 bg-white/[0.02] space-y-2">
          <div class="flex items-center justify-between text-xs text-text-muted">
            <span>إجمالي سعر البيع:</span>
            <span class="font-mono font-bold">{{ formatCurrency(form.selling_price) }}</span>
          </div>
          <div class="flex items-center justify-between text-xs text-text-muted">
            <span>المبلغ المدفوع الآن:</span>
            <span class="font-mono font-bold text-emerald-400">{{ formatCurrency(form.amount || 0) }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-white/5 pt-2.5 font-bold">
            <span class="text-sm text-text-main">الآجل المتبقي (مديونية العميل):</span>
            <div class="flex items-center gap-2">
              <span class="font-mono text-lg text-error">{{ formatCurrency(Math.max(0, (form.selling_price || 0) - (form.amount || 0))) }}</span>
              <span v-if="(form.selling_price || 0) - (form.amount || 0) > 0" class="rounded-full bg-red-500/10 px-2.5 py-0.5 text-[10px] font-bold text-red-400 border border-red-500/20">سيتم تسجيلها كآجل</span>
              <span v-else class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-500/20">مدفوع بالكامل ✓</span>
            </div>
          </div>
        </div>

        <!-- Payment Details Info -->
        <div v-if="selectedPaymentMethod" class="mt-4 p-4 bg-info/10 border border-info/20 rounded-xl">
          <div class="text-info font-semibold mb-2">تفاصيل طريقة الدفع:</div>
          <div class="text-sm text-text-muted">
            {{ selectedPaymentMethod.fullDetails }}
          </div>
        </div>

        <div class="mt-6">
          <label class="block text-sm font-semibold text-text-main mb-2">نوع حساب التحصيل</label>
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

          <label class="block text-sm font-medium text-text-muted mb-2">
            حساب التسوية / الخزينة <span class="text-error">*</span>
          </label>
          <select
            v-model="form.account_id"
            required
            class="form-select-dark"
          >
            <option value="">اختر الحساب</option>
            <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">
              {{ formatSettlementOption(acc) }}
            </option>
          </select>
          <p v-if="filteredAccounts.length === 0" class="text-xs text-warning mt-1">
            لا توجد حسابات متاحة في هذا التصنيف.
          </p>
          <p v-if="selectedPaymentMethod?.defaultAccountId && form.account_id == selectedPaymentMethod.defaultAccountId" class="text-xs text-text-muted mt-1">
            مُقترَح تلقائياً من إعدادات طريقة الدفع؛ يمكنك تغييره.
          </p>

          <!-- Balance Preview -->
          <div
            v-if="selectedSettlementAccount"
            class="mt-4 space-y-2 rounded-xl border border-gold/25 bg-gold/10 p-4 text-sm"
          >
            <div class="text-[10px] font-bold uppercase tracking-wider text-gold/90">رصيد حساب التحصيل</div>
            <div class="flex justify-between gap-2 text-text-muted">
              <span>الرصيد الحالي</span>
              <span class="font-mono font-bold text-white tabular-nums">
                {{ formatMoney(balancePreview.current, balancePreview.currency) }}
              </span>
            </div>
            <div
              v-if="balancePreview.delta > 0"
              class="flex justify-between gap-2 border-t border-white/10 pt-2"
            >
              <span class="flex items-center gap-1 text-success">
                <ArrowUpRight class="h-4 w-4" />
                بعد تسجيل المعاملة (+ المبلغ)
              </span>
              <span class="font-mono text-base font-black text-success tabular-nums">
                {{ formatMoney(balancePreview.after, balancePreview.currency) }}
              </span>
            </div>
            <p v-else class="border-t border-white/10 pt-2 text-[11px] text-text-muted">
              أدخل مبلغاً في «المبلغ المدفوع الآن» ليظهر تقدير الرصيد بعد الزيادة.
            </p>
          </div>
        </div>

        <div class="mt-6">
          <label class="block text-sm font-medium text-text-muted mb-2">
            تفاصيل إضافية للجهة / المرجع (اختياري)
          </label>
          <textarea
            v-model="form.payment_detail_note"
            rows="2"
            placeholder="مثال: رقم إيصال، فرع، ملاحظة للمحاسبة"
            class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all resize-none"
          />
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

      <FawryApiResponsePanel
        :envelope="store.lastApiEnvelope"
        @clear="store.clearLastApiEnvelope()"
      />

      <!-- Actions -->
      <div class="flex items-center justify-between gap-4">
        <router-link
          to="/fawry"
          class="px-8 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
        >
          إلغاء
        </router-link>
        <button
          type="submit"
          :disabled="store.loading.create"
          class="px-8 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <Loader2 v-if="store.loading.create" class="w-5 h-5 animate-spin" />
          <Check v-else class="w-5 h-5" />
          {{ store.loading.create ? 'جاري الإنشاء...' : 'إنشاء المعاملة' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useFawryStore } from '@/stores/fawryStore';
import FawryApiResponsePanel from '@/views/fawry/FawryApiResponsePanel.vue';
import { useCustomerStore } from '@/stores/customerStore';
import { useAuthStore } from '@/stores/authStore';
import { fetchSettlementAccounts as fetchModuleSettlementAccounts } from '@/composables/useTreasuryAccountGroups';
import {
  ArrowRight,
  ArrowUpRight,
  User,
  CreditCard,
  Check,
  Loader2,
  Banknote,
  Wallet,
  Landmark,
  FileText,
} from 'lucide-vue-next';

const router = useRouter();
const store = useFawryStore();
const customerStore = useCustomerStore();
const authStore = useAuthStore();

function createDefaultForm() {
  return {
    client_id: '',
    client_name: '',
    employee_id: '',
    operation_type: '',
    currency_id: '',
    fawry_price: 0,
    selling_price: 0,
    payment_method: '',
    amount: 0,
    account_id: '',
    fawry_machine_id: '',
    reference_number: '',
    notes: '',
    payment_detail_note: '',
  };
}

// Form data
const form = ref(createDefaultForm());

function resetForm() {
  form.value = createDefaultForm();
  settlementCategoryUi.value = 'cash';
  store.clearLastApiEnvelope();
  if (authStore.user?.id) {
    form.value.employee_id = authStore.user.id;
  }
}

/** هامش على التكلفة لضبط سعر البيع سريعاً (20٪ = البيع = التكلفة × 1.20) */
const sellMarkupPercents = [20, 50, 100];

/** نسبة المدفوع من سعر البيع — مماثل لحجز الطيران/الباص */
const paidAmountPercents = [20, 25, 50, 75, 100];

const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

// Customers
const customers = ref([]);
const settlementAccounts = ref([]);

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

// Computed
const calculatedProfit = computed(() => {
  return roundMoney((form.value.selling_price || 0) - (form.value.fawry_price || 0));
});

/** نسبة الربح على التكلفة (للعرض فقط) */
const marginOnCostPercent = computed(() => {
  const c = Number(form.value.fawry_price) || 0;
  if (c <= 0) {
    return null;
  }
  const p = calculatedProfit.value;
  return roundMoney((p / c) * 100);
});

const selectedPaymentMethod = computed(() => {
  return store.paymentMethods.find(m => m.value === form.value.payment_method);
});

const selectedSettlementAccount = computed(() => {
  const id = form.value.account_id;
  if (id == null || id === '') return null;
  return settlementAccounts.value.find((x) => String(x.id) === String(id)) ?? null;
});

const selectedMachine = computed(() => {
  const id = form.value.fawry_machine_id;
  if (!id) return null;
  return store.machines.find(m => String(m.id) === String(id)) ?? null;
});

const formatMachineType = (type) => {
  const types = {
    fawry: 'فوري',
    aman: 'أمان',
    momtaz: 'ممتاز',
    masary: 'مصاري',
    other: 'أخرى',
  };
  return types[type] || type;
};

const balancePreview = computed(() => {
  const a = selectedSettlementAccount.value;
  if (!a) return null;
  const cur = Number(a.balance) || 0;
  const add = Number(form.value.amount) || 0;
  return {
    current: cur,
    after: cur + add,
    delta: add,
    currency: a.currency || 'EGP',
  };
});

watch(
  () => form.value.client_id,
  (id) => {
    if (!id) {
      return;
    }
    const c = customers.value.find((x) => String(x.id) === String(id));
    if (c?.full_name) {
      form.value.client_name = c.full_name;
    }
  }
);



// Methods
const formatCurrency = (amount) => {
  const n = Number(amount) || 0;
  return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ج.م`;
};

const applySellMarkupPercent = (pct) => {
  const c = Number(form.value.fawry_price) || 0;
  if (c <= 0) {
    store.addToast('أدخل تكلفة فوري أولاً لاستخدام التسعير السريع', 'error');
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

const formatMoney = (amount, currencyCode = 'EGP') => {
  const n = Number(amount) || 0;
  const code = currencyCode || 'EGP';
  const label = code === 'EGP' ? 'ج.م' : code;
  return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${label}`;
};

const getAccountTypeLabel = (type) => {
  const labels = {
    cashbox: 'خزينة نقدية',
    treasury: 'خزينة عامة',
    wallet: 'محفظة إلكترونية',
    bank: 'بنك',
  };
  return labels[type] || type;
};

const formatSettlementOption = (account) => {
  const bal = formatMoney(account.balance ?? 0, account.currency || 'EGP');
  const typeLabel = getAccountTypeLabel(account.type);
  if (account.type === 'wallet' && account.wallet_provider) {
    const num = String(account.wallet_number ?? '').trim();
    const line = num ? `${account.wallet_provider} — ${num}` : account.wallet_provider;
    return `${account.name} — ${line} — ${bal}`;
  }
  return `${account.name} — ${typeLabel} — ${bal}`;
};

const onPaymentMethodChange = () => {
  const method = selectedPaymentMethod.value;
  if (method?.defaultAccountId) {
    form.value.account_id = method.defaultAccountId;
  }
};

const loadSettlementAccounts = async () => {
  try {
    settlementAccounts.value = await fetchModuleSettlementAccounts(axios, { module: 'fawry' });
  } catch (error) {
    console.error('Failed to load settlement accounts:', error);
    settlementAccounts.value = [];
  }
};

const fetchCustomers = async () => {
  try {
    await customerStore.fetchCustomers({ per_page: 200 });
    customers.value = customerStore.customers || [];
  } catch (error) {
    console.error('Failed to fetch customers:', error);
  }
};

const handleSubmit = async () => {
  if (!form.value.client_id && !String(form.value.client_name || '').trim()) {
    store.addToast('أدخل اسم العميل أو اختر عميلاً من القائمة', 'error');
    return;
  }
  if (!form.value.account_id) {
    store.addToast('اختر حساب التسوية / الخزينة', 'error');
    return;
  }
  const sp = roundMoney(Number(form.value.selling_price) || 0);
  if (form.value.amount === null || form.value.amount === undefined || form.value.amount === '') {
    form.value.amount = 0;
  }

  const payload = {
    ...form.value,
    selling_price: sp,
    client_amount: sp,
    payment_details: form.value.payment_detail_note?.trim()
      ? { note: form.value.payment_detail_note.trim() }
      : {},
  };

  try {
    const created = await store.createTransaction(payload);
    if (created?.id) {
      const account = selectedSettlementAccount.value;
      if (account) {
        const newBal = (Number(account.balance) || 0) + (Number(form.value.amount) || 0);
        store.addToast(
          `تم إنشاء المعاملة بنجاح — الرصيد الجديد: ${formatMoney(newBal, account.currency || 'EGP')}`,
          'success'
        );
      }
      router.push(`/fawry/${created.id}`);
    } else {
      router.push('/fawry');
    }
  } catch (error) {
    console.error('Failed to create transaction:', error);
  }
};

// Lifecycle
onMounted(async () => {
  if (!authStore.user) {
    await authStore.initAuth();
  }
  resetForm();

  await Promise.all([
    store.fetchSettings(),
    fetchCustomers(),
    loadSettlementAccounts(),
    store.fetchMachines({ is_active: 1 }),
  ]);
});

onActivated(() => {
  resetForm();
});
</script>
