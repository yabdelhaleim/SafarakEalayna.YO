<template>
  <div
    class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10"
  >
    <div class="flex justify-between items-start mb-4">
      <div
        :class="[
          'p-3 rounded-xl transition-transform',
          iconBgClass,
          'group-hover:scale-110'
        ]"
      >
        <component :is="iconComponent" class="w-6 h-6" :class="iconColorClass" />
      </div>
      <span
        v-if="trend"
        :class="[
          'text-xs font-bold px-2 py-1 rounded-full',
          trendColorClass
        ]"
      >
        {{ trend }}
      </span>
    </div>
    <div>
      <div class="text-sm text-text-muted uppercase tracking-widest mb-1">
        {{ label }}
      </div>
      <div
        class="text-2xl font-bold font-mono group-hover:text-gold transition-colors"
      >
        <span v-if="animatedValue !== null">{{ animatedValue }}</span>
        <span v-else class="animate-pulse">...</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useTransition } from '@vueuse/core';
import {
  DollarSign,
  TrendingUp,
  Plane,
  Bus,
  ConciergeBell,
  Globe,
  CreditCard,
  Users,
  Activity,
} from 'lucide-vue-next';

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: Number,
    required: true,
  },
  format: {
    type: String,
    default: 'number', // 'number' or 'currency'
  },
  icon: {
    type: String,
    required: true,
  },
  trend: {
    type: String,
    default: null,
  },
  trendColor: {
    type: String,
    default: 'gold', // 'success', 'error', 'warning', 'gold'
  },
});

const iconMap = {
  DollarSign,
  TrendingUp,
  Plane,
  Bus,
  ConciergeBell,
  Globe,
  CreditCard,
  Users,
  Activity,
};

const iconComponent = computed(() => iconMap[props.icon] || Activity);

const iconColorClass = computed(() => {
  const colors = {
    gold: 'text-gold',
    success: 'text-success',
    error: 'text-error',
    warning: 'text-warning',
    blue: 'text-blue-500',
  };
  return colors[props.trendColor] || colors.gold;
});

const iconBgClass = computed(() => {
  const bgs = {
    gold: 'bg-gold/10',
    success: 'bg-success/10',
    error: 'bg-error/10',
    warning: 'bg-warning/10',
    blue: 'bg-blue-500/10',
  };
  return bgs[props.trendColor] || bgs.gold;
});

const trendColorClass = computed(() => {
  const colors = {
    gold: 'bg-gold/10 text-gold',
    success: 'bg-success/10 text-success',
    error: 'bg-error/10 text-error',
    warning: 'bg-warning/10 text-warning',
  };
  return colors[props.trendColor] || colors.gold;
});

// Format value for display
const formattedValue = computed(() => {
  if (props.format === 'currency') {
    return props.value.toLocaleString('ar-EG');
  }
  return props.value.toLocaleString('ar-EG');
});

// Animated value
const source = ref(0);
const animatedValue = ref(null);

const animateValue = () => {
  source.value = props.value;
};

onMounted(animateValue);

watch(() => props.value, animateValue);

const output = useTransition(source, {
  duration: 1500,
});

// Format the animated output
watch(output, (newVal) => {
  if (props.format === 'currency') {
    animatedValue.value = Math.floor(newVal).toLocaleString('ar-EG') + ' جنيه';
  } else {
    animatedValue.value = Math.floor(newVal).toLocaleString('ar-EG');
  }
}, { immediate: true });
</script>
