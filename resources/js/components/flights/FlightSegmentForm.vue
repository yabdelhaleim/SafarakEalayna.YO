<template>
  <div class="space-y-4">
    <div v-for="(segment, index) in segments" :key="segment.id || index" 
      class="p-6 bg-card border border-white/10 rounded-2xl relative group animate-in slide-in-from-bottom-4 duration-300"
      :style="{ animationDelay: `${index * 100}ms` }">
      
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-sm font-bold text-muted uppercase tracking-wider">Segment {{ index + 1 }}</h3>
        <button v-if="segments.length > 1" @click="removeSegment(index)" 
          class="p-2 text-error hover:bg-error/10 rounded-lg transition-colors opacity-0 group-hover:opacity-100">
          <Trash2 class="w-4 h-4" />
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="relative">
          <label class="block text-xs text-muted mb-2 uppercase">From*</label>
          <input v-model="segment.from" type="text" placeholder="CAI"
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
            @input="onAirportInput('from', $event)" />
          <div v-if="showAirportDropdown(segment.from)" class="absolute z-50 w-full mt-1 bg-card border border-white/10 rounded-xl shadow-2xl overflow-hidden max-h-60 overflow-y-auto">
            <button
              v-for="airport in filteredAirports(segment.from)"
              :key="airport.code"
              @click="selectAirport(segment, 'from', airport.code)"
              class="w-full p-3 text-left hover:bg-white/5 border-b border-white/5 last:border-0 transition-colors"
            >
              <div class="font-mono font-bold text-gold">{{ airport.code }}</div>
              <div class="text-xs text-muted">{{ airport.city }}, {{ airport.country }}</div>
            </button>
          </div>
        </div>
        <div class="relative">
          <label class="block text-xs text-muted mb-2 uppercase">To*</label>
          <input v-model="segment.to" type="text" placeholder="DXB"
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono"
            @input="onAirportInput('to', $event)" />
          <div v-if="showAirportDropdown(segment.to)" class="absolute z-50 w-full mt-1 bg-card border border-white/10 rounded-xl shadow-2xl overflow-hidden max-h-60 overflow-y-auto">
            <button
              v-for="airport in filteredAirports(segment.to)"
              :key="airport.code"
              @click="selectAirport(segment, 'to', airport.code)"
              class="w-full p-3 text-left hover:bg-white/5 border-b border-white/5 last:border-0 transition-colors"
            >
              <div class="font-mono font-bold text-gold">{{ airport.code }}</div>
              <div class="text-xs text-muted">{{ airport.city }}, {{ airport.country }}</div>
            </button>
          </div>
        </div>
        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Date*</label>
          <input v-model="segment.departureDate" type="date" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
        </div>
        
        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Dep. Time*</label>
          <input v-model="segment.departureTime" type="time" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
        </div>
        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Arr. Time*</label>
          <input v-model="segment.arrivalTime" type="time" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono" />
        </div>
        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Airline*</label>
          <input v-model="segment.airline" type="text" placeholder="EgyptAir" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
        </div>

        <div>
          <label class="block text-xs text-muted mb-2 uppercase">Flight No / PNR*</label>
          <input v-model="segment.flightNumber" type="text" placeholder="MS123" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono uppercase" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-xs text-muted mb-2 uppercase">Baggage</label>
          <input v-model="segment.baggage" type="text" placeholder="23kg or 1 piece" 
            class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
        </div>
      </div>
    </div>

    <button @click="addSegment" 
      class="w-full py-4 border-2 border-dashed border-white/10 rounded-2xl text-muted hover:border-gold/50 hover:text-gold transition-all flex items-center justify-center gap-2 group">
      <Plus class="w-5 h-5 group-hover:scale-110 transition-transform" />
      <span>Add Segment</span>
    </button>
  </div>
</template>

<script setup>
import { Trash2, Plus } from 'lucide-vue-next';

const props = defineProps({
  segments: {
    type: Array,
    required: true
  }
});

const emit = defineEmits(['update:segments']);

const addSegment = () => {
  const newSegments = [...props.segments, {
    id: crypto.randomUUID(),
    from: '',
    to: '',
    departureDate: '',
    departureTime: '',
    arrivalTime: '',
    airline: '',
    flightNumber: '',
    baggage: ''
  }];
  emit('update:segments', newSegments);
};

const removeSegment = (index) => {
  const newSegments = props.segments.filter((_, i) => i !== index);
  emit('update:segments', newSegments);
};

// IATA Airport data (major airports)
const airports = [
  { code: 'CAI', city: 'Cairo', country: 'Egypt' },
  { code: 'DXB', city: 'Dubai', country: 'UAE' },
  { code: 'JED', city: 'Jeddah', country: 'Saudi Arabia' },
  { code: 'RUH', city: 'Riyadh', country: 'Saudi Arabia' },
  { code: 'DOH', city: 'Doha', country: 'Qatar' },
  { code: 'KWI', city: 'Kuwait', country: 'Kuwait' },
  { code: 'BAH', city: 'Manama', country: 'Bahrain' },
  { code: 'MCT', city: 'Muscat', country: 'Oman' },
  { code: 'AMM', city: 'Amman', country: 'Jordan' },
  { code: 'BEY', city: 'Beirut', country: 'Lebanon' },
  { code: 'IST', city: 'Istanbul', country: 'Turkey' },
  { code: 'LHR', city: 'London', country: 'UK' },
  { code: 'CDG', city: 'Paris', country: 'France' },
  { code: 'FRA', city: 'Frankfurt', country: 'Germany' },
  { code: 'AMS', city: 'Amsterdam', country: 'Netherlands' },
  { code: 'JFK', city: 'New York', country: 'USA' },
  { code: 'LAX', city: 'Los Angeles', country: 'USA' },
  { code: 'YYZ', city: 'Toronto', country: 'Canada' },
  { code: 'SYD', city: 'Sydney', country: 'Australia' },
  { code: 'SIN', city: 'Singapore', country: 'Singapore' },
  { code: 'BKK', city: 'Bangkok', country: 'Thailand' },
  { code: 'DEL', city: 'Delhi', country: 'India' },
  { code: 'BOM', city: 'Mumbai', country: 'India' },
  { code: 'PEK', city: 'Beijing', country: 'China' },
  { code: 'NRT', city: 'Tokyo', country: 'Japan' },
  { code: 'CGK', city: 'Jakarta', country: 'Indonesia' },
  { code: 'KUL', city: 'Kuala Lumpur', country: 'Malaysia' },
  { code: 'MNL', city: 'Manila', country: 'Philippines' },
  { code: 'HKG', city: 'Hong Kong', country: 'China' },
  { code: 'ICN', city: 'Seoul', country: 'South Korea' }
];

const filteredAirports = (query) => {
  if (!query || query.length < 2) return [];
  const q = query.toUpperCase();
  return airports.filter(a =>
    a.code.includes(q) || a.city.toUpperCase().includes(q) || a.country.toUpperCase().includes(q)
  ).slice(0, 8);
};

const showAirportDropdown = (query) => {
  return query && query.length >= 2 && filteredAirports(query).length > 0;
};

const selectAirport = (segment, field, code) => {
  segment[field] = code;
};

const onAirportInput = (field, event) => {
  // Just trigger reactivity, the dropdown will show based on the value
};
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.border-gold { border-color: var(--gold); }
.text-error { color: var(--error); }
</style>
