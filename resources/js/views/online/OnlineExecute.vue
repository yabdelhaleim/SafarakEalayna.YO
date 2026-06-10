<template>
  <div class="online-transaction-view mx-auto max-w-5xl space-y-8 pb-16">
    <header class="flight-hero relative !from-violet-900/90 !to-slate-900/90">
      <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <router-link
            to="/online"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة للقائمة"
          >
            <ArrowRight class="h-5 w-5 text-violet-300/90" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-violet-400/90">الخدمات الإلكترونية</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">تنفيذ معاملة جديدة</h1>
            <p class="mt-2 text-sm text-text-muted">قم بتسجيل بيانات العملية والتحصيل المالي بدقة لضمان توازن الحسابات.</p>
          </div>
        </div>
      </div>
    </header>

    <form class="space-y-6" @submit.prevent="submit">
      <div class="flight-panel">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-violet-500/10 rounded-lg">
            <Globe class="w-5 h-5 text-violet-400" />
          </div>
          <h2 class="flight-panel__title !mb-0">الخدمة والمزود</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">نوع الخدمة <span class="text-error">*</span></label>
            <select
              v-model="form.service_type_id"
              required
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none cursor-pointer text-text-main"
            >
              <option :value="null" class="bg-card-bg">— اختر نوع الخدمة —</option>
              <option v-for="t in store.activeServiceTypes" :key="t.id" :value="t.id" class="bg-card-bg">
                {{ t.name_ar || t.name || t.code }}
              </option>
            </select>
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">المزود</label>
            <select
              v-model="form.provider_id"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none cursor-pointer text-text-main"
            >
              <option :value="null" class="bg-card-bg">— بدون مزود —</option>
              <option v-for="p in store.activeProviders" :key="p.id" :value="p.id" class="bg-card-bg">
                {{ p.name_ar || p.name || p.code }}
              </option>
            </select>
          </div>
        </div>
      </div>

      <div class="flight-panel">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-blue-500/10 rounded-lg">
            <User class="w-5 h-5 text-blue-400" />
          </div>
          <h2 class="flight-panel__title !mb-0">بيانات العميل</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">العميل المسجّل</label>
            <select
              v-model="form.customer_id"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none cursor-pointer text-text-main"
              @change="onCustomerSelected"
            >
              <option :value="null" class="bg-card-bg">— بدون اختيار (عميل جديد) —</option>
              <option v-for="c in store.customers" :key="c.id" :value="c.id" class="bg-card-bg">
                {{ c.name }} {{ c.phone ? `— ${c.phone}` : '' }}
              </option>
            </select>
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">اسم العميل <span class="text-error">*</span></label>
            <input
              v-model="form.customer_name"
              type="text"
              :readonly="!!form.customer_id"
              :required="!form.customer_id"
              placeholder="مثال: حسين علي"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none text-text-main read-only:opacity-50"
            />
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">رقم التليفون</label>
            <input
              v-model="form.customer_phone"
              type="text"
              :readonly="!!form.customer_id"
              placeholder="مثال: 01024607766"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-violet-500/50 outline-none text-text-main read-only:opacity-50"
            />
          </div>
        </div>
      </div>

      <div class="flight-panel">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-success/10 rounded-lg">
            <Banknote class="w-5 h-5 text-success" />
          </div>
          <h2 class="flight-panel__title !mb-0">التسعير والربح</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">سعر الشراء (التكلفة) <span class="text-error">*</span></label>
            <div class="relative">
              <input
                v-model.number="form.purchase_price"
                type="number"
                min="0"
                step="0.01"
                required
                class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-violet-500/50 outline-none text-text-main"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
            </div>
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">سعر البيع <span class="text-error">*</span></label>
            <div class="relative">
              <input
                v-model.number="form.selling_price"
                type="number"
                min="0"
                step="0.01"
                required
                class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-violet-500/50 outline-none text-text-main"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
            </div>
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">الربح الصافي</label>
            <div class="flex h-[46px] items-center justify-between rounded-xl border border-success/20 bg-success/10 px-4">
              <span class="text-sm font-black text-success font-mono">{{ formatMoney(profit) }}</span>
              <TrendingUp class="h-4 w-4 text-success" />
            </div>
          </div>
        </div>
      </div>

      <div class="flight-panel">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-amber-500/10 rounded-lg">
            <CreditCard class="w-5 h-5 text-amber-400" />
          </div>
          <h2 class="flight-panel__title !mb-0">طريقة التحصيل</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">حساب التحصيل (الخزينة) <span class="text-error">*</span></label>
            <select
              v-model="form.account_id"
              required
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none cursor-pointer text-text-main"
            >
              <option :value="null" class="bg-card-bg">— اختر الحساب —</option>
              <option v-for="a in store.accounts" :key="a.id" :value="a.id" class="bg-card-bg">
                {{ a.name }} — رصيد ({{ formatMoney(a.balance) }})
              </option>
            </select>
          </div>

          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">طريقة الدفع <span class="text-error">*</span></label>
            <select
              v-model="form.payment_method"
              required
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none cursor-pointer text-text-main"
            >
              <option value="" class="bg-card-bg">— اختر طريقة الدفع —</option>
              <option v-for="m in store.paymentMethods" :key="m.code" :value="m.code" class="bg-card-bg">
                {{ m.name_ar || m.label }}
              </option>
            </select>
          </div>
        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-text-muted">المبلغ المدفوع حالياً <span class="text-error">*</span></label>
            <div class="relative">
              <input
                v-model.number="form.amount_paid"
                type="number"
                min="0"
                step="0.01"
                required
                class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-violet-500/50 outline-none text-text-main"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-text-muted">ج.م</span>
            </div>
            <!-- Quick amounts -->
            <div class="mt-2 flex gap-2">
              <button
                v-for="pct in [25, 50, 75, 100]"
                :key="pct"
                type="button"
                @click="setPaidPercent(pct)"
                class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-xs text-text-muted hover:border-violet-500/40 hover:text-violet-400 transition"
              >
                {{ pct }}٪
              </button>
            </div>
          </div>

          <div class="space-y-2 flex flex-col justify-end">
            <!-- Debt Breakdown Live Preview -->
            <div
              v-if="form.selling_price"
              class="rounded-xl p-4 border border-violet-500/20 bg-violet-500/5 text-xs text-text-muted space-y-1"
            >
              <p>
                إجمالي المطلوب من العميل:
                <strong class="text-text-main font-mono">{{ formatMoney(form.selling_price) }}</strong>
              </p>
              <p>
                المدفوع نقداً:
                <strong class="text-emerald-400 font-mono">{{ formatMoney(form.amount_paid || 0) }}</strong>
              </p>
              <p>
                المتبقي (آجل):
                <strong class="text-red-400 font-mono">{{ formatMoney(form.selling_price - (form.amount_paid || 0)) }}</strong>
              </p>
              <p v-if="!form.customer_id" class="text-amber-400 text-[10px] font-bold mt-1">
                ⚠️ تنبيه: لم يتم اختيار عميل مسجل. سيتم اعتبار المعاملة نقدية بالكامل ولن تُسجل مديونية.
              </p>
            </div>
          </div>
        </div>

        <div class="mt-6 space-y-2">
          <label class="text-xs font-bold text-text-muted">رقم مرجع / ملاحظات</label>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
             <input
              v-model="form.reference_number"
              type="text"
              placeholder="رقم المرجع (اختياري)"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm font-mono focus:border-violet-500/50 outline-none text-text-main"
            />
            <input
              v-model="form.notes"
              type="text"
              placeholder="ملاحظات إضافية"
              class="w-full px-4 py-3 bg-white/[0.03] border border-white/10 rounded-xl text-sm focus:border-violet-500/50 outline-none text-text-main"
            />
          </div>
        </div>
      </div>

      <div class="flex gap-4">
        <button
          type="submit"
          :disabled="store.loading.create"
          class="flex-1 rounded-2xl bg-violet-600 py-4 text-sm font-black text-white shadow-lg shadow-violet-600/20 transition-all hover:scale-[1.01] active:scale-[0.99] disabled:opacity-50"
        >
          <Loader2 v-if="store.loading.create" class="mb-0.5 ml-2 inline h-5 w-5 animate-spin" />
          <CheckCircle v-else class="mb-0.5 ml-2 inline h-5 w-5" />
          {{ store.loading.create ? 'جاري التنفيذ...' : 'تأكيد وحفظ العملية' }}
        </button>
        <router-link to="/online" class="flex-1 rounded-2xl bg-white/5 py-4 text-center text-sm font-bold text-text-main transition hover:bg-white/10">
          إلغاء
        </router-link>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, onActivated, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
  ArrowRight,
  Globe,
  User,
  Banknote,
  CreditCard,
  CheckCircle,
  Loader2,
  TrendingUp,
} from 'lucide-vue-next';
import { useOnlineStore } from '@/stores/onlineStore';

