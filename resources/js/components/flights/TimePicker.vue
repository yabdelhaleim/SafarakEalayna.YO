<template>
  <div class="flex items-center gap-2" dir="ltr">
    <!-- Hour Select -->
    <div class="flex-1 min-w-[70px]">
      <select
        v-model="selectedHour"
        @change="updateTime"
        class="flight-select text-center font-mono font-bold"
      >
        <option value="" disabled>الساعة</option>
        <option v-for="hour in hours" :key="hour" :value="hour">{{ hour }}</option>
      </select>
    </div>
    
    <span class="text-white/60 font-black text-lg select-none">:</span>
    
    <!-- Minute Select -->
    <div class="flex-1 min-w-[70px]">
      <select
        v-model="selectedMinute"
        @change="updateTime"
        class="flight-select text-center font-mono font-bold"
      >
        <option value="" disabled>الدقيقة</option>
        <option v-for="minute in minutes" :key="minute" :value="minute">{{ minute }}</option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  required: { type: Boolean, default: false }
});

const emit = defineEmits(['update:modelValue']);

const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
const minutes = Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0'));

const selectedHour = ref('');
const selectedMinute = ref('');

// Sync selection with v-model
watch(() => props.modelValue, (newVal) => {
  if (newVal && newVal.includes(':')) {
    const parts = newVal.split(':');
    selectedHour.value = parts[0];
    selectedMinute.value = parts[1];
  } else {
    selectedHour.value = '';
    selectedMinute.value = '';
  }
}, { immediate: true });

const updateTime = () => {
  if (selectedHour.value && selectedMinute.value) {
    emit('update:modelValue', `${selectedHour.value}:${selectedMinute.value}`);
  } else {
    emit('update:modelValue', '');
  }
};
</script>
