<template>
  <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">إنشاء حجز حج/عمرة جديد</h1>
        <p class="text-muted mt-1">{{ steps[currentStep - 1].label }}</p>
      </div>
      <router-link :to="{ name: 'hajj.index' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> إلغاء
      </router-link>
    </div>

    <!-- Step Indicator -->
    <div class="relative px-4 py-8 bg-card border border-white/10 rounded-3xl shadow-2xl">
      <div class="flex justify-between relative z-10">
        <div v-for="step in steps" :key="step.id" class="flex flex-col items-center gap-3 flex-1 relative">
          <div v-if="step.id < steps.length"
            :class="['absolute top-5 left-1/2 w-full h-[2px] -z-10 transition-colors duration-500',
              currentStep > step.id ? 'bg-success' : 'bg-white/10']"></div>
          <div :class="[
            'w-10 h-10 rounded-full flex items-center justify-center transition-all duration-500 border-2',
            currentStep === step.id ? 'bg-gold border-gold text-black shadow-[0_0_20px_rgba(212,168,67,0.4)] scale-110' :
            currentStep > step.id ? 'bg-success border-success text-white' : 'bg-input border-white/10 text-muted'
          ]">
            <Check v-if="currentStep > step.id" class="w-5 h-5" />
            <span v-else class="font-bold">{{ step.id }}</span>
          </div>
          <span :class="['text-[10px] font-bold uppercase tracking-widest transition-colors duration-500',
            currentStep === step.id ? 'text-gold' : 'text-muted']">{{ step.title }}</span>
        </div>
      </div>
    </div>

    <!-- Step Content -->
    <div class="min-h-[400px]">
      <transition name="step" mode="out-in">
        <div :key="currentStep" class="space-y-8">
          <!-- Step 1: العميل -->
          <section v-if="currentStep === 1" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">لمن هذا الحجز؟</h2>
              <p class="text-muted text-sm mb-8">ابحث عن عميل أو أنشئ عميل جديد.</p>

              <div class="space-y-4">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
                  <input v-model="customerSearch" type="text" placeholder="بحث بالاسم أو الهاتف..."
                    class="w-full pl-10 pr-4 py-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                    @input="onCustomerSearch" />
                </div>

                <div v-if="store.loading.customers" class="text-center py-8">
                  <div class="animate-spin w-8 h-8 border-2 border-gold border-t-transparent rounded-full mx-auto"></div>
                </div>

                <div v-else-if="filteredCustomers.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <button v-for="c in filteredCustomers" :key="c.id" type="button" @click="selectCustomer(c)"
                    :class="['w-full p-4 rounded-xl text-right transition-all',
                      form.customer?.id === c.id ? 'bg-gold/20 border-2 border-gold' : 'bg-input border border-white/10 hover:border-gold/50']">
                    <div class="font-bold">{{ c.full_name || c.name }}</div>
                    <div class="text-sm text-muted">{{ c.phone }}</div>
                  </button>
                </div>

                <button type="button" @click="showNewCustomerForm = !showNewCustomerForm"
                  class="w-full p-4 bg-white/5 border border-dashed border-white/20 rounded-xl text-muted hover:border-gold hover:text-gold transition-all flex items-center justify-center gap-2">
                  <Plus class="w-4 h-4" /> {{ showNewCustomerForm ? 'إخفاء النموذج' : 'إضافة عميل جديد' }}
                </button>

                <div v-if="showNewCustomerForm" class="p-4 bg-card border border-white/10 rounded-xl space-y-4">
                  <h3 class="font-bold">عميل جديد</h3>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input v-model="newCustomer.full_name" placeholder="الاسم الكامل" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    <input v-model="newCustomer.phone" placeholder="رقم الهاتف" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    <input v-model="newCustomer.passport_number" placeholder="رقم الجواز" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    <input v-model="newCustomer.date_of_birth" type="date" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                  <button type="button" @click="createNewCustomer" class="w-full py-2 bg-gold text-black rounded-xl font-bold hover:bg-gold/90">
                    حفظ العميل
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Step 2: البرنامج -->
          <section v-if="currentStep === 2" class="space-y-6">
            <div class="max-w-3xl mx-auto">
              <h2 class="text-xl font-bold mb-2">اختر البرنامج</h2>
              <p class="text-muted text-sm mb-8">البرامج تُدار عبر لوحة Filament وتُجلب آلياً.</p>

              <select v-model="form.program_id" @change="onProgramSelect"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none cursor-pointer">
                <option :value="null">اختر برنامج...</option>
                <option v-for="p in store.programs" :key="p.id" :value="p.id">
                  {{ p.program_name }} — {{ p.program_type === 'hajj' ? 'حج' : 'عمرة' }} ({{ p.total_nights }} ليلة)
                </option>
              </select>

              <div v-if="!store.programs.length" class="mt-4 p-4 bg-warning/10 border border-warning/30 rounded-xl text-warning text-sm">
                لا توجد برامج مفعّلة. أنشئ برنامجاً من Filament &gt; البرامج.
              </div>

              <div v-if="selectedProgram" class="mt-6 p-6 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-gold">{{ selectedProgram.program_name }}</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <Field label="النوع" :value="selectedProgram.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة'" />
                  <Field label="المدة" :value="`${selectedProgram.total_nights} ليلة`" />
                  <Field label="فندق مكة" :value="selectedProgram.mecca_hotel_name" />
                  <Field label="ليالي مكة" :value="selectedProgram.mecca_nights" />
                  <Field v-if="selectedProgram.medina_hotel_name" label="فندق المدينة" :value="selectedProgram.medina_hotel_name" />
                  <Field v-if="selectedProgram.medina_nights" label="ليالي المدينة" :value="selectedProgram.medina_nights" />
                  <Field label="الطيران" :value="selectedProgram.airline" />
                  <Field label="الشركة المنفذة" :value="selectedProgram.executing_company_label || selectedProgram.executing_company" />
                  <Field label="مشرف الرحلة" :value="selectedProgram.trip_supervisor_label || selectedProgram.trip_supervisor" />
                  <Field label="نوع التسكين" :value="selectedProgram.accommodation_label || selectedProgram.accommodation_type" />
                  <Field v-if="selectedProgram.departure_date" label="تاريخ السفر" :value="selectedProgram.departure_date" />
                  <Field v-if="selectedProgram.return_date" label="تاريخ العودة" :value="selectedProgram.return_date" />
                </div>
              </div>
            </div>
          </section>

          <!-- Step 3: المرافق -->
          <section v-if="currentStep === 3" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">مرافق</h2>
              <p class="text-muted text-sm mb-8">اختياري — أضف مرافق للحاج.</p>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50">
                <input v-model="needsCompanion" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold">يحتاج مرافق</div>
                  <div class="text-sm text-muted">أضف مرافق لهذا الحجز</div>
                </div>
              </label>

              <div v-if="needsCompanion" class="space-y-4 mt-4">
                <input v-model="companionSearch" type="text" placeholder="بحث عن المرافق..."
                  class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                  @input="onCompanionSearch" />

                <div v-if="filteredCompanions.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <button v-for="c in filteredCompanions" :key="c.id" type="button" @click="form.companion_customer_id = c.id; companionSearch = ''"
                    :class="['w-full p-4 rounded-xl text-right',
                      form.companion_customer_id === c.id ? 'bg-gold/20 border-2 border-gold' : 'bg-input border border-white/10 hover:border-gold/50']">
                    <div class="font-bold">{{ c.full_name || c.name }}</div>
                    <div class="text-sm text-muted">{{ c.phone }}</div>
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Step 4: التسعير -->
          <section v-if="currentStep === 4" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-6">
              <h2 class="text-xl font-bold text-center">التسعير والربح</h2>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر الشراء (التكلفة)</label>
                  <input v-model.number="form.purchase_price" type="number" min="0" step="0.01"
                    class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                </div>
                <div>
                  <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر البيع</label>
                  <input v-model.number="form.selling_price" type="number" min="0" step="0.01"
                    class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                </div>
              </div>

              <!-- تسعير سريع: هامش على التكلفة -->
              <div class="p-4 bg-card border border-white/10 rounded-2xl space-y-3">
                <div class="text-xs text-muted">تسعير سريع — هامش ربح على التكلفة</div>
                <div class="flex flex-wrap gap-2">
                  <button v-for="p in [20, 30, 50, 100]" :key="p" type="button" @click="applyMarkup(p)"
                    class="px-4 py-2 rounded-xl bg-white/5 hover:bg-gold/20 border border-white/10 text-sm transition-all">
                    +{{ p }}%
                  </button>
                </div>
                <div class="text-xs text-muted">سعر البيع = التكلفة × (1 + النسبة).</div>
              </div>

              <!-- بطاقة الربح -->
              <div class="p-6 bg-card border border-white/10 rounded-2xl flex items-center justify-between">
                <div>
                  <div class="text-sm text-muted uppercase tracking-widest">الربح</div>
                  <div class="text-2xl font-bold font-mono" :class="profitClass">
                    {{ formatMoney(profit) }}
                  </div>
                  <div v-if="marginPct !== null" class="text-xs text-muted mt-1">
                    نسبة الربح على التكلفة: {{ marginPct }}%
                  </div>
                </div>
                <div :class="['w-12 h-12 rounded-full flex items-center justify-center',
                  profit >= 0 ? 'bg-success/20 text-success' : 'bg-error/20 text-error']">
                  <TrendingUp v-if="profit >= 0" class="w-6 h-6" />
                  <TrendingDown v-else class="w-6 h-6" />
                </div>
              </div>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl">
                <input v-model="form.per_person" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold">السعر للفرد</div>
                  <div class="text-sm text-muted">السعر يُحسب لكل شخص على حدة</div>
                </div>
              </label>
            </div>
          </section>

          <!-- Step 5: الحساب والدفع -->
          <section v-if="currentStep === 5" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-6">
              <h2 class="text-xl font-bold mb-2">حساب التسوية والدفع الأولي</h2>
              <p class="text-muted text-sm">اختر الحساب الذي ستُسجَّل فيه القيود (إيراد البيع ومصروف التكلفة).</p>

              <div>
                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">حساب التسوية</label>
                <select v-model="form.account_id"
                  class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                  <option :value="null">اختر الحساب...</option>
                  <option v-for="a in store.accounts" :key="a.id" :value="a.id">
                    {{ a.name }} ({{ accountTypeLabel(a) }}) — {{ formatMoney(a.balance, a.currency || 'EGP') }}
                  </option>
                </select>
                <p class="text-xs text-muted mt-2">الحسابات تُدار من Filament &gt; الحسابات.</p>
              </div>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl">
                <input v-model="addPayment" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold">تسجيل دفعة أولية الآن؟</div>
                  <div class="text-sm text-muted">يمكنك تأجيلها وإضافتها لاحقاً من صفحة الحجز.</div>
                </div>
              </label>

              <div v-if="addPayment" class="space-y-4 p-6 bg-card border border-white/10 rounded-2xl">
                <div>
                  <label class="block text-xs text-muted mb-2 uppercase tracking-widest">المبلغ المدفوع الآن</label>
                  <input v-model.number="form.initial_payment.amount" type="number" min="0" step="0.01"
                    class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                  <div class="flex flex-wrap gap-2 mt-3">
                    <button v-for="p in [20, 25, 50, 75, 100]" :key="p" type="button" @click="setPaidPercent(p)"
                      class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-gold/20 border border-white/10 text-xs">
                      {{ p }}% من سعر البيع
                    </button>
                    <button type="button" @click="form.initial_payment.amount = 0"
                      class="px-3 py-1.5 rounded-lg bg-error/10 hover:bg-error/20 border border-error/30 text-xs text-error">
                      تصفير
                    </button>
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-muted mb-2">طريقة الدفع</label>
                    <select v-model="form.initial_payment.payment_method"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                      <option value="cash">نقدي</option>
                      <option value="bank_transfer">تحويل بنكي</option>
                      <option value="wallet">محفظة إلكترونية</option>
                      <option value="postal">بريد</option>
                      <option value="other">أخرى</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">تاريخ الدفع</label>
                    <input v-model="form.initial_payment.payment_date" type="date"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">رقم المرجع (اختياري)</label>
                    <input v-model="form.initial_payment.reference" type="text"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">المدفوع بواسطة</label>
                    <input v-model="form.initial_payment.paid_by" type="text"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                </div>
              </div>

              <div>
                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">اسم الموظف القائم بالحجز</label>
                <input v-model="form.agent_name" type="text"
                  class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                  placeholder="اسم الموظف" />
              </div>

              <div>
                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">ملاحظات</label>
                <textarea v-model="form.notes" rows="2"
                  class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"></textarea>
              </div>
            </div>
          </section>

          <!-- Step 6: مراجعة -->
          <section v-if="currentStep === 6" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-4 p-6 bg-card border border-white/10 rounded-2xl">
              <h2 class="text-xl font-bold">مراجعة الحجز</h2>
              <Row label="العميل" :value="form.customer?.full_name || form.customer?.name" />
              <Row label="البرنامج" :value="selectedProgram?.program_name" />
              <Row v-if="form.companion_customer_id" label="المرافق" :value="companionName" />
              <Row label="سعر الشراء" :value="formatMoney(form.purchase_price)" />
              <Row label="سعر البيع" :value="formatMoney(form.selling_price)" />
              <Row label="الربح" :value="formatMoney(profit)" :valueClass="profitClass" />
              <Row label="حساب التسوية" :value="selectedAccount?.name" />
              <Row v-if="addPayment && form.initial_payment.amount > 0" label="الدفعة الأولية"
                :value="formatMoney(form.initial_payment.amount)" valueClass="text-gold" />
            </div>
          </section>
        </div>
      </transition>
    </div>

    <!-- Navigation Buttons -->
    <div class="flex justify-between items-center pt-8 border-t border-white/10">
      <button v-if="currentStep > 1" type="button" @click="prevStep"
        class="px-8 py-3 rounded-xl bg-white/5 hover:bg-white/10 font-bold">السابق</button>
      <div v-else></div>

      <button v-if="currentStep < 6" type="button" @click="nextStep" :disabled="!isStepValid"
        class="px-10 py-3 rounded-xl bg-gold text-black font-bold hover:bg-gold/90 disabled:opacity-30 disabled:grayscale">
        التالي
      </button>

      <button v-else type="button" @click="saveBooking" :disabled="isSaving"
        class="px-10 py-3 rounded-xl bg-success text-white font-bold hover:bg-success/90 shadow-lg shadow-success/20 flex items-center gap-3">
        <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
        {{ isSaving ? 'جارٍ الحفظ...' : 'حفظ الحجز' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue';
import { useRouter } from 'vue-router';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { ArrowLeft, Check, Loader2, Search, Plus, TrendingUp, TrendingDown } from 'lucide-vue-next';

const store = useHajjUmraStore();
const router = useRouter();

const currentStep = ref(1);
const isSaving = ref(false);
const customerSearch = ref('');
const companionSearch = ref('');
const showNewCustomerForm = ref(false);
const needsCompanion = ref(false);
const addPayment = ref(false);

const newCustomer = ref({ full_name: '', phone: '', passport_number: '', date_of_birth: '' });

const form = ref({
  customer: null,
  program_id: null,
  companion_customer_id: null,
  purchase_price: 0,
  selling_price: 0,
  currency: 'EGP',
  per_person: true,
  status: 'confirmed',
  account_id: null,
  agent_name: '',
  notes: '',
  initial_payment: {
    amount: 0,
    payment_method: 'cash',
    payment_date: new Date().toISOString().split('T')[0],
    reference: '',
    paid_by: '',
  },
});

const steps = [
  { id: 1, title: 'العميل', label: 'اختيار العميل' },
  { id: 2, title: 'البرنامج', label: 'اختيار البرنامج' },
  { id: 3, title: 'المرافق', label: 'إضافة مرافق' },
  { id: 4, title: 'التسعير', label: 'الشراء والبيع' },
  { id: 5, title: 'الدفع', label: 'الحساب والدفع الأولي' },
  { id: 6, title: 'الملخص', label: 'مراجعة نهائية' },
];

const filteredCustomers = computed(() => {
  if (!customerSearch.value) return [];
  const q = customerSearch.value.toLowerCase();
  return store.customers.filter((c) =>
    (c.full_name || c.name)?.toLowerCase().includes(q) || c.phone?.includes(q),
  );
});

const filteredCompanions = computed(() => {
  if (!companionSearch.value) return [];
  const q = companionSearch.value.toLowerCase();
  return store.customers.filter((c) =>
    (c.full_name || c.name)?.toLowerCase().includes(q) || c.phone?.includes(q),
  );
});

const selectedProgram = computed(() => store.programs.find((p) => p.id === form.value.program_id));
const selectedAccount = computed(() => store.accounts.find((a) => a.id === form.value.account_id));
const companionName = computed(() => {
  const c = store.customers.find((x) => x.id === form.value.companion_customer_id);
  return c?.full_name || c?.name || '';
});

const profit = computed(() =>
  Math.round(((form.value.selling_price || 0) - (form.value.purchase_price || 0)) * 100) / 100,
);
const profitClass = computed(() => (profit.value >= 0 ? 'text-success' : 'text-error'));
const marginPct = computed(() => {
  const c = Number(form.value.purchase_price) || 0;
  if (c <= 0) return null;
  return Math.round((profit.value / c) * 10000) / 100;
});

const isStepValid = computed(() => {
  switch (currentStep.value) {
    case 1: return !!form.value.customer;
    case 2: return !!form.value.program_id;
    case 3: return !needsCompanion.value || !!form.value.companion_customer_id;
    case 4: return form.value.purchase_price >= 0 && form.value.selling_price >= 0 && form.value.selling_price > 0;
    case 5: return !!form.value.account_id;
    default: return true;
  }
});

function formatMoney(n, curr = 'EGP') {
  const num = Number(n) || 0;
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(num);
}

function accountTypeLabel(a) {
  const map = { cashbox: 'صندوق نقدية', bank: 'بنك', wallet: 'محفظة', treasury: 'خزينة', customer: 'عميل', supplier: 'مورّد' };
  return map[a.type] || a.type || '-';
}

function applyMarkup(pct) {
  const c = Number(form.value.purchase_price) || 0;
  if (c <= 0) {
    store.addToast('أدخل تكلفة أولاً', 'error');
    return;
  }
  form.value.selling_price = Math.round(c * (1 + pct / 100) * 100) / 100;
}

function setPaidPercent(pct) {
  const sp = Number(form.value.selling_price) || 0;
  if (sp <= 0) {
    store.addToast('أدخل سعر البيع أولاً', 'error');
    return;
  }
  form.value.initial_payment.amount = Math.round((sp * pct) / 100 * 100) / 100;
}

function onCustomerSearch() {
  if (customerSearch.value.length >= 2) store.fetchCustomers(customerSearch.value);
}

function onCompanionSearch() {
  if (companionSearch.value.length >= 2) store.fetchCustomers(companionSearch.value);
}

function selectCustomer(c) {
  form.value.customer = c;
  form.value.agent_name ||= c.full_name || c.name || '';
  form.value.initial_payment.paid_by = c.full_name || c.name || '';
  customerSearch.value = '';
}

async function createNewCustomer() {
  if (!newCustomer.value.full_name?.trim() || !newCustomer.value.phone?.trim()) {
    store.addToast('الاسم والهاتف مطلوبان', 'error');
    return;
  }
  try {
    const c = await store.createCustomer(newCustomer.value);
    selectCustomer(c);
    showNewCustomerForm.value = false;
    newCustomer.value = { full_name: '', phone: '', passport_number: '', date_of_birth: '' };
    store.addToast('تم إضافة العميل');
  } catch (e) {
    store.addToast('فشل إضافة العميل', 'error');
  }
}

function onProgramSelect() {
  const p = selectedProgram.value;
  if (!p) return;
  if ((!form.value.purchase_price || form.value.purchase_price <= 0) && p.default_purchase_price > 0) {
    form.value.purchase_price = p.default_purchase_price;
  }
  if ((!form.value.selling_price || form.value.selling_price <= 0) && p.default_selling_price > 0) {
    form.value.selling_price = p.default_selling_price;
  }
}

function nextStep() {
  if (currentStep.value < 6 && isStepValid.value) {
    currentStep.value++;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

function prevStep() {
  if (currentStep.value > 1) {
    currentStep.value--;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

async function saveBooking() {
  isSaving.value = true;
  try {
    const payload = {
      customer_id: form.value.customer.id,
      companion_customer_id: form.value.companion_customer_id || null,
      program_id: form.value.program_id,
      purchase_price: Number(form.value.purchase_price) || 0,
      selling_price: Number(form.value.selling_price) || 0,
      currency: form.value.currency,
      per_person: !!form.value.per_person,
      status: form.value.status,
      account_id: form.value.account_id,
      agent_name: form.value.agent_name?.trim() || form.value.customer?.full_name || '',
      notes: form.value.notes?.trim() || null,
    };

    if (addPayment.value && Number(form.value.initial_payment.amount) > 0) {
      payload.initial_payment = {
        amount: Number(form.value.initial_payment.amount),
        payment_method: form.value.initial_payment.payment_method,
        account_id: form.value.account_id,
        payment_date: form.value.initial_payment.payment_date,
        reference: form.value.initial_payment.reference || null,
        paid_by: form.value.initial_payment.paid_by || form.value.customer?.full_name || '',
      };
    }

    const created = await store.createBooking(payload);
    store.addToast('تم إنشاء الحجز بنجاح');
    router.push({ name: 'hajj.show', params: { id: created.id } }).catch(() => router.push({ name: 'hajj.index' }));
  } catch (e) {
    console.error(e);
    store.addToast(e.response?.data?.message || 'فشل حفظ الحجز', 'error');
  } finally {
    isSaving.value = false;
  }
}

onMounted(async () => {
  await Promise.all([store.fetchSettings(), store.fetchAccounts()]);
});

const Field = (props) =>
  h('div', null, [
    h('span', { class: 'text-muted ml-2' }, props.label + ':'),
    h('span', { class: 'font-bold' }, String(props.value ?? '-')),
  ]);
Field.props = ['label', 'value'];

const Row = (props) =>
  h('div', { class: 'flex justify-between items-center pb-3 border-b border-white/10 last:border-0' }, [
    h('span', { class: 'text-muted' }, props.label),
    h('span', { class: `font-bold font-mono ${props.valueClass || ''}` }, String(props.value ?? '-')),
  ]);
Row.props = ['label', 'value', 'valueClass'];
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning, #f59e0b); }
.bg-success { background-color: var(--success); }
.border-warning { border-color: var(--warning, #f59e0b); }
.bg-warning { background-color: var(--warning, #f59e0b); }

.step-enter-active, .step-leave-active { transition: all 0.4s ease; }
.step-enter-from { opacity: 0; transform: translateX(30px); }
.step-leave-to { opacity: 0; transform: translateX(-30px); }
</style>
