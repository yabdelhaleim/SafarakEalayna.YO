<template>
  <div class="mx-auto max-w-5xl space-y-8 pb-16 animate-in fade-in duration-700">
    <header class="flight-hero relative">
      <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <router-link
            to="/fawry"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة للقائمة"
          >
            <ArrowRight class="h-5 w-5 text-amber-300/90" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-amber-400/90">معاملة فوري</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">تفاصيل المعاملة</h1>
            <p class="mt-2 text-sm text-text-muted">
              رقم المعاملة: <span class="font-mono text-gold">#{{ transaction?.id }}</span>
            </p>
          </div>
        </div>
      </div>
    </header>

    <div v-if="store.loading.transaction && !transaction" class="flex justify-center py-20">
      <Loader2 class="h-10 w-10 animate-spin text-gold" />
    </div>

    <template v-else-if="transaction">
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="flight-panel !p-6 sm:!p-8">
          <h2 class="flight-panel__title mb-4 flex items-center gap-2">
            <User class="h-5 w-5 text-gold" />
            بيانات العميل
          </h2>
          <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <dt class="text-xs text-text-muted">اسم العميل</dt>
              <dd class="mt-1 text-lg font-semibold text-text-main">{{ transaction.client_name }}</dd>
            </div>
            <div>
              <dt class="text-xs text-text-muted">رقم المرجع</dt>
              <dd class="mt-1 font-mono text-sm font-semibold text-text-main">
                {{ transaction.reference_number || '—' }}
              </dd>
            </div>
          </dl>
        </div>

        <div class="flight-panel !p-6 sm:!p-8">
          <h2 class="flight-panel__title mb-4 flex items-center gap-2">
            <CreditCard class="h-5 w-5 text-sky-400" />
            تفاصيل العملية
          </h2>
          <dl class="grid grid-cols-1 gap-4">
            <div>
              <dt class="text-xs text-text-muted">نوع العملية</dt>
              <dd class="mt-1">
                <span
                  :class="[
                    'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold',
                    getOperationTypeBadgeClass(transaction.operation_type),
                  ]"
                >
                  {{ transaction.operation_type_label || store.getOperationTypeLabel(transaction.operation_type) }}
                </span>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-text-muted">طريقة الدفع</dt>
              <dd class="mt-1">
                <span
                  :class="[
                    'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold',
                    getPaymentMethodBadgeClass(transaction.payment_method),
                  ]"
                >
                  {{ transaction.payment_method_label || store.getPaymentMethodLabel(transaction.payment_method) }}
                </span>
              </dd>
            </div>
            <div>
              <dt class="text-xs text-text-muted">التاريخ</dt>
              <dd class="mt-1 font-semibold text-text-main">{{ formatDate(transaction.created_at) }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <div class="flight-panel !p-6 sm:!p-8">
        <h2 class="flight-panel__title mb-4 flex items-center gap-2">
          <Banknote class="h-5 w-5 text-gold" />
          المبالغ والتسعير
        </h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <dt class="text-xs text-text-muted">مبلغ العميل</dt>
            <dd class="mt-1 font-mono text-lg font-bold tabular-nums text-text-main">
              {{ formatCurrency(transaction.client_amount) }}
            </dd>
          </div>
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <dt class="text-xs text-text-muted">سعر فوري</dt>
            <dd class="mt-1 font-mono text-lg font-bold tabular-nums text-text-main">
              {{ formatCurrency(transaction.fawry_price) }}
            </dd>
          </div>
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <dt class="text-xs text-text-muted">سعر البيع</dt>
            <dd class="mt-1 font-mono text-lg font-bold tabular-nums text-text-main">
              {{ formatCurrency(transaction.selling_price) }}
            </dd>
          </div>
          <div class="rounded-xl border border-success/25 bg-success/10 p-4">
            <dt class="text-xs font-semibold text-success/90">الربح</dt>
            <dd class="mt-1 font-mono text-lg font-black tabular-nums text-success">
              {{ profitPrefix }}{{ formatCurrency(Math.abs(transaction.profit || 0)) }}
            </dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel !p-6 sm:!p-8">
        <h2 class="flight-panel__title mb-4 flex items-center gap-2">
          <Wallet class="h-5 w-5 text-purple-300" />
          التحصيل والدفع
        </h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <p class="text-xs text-text-muted">المبلغ المدفوع</p>
            <p class="mt-1 font-mono text-lg font-bold tabular-nums text-text-main">
              {{ formatCurrency(transaction.amount) }}
            </p>
          </div>
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <p class="text-xs text-text-muted">الموظف المسؤول</p>
            <p class="mt-1 font-semibold text-text-main">{{ transaction.employee?.name || '—' }}</p>
          </div>
        </div>
      </div>

      <div v-if="transaction.notes" class="flight-panel !p-6 sm:!p-8">
        <h2 class="flight-panel__title mb-3 flex items-center gap-2">
          <FileText class="h-5 w-5 text-amber-300" />
          ملاحظات
        </h2>
        <p class="text-sm leading-relaxed text-text-main">{{ transaction.notes }}</p>
      </div>

      <div
        v-if="transaction.expense_transaction_id || transaction.income_transaction_id"
        class="flight-panel !p-6 sm:!p-8"
      >
        <h2 class="flight-panel__title mb-4 flex items-center gap-2">
          <Scale class="h-5 w-5 text-sky-400" />
          القيود المحاسبية
        </h2>
        <div class="space-y-2">
          <div
            v-if="transaction.expense_transaction_id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-input-bg px-4 py-3 text-sm"
          >
            <span class="font-semibold text-error/90">قيد مصروف</span>
            <span class="font-mono font-bold text-text-main">#{{ transaction.expense_transaction_id }}</span>
            <span class="text-xs text-text-muted">{{ formatCurrency(transaction.fawry_price) }}</span>
          </div>
          <div
            v-if="transaction.income_transaction_id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-input-bg px-4 py-3 text-sm"
          >
            <span class="font-semibold text-success/90">قيد إيراد</span>
            <span class="font-mono font-bold text-text-main">#{{ transaction.income_transaction_id }}</span>
            <span class="text-xs text-text-muted">{{ formatCurrency(transaction.selling_price) }}</span>
          </div>
        </div>
      </div>

      <FawryApiResponsePanel
        :envelope="store.lastApiEnvelope"
        @clear="store.clearLastApiEnvelope()"
      />

      <div class="no-print flex flex-wrap items-center justify-between gap-4">
        <router-link to="/fawry" class="btn-airline-ghost px-6 py-3">رجوع للقائمة</router-link>
        <div class="flex flex-wrap gap-3">
          <router-link
            :to="`/fawry/${transaction.id}/edit`"
            class="btn-airline inline-flex items-center gap-2 px-5 py-3 text-sm"
          >
            <Pencil class="h-4 w-4" />
            تعديل
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-error/40 bg-error/15 px-5 py-3 font-bold text-error transition hover:bg-error/25"
            @click="confirmDelete"
          >
            <Trash2 class="h-4 w-4" />
            حذف
          </button>
        </div>
      </div>
    </template>

    <div v-else class="space-y-6">
      <div class="flight-panel !p-10 text-center">
        <AlertCircle class="mx-auto mb-4 h-14 w-14 text-error/40" />
        <h3 class="text-lg font-bold text-text-main">المعاملة غير موجودة</h3>
        <p class="mt-2 text-sm text-text-muted">لم يتم العثور على المعاملة المطلوبة</p>
        <router-link to="/fawry" class="btn-airline mt-6 inline-flex">رجوع للقائمة</router-link>
      </div>
      <FawryApiResponsePanel
        :envelope="store.lastApiEnvelope"
        @clear="store.clearLastApiEnvelope()"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
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
  Scale,
  Pencil,
  Trash2,
  Loader2,
  AlertCircle,
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const store = useFawryStore();

const transaction = computed(() => {
  return store.transactions.find(t => t.id === parseInt(route.params.id));
});

const profitPrefix = computed(() => {
  const p = Number(transaction.value?.profit);
  if (!Number.isFinite(p) || p === 0) return '';
  return p > 0 ? '+' : '−';
});

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
  }).format(amount || 0);
};

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const getOperationTypeBadgeClass = (type) => {
  const classes = {
    withdrawal: 'bg-error/15 text-error',
    deposit: 'bg-success/15 text-success',
    payment: 'bg-sky-500/15 text-sky-200',
    travel_permit: 'bg-amber-500/15 text-amber-200',
  };
  return classes[type] || 'bg-white/10 text-text-muted';
};

const getPaymentMethodBadgeClass = (method) => {
  const classes = {
    cash: 'bg-success/15 text-success',
    bank_transfer: 'bg-sky-500/15 text-sky-200',
    cash_wallet: 'bg-purple-500/15 text-purple-200',
    office_safe: 'bg-amber-500/15 text-amber-200',
    office_drawer: 'bg-white/10 text-text-muted',
  };
  return classes[method] || 'bg-white/10 text-text-muted';
};

const confirmDelete = () => {
  if (confirm(`هل أنت متأكد من حذف معاملة "${transaction.value.client_name}"؟`)) {
    store.deleteTransaction(transaction.value.id).then(() => {
      router.push('/fawry');
    });
  }
};

onMounted(async () => {
  const id = parseInt(route.params.id, 10);
  if (!Number.isFinite(id)) {
    return;
  }
  if (transaction.value) {
    return;
  }
  try {
    await store.fetchTransactionById(id);
  } catch {
    //
  }
});
</script>
