<template>
  <div class="space-y-1">
    <button
      @click="toggle"
      :class="[
        'w-full flex items-center justify-between px-4 py-3 rounded-xl transition-all duration-200 group outline-none',
        isActive ? 'bg-white/10 text-text-main font-bold' : 'text-text-muted hover:bg-white/5 hover:text-text-main'
      ]"
    >
      <div class="flex items-center gap-3">
        <component
          :is="icon"
          :class="[
            'w-5 h-5 transition-transform duration-200',
            isActive ? 'text-gold scale-110' : 'group-hover:scale-105'
          ]"
        />
        <span>{{ label }}</span>
      </div>
      <ChevronDown
        :class="[
          'w-4 h-4 transition-transform duration-300',
          isOpen ? 'rotate-180 text-white' : 'text-text-muted'
        ]"
      />
    </button>
    
    <div
      v-show="isOpen"
      class="pl-4 pr-9 mt-1 space-y-1 overflow-hidden"
    >
      <router-link
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        :class="[
          'block px-3 py-2 rounded-lg text-sm transition-all duration-200 border-r-2',
          isItemActive(item.to)
            ? 'bg-gold/10 text-gold border-gold font-bold'
            : 'border-transparent text-text-muted hover:bg-white/5 hover:text-white hover:border-white/20'
        ]"
      >
        {{ item.label }}
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps({
  label: { type: String, required: true },
  icon: { type: [Object, Function], required: true },
  items: { type: Array, required: true }, // { label, to }
  activePathPrefix: { type: String, required: true }
});

const route = useRoute();
const isOpen = ref(false);

const isActive = computed(() => {
  return route.path.startsWith(props.activePathPrefix);
});

const isItemActive = (path) => {
  return route.path === path || route.path.startsWith(path + '/');
};

watch(() => route.path, () => {
  if (isActive.value && !isOpen.value) {
    isOpen.value = true;
  }
});

onMounted(() => {
  if (isActive.value) {
    isOpen.value = true;
  }
});

const toggle = () => {
  isOpen.value = !isOpen.value;
};
</script>
