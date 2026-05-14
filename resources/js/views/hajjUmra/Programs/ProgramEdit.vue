<template>
  <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in duration-700">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">تعديل البرنامج</h1>
        <p class="text-muted mt-1">برنامج #{{ program?.id }}</p>
      </div>
      <router-link :to="{ name: 'hajj.programs' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
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
              <option value="umrah">🕋 عمرة</option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">عدد الليالي</label>
            <input v-model.number="form.total_nights" type="number" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2">الحالة</label>
            <select v-model="form.booking_status" required class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none appearance-none">
              <option value="active">نشط</option>
              <option value="closed">مغلق</option>
            </select>
          </div>
        </div>

        <div class="flex gap-3 pt-6 border-t border-white/10">
          <button type="submit" :disabled="isSaving" class="flex-1 py-3 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center justify-center gap-2">
            <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
            {{ isSaving ? 'جاري الحفظ...' : 'حفظ التغييرات' }}
          </button>
          <router-link :to="{ name: 'hajj.programs' }" class="flex-1 py-3 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition-all text-center">
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

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const isSaving = ref(false);
const program = ref(null);

const form = ref({
  program_name: '',
  program_type: '',
  total_nights: 0,
  booking_status: 'active'
});

const loadProgram = async () => {
  loading.value = true;
  try {
    const response = await axios.get(`/api/v1/programs/${route.params.id}`);
    program.value = response.data.data;
    form.value = {
      program_name: program.value.program_name,
      program_type: program.value.program_type,
      total_nights: program.value.total_nights,
      booking_status: program.value.booking_status
    };
  } catch (error) {
    console.error('Failed to load program', error);
  } finally {
    loading.value = false;
  }
};

const saveProgram = async () => {
  isSaving.value = true;
  try {
    await axios.put(`/api/v1/programs/${route.params.id}`, form.value);
    await router.push({ name: 'hajj.programs' });
  } catch (error) {
    console.error('Failed to save program', error);
    isSaving.value = false;
  }
};

onMounted(async () => {
  await loadProgram();
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
</style>
