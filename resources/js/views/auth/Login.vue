<template>
  <div class="relative flex min-h-screen flex-col bg-bg-deep text-text-main">
    <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
      <div class="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-sky-500/15 blur-3xl" />
      <div class="absolute -bottom-32 -left-24 h-96 w-96 rounded-full bg-gold/10 blur-3xl" />
      <div
        class="absolute inset-0 opacity-[0.12]"
        style="
          background-image: linear-gradient(rgba(255, 255, 255, 0.06) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, 0.06) 1px, transparent 1px);
          background-size: 48px 48px;
        "
      />
    </div>

    <div class="relative z-10 mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-10 sm:px-6">
      <header class="flight-hero mb-6 !mb-6 text-center">
        <div class="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-600 shadow-lg shadow-sky-500/30">
          <Plane class="h-7 w-7 text-white" />
        </div>
        <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">سفرك علينا</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl">تسجيل الدخول</h1>
        <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-text-muted">
          أدخل البريد وكلمة المرور للوصول إلى لوحة التحكم — نفس أسلوب واجهة النظام الداكنة.
        </p>
      </header>

      <div class="flight-panel !p-6 sm:!p-8">
        <div
          v-if="errorMessage"
          class="mb-6 flex items-start gap-3 rounded-xl border border-error/35 bg-error/10 p-4 text-sm text-error"
        >
          <AlertCircle class="mt-0.5 h-5 w-5 shrink-0" />
          <span class="leading-relaxed">{{ errorMessage }}</span>
        </div>

        <form class="space-y-5" @submit.prevent="handleLogin">
          <div>
            <label for="email" class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">
              البريد الإلكتروني
            </label>
            <div class="relative">
              <Mail class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-text-muted" />
              <input
                id="email"
                v-model="form.email"
                type="email"
                class="flight-input !bg-input-bg !pl-12 !text-text-main placeholder:!text-text-muted/80"
                :class="errors.email ? '!border-error/50 !ring-2 !ring-error/25' : ''"
                placeholder="admin@admin.com"
                required
                autocomplete="email"
                dir="ltr"
              />
            </div>
            <p v-if="errors.email" class="mt-1.5 text-xs text-error">
              {{ Array.isArray(errors.email) ? errors.email[0] : errors.email }}
            </p>
          </div>

          <div>
            <label for="password" class="mb-2 block text-xs font-bold uppercase tracking-wider text-text-muted">
              كلمة المرور
            </label>
            <div class="relative">
              <Lock class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-text-muted" />
              <input
                id="password"
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                class="flight-input !bg-input-bg !pl-12 !pr-12 !text-text-main placeholder:!text-text-muted/80"
                :class="errors.password ? '!border-error/50 !ring-2 !ring-error/25' : ''"
                placeholder="••••••••"
                required
                autocomplete="current-password"
                dir="ltr"
              />
              <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-text-muted transition-colors hover:bg-white/10 hover:text-text-main"
                @click="showPassword = !showPassword"
              >
                <Eye v-if="!showPassword" class="h-5 w-5" />
                <EyeOff v-else class="h-5 w-5" />
              </button>
            </div>
            <p v-if="errors.password" class="mt-1.5 text-xs text-error">
              {{ Array.isArray(errors.password) ? errors.password[0] : errors.password }}
            </p>
          </div>

          <button
            type="submit"
            class="btn-airline w-full justify-center py-3.5 shadow-xl disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="authStore.loading.login"
          >
            <Loader2 v-if="authStore.loading.login" class="h-5 w-5 animate-spin" />
            <span v-else>تسجيل الدخول</span>
          </button>
        </form>

        <!-- Professional Credentials Box -->
        <div class="mt-8 rounded-2xl border border-white/10 bg-white/5 p-5 shadow-inner">
          <div class="mb-4 flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gold/20 text-gold">
              <Info class="h-5 w-5" />
            </div>
            <div>
              <span class="block text-[10px] font-black uppercase tracking-widest text-text-main">بيانات الوصول السريع</span>
              <span class="text-[9px] text-text-muted italic">لأغراض المراجعة والتدقيق</span>
            </div>
          </div>
          <div class="space-y-3">
            <button
              type="button"
              class="group flex w-full items-center justify-between rounded-xl border border-white/5 bg-card-bg/50 px-4 py-3 text-start transition-all hover:border-gold/30 hover:bg-gold/5"
              @click="fillAdmin"
            >
              <div class="flex flex-col">
                <span class="text-xs font-bold text-text-muted group-hover:text-text-main">مدير النظام</span>
                <span class="text-[9px] text-text-muted/50 font-mono">Full Access</span>
              </div>
              <code class="font-mono text-[10px] text-gold bg-gold/10 px-2 py-0.5 rounded border border-gold/20">admin@admin.com</code>
            </button>
            <button
              type="button"
              class="group flex w-full items-center justify-between rounded-xl border border-white/5 bg-card-bg/50 px-4 py-3 text-start transition-all hover:border-gold/30 hover:bg-gold/5"
              @click="fillEmployee"
            >
              <div class="flex flex-col">
                <span class="text-xs font-bold text-text-muted group-hover:text-text-main">موظف (مثال)</span>
                <span class="text-[9px] text-text-muted/50 font-mono">Standard Ops</span>
              </div>
              <code class="font-mono text-[10px] text-gold bg-gold/10 px-2 py-0.5 rounded border border-gold/20">employee1@office.com</code>
            </button>
          </div>
          <div class="mt-4 pt-4 border-t border-white/5 text-center">
            <span class="text-[10px] text-text-muted/60">
              ⚠️ يتم إنشاء الحسابات الجديدة حصراً من داخل لوحة الإدارة.
            </span>
          </div>
        </div>
      </div>

      <p class="mt-8 text-center text-[11px] text-text-muted/80">© 2026 سفرك علينا. جميع الحقوق محفوظة.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import { Plane, Mail, Lock, Eye, EyeOff, AlertCircle, Loader2, Info } from 'lucide-vue-next';

const router = useRouter();
const authStore = useAuthStore();

const form = reactive({
  email: '',
  password: '',
});

const showPassword = ref(false);

const errors = computed(() => authStore.errors);
const errorMessage = computed(() => {
  if (authStore.errors?.message) return authStore.errors.message;
  return '';
});

const handleLogin = async () => {
  const result = await authStore.login(form);
  if (result.success) {
    router.push('/dashboard');
  }
};

const fillAdmin = () => {
  form.email = 'admin@admin.com';
  form.password = '11223311';
};

const fillEmployee = () => {
  form.email = 'employee1@office.com';
  form.password = 'password';
};
</script>
