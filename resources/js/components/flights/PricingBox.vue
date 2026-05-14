<template>
  <div class="space-y-6 max-w-lg mx-auto">
    <div class="grid grid-cols-1 gap-6">
      <div>
        <label class="block text-xs text-muted mb-2 uppercase tracking-widest">Currency</label>
        <select v-model="pricing.currency" 
          class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none appearance-none cursor-pointer">
          <option value="EGP">EGP - Egyptian Pound</option>
          <option value="USD">USD - US Dollar</option>
          <option value="EUR">EUR - Euro</option>
          <option value="SAR">SAR - Saudi Riyal</option>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-muted mb-2 uppercase tracking-widest">Purchase Price*</label>
          <input v-model.number="pricing.purchasePrice" type="number" step="0.01"
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl" />
        </div>
        <div>
          <label class="block text-xs text-muted mb-2 uppercase tracking-widest">Selling Price*</label>
          <input v-model.number="pricing.sellingPrice" type="number" step="0.01"
            class="w-full p-4 bg-input border border-white/10 rounded-2xl focus:border-gold outline-none font-mono text-xl" />
        </div>
      </div>
    </div>

    <!-- Live Profit Display -->
    <div :class="[
      'p-8 rounded-3xl border-2 transition-all duration-500 overflow-hidden relative',
      profit > 0 ? 'bg-success/5 border-success/20' : profit < 0 ? 'bg-error/5 border-error/20' : 'bg-white/5 border-white/10'
    ]">
      <div class="relative z-10 flex flex-col items-center text-center">
        <span class="text-xs text-muted uppercase tracking-[0.2em] mb-4">Net Profit Analysis</span>

        <div class="flex items-baseline gap-2 mb-2">
          <span v-if="profit > 0" class="text-2xl text-success">+</span>
          <span v-else-if="profit < 0" class="text-2xl text-error">−</span>
          <span :class="['text-5xl font-bold font-mono transition-colors duration-500', profit > 0 ? 'text-success' : profit < 0 ? 'text-error' : 'text-muted']">
            {{ currencySymbol }}{{ Math.abs(profit).toLocaleString() }}
          </span>
          <span class="text-xl text-muted font-mono">{{ pricing.currency }}</span>
        </div>

        <div :class="[
          'px-4 py-1.5 rounded-full text-xs font-bold transition-all duration-500',
          profit > 0 ? 'bg-success/20 text-success' : profit < 0 ? 'bg-error/20 text-error' : 'bg-white/10 text-muted'
        ]">
          <span v-if="profit > 0">✅ {{ profitPercentage }}% Margin</span>
          <span v-else-if="profit < 0">❌ {{ Math.abs(profitPercentage) }}% Loss</span>
          <span v-else>➖ Break-even</span>
        </div>
      </div>

      <!-- Animated background pulse -->
      <div v-if="profit !== 0" :class="[
        'absolute inset-0 opacity-10 animate-pulse',
        profit > 0 ? 'bg-success' : 'bg-error'
      ]"></div>
    </div>

    <div class="flex justify-between text-sm px-4">
      <div class="flex flex-col">
        <span class="text-muted">Purchase</span>
        <span class="font-mono">{{ pricing.purchasePrice.toLocaleString() }} {{ pricing.currency }}</span>
      </div>
      <div class="flex flex-col text-right">
        <span class="text-muted">Selling</span>
        <span class="font-mono">{{ pricing.sellingPrice.toLocaleString() }} {{ pricing.currency }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
  modelValue: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['update:modelValue']);

const pricing = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
});

const profit = computed(() => {
  return (pricing.value.sellingPrice || 0) - (pricing.value.purchasePrice || 0);
});

const profitPercentage = computed(() => {
  if (!pricing.value.purchasePrice || pricing.value.purchasePrice === 0) return '-';
  return ((profit.value / pricing.value.purchasePrice) * 100).toFixed(1);
});

const currencySymbol = computed(() => {
  const symbols = {
    EGP: 'EGP ',
    USD: '$',
    EUR: '€',
    SAR: 'SAR '
  };
  return symbols[pricing.value.currency] || pricing.value.currency + ' ';
});

// Sync profit to model
watch(profit, (newVal) => {
  pricing.value.profit = newVal;
}, { immediate: true });
</script>

<style scoped>
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.border-gold { border-color: var(--gold); }
.text-success { color: var(--success); }
.bg-success { background-color: var(--success); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
</style>
