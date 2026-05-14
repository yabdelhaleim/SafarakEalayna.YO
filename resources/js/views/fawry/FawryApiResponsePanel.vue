<template>
  <div
    v-if="envelope"
    class="bg-card-bg border border-white/10 rounded-2xl p-4 text-right"
  >
    <div class="flex items-center justify-between gap-2 mb-2">
      <span class="text-sm font-semibold text-text-main">استجابة API الأخيرة</span>
      <button
        type="button"
        class="text-xs text-gold hover:underline"
        @click="$emit('clear')"
      >
        مسح العرض
      </button>
    </div>
    <pre
      class="text-xs font-mono text-text-muted bg-black/30 rounded-lg p-4 overflow-x-auto max-h-96 overflow-y-auto whitespace-pre-wrap break-all dir-ltr text-left"
    >{{ formatted }}</pre>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  envelope: { type: [Object, null], default: null },
});

defineEmits(['clear']);

const formatted = computed(() =>
  props.envelope ? JSON.stringify(props.envelope, null, 2) : ''
);
</script>
