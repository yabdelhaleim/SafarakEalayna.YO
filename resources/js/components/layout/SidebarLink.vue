<template>
  <router-link
    :to="to"
    :class="[
      'flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group',
      isActive
        ? 'bg-gold text-black shadow-lg shadow-gold/20'
        : 'text-text-muted hover:bg-white/5 hover:text-text-main'
    ]"
  >
    <component
      :is="icon"
      :class="[
        'w-5 h-5 transition-transform duration-200',
        isActive ? 'scale-110' : 'group-hover:scale-105'
      ]"
    />
    <span class="font-semibold">{{ label }}</span>
    <span
      v-if="badge"
      :class="[
        'ml-auto text-xs font-bold px-2 py-0.5 rounded-full',
        isActive ? 'bg-black/20' : 'bg-white/10'
      ]"
    >
      {{ badge }}
    </span>
  </router-link>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const props = defineProps({
  to: {
    type: [String, Object],
    required: true
  },
  label: {
    type: String,
    required: true
  },
  icon: {
    type: [Object, Function],
    required: true
  },
  badge: {
    type: [String, Number],
    default: null
  }
});

const route = useRoute();

const isActive = computed(() => {
  if (typeof props.to === 'string') {
    return route.path === props.to || route.path.startsWith(props.to + '/');
  }
  return route.path === props.to.path || route.path.startsWith(props.to.path + '/');
});
</script>
