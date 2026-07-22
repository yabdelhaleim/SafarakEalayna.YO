<template>
  <section class="bg-card-bg border border-white/10 rounded-3xl p-6">
    <!-- Header -->
    <header class="flex items-center justify-between mb-5">
      <div class="flex items-center gap-3">
        <div class="rounded-xl bg-gold/15 p-2.5 text-gold">
          <BellRing class="w-5 h-5" />
        </div>
        <div>
          <h2 class="text-base font-black text-white">🔔 مجموعات تقترب من السقف</h2>
          <p class="text-xs text-text-muted mt-0.5">تنبيهات العتبات (info / warning / danger)</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span class="text-xs text-text-muted hidden sm:inline">تحديث كل 30 ثانية</span>
        <button
          type="button"
          class="p-1.5 rounded-lg hover:bg-white/5 text-text-muted"
          :disabled="loading"
          @click="reload(true)"
        >
          <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        </button>
      </div>
    </header>

    <!-- Summary KPIs -->
    <div v-if="summary" class="grid grid-cols-3 gap-3 mb-5">
      <div class="rounded-xl bg-success/5 border border-success/20 p-3 text-center">
        <div class="text-2xl font-black text-success font-mono">
          {{ summary.safe_count }}
        </div>
        <div class="text-[11px] font-bold uppercase tracking-wider text-success/80 mt-1">
          سليم
        </div>
      </div>
      <div class="rounded-xl bg-warning/5 border border-warning/20 p-3 text-center">
        <div class="text-2xl font-black text-warning font-mono">
          {{ summary.warning_count }}
        </div>
        <div class="text-[11px] font-bold uppercase tracking-wider text-warning/80 mt-1">
          تحذير
        </div>
      </div>
      <div class="rounded-xl bg-error/5 border border-error/20 p-3 text-center">
        <div class="text-2xl font-black text-error font-mono">
          {{ summary.danger_count }}
        </div>
        <div class="text-[11px] font-bold uppercase tracking-wider text-error/80 mt-1">
          خطر
        </div>
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-else-if="loading" class="grid grid-cols-3 gap-3 mb-5">
      <div v-for="i in 3" :key="i" class="rounded-xl bg-white/[0.03] border border-white/5 p-3 h-20 animate-pulse"></div>
    </div>

    <!-- Top groups table -->
    <div v-if="summary && summary.top_groups && summary.top_groups.length > 0" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs font-bold uppercase tracking-wider text-text-muted border-b border-white/10">
            <th class="text-right py-2 px-2">المجموعة</th>
            <th class="text-right py-2 px-2">المستوى</th>
            <th class="text-left py-2 px-2">المتاح</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="g in summary.top_groups"
            :key="g.id"
            class="border-b border-white/5 hover:bg-white/[0.03] transition-colors"
          >
            <td class="py-3 px-2">
              <button
                type="button"
                class="text-right font-bold text-white hover:text-gold transition-colors flex items-center gap-2"
                @click="openModal(g)"
              >
                <Bell class="w-3.5 h-3.5 text-text-muted" />
                {{ g.name }}
                <span class="text-[10px] text-text-muted font-mono">({{ g.code }})</span>
              </button>
            </td>
            <td class="py-3 px-2">
              <span
                class="text-xs font-bold px-2.5 py-1 rounded-full"
                :class="levelClass(g.level)"
              >
                {{ levelLabel(g.level) }}
              </span>
            </td>
            <td class="py-3 px-2 text-left">
              <div class="font-mono text-sm font-bold" :class="levelTextColor(g.level)">
                {{ formatMoney(g.available, g.currency) }}
              </div>
              <div class="text-[10px] text-text-muted">
                عتبة {{ formatMoney(g.threshold, g.currency) }}
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div
      v-else-if="summary && (!summary.top_groups || summary.top_groups.length === 0)"
      class="text-center py-8 text-text-muted"
    >
      <CheckCircle2 class="w-10 h-10 mx-auto text-success/60 mb-2" />
      <p class="text-sm font-bold">كل المجموعات في وضع آمن</p>
      <p class="text-xs mt-1">لم تتجاوز أي مجموعة عتبة الإشعار</p>
    </div>

    <!-- Notification settings modal -->
    <GroupNotificationsModal
      :open="modalOpen"
      :group="modalGroup"
      @close="modalOpen = false"
      @saved="onSaved"
    />
  </section>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import {
  BellRing, Bell, RefreshCw, CheckCircle2,
} from 'lucide-vue-next';
import { useFlightStore } from '@/stores/flightStore';
import GroupNotificationsModal from './GroupNotificationsModal.vue';

const flightStore = useFlightStore();

const summary = computed(() => flightStore.groupThresholdSummary);
const loading = computed(() => flightStore.loading.groupThresholdSummary);

const modalOpen = ref(false);
const modalGroup = ref(null);

let pollHandle = null;

function reload(force = false) {
  flightStore.fetchGroupThresholdSummary(force);
}

function openModal(g) {
  // Fetch full group record so modal has thresholds + channels
  flightStore.fetchGroup(g.id).then((fresh) => {
    if (fresh) {
      modalGroup.value = fresh;
      modalOpen.value = true;
    }
  });
}

function onSaved() {
  reload(true);
}

function levelLabel(level) {
  return { info: 'معلومة', warning: 'تحذير', danger: 'خطر' }[level] || level;
}

function levelClass(level) {
  return {
    info: 'bg-info/10 text-info border border-info/20',
    warning: 'bg-warning/10 text-warning border border-warning/20',
    danger: 'bg-error/10 text-error border border-error/20',
  }[level] || 'bg-white/10 text-text-muted';
}

function levelTextColor(level) {
  return {
    info: 'text-info',
    warning: 'text-warning',
    danger: 'text-error',
  }[level] || 'text-white';
}

function formatMoney(amount, currency = 'EGP') {
  const n = Number(amount || 0);
  return `${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

onMounted(() => {
  reload();
  pollHandle = setInterval(() => reload(true), 30000);
});

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle);
});
</script>