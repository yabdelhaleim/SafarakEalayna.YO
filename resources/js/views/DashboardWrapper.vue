<template>
  <component :is="activeComponent" />
</template>

<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/authStore';
import Dashboard from '@/views/Dashboard.vue';
import EmployeeDashboard from '@/views/EmployeeDashboard.vue';

const authStore = useAuthStore();

const activeComponent = computed(() => {
  if (authStore.user?.role === 'employee') {
    return EmployeeDashboard;
  }
  return Dashboard;
});
</script>
