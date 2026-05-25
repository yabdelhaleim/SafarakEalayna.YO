<template>
  <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 animate-pulse flex flex-col" :style="{ minHeight: height }">
    <!-- Chart Header -->
    <div class="flex justify-between items-center mb-6">
      <div class="h-5 bg-gray-200 rounded w-1/3"></div>
      <div class="h-8 bg-gray-100 rounded w-20"></div>
    </div>
    
    <!-- Chart Area -->
    <div class="flex-1 flex items-end justify-between gap-2 mt-auto pt-8">
      <div 
        v-for="i in bars" 
        :key="i"
        class="bg-gray-100 rounded-t-sm w-full"
        :style="{ height: `${Math.max(20, Math.random() * 100)}%` }"
      ></div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  height: {
    type: String,
    default: '350px'
  },
  bars: {
    type: Number,
    default: 12 // e.g. 12 months
  }
});
</script>
