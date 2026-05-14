<template>
  <div class="space-y-6">
    <!-- Passenger Counts -->
    <div class="flex flex-wrap gap-4 p-6 bg-card border border-white/10 rounded-2xl">
      <div v-for="type in ['adult', 'child', 'infant']" :key="type" class="flex-1 min-w-[120px] flex flex-col items-center">
        <span class="text-xs text-muted uppercase mb-2">{{ type }}s</span>
        <div class="flex items-center gap-4">
          <button @click="adjustCount(type, -1)" 
            class="w-8 h-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
            <Minus class="w-4 h-4" />
          </button>
          <span class="text-xl font-bold font-mono">{{ counts[type] }}</span>
          <button @click="adjustCount(type, 1)" 
            class="w-8 h-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
            <Plus class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Validation Warning -->
    <div v-if="infantsExceedAdults" class="p-4 bg-warning/10 border border-warning/30 rounded-xl flex items-center gap-3 mb-6">
      <AlertCircle class="w-5 h-5 text-warning flex-shrink-0" />
      <div class="text-sm">
        <span class="font-bold text-warning">Warning:</span> Infants cannot exceed adults. Each adult can accompany maximum one infant.
      </div>
    </div>

    <!-- Passenger Counts -->
    <div class="flex flex-wrap gap-4 p-6 bg-card border border-white/10 rounded-2xl">
      <div v-for="type in ['adult', 'child', 'infant']" :key="type" class="flex-1 min-w-[120px] flex flex-col items-center">
        <span class="text-xs text-muted uppercase mb-2">{{ type }}s</span>
        <div class="flex items-center gap-4">
          <button @click="adjustCount(type, -1)"
            class="w-8 h-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
            <Minus class="w-4 h-4" />
          </button>
          <span class="text-xl font-bold font-mono">{{ counts[type] }}</span>
          <button @click="adjustCount(type, 1)"
            class="w-8 h-8 flex items-center justify-center rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
            <Plus class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Passenger Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div v-for="(passenger, index) in passengers" :key="passenger.id || index"
        class="p-6 bg-card border border-white/10 rounded-2xl relative group animate-in zoom-in-95 duration-200">

        <div class="flex justify-between items-center mb-4">
          <span class="px-2 py-1 rounded bg-gold/10 text-gold text-[10px] font-bold uppercase tracking-widest">
            {{ passenger.type }}
          </span>
          <button @click="removePassenger(index)"
            :class="['transition-opacity', passengers.length === 1 ? 'opacity-0 cursor-not-allowed' : 'opacity-0 group-hover:opacity-100']"
            :disabled="passengers.length === 1">
            <Trash2 class="w-4 h-4" />
          </button>
        </div>

        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Full Name*</label>
          <input v-model="passenger.name" type="text" placeholder="John Doe"
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none"
            :class="{ 'border-error': passenger.name && passenger.name.length < 3 }" />
          <p v-if="passenger.name && passenger.name.length < 3" class="text-[10px] text-error mt-1">Name must be at least 3 characters</p>
        </div>

        <!-- Type Toggle (Mobile only or quick fix) -->
        <div class="mt-4 flex gap-2">
          <button v-for="t in ['adult', 'child', 'infant']" :key="t"
            @click="passenger.type = t"
            :class="[
              'flex-1 py-1.5 text-[10px] font-bold uppercase rounded-lg border transition-all',
              passenger.type === t ? 'bg-gold border-gold text-black' : 'bg-white/5 border-white/5 text-muted hover:border-white/20'
            ]">
            {{ t }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="passengers.length === 0" class="text-center p-12 bg-white/5 rounded-2xl border-2 border-dashed border-white/10">
      <Users class="w-12 h-12 text-white/10 mx-auto mb-4" />
      <p class="text-muted italic">Add at least one passenger to continue</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Plus, Minus, Trash2, Users, AlertCircle } from 'lucide-vue-next';

const props = defineProps({
  passengers: {
    type: Array,
    required: true
  }
});

const emit = defineEmits(['update:passengers']);

const counts = computed(() => {
  return {
    adult: props.passengers.filter(p => p.type === 'adult').length,
    child: props.passengers.filter(p => p.type === 'child').length,
    infant: props.passengers.filter(p => p.type === 'infant').length
  };
});

const infantsExceedAdults = computed(() => {
  return counts.value.infant > counts.value.adult;
});

const allNamesValid = computed(() => {
  return props.passengers.every(p => p.name && p.name.length >= 3);
});

const adjustCount = (type, delta) => {
  if (delta > 0) {
    // Add passenger
    const newPassengers = [...props.passengers, {
      id: crypto.randomUUID(),
      name: '',
      type: type
    }];
    emit('update:passengers', newPassengers);
  } else {
    // Remove last passenger of this type
    const index = [...props.passengers].reverse().findIndex(p => p.type === type);
    if (index !== -1) {
      const actualIndex = props.passengers.length - 1 - index;
      const newPassengers = props.passengers.filter((_, i) => i !== actualIndex);
      emit('update:passengers', newPassengers);
    }
  }
};

const removePassenger = (index) => {
  const newPassengers = props.passengers.filter((_, i) => i !== index);
  emit('update:passengers', newPassengers);
};

defineExpose({
  counts,
  infantsExceedAdults,
  allNamesValid
});

// Validation: Infants <= Adults
watch(() => counts.value, (newCounts) => {
  if (newCounts.infant > newCounts.adult) {
    // You might want to handle this validation here or in the parent
    console.warn('Infants cannot exceed adults');
  }
}, { deep: true });
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.border-gold { border-color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
</style>
