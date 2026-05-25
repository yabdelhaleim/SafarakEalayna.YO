<template>
  <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100 animate-pulse">
    <!-- Header skeleton -->
    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
      <div class="h-5 bg-gray-200 rounded w-1/4"></div>
      <div class="h-8 bg-gray-200 rounded w-24"></div>
    </div>
    
    <!-- Table content skeleton -->
    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-50/50 border-b border-gray-100">
            <th v-for="i in columns" :key="'th-'+i" class="px-6 py-4">
              <div class="h-4 bg-gray-200 rounded w-3/4"></div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="'tr-'+row" class="border-b border-gray-50">
            <td v-for="col in columns" :key="'td-'+row+'-'+col" class="px-6 py-4">
              <div class="h-4 bg-gray-100 rounded" :class="col === 1 ? 'w-1/2' : 'w-3/4'"></div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
defineProps({
  rows: {
    type: Number,
    default: 5
  },
  columns: {
    type: Number,
    default: 5
  }
});
</script>
