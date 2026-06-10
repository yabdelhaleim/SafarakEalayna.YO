<template>
  <div :class="wrapperClass">
    <div
      v-if="showHeader"
      class="compact-passenger-list__header grid grid-cols-[1.5rem_1fr_3.5rem_3rem] gap-3 px-1 pb-2 text-[10px] font-black uppercase tracking-wider text-text-muted border-b border-white/10"
      dir="ltr"
    >
      <span>#</span>
      <span>الاسم</span>
      <span class="text-center">النوع</span>
      <span class="text-right">الأمتعة</span>
    </div>
    <ol class="compact-passenger-list space-y-1.5" dir="ltr">
      <li
        v-for="(passenger, index) in passengers"
        :key="passenger.id || passenger.uid || index"
        class="grid grid-cols-[1.5rem_1fr_3.5rem_3rem] gap-3 items-baseline font-mono text-sm leading-snug"
        :class="rowClass"
      >
        <span class="text-text-muted text-right shrink-0">{{ index + 1 }}.</span>
        <span class="font-bold uppercase tracking-wide text-white truncate" :title="fullName(passenger)">
          {{ compactPassengerName(passenger) }}
        </span>
        <span class="text-center shrink-0 text-emerald-300/90 text-xs font-bold">
          {{ compactPassengerTypeLabel(passenger.type) }}
        </span>
        <span class="text-right shrink-0 text-gold text-xs font-bold">
          {{ compactBaggageLabel(passenger) }}
        </span>
      </li>
    </ol>
  </div>
</template>

<script setup>
import {
  compactBaggageLabel,
  compactPassengerName,
  compactPassengerTypeLabel,
  passengerFirstName,
  passengerLastName,
} from '@/utils/flightPassengerDisplay';

defineProps({
  passengers: { type: Array, default: () => [] },
  showHeader: { type: Boolean, default: true },
  wrapperClass: { type: String, default: '' },
  rowClass: { type: String, default: '' },
});

function fullName(passenger) {
  const f = passengerFirstName(passenger);
  const l = passengerLastName(passenger);
  return [f, l].filter(Boolean).join(' ') || '—';
}
</script>
