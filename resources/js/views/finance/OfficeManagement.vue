<template>
  <DepartmentManagement
    type="office"
    :modules="['bus', 'wallet', 'online', 'fawry', 'general']"
    title="إدارة قسم المكتب"
    department-name="المكتب"
  />
</template>

<script setup>
import DepartmentManagement from './DepartmentManagement.vue';
</script>
