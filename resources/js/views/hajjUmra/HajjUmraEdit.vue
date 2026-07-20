<template>
  <div class="max-w-5xl mx-auto space-y-8 pb-16 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-4">
        <router-link
          :to="{ name: 'hajj.show', params: { id: resolvedId } }"
          class="btn-airline-ghost rounded-xl p-2.5 shrink-0"
          aria-label="العودة لتفاصيل الحجز"
        >
          <ArrowLeft class="h-5 w-5 text-emerald-300" />
        </router-link>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-400/90">
            تعديل حجز
          </p>
          <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
            حجز #{{ resolvedId }}
            <span v-if="booking?.program" class="text-base font-normal text-emerald-300/80">
              — {{ booking.program.program_name }}
            </span>
          </h1>
        </div>
      </div>
      <router-link
        :to="{ name: 'hajj.show', params: { id: resolvedId } }"
        class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-sm font-bold transition-all"
      >
        إلغاء التعديل
      </router-link>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto" />
      <p class="text-text-muted mt-4 text-sm">جاري تحميل الحجز...</p>
    </div>

    <div v-else-if="!booking" class="bg-error/10 border border-error/30 rounded-2xl p-6 text-error text-center">
      تعذر العثور على الحجز.
    </div>

    <template v-else>
      <!-- Warning Banner -->
      <div class="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-4 flex items-start gap-3" role="alert">
        <AlertTriangle class="w-6 h-6 text-amber-400 shrink-0 mt-0.5" />
        <div class="text-sm text-amber-100 leading-relaxed">
          <p class="font-bold mb-1">وضع التعديل — تأثيرات محاسبية على الحجز:</p>
          <ul class="list-disc pr-5 space-y-1 text-amber-200/90 text-xs">
            <li><strong>البرنامج (Program)</strong>، <strong>المرافق (Companion)</strong>، <strong>المشرف (Trip Supervisor)</strong>، <strong>شركة التنفيذ (Executing Company)</strong> ثابتة — مرتبطة بسجل البرنامج. عدّلها من Filament على سجل البرنامج نفسه.</li>
            <li>تغيير <strong>سعر البيع / الشراء</strong>، <strong>رسوم الإقامة الإضافية</strong>، أو <strong>تسعير المرافق</strong> ينعكس محاسبياً عبر قيد عكسي "عكس:" ثم قيد جديد (لا تدمير للأصل).</li>
            <li>تغيير <strong>الحالة</strong> من جديدة إلى "ملغي" يجب أن يتم من صفحة التفاصيل (زر الإلغاء) لتطبيق القيود العكسية الصحيحة.</li>
            <li>تعديل شبكة الركاب (Passengers) هنا يحدّث الـ subtotals على الحجز فقط — لا ينشئ سجلات ركاب مستقلة.</li>
          </ul>
        </div>
      </div>

      <!-- Booking Summary Card (read-only) -->
      <section class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <Info class="w-5 h-5 text-emerald-400" />
          ملخص الحجز الحالي (قراءة فقط)
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">الحالة</div>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold" :class="statusBadge(booking.status)">
              {{ statusLabel(booking.status) }}
            </span>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">البرنامج</div>
            <div class="font-bold">{{ booking.program?.program_name || '—' }}</div>
            <div class="text-[10px] text-text-muted">{{ booking.program?.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة' }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">المشرف</div>
            <div>{{ booking.program?.trip_supervisor || '—' }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">شركة التنفيذ</div>
            <div>{{ booking.program?.executing_company || '—' }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">إجمالي البيع</div>
            <div class="font-mono font-bold">{{ formatMoney(totalSelling) }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">إجمالي الشراء</div>
            <div class="font-mono">{{ formatMoney(totalPurchase) }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">صافي الربح</div>
            <div class="font-mono font-bold text-success">{{ formatMoney(totalProfit) }}</div>
          </div>
          <div>
            <div class="text-[11px] uppercase tracking-wider text-text-muted mb-1">العملة</div>
            <div class="font-mono">{{ booking.currency || 'EGP' }}</div>
          </div>
        </div>
      </section>

      <form @submit.prevent="saveChanges" class="space-y-6">
        <!-- 1. Status + Agent -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <Settings class="w-5 h-5 text-text-muted" />
            الحالة والموظف
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">الحالة <span class="text-error">*</span></label>
              <select v-model="form.status" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                <option v-for="s in (store.statuses?.hajj_umra || [])" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">اسم الموظف / الوكيل</label>
              <input v-model="form.agent_name" type="text" maxlength="150" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
            </div>
          </div>
        </section>

        <!-- 2. Pricing -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <DollarSign class="w-5 h-5 text-gold" />
            التسعير والربح
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر الشراء</label>
              <input v-model.number="form.purchase_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر البيع</label>
              <input v-model.number="form.selling_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
            </div>
          </div>

          <div class="p-4 bg-input/40 border border-white/10 rounded-xl space-y-3">
            <div class="text-xs text-text-muted">هامش ربح سريع على سعر الشراء:</div>
            <div class="flex flex-wrap gap-2">
              <button v-for="p in [10, 20, 30, 50, 100]" :key="p" type="button" @click="applyMarkup(p)"
                class="px-4 py-2 bg-white/5 hover:bg-gold/20 border border-white/10 hover:border-gold rounded-lg text-sm font-bold transition-all">
                +{{ p }}%
              </button>
            </div>
            <div class="text-sm" :class="profitAmount >= 0 ? 'text-success' : 'text-error'">
              صافي الربح المتوقع: <span class="font-mono font-bold">{{ formatMoney(profitAmount) }}</span> ج.م
            </div>
          </div>
        </section>

        <!-- 3. Companion & Supplier -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <Users class="w-5 h-5 text-text-muted" />
            المرافق والمورّد
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">المرافق (Companion)</label>
              <select v-model="form.companion_customer_id" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                <option :value="null">— لا يوجد —</option>
                <option v-for="c in customersList" :key="c.id" :value="c.id">{{ c.full_name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر شراء المرافق</label>
              <input v-model.number="form.companion_purchase_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر بيع المرافق</label>
              <input v-model.number="form.companion_selling_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">مورّد العمرة</label>
              <select v-model="form.supplier_id" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
                <option :value="null">— اختر —</option>
                <option v-for="s in suppliersList" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">نوع الإقامة</label>
              <input v-model="form.accommodation_choice" type="text" maxlength="50" placeholder="QUAD / TRIPLE / DOUBLE" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">رسوم الإقامة الإضافية</label>
              <input v-model.number="form.accommodation_extra_charge" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
            </div>
          </div>
        </section>

        <!-- 4. Passengers pricing grid -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <UserCheck class="w-5 h-5 text-text-muted" />
            شبكة تسعير الركاب
          </h2>
          <p class="text-xs text-text-muted">حدّد الفئة، العدد، سعر الوحدة، والسعر الفرعي. الـ subtotal يُحسب آلياً.</p>

          <div class="space-y-3">
            <div v-for="(p, idx) in form.passengers" :key="idx" class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end p-3 bg-white/5 rounded-xl">
              <div>
                <label class="block text-[10px] text-text-muted mb-1">الفئة</label>
                <select v-model="p.category" class="w-full p-2 bg-input border border-white/10 rounded-lg text-sm focus:border-gold outline-none">
                  <option v-for="cat in PASSENGER_CATEGORIES" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] text-text-muted mb-1">العدد</label>
                <input v-model.number="p.count" type="number" min="0" class="w-full p-2 bg-input border border-white/10 rounded-lg text-sm font-mono focus:border-gold outline-none" />
              </div>
              <div>
                <label class="block text-[10px] text-text-muted mb-1">سعر الوحدة</label>
                <input v-model.number="p.unit_price" type="number" min="0" step="0.01" class="w-full p-2 bg-input border border-white/10 rounded-lg text-sm font-mono focus:border-gold outline-none" />
              </div>
              <div>
                <label class="block text-[10px] text-text-muted mb-1">الإجمالي (تلقائي)</label>
                <input :value="p.count * p.unit_price" readonly type="number" class="w-full p-2 bg-black/30 border border-white/10 rounded-lg text-sm font-mono text-gold cursor-not-allowed" />
              </div>
              <div>
                <button type="button" @click="removePassenger(idx)" class="w-full p-2 bg-error/10 hover:bg-error/20 border border-error/30 rounded-lg text-xs font-bold transition-all">
                  حذف
                </button>
              </div>
            </div>
            <button type="button" @click="addPassenger" class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-sm font-bold transition-all">
              + إضافة صف ركاب
            </button>
          </div>
        </section>

        <!-- 5. Notes -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-4">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <MessageSquare class="w-5 h-5 text-text-muted" />
            ملاحظات
          </h2>
          <textarea v-model="form.notes" rows="4" maxlength="1000" placeholder="أي ملاحظات إضافية..."
            class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          <div class="text-[10px] text-text-muted text-left">{{ (form.notes || '').length }} / 1000</div>
        </section>

        <!-- Submit -->
        <div class="flex gap-3 sticky bottom-4 z-30">
          <button type="submit" :disabled="isSaving || !isDirty"
            class="flex-1 py-4 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            <Save v-else class="w-5 h-5" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ التعديلات' }}
          </button>
          <router-link :to="{ name: 'hajj.show', params: { id: resolvedId } }"
            class="flex-1 py-4 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all text-center">
            إلغاء
          </router-link>
        </div>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
  ArrowLeft,
  AlertTriangle,
  Info,
  Settings,
  DollarSign,
  Users,
  UserCheck,
  MessageSquare,
  Loader2,
  Save,
} from 'lucide-vue-next';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';

const props = defineProps({ id: { type: [String, Number], default: null } });

const route = useRoute();
const store = useHajjUmraStore();

const resolvedId = computed(() => props.id ?? route.params.id);
const booking = computed(() => store.currentBooking);
const loading = ref(true);
const isSaving = ref(false);
const customersList = ref([]);
const suppliersList = ref([]);

const PASSENGER_CATEGORIES = [
  { value: 'adult', label: 'بالغ' },
  { value: 'child_with_bed', label: 'طفل بسرير' },
  { value: 'child_no_bed', label: 'طفل بدون سرير' },
  { value: 'infant', label: 'رضيع' },
];

function initialForm() {
  return {
    status: 'pending',
    agent_name: '',
    notes: '',
    purchase_price: 0,
    selling_price: 0,
    companion_customer_id: null,
    companion_purchase_price: 0,
    companion_selling_price: 0,
    supplier_id: null,
    accommodation_choice: '',
    accommodation_extra_charge: 0,
    passengers: [],
  };
}

const form = ref(initialForm());
const original = ref(initialForm());

function hydrate() {
  const b = booking.value;
  if (!b) return;
  const next = {
    status: b.status || 'pending',
    agent_name: b.agent_name || '',
    notes: b.notes || '',
    purchase_price: Number(b.pricing?.purchase_price ?? b.purchase_price ?? 0),
    selling_price: Number(b.pricing?.selling_price ?? b.selling_price ?? 0),
    companion_customer_id: b.companion?.id ?? null,
    companion_purchase_price: Number(b.companion_purchase_price ?? 0),
    companion_selling_price: Number(b.companion_selling_price ?? 0),
    supplier_id: b.supplier?.id ?? null,
    accommodation_choice: b.accommodation_choice || '',
    accommodation_extra_charge: Number(b.accommodation_extra_charge ?? 0),
    passengers: Array.isArray(b.passengers) ? b.passengers.map((p) => ({
      category: p.category || 'adult',
      count: Number(p.count || 0),
      unit_price: Number(p.unit_price || 0),
      subtotal: Number(p.subtotal || p.count * p.unit_price || 0),
    })) : [],
  };
  form.value = next;
  original.value = JSON.parse(JSON.stringify(next));
}

const totalSelling = computed(() => {
  const paxSum = form.value.passengers.reduce((s, p) => s + (Number(p.count) * Number(p.unit_price)), 0);
  return (Number(form.value.selling_price) + Number(form.value.companion_selling_price) + Number(form.value.accommodation_extra_charge)) + (paxSum || 0);
});

const totalPurchase = computed(() => {
  const paxSum = form.value.passengers.reduce((s, p) => s + (Number(p.count) * Number(p.unit_price * 0.8 || 0)), 0); // approximation only
  return (Number(form.value.purchase_price) + Number(form.value.companion_purchase_price)) + (paxSum || 0);
});

const totalProfit = computed(() => totalSelling.value - totalPurchase.value);

const profitAmount = computed(() => Number(form.value.selling_price || 0) - Number(form.value.purchase_price || 0));

const isDirty = computed(() => JSON.stringify(form.value) !== JSON.stringify(original.value));

function applyMarkup(percent) {
  const cost = Number(form.value.purchase_price || 0);
  form.value.selling_price = Math.round(cost * (1 + percent / 100) * 100) / 100;
}

function addPassenger() {
  form.value.passengers.push({ category: 'adult', count: 1, unit_price: 0, subtotal: 0 });
}

function removePassenger(idx) {
  form.value.passengers.splice(idx, 1);
}

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusLabel(s) {
  const map = {
    draft: 'مسودة',
    pending: 'قيد الانتظار',
    confirmed: 'مؤكد',
    cancelled: 'ملغي',
    completed: 'مكتمل',
    refunded: 'مسترد',
    in_progress: 'قيد التنفيذ',
    partial: 'دفع جزئي',
  };
  return map[s] ?? s ?? '—';
}

function statusBadge(s) {
  switch (s) {
    case 'confirmed':
    case 'completed':
    case 'paid':
      return 'bg-success/20 text-success';
    case 'cancelled':
    case 'refunded':
      return 'bg-error/20 text-error';
    case 'pending':
    case 'partial':
    case 'in_progress':
      return 'bg-warning/20 text-warning';
    case 'draft':
      return 'bg-white/10 text-text-muted';
    default:
      return 'bg-emerald-500/20 text-emerald-300';
  }
}

async function saveChanges() {
  if (!isDirty.value) return;
  isSaving.value = true;
  try {
    // Update form.passengers subtotals before saving
    const payload = {
      ...form.value,
      passengers: form.value.passengers.map((p) => ({
        ...p,
        subtotal: Number(p.count) * Number(p.unit_price),
      })),
    };
    await store.updateBooking(resolvedId.value, payload);
    store.addToast('تم تحديث الحجز بنجاح!');
    await new Promise((r) => setTimeout(r, 200));
    window.location.href = `/hajj-umra/bookings/${resolvedId.value}`;
  } catch (error) {
    store.addToast(store.errors?.message || 'فشل تحديث الحجز', 'error');
  } finally {
    isSaving.value = false;
  }
}

onMounted(async () => {
  try {
    await Promise.all([
      store.fetchBookingById(resolvedId.value),
      store.fetchSettings(),
      fetch('/api/v1/customers?per_page=200', { headers: { Accept: 'application/json' }, credentials: 'include' })
        .then((r) => r.json())
        .then((j) => { customersList.value = j?.data?.items ?? j?.data ?? []; })
        .catch(() => { customersList.value = []; }),
      fetch('/api/v1/umrah-suppliers', { headers: { Accept: 'application/json' }, credentials: 'include' })
        .then((r) => r.json())
        .then((j) => { suppliersList.value = j?.data ?? []; })
        .catch(() => { suppliersList.value = []; }),
    ]);
    hydrate();
  } finally {
    loading.value = false;
  }
});

watch(booking, () => hydrate());
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
.btn-airline-ghost { background-color: rgba(255,255,255,0.04); }
.animate-in { animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
