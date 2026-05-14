<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-white tracking-tight">برامج الحج والعمرة</h1>
        <p class="text-muted mt-1">إشاء وإدارة برامج الحج والعمرة</p>
      </div>
      <router-link :to="{ name: 'hajj.programs.create' }"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20">
        <Plus class="w-5 h-5" /> برنامج جديد
      </router-link>
    </div>

    <!-- Programs List -->
    <div class="bg-card border border-white/10 rounded-2xl overflow-hidden shadow-2xl">
      <div v-if="programs.length === 0 && !loading" class="p-20 text-center">
        <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
          <Calendar class="w-10 h-10 text-white/10" />
        </div>
        <h3 class="text-xl font-bold mb-2">لا توجد برامج حالياً</h3>
        <p class="text-muted text-sm mb-4">ابدأ بإنشاء برنامج حج أو عمرة جديد</p>
        <router-link :to="{ name: 'hajj.programs.create' }" class="text-gold font-bold hover:underline">
          إنشاء برنامج جديد
        </router-link>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">اسم البرنامج</th>
              <th class="px-6 py-4 font-semibold">النوع</th>
              <th class="px-6 py-4 font-semibold">المدة</th>
              <th class="px-6 py-4 font-semibold">فندق مكة</th>
              <th class="px-6 py-4 font-semibold">فندق المدينة</th>
              <th class="px-6 py-4 font-semibold">الطيران</th>
              <th class="px-6 py-4 font-semibold">الحالة</th>
              <th class="px-6 py-4 font-semibold text-right">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading" v-for="i in 5" :key="i" class="border-b border-white/5">
              <td v-for="j in 8" :key="j" class="px-6 py-4">
                <div class="h-4 animate-shimmer rounded w-full"></div>
              </td>
            </tr>
            <tr v-else v-for="program in programs" :key="program.id" class="border-b border-white/5 hover:bg-white/5 transition-colors group">
              <td class="px-6 py-4">
                <div class="font-bold">{{ program.program_name }}</div>
              </td>
              <td class="px-6 py-4">
                <div :class="['inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                  program.program_type === 'hajj' ? 'bg-gold/10 text-gold' : 'bg-success/10 text-success']">
                  {{ program.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة' }}
                </div>
              </td>
              <td class="px-6 py-4">
                <div class="font-bold">{{ program.total_nights }} ليلة</div>
              </td>
              <td class="px-6 py-4">
                <div class="text-sm">{{ program.mecca_hotel_name }}</div>
                <div class="text-xs text-muted">{{ program.mecca_nights }} ليلة</div>
              </td>
              <td class="px-6 py-4">
                <div class="text-sm">{{ program.medina_hotel_name || '-' }}</div>
                <div class="text-xs text-muted">{{ program.medina_nights || 0 }} ليلة</div>
              </td>
              <td class="px-6 py-4">
                <div class="text-sm">{{ program.airline }}</div>
              </td>
              <td class="px-6 py-4">
                <div :class="['inline-flex items-center gap-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                  program.booking_status === 'active' ? 'bg-success/10 text-success' : 'bg-error/10 text-error']">
                  {{ program.booking_status === 'active' ? 'نشط' : 'مغلق' }}
                </div>
              </td>
              <td class="px-6 py-4 text-right">
                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                  <router-link :to="{ name: 'hajj.programs.edit', params: { id: program.id } }" class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-gold transition-all">
                    <Edit2 class="w-4 h-4" />
                  </router-link>
                  <button @click="confirmDelete(program)" class="p-2 hover:bg-error/10 rounded-lg text-muted hover:text-error transition-all">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useRouter } from 'vue-router';
import { Plus, Calendar, Edit2, Trash2 } from 'lucide-vue-next';

const store = useHajjUmraStore();
const router = useRouter();

const programs = ref([]);
const loading = ref(false);

const loadPrograms = async () => {
  loading.value = true;
  try {
    await store.fetchPrograms();
    programs.value = store.programs;
  } catch (error) {
    console.error('Failed to load programs', error);
  } finally {
    loading.value = false;
  }
};

const confirmDelete = async (program) => {
  if (confirm(`هل أنت متأكد من حذف برنامج "${program.program_name}"؟`)) {
    try {
      // Note: You'll need to add deleteProgram to the store
      programs.value = programs.value.filter(p => p.id !== program.id);
      store.addToast('تم حذف البرنامج بنجاح');
    } catch (error) {
      store.addToast('فشل حذف البرنامج', 'error');
    }
  }
};

onMounted(async () => {
  await loadPrograms();
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
