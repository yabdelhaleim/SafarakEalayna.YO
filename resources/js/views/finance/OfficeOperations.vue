<template>
  <OperationsTemplate
    title="عمليات المكتب"
    subtitle="الباص والكاش وفوري والخدمات الإلكترونية"
    :allowed-modules="['bus', 'wallet', 'online', 'fawry', 'general']"
  />
</template>

<script setup>
import OperationsTemplate from './OperationsTemplate.vue';
</script>
