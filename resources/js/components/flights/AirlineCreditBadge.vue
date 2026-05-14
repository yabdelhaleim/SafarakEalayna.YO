<template>
  <div :class="[
    'px-4 py-3 rounded-2xl border flex items-center justify-between gap-4 transition-all duration-300 relative overflow-hidden',
    statusClass.bg,
    statusClass.border,
    compact ? 'text-xs' : 'text-sm'
  ]">
    <!-- Left Icon & Main Info -->
    <div class="flex items-center gap-3 relative z-10">
      <div :class="['p-2 rounded-xl flex items-center justify-center', statusClass.iconBg]">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" :class="statusClass.iconColor" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
        </svg>
      </div>

      <div>
        <div class="flex items-center gap-2">
          <span class="font-bold text-white tracking-wide">{{ credit.carrier?.name || credit.carrier_name || 'Airline Credit' }}</span>
          <span :class="['px-2 py-0.5 rounded text-[10px] font-extrabold uppercase font-mono tracking-wider', statusClass.badgeBg, statusClass.badgeText]">
            {{ credit.status }}
          </span>
        </div>
        <div class="text-muted font-mono mt-0.5 text-xs flex items-center gap-2">
          <span v-if="credit.booking?.booking_number">Ref: {{ credit.booking.booking_number }}</span>
          <span v-if="credit.expiry_date"> • Exp: {{ formattedExpiry }}</span>
        </div>
      </div>
    </div>

    <!-- Right Amount Display -->
    <div class="text-right font-mono relative z-10">
      <div class="flex items-baseline justify-end gap-1">
        <span class="font-extrabold text-white text-base">{{ Number(credit.amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
        <span class="text-xs font-bold text-gold">{{ credit.currency }}</span>
      </div>
      <span class="text-[10px] text-muted block uppercase tracking-widest mt-0.5">Non-Cash Asset</span>
    </div>

    <!-- Background Accent Bar -->
    <div class="absolute left-0 top-0 bottom-0 w-1 opacity-80" :class="statusClass.barColor"></div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  credit: {
    type: Object,
    required: true,
    default: () => ({
      amount: 0.00,
      currency: 'USD',
      status: 'active',
      expiry_date: null,
      carrier: { name: 'Airline' },
      booking: { booking_number: '' }
    })
  },
  compact: {
    type: Boolean,
    default: false
  }
});

const formattedExpiry = computed(() => {
  if (!props.credit.expiry_date) return 'None';
  const d = new Date(props.credit.expiry_date);
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: '2-digit' });
});

const statusClass = computed(() => {
  const s = props.credit.status || 'active';
  switch (s) {
    case 'used':
      return {
        bg: 'bg-white/5',
        border: 'border-white/10 opacity-70',
        iconBg: 'bg-white/5',
        iconColor: 'text-muted',
        badgeBg: 'bg-white/10',
        badgeText: 'text-muted',
        barColor: 'bg-gray-500'
      };
    case 'expired':
      return {
        bg: 'bg-error/5',
        border: 'border-error/20',
        iconBg: 'bg-error/10',
        iconColor: 'text-error',
        badgeBg: 'bg-error/20',
        badgeText: 'text-error',
        barColor: 'bg-error'
      };
    case 'active':
    default:
      return {
        bg: 'bg-gold/5',
        border: 'border-gold/20 hover:border-gold/40',
        iconBg: 'bg-gold/10',
        iconColor: 'text-gold',
        badgeBg: 'bg-gold/20',
        badgeText: 'text-gold',
        barColor: 'bg-gold'
      };
  }
});
</script>

<style scoped>
.text-muted { color: var(--text-muted, #9ca3af); }
.text-gold { color: var(--gold, #d97706); }
.border-gold\/20 { border-color: rgba(217, 119, 6, 0.2); }
.bg-gold\/5 { background-color: rgba(217, 119, 6, 0.05); }
.bg-gold\/10 { background-color: rgba(217, 119, 6, 0.1); }
.bg-gold\/20 { background-color: rgba(217, 119, 6, 0.2); }
.bg-gold { background-color: var(--gold, #d97706); }

.text-error { color: var(--error, #ef4444); }
.bg-error\/5 { background-color: rgba(239, 68, 68, 0.05); }
.bg-error\/10 { background-color: rgba(239, 68, 68, 0.1); }
.bg-error\/20 { background-color: rgba(239, 68, 68, 0.2); }
.border-error\/20 { border-color: rgba(239, 68, 68, 0.2); }
.bg-error { background-color: var(--error, #ef4444); }
</style>
