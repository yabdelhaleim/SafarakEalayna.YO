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
          تفاصيل المعاملة #{{ transaction?.id }}
        </h1>
        <p class="text-text-muted mt-1">سجل المعاملات المالية</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-4 border-gold/20 border-t-gold rounded-full animate-spin"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-error/10 border border-error/20 rounded-2xl p-8 text-center">
      <p class="text-error font-bold">{{ error }}</p>
    </div>

    <!-- Transaction Detail -->
    <template v-else-if="transaction">
      <!-- Info Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-3">
          <p class="text-xs text-text-muted font-bold uppercase tracking-wider">النوع</p>
          <div
            :class="[
              'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider',
              typeStyles[transaction.type] || 'bg-white/10 text-white'
            ]"
          >
            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
            {{ typeLabel(transaction.type) }}
          </div>
        </div>

        <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-3">
          <p class="text-xs text-text-muted font-bold uppercase tracking-wider">المبلغ</p>
          <p class="font-mono text-2xl font-black"
            :class="transaction.type === 'income' ? 'text-success' : transaction.type === 'expense' ? 'text-error' : 'text-blue-500'"
          >
            {{ transaction.type === 'income' ? '+' : transaction.type === 'expense' ? '-' : '' }}
            {{ formatCurrency(transaction.amount) }}
          </p>
        </div>

        <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-3">
          <p class="text-xs text-text-muted font-bold uppercase tracking-wider">القسم</p>
          <p class="text-lg font-bold">{{ moduleLabel(transaction.module) }}</p>
        </div>

        <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-3">
          <p class="text-xs text-text-muted font-bold uppercase tracking-wider">التاريخ</p>
          <p class="text-lg font-bold font-mono">{{ formatDate(transaction.created_at) }}</p>
        </div>
      </div>

      <!-- Accounts Info -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-6">
        <h2 class="text-lg font-extrabold text-text-main">الحسابات</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-3 p-4 bg-white/5 rounded-xl">
            <p class="text-xs text-text-muted font-bold uppercase tracking-wider">من حساب</p>
            <p class="text-lg font-bold">{{ transaction.from_account_name || '—' }}</p>
            <div class="flex items-center gap-3 text-sm text-text-muted">
              <span class="text-[10px] px-2 py-0.5 rounded bg-white/10">{{ transaction.from_account_type || '—' }}</span>
              <span class="font-mono">{{ transaction.from_account_currency || '—' }}</span>
            </div>
          </div>

          <div class="space-y-3 p-4 bg-white/5 rounded-xl">
            <p class="text-xs text-text-muted font-bold uppercase tracking-wider">إلى حساب</p>
            <p class="text-lg font-bold">{{ transaction.to_account_name || '—' }}</p>
            <div class="flex items-center gap-3 text-sm text-text-muted">
              <span class="text-[10px] px-2 py-0.5 rounded bg-white/10">{{ transaction.to_account_type || '—' }}</span>
              <span class="font-mono">{{ transaction.to_account_currency || '—' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Linked Booking Info (Related Meta) -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 space-y-6" v-if="transaction.related_meta">
        <div class="flex items-center justify-between border-b border-white/10 pb-4">
          <h2 class="text-lg font-extrabold text-text-main flex items-center gap-2">
            <Link2 class="w-5 h-5 text-gold" />
            تفاصيل العملية / الحجز المرتبط بالمعاملة
          </h2>
          <span class="text-[10px] font-black uppercase tracking-wider bg-gold/10 text-gold px-2.5 py-1 rounded-full">
            {{ getBookingTypeLabel(transaction.related_meta.type) }}
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <!-- Person details -->
          <div class="space-y-1 bg-white/5 p-4 rounded-xl border border-white/5">
            <p class="text-[10px] text-text-muted font-bold uppercase">العميل / الطرف المرتبط</p>
            <p class="text-base font-bold text-white">{{ transaction.related_meta.person_name || '—' }}</p>
            <p class="text-xs text-text-muted font-mono" v-if="transaction.related_meta.person_phone">
              {{ transaction.related_meta.person_phone }}
            </p>
          </div>

          <!-- Booking details -->
          <div class="space-y-1 md:col-span-2 bg-white/5 p-4 rounded-xl border border-white/5">
            <p class="text-[10px] text-text-muted font-bold uppercase">بيانات ومضمون الحجز والرحلة</p>
            <p class="text-base font-bold text-white/95 leading-relaxed">{{ transaction.related_meta.details || '—' }}</p>
          </div>
        </div>

        <!-- Financial Breakdown of Booking -->
        <div class="pt-4 border-t border-white/5 space-y-4">
          <p class="text-xs text-text-muted font-bold uppercase">موقف سداد الحجز الإجمالي</p>
          
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white/5 p-4 rounded-xl space-y-1 border border-white/5">
              <p class="text-[10px] text-text-muted font-bold">إجمالي قيمة الحجز</p>
              <p class="text-lg font-black text-white font-mono">{{ formatCurrency(transaction.related_meta.total_amount) }}</p>
            </div>
            
            <div class="bg-white/5 p-4 rounded-xl space-y-1 border-r-2 border-r-success border border-white/5">
              <p class="text-[10px] text-success font-bold">المبلغ المدفوع (المحصل)</p>
              <p class="text-lg font-black text-success font-mono">{{ formatCurrency(transaction.related_meta.paid_amount) }}</p>
            </div>
            
            <div class="bg-white/5 p-4 rounded-xl space-y-1 border-r-2 border-r-error border border-white/5">
              <p class="text-[10px] text-error font-bold">المبلغ الآجل (المتبقي)</p>
              <p class="text-lg font-black text-error font-mono">{{ formatCurrency(transaction.related_meta.remaining_amount) }}</p>
            </div>
          </div>

          <!-- Progress Bar & Status Badge -->
          <div class="space-y-2 pt-2">
            <div class="flex items-center justify-between text-xs font-bold">
              <span class="text-text-muted">نسبة السداد للعملية</span>
              <span :class="getPaymentStatusClass(transaction.related_meta)">
                {{ getPaymentStatusText(transaction.related_meta) }} ({{ getPaymentPercentage(transaction.related_meta) }}%)
              </span>
            </div>
            <div class="w-full bg-white/5 h-2.5 rounded-full overflow-hidden">
              <div 
                :class="['h-full transition-all duration-1000', getProgressBarClass(transaction.related_meta)]"
                :style="{ width: `${getPaymentPercentage(transaction.related_meta)}%` }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Notes -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6" v-if="transaction.notes">
        <h2 class="text-lg font-extrabold text-text-main mb-4">الوصف</h2>
        <p class="text-text-muted">{{ transaction.notes }}</p>
      </div>

      <!-- Account Entries -->
      <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden" v-if="transaction.entries?.length">
        <div class="px-6 py-4 bg-white/5 border-b border-white/10">
          <h2 class="text-lg font-extrabold text-text-main">قيود اليومية</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-widest border-b border-white/10">
                <th class="px-6 py-4">الحساب</th>
                <th class="px-6 py-4">نوع الحساب</th>
                <th class="px-6 py-4">مدين</th>
                <th class="px-6 py-4">دائن</th>
                <th class="px-6 py-4">الرصيد بعد</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-for="entry in transaction.entries" :key="entry.id" class="hover:bg-white/[0.02] transition-colors">
                <td class="px-6 py-4 font-bold text-sm">{{ entry.account_name }}</td>
                <td class="px-6 py-4">
                  <span class="text-[10px] px-2 py-0.5 rounded bg-white/10 text-text-muted">{{ entry.account_type }}</span>
                </td>
                <td class="px-6 py-4 font-mono font-black text-error">
                  {{ entry.debit > 0 ? formatCurrency(entry.debit, entry.account_currency) : '—' }}
                </td>
                <td class="px-6 py-4 font-mono font-black text-success">
                  {{ entry.credit > 0 ? formatCurrency(entry.credit, entry.account_currency) : '—' }}
                </td>
                <td class="px-6 py-4 font-mono font-black text-text-main">
                  {{ formatCurrency(entry.balance_after, entry.account_currency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Created By -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-gold/10 flex items-center justify-center text-gold">
            <User class="w-5 h-5" />
          </div>
          <div>
            <p class="text-xs text-text-muted">أُضيف بواسطة</p>
            <p class="font-bold">{{ transaction.created_by_name || 'نظام' }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { ArrowRight, User, Link2 } from 'lucide-vue-next';

const route = useRoute();
const transaction = ref(null);
const loading = ref(true);
const error = ref(null);

const typeStyles = {
  income: 'bg-success/10 text-success',
  expense: 'bg-error/10 text-error',
  transfer: 'bg-blue-500/10 text-blue-500',
  refund: 'bg-warning/10 text-warning',
};

const typeLabel = (value) => {
  const labels = { income: 'دخل', expense: 'مصروف', transfer: 'تحويل', refund: 'استرداد' };
  return labels[value] || value;
};

const moduleLabel = (value) => {
  const labels = {
    flight: 'طيران', bus: 'باص', hajj_umra: 'حج وعمرة',
    visa: 'تأشيرات', fawry: 'فوري', online: 'خدمات إلكترونية',
    wallet: 'محافظ', general: 'عام',
  };
  return labels[value] || value || '—';
};

const getBookingTypeLabel = (type) => {
  const labels = {
    FlightBooking: 'حجز طيران B2C/B2B',
    VisaBooking: 'حجز تأشيرة',
    HajjUmraBooking: 'حجز حج وعمرة',
    BusBooking: 'حجز حافلة / باص',
    OnlineTransaction: 'عملية خدمات إلكترونية',
  };
  return labels[type] || type;
};

const getPaymentPercentage = (meta) => {
  if (!meta.total_amount) return 0;
  return Math.min(100, Math.round((meta.paid_amount / meta.total_amount) * 100));
};

const getPaymentStatusText = (meta) => {
  const pct = getPaymentPercentage(meta);
  if (pct >= 100) return 'مدفوع بالكامل';
  if (pct > 0) return 'مدفوع جزئياً';
  return 'آجل بالكامل';
};

const getPaymentStatusClass = (meta) => {
  const pct = getPaymentPercentage(meta);
  if (pct >= 100) return 'text-success';
  if (pct > 0) return 'text-gold';
  return 'text-error';
};

const getProgressBarClass = (meta) => {
  const pct = getPaymentPercentage(meta);
  if (pct >= 100) return 'bg-success';
  if (pct > 0) return 'bg-gold';
  return 'bg-error';
};

const formatCurrency = (value, currency = 'EGP') => {
  if (value === null || value === undefined) return '—';
  return Number(value).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric', month: 'short', day: 'numeric',
  });
};

onMounted(async () => {
  try {
    const response = await axios.get(`/api/v1/reports/transactions/${route.params.id}`);
    transaction.value = response.data?.data || null;
    if (!transaction.value) {
      error.value = 'لم يتم العثور على المعاملة';
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'فشل تحميل تفاصيل المعاملة';
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning); }
.font-mono { font-family: 'IBM Plex Sans Arabic', sans-serif; }
</style>
