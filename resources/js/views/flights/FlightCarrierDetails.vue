<template>
  <div class="flight-carrier-details max-w-6xl mx-auto space-y-6 pb-16 animate-in fade-in duration-500">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-4 min-w-0">
        <router-link
          :to="{ name: 'flights.carriers-debt' }"
          class="btn-airline-ghost rounded-xl p-2.5 shrink-0"
          aria-label="العودة لقائمة الناقلين"
        >
          <ArrowRight class="h-5 w-5 text-sky-300" />
        </router-link>
        <div class="min-w-0">
          <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-sky-400/90">
            تفاصيل الناقل
          </p>
          <h1 class="mt-1 text-2xl font-black tracking-tight text-text-main sm:text-3xl truncate">
            {{ carrier?.name || '...' }}
          </h1>
          <p v-if="carrier" class="mt-1 text-xs text-text-muted font-mono">
            {{ carrier.code }} · {{ carrier.currency }} · حد ائتمان: {{ formatMoney(carrier.credit_limit) }}
          </p>
        </div>
      </div>
      <button
        v-if="carrier"
        @click="openRechargeModal = true"
        class="px-5 py-2.5 bg-gold text-black rounded-xl font-bold hover:bg-gold/90 transition-all flex items-center gap-2"
      >
        <Zap class="w-4 h-4" />
        شحن الرصيد
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-20">
      <div class="animate-spin w-12 h-12 border-4 border-gold border-t-transparent rounded-full mx-auto" />
      <p class="text-text-muted mt-4 text-sm">جاري تحميل تفاصيل الناقل...</p>
    </div>

    <template v-else-if="carrier">
      <!-- KPI cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="الرصيد الحالي"
          :value="Number(carrier.balance || 0)"
          format="number"
          icon="CreditCard"
          trend-color="gold"
        />
        <StatCard
          label="الرصيد المتاح"
          :value="Number(carrier.available_balance ?? 0)"
          format="number"
          icon="DollarSign"
          trend-color="success"
        />
        <StatCard
          label="حد الائتمان"
          :value="Number(carrier.credit_limit || 0)"
          format="number"
          icon="TrendingUp"
          trend-color="warning"
        />
        <StatCard
          label="عدد المجموعات"
          :value="(carrier.groups || []).length"
          format="number"
          icon="Users"
          trend-color="blue"
        />
      </div>

      <!-- Info + Recent transactions -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Carrier info -->
        <section class="bg-card border border-white/10 rounded-2xl p-6">
          <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
            <Info class="w-5 h-5 text-sky-400" />
            بيانات الناقل
          </h2>
          <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="text-text-muted">الاسم</dt>
              <dd class="font-bold text-left">{{ carrier.name }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-text-muted">الكود</dt>
              <dd class="font-mono">{{ carrier.code }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-text-muted">كود IATA</dt>
              <dd class="font-mono">{{ carrier.iata_code || '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-text-muted">العملة</dt>
              <dd>{{ carrier.currency }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-text-muted">الحالة</dt>
              <dd>
                <span
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold"
                  :class="carrier.is_active ? 'bg-success/20 text-success' : 'bg-error/20 text-error'"
                >
                  {{ carrier.is_active ? 'نشط' : 'غير نشط' }}
                </span>
              </dd>
            </div>
            <div v-if="carrier.system" class="flex justify-between gap-2 pt-2 border-t border-white/10">
              <dt class="text-text-muted">النظام</dt>
              <dd>{{ carrier.system.name }} <span class="text-xs text-text-muted">({{ carrier.system.code }})</span></dd>
            </div>
          </dl>
        </section>

        <!-- Recent transactions -->
        <section class="bg-card border border-white/10 rounded-2xl p-6 lg:col-span-2">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold flex items-center gap-2">
              <Activity class="w-5 h-5 text-gold" />
              آخر الحركات
            </h2>
            <router-link
              :to="{ name: 'flights.carriers-debt' }"
              class="text-xs text-sky-400 hover:text-sky-300 transition-colors"
            >
              عرض كل الديون ←
            </router-link>
          </div>
          <div v-if="!carrier.transactions || carrier.transactions.length === 0" class="text-center py-8 text-text-muted text-sm">
            لا توجد حركات مسجلة بعد.
          </div>
          <ol v-else class="space-y-2">
            <li
              v-for="tx in carrier.transactions"
              :key="tx.id"
              class="flex items-center justify-between gap-3 p-3 bg-white/5 rounded-lg hover:bg-white/10 transition-colors"
            >
              <div class="flex items-center gap-3 min-w-0">
                <span
                  class="inline-flex items-center justify-center w-8 h-8 rounded-lg shrink-0"
                  :class="tx.type === 'credit' ? 'bg-success/20 text-success' : 'bg-warning/20 text-warning'"
                >
                  <component :is="tx.type === 'credit' ? ArrowDownLeft : ArrowUpRight" class="w-4 h-4" />
                </span>
                <div class="min-w-0">
                  <div class="font-bold text-sm">
                    {{ tx.type === 'credit' ? 'شحن رصيد' : 'استهلاك رصيد' }}
                  </div>
                  <div class="text-xs text-text-muted truncate">
                    {{ tx.description || '—' }}
                  </div>
                </div>
              </div>
              <div class="text-right shrink-0">
                <div class="font-mono font-bold" :class="tx.type === 'credit' ? 'text-success' : 'text-warning'">
                  {{ formatMoney(tx.amount) }} {{ carrier.currency }}
                </div>
                <div class="text-[10px] text-text-muted">{{ formatDate(tx.created_at) }}</div>
              </div>
            </li>
          </ol>
        </section>
      </div>

      <!-- Groups under this carrier -->
      <section class="bg-card border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
          <Layers class="w-5 h-5 text-text-muted" />
          مجموعات الناقل ({{ (carrier.groups || []).length }})
        </h2>
        <div v-if="!carrier.groups || carrier.groups.length === 0" class="text-center py-8 text-text-muted text-sm">
          لا توجد مجموعات مسجلة على هذا الناقل.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-text-muted text-xs uppercase border-b border-white/10">
                <th class="text-right p-3">الاسم</th>
                <th class="text-right p-3">الكود</th>
                <th class="text-right p-3">نسبة العمولة</th>
                <th class="text-right p-3">الحالة</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="g in carrier.groups" :key="g.id" class="border-b border-white/5 hover:bg-white/5 transition-colors">
                <td class="p-3 font-bold">{{ g.name }}</td>
                <td class="p-3 font-mono">{{ g.code }}</td>
                <td class="p-3 font-mono">{{ g.commission_rate }}%</td>
                <td class="p-3">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold"
                    :class="g.is_active ? 'bg-success/20 text-success' : 'bg-white/10 text-text-muted'"
                  >
                    {{ g.is_active ? 'نشط' : 'غير نشط' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <!-- Not found -->
    <div v-else class="text-center py-20">
      <div class="text-6xl mb-4">⚠️</div>
      <h2 class="text-xl font-bold text-text-main mb-2">تعذّر العثور على الناقل</h2>
      <p class="text-text-muted mb-6">قد يكون الناقل محذوفاً أو أن الرابط غير صحيح.</p>
      <router-link
        :to="{ name: 'flights.carriers-debt' }"
        class="inline-block px-5 py-2.5 bg-sky-500/20 hover:bg-sky-500/30 border border-sky-500/30 rounded-xl text-sm font-bold transition-all"
      >
        ← العودة لقائمة الناقلين
      </router-link>
    </div>

    <!-- Recharge modal -->
    <RechargeCarrierModal
      v-if="openRechargeModal && carrier"
      :carrier="carrier"
      @close="openRechargeModal = false"
      @done="onRechargeDone"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
  ArrowRight,
  ArrowDownLeft,
  ArrowUpRight,
  Activity,
  Info,
  Layers,
  Zap,
} from 'lucide-vue-next';
import StatCard from '@/components/dashboard/StatCard.vue';
import RechargeCarrierModal from '@/components/flights/RechargeCarrierModal.vue';
import api from '@/utils/api';

const props = defineProps({ id: { type: [String, Number], default: null } });

const route = useRoute();

const carrierId = computed(() => props.id ?? route.params.id);
const carrier = ref(null);
const loading = ref(true);
const openRechargeModal = ref(false);

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(s) {
  if (!s) return '—';
  try {
    return new Date(s).toLocaleString('ar-EG', {
      year: 'numeric', month: 'short', day: '2-digit',
      hour: '2-digit', minute: '2-digit',
    });
  } catch {
    return s;
  }
}

async function loadCarrier() {
  loading.value = true;
  try {
    const res = await api.get(`/api/v1/flight/carriers/${carrierId.value}`);
    carrier.value = res.data?.data ?? null;
  } catch (e) {
    console.error('Failed to load carrier', e);
    carrier.value = null;
  } finally {
    loading.value = false;
  }
}

async function onRechargeDone() {
  openRechargeModal.value = false;
  await loadCarrier();
}

onMounted(loadCarrier);
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.bg-success { background-color: var(--success); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
.text-warning { color: var(--warning); }
.bg-warning { background-color: var(--warning); }
.btn-airline-ghost { background-color: rgba(255,255,255,0.04); }
.animate-in { animation: fadeIn 0.5s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
