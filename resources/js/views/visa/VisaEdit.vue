<template>
  <div class="max-w-5xl mx-auto space-y-8 pb-16 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-4">
        <router-link
          :to="{ name: 'visa.show', params: { id: bookingId } }"
          class="btn-airline-ghost rounded-xl p-2.5 shrink-0"
          aria-label="العودة لتفاصيل الطلب"
        >
          <ArrowLeft class="h-5 w-5 text-sky-300" />
        </router-link>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">
            تعديل طلب تأشيرة
          </p>
          <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">
            طلب #{{ bookingId }}
          </h1>
        </div>
      </div>
      <router-link
        :to="{ name: 'visa.show', params: { id: bookingId } }"
        class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-sm font-bold transition-all"
      >
        إلغاء التعديل
      </router-link>
    </div>

    <!-- Loading / Error -->
    <div v-if="store.loading.detail" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto" />
      <p class="text-text-muted mt-4 text-sm">جاري تحميل الطلب...</p>
    </div>

    <div v-else-if="!booking" class="bg-error/10 border border-error/30 rounded-2xl p-6 text-error text-center">
      تعذر العثور على الطلب — ربما تم حذفه.
    </div>

    <template v-else>
      <!-- Warning Banner -->
      <div class="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-4 flex items-start gap-3" role="alert">
        <AlertTriangle class="w-6 h-6 text-amber-400 shrink-0 mt-0.5" />
        <div class="text-sm text-amber-100 leading-relaxed">
          <p class="font-bold mb-1">وضع التعديل — جميع الحقول أدناه قابلة للتغيير:</p>
          <ul class="list-disc pr-5 space-y-1 text-amber-200/90 text-xs">
            <li>تغيير <strong>سعر البيع</strong> أو <strong>سعر الشراء</strong> أو <strong>رسوم الخدمة</strong> ينعكس محاسبياً: قيد عكسي بـ "عكس:" ثم قيد جديد (لا تدمير).</li>
            <li>تغيير <strong>الحالة</strong> من جديدة إلى "ملغي" يحتاج استخدام زر الإلغاء في صفحة التفاصيل لتطبيق القيود العكسية الصحيحة.</li>
            <li>كل حقول <strong>تفاصيل التأشيرة</strong> قابلة للتعديل بما فيها الوكيل، رقم التأشيرة، التواريخ.</li>
          </ul>
        </div>
      </div>

      <form @submit.prevent="saveChanges" class="space-y-6">
        <!-- 1. Status + Agent -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <Settings class="w-5 h-5 text-text-muted" />
            حالة الطلب
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">
                الحالة <span class="text-error">*</span>
              </label>
              <select
                v-model="form.status"
                required
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              >
                <option v-for="s in (store.statuses?.visa || [])" :key="s.value" :value="s.value">
                  {{ s.label }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">اسم الموظف / الوكيل</label>
              <input
                v-model="form.agent_name"
                type="text"
                maxlength="150"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
          </div>
        </section>

        <!-- 2. Pricing -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <DollarSign class="w-5 h-5 text-gold" />
            التسعير والربح
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر الشراء</label>
              <input
                v-model.number="form.purchase_price"
                type="number"
                min="0"
                step="0.01"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">سعر البيع</label>
              <input
                v-model.number="form.selling_price"
                type="number"
                min="0"
                step="0.01"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">رسوم الخدمة</label>
              <input
                v-model.number="form.service_fee"
                type="number"
                min="0"
                step="0.01"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
              />
            </div>
          </div>

          <!-- Quick markup -->
          <div class="p-4 bg-input/40 border border-white/10 rounded-xl space-y-3">
            <div class="text-xs text-text-muted">تسعير سريع — هامش ربح على سعر الشراء:</div>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="p in [10, 20, 30, 50, 100]"
                :key="p"
                @click="applyMarkup(p)"
                type="button"
                class="px-4 py-2 bg-white/5 hover:bg-gold/20 border border-white/10 hover:border-gold rounded-lg text-sm font-bold transition-all"
              >
                +{{ p }}%
              </button>
            </div>
            <div class="text-sm" :class="profitAmount >= 0 ? 'text-success' : 'text-error'">
              صافي الربح المتوقع: <span class="font-mono font-bold">{{ formatMoney(profitAmount) }}</span> ج.م
            </div>
          </div>
        </section>

        <!-- 3. Visa Details -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-6">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <FileText class="w-5 h-5 text-text-muted" />
            تفاصيل التأشيرة
          </h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-text-muted mb-2">نوع التأشيرة</label>
              <select
                v-model="form.visa_details.visa_type"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              >
                <option v-for="t in (store.statuses?.visa_types || [])" :key="t.value" :value="t.value">
                  {{ t.label }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">الدولة</label>
              <input
                v-model="form.visa_details.country"
                type="text"
                maxlength="100"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">المدّة</label>
              <select
                v-model="form.visa_details.visa_duration_id"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              >
                <option :value="null">— اختر —</option>
                <option v-for="d in store.durations" :key="d.id" :value="d.id">
                  {{ d.label }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">نوع الدخول</label>
              <select
                v-model="form.visa_details.entry_type"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              >
                <option v-for="e in (store.statuses?.visa_entry_types || [])" :key="e.value" :value="e.value">
                  {{ e.label }}
                </option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs text-text-muted mb-2">الوكيل</label>
              <select
                v-model="form.visa_details.visa_agent_id"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              >
                <option :value="null">— اختر —</option>
                <option v-for="a in store.agents" :key="a.id" :value="a.id">
                  {{ a.name }}
                </option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs text-text-muted mb-2">رقم التأشيرة</label>
              <input
                v-model="form.visa_details.visa_number"
                type="text"
                maxlength="100"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">تاريخ التقديم</label>
              <input
                v-model="form.visa_details.submission_date"
                type="date"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">تاريخ النتيجة المتوقعة</label>
              <input
                v-model="form.visa_details.expected_result_date"
                type="date"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">صلاحية من</label>
              <input
                v-model="form.visa_details.validity_from"
                type="date"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-xs text-text-muted mb-2">صلاحية إلى</label>
              <input
                v-model="form.visa_details.validity_to"
                type="date"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
              />
            </div>
          </div>

          <!-- Executing company/agent (advanced) -->
          <details class="group">
            <summary
              class="cursor-pointer text-xs text-text-muted hover:text-white select-none transition-colors list-none flex items-center gap-1"
            >
              <ChevronDown class="w-3 h-3 transition-transform group-open:rotate-180" />
              حقول إضافية (الشركة المنفذة / الوكيل التنفيذي)
            </summary>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
              <div>
                <label class="block text-xs text-text-muted mb-2">الشركة المنفذة</label>
                <input
                  v-model="form.visa_details.executing_company"
                  type="text"
                  maxlength="150"
                  class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                />
              </div>
              <div>
                <label class="block text-xs text-text-muted mb-2">الوكيل التنفيذي</label>
                <input
                  v-model="form.visa_details.executing_agent"
                  type="text"
                  maxlength="150"
                  class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                />
              </div>
              <div>
                <label class="block text-xs text-text-muted mb-2">جهة اتصال الوكيل</label>
                <input
                  v-model="form.visa_details.executing_agent_contact"
                  type="text"
                  maxlength="150"
                  class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
                />
              </div>
            </div>
          </details>
        </section>

        <!-- 4. Notes -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 space-y-4">
          <h2 class="text-lg font-bold flex items-center gap-2">
            <MessageSquare class="w-5 h-5 text-text-muted" />
            ملاحظات
          </h2>
          <textarea
            v-model="form.notes"
            rows="4"
            maxlength="1000"
            placeholder="أي ملاحظات إضافية على الطلب..."
            class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
          />
          <div class="text-[10px] text-text-muted text-left">{{ (form.notes || '').length }} / 1000</div>
        </section>

        <!-- Submit -->
        <div class="flex gap-3 sticky bottom-4 z-30">
          <button
            type="submit"
            :disabled="isSaving || !isDirty"
            class="flex-1 py-4 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            <Save v-else class="w-5 h-5" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ التعديلات' }}
          </button>
          <router-link
            :to="{ name: 'visa.show', params: { id: bookingId } }"
            class="flex-1 py-4 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all text-center"
          >
            إلغاء
          </router-link>
        </div>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useVisaStore } from '@/stores/visaStore';
import {
  ArrowLeft,
  AlertTriangle,
  Settings,
  DollarSign,
  FileText,
  MessageSquare,
  ChevronDown,
  Loader2,
  Save,
} from 'lucide-vue-next';

const props = defineProps({ id: { type: [String, Number], default: null } });

const route = useRoute();
const router = useRouter();
const store = useVisaStore();

const bookingId = computed(() => props.id ?? route.params.id);
const booking = computed(() => store.currentBooking);
const isSaving = ref(false);

function initialForm() {
  return {
    status: 'draft',
    agent_name: '',
    notes: '',
    purchase_price: 0,
    selling_price: 0,
    service_fee: 0,
    visa_details: {
      visa_type: 'tourist',
      country: '',
      duration: '',
      visa_duration_id: null,
      entry_type: 'single',
      visa_agent_id: null,
      visa_number: '',
      validity_from: null,
      validity_to: null,
      executing_company: '',
      executing_agent: '',
      executing_agent_contact: '',
      submission_date: null,
      expected_result_date: null,
    },
  };
}

const form = ref(initialForm());
const original = ref(initialForm());

function hydrate() {
  if (!booking.value) return;
  const b = booking.value;
  const next = {
    status: b.status || 'draft',
    agent_name: b.agent_name || '',
    notes: b.notes || '',
    purchase_price: Number(b.pricing?.purchase_price ?? b.purchase_price ?? 0),
    selling_price: Number(b.pricing?.selling_price ?? b.selling_price ?? 0),
    service_fee: Number(b.pricing?.service_fee ?? b.service_fee ?? 0),
    visa_details: {
      visa_type: b.visa_detail?.visa_type || 'tourist',
      country: b.visa_detail?.country || '',
      duration: b.visa_detail?.duration || '',
      visa_duration_id: b.visa_detail?.duration_row?.id || null,
      entry_type: b.visa_detail?.entry_type || 'single',
      visa_agent_id: b.visa_detail?.agent?.id || null,
      visa_number: b.visa_detail?.visa_number || '',
      validity_from: b.visa_detail?.validity_from || null,
      validity_to: b.visa_detail?.validity_to || null,
      executing_company: b.visa_detail?.executing_company || '',
      executing_agent: b.visa_detail?.executing_agent || '',
      executing_agent_contact: b.visa_detail?.executing_agent_contact || '',
      submission_date: b.visa_detail?.submission_date || null,
      expected_result_date: b.visa_detail?.expected_result_date || null,
    },
  };
  form.value = next;
  original.value = JSON.parse(JSON.stringify(next));
}

const profitAmount = computed(
  () =>
    Number(form.value.selling_price || 0) +
    Number(form.value.service_fee || 0) -
    Number(form.value.purchase_price || 0),
);

const isDirty = computed(() => JSON.stringify(form.value) !== JSON.stringify(original.value));

function applyMarkup(percent) {
  const cost = Number(form.value.purchase_price || 0);
  form.value.selling_price = Math.round(cost * (1 + percent / 100) * 100) / 100;
}

function formatMoney(n) {
  const v = Number(n || 0);
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function saveChanges() {
  if (!isDirty.value) return;
  isSaving.value = true;
  try {
    await store.updateBooking(bookingId.value, form.value);
    store.addToast('تم تحديث الطلب بنجاح!');
    await router.push({ name: 'visa.show', params: { id: bookingId.value } });
  } catch (error) {
    store.addToast(store.errors?.message || 'فشل تحديث الطلب', 'error');
  } finally {
    isSaving.value = false;
  }
}

onMounted(async () => {
  await Promise.all([
    store.fetchBookingById(bookingId.value),
    store.fetchSettings(),
    store.fetchAccounts(),
  ]);
  hydrate();
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
details summary::-webkit-details-marker { display: none; }
details summary::marker { display: none; content: ''; }
.animate-in { animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
