<template>
  <DepartmentManagement
    type="tourism"
    :modules="['flight', 'hajj_umra', 'visa']"
    title="إدارة قسم السياحة"
    department-name="السياحة"
  />
</template>

<script setup>
import DepartmentManagement from './DepartmentManagement.vue';
</script>
