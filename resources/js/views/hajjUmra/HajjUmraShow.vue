<template>
  <div class="hajj-umra-booking mx-auto max-w-5xl space-y-8 pb-16">
    <header class="flight-hero relative">
      <div class="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <router-link
            :to="{ name: 'hajj.index' }"
            class="btn-airline-ghost shrink-0 rounded-xl p-2.5"
            aria-label="العودة للقائمة"
          >
            <ArrowLeft class="h-5 w-5 text-amber-300/90" />
          </router-link>
          <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-amber-400/90">حجز حج وعمرة</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">تفاصيل الحجز</h1>
            <p class="mt-2 text-sm text-text-muted">رقم الحجز: <span class="font-mono text-gold">#{{ id }}</span></p>
          </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
          <router-link :to="{ name: 'hajj.edit', params: { id: id } }" class="btn-airline-ghost rounded-xl px-4 py-2.5 text-sm font-bold">
            <Edit2 class="mb-0.5 ml-2 inline h-4 w-4" /> تعديل
          </router-link>
          <button @click="openPrintOptions" class="btn-airline-ghost rounded-xl px-4 py-2.5 text-sm font-bold text-gold border-gold/30">
            <Printer class="mb-0.5 ml-2 inline h-4 w-4" /> خيارات الطباعة
          </button>
        </div>
      </div>
    </header>

    <div v-if="store.loading.detail" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="booking" class="space-y-6">
      <div class="flight-panel">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
          <h2 class="flight-panel__title !mb-0 flex items-center gap-2">
            <Users class="h-5 w-5 text-gold" /> بيانات العميل
          </h2>
          <span
            :class="[
              'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-bold uppercase',
              statusStyles[booking.status] || 'bg-white/10 text-text-muted',
            ]"
          >
            {{ statusLabels[booking.status] || booking.status }}
          </span>
        </div>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-text-muted">الاسم</dt>
            <dd class="mt-1 font-bold">{{ booking.customer?.full_name || booking.customer?.name }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">الهاتف</dt>
            <dd class="mt-1 font-bold font-mono">{{ booking.customer?.phone }}</dd>
          </div>
          <div v-if="booking.customer?.passport_number">
            <dt class="text-xs text-text-muted">رقم الجواز</dt>
            <dd class="mt-1 font-bold font-mono">{{ booking.customer.passport_number }}</dd>
          </div>
          <div v-if="booking.companion">
            <dt class="text-xs text-text-muted">المرافق</dt>
            <dd class="mt-1 font-bold text-gold">{{ booking.companion?.full_name }}</dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4 flex items-center gap-2">
          <Calendar class="h-5 w-5 text-gold" /> تفاصيل البرنامج
        </h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs text-text-muted">البرنامج</dt>
            <dd class="mt-1 font-bold">{{ booking.program?.program_name }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">النوع</dt>
            <dd class="mt-1 font-bold">{{ booking.program?.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">مدة البرنامج</dt>
            <dd class="mt-1 font-bold">{{ booking.program?.total_nights || '—' }} ليلة</dd>
          </div>
          <div v-if="booking.baggage">
            <dt class="text-xs text-text-muted">الأمتعة</dt>
            <dd class="mt-1 font-bold text-success">{{ booking.baggage }}</dd>
          </div>
        </dl>
      </div>

      <div class="flight-panel">
        <h2 class="flight-panel__title mb-4 flex items-center gap-2">
          <DollarSign class="h-5 w-5 text-gold" /> المبالغ والمالية
        </h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <p class="text-xs text-text-muted">الإجمالي</p>
            <p class="mt-1 font-mono text-lg font-bold">{{ formatMoney(booking.pricing?.selling_price) }}</p>
          </div>
          <div class="rounded-xl border border-success/20 bg-success/10 p-4">
            <p class="text-xs text-text-muted">المدفوع</p>
            <p class="mt-1 font-mono text-lg font-bold text-success">{{ formatMoney(booking.finance?.paid_amount) }}</p>
          </div>
          <div class="rounded-xl border border-error/20 bg-error/10 p-4">
            <p class="text-xs text-text-muted">المتبقي</p>
            <p class="mt-1 font-mono text-lg font-bold text-error">{{ formatMoney(booking.finance?.remaining_amount) }}</p>
          </div>
        </div>

        <div v-if="booking.payments?.length" class="mt-6 space-y-2">
          <h3 class="text-sm font-bold text-text-main">سجل الدفعات</h3>
          <div
            v-for="p in booking.payments"
            :key="p.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-input-bg px-4 py-3 text-sm"
          >
            <span class="font-mono font-bold text-gold">{{ formatMoney(p.amount) }}</span>
            <span class="text-text-muted">{{ paymentLabels[p.payment_method] || p.payment_method }}</span>
            <span class="text-xs text-text-muted">{{ formatDate(p.payment_date) }}</span>
          </div>
        </div>

        <button
          v-if="booking.finance?.remaining_amount > 0 && booking.status !== 'cancelled'"
          type="button"
          class="mt-6 w-full rounded-xl bg-success px-4 py-3 font-bold text-black transition hover:bg-success/90"
          @click="showAddPayment = true"
        >
          <CreditCard class="mb-0.5 ml-2 inline h-4 w-4" /> تسديد دفعة
        </button>
      </div>

      <!-- Voucher for Printing -->
      <div
        id="hajj-umra-ticket-content"
        dir="rtl"
        class="hajj-umra-print-document overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-2xl print:rounded-none print:border-0 print:shadow-none"
      >
        <div
          class="relative flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 bg-gradient-to-l from-[#1a1208] via-[#2d1f0a] to-[#1a1208] px-6 py-5 text-white print:border-slate-300"
        >
          <div class="flex min-w-0 items-center gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
              <Calendar class="h-7 w-7 text-amber-300" />
            </div>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-200/90">Booking Voucher</p>
              <h2 class="text-xl font-black tracking-tight sm:text-2xl">وصل حجز {{ booking.program?.program_type === 'hajj' ? 'حج' : 'عمرة' }}</h2>
              <p class="mt-0.5 font-mono text-sm text-amber-100/90">#{{ id }}</p>
            </div>
          </div>
          <div class="text-left">
            <div class="text-lg font-black text-amber-300">سفرك علينا</div>
            <div class="text-[10px] font-semibold uppercase tracking-wider text-amber-200/80">Safarak Ealayna</div>
          </div>
        </div>

        <div class="bg-white px-6 py-6 sm:px-8">
          <div class="mb-6 grid grid-cols-1 gap-3 border-b border-dashed border-slate-200 pb-6 sm:grid-cols-2">
            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/80">
              <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">البرنامج</div>
              <div class="mt-1 text-lg font-black text-slate-900">{{ booking.program?.program_name }}</div>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/80">
              <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">تاريخ الحجز</div>
              <div class="mt-1 text-lg font-black text-slate-900">{{ formatDate(booking.created_at) }}</div>
            </div>
          </div>

          <div v-if="printOptions.passengers">
            <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">بيانات العميل</h3>
            <div class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div class="break-inside-avoid rounded-lg border border-slate-200 bg-slate-50/80 p-4">
                <div class="text-xs font-bold text-slate-500">الاسم</div>
                <div class="mt-1 text-base font-bold text-slate-900">{{ booking.customer?.full_name || booking.customer?.name }}</div>
              </div>
              <div class="break-inside-avoid rounded-lg border border-slate-200 bg-slate-50/80 p-4">
                <div class="text-xs font-bold text-slate-500">الهاتف</div>
                <div class="mt-1 font-mono text-base font-bold text-slate-900">{{ booking.customer?.phone }}</div>
              </div>
              <div v-if="booking.companion" class="break-inside-avoid rounded-lg border border-slate-200 bg-slate-50/80 p-4 sm:col-span-2">
                <div class="text-xs font-bold text-slate-500">المرافق</div>
                <div class="mt-1 text-base font-bold text-slate-900">{{ booking.companion?.full_name }}</div>
              </div>
            </div>
          </div>

          <div v-if="printOptions.tripDetails">
            <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">تفاصيل البرنامج</h3>
            <div class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="break-inside-avoid rounded-lg border border-slate-200 p-4">
                <div class="text-xs font-bold text-slate-500">النوع</div>
                <div class="mt-1 font-bold text-slate-900">{{ booking.program?.program_type === 'hajj' ? 'حج' : 'عمرة' }}</div>
              </div>
              <div class="break-inside-avoid rounded-lg border border-slate-200 p-4">
                <div class="text-xs font-bold text-slate-500">المدة</div>
                <div class="mt-1 font-bold text-slate-900">{{ booking.program?.total_nights }} ليلة</div>
              </div>
              <div v-if="printOptions.baggage && booking.baggage" class="break-inside-avoid rounded-lg border border-slate-200 p-4">
                <div class="text-xs font-bold text-slate-500">الأمتعة</div>
                <div class="mt-1 font-bold text-slate-900">{{ booking.baggage }}</div>
              </div>
            </div>
          </div>

          <div v-if="printOptions.price">
            <h3 class="mb-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">المبالغ</h3>
            <table class="mb-8 w-full border-collapse overflow-hidden rounded-xl border border-slate-200 text-sm">
              <thead class="bg-slate-100 text-slate-600">
                <tr>
                  <th class="border-b border-slate-200 px-4 py-2 text-right font-bold">البند</th>
                  <th class="border-b border-slate-200 px-4 py-2 text-left font-mono font-bold">المبلغ</th>
                </tr>
              </thead>
              <tbody>
                <tr class="border-b border-slate-100">
                  <td class="px-4 py-3 font-semibold text-slate-700">الإجمالي</td>
                  <td class="px-4 py-3 text-left font-mono font-bold text-slate-900">{{ formatMoney(booking.pricing?.selling_price) }}</td>
                </tr>
                <tr class="border-b border-slate-100">
                  <td class="px-4 py-3 font-semibold text-slate-700">المدفوع</td>
                  <td class="px-4 py-3 text-left font-mono font-bold text-emerald-700">{{ formatMoney(booking.finance?.paid_amount) }}</td>
                </tr>
                <tr>
                  <td class="px-4 py-3 font-semibold text-slate-700">المتبقي</td>
                  <td class="px-4 py-3 text-left font-mono font-bold text-rose-700">{{ formatMoney(booking.finance?.remaining_amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="printOptions.notes && booking.notes" class="mb-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
            <span class="font-bold text-slate-500">ملاحظات: </span>{{ booking.notes }}
          </div>

          <div class="border-t border-slate-200 pt-4 text-center text-[10px] leading-relaxed text-slate-500">
            وثيقة إعلامية صادرة من سفرك علينا — يرجى مراجعة التفاصيل قبل السفر.
          </div>
        </div>
      </div>
    </div>

    <!-- Print Options Modal -->
    <div v-if="showPrintModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-md" @click="showPrintModal = false"></div>
      <div class="relative w-full max-w-md overflow-hidden rounded-3xl bg-card border border-white/10 shadow-2xl animate-in zoom-in-95 duration-200">
        <div class="p-6">
          <div class="mb-6 flex items-center justify-between">
            <h3 class="text-xl font-black text-text-main">إعدادات الطباعة</h3>
            <button @click="showPrintModal = false" class="rounded-full p-2 hover:bg-white/5 transition">
              <X class="h-6 w-6 text-text-muted" />
            </button>
          </div>

          <div class="space-y-3">
            <div
              v-for="(label, key) in { logo: 'شعار المكتب', passengers: 'بيانات العملاء', tripDetails: 'تفاصيل البرنامج', dates: 'التواريخ', price: 'المبالغ المالية', baggage: 'الأمتعة', notes: 'الملاحظات' }"
              :key="key"
              class="flex items-center justify-between rounded-2xl bg-white/[0.03] p-4 transition hover:bg-white/[0.05]"
            >
              <span class="text-sm font-bold text-text-main">{{ label }}</span>
              <button
                @click="printOptions[key] = !printOptions[key]"
                :class="['relative h-6 w-11 rounded-full transition-all duration-300', printOptions[key] ? 'bg-gold' : 'bg-white/10']"
              >
                <span :class="['absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-all duration-300', printOptions[key] ? 'translate-x-5' : 'translate-x-0']"></span>
              </button>
            </div>
          </div>

          <div class="mt-8 flex gap-3">
            <button @click="runPrintJob" class="flex-1 rounded-2xl bg-gold py-4 text-sm font-black text-black shadow-lg shadow-gold/20 transition-all hover:scale-[1.02] active:scale-[0.98]">
              <Printer class="mb-0.5 ml-2 inline h-5 w-5" /> بدء الطباعة
            </button>
            <button @click="showPrintModal = false" class="flex-1 rounded-2xl bg-white/5 py-4 text-sm font-bold text-text-main transition hover:bg-white/10">إلغاء</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Modal (Partial Implementation or Link to store) -->
    <div v-if="showAddPayment" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-md" @click="showAddPayment = false"></div>
      <div class="relative w-full max-w-lg overflow-hidden rounded-3xl bg-card border border-white/10 shadow-2xl animate-in zoom-in-95 duration-200">
        <div class="p-8">
           <div class="mb-6 flex items-center justify-between">
            <h3 class="text-xl font-black text-text-main flex items-center gap-2">
              <CreditCard class="h-6 w-6 text-gold" /> تسجيل دفعة جديدة
            </h3>
            <button @click="showAddPayment = false" class="rounded-full p-2 hover:bg-white/5 transition">
              <X class="h-6 w-6 text-text-muted" />
            </button>
          </div>

          <div class="space-y-5">
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">طريقة الدفع</label>
                <select v-model="newPayment.payment_method" class="w-full rounded-xl border border-white/10 bg-input-bg p-3 text-sm text-text-main outline-none focus:border-gold/50">
                  <option v-for="(label, key) in paymentLabels" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">المبلغ</label>
                <input v-model.number="newPayment.amount" type="number" step="0.01" class="w-full rounded-xl border border-white/10 bg-input-bg p-3 text-sm text-text-main outline-none focus:border-gold/50 font-mono" />
              </div>
            </div>
             <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted">نوع حساب الإيداع</label>
                <div class="flex flex-wrap gap-2 mb-2" dir="rtl">
                  <button
                    v-for="chip in settlementCategoryChips"
                    :key="chip.id"
                    type="button"
                    @click="settlementCategoryUi = chip.id"
                    :class="[
                      'flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border transition-all text-[10px] font-bold',
                      settlementCategoryUi === chip.id
                        ? 'bg-white/10 border-gold text-gold'
                        : 'bg-white/[0.02] border-white/10 text-text-muted hover:border-white/20'
                    ]"
                  >
                    <component :is="chip.icon" :class="['h-3 w-3', chip.iconClass]" />
                    {{ chip.label }}
                  </button>
                </div>

                <label class="text-xs font-bold text-text-muted">حساب الإيداع</label>
                <select v-model="newPayment.account_id" class="w-full rounded-xl border border-white/10 bg-input-bg p-3 text-sm text-text-main outline-none focus:border-gold/50">
                  <option :value="null">حساب التسوية الافتراضي</option>
                  <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                </select>
                <p v-if="filteredAccounts.length === 0" class="text-xs text-warning mt-1">لا توجد حسابات متاحة في هذا التصنيف.</p>
              </div>
          </div>

          <div class="mt-8 flex gap-3">
             <button @click="submitPayment" class="flex-1 rounded-2xl bg-success py-4 text-sm font-black text-white shadow-lg shadow-success/20 transition-all hover:scale-[1.02] active:scale-[0.98]">
              حفظ العملية
            </button>
            <button @click="showAddPayment = false" class="flex-1 rounded-2xl bg-white/5 py-4 text-sm font-bold text-text-main transition hover:bg-white/10">إلغاء</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useRoute } from 'vue-router';
import {
  Users,
  Calendar,
  DollarSign,
  CreditCard,
  Edit2,
  ArrowLeft,
  Printer,
  ChevronRight,
  ShieldCheck,
  Plane,
  X,
  Info,
  Banknote,
  Wallet as WalletIcon,
  Landmark,
} from 'lucide-vue-next';

const store = useHajjUmraStore();
const route = useRoute();
const id = computed(() => route.params.id);

const booking = computed(() => store.currentBooking);
const showAddPayment = ref(false);
const showPrintModal = ref(false);

const settlementCategoryUi = ref('cash');
const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: WalletIcon, iconClass: 'text-sky-300' },
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

const printOptions = ref({
  logo: true,
  passengers: true,
  tripDetails: true,
  dates: true,
  price: true,
  baggage: true,
  notes: true,
});

const newPayment = ref({
  payment_method: 'cash',
  amount: 0,
  account_id: null,
  payment_date: new Date().toISOString().split('T')[0],
  paid_by: '',
});

const paymentLabels = {
  cash: 'نقدي',
  bank_transfer: 'تحويل بنكي',
  cash_wallet: 'محفظة كاش',
  postal_transfer: 'تحويل بريدي',
  instapay: 'إنستاباي',
};

const statusLabels = {
  pending: 'قيد الانتظار',
  confirmed: 'مؤكد',
  in_progress: 'قيد التنفيذ',
  completed: 'مكتمل',
  cancelled: 'ملغي',
  refunded: 'مسترد',
};

const statusStyles = {
  pending: 'bg-amber-500/10 text-amber-500',
  confirmed: 'bg-emerald-500/10 text-emerald-500',
  in_progress: 'bg-blue-500/10 text-blue-500',
  completed: 'bg-indigo-500/10 text-indigo-500',
  cancelled: 'bg-rose-500/10 text-rose-500',
  refunded: 'bg-slate-500/10 text-slate-500',
};

const formatMoney = (val) => {
  if (val === undefined || val === null) return '0.00 ج.م';
  return Number(val).toLocaleString('ar-EG', { minimumFractionDigits: 2 }) + ' ج.م';
};

const formatDate = (date) => {
  if (!date) return '—';
  return new Date(date).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
};

const openPrintOptions = () => {
  showPrintModal.value = true;
};

const runPrintJob = async () => {
  showPrintModal.value = false;
  await nextTick();
  const content = document.getElementById('hajj-umra-ticket-content');
  if (!content) return;

  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <html>
      <head>
        <title>وصل حجز - ${booking.value?.customer?.full_name}</title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
        <style>
          body { font-family: 'Cairo', sans-serif; padding: 0; margin: 0; background: white; }
          .hajj-umra-print-document { border: none !important; box-shadow: none !important; width: 100% !important; }
          @media print {
            .no-print { display: none; }
            body { padding: 0; }
          }
        </style>
      </head>
      <body dir="rtl">
        ${content.outerHTML}
        <script>
          window.onload = () => {
            window.print();
            setTimeout(() => window.close(), 500);
          };
        <\/script>
      </body>
    </html>
  `);
  printWindow.document.close();
};

const updateStatus = async (status) => {
  try {
    await store.updateBooking(id.value, { status });
    store.addToast('تم تحديث الحالة بنجاح');
    await store.fetchBookingById(id.value);
  } catch (error) {
    store.addToast(store.errors?.message || 'فشل تحديث الحالة', 'error');
  }
};

const submitPayment = async () => {
  try {
    await store.addPayment(id.value, {
      amount: Number(newPayment.value.amount),
      payment_method: newPayment.value.payment_method,
      account_id: newPayment.value.account_id || booking.value?.finance?.account?.id || null,
      payment_date: newPayment.value.payment_date,
      paid_by: newPayment.value.paid_by || booking.value?.customer?.full_name,
    });
    store.addToast('تم إضافة الدفعة بنجاح');
    showAddPayment.value = false;
    newPayment.value = {
      payment_method: 'cash',
      amount: 0,
      account_id: null,
      payment_date: new Date().toISOString().split('T')[0],
      paid_by: '',
    };
    await store.fetchBookingById(id.value);
  } catch (error) {
    store.addToast(store.errors?.message || 'فشل إضافة الدفعة', 'error');
  }
};

onMounted(async () => {
  await Promise.all([
    store.fetchBookingById(id.value),
    store.fetchAccounts({ module: 'hajj' }),
    store.fetchSettings(),
  ]);
});
</script>

<style scoped>
.hajj-umra-booking {
  --gold: #fbbf24;
  --card-bg: #111827;
  --input-bg: #1f2937;
  --text-main: #f9fafb;
  --text-muted: #9ca3af;
  --success: #10b981;
  --error: #ef4444;
}

.flight-hero {
  background: linear-gradient(135deg, rgba(26, 18, 8, 0.9) 0%, rgba(45, 31, 10, 0.8) 100%);
  padding: 2.5rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(251, 191, 36, 0.1);
  overflow: hidden;
}

.flight-hero::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -20%;
  width: 140%;
  height: 200%;
  background: radial-gradient(circle, rgba(251, 191, 36, 0.05) 0%, transparent 70%);
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
  border-color: rgba(251, 191, 36, 0.2);
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
  background: rgba(251, 191, 36, 0.1);
  border-color: rgba(251, 191, 36, 0.3);
  color: var(--gold);
}

.hajj-umra-print-document {
  display: none;
}

@media print {
  .hajj-umra-print-document {
    display: block;
  }
}
</style>
