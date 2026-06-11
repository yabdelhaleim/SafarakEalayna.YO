<template>
  <div class="space-y-8 animate-in fade-in duration-500">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Customer & Booking Info -->
      <div class="space-y-6">
        <section class="p-6 bg-card border border-white/10 rounded-2xl">
          <h3 class="text-xs text-muted uppercase tracking-widest mb-4 flex items-center gap-2">
            <User class="w-4 h-4" /> Customer Details
          </h3>
          <div v-if="booking.customer" class="space-y-1">
            <div class="text-xl font-bold text-gold">{{ booking.customer.name }}</div>
            <div class="text-muted">{{ booking.customer.phone }}</div>
            <div class="text-muted text-sm">{{ booking.customer.email }}</div>
          </div>
        </section>

        <section class="p-6 bg-card border border-white/10 rounded-2xl">
          <h3 class="text-xs text-muted uppercase tracking-widest mb-4 flex items-center gap-2">
            <FileText class="w-4 h-4" /> Booking Information
          </h3>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-[10px] text-muted uppercase">Booking #</div>
              <div class="font-mono text-gold">{{ booking.bookingNumber }}</div>
            </div>
            <div>
              <div class="text-[10px] text-muted uppercase">System</div>
              <div>{{ booking.systemType }}</div>
            </div>
            <div>
              <div class="text-[10px] text-muted uppercase">Status</div>
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-warning animate-pulse"></span>
                <span class="capitalize">{{ booking.status }}</span>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- Pricing Summary -->
      <section class="p-8 bg-card border border-gold/20 rounded-2xl flex flex-col justify-between">
        <h3 class="text-xs text-muted uppercase tracking-widest mb-6 flex items-center gap-2">
          <CreditCard class="w-4 h-4" /> Financial Summary
        </h3>
        
        <div class="space-y-4">
          <div class="flex justify-between items-center text-muted">
            <span>Purchase Price</span>
            <span class="font-mono">{{ booking.pricing.purchasePrice.toLocaleString() }} ج.م</span>
          </div>
          <div class="flex justify-between items-center text-xl font-bold">
            <span>Selling Price</span>
            <span class="font-mono text-gold">{{ booking.pricing.sellingPrice.toLocaleString() }} ج.م</span>
          </div>
          <div class="pt-4 border-t border-white/10 flex justify-between items-center">
            <span class="text-sm text-muted">Expected Profit</span>
            <div class="flex flex-col items-end">
              <span :class="['text-2xl font-bold font-mono', booking.pricing.profit >= 0 ? 'text-success' : 'text-error']">
                {{ booking.pricing.profit >= 0 ? '+' : '' }}{{ booking.pricing.profit.toLocaleString() }} ج.م
              </span>
              <span class="text-[10px] px-2 py-0.5 rounded bg-success/10 text-success font-bold">
                {{ profitPercentage }}% Margin
              </span>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Flight Segments -->
    <section class="p-6 bg-card border border-white/10 rounded-2xl">
      <h3 class="text-xs text-muted uppercase tracking-widest mb-6 flex items-center gap-2">
        <Plane class="w-4 h-4" /> Flight Itinerary ({{ booking.segments.length }} Segments)
      </h3>
      <div class="space-y-6">
        <div v-for="(segment, idx) in booking.segments" :key="idx" 
          class="flex flex-col md:flex-row md:items-center gap-6 pb-6 border-b border-white/5 last:border-0 last:pb-0">
          <div class="flex items-center gap-4 flex-1">
            <div class="text-2xl font-mono font-bold">{{ segment.from }}</div>
            <div class="flex-1 border-t-2 border-dashed border-white/10 relative">
              <Plane class="w-4 h-4 text-gold absolute left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-90" />
            </div>
            <div class="text-2xl font-mono font-bold">{{ segment.to }}</div>
          </div>
          
          <div class="grid grid-cols-2 md:grid-cols-3 gap-8 flex-[2]">
            <div>
              <div class="text-[10px] text-muted uppercase">Flight</div>
              <div class="font-medium">{{ segment.airline }} - {{ segment.flightNumber }}</div>
            </div>
            <div>
              <div class="text-[10px] text-muted uppercase">Departure</div>
              <div class="font-medium">{{ segment.departureDate }} | {{ segment.departureTime }}</div>
            </div>
            <div>
              <div class="text-[10px] text-muted uppercase">Baggage</div>
              <div class="font-medium">{{ segment.baggage || 'None' }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Passengers -->
    <section class="p-6 bg-card border border-white/10 rounded-2xl">
      <h3 class="text-xs text-muted uppercase tracking-widest mb-6 flex items-center gap-2">
        <Users class="w-4 h-4" /> Passenger List ({{ booking.passengers.length }})
      </h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div v-for="(pax, idx) in booking.passengers" :key="idx" 
          class="p-3 bg-white/5 rounded-xl flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-gold/10 text-gold flex items-center justify-center text-xs font-bold">
            {{ idx + 1 }}
          </div>
          <div>
            <div class="font-medium text-sm">{{ pax.name }}</div>
            <div class="text-[10px] text-muted uppercase">{{ pax.type }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { User, Plane, Users, CreditCard, FileText } from 'lucide-vue-next';

const props = defineProps({
  booking: {
    type: Object,
    required: true
  }
});

const profitPercentage = computed(() => {
  const { purchasePrice, profit } = props.booking.pricing;
  if (!purchasePrice || purchasePrice === 0) return 0;
  return ((profit / purchasePrice) * 100).toFixed(1);
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.bg-warning { background-color: var(--warning); }
</style>
