<template>
  <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="font-display font-extrabold text-lg text-text-main">أفضل العملاء</h3>
      <!-- TODO: Create customers page -->
      <!-- <router-link
        to="/customers"
        class="text-sm text-gold hover:underline font-semibold"
      >
        عرض الكل
      </router-link> -->
    </div>

    <div v-if="customers.length === 0" class="text-center py-12">
      <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
        <Users class="w-8 h-8 text-white/10" />
      </div>
      <p class="text-text-muted text-sm">لا يوجد عملاء</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="(customer, index) in customers"
        :key="customer.id"
        class="flex items-center gap-4 p-3 rounded-xl hover:bg-white/5 transition-all group"
      >
        <!-- Rank -->
        <div
          :class="[
            'w-8 h-8 rounded-lg flex items-center justify-center font-bold text-sm flex-shrink-0',
            index < 3 ? 'bg-gold text-black' : 'bg-white/5 text-text-muted'
          ]"
        >
          {{ index + 1 }}
        </div>

        <!-- Customer Info -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-text-main truncate">
            {{ customer.name }}
          </p>
          <p class="text-xs text-text-muted truncate">
            {{ customer.phone }}
          </p>
        </div>

        <!-- Stats -->
        <div class="text-right flex-shrink-0">
          <p class="text-sm font-bold text-gold font-mono">
            {{ customer.total_bookings }} حجز
          </p>
          <p class="text-xs text-text-muted">
            {{ (customer.balance || 0).toLocaleString() }} جنيه
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Users } from 'lucide-vue-next';

const props = defineProps({
  customers: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
});
</script>