const store = useOnlineStore();
const router = useRouter();

const initialForm = () => ({
  service_type_id: null,
  provider_id: null,
  customer_id: null,
  customer_name: '',
  customer_phone: '',
  customer_country: '',
  employee_id: null,
  purchase_price: 0,
  selling_price: 0,
  amount_paid: 0,
  payment_method: '',
  account_id: null,
  reference_number: '',
  notes: '',
  status: '',
});

const form = ref(initialForm());

function resetForm() {
  form.value = initialForm();
}

const profit = computed(() => {
  const purchase = Number(form.value.purchase_price) || 0;
  const selling = Number(form.value.selling_price) || 0;
  return Math.max(selling - purchase, -Infinity);
});

const formatMoney = (value) =>
  new Intl.NumberFormat('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    value ?? 0,
  ) + ' ج.م';

const setPaidPercent = (pct) => {
  const tot = Number(form.value.selling_price) || 0;
  if (tot <= 0) {
    store.addToast('يرجى تحديد سعر البيع أولاً', 'error');
    return;
  }
  form.value.amount_paid = Math.round((tot * pct) / 100 * 100) / 100;
};

watch(
  () => form.value.selling_price,
  (newVal) => {
    if (!form.value.amount_paid || form.value.amount_paid === 0) {
      form.value.amount_paid = newVal;
    }
  }
);

