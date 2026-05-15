<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <div>
      <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">الفواتير</h1>
      <p class="text-text-muted mt-1">إدارة ومتابعة الفواتير</p>
    </div>

    <div class="bg-card-bg border border-white/10 rounded-2xl p-12 text-center">
      <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-6">
        <FileText class="w-10 h-10 text-white/10" />
      </div>
      <h2 class="text-2xl font-bold text-text-main mb-2">قريباً</h2>
      <p class="text-text-muted max-w-md mx-auto">
        صفحة الفواتير قيد التطوير. ستتمكن من عرض وإضافة وتعديل الفواتير قريباً.
      </p>
    </div>
  </div>
</template>

<script setup>
import { FileText } from 'lucide-vue-next';
</script>
