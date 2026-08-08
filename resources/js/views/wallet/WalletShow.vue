<template>
  <div class="animate-in fade-in duration-500 pb-16 wallet-module">
    <!-- Header -->
    <header class="relative overflow-hidden bg-gradient-to-br from-[#0a192f] via-[#112240] to-[#1a365d] border-b border-white/5">
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl translate-y-1/2 -translate-x-1/3"></div>
      </div>
      <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex items-center gap-4">
          <router-link
            :to="{ name: 'wallet.dashboard' }"
            class="p-2 hover:bg-white/10 rounded-lg transition-all"
            aria-label="عودة"
          >
            <ArrowRight class="w-5 h-5 text-white/70" />
          </router-link>
          <div>
            <div class="flex items-center gap-3 mb-2">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 border border-blue-500/30">
                <Wallet class="h-5 w-5 text-blue-400" />
              </div>
              <span class="text-xs font-bold uppercase tracking-[0.2em] text-blue-400/80">وحدة المحافظ والتحويلات</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
              تفاصيل العملية #{{ transaction?.id || '—' }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
              عرض كامل لبيانات عملية المحفظة والحسابات المرتبطة.
            </p>
          </div>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8 space-y-8">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-32">
        <div class="w-10 h-10 border-4 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="flex flex-col items-center justify-center py-32 gap-5">
        <div class="w-20 h-20 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center">
          <AlertCircle class="w-10 h-10 text-red-400" />
        </div>
        <div class="text-center">
          <h3 class="text-xl font-bold text-white">{{ error }}</h3>
          <p class="text-sm text-white/40 mt-1">فشل جلب تفاصيل العملية</p>
        </div>
        <div class="flex gap-3">
          <button @click="fetchTransaction" class="px-6 py-2.5 bg-blue-500 text-white rounded-xl font-bold hover:bg-blue-400 transition">
            إعادة المحاولة
          </button>
          <router-link :to="{ name: 'wallet.dashboard' }" class="px-6 py-2.5 bg-white/10 text-white rounded-xl font-bold hover:bg-white/20 transition">
            عودة للداش بورد
          </router-link>
        </div>
      </div>

      <!-- Detail -->
      <template v-else-if="transaction">
        <!-- Top KPI Cards -->
        <section class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <!-- Type -->
          <div class="group relative overflow-hidden rounded-2xl border border-blue-500/20 bg-gradient-to-br from-blue-500/10 to-transparent p-6 transition hover:border-blue-500/40">
            <div class="flex items-center justify-between mb-4">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                <ArrowLeftRight class="h-5 w-5" />
              </div>
              <span
                :class="typeBadgeClass(transaction.type_color)"
                class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full"
              >
                {{ transaction.type_label }}
              </span>
            </div>
            <p class="text-xs font-bold uppercase tracking-wider text-blue-400/70 mb-1">نوع العملية</p>
            <p class="font-mono text-2xl font-black text-white">{{ transaction.type_label }}</p>
          </div>

          <!-- Amount -->
          <div class="group relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-transparent p-6 transition hover:border-emerald-500/40">
            <div class="flex items-center justify-between mb-4">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                <TrendingUp class="h-5 w-5" />
              </div>
            </div>
            <p class="text-xs font-bold uppercase tracking-wider text-emerald-400/70 mb-1">المبلغ</p>
            <p class="font-mono text-2xl font-black text-white tabular-nums">
              {{ fmt(transaction.amount) }}
            </p>
            <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
          </div>

          <!-- Service Fee -->
          <div class="group relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-transparent p-6 transition hover:border-sky-500/40">
            <div class="flex items-center justify-between mb-4">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                <BarChart3 class="h-5 w-5" />
              </div>
            </div>
            <p class="text-xs font-bold uppercase tracking-wider text-sky-400/70 mb-1">قيمة الخدمة</p>
            <p class="font-mono text-2xl font-black text-white tabular-nums">
              {{ fmt(transaction.service_fee) }}
            </p>
            <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
          </div>

          <!-- Total -->
          <div class="group relative overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-transparent p-6 transition hover:border-amber-500/40">
            <div class="flex items-center justify-between mb-4">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                <CreditCard class="h-5 w-5" />
              </div>
            </div>
            <p class="text-xs font-bold uppercase tracking-wider text-amber-400/70 mb-1">الإجمالي</p>
            <p class="font-mono text-2xl font-black text-white tabular-nums">
              {{ fmt(transaction.total_amount) }}
            </p>
            <p class="text-[11px] text-white/30 mt-1">جنيه مصري</p>
          </div>
        </section>

        <!-- Parties & Accounts -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <!-- Customer Card -->
          <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-6 space-y-5">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                <Users class="h-5 w-5" />
              </div>
              <h2 class="text-lg font-bold text-white">بيانات العميل</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">اسم العميل</p>
                <p class="text-sm font-bold text-white/90">{{ transaction.customer_name || '—' }}</p>
              </div>
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">رقم المحفظة</p>
                <p class="text-sm font-mono font-bold text-white/90" dir="ltr">{{ transaction.wallet_number || '—' }}</p>
              </div>
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">نوع المحفظة</p>
                <p class="text-sm font-bold text-white/90">
                  <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/5 text-white/60">
                    {{ transaction.wallet_type?.name || '—' }}
                  </span>
                </p>
              </div>
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">الموظف</p>
                <p class="text-sm font-bold text-white/90">{{ transaction.employee?.name || '—' }}</p>
              </div>
            </div>
          </section>

          <!-- Accounts Card -->
          <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-6 space-y-5">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400">
                <Vault class="h-5 w-5" />
              </div>
              <h2 class="text-lg font-bold text-white">الحسابات</h2>
            </div>
            <div class="space-y-3">
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">حساب المحفظة الإلكترونية (الوكالة)</p>
                <p class="text-sm font-bold text-white/90">{{ transaction.wallet_account?.name || '—' }}</p>
              </div>
              <div class="space-y-1 p-4 bg-white/5 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">الحساب النقدي</p>
                <p class="text-sm font-bold text-white/90">{{ transaction.cash_account?.name || '—' }}</p>
              </div>
            </div>
          </section>
        </div>

        <!-- Notes -->
        <section v-if="transaction.notes" class="rounded-2xl border border-white/5 bg-white/[0.02] p-6">
          <h2 class="text-lg font-bold text-white mb-4">ملاحظات</h2>
          <p class="text-sm leading-relaxed text-white/70 whitespace-pre-wrap">{{ transaction.notes }}</p>
        </section>

        <!-- Meta Footer -->
        <section class="rounded-2xl border border-white/5 bg-white/[0.02] p-6">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-400">
                <Clock class="w-5 h-5" />
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">تاريخ الإنشاء</p>
                <p class="text-sm font-mono font-bold text-white/90">{{ formatDt(transaction.created_at) }}</p>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                <User class="w-5 h-5" />
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">أُضيفت بواسطة</p>
                <p class="text-sm font-bold text-white/90">{{ transaction.created_by_name || 'النظام' }}</p>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-400">
                <RefreshCw class="w-5 h-5" />
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-white/40">آخر تحديث</p>
                <p class="text-sm font-mono font-bold text-white/90">{{ formatDt(transaction.updated_at) }}</p>
              </div>
            </div>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { isRequestCanceled } from '@/utils/api';
