<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        to="/finance/transactions"
        class="p-2 hover:bg-white/10 rounded-lg transition-all"
      >
        <ArrowRight class="w-5 h-5 text-text-muted" />
      </router-link>
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          معاملة مالية جديدة
        </h1>
        <p class="text-text-muted mt-1">إضافة دخل أو مصروف جديد</p>
      </div>
    </div>

    <!-- Form -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
      <form @submit.prevent="handleSubmit" class="space-y-6">
        <!-- Transaction Type -->
        <div>
          <label class="block text-sm font-semibold text-text-main mb-3">
            نوع المعاملة
          </label>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <button
              v-for="t in creatableTypes"
              :key="t.value"
              type="button"
              @click="form.type = t.value"
              :class="typeButtonClass(t.value)"
            >
              <component :is="typeIcon(t.value)" class="w-6 h-6 mx-auto mb-2" />
              <span class="font-semibold text-sm">{{ t.label }}</span>
            </button>
          </div>
        </div>

        <!-- Amount & Date -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              المبلغ *
            </label>
            <div class="relative">
              <input
                v-model.number="form.amount"
                type="number"
                step="0.01"
                required
                placeholder="0.00"
                class="w-full pl-4 pr-12 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">
                جنيه
              </span>
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              التاريخ *
            </label>
            <input
              v-model="form.date"
              type="date"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>
        </div>

        <!-- Module & Description -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              القسم
            </label>
            <select
              v-model="form.module"
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            >
              <option
                v-for="m in store.transactionModules"
                :key="m.value"
                :value="m.value"
              >
                {{ m.label }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              الوصف *
            </label>
            <input
              v-model="form.description"
              type="text"
              required
              placeholder="وصف المعاملة..."
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>
        </div>

        <!-- Account & Reference -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              الحساب
            </label>
            <select
              v-model="form.account_id"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            >
              <option :value="null" disabled>اختر الحساب</option>
              <option
                v-for="account in store.accounts"
                :key="account.id"
                :value="account.id"
              >
                {{ account.name }} ({{ account.balance?.toLocaleString() || 0 }} جنيه)
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              رقم المرجع
            </label>
            <input
              v-model="form.reference"
              type="text"
              placeholder="رقم الإيصال أو الحجز..."
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-semibold text-text-main mb-2">
            ملاحظات
          </label>
          <textarea
            v-model="form.notes"
            rows="3"
            placeholder="ملاحظات إضافية..."
            class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm resize-none"
          ></textarea>
        </div>

        <!-- Submit Buttons -->
        <div class="flex gap-4 pt-4">
          <button
            type="submit"
            :disabled="store.loading.create"
            class="flex-1 px-6 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all disabled:opacity-50 flex items-center justify-center gap-2"
          >
            <Save v-if="!store.loading.create" class="w-4 h-4" />
            <Loader2 v-else class="w-4 h-4 animate-spin" />
            {{ store.loading.create ? 'جاري الحفظ...' : 'حفظ المعاملة' }}
          </button>
          <router-link
            to="/finance/transactions"
            class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
          >
            إلغاء
          </router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useFinanceStore } from '@/stores/financeStore';
import {
  ArrowRight,
  TrendingUp,
  TrendingDown,
  RotateCcw,
  ArrowRightLeft,
  Save,
  Loader2,
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const store = useFinanceStore();

function createDefaultForm() {
  return {
    type: 'income',
    amount: null,
    date: new Date().toISOString().split('T')[0],
    module: 'general',
    description: '',
    account_id: null,
    reference: '',
    notes: '',
  };
}

const form = ref(createDefaultForm());

function resetForm() {
  form.value = createDefaultForm();
}

function applyRouteDefaults() {
  const types = store.transactionTypes;
  const allowedTypes = types.filter((t) => creatableTypeValues.includes(t.value));
  if (route.query.type && creatableTypeValues.includes(route.query.type)) {
    form.value.type = route.query.type;
  } else if (allowedTypes.length) {
    const has = allowedTypes.some((t) => t.value === form.value.type);
    if (!has) form.value.type = allowedTypes[0].value;
  }

  const mods = store.transactionModules;
  if (mods.length) {
    const hasM = mods.some((m) => m.value === form.value.module);
    if (!hasM) form.value.module = mods[0].value;
  }

  if (route.query.account_id) {
    form.value.account_id = Number(route.query.account_id);
  }
}

const creatableTypeValues = ['income', 'expense'];

const creatableTypes = computed(() =>
  store.transactionTypes.filter((t) => creatableTypeValues.includes(t.value))
);

const handleSubmit = async () => {
  if (!form.value.account_id) {
    store.addToast('يجب اختيار حساب السيولة', 'error');
    return;
  }
  if (!creatableTypeValues.includes(form.value.type)) {
    store.addToast('استخدم شاشة التحويلات للتحويل بين الحسابات', 'warning');
    return;
  }
  try {
    await store.createTransaction(form.value);
    store.addToast('تم إضافة المعاملة بنجاح');
    router.push('/finance/transactions');
  } catch (error) {
    const msg = error.response?.data?.message || store.errors?.message || 'فشل إضافة المعاملة';
    store.addToast(msg, 'error');
  }
};

const typeIcon = (value) => {
  switch (value) {
    case 'income':
      return TrendingUp;
    case 'expense':
      return TrendingDown;
    case 'refund':
      return RotateCcw;
    default:
      return ArrowRightLeft;
  }
};

const typeButtonClass = (value) => {
  const base = 'p-4 rounded-xl border-2 transition-all text-center';
  const inactive = 'border-white/10 text-text-muted';
  if (form.value.type !== value) {
    return `${base} ${inactive} hover:border-gold/30`;
  }
  const active = {
    income: 'border-success bg-success/10 text-success',
    expense: 'border-error bg-error/10 text-error',
    refund: 'border-warning bg-warning/10 text-warning',
    transfer: 'border-blue-500 bg-blue-500/10 text-blue-500',
  };
  return `${base} ${active[value] || 'border-gold bg-gold/10 text-gold'}`;
};

onMounted(async () => {
  resetForm();
  await store.fetchSettingsMeta();
  await store.fetchAccounts();
  applyRouteDefaults();
});

onActivated(() => {
  resetForm();
  applyRouteDefaults();
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}

.bg-input-bg {
  background-color: var(--input-bg);
}

.text-text-main {
  color: var(--text-main);
}

.text-text-muted {
  color: var(--text-muted);
}

.text-gold {
  color: var(--gold);
}

.bg-gold {
  background-color: var(--gold);
}

.text-success {
  color: var(--success);
}

.text-error {
  color: var(--error);
}

.text-warning {
  color: var(--warning);
}

.text-blue-500 {
  color: #4F8EF7;
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
