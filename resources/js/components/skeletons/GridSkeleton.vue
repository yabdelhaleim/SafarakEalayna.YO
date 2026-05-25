<template>
  <div class="grid" :class="gridClass">
    <div 
      v-for="i in count" 
      :key="i" 
      class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 animate-pulse flex flex-col gap-3"
      :style="{ minHeight: itemHeight }"
    >
      <div class="h-4 bg-gray-200 rounded w-1/2"></div>
      <div class="h-3 bg-gray-100 rounded w-full mt-2"></div>
      <div class="h-3 bg-gray-100 rounded w-5/6"></div>
      <div class="mt-auto pt-4 flex justify-between">
        <div class="h-8 bg-gray-200 rounded w-20"></div>
        <div class="h-8 bg-gray-100 rounded w-20"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  count: {
    type: Number,
    default: 4
  },
  gridClass: {
    type: String,
    default: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6'
  },
  itemHeight: {
    type: String,
    default: '180px'
  }
});
</script>
