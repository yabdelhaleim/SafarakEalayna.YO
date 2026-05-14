<template>
  <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="font-display font-extrabold text-lg text-text-main">تنبيهات هامة</h3>
      <span
        v-if="alerts.length > 0"
        class="text-xs font-bold px-2 py-1 bg-warning/10 text-warning rounded-full"
      >
        {{ alerts.length }} تنبيه
      </span>
    </div>

    <div v-if="alerts.length === 0" class="text-center py-12">
      <div class="w-16 h-16 bg-success/10 rounded-full flex items-center justify-center mx-auto mb-4">
        <CheckCircle class="w-8 h-8 text-success" />
      </div>
      <p class="text-text-muted text-sm">لا توجد تنبيهات</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="(alert, index) in alerts"
        :key="index"
        :class="[
          'flex items-center gap-3 p-4 rounded-xl border transition-all hover:shadow-lg',
          alertTypeClasses[alert.type]?.containerClass || 'bg-white/5 border-white/10'
        ]"
      >
        <div
          :class="[
            'p-2 rounded-lg flex-shrink-0',
            alertTypeClasses[alert.type]?.iconBgClass || 'bg-white/5'
          ]"
        >
          <component
            :is="alertTypeClasses[alert.type]?.icon || AlertTriangle"
            :class="[
              'w-4 h-4',
              alertTypeClasses[alert.type]?.iconClass || 'text-text-muted'
            ]"
          />
        </div>

        <div class="flex-1">
          <p
            :class="[
              'text-sm font-semibold',
              alertTypeClasses[alert.type]?.textClass || 'text-text-main'
            ]"
          >
            {{ alert.message }}
          </p>
          <p
            v-if="alert.priority"
            class="text-xs mt-0.5 opacity-70"
          >
            الأولوية: {{ priorityLabels[alert.priority] || alert.priority }}
          </p>
        </div>

        <div
          v-if="alert.priority"
          :class="[
            'w-2 h-2 rounded-full flex-shrink-0',
            priorityClass[alert.priority] || 'bg-text-muted'
          ]"
        ></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import {
  AlertTriangle,
  AlertCircle,
  Info,
  CheckCircle,
} from 'lucide-vue-next';

const props = defineProps({
  alerts: {
    type: Array,
    default: () => [],
  },
});

const alertTypeClasses = {
  warning: {
    icon: AlertTriangle,
    iconClass: 'text-warning',
    iconBgClass: 'bg-warning/10',
    containerClass: 'bg-warning/5 border-warning/20',
    textClass: 'text-warning',
  },
  error: {
    icon: AlertCircle,
    iconClass: 'text-error',
    iconBgClass: 'bg-error/10',
    containerClass: 'bg-error/5 border-error/20',
    textClass: 'text-error',
  },
  info: {
    icon: Info,
    iconClass: 'text-blue-400',
    iconBgClass: 'bg-blue-400/10',
    containerClass: 'bg-blue-400/5 border-blue-400/20',
    textClass: 'text-blue-400',
  },
  success: {
    icon: CheckCircle,
    iconClass: 'text-success',
    iconBgClass: 'bg-success/10',
    containerClass: 'bg-success/5 border-success/20',
    textClass: 'text-success',
  },
};

const priorityClass = {
  high: 'bg-error animate-pulse',
  medium: 'bg-warning',
  low: 'bg-text-muted',
};

const priorityLabels = {
  high: 'عالية',
  medium: 'متوسطة',
  low: 'منخفضة',
};
</script>
