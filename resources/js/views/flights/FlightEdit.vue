<template>
  <div class="flight-edit-page max-w-7xl mx-auto space-y-8 pb-16 animate-in fade-in duration-700">
    <!-- Top bar -->
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-4">
        <router-link
          :to="{ name: 'flights.show', params: { id: resolvedId } }"
          class="btn-airline-ghost rounded-xl p-2.5 shrink-0"
          aria-label="العودة لتفاصيل الحجز"
        >
          <ArrowRight class="h-5 w-5 text-sky-300" />
        </router-link>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">
            تعديل حجز طيران
          </p>
          <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
            حجز #{{ resolvedId }}
          </h1>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <router-link
          :to="{ name: 'flights.show', params: { id: resolvedId } }"
          class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-sm font-bold transition-all"
        >
          <X class="w-4 h-4 inline-block ml-1" />
          إلغاء التعديل
        </router-link>
      </div>
    </div>

    <!-- Warning banner — Edit Mode Accounting Implications -->
    <div
      class="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-4 flex items-start gap-3"
      role="alert"
    >
      <AlertTriangle class="w-6 h-6 text-amber-400 shrink-0 mt-0.5" />
      <div class="text-sm text-amber-100 leading-relaxed">
        <p class="font-bold mb-1">وضع التعديل — تأثيرات محاسبية:</p>
        <ul class="list-disc pr-5 space-y-1 text-amber-200/90 text-xs">
          <li>تغيير سعر البيع أو الشراء سيُنشئ قيد عكسي مع بادئة "عكس:" ثم يُسجّل قيد جديد (لا تدمير للأصل).</li>
          <li>تعديل عدد الركاب أو حذف راكب قد يستلزم تعديل القيود المرتبطة بحركة المجموعة (FlightGroup).</li>
          <li>أي تغيير محاسبي هنا ينعكس على خزينة المديونيات وميزان الحسابات فوراً بعد الحفظ.</li>
        </ul>
      </div>
    </div>

    <!-- Booking Summary card -->
    <div v-if="booking" class="flight-panel">
      <div class="flex items-center gap-3 mb-6">
        <div class="dashboard-kpi__icon !h-12 !w-12 shrink-0">
          <Plane class="h-6 w-6" />
        </div>
        <div>
          <h2 class="text-lg font-black">ملخص الحجز الحالي</h2>
          <p class="text-xs text-text-muted">قراءة فقط — هذه البيانات ستبقى كما هي حتى تضغط "حفظ التعديلات" في الأسفل.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">الحالة</div>
          <span
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold"
            :class="statusBadge(booking.status)"
          >
            {{ statusLabel(booking.status) }}
          </span>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">رقم الحجز</div>
          <div class="font-mono font-bold text-gold">#{{ booking.id }}</div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">العملة</div>
          <div class="font-mono">{{ booking.currency || 'EGP' }}</div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">أنشأه</div>
          <div>{{ booking.createdBy || '—' }}</div>
          <div class="text-[11px] text-text-muted">{{ formatDate(booking.createdAt) }}</div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">إجمالي البيع</div>
          <div class="font-mono font-bold">{{ formatMoney(booking.sellingPrice) }}</div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">إجمالي الشراء</div>
          <div class="font-mono">{{ formatMoney(booking.purchasePrice) }}</div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">صافي الربح</div>
          <div class="font-mono font-bold text-success">
            {{ formatMoney(booking.profit) }}
          </div>
        </div>
        <div>
          <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">المدفوع</div>
          <div class="font-mono">{{ formatMoney(booking.totalPaid) }} / {{ formatMoney(booking.sellingPrice) }}</div>
        </div>
      </div>

      <!-- Modification History -->
      <div v-if="modifications.length" class="mt-6 pt-6 border-t border-white/10">
        <h3 class="text-sm font-bold mb-3 flex items-center gap-2">
          <History class="w-4 h-4 text-text-muted" />
          سجل التعديلات المحاسبية
        </h3>
        <ol class="space-y-2 text-xs">
          <li
            v-for="(m, idx) in modifications"
            :key="idx"
            class="flex items-center justify-between p-2.5 bg-white/5 rounded-lg"
          >
            <span class="font-mono text-text-muted">{{ formatDate(m.date) }}</span>
            <span
              class="px-2 py-0.5 rounded text-[10px] font-bold"
              :class="m.type === 'reversal' ? 'bg-amber-500/20 text-amber-300' : 'bg-sky-500/20 text-sky-300'"
            >
              {{ m.type === 'reversal' ? 'عكس' : 'تسجيل' }}
            </span>
            <span class="font-mono">{{ formatMoney(m.amount) }}</span>
            <span class="text-text-muted truncate max-w-[40%]" :title="m.notes">{{ m.notes }}</span>
          </li>
        </ol>
      </div>
    </div>

    <!-- Loading state -->
    <div v-else-if="loading" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto" />
      <p class="text-text-muted mt-4 text-sm">جاري تحميل بيانات الحجز...</p>
    </div>

    <!-- Embedded FlightCreate (edit mode) -->
    <FlightCreate
      v-if="booking"
      :is-edit="true"
      :booking-id="resolvedId"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  ArrowRight,
  AlertTriangle,
  Plane,
  X,
  History,
} from 'lucide-vue-next';
import FlightCreate from './FlightCreate.vue';
import { useFlightStore } from '@/stores/flightStore';

const props = defineProps({ id: { type: [String, Number], default: null } });

const route = useRoute();
const router = useRouter();
const store = useFlightStore();

// Accept id from route params (in case the parent route didn't forward props)
const resolvedId = computed(() => props.id ?? route.params.id);

const booking = computed(() => store.currentBooking);
const loading = ref(true);
const modifications = ref([]);

onMounted(async () => {
  try {
    await store.fetchBookingById(resolvedId.value);
    // Try to fetch modification history if endpoint exists; ignore if 404.
    try {
      const res = await fetch(`/api/v1/flight/bookings/${resolvedId.value}/modifications`, {
        headers: { Accept: 'application/json' },
        credentials: 'include',
      });
      if (res.ok) {
        const json = await res.json();
        modifications.value = json?.data ?? [];
      }
    } catch {
      // endpoint not yet wired — gracefully fall back to empty list
      modifications.value = [];
    }
  } finally {
    loading.value = false;
  }
});

// ---------- Helpers ----------
function formatMoney(n) {
  const v = Number(n ?? 0);
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

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

const STATUS_LABELS = {
  draft: 'مسودة',
  pending: 'قيد الانتظار',
  confirmed: 'مؤكد',
  cancelled: 'ملغي',
  refunded: 'مسترد',
  completed: 'مكتمل',
  partial: 'دفع جزئي',
  unpaid: 'غير مدفوع',
  paid: 'مدفوع',
};

function statusLabel(s) {
  return STATUS_LABELS[s] ?? s ?? '—';
}

function statusBadge(s) {
  switch (s) {
    case 'confirmed':
    case 'paid':
    case 'completed':
      return 'bg-success/20 text-success';
    case 'cancelled':
    case 'refunded':
      return 'bg-error/20 text-error';
    case 'pending':
    case 'partial':
      return 'bg-warning/20 text-warning';
    case 'draft':
      return 'bg-white/10 text-text-muted';
    default:
      return 'bg-sky-500/20 text-sky-300';
  }
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
.flight-panel { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 1.5rem; }
.btn-airline-ghost { background-color: rgba(255,255,255,0.04); }
.animate-in { animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
