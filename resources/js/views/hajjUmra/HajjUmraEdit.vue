<template>
  <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in duration-700">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">تعديل الحجز</h1>
        <p class="text-muted mt-1">حجز #{{ booking?.id }}</p>
      </div>
      <router-link :to="{ name: 'hajj.show', params: { id: booking?.id } }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> رجوع
      </router-link>
    </div>

    <div v-if="store.loading.detail" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="booking" class="space-y-6">
      <div class="p-6 bg-card border border-white/10 rounded-2xl space-y-6">
        <h2 class="text-lg font-bold">تحديث الحجز</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-muted mb-2">الحالة</label>
            <select v-model="form.status" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none">
              <option v-for="s in (store.statuses?.hajj_umra || [])" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs text-muted mb-2">اسم الموظف / الوكيل</label>
            <input v-model="form.agent_name" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
        </div>
        <p class="text-xs text-muted">المشرف وشركة التنفيذ مرتبطان بالبرنامج نفسه؛ يُعدَّلان من فيلامنت على سجل البرنامج.</p>

        <!-- Pricing -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-muted mb-2">سعر الشراء</label>
            <input v-model.number="form.purchase_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
          </div>
          <div>
            <label class="block text-xs text-muted mb-2">سعر البيع</label>
            <input v-model.number="form.selling_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
          </div>
        </div>

        <div class="p-4 bg-input/40 border border-white/10 rounded-xl space-y-3">
          <div class="text-xs text-muted">تسعير سريع — هامش ربح على سعر الشراء:</div>
          <div class="flex flex-wrap gap-2">
            <button v-for="p in [20, 30, 50, 100]" :key="p" type="button"
              @click="applyMarkup(p)"
              class="px-4 py-2 bg-white/5 hover:bg-gold/20 border border-white/10 hover:border-gold rounded-lg text-sm font-bold transition-all">
              +{{ p }}%
            </button>
          </div>
          <div class="text-sm" :class="profitAmount >= 0 ? 'text-success' : 'text-error'">
            صافي الربح: {{ profitAmount.toLocaleString() }} ج.م
          </div>
        </div>

        <div>
          <label class="block text-xs text-muted mb-2">ملاحظات</label>
          <textarea v-model="form.notes" rows="3" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"></textarea>
        </div>

        <div class="flex gap-3">
          <button @click="saveChanges" :disabled="isSaving" class="flex-1 py-3 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2">
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ التغييرات' }}
          </button>
          <router-link :to="{ name: 'hajj.show', params: { id: booking.id } }" class="flex-1 py-3 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all text-center">
            إلغاء
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Loader2 } from 'lucide-vue-next';

const store = useHajjUmraStore();
const route = useRoute();
const router = useRouter();

const booking = computed(() => store.currentBooking);
const isSaving = ref(false);

const form = ref({
  status: 'pending',
  agent_name: '',
  purchase_price: 0,
  selling_price: 0,
  notes: ''
});

const profitAmount = computed(() => Number(form.value.selling_price || 0) - Number(form.value.purchase_price || 0));

const applyMarkup = (percent) => {
  const cost = Number(form.value.purchase_price || 0);
  form.value.selling_price = Math.round(cost * (1 + percent / 100) * 100) / 100;
};

const saveChanges = async () => {
  isSaving.value = true;
  try {
    await store.updateBooking(route.params.id, form.value);
    store.addToast('تم تحديث الحجز بنجاح!');
    await router.push({ name: 'hajj.show', params: { id: route.params.id } });
  } catch (error) {
    store.addToast(store.errors?.message || 'فشل تحديث الحجز', 'error');
  } finally {
    isSaving.value = false;
  }
};

onMounted(async () => {
  await Promise.all([
    store.fetchBookingById(route.params.id),
    store.fetchSettings(),
  ]);
  if (booking.value) {
    form.value.status = booking.value.status || 'pending';
    form.value.agent_name = booking.value.agent_name || '';
    form.value.purchase_price = booking.value.pricing?.purchase_price ?? 0;
    form.value.selling_price = booking.value.pricing?.selling_price ?? 0;
    form.value.notes = booking.value.notes || '';
  }
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
</style>
