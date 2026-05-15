<template>
  <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">طلب تأشيرة جديد</h1>
        <p class="text-muted mt-1">{{ steps[currentStep - 1].label }}</p>
      </div>
      <router-link :to="{ name: 'visa.index' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> إلغاء
      </router-link>
    </div>

    <!-- Step Indicator -->
    <div class="relative px-4 py-8 bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl">
      <div class="flex justify-between relative z-10">
        <div v-for="step in steps" :key="step.id" class="flex flex-col items-center gap-3 flex-1 relative">
          <div v-if="step.id < steps.length"
            :class="['absolute top-5 left-1/2 w-full h-[2px] -z-10 transition-colors duration-500',
              currentStep > step.id ? 'bg-success' : 'bg-white/10']">
          </div>
          <div :class="[
            'w-10 h-10 rounded-full flex items-center justify-center transition-all duration-500 border-2',
            currentStep === step.id ? 'bg-gold border-gold text-black shadow-[0_0_20px_rgba(212,168,67,0.4)] scale-110' :
              currentStep > step.id ? 'bg-success border-success text-white' : 'bg-input border-white/10 text-muted'
          ]">
            <Check v-if="currentStep > step.id" class="w-5 h-5" />
            <span v-else class="font-bold">{{ step.id }}</span>
          </div>
          <span :class="['text-[10px] font-bold uppercase tracking-widest transition-colors duration-500',
            currentStep === step.id ? 'text-gold' : 'text-muted']">
            {{ step.title }}
          </span>
        </div>
      </div>
    </div>

    <!-- Step Content -->
    <div class="min-h-[400px]">
      <transition name="step" mode="out-in">
        <div :key="currentStep" class="space-y-8">

          <!-- Step 1: Customer -->
          <div v-if="currentStep === 1" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">لمن هذا الطلب؟</h2>
              <p class="text-muted text-sm mb-8">البحث عن عميل موجود أو إنشاء عميل جديد.</p>

              <div class="space-y-4">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
                  <input v-model="customerSearch" type="text" placeholder="ابحث بالاسم أو رقم الهاتف..."
                    class="w-full pl-10 pr-4 py-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                    @input="searchCustomers" />
                </div>

                <div v-if="store.loading.customers" class="text-center py-8">
                  <div class="animate-spin w-8 h-8 border-2 border-gold border-t-transparent rounded-full mx-auto"></div>
                </div>

                <div v-else-if="filteredCustomers.length > 0" class="space-y-2 max-h-64 overflow-y-auto">
                  <button v-for="customer in filteredCustomers" :key="customer.id"
                    @click="selectCustomer(customer)"
                    :class="['w-full p-4 rounded-xl text-right transition-all', form.customer?.id === customer.id ? 'bg-gold/20 border-2 border-gold' : 'bg-input border border-white/10 hover:border-gold/50']">
                    <div class="font-bold">{{ customer.full_name || customer.name }}</div>
                    <div class="text-sm text-muted">{{ customer.phone }}</div>
                  </button>
                </div>

                <button @click="showNewCustomerForm = true"
                  class="w-full p-4 bg-white/5 border border-dashed border-white/20 rounded-xl text-muted hover:border-gold hover:text-gold transition-all flex items-center justify-center gap-2">
                  <Plus class="w-4 h-4" /> إضافة عميل جديد
                </button>

                <div v-if="showNewCustomerForm" class="p-4 bg-card border border-white/10 rounded-xl space-y-4">
                  <h3 class="font-bold">عميل جديد</h3>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-muted mb-2">الاسم الكامل *</label>
                      <input v-model="newCustomer.full_name" type="text"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">رقم الهاتف *</label>
                      <input v-model="newCustomer.phone" type="text"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">رقم الجواز</label>
                      <input v-model="newCustomer.passport_number" type="text"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">تاريخ الميلاد</label>
                      <input v-model="newCustomer.date_of_birth" type="date"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                  </div>
                  <div class="flex gap-2">
                    <button @click="createNewCustomer"
                      class="flex-1 py-2 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all">
                      إضافة العميل
                    </button>
                    <button @click="showNewCustomerForm = false"
                      class="flex-1 py-2 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all">
                      إلغاء
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 2: Visa Details -->
          <div v-if="currentStep === 2" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">تفاصيل التأشيرة</h2>
              <p class="text-muted text-sm mb-8">أدخل البيانات المطلوبة. الوكيل والمدّة من الإعدادات في فيلامنت.</p>

              <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-muted mb-2">نوع التأشيرة *</label>
                    <select v-model="form.visa_details.visa_type"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
                      <option v-for="t in (store.statuses?.visa_types || [])" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">الدولة *</label>
                    <input v-model="form.visa_details.country" type="text"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>

                  <div>
                    <label class="block text-xs text-muted mb-2">المدّة (إعدادات)</label>
                    <select v-model="form.visa_details.visa_duration_id"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                      <option :value="null">— اختر —</option>
                      <option v-for="d in store.durations" :key="d.id" :value="d.id">
                        {{ d.label }}{{ d.days ? ` (${d.days} يوم)` : '' }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">نوع الدخول</label>
                    <select v-model="form.visa_details.entry_type"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
                      <option v-for="e in (store.statuses?.visa_entry_types || [])" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                  </div>

                  <div class="md:col-span-2">
                    <label class="block text-xs text-muted mb-2">وكيل التأشيرة (إعدادات)</label>
                    <select v-model="form.visa_details.visa_agent_id"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                      <option :value="null">— اختر —</option>
                      <option v-for="a in store.agents" :key="a.id" :value="a.id">
                        {{ a.name }}{{ a.phone ? ` — ${a.phone}` : '' }}
                      </option>
                    </select>
                  </div>

                  <div>
                    <label class="block text-xs text-muted mb-2">تاريخ التقديم</label>
                    <input v-model="form.visa_details.submission_date" type="date"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">تاريخ النتيجة المتوقع</label>
                    <input v-model="form.visa_details.expected_result_date" type="date"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 3: Pricing -->
          <div v-if="currentStep === 3" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold text-center mb-2">التسعير والربح</h2>
              <p class="text-muted text-sm text-center mb-6">سعر الشراء = تكلفة التأشيرة من الوكيل، سعر البيع = ما تتقاضاه من العميل.</p>

              <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                  <div>
                    <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر الشراء</label>
                    <input v-model.number="form.purchase_price" type="number" min="0" step="0.01"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر البيع</label>
                    <input v-model.number="form.selling_price" type="number" min="0" step="0.01"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2 uppercase tracking-widest">رسوم الخدمة</label>
                    <input v-model.number="form.service_fee" type="number" min="0" step="0.01"
                      class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                  </div>
                </div>

                <!-- Quick markup buttons -->
                <div class="p-4 bg-input/40 border border-white/10 rounded-xl space-y-3">
                  <div class="text-xs text-muted">تسعير سريع — هامش ربح على سعر الشراء:</div>
                  <div class="flex flex-wrap gap-2">
                    <button v-for="p in markupPresets" :key="p"
                      @click="applyMarkup(p)"
                      class="px-4 py-2 bg-white/5 hover:bg-gold/20 border border-white/10 hover:border-gold rounded-lg text-sm font-bold transition-all">
                      +{{ p }}%
                    </button>
                  </div>
                </div>

                <div class="p-6 bg-card border border-white/10 rounded-xl">
                  <div class="flex items-center justify-between">
                    <div>
                      <div class="text-sm text-muted uppercase tracking-widest">صافي الربح</div>
                      <div class="text-2xl font-bold font-mono" :class="profitClass">
                        {{ profitAmount.toLocaleString() }} ج.م
                      </div>
                      <div class="text-xs text-muted mt-1">
                        نسبة الربح على التكلفة: {{ markupPercentage }}%
                      </div>
                    </div>
                    <div :class="['w-12 h-12 rounded-full flex items-center justify-center', profitAmount >= 0 ? 'bg-success/20 text-success' : 'bg-error/20 text-error']">
                      <TrendingUp v-if="profitAmount >= 0" class="w-6 h-6" />
                      <TrendingDown v-else class="w-6 h-6" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 4: Account & Initial Payment -->
          <div v-if="currentStep === 4" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">حساب التسوية والدفعة الأولى</h2>
              <p class="text-muted text-sm mb-8">حدّد الحساب الذي سيُسوّى عليه الطلب، ودفعة أولى اختيارية.</p>

              <div class="space-y-6">
                <div>
                  <label class="block text-sm font-semibold text-white mb-2">نوع حساب التحصيل</label>
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

                  <label class="block text-xs text-muted mb-2">حساب التسوية *</label>
                  <select v-model="form.account_id"
                    class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                    <option :value="null">— اختر حساب —</option>
                    <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">
                      {{ acc.name }} — {{ acc.code }}
                    </option>
                  </select>
                  <p v-if="filteredAccounts.length === 0" class="text-xs text-warning mt-2">
                    لا توجد حسابات متاحة في هذا التصنيف.
                  </p>
                </div>

                <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50 transition-all">
                  <input v-model="addPayment" type="checkbox" class="w-5 h-5 rounded" />
                  <div>
                    <div class="font-bold">إضافة دفعة أولية</div>
                    <div class="text-sm text-muted">تسجيل دفعة الآن — أو لاحقاً.</div>
                  </div>
                </label>

                <div v-if="addPayment" class="space-y-4 p-6 bg-card border border-white/10 rounded-xl">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-muted mb-2">طريقة الدفع</label>
                      <select v-model="form.initial_payment.payment_method"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                        <option value="cash">نقدي</option>
                        <option value="bank_transfer">تحويل بنكي</option>
                        <option value="cash_wallet">محفظة كاش</option>
                        <option value="postal_transfer">تحويل بريدي</option>
                        <option value="instapay">إنستاباي</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">حساب الإيداع</label>
                      <select v-model="form.initial_payment.account_id"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                        <option :value="null">— نفس حساب التسوية —</option>
                        <option v-for="acc in store.accounts" :key="acc.id" :value="acc.id">
                          {{ acc.name }} — {{ acc.code }}
                        </option>
                      </select>
                    </div>
                    <div class="md:col-span-2">
                      <label class="block text-xs text-muted mb-2">المبلغ</label>
                      <input v-model.number="form.initial_payment.amount" type="number" min="0" step="0.01"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
                      <div class="flex flex-wrap gap-2 mt-3">
                        <button v-for="p in paymentPresets" :key="p"
                          @click="applyPaymentPercent(p)"
                          class="px-3 py-1.5 bg-white/5 hover:bg-gold/20 border border-white/10 hover:border-gold rounded-lg text-xs font-bold transition-all">
                          {{ p }}%
                        </button>
                      </div>
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">تاريخ الدفع</label>
                      <input v-model="form.initial_payment.payment_date" type="date"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2">المرجع/المرسِل</label>
                      <input v-model="form.initial_payment.paid_by" type="text"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
                    </div>
                  </div>
                </div>

                <div>
                  <label class="block text-xs text-muted mb-2">ملاحظات</label>
                  <textarea v-model="form.notes" rows="3"
                    class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 5: Summary -->
          <div v-if="currentStep === 5" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-4">مراجعة الطلب</h2>

              <div class="space-y-3 p-6 bg-card border border-white/10 rounded-xl">
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">العميل</span>
                  <span class="font-bold">{{ form.customer?.full_name || form.customer?.name }}</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">الدولة / النوع</span>
                  <span class="font-bold">{{ form.visa_details.country }} — {{ visaTypeLabel }}</span>
                </div>
                <div v-if="agentName" class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">الوكيل</span>
                  <span class="font-bold">{{ agentName }}</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">سعر الشراء</span>
                  <span class="font-bold font-mono">{{ Number(form.purchase_price || 0).toLocaleString() }} ج.م</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">سعر البيع</span>
                  <span class="font-bold font-mono">{{ Number(form.selling_price || 0).toLocaleString() }} ج.م</span>
                </div>
                <div v-if="form.service_fee" class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">رسوم الخدمة</span>
                  <span class="font-bold font-mono">{{ Number(form.service_fee).toLocaleString() }} ج.م</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">صافي الربح</span>
                  <span class="font-bold font-mono" :class="profitClass">{{ profitAmount.toLocaleString() }} ج.م</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-white/10">
                  <span class="text-muted">حساب التسوية</span>
                  <span class="font-bold">{{ accountName }}</span>
                </div>
                <div v-if="addPayment" class="flex justify-between items-center">
                  <span class="text-muted">الدفعة الأولى</span>
                  <span class="font-bold font-mono text-gold">{{ Number(form.initial_payment.amount || 0).toLocaleString() }} ج.م</span>
                </div>
              </div>
            </div>
          </div>

        </div>
      </transition>
    </div>

    <!-- Navigation Buttons -->
    <div class="flex justify-between items-center pt-8 border-t border-white/10">
      <button v-if="currentStep > 1" @click="prevStep"
        class="px-8 py-3 rounded-xl bg-white/5 hover:bg-white/10 font-bold transition-all">
        السابق
      </button>
      <div v-else></div>

      <button v-if="currentStep < 5" @click="nextStep" :disabled="!isStepValid"
        class="px-10 py-3 rounded-xl bg-gold text-black font-bold hover:bg-gold/90 transition-all disabled:opacity-30 disabled:grayscale">
        التالي
      </button>

      <button v-else @click="saveBooking" :disabled="isSaving"
        class="px-10 py-3 rounded-xl bg-success text-white font-bold hover:bg-success/90 transition-all shadow-lg shadow-success/20 flex items-center gap-3">
        <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
        {{ isSaving ? 'جاري الحفظ...' : 'حفظ الطلب' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useVisaStore } from '@/stores/visaStore';
import { useRouter } from 'vue-router';
import { 
  ArrowLeft, Check, Loader2, Search, Plus, TrendingUp, TrendingDown,
  Banknote, Wallet, Landmark
} from 'lucide-vue-next';

const store = useVisaStore();
const router = useRouter();

const currentStep = ref(1);
const isSaving = ref(false);
const customerSearch = ref('');
const showNewCustomerForm = ref(false);
const addPayment = ref(false);

const settlementCategoryUi = ref('cash');
const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

const filteredAccounts = computed(() => {
  const accounts = store.accounts || [];
  if (settlementCategoryUi.value === 'cash') {
    return accounts.filter(a => a.type === 'cashbox' || a.type === 'treasury');
  }
  if (settlementCategoryUi.value === 'wallet') {
    return accounts.filter(a => a.type === 'wallet');
  }
  if (settlementCategoryUi.value === 'bank') {
    return accounts.filter(a => a.type === 'bank');
  }
  return accounts;
});

const newCustomer = ref({
  full_name: '',
  phone: '',
  passport_number: '',
  date_of_birth: ''
});

const form = ref({
  customer: null,
  visa_details: {
    visa_type: 'tourist',
    country: '',
    visa_duration_id: null,
    duration: '',
    entry_type: 'single',
    visa_agent_id: null,
    submission_date: new Date().toISOString().split('T')[0],
    expected_result_date: ''
  },
  purchase_price: 0,
  selling_price: 0,
  service_fee: 0,
  currency: 'EGP',
  account_id: null,
  agent_name: '',
  notes: '',
  initial_payment: {
    payment_method: 'cash',
    amount: 0,
    account_id: null,
    payment_date: new Date().toISOString().split('T')[0],
    paid_by: ''
  }
});

const steps = [
  { id: 1, title: 'العميل', label: 'اختيار عميل' },
  { id: 2, title: 'التأشيرة', label: 'تفاصيل التأشيرة' },
  { id: 3, title: 'التسعير', label: 'تحديد السعر' },
  { id: 4, title: 'الدفع', label: 'حساب التسوية والدفعة الأولى' },
  { id: 5, title: 'الملخص', label: 'مراجعة نهائية' }
];

const markupPresets = [20, 30, 50, 100];
const paymentPresets = [25, 50, 75, 100];

const filteredCustomers = computed(() => {
  if (!customerSearch.value) return store.customers.slice(0, 10);
  const q = customerSearch.value.toLowerCase();
  return store.customers.filter(c =>
    (c.full_name || c.name || '').toLowerCase().includes(q) ||
    (c.phone || '').includes(customerSearch.value)
  );
});

const profitAmount = computed(() => {
  return Number(form.value.selling_price || 0) + Number(form.value.service_fee || 0) - Number(form.value.purchase_price || 0);
});

const profitClass = computed(() => profitAmount.value >= 0 ? 'text-success' : 'text-error');

const markupPercentage = computed(() => {
  const cost = Number(form.value.purchase_price || 0);
  if (!cost) return 0;
  return Math.round((profitAmount.value / cost) * 100);
});

const visaTypeLabel = computed(() => {
  const found = (store.statuses?.visa_types || []).find(t => t.value === form.value.visa_details.visa_type);
  return found?.label || form.value.visa_details.visa_type;
});

const agentName = computed(() => {
  const a = store.agents.find(x => x.id === form.value.visa_details.visa_agent_id);
  return a?.name || '';
});

const accountName = computed(() => {
  const a = store.accounts.find(x => x.id === form.value.account_id);
  return a ? `${a.name} — ${a.code}` : '—';
});

const isStepValid = computed(() => {
  switch (currentStep.value) {
    case 1: return !!form.value.customer;
    case 2: return !!form.value.visa_details.country && !!form.value.visa_details.visa_type;
    case 3: return form.value.purchase_price >= 0 && form.value.selling_price >= 0;
    case 4: return !!form.value.account_id && (!addPayment.value || (form.value.initial_payment.amount > 0));
    default: return true;
  }
});

const searchCustomers = () => {
  if (customerSearch.value.length >= 2) {
    store.fetchCustomers(customerSearch.value);
  }
};

const selectCustomer = (customer) => {
  form.value.customer = customer;
  form.value.initial_payment.paid_by = customer.full_name || customer.name;
  customerSearch.value = '';
};

const createNewCustomer = async () => {
  if (!newCustomer.value.full_name || !newCustomer.value.phone) {
    store.addToast('الاسم ورقم الهاتف مطلوبان', 'error');
    return;
  }
  try {
    const customer = await store.createCustomer(newCustomer.value);
    form.value.customer = customer;
    form.value.initial_payment.paid_by = customer.full_name || customer.name;
    showNewCustomerForm.value = false;
    newCustomer.value = { full_name: '', phone: '', passport_number: '', date_of_birth: '' };
    store.addToast('تم إضافة العميل بنجاح');
  } catch (error) {
    store.addToast('فشل إضافة العميل', 'error');
  }
};

const applyMarkup = (percent) => {
  const cost = Number(form.value.purchase_price || 0);
  form.value.selling_price = Math.round(cost * (1 + percent / 100) * 100) / 100;
};

const applyPaymentPercent = (percent) => {
  const sp = Number(form.value.selling_price || 0) + Number(form.value.service_fee || 0);
  form.value.initial_payment.amount = Math.round(sp * (percent / 100) * 100) / 100;
};

const nextStep = () => {
  if (currentStep.value < 5 && isStepValid.value) {
    currentStep.value++;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
};

const prevStep = () => {
  if (currentStep.value > 1) {
    currentStep.value--;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
};

const buildPayload = () => {
  const f = form.value;
  const payload = {
    customer_id: f.customer?.id || null,
    visa_details: {
      visa_type: f.visa_details.visa_type,
      country: f.visa_details.country,
      visa_duration_id: f.visa_details.visa_duration_id || null,
      entry_type: f.visa_details.entry_type,
      visa_agent_id: f.visa_details.visa_agent_id || null,
      submission_date: f.visa_details.submission_date || null,
      expected_result_date: f.visa_details.expected_result_date || null,
    },
    purchase_price: Number(f.purchase_price || 0),
    selling_price: Number(f.selling_price || 0),
    service_fee: Number(f.service_fee || 0),
    currency: f.currency || 'EGP',
    account_id: f.account_id,
    agent_name: f.agent_name || (agentName.value || 'Admin'),
    notes: f.notes || null,
  };
  if (addPayment.value && Number(f.initial_payment.amount || 0) > 0) {
    payload.initial_payment = {
      amount: Number(f.initial_payment.amount),
      payment_method: f.initial_payment.payment_method,
      account_id: f.initial_payment.account_id || f.account_id,
      payment_date: f.initial_payment.payment_date,
      paid_by: f.initial_payment.paid_by || null,
    };
  }
  return payload;
};

const saveBooking = async () => {
  isSaving.value = true;
  try {
    const created = await store.createBooking(buildPayload());
    store.addToast('تم إنشاء الطلب بنجاح!');
    if (created?.id) {
      await router.push({ name: 'visa.show', params: { id: created.id } });
    } else {
      await router.push({ name: 'visa.index' });
    }
  } catch (error) {
    console.error('Failed to save booking', error);
    const msg = store.errors?.message || 'فشل حفظ الطلب. يرجى التحقق من المدخلات.';
    store.addToast(msg, 'error');
  } finally {
    isSaving.value = false;
  }
};

onMounted(async () => {
  await Promise.all([
    store.fetchSettings(),
    store.fetchAccounts({ module: 'visa' }),
    store.fetchCustomers(),
  ]);
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }

.step-enter-active, .step-leave-active { transition: all 0.4s ease; }
.step-enter-from { opacity: 0; transform: translateX(30px); }
.step-leave-to { opacity: 0; transform: translateX(-30px); }
</style>
