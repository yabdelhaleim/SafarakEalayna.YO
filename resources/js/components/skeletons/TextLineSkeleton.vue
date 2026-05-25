<template>
  <div class="animate-pulse flex flex-col" :class="gapClass">
    <div 
      v-for="i in lines" 
      :key="i" 
      class="bg-gray-200 rounded"
      :class="[heightClass, getWidthClass(i)]"
    ></div>
  </div>
</template>

<script setup>
const props = defineProps({
  lines: {
    type: Number,
    default: 1
  },
  heightClass: {
    type: String,
    default: 'h-4'
  },
  gapClass: {
    type: String,
    default: 'gap-3'
  }
});

const getWidthClass = (index) => {
  // Make lines vary in length for a realistic text look
  if (props.lines === 1) return 'w-full';
  
  const widthClasses = ['w-full', 'w-5/6', 'w-4/5', 'w-3/4', 'w-2/3', 'w-1/2'];
  return widthClasses[index % widthClasses.length];
};
</script>
