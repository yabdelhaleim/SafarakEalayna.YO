<template>
  <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="font-display font-extrabold text-lg text-text-main">النشاطات الحديثة</h3>
      <!-- TODO: Create activities page -->
      <!-- <router-link
        to="/activities"
        class="text-sm text-gold hover:underline font-semibold"
      >
        عرض الكل
      </router-link> -->
    </div>

    <div v-if="activities.length === 0" class="text-center py-12">
      <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
        <Activity class="w-8 h-8 text-white/10" />
      </div>
      <p class="text-text-muted text-sm">لا توجد نشاطات حديثة</p>
    </div>

    <div v-else class="space-y-4">
      <div
        v-for="(activity, index) in activities"
        :key="activity.id"
        class="flex items-start gap-4 p-3 rounded-xl hover:bg-white/5 transition-all group"
        :style="{ animationDelay: `${index * 50}ms` }"
      >
        <!-- Icon -->
        <div
          :class="[
            'p-2.5 rounded-lg flex-shrink-0',
            activityTypeClasses[activity.type]?.bgClass || 'bg-white/5'
          ]"
        >
          <component
            :is="activityTypeClasses[activity.type]?.icon || Activity"
            :class="[
              'w-4 h-4',
              activityTypeClasses[activity.type]?.iconClass || 'text-text-muted'
            ]"
          />
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-text-main truncate">
            {{ activity.description }}
          </p>
          <p class="text-xs text-text-muted mt-0.5">
            {{ activity.customer }}
          </p>
        </div>

        <!-- Time -->
        <div class="flex-shrink-0 text-xs text-text-muted font-mono">
          {{ formatTime(activity.created_at) }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import {
  Activity,
  Plane,
  Bus,
  ConciergeBell,
  Globe,
} from 'lucide-vue-next';

const props = defineProps({
  activities: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

const activityTypeClasses = {
  flight: {
    icon: Plane,
    iconClass: 'text-gold',
    bgClass: 'bg-gold/10',
  },
  bus: {
    icon: Bus,
    iconClass: 'text-teal-400',
    bgClass: 'bg-teal-400/10',
  },
  service: {
    icon: ConciergeBell,
    iconClass: 'text-blue-400',
    bgClass: 'bg-blue-400/10',
  },
  online: {
    icon: Globe,
    iconClass: 'text-success',
    bgClass: 'bg-success/10',
  },
};

const formatTime = (dateString) => {
  if (!dateString) return '';

  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'الآن';
  if (diffMins < 60) return `منذ ${diffMins} دقيقة`;
  if (diffHours < 24) return `منذ ${diffHours} ساعة`;
  if (diffDays < 7) return `منذ ${diffDays} يوم`;

  return date.toLocaleDateString('ar-EG', {
    month: 'short',
    day: 'numeric',
  });
};
</script>

<style scoped>
@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(-10px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.group {
  animation: slideIn 0.3s ease-out forwards;
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .group {
    animation: none;
    opacity: 1;
  }
}
</style>
