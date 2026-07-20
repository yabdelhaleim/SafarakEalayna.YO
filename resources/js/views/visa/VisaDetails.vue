<template>
  <div class="visa-details-page max-w-4xl mx-auto space-y-8 pb-16 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-4">
        <router-link
          :to="{ name: 'visa.show', params: { id: bookingId } }"
          class="btn-airline-ghost rounded-xl p-2.5 shrink-0"
          aria-label="العودة لتفاصيل الطلب"
        >
          <ArrowLeft class="h-5 w-5 text-indigo-300" />
        </router-link>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-indigo-400/90">
            تفاصيل التأشيرة
          </p>
          <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
            طلب #{{ bookingId }}
          </h1>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <router-link
          :to="{ name: 'visa.edit', params: { id: bookingId } }"
          class="px-5 py-2.5 rounded-xl bg-gold/20 hover:bg-gold/30 border border-gold/40 text-sm font-bold transition-all"
        >
          <Edit2 class="w-4 h-4 inline-block ml-1" />
          تعديل التفاصيل
        </router-link>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto" />
      <p class="text-text-muted mt-4 text-sm">جاري تحميل تفاصيل التأشيرة...</p>
    </div>

    <div v-else-if="!visa" class="bg-error/10 border border-error/30 rounded-2xl p-6 text-error text-center">
      تعذر العثور على تفاصيل التأشيرة.
    </div>

    <div v-else class="space-y-6">
      <!-- Visa Identity -->
      <section class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <FileText class="w-5 h-5 text-indigo-400" />
          هوية التأشيرة
        </h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <Field label="نوع التأشيرة" :value="formatType(visa.visa_type)" />
          <Field label="الدولة" :value="visa.country" />
          <Field label="المدّة" :value="durationLabel" />
          <Field label="نوع الدخول" :value="formatEntry(visa.entry_type)" />
          <Field label="رقم التأشيرة" :value="visa.visa_number || '—'" mono />
          <Field label="الوكيل" :value="visa.agent?.name || '—'" />
        </dl>
      </section>

      <!-- Dates Timeline -->
      <section class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <Calendar class="w-5 h-5 text-text-muted" />
          التواريخ
        </h2>
        <ol class="relative border-r-2 border-white/10 pr-6 space-y-6">
          <TimelineDot
            v-if="visa.submission_date"
            label="تاريخ التقديم"
            :value="visa.submission_date"
            color="bg-sky-500"
          />
          <TimelineDot
            v-if="visa.expected_result_date"
            label="تاريخ النتيجة المتوقعة"
            :value="visa.expected_result_date"
            color="bg-warning"
          />
          <TimelineDot
            v-if="visa.validity_from"
            label="صلاحية من"
            :value="visa.validity_from"
            color="bg-success"
          />
          <TimelineDot
            v-if="visa.validity_to"
            label="صلاحية إلى"
            :value="visa.validity_to"
            color="bg-indigo-500"
          />
        </ol>
        <div v-if="!visa.submission_date && !visa.expected_result_date && !visa.validity_from && !visa.validity_to" class="text-text-muted text-sm text-center py-4">
          لا توجد تواريخ مسجلة بعد.
        </div>
      </section>

      <!-- Executing Company -->
      <section v-if="visa.executing_company || visa.executing_agent" class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <Building class="w-5 h-5 text-text-muted" />
          الشركة المنفذة
        </h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <Field label="الشركة المنفذة" :value="visa.executing_company || '—'" />
          <Field label="الوكيل التنفيذي" :value="visa.executing_agent || '—'" />
          <Field label="جهة الاتصال" :value="visa.executing_agent_contact || '—'" />
        </dl>
      </section>

      <!-- Modification History -->
      <section v-if="modifications.length" class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <History class="w-5 h-5 text-text-muted" />
          سجل التعديلات المحاسبية
        </h2>
        <ol class="space-y-2 text-sm">
          <li
            v-for="(m, idx) in modifications"
            :key="idx"
            class="grid grid-cols-1 md:grid-cols-4 gap-2 items-center p-3 bg-white/5 rounded-lg"
          >
            <span class="font-mono text-text-muted text-xs">{{ formatDate(m.date) }}</span>
            <span
              class="px-2 py-0.5 rounded text-[10px] font-bold w-fit"
              :class="m.type === 'reversal' ? 'bg-amber-500/20 text-amber-300' : 'bg-sky-500/20 text-sky-300'"
            >
              {{ m.type === 'reversal' ? 'عكس' : 'تسجيل' }}
            </span>
            <span class="font-mono">{{ formatMoney(m.amount) }}</span>
            <span class="text-text-muted truncate" :title="m.notes">{{ m.notes }}</span>
          </li>
        </ol>
      </section>

      <!-- Status -->
      <section class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <Info class="w-5 h-5 text-text-muted" />
          الحالة الراهنة
        </h2>
        <div class="flex items-center gap-3">
          <span
            class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold uppercase"
            :class="statusBadge(booking.status)"
          >
            {{ statusLabel(booking.status) }}
          </span>
          <span v-if="visa.status && visa.status !== booking.status" class="text-xs text-text-muted">
            (حالة التأشيرة: {{ visa.status }})
          </span>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useVisaStore } from '@/stores/visaStore';
