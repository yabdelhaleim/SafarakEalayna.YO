<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        to="/wallet"
        class="p-2 hover:bg-white/10 rounded-lg transition-colors"
      >
        <ArrowRight class="w-6 h-6" />
      </router-link>
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          عملية محفظة جديدة
        </h1>
        <p class="text-text-muted mt-1">
          تسجيل عملية إرسال أو استقبال رصيد
        </p>
      </div>
    </div>

    <form @submit.prevent="submit" class="max-w-4xl mx-auto space-y-8">
      <!-- نوع العملية -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <button
          type="button"
          @click="form.type = 'send'"
          :class="[
            'relative p-6 rounded-2xl border text-right transition-all',
            form.type === 'send'
              ? 'border-warning/50 bg-warning/10 ring-2 ring-warning/30 shadow-lg shadow-warning/5'
              : 'border-white/10 bg-card-bg hover:border-warning/25',
          ]"
        >
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-warning/15 rounded-xl text-warning">
              <ArrowUpCircle class="w-6 h-6" />
            </div>
            <span class="font-bold text-text-main text-lg">إرسال رصيد</span>
          </div>
          <p class="text-sm text-text-muted leading-relaxed">
            نرسل رصيد على محفظة العميل ويدفع لنا نقدي + خدمة
          </p>
          <div v-if="form.type === 'send'" class="absolute top-4 left-4 text-warning">
            <CheckCircle2 class="w-6 h-6" />
          </div>
        </button>

        <button
          type="button"
          @click="form.type = 'receive'"
          :class="[
            'relative p-6 rounded-2xl border text-right transition-all',
            form.type === 'receive'
              ? 'border-success/50 bg-success/10 ring-2 ring-success/30 shadow-lg shadow-success/5'
              : 'border-white/10 bg-card-bg hover:border-success/25',
          ]"
        >
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-success/15 rounded-xl text-success">
              <ArrowDownCircle class="w-6 h-6" />
            </div>
            <span class="font-bold text-text-main text-lg">استقبال رصيد</span>
          </div>
          <p class="text-sm text-text-muted leading-relaxed">
            العميل يرسل رصيد لمحفظتنا ونعطيه نقدي ناقص الخدمة
          </p>
          <div v-if="form.type === 'receive'" class="absolute top-4 left-4 text-success">
            <CheckCircle2 class="w-6 h-6" />
          </div>
        </button>
      </div>

      <!-- نوع المحفظة -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-info/10 rounded-lg">
            <Wallet class="w-5 h-5 text-info" />
          </div>
          <h2 class="text-xl font-bold text-text-main">نوع المحفظة</h2>
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="wt in activeWalletTypes"
            :key="wt.id"
            type="button"
            @click="form.wallet_type_id = wt.id"
            :class="[
              'px-4 py-2.5 rounded-xl text-sm font-semibold border transition-all',
              form.wallet_type_id === wt.id
                ? 'border-gold/60 bg-gold/15 text-gold'
                : 'border-white/10 bg-white/5 text-text-main hover:border-gold/30',
            ]"
          >
            {{ wt.name }}
          </button>
        </div>
        <p v-if="errors.wallet_type_id" class="text-error text-sm mt-3">{{ errors.wallet_type_id }}</p>
      </div>

      <!-- بيانات العميل -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-gold/10 rounded-lg">
            <User class="w-5 h-5 text-gold" />
          </div>
          <h2 class="text-xl font-bold text-text-main">بيانات العميل</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              اسم العميل <span class="text-error">*</span>
            </label>
            <input
              v-model="form.customer_name"
              type="text"
              placeholder="اسم العميل"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
              :class="{ '!border-error': errors.customer_name }"
            />
            <p v-if="errors.customer_name" class="text-error text-xs mt-1">{{ errors.customer_name }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              رقم المحفظة (الهاتف) <span class="text-error">*</span>
            </label>
            <input
              v-model="form.wallet_number"
              type="tel"
              placeholder="01012345678"
              class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
              :class="{ '!border-error': errors.wallet_number }"
            />
            <p v-if="errors.wallet_number" class="text-error text-xs mt-1">{{ errors.wallet_number }}</p>
          </div>
        </div>
      </div>

      <!-- المبالغ -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-success/10 rounded-lg">
            <Banknote class="w-5 h-5 text-success" />
          </div>
          <h2 class="text-xl font-bold text-text-main">المبالغ</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              المبلغ <span class="text-error">*</span>
            </label>
            <div class="relative">
              <input
                v-model.number="form.amount"
                type="number"
                min="0.01"
                step="0.01"
                placeholder="0.00"
                class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
                :class="{ '!border-error': errors.amount }"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">ج.م</span>
            </div>
            <p v-if="errors.amount" class="text-error text-xs mt-1">{{ errors.amount }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">قيمة الخدمة (العمولة)</label>
            <div class="relative">
              <input
                v-model.number="form.service_fee"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all font-mono tabular-nums"
              />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">ج.م</span>
            </div>
          </div>
        </div>

        <div
          v-if="form.amount"
          :class="[
            'rounded-xl p-5 border text-sm',
            form.type === 'send'
              ? 'bg-warning/5 border-warning/20'
              : 'bg-success/5 border-success/20',
          ]"
        >
          <template v-if="form.type === 'send'">
            <p class="text-text-muted mb-1">
              العميل يدفع:
              <strong class="text-text-main">{{ formatCurrency(totalAmount) }}</strong>
              ({{ formatCurrency(form.amount) }} + خدمة {{ formatCurrency(form.service_fee || 0) }})
            </p>
            <p class="text-text-muted">
              ربح الوكالة: <strong class="text-success">{{ formatCurrency(form.service_fee || 0) }}</strong>
            </p>
          </template>
          <template v-else>
            <p class="text-text-muted mb-1">
              العميل يستلم:
              <strong class="text-text-main">{{ formatCurrency(totalAmount) }}</strong>
              ({{ formatCurrency(form.amount) }} − خدمة {{ formatCurrency(form.service_fee || 0) }})
            </p>
            <p class="text-text-muted">
              ربح الوكالة: <strong class="text-success">{{ formatCurrency(form.service_fee || 0) }}</strong>
            </p>
          </template>
        </div>
      </div>

      <!-- الحسابات -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-6">
          <div class="p-2 bg-purple/10 rounded-lg">
            <Landmark class="w-5 h-5 text-purple" />
          </div>
          <h2 class="text-xl font-bold text-text-main">الحسابات</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              حساب المحفظة الإلكترونية (للوكالة) <span class="text-error">*</span>
            </label>
            <select
              v-model="form.wallet_account_id"
              class="form-select-dark"
              :class="{ '!border-error': errors.wallet_account_id }"
            >
              <option value="">— اختر الحساب —</option>
              <option v-for="acc in walletAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
            </select>
            <p v-if="errors.wallet_account_id" class="text-error text-xs mt-1">{{ errors.wallet_account_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">
              الحساب النقدي <span class="text-error">*</span>
            </label>
            <select
              v-model="form.cash_account_id"
              class="form-select-dark"
              :class="{ '!border-error': errors.cash_account_id }"
            >
              <option value="">— اختر الحساب —</option>
              <option v-for="acc in cashAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
            </select>
            <p v-if="errors.cash_account_id" class="text-error text-xs mt-1">{{ errors.cash_account_id }}</p>
          </div>
        </div>
      </div>

      <!-- ملاحظات -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-white/5 rounded-lg">
            <FileText class="w-5 h-5 text-text-muted" />
          </div>
          <h2 class="text-xl font-bold text-text-main">ملاحظات (اختياري)</h2>
        </div>
        <textarea
          v-model="form.notes"
          rows="3"
          placeholder="أي ملاحظات إضافية..."
          class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all resize-none"
        />
      </div>

      <div v-if="globalError" class="bg-error/10 border border-error/30 rounded-xl p-4 text-sm text-error">
        {{ globalError }}
      </div>

      <div class="flex flex-wrap gap-3">
        <button
          type="submit"
          :disabled="loading.create || !form.type"
          class="flex-1 min-w-[200px] py-3.5 px-6 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all shadow-lg shadow-gold/20 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <span v-if="loading.create" class="inline-flex items-center justify-center gap-2">
            <Loader2 class="w-5 h-5 animate-spin" />
            جاري الحفظ...
          </span>
          <span v-else>
            {{ form.type === 'send' ? 'تسجيل إرسال الرصيد' : 'تسجيل استقبال الرصيد' }}
          </span>
        </button>
        <router-link
          to="/wallet"
          class="px-8 py-3.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold text-text-main transition-all text-center"
        >
          إلغاء
        </router-link>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import axios from 'axios';
import { useWalletStore } from '@/stores/walletStore';
import {
  ArrowRight,
  ArrowUpCircle,
  ArrowDownCircle,
  CheckCircle2,
  Wallet,
  User,
  Banknote,
  Landmark,
  FileText,
  Loader2,
} from 'lucide-vue-next';

const router = useRouter();
const store = useWalletStore();
const { activeWalletTypes, loading } = storeToRefs(store);

const form = ref({
  type: 'send',
  wallet_type_id: '',
  customer_name: '',
  wallet_number: '',
  amount: '',
  service_fee: '',
  wallet_account_id: '',
  cash_account_id: '',
  notes: '',
});

const errors = ref({});
const globalError = ref('');
const walletAccounts = ref([]);
const cashAccounts = ref([]);

const totalAmount = computed(() => {
  const amt = parseFloat(form.value.amount) || 0;
  const fee = parseFloat(form.value.service_fee) || 0;
  return form.value.type === 'send' ? amt + fee : amt - fee;
});

onMounted(async () => {
  await store.fetchWalletTypes();
  await fetchAccounts();
});

async function fetchAccounts() {
  try {
    const res = await axios.get('/api/v1/finance/accounts', {
      params: {
        module: 'wallet',
        is_active: 1,
        per_page: 200,
      }
    });
    const all = res.data?.data?.items || res.data?.data || [];
    walletAccounts.value = all.filter(
      (a) => a.type === 'wallet' && a.is_active !== false && Number(a.is_active) !== 0,
    );
    cashAccounts.value = all.filter(
      (a) =>
        ['cashbox', 'treasury', 'bank'].includes(a.type) &&
        a.is_active !== false &&
        Number(a.is_active) !== 0,
    );
  } catch (e) {
    console.error('Failed to load accounts', e);
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
  }).format(Number(amount) || 0);
}

async function submit() {
  errors.value = {};
  globalError.value = '';

  if (!form.value.type) {
    errors.value.type = 'اختر نوع العملية';
    return;
  }
  if (!form.value.wallet_type_id) {
    errors.value.wallet_type_id = 'اختر نوع المحفظة';
    return;
  }
  if (!form.value.customer_name) {
    errors.value.customer_name = 'اسم العميل مطلوب';
    return;
  }
  if (!form.value.wallet_number) {
    errors.value.wallet_number = 'رقم المحفظة مطلوب';
    return;
  }
  if (!form.value.amount || parseFloat(form.value.amount) <= 0) {
    errors.value.amount = 'المبلغ مطلوب';
    return;
  }
  if (!form.value.wallet_account_id) {
    errors.value.wallet_account_id = 'حساب المحفظة مطلوب';
    return;
  }
  if (!form.value.cash_account_id) {
    errors.value.cash_account_id = 'الحساب النقدي مطلوب';
    return;
  }

  try {
    await store.createTransaction({
      ...form.value,
      service_fee: parseFloat(form.value.service_fee) || 0,
    });
    router.push('/wallet');
  } catch (e) {
    const serverErrors = e.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]),
      );
    } else {
      globalError.value = e.response?.data?.message || 'حدث خطأ، حاول مرة أخرى';
    }
  }
}
</script>
