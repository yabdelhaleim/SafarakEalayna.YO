<template>
  <div :class="[
    'p-6 rounded-3xl border transition-all duration-500 relative overflow-hidden flex flex-col justify-between h-full',
    treasury.is_active 
      ? 'bg-gradient-to-br from-white/10 to-white/5 border-white/20 hover:border-gold/50 shadow-lg' 
      : 'bg-white/5 border-white/5 opacity-60'
  ]">
    <!-- Top Row: Title & Active Badge -->
    <div class="flex items-start justify-between gap-4 relative z-10">
      <div>
        <h4 class="text-lg font-bold tracking-wide text-white line-clamp-1">{{ treasury.name }}</h4>
        <span class="text-xs text-muted font-mono mt-1 block">ID: {{ treasury.id }}</span>
      </div>

      <div :class="[
        'px-3 py-1 rounded-full text-xs font-bold font-mono tracking-wider transition-colors',
        treasury.is_active ? 'bg-success/20 text-success' : 'bg-white/10 text-muted'
      ]">
        {{ treasury.is_active ? 'ACTIVE' : 'DISABLED' }}
      </div>
    </div>

    <!-- Middle: Current Balance Display -->
    <div class="my-6 relative z-10">
      <span class="text-xs text-muted uppercase tracking-widest block mb-1">Available Vault Balance</span>
      <div class="flex items-baseline gap-2">
        <span :class="[
          'text-4xl font-extrabold font-mono tracking-tight transition-colors',
          Number(treasury.current_balance) < 0 ? 'text-error' : 'text-gold'
        ]">
          {{ Number(treasury.current_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </span>
        <span class="text-lg font-bold font-mono text-muted">{{ treasury.currency }}</span>
      </div>
    </div>

    <!-- Bottom: Footer Meta -->
    <div class="pt-4 border-t border-white/5 flex items-center justify-between text-xs text-muted relative z-10 font-mono">
      <span>Updated: {{ formattedDate }}</span>
      <span class="text-gold/80 hover:underline cursor-pointer" @click="$emit('select', treasury)">
        Select Vault →
      </span>
    </div>

    <!-- Premium Background Glow Elements -->
    <div v-if="treasury.is_active" class="absolute -right-12 -bottom-12 w-40 h-40 bg-gold/10 rounded-full blur-2xl pointer-events-none"></div>
    <div v-if="treasury.is_active && isSelected" class="absolute inset-0 border-2 border-gold rounded-3xl pointer-events-none animate-pulse"></div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  treasury: {
    type: Object,
    required: true,
    default: () => ({
      id: 0,
      name: 'Vault',
      currency: 'USD',
      current_balance: 0.00,
      is_active: true,
      updated_at: new Date().toISOString()
    })
  },
  isSelected: {
    type: Boolean,
    default: false
  }
});

defineEmits(['select']);

const formattedDate = computed(() => {
  if (!props.treasury.updated_at) return 'N/A';
  const d = new Date(props.treasury.updated_at);
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
});
</script>

<style scoped>
.text-muted { color: var(--text-muted, #9ca3af); }
.text-gold { color: var(--gold, #d97706); }
.border-gold { border-color: var(--gold, #d97706); }
.bg-gold\/10 { background-color: rgba(217, 119, 6, 0.1); }
.text-success { color: var(--success, #10b981); }
.bg-success\/20 { background-color: rgba(16, 185, 129, 0.2); }
.text-error { color: var(--error, #ef4444); }
</style>