import {
  ArrowLeft,
  Edit2,
  FileText,
  Calendar,
  Building,
  History,
  Info,
} from 'lucide-vue-next';

const props = defineProps({ id: { type: [String, Number], default: null } });

const route = useRoute();
const router = useRouter();
const store = useVisaStore();

const bookingId = computed(() => props.id ?? route.params.id);
const booking = computed(() => store.currentBooking);
const visa = computed(() => booking.value?.visa_detail || null);
const loading = ref(true);
const modifications = ref([]);

onMounted(async () => {
  try {
    await store.fetchBookingById(bookingId.value);
    await store.fetchSettings();
    try {
      const res = await fetch(`/api/v1/visa/bookings/${bookingId.value}/modifications`, {
        headers: { Accept: 'application/json' },
        credentials: 'include',
      });
      if (res.ok) {
        const json = await res.json();
        modifications.value = json?.data ?? [];
      }
    } catch {
      modifications.value = [];
    }
  } finally {
    loading.value = false;
  }
});

// Field component (inline)
const Field = defineComponent({
  name: 'Field',
  props: { label: String, value: [String, Number], mono: Boolean },
  setup(props) {
    return () =>
      h('div', null, [
        h('dt', { class: 'text-xs text-text-muted' }, props.label),
        h(
          'dd',
          { class: ['mt-1 font-bold', props.mono && 'font-mono'] },
          props.value ?? '—',
        ),
      ]);
  },
});

// TimelineDot component (inline)
const TimelineDot = defineComponent({
  name: 'TimelineDot',
  props: { label: String, value: String, color: String },
  setup(props) {
    return () =>
      h('li', { class: 'relative' }, [
        h('span', {
          class: ['absolute -right-[31px] top-1 h-4 w-4 rounded-full border-4 border-bg-card', props.color],
        }),
        h('div', { class: 'text-xs text-text-muted mb-1' }, props.label),
        h('div', { class: 'font-mono font-bold' }, props.value),
      ]);
  },
});

const STATUS_LABELS = {
  draft: 'مسودة',
  pending: 'قيد الانتظار',
  submitted: 'مقدَّم',
  approved: 'موافق عليه',
  rejected: 'مرفوض',
  cancelled: 'ملغي',
  refunded: 'مسترد',
  issued: 'صادر',
  expired: 'منتهي',
  completed: 'مكتمل',
};

function statusLabel(s) {
  return STATUS_LABELS[s] ?? s ?? '—';
}

function statusBadge(s) {
  switch (s) {
    case 'approved':
    case 'issued':
    case 'completed':
      return 'bg-success/20 text-success';
    case 'cancelled':
    case 'rejected':
    case 'refunded':
    case 'expired':
      return 'bg-error/20 text-error';
    case 'pending':
    case 'submitted':
      return 'bg-warning/20 text-warning';
    case 'draft':
      return 'bg-white/10 text-text-muted';
    default:
      return 'bg-indigo-500/20 text-indigo-300';
  }
}

const VISA_TYPE_LABELS = {
  tourist: 'سياحية',
  business: 'عمل',
  work: 'عمل وتأشيرة عمل',
  student: 'دراسية',
  family: 'عائلية',
  transit: 'ترانزيت',
  pilgrimage: 'حج/عمرة',
  medical: 'طبية',
  other: 'أخرى',
};

const ENTRY_LABELS = {
  single: 'دخول واحد',
  multiple: 'دخول متعدد',
  double: 'دخول مرتين',
};

function formatType(t) {
  return VISA_TYPE_LABELS[t] ?? t ?? '—';
}

function formatEntry(e) {
  return ENTRY_LABELS[e] ?? e ?? '—';
}

const durationLabel = computed(() => {
  const d = visa.value?.duration_row;
  if (d?.label) return d.label;
  return visa.value?.duration || '—';
});

function formatDate(s) {
  if (!s) return '—';
  try {
    return new Date(s).toLocaleString('ar-EG', {
      year: 'numeric',
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return s;
  }
}

function formatMoney(n) {
  const v = Number(n || 0);
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning); }
.border-bg-card { border-color: var(--card-bg); }
.btn-airline-ghost { background-color: rgba(255,255,255,0.04); }
.animate-in { animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
