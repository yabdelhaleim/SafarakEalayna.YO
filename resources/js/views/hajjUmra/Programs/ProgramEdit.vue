<template>
  <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in duration-700">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">تعديل البرنامج</h1>
        <p class="text-muted mt-1">برنامج #{{ program?.id }}</p>
      </div>
      <router-link :to="{ name: 'hajj.programs.list' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> رجوع
      </router-link>
    </div>

    <div v-if="loading" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="program" class="p-8 bg-card border border-white/10 rounded-2xl space-y-6">
      <form @submit.prevent="saveProgram" class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs text-muted mb-2">اسم البرنامج</label>
            <input v-model="form.program_name" type="text" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">نوع البرنامج</label>
            <select v-model="form.program_type" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
              <option value="hajj">🕋 حج</option>
              <option value="umra">🕋 عمرة</option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">الموسم</label>
            <input v-model="form.season" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">عدد الليالي</label>
            <input v-model.number="form.total_nights" type="number" min="1" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">فندق مكة</label>
            <input v-model="form.mecca_hotel_name" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">ليالي مكة</label>
            <input v-model.number="form.mecca_nights" type="number" min="0" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">فندق المدينة</label>
            <input v-model="form.medina_hotel_name" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">ليالي المدينة</label>
            <input v-model.number="form.medina_nights" type="number" min="0" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">شركة الطيران</label>
            <input v-model="form.airline" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">الشركة المنفذة</label>
            <input v-model="form.executing_company" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">مشرف الرحلة</label>
            <input v-model="form.trip_supervisor" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">منفذ السفر</label>
            <input v-model="form.departure_point" type="text" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تاريخ السفر</label>
            <input v-model="form.departure_date" type="date" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تاريخ العودة</label>
            <input v-model="form.return_date" type="date" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">تكلفة افتراضية (ج.م)</label>
            <input v-model.number="form.default_purchase_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">سعر بيع افتراضي (ج.م)</label>
            <input v-model.number="form.default_selling_price" type="number" min="0" step="0.01" class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">حالة الحجز</label>
            <select v-model="form.booking_status" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
              <option value="open">مفتوح</option>
              <option value="closed">مغلق</option>
              <option value="success">ناجح</option>
              <option value="cancelled">ملغي</option>
            </select>
          </div>

          <div class="flex items-center">
            <label class="flex items-center gap-3 cursor-pointer">
              <input v-model="form.is_active" type="checkbox" class="w-5 h-5" />
              <span class="font-bold">البرنامج مفعّل ويظهر في الحجوزات</span>
            </label>
          </div>
        </div>

        <p v-if="errorMessage" class="text-error text-sm">{{ errorMessage }}</p>

        <div class="flex gap-3 pt-6 border-t border-white/10">
          <button type="submit" :disabled="isSaving" class="flex-1 py-3 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2">
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ التغييرات' }}
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
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Loader2 } from 'lucide-vue-next';
import axios from 'axios';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';

const route = useRoute();
const router = useRouter();
const store = useHajjUmraStore();

const loading = ref(false);
const isSaving = ref(false);
const errorMessage = ref('');
const program = ref(null);

const form = ref({
  program_name: '',
  program_type: '',
  season: '',
  total_nights: 0,
  mecca_hotel_name: '',
  mecca_nights: 0,
  medina_hotel_name: '',
  medina_nights: 0,
  airline: '',
  executing_company: '',
  trip_supervisor: '',
  departure_point: '',
  departure_date: '',
  return_date: '',
  default_purchase_price: 0,
  default_selling_price: 0,
  booking_status: 'open',
  is_active: true,
});

const normalizeStatus = (status) => (status === 'active' ? 'open' : (status || 'open'));

const loadProgram = async () => {
  loading.value = true;
  try {
    const response = await axios.get(`/api/v1/hajj-umra/programs/${route.params.id}`);
    program.value = response.data.data;
    form.value = {
      program_name: program.value.program_name,
      program_type: program.value.program_type,
      season: program.value.season || '',
      total_nights: program.value.total_nights,
      mecca_hotel_name: program.value.mecca_hotel_name || '',
      mecca_nights: program.value.mecca_nights || 0,
      medina_hotel_name: program.value.medina_hotel_name || '',
      medina_nights: program.value.medina_nights || 0,
      airline: program.value.airline || '',
      executing_company: program.value.executing_company || '',
      trip_supervisor: program.value.trip_supervisor || '',
      departure_point: program.value.departure_point || '',
      departure_date: program.value.departure_date || '',
      return_date: program.value.return_date || '',
      default_purchase_price: program.value.default_purchase_price || 0,
      default_selling_price: program.value.default_selling_price || 0,
      booking_status: normalizeStatus(program.value.booking_status),
      is_active: program.value.is_active !== false,
    };
  } catch (error) {
    console.error('Failed to load program', error);
    errorMessage.value = 'فشل تحميل البرنامج';
  } finally {
    loading.value = false;
  }
};

const saveProgram = async () => {
  isSaving.value = true;
  errorMessage.value = '';
  try {
    await axios.put(`/api/v1/hajj-umra/programs/${route.params.id}`, form.value);
    store.addToast('تم تحديث البرنامج بنجاح');
    await router.push({ name: 'hajj.programs.list' });
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'فشل حفظ التغييرات';
    isSaving.value = false;
  }
};

onMounted(loadProgram);
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
</style>
