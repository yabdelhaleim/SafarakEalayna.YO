<template>
  <div class="finance-dashboard visa-booking animate-in pb-10 fade-in duration-700">
    <header class="visa-hero relative overflow-hidden bg-gradient-to-br from-[#0a1628] via-[#0d1f3c] to-[#111827] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="relative z-10 mx-auto flex max-w-7xl flex-col gap-4 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/20 border border-indigo-500/30">
              <Building2 class="h-4 w-4 text-indigo-400" />
            </div>
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-indigo-400/90">مالية الوكلاء</p>
          </div>
          <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
            مديونيات وكلاء التأشيرات
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/50">
            إدارة ديون الوكلاء، تسجيل عمليات السداد (عند الدفع للوكيل) أو السحب (عند استرداد مبالغ).
          </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
          <router-link
            :to="{ name: 'visa.treasury' }"
            class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-bold text-white/70 transition hover:border-indigo-500/40 hover:text-white"
          >
            <ArrowRight class="h-4 w-4 rotate-180" />
            الخزينة
          </router-link>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-indigo-500/30 bg-indigo-500/15 px-4 py-2 text-sm font-bold text-indigo-200 transition hover:bg-indigo-500/25"
            @click="reload"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
            تحديث
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8 mt-8">
      <div v-if="loading && !items.length" class="rounded-2xl border border-white/10 bg-white/[0.03] py-24 text-center text-white/40">
        جاري تحميل بيانات المديونيات…
      </div>

      <template v-else>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
          <div
            v-for="item in items"
            :key="item.id"
            class="group relative overflow-hidden rounded-2xl border border-white/10 bg-[#0d1525] p-6 transition-all hover:border-indigo-500/30 hover:shadow-2xl hover:shadow-indigo-500/5"
          >
            <div class="mb-4 flex items-start justify-between">
              <div>
                <h3 class="text-lg font-bold text-white group-hover:text-indigo-400 transition-colors">{{ item.name }}</h3>
                <p class="text-xs text-white/40">{{ item.country || '—' }} · {{ item.phone || '—' }}</p>
              </div>
              <div :class="[item.net_due > 0 ? 'bg-red-500/20 text-red-400' : 'bg-emerald-500/20 text-emerald-400', 'rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider']">
                {{ item.net_due > 0 ? 'مديونية' : 'رصيد' }}
              </div>
            </div>

            <div class="mb-6 grid grid-cols-2 gap-4 rounded-xl bg-white/[0.02] p-4 border border-white/5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30 mb-1">المدفوع للوكيل</p>
                <p class="font-mono text-sm font-bold text-white">{{ Number(item.total_repaid).toLocaleString() }}</p>
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30 mb-1">المسحوب منه</p>
                <p class="font-mono text-sm font-bold text-white">{{ Number(item.total_withdrawn).toLocaleString() }}</p>
              </div>
              <div class="col-span-2 pt-2 border-t border-white/5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-400/60 mb-1">صافي المديونية</p>
                <p class="font-mono text-2xl font-black text-white">{{ Number(Math.abs(item.net_due)).toLocaleString() }} <span class="text-xs font-normal text-white/40">ج.م</span></p>
              </div>
            </div>

            <div class="flex gap-2">
              <button
                @click="openActionModal('repay', item)"
                class="flex-1 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black text-white transition hover:bg-emerald-500 active:scale-95"
              >
                سداد دين
              </button>
              <button
                @click="openActionModal('withdraw', item)"
                class="flex-1 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-xs font-black text-white/70 transition hover:bg-white/10 active:scale-95"
              >
                سحب مبلغ
              </button>
            </div>
          </div>
        </div>

        <div v-if="!loading && !items.length" class="rounded-2xl border border-white/5 bg-white/[0.02] py-24 text-center">
          <Building2 class="mx-auto mb-4 h-12 w-12 text-white/10" />
          <p class="text-white/40 font-bold">لا يوجد وكلاء تأشيرات متاحين حالياً.</p>
        </div>
      </template>
    </div>

    <!-- Action Modal -->
    <Teleport to="body">
      <div
        v-if="actionModal.show"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-md"
        @click.self="closeActionModal"
      >
        <div class="w-full max-w-md overflow-hidden rounded-3xl border border-white/10 bg-[#0a111e] shadow-2xl animate-in zoom-in-95 duration-200">
          <div class="border-b border-white/5 px-8 py-6 flex items-center justify-between">
            <div>
              <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-400">{{ actionModal.type === 'repay' ? 'سداد مديونية' : 'سحب مبلغ' }}</p>
              <h3 class="text-xl font-black text-white">{{ actionModal.agent?.name }}</h3>
            </div>
            <button @click="closeActionModal" class="text-white/20 hover:text-white transition">✕</button>
          </div>

          <form @submit.prevent="submitAction" class="p-8 space-y-6">
            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">المبلغ</label>
              <div class="relative">
                <input
                  v-model.number="form.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-4 font-mono text-xl font-black text-white outline-none focus:border-indigo-500/50"
                  placeholder="0.00"
                />
                <span class="absolute left-5 top-1/2 -translate-y-1/2 text-xs font-bold text-white/20">ج.م</span>
              </div>
            </div>

            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">{{ actionModal.type === 'repay' ? 'من حساب' : 'إلى حساب' }}</label>
              <select
                v-model="form.account_id"
                required
                class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-white outline-none focus:border-indigo-500/50"
              >
                <option value="" disabled>اختر الحساب…</option>
                <option v-for="acc in settlementAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} ({{ Number(acc.balance).toLocaleString() }} {{ acc.currency }})
                </option>
              </select>
              <p v-if="!settlementAccounts.length" class="text-[10px] text-amber-400 font-bold">لا توجد حسابات تسوية نشطة لقسم التأشيرات.</p>
            </div>

            <div class="space-y-2">
              <label class="text-xs font-bold text-white/40">ملاحظات</label>
              <textarea
                v-model="form.notes"
                rows="2"
                class="w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-white outline-none focus:border-indigo-500/50"
                placeholder="تفاصيل العملية…"
              ></textarea>
            </div>

            <div class="flex gap-3 pt-4">
              <button
                type="submit"
                :disabled="submitting || !form.account_id"
                class="flex-1 rounded-2xl bg-indigo-600 py-4 text-sm font-black text-white shadow-xl shadow-indigo-600/20 transition-all hover:bg-indigo-500 disabled:opacity-30 active:scale-95"
              >
                <span v-if="submitting">جاري التنفيذ…</span>
                <span v-else>تأكيد العملية</span>
              </button>
              <button
                type="button"
                @click="closeActionModal"
                class="flex-1 rounded-2xl border border-white/10 bg-white/5 py-4 text-sm font-bold text-white/60 transition hover:bg-white/10"
              >
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useVisaStore } from '@/stores/visaStore';
import { ArrowRight, RefreshCw, Building2, CreditCard } from 'lucide-vue-next';

