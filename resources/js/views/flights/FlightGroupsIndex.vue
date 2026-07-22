<template>
  <div class="space-y-6">
    <!-- Header -->
    <header class="flex items-center justify-between flex-wrap gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center gap-3">
          <Layers class="w-6 h-6 text-gold" />
          مجموعات الطيران
        </h1>
        <p class="text-sm text-text-muted mt-1">
          إدارة المجموعات وعتبات الإشعارات
        </p>
      </div>
      <button
        type="button"
        class="px-4 py-2 rounded-xl bg-white/5 border border-white/10 text-sm font-bold hover:bg-white/10 flex items-center gap-2"
        :disabled="loading"
        @click="reload(true)"
      >
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        تحديث
      </button>
    </header>

    <!-- KPI summary -->
    <section v-if="summary" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="rounded-2xl bg-card-bg border border-white/10 p-4">
        <div class="text-xs font-bold uppercase tracking-wider text-text-muted">إجمالي</div>
        <div class="text-2xl font-black text-white font-mono mt-1">{{ summary.total_groups }}</div>
      </div>
      <div class="rounded-2xl bg-success/5 border border-success/20 p-4">
        <div class="text-xs font-bold uppercase tracking-wider text-success">سليم</div>
        <div class="text-2xl font-black text-success font-mono mt-1">{{ summary.safe_count }}</div>
      </div>
      <div class="rounded-2xl bg-warning/5 border border-warning/20 p-4">
        <div class="text-xs font-bold uppercase tracking-wider text-warning">تحذير</div>
        <div class="text-2xl font-black text-warning font-mono mt-1">{{ summary.warning_count }}</div>
      </div>
      <div class="rounded-2xl bg-error/5 border border-error/20 p-4">
        <div class="text-xs font-bold uppercase tracking-wider text-error">خطر</div>
        <div class="text-2xl font-black text-error font-mono mt-1">{{ summary.danger_count }}</div>
      </div>
    </section>

    <!-- Filters -->
    <section class="flex items-center gap-3 flex-wrap">
      <div class="relative flex-1 min-w-[220px]">
        <Search class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none" />
        <input
          v-model="filters.search"
          type="text"
          placeholder="ابحث بالاسم أو الكود..."
          class="w-full bg-input border border-white/10 rounded-xl px-10 py-2.5 text-sm text-white placeholder-text-muted outline-none focus:border-gold"
        />
      </div>
      <select
        v-model="filters.level"
        class="bg-input border border-white/10 rounded-xl px-3 py-2.5 text-sm text-white outline-none focus:border-gold"
      >
        <option value="">كل المستويات</option>
        <option value="safe">سليم</option>
        <option value="warning">تحذير</option>
        <option value="danger">خطر</option>
      </select>
    </section>

    <!-- Table -->
    <section class="rounded-3xl bg-card-bg border border-white/10 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-white/[0.02] border-b border-white/10">
            <tr class="text-xs font-bold uppercase tracking-wider text-text-muted">
              <th class="text-right py-3 px-4">المجموعة</th>
              <th class="text-right py-3 px-4">الناقل</th>
              <th class="text-right py-3 px-4">الرصيد</th>
              <th class="text-right py-3 px-4">حد الائتمان</th>
              <th class="text-right py-3 px-4">المتاح</th>
              <th class="text-right py-3 px-4">المستوى</th>
              <th class="text-right py-3 px-4">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && filtered.length === 0">
              <td colspan="7" class="text-center py-12 text-text-muted">
                <Loader2 class="w-6 h-6 mx-auto animate-spin mb-2" />
                جاري التحميل...
              </td>
            </tr>
            <tr v-else-if="filtered.length === 0">
              <td colspan="7" class="text-center py-12 text-text-muted">
                <Inbox class="w-8 h-8 mx-auto mb-2 opacity-50" />
                لا توجد مجموعات مطابقة
              </td>
            </tr>
            <tr
              v-for="g in filtered"
              :key="g.id"
              class="border-b border-white/5 hover:bg-white/[0.03] transition-colors"
            >
              <td class="py-3 px-4">
                <div class="font-bold text-white">{{ g.name }}</div>
                <div class="text-xs text-text-muted font-mono">{{ g.code }}</div>
              </td>
              <td class="py-3 px-4 text-text-muted">
                {{ g.carrier?.name || '—' }}
                <div v-if="g.carrier?.currency" class="text-[10px] font-mono">{{ g.carrier.currency }}</div>
              </td>
              <td class="py-3 px-4 font-mono">
                <span :class="balanceClass(g.balance)">
                  {{ formatMoney(g.balance, g.carrier?.currency) }}
                </span>
              </td>
              <td class="py-3 px-4 font-mono text-text-muted">
                {{ formatMoney(g.credit_limit, g.carrier?.currency) }}
              </td>
              <td class="py-3 px-4 font-mono font-bold">
                {{ formatMoney(available(g), g.carrier?.currency) }}
              </td>
              <td class="py-3 px-4">
                <span
                  class="text-xs font-bold px-2.5 py-1 rounded-full"
                  :class="levelClass(g)"
                >
                  {{ levelLabel(g) }}
                </span>
              </td>
              <td class="py-3 px-4">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gold/10 text-gold border border-gold/20 text-xs font-bold hover:bg-gold/20 transition-colors"
                  @click="openNotificationsModal(g)"
                >
                  <Bell class="w-3.5 h-3.5" />
                  إعدادات الإشعارات
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Modal -->
    <GroupNotificationsModal
      :open="modalOpen"
      :group="modalGroup"
      @close="modalOpen = false"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  Layers, Bell, RefreshCw, Search, Loader2, Inbox,
} from 'lucide-vue-next';
import { useFlightStore } from '@/stores/flightStore';
import GroupNotificationsModal from '@/components/flights/GroupNotificationsModal.vue';

