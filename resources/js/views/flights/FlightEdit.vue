<template>
  <FlightCreate :is-edit="true" :booking-id="id" />
</template>

<script setup>
import FlightCreate from './FlightCreate.vue';

const props = defineProps(['id']);
</script>