const onCustomerSelected = () => {
  if (!form.value.customer_id) {
    return;
  }
  const customer = store.customers.find((c) => c.id === form.value.customer_id);
  if (customer) {
    form.value.customer_name = customer.name;
    form.value.customer_phone = customer.phone ?? '';
  }
};

const submit = async () => {
  if (!form.value.service_type_id) {
    store.addToast('اختر نوع الخدمة.', 'error');
    return;
  }
  if (!form.value.customer_id && !String(form.value.customer_name || '').trim()) {
    store.addToast('أدخل اسم العميل أو اختر عميلاً من القائمة.', 'error');
    return;
  }
  if (!form.value.payment_method) {
    store.addToast('اختر طريقة الدفع.', 'error');
    return;
  }
  if (!form.value.account_id) {
    store.addToast('اختر حساب التحصيل.', 'error');
    return;
  }

  try {
    const payload = {
      ...form.value,
      service_type_id: Number(form.value.service_type_id),
      account_id: Number(form.value.account_id),
      purchase_price: Number(form.value.purchase_price),
      selling_price: Number(form.value.selling_price),
      amount_paid: Number(form.value.amount_paid),
      customer_name: String(form.value.customer_name ?? '').trim(),
      payment_method: String(form.value.payment_method ?? '').trim(),
    };
    if (form.value.provider_id != null) {
      payload.provider_id = Number(form.value.provider_id);
    }
    if (form.value.customer_id != null) {
      payload.customer_id = Number(form.value.customer_id);
    }
    if (form.value.employee_id != null) {
      payload.employee_id = Number(form.value.employee_id);
    }

    if (!payload.customer_id) delete payload.customer_id;
    if (!form.value.provider_id) delete payload.provider_id;
    if (!payload.employee_id) delete payload.employee_id;
    if (!payload.status) delete payload.status;

    await store.createTransaction(payload);
    router.push('/online');
  } catch (error) {
    /* toast handled in store */
  }
};

function applyDefaultsFromApi() {
  if (!form.value.payment_method && store.paymentMethods.length) {
    const first = store.paymentMethods[0];
    form.value.payment_method = first.code ?? first.value ?? '';
  }
  if (!form.value.status && store.statuses.length) {
    const completed = store.statuses.find((s) => s.value === 'completed');
    form.value.status = completed?.value ?? store.statuses[0].value;
  }
}

watch(
  () => [store.paymentMethods, store.statuses],
  () => applyDefaultsFromApi(),
  { deep: true },
);

onMounted(async () => {
  resetForm();
  await Promise.all([
    store.fetchAllSettings(),
    store.fetchCustomers(),
    store.fetchEmployees(),
  ]);
  applyDefaultsFromApi();
});

onActivated(() => {
  resetForm();
  applyDefaultsFromApi();
});
</script>

<style scoped>
.online-transaction-view {
  --violet: #8b5cf6;
  --card-bg: #111827;
  --input-bg: #1f2937;
  --text-main: #f9fafb;
  --text-muted: #9ca3af;
  --success: #10b981;
  --error: #ef4444;
}

.flight-hero {
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.9) 0%, rgba(15, 23, 42, 0.8) 100%);
  padding: 2.5rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(139, 92, 246, 0.1);
  overflow: hidden;
}

.flight-hero::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -20%;
  width: 140%;
  height: 200%;
  background: radial-gradient(circle, rgba(139, 92, 246, 0.05) 0%, transparent 70%);
  pointer-events: none;
}

.flight-panel {
  background: var(--card-bg);
  border: 1px solid rgba(255, 255, 255, 0.05);
  border-radius: 1.5rem;
  padding: 1.5rem;
  transition: all 0.3s ease;
}

.flight-panel:hover {
  border-color: rgba(139, 92, 246, 0.2);
  transform: translateY(-2px);
}

.flight-panel__title {
  font-size: 1rem;
  font-weight: 800;
  color: var(--text-main);
}

.btn-airline-ghost {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text-main);
  transition: all 0.2s ease;
}

.btn-airline-ghost:hover {
  background: rgba(139, 92, 246, 0.1);
  border-color: rgba(139, 92, 246, 0.3);
  color: var(--violet);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', monospace;
}
</style>