const flightStore = useFlightStore();

const loading = computed(() => flightStore.loading.groups);
const groups = computed(() => flightStore.groups || []);
const summary = computed(() => flightStore.groupThresholdSummary);

const filters = ref({
  search: '',
  level: '',
});

const modalOpen = ref(false);
const modalGroup = ref(null);

// Compute "available" locally so the table works even before summary loads.
function available(g) {
  const balance = Number(g.balance ?? g.account?.balance ?? 0);
  const limit = Number(g.credit_limit ?? 0);
  return balance + limit;
}

function levelOf(g) {
  // If summary already computed it, prefer that
  const info = Number(g.notification_threshold_info ?? 0);
  const warn = Number(g.notification_threshold_warning ?? 0);
  const dang = Number(g.notification_threshold_danger ?? 0);
  if (dang > 0 && available(g) <= dang) return 'danger';
  if (warn > 0 && available(g) <= warn) return 'warning';
  if (info > 0 && available(g) <= info) return 'info';
  return null;
}

function levelLabel(g) {
  const lvl = g.last_threshold_level || levelOf(g);
  return { info: 'معلومة', warning: 'تحذير', danger: 'خطر', null: 'سليم' }[lvl || 'null'] || lvl;
}

function levelClass(g) {
  const lvl = g.last_threshold_level || levelOf(g);
  return {
    info: 'bg-info/10 text-info border border-info/20',
    warning: 'bg-warning/10 text-warning border border-warning/20',
    danger: 'bg-error/10 text-error border border-error/20',
    null: 'bg-success/10 text-success border border-success/20',
  }[lvl || 'null'] || 'bg-white/10 text-text-muted';
}

function balanceClass(balance) {
  const b = Number(balance || 0);
  if (b > 0) return 'text-success';
  if (b < 0) return 'text-error';
  return 'text-text-muted';
}

function formatMoney(amount, currency = 'EGP') {
  const n = Number(amount || 0);
  return `${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || 'EGP'}`;
}

const filtered = computed(() => {
  let list = groups.value;
  if (filters.value.search) {
    const q = filters.value.search.toLowerCase();
    list = list.filter(g =>
      (g.name || '').toLowerCase().includes(q) ||
      (g.code || '').toLowerCase().includes(q)
    );
  }
  if (filters.value.level) {
    list = list.filter(g => {
      const lvl = g.last_threshold_level || levelOf(g);
      if (filters.value.level === 'safe') return !lvl;
      return lvl === filters.value.level;
    });
  }
  return list;
});

function openNotificationsModal(g) {
  // Fetch full group record to ensure we have notification fields
  flightStore.fetchGroup(g.id).then((fresh) => {
    modalGroup.value = fresh || g;
    modalOpen.value = true;
  });
}

function onSaved() {
  reload(true);
}

function reload(force = false) {
  flightStore.fetchGroups();
  flightStore.fetchGroupThresholdSummary(force);
}

onMounted(() => reload(true));
</script>