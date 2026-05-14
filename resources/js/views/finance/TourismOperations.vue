<template>
  <OperationsTemplate
    title="عمليات السياحة"
    subtitle="الطيران والحج والعمرة والتأشيرات"
    :allowed-modules="['flight', 'hajj_umra', 'visa']"
  />
</template>

<script setup>
import OperationsTemplate from './OperationsTemplate.vue';
</script>
