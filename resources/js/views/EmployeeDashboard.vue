<template>
  <div class="space-y-8 pb-12">
    <!-- Header -->
    <header class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 p-8 shadow-2xl border border-white/10">
      <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-500/10 via-transparent to-transparent"></div>
      <div class="relative z-10 flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-xl font-black tracking-tight text-white sm:text-3xl">
            أهلاً بك، {{ data.user?.name }}
          </h1>
          <p class="mt-2 text-sm text-gray-400">
            ملخص نشاطك وحضورك لهذا الشهر
          </p>
        </div>
        
        <!-- Attendance Quick Info -->
        <div v-if="data.attendance" class="flex items-center gap-4 bg-white/5 border border-white/10 p-4 rounded-2xl backdrop-blur-md">
          <div :class="['h-3 w-3 rounded-full animate-pulse', data.attendance.check_out ? 'bg-gray-400' : 'bg-emerald-400']"></div>
          <div>
            <div class="text-[10px] text-gray-500 uppercase font-bold">حالة الحضور اليوم</div>
            <div class="text-sm font-bold text-white">
              {{ data.attendance.check_out ? 'انصرفت' : 'حاضر الآن' }}
              <span class="text-xs font-normal text-gray-400 ml-2">({{ data.attendance.check_in }})</span>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- My Sales Stats (Counts Only) -->
    <div v-if="isLoading()" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <KPICardSkeleton v-for="i in 6" :key="`sales-${i}`" />
    </div>
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      
      <!-- Flights -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-sky-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-sky-500/10 rounded-xl text-sky-400 group-hover:scale-110 transition-transform">
            <Plane class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.flights || 0 }}</div>
        <div class="text-sm font-bold text-sky-400 mt-1">تذكرة طيران</div>
      </div>

      <!-- Bus -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-amber-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-amber-500/10 rounded-xl text-amber-400 group-hover:scale-110 transition-transform">
            <Bus class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.bus || 0 }}</div>
        <div class="text-sm font-bold text-amber-400 mt-1">حجز باص</div>
      </div>

      <!-- Visas -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-purple-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-purple-500/10 rounded-xl text-purple-400 group-hover:scale-110 transition-transform">
            <Globe class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.visas || 0 }}</div>
        <div class="text-sm font-bold text-purple-400 mt-1">طلب تأشيرة</div>
      </div>

      <!-- Fawry -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-yellow-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-yellow-500/10 rounded-xl text-yellow-400 group-hover:scale-110 transition-transform">
            <CreditCard class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.fawry || 0 }}</div>
        <div class="text-sm font-bold text-yellow-500 mt-1">معاملة فوري</div>
      </div>

      <!-- Online -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-emerald-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-emerald-500/10 rounded-xl text-emerald-400 group-hover:scale-110 transition-transform">
            <Zap class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.online || 0 }}</div>
        <div class="text-sm font-bold text-emerald-400 mt-1">خدمة إلكترونية</div>
      </div>

      <!-- Hajj & Umra -->
      <div class="bg-card-bg border border-white/10 rounded-2xl p-6 hover:border-indigo-500/30 transition-all group">
        <div class="flex items-center justify-between mb-4">
          <div class="p-3 bg-indigo-500/10 rounded-xl text-indigo-400 group-hover:scale-110 transition-transform">
            <span class="text-xl">🕋</span>
          </div>
          <span class="text-xs font-bold text-gray-500">مبيعات الشهر</span>
        </div>
        <div class="text-3xl font-black text-white font-mono">{{ data.sales_summary?.hajj_umra || 0 }}</div>
        <div class="text-sm font-bold text-indigo-400 mt-1">برنامج حج/عمرة</div>
      </div>

    </div>

    <!-- Recent Activity Section -->
    <div class="bg-card-bg border border-white/10 rounded-3xl p-6 shadow-xl">
      <h3 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
        <Clock class="w-5 h-5 text-indigo-400" />
        أحدث العمليات التي قمت بها
      </h3>
      
      <div v-if="isLoading()" class="space-y-4">
        <TextLineSkeleton :lines="3" heightClass="h-16" gapClass="gap-4" />
      </div>
      <template v-else>
        <div v-if="!data.recent_activity?.length" class="text-center py-12">
          <div class="text-gray-500 italic">لا توجد عمليات مسجلة لك مؤخراً</div>
        </div>
      
      <div v-else class="space-y-4">
        <div 
          v-for="(act, i) in data.recent_activity" 
          :key="i" 
          class="flex items-center justify-between p-4 bg-white/5 border border-white/5 rounded-2xl hover:bg-white/10 transition-all"
        >
          <div class="flex items-center gap-4">
            <div class="p-2 bg-indigo-500/10 rounded-lg text-indigo-400">
              <Activity class="w-4 h-4" />
            </div>
            <div>
              <div class="text-sm font-bold text-white">{{ act.description }}</div>
              <div class="text-xs text-gray-500">{{ act.time }}</div>
            </div>
          </div>
          <button class="text-xs font-bold text-indigo-400 hover:underline">عرض التفاصيل</button>
        </div>
      </div>
      </template>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { 
  Plane, 
  Bus, 
  Globe, 
  CreditCard, 
  Zap, 
  Clock, 
  Activity 
} from 'lucide-vue-next';
import { useAsyncState } from '@/composables/useAsyncState';
import KPICardSkeleton from '@/components/skeletons/KPICardSkeleton.vue';
import TextLineSkeleton from '@/components/skeletons/TextLineSkeleton.vue';

const data = ref({
  user: null,
  sales_summary: {},
  attendance: null,
  recent_activity: []
});

const { state, setLoading, setSuccess, setEmpty, setError, isLoading, isSuccess, isEmpty } = useAsyncState('loading');

const fetchDashboard = async () => {
  setLoading();
  try {
    const res = await axios.get('/api/v1/employee/dashboard');
    data.value = res.data.data;
    setSuccess();
  } catch (err) {
    console.error('Failed to fetch employee dashboard:', err);
    setError(err);
  }
};

let pollingInterval = null;

onMounted(() => {
  fetchDashboard();
  
  // Auto-refresh every 15 seconds to fetch new sales/attendance/activity data without manual reload
  pollingInterval = setInterval(async () => {
    if (!isLoading()) {
      await fetchDashboard();
    }
  }, 15000);
});

onUnmounted(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval);
  }
});
</script>
