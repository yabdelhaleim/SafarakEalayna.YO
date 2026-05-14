<template>
  <div class="mx-auto max-w-7xl space-y-8 pb-16">
    <header class="flight-hero relative !from-violet-900/90 !to-slate-900/90">
      <div class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-violet-400/90">الخدمات الإلكترونية</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">الخزنة والمالية</h1>
          <p class="mt-2 text-sm text-text-muted">
            عرض أرصدة الحسابات (خزائن/بنوك/محافظ/خزينة عامة) الخاصة بموديول الخدمات الإلكترونية فقط.
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
          <router-link
            to="/online"
            class="btn-airline-ghost rounded-xl px-4 py-2.5 text-sm font-bold"
          >
            <ArrowRight class="mb-0.5 ml-2 inline h-4 w-4" /> رجوع للمعاملات
          </router-link>
        </div>
      </div>
    </header>

    <div v-if="store.loading.settings" class="bg-card-bg border border-white/10 rounded-2xl p-12 flex items-center justify-center">
      <Loader2 class="w-8 h-8 text-gold animate-spin" />
    </div>

    <template v-else>
      <!-- KPIs -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <SummaryCard :icon="Wallet" title="إجمالي المحافظ" :value="formatMoney(totals.wallet)" accent="info" />
        <SummaryCard :icon="Building2" title="إجمالي البنوك" :value="formatMoney(totals.bank)" accent="warning" />
        <SummaryCard :icon="Banknote" title="إجمالي الخزائن" :value="formatMoney(totals.cashbox)" accent="gold" />
        <SummaryCard :icon="BriefcaseBusiness" title="الخزينة العامة" :value="formatMoney(totals.treasury)" accent="success" />
      </div>

      <!-- Accounts list -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="flight-panel">
          <div class="flex items-center justify-between gap-4">
            <h2 class="flight-panel__title">الحسابات</h2>
            <span class="text-xs text-text-muted">({{ store.accounts.length }})</span>
          </div>

          <div v-if="!store.accounts.length" class="mt-6 text-center text-text-muted text-sm">
            لا توجد حسابات مفعّلة للخدمات الإلكترونية.
          </div>

          <div v-else class="mt-4 space-y-2">
            <button
              v-for="acc in store.accounts"
              :key="acc.id"
              type="button"
              class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-right transition-colors hover:bg-white/10"
              @click="openStatement(acc)"
            >
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <div class="font-bold text-text-main truncate">{{ acc.name }}</div>
                  <div class="mt-1 text-xs text-text-muted font-mono">
                    {{ acc.type }} <span v-if="acc.wallet_provider">• {{ acc.wallet_provider }}</span>
                    <span v-if="acc.wallet_number">• {{ acc.wallet_number }}</span>
                  </div>
                </div>
                <div class="shrink-0 text-left">
                  <div
                    class="font-mono font-black"
                    :class="Number(acc.balance) >= 0 ? 'text-success' : 'text-error'"
                  >
                    {{ formatMoney(acc.balance, acc.currency) }}
                  </div>
                  <div class="text-[11px] text-text-muted mt-1">فتح كشف الحساب</div>
                </div>
              </div>
            </button>
          </div>
        </div>

        <div class="flight-panel">
          <h2 class="flight-panel__title">ملاحظات سريعة</h2>
          <div class="mt-4 space-y-3 text-sm text-text-muted leading-relaxed">
            <p>
              - هذه الصفحة تعتمد على بيانات الحسابات القادمة من لوحة التحكم (Filament) للموديول (Online).
            </p>
            <p>
              - لعرض القيود بالتفصيل اضغط على أي حساب لفتح "كشف الحساب".
            </p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useOnlineStore } from '@/stores/onlineStore';
import SummaryCard from '@/components/online/OnlineSummaryCard.vue';
import {
  ArrowRight,
  Loader2,
  Wallet,
  Building2,
  Banknote,
  BriefcaseBusiness,
} from 'lucide-vue-next';

const store = useOnlineStore();
const router = useRouter();

const totals = computed(() => {
  const sumBy = (type) =>
    store.accounts
      .filter((a) => (a.type ?? '').toLowerCase() === type)
      .reduce((acc, a) => acc + Number(a.balance ?? 0), 0);

  return {
    wallet: sumBy('wallet'),
    bank: sumBy('bank'),
    cashbox: sumBy('cashbox'),
    treasury: sumBy('treasury'),
  };
});

function formatMoney(value, currency = 'EGP') {
  const number = Number(value ?? 0);
  try {
    return new Intl.NumberFormat('ar-EG', { style: 'currency', currency }).format(number);
  } catch {
    return `${number.toFixed(2)} ${currency}`;
  }
}

function openStatement(acc) {
  router.push(`/finance/accounts/${acc.id}/statement`);
}

onMounted(async () => {
  if (!store.serviceTypes.length && !store.accounts.length) {
    await store.fetchAllSettings();
  }
});
</script>

