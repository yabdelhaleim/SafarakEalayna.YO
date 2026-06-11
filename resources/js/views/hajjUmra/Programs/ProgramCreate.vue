<template>
  <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-700">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">إنشاء برنامج جديد</h1>
        <p class="text-muted mt-1">إضافة برنامج حج أو عمرة جديد</p>
      </div>
      <router-link :to="{ name: 'hajj.programs.list' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> رجوع
      </router-link>
    </div>

    <div class="p-8 bg-card border border-white/10 rounded-2xl space-y-6">
      <form @submit.prevent="saveProgram" class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold mb-4">المعلومات الأساسية</h3>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">اسم البرنامج</label>
            <input v-model="form.program_name" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">نوع البرنامج</label>
            <select v-model="form.program_type" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
              <option value="">اختر النوع...</option>
              <option value="hajj">🕋 حج</option>
              <option value="umra">🕋 عمرة</option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">الموسم</label>
            <input v-model="form.season" type="text" placeholder="مثال: رمضان 2026" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">عدد الليالي</label>
            <input v-model.number="form.total_nights" type="number" min="1" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div class="md:col-span-2 mt-4">
            <h3 class="text-lg font-bold mb-4">الفنادق والإقامة</h3>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">فندق مكة</label>
            <input v-model="form.mecca_hotel_name" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">مدة الإقامة بمكة (ليالي)</label>
            <input v-model.number="form.mecca_nights" type="number" min="0" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">فندق المدينة</label>
            <input v-model="form.medina_hotel_name" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">مدة الإقامة بالمدينة (ليالي)</label>
            <input v-model.number="form.medina_nights" type="number" min="0" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div class="md:col-span-2">
            <label class="block text-xs text-muted mb-2">نوع التسكين</label>
            <select v-model="form.accommodation_type" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
              <option value="">اختر نوع التسكين...</option>
              <option value="single">منفرد</option>
              <option value="double">مزدوج</option>
              <option value="triple">ثلاثي</option>
              <option value="quad">رباعي</option>
            </select>
          </div>

          <div class="md:col-span-2 mt-4">
            <h3 class="text-lg font-bold mb-4">السفر والطيران</h3>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">اسم الطيران</label>
            <input v-model="form.airline" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">الشركة المنفذة</label>
            <input v-model="form.executing_company" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">مشرف الرحلة</label>
            <input v-model="form.trip_supervisor" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">منفذ السفر</label>
            <input v-model="form.departure_point" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تاريخ السفر</label>
            <input v-model="form.departure_date" type="date" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تاريخ العودة</label>
            <input v-model="form.return_date" type="date" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div class="md:col-span-2 mt-4">
            <h3 class="text-lg font-bold mb-4">التسعير الافتراضي والحالة</h3>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تكلفة افتراضية (ج.م)</label>
            <input v-model.number="form.default_purchase_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">سعر بيع افتراضي (ج.م)</label>
            <input v-model.number="form.default_selling_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div class="md:col-span-2">
            <div class="flex gap-4 flex-wrap">
              <label v-for="status in bookingStatuses" :key="status.value"
                class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50 transition-all flex-1 min-w-[140px]">
                <input v-model="form.booking_status" type="radio" :value="status.value" class="w-5 h-5" />
                <div>
                  <div class="font-bold">{{ status.label }}</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <p v-if="errorMessage" class="text-error text-sm">{{ errorMessage }}</p>

        <div class="flex gap-3 pt-6 border-t border-white/10">
          <button type="submit" :disabled="isSaving" class="flex-1 py-3 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2">
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ البرنامج' }}
          </button>
          <router-link :to="{ name: 'hajj.programs.list' }" class="flex-1 py-3 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all text-center">
            إلغاء
          </router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, Loader2 } from 'lucide-vue-next';
import axios from 'axios';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';

const router = useRouter();
const store = useHajjUmraStore();
const isSaving = ref(false);
const errorMessage = ref('');

const bookingStatuses = [
  { value: 'open', label: 'مفتوح للحجز' },
  { value: 'closed', label: 'مغلق' },
  { value: 'success', label: 'ناجح' },
  { value: 'cancelled', label: 'ملغي' },
];

const form = ref({
  program_name: '',
  program_type: '',
  season: '',
  total_nights: 0,
  accommodation_type: '',
  mecca_hotel_name: '',
  mecca_nights: 0,
  medina_hotel_name: '',
  medina_nights: 0,
  departure_date: '',
  return_date: '',
  airline: '',
  trip_supervisor: '',
  executing_company: '',
  departure_point: '',
  booking_status: 'open',
  default_purchase_price: 0,
  default_selling_price: 0,
  is_active: true,
});

const saveProgram = async () => {
  isSaving.value = true;
  errorMessage.value = '';
  try {
    await axios.post('/api/v1/hajj-umra/programs', form.value);
    store.addToast('تم إنشاء البرنامج بنجاح');
    await router.push({ name: 'hajj.programs.list' });
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'فشل حفظ البرنامج';
    isSaving.value = false;
  }
};
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
</style>
