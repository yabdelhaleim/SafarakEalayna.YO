<template>
  <!-- Debug panel disabled -->
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