const store = useVisaStore();
const loading = ref(false);
const submitting = ref(false);

const items = computed(() => store.agentsFinance || []);
const settlementAccounts = computed(() => (store.treasuryOverview?.settlement_accounts || []));

const reload = async () => {
  loading.value = true;
  try {
    await Promise.all([
      store.fetchVisaAgentsDues(),
      store.fetchVisaTreasuryOverview()
    ]);
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  reload();
});

const actionModal = ref({ show: false, type: 'repay', agent: null });
const form = ref({ amount: 0, account_id: '', notes: '' });

const openActionModal = (type, agent) => {
  actionModal.value = { show: true, type, agent };
  form.value = { amount: 0, account_id: '', notes: '' };
};

const closeActionModal = () => {
  actionModal.value = { show: false, type: 'repay', agent: null };
};

const submitAction = async () => {
  if (submitting.value) return;
  submitting.value = true;
  try {
    const payload = {
      amount: form.value.amount,
      notes: form.value.notes,
    };

    if (actionModal.value.type === 'repay') {
      payload.from_account_id = form.value.account_id;
      await store.recordVisaAgentRepay(actionModal.value.agent.id, payload);
    } else {
      payload.to_account_id = form.value.account_id;
      await store.recordVisaAgentWithdraw(actionModal.value.agent.id, payload);
    }
    
    closeActionModal();
    await reload();
  } finally {
    submitting.value = false;
  }
};
</script>

<style scoped>
.visa-hero {
  background-image: radial-gradient(circle at 10% 20%, rgba(79, 70, 229, 0.05) 0%, transparent 50%);
}
</style>