import {
  ArrowRight, Wallet, ArrowLeftRight, TrendingUp, BarChart3, CreditCard,
  Users, Vault, Clock, User, RefreshCw, AlertCircle,
} from 'lucide-vue-next';

const route = useRoute();
const transaction = ref(null);
const loading = ref(true);
const error = ref(null);

const fmt = (v) => Number(v || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ar-EG', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return iso;
  }
};

const typeBadgeClass = (color) => {
  const map = {
    success: 'bg-emerald-500/20 text-emerald-400',
    warning: 'bg-amber-500/20 text-amber-400',
    danger: 'bg-red-500/20 text-red-400',
    info: 'bg-blue-500/20 text-blue-400',
  };
  return map[color] || 'bg-white/10 text-white/70';
};

const fetchTransaction = async () => {
  loading.value = true;
  error.value = null;
  try {
    const response = await axios.get(`/api/v1/wallet/transactions/${route.params.id}`);
    transaction.value = response.data?.data || null;
    if (!transaction.value) {
      error.value = 'لم يتم العثور على العملية';
    }
  } catch (e) {
    if (isRequestCanceled(e)) return;
    error.value = e.response?.data?.message || 'فشل تحميل تفاصيل العملية';
  } finally {
    loading.value = false;
  }
};

onMounted(fetchTransaction);
</script>

<style scoped>
.wallet-module {
  --blue-glow: rgba(59, 130, 246, 0.1);
}
</style>
