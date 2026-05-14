<template>
  <div
    class="p-5 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group transition-all hover:-translate-y-0.5"
    :class="hoverBorderClass"
  >
    <div class="flex justify-between items-start mb-3">
      <div
        class="p-3 rounded-xl group-hover:scale-110 transition-transform"
        :class="iconWrapperClass"
      >
        <component :is="icon" class="w-5 h-5" />
      </div>
    </div>
    <div>
      <div class="text-xs text-text-muted uppercase tracking-widest mb-1">{{ title }}</div>
      <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">
        {{ value }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  icon: { type: [Function, Object], required: true },
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  accent: { type: String, default: 'gold' },
});

const iconWrapperClass = computed(() => {
  const map = {
    gold: 'bg-gold/10 text-gold',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    info: 'bg-blue-400/10 text-blue-400',
    error: 'bg-error/10 text-error',
  };
  return map[props.accent] ?? map.gold;
});

const hoverBorderClass = computed(() => {
  const map = {
    gold: 'hover:border-gold/30 hover:shadow-lg hover:shadow-gold/10',
    success: 'hover:border-success/30',
    warning: 'hover:border-warning/30',
    info: 'hover:border-blue-400/30',
    error: 'hover:border-error/30',
  };
  return map[props.accent] ?? map.gold;
});
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold\/10 { background-color: color-mix(in srgb, var(--gold) 10%, transparent); }
.text-success { color: var(--success); }
.bg-success\/10 { background-color: color-mix(in srgb, var(--success) 10%, transparent); }
.text-warning { color: var(--warning); }
.bg-warning\/10 { background-color: color-mix(in srgb, var(--warning) 10%, transparent); }
.text-error { color: var(--error); }
.bg-error\/10 { background-color: color-mix(in srgb, var(--error) 10%, transparent); }
.text-blue-400 { color: #60A5FA; }
.bg-blue-400\/10 { background-color: rgba(96, 165, 250, 0.10); }
.font-mono { font-family: 'IBM Plex Sans Arabic', sans-serif; }
</style>
