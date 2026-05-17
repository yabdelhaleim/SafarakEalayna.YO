<template>
  <div class="min-h-screen bg-[#0a1628] flex items-center justify-center p-6 relative overflow-hidden text-white">
    <!-- Decorative background -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-sky-500/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-purple-500/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>
    </div>

    <div class="relative z-10 text-center max-w-2xl mx-auto animate-in fade-in zoom-in duration-700">
      <div class="mb-8">
        <h1 class="text-[120px] sm:text-[150px] font-black leading-none tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-white to-white/10 opacity-20">
          404
        </h1>
        <div class="mt-[-60px] sm:mt-[-80px]">
          <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-sky-500/20 border border-sky-500/30 mb-6 shadow-lg shadow-sky-500/10">
            <SearchX class="w-10 h-10 text-sky-400" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-black mb-4">الصفحة غير موجودة</h2>
          <p class="text-white/50 text-lg mb-10 leading-relaxed max-w-md mx-auto">
            عذراً، يبدو أنك وصلت إلى وجهة غير موجودة في نظامنا. تأكد من صحة الرابط أو عد للرئيسية.
          </p>
          
          <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
            <router-link 
              to="/dashboard" 
              class="w-full sm:w-auto px-10 py-4 bg-sky-600 hover:bg-sky-500 text-white font-bold rounded-2xl transition-all hover:scale-105 shadow-xl shadow-sky-600/20 flex items-center justify-center gap-2"
            >
              <Home class="w-5 h-5" />
              العودة للرئيسية
            </router-link>
            <button 
              @click="$router.back()" 
              class="w-full sm:w-auto px-10 py-4 bg-white/5 hover:bg-white/10 border border-white/10 text-white font-bold rounded-2xl transition-all flex items-center justify-center gap-2"
            >
              <ArrowLeft class="w-5 h-5" />
              رجوع للخلف
            </button>
          </div>
        </div>
      </div>
      
      <div class="pt-12 border-t border-white/5 text-white/20 text-[10px] uppercase tracking-widest font-bold">
        Safarak Ealayna &copy; 2026 • Professional Agency Management
      </div>
    </div>
  </div>
</template>

<script setup>
import { SearchX, Home, ArrowLeft } from 'lucide-vue-next';
</script>
