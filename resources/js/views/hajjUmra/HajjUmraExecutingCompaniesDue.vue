<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-16">
    <!-- Header -->
    <header class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-black text-white tracking-tight">
          الشركات المنفذة (الحج والعمرة)
        </h1>
        <p class="text-white/50 mt-1">
          إدارة مديونيات الشركات المنفذة، عمليات السحب والسداد، وكشوف الحسابات.
        </p>
      </div>
    </header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
      <div class="p-6 bg-white/[0.02] border border-white/10 rounded-2xl relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-blue-500/5 rounded-full blur-2xl group-hover:bg-blue-500/10 transition"></div>
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-blue-500/10 rounded-xl">
            <Building2 class="w-5 h-5 text-blue-400" />
          </div>
          <span class="text-xs font-bold uppercase tracking-widest text-white/40">إجمالي الشركات</span>
        </div>
        <p class="text-3xl font-black font-mono text-white tabular-nums">
          {{ store.executingCompaniesFinance.length }}
        </p>
      </div>

      <div class="p-6 bg-white/[0.02] border border-white/10 rounded-2xl relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/5 rounded-full blur-2xl group-hover:bg-red-500/10 transition"></div>
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-red-500/10 rounded-xl">
            <AlertTriangle class="w-5 h-5 text-red-400" />
          </div>
          <span class="text-xs font-bold uppercase tracking-widest text-white/40">إجمالي المستحقات</span>
        </div>
        <p class="text-3xl font-black font-mono text-red-400 tabular-nums">
          {{ Number(totalNetDue).toLocaleString('ar-EG') }}
        </p>
        <p class="text-[10px] text-white/20 mt-1 uppercase tracking-widest">جنيه مصري</p>
      </div>

      <div class="p-6 bg-white/[0.02] border border-white/10 rounded-2xl relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/5 rounded-full blur-2xl group-hover:bg-emerald-500/10 transition"></div>
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-emerald-500/10 rounded-xl">
            <CheckCircle class="w-5 h-5 text-emerald-400" />
          </div>
          <span class="text-xs font-bold uppercase tracking-widest text-white/40">شركات نشطة</span>
        </div>
        <p class="text-3xl font-black font-mono text-emerald-400 tabular-nums">
          {{ store.executingCompaniesFinance.length }}
        </p>
      </div>
    </div>

    <!-- Companies Table -->
    <div class="bg-white/[0.02] border border-white/10 rounded-2xl overflow-hidden shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="bg-black/20 text-[11px] text-white/40 uppercase tracking-[0.2em] border-b border-white/5">
              <th class="px-6 py-5 font-bold">الشركة</th>
              <th class="px-6 py-5 font-bold">التواصل</th>
              <th class="px-6 py-5 font-bold">إجمالي المسحوب</th>
              <th class="px-6 py-5 font-bold">إجمالي المسدد</th>
              <th class="px-6 py-5 font-bold">الصافي (الدين)</th>
              <th class="px-6 py-5 font-bold text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <template v-if="store.loading.list">
              <tr v-for="i in 6" :key="i">
                <td v-for="j in 6" :key="j" class="px-6 py-6">
                  <div class="h-4 animate-pulse rounded bg-white/5 w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="store.executingCompaniesFinance.length > 0">
              <tr
                v-for="company in store.executingCompaniesFinance"
                :key="company.id"
                class="hover:bg-white/[0.03] transition-colors group"
              >
                <td class="px-6 py-5">
                  <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                      <Building2 class="w-5 h-5" />
                    </div>
                    <div>
                      <p class="font-bold text-white text-sm">{{ company.name }}</p>
                      <p class="text-[10px] font-mono text-white/30 uppercase tracking-widest mt-0.5">ID: #{{ company.id }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-5">
                  <div class="flex items-center gap-2 text-xs text-white/60">
                    <Phone class="w-3 h-3 text-white/20" />
                    <span>{{ company.phone || '—' }}</span>
                  </div>
                </td>
                <td class="px-6 py-5 font-mono text-sm text-white/60">
                  {{ Number(company.total_withdrawn).toLocaleString('ar-EG') }}
                </td>
                <td class="px-6 py-5 font-mono text-sm text-white/60">
                  {{ Number(company.total_repaid).toLocaleString('ar-EG') }}
                </td>
                <td class="px-6 py-5">
                  <div class="flex flex-col">
                    <span
                      :class="[
                        'font-mono font-black text-sm tabular-nums',
                        company.net_due < 0 ? 'text-emerald-400' : (company.net_due > 0 ? 'text-red-400' : 'text-white/40')
                      ]"
                    >
                      {{ Number(Math.abs(company.net_due)).toLocaleString('ar-EG') }} ج.م
                    </span>
                    <p v-if="company.net_due !== 0" class="text-[10px] font-bold uppercase tracking-tighter mt-0.5" :class="company.net_due > 0 ? 'text-red-400/50' : 'text-emerald-400/50'">
                      {{ company.net_due > 0 ? 'مستحق علينا' : 'رصيد دائن' }}
                    </p>
                  </div>
                </td>
                <td class="px-6 py-5 text-left">
                  <div class="flex items-center justify-end gap-2">
                    <button
                      @click="openFinanceModal(company, 'repay')"
                      class="px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500 hover:text-black text-[10px] font-black transition-all"
                    >
                      سداد
                    </button>
                    <button
                      @click="openFinanceModal(company, 'withdraw')"
                      class="px-3 py-1.5 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 hover:bg-blue-500 hover:text-white text-[10px] font-black transition-all"
                    >
                      سحب
                    </button>
                    <router-link
                      :to="{ name: 'finance.accounts.statement.detail', params: { id: company.account_id } }"
                      class="p-2 rounded-lg bg-white/5 border border-white/5 text-white/40 hover:text-blue-400 transition-all"
                      title="كشف الحساب"
                    >
                      <FileText class="w-4 h-4" />
                    </router-link>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="6" class="px-6 py-24 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center border border-white/5">
                    <Building2 class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-white">لا توجد شركات منفذة</h3>
                    <p class="text-white/30 text-sm mt-1">لم يتم تسجيل أي شركات منفذة نشطة حالياً.</p>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Finance Modal (Settle / Repay) -->
    <Teleport to="body">
      <div v-if="modal.show" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-[#0b1220] border border-white/10 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
          <div :class="['px-6 py-5 border-b border-white/5 flex items-center justify-between', modal.type === 'repay' ? 'bg-emerald-500/5' : 'bg-blue-500/5']">
            <h3 :class="['text-lg font-black flex items-center gap-2', modal.type === 'repay' ? 'text-emerald-400' : 'text-blue-400']">
              <Wallet class="w-5 h-5" />
              {{ modal.type === 'repay' ? 'سداد مستحقات للشركة' : 'سحب مبلغ من الشركة' }}
            </h3>
            <button @click="closeModal" class="text-white/20 hover:text-white">✕</button>
          </div>

          <form @submit.prevent="submitFinanceAction" class="p-6 space-y-5">
            <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30">الشركة</p>
                <p class="text-sm font-bold text-white">{{ modal.company?.name }}</p>
              </div>
              <div class="text-left">
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30">الصافي الحالي</p>
                <p :class="['text-sm font-black tabular-nums', modal.company?.net_due > 0 ? 'text-red-400' : 'text-emerald-400']">
                   {{ Number(Math.abs(modal.company?.net_due || 0)).toLocaleString('ar-EG') }} ج.م
                </p>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">
                {{ modal.type === 'repay' ? 'الدفع من حساب *' : 'الإيداع في حساب *' }}
              </label>
              <select v-model="form.account_id" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white outline-none focus:border-blue-500/50 transition">
                <option value="">-- اختر الحساب --</option>
                <option v-for="acc in hajjAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} ({{ Number(acc.balance).toLocaleString('ar-EG') }} ج.م)
                </option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">المبلغ *</label>
              <div class="relative">
                <input v-model.number="form.amount" type="number" step="0.01" required min="0.01" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white font-mono outline-none focus:border-blue-500/50 transition" />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-white/20 text-xs">ج.م</span>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">ملاحظات</label>
              <input v-model="form.notes" type="text" placeholder="ملاحظات إضافية على العملية..." class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white text-xs outline-none focus:border-blue-500/50 transition" />
            </div>

            <div class="flex gap-3 pt-4">
              <button
                type="submit"
                :disabled="loading"
                :class="['flex-1 font-black py-3 rounded-xl transition disabled:opacity-40 shadow-lg', modal.type === 'repay' ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-900/20' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20']"
              >
                {{ loading ? 'جاري التنفيذ...' : 'تأكيد العملية' }}
              </button>
              <button type="button" @click="closeModal" class="px-6 py-3 border border-white/10 text-white/60 font-bold rounded-xl hover:bg-white/5 transition">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import {
  Building2,
  AlertTriangle,
  CheckCircle,
  Phone,
  FileText,
  Wallet,
} from 'lucide-vue-next';
import axios from 'axios';
import { fetchSettlementAccounts } from '@/composables/useTreasuryAccountGroups';

const store = useHajjUmraStore();

const modal = ref({ show: false, type: 'repay', company: null });
const form = ref({ amount: 0, account_id: '', notes: '' });
const loading = ref(false);
const accounts = ref([]);

const hajjAccounts = computed(() => {
  return accounts.value.filter(a => a.module_type === 'hajj_umra');
});

const totalNetDue = computed(() => {
  return store.executingCompaniesFinance.reduce((sum, c) => {
    const netDue = Number(c.net_due) || 0;
    return sum + (netDue > 0 ? netDue : 0);
  }, 0);
});

const loadData = async () => {
  try {
    await store.fetchExecutingCompaniesDues();
    accounts.value = await fetchSettlementAccounts(axios, { module: 'hajj_umra' });
  } catch (e) {
    console.error('Failed to load executing companies dues', e);
  }
};

const openFinanceModal = (company, type) => {
  modal.value = { show: true, type, company };
  form.value = {
    amount: Math.abs(company.net_due),
    account_id: '',
    notes: '',
  };
};

const closeModal = () => {
  modal.value = { show: false, type: 'repay', company: null };
  form.value = { amount: 0, account_id: '', notes: '' };
};

const submitFinanceAction = async () => {
  if (!form.value.account_id) {
    store.addToast('يرجى اختيار الحساب', 'error');
    return;
  }
  loading.value = true;
  try {
    if (modal.value.type === 'repay') {
      await store.recordExecutingCompanyRepay(modal.value.company.id, {
        amount: form.value.amount,
        from_account_id: form.value.account_id,
        notes: form.value.notes
      });
    } else {
      await store.recordExecutingCompanyWithdraw(modal.value.company.id, {
        amount: form.value.amount,
        to_account_id: form.value.account_id,
        notes: form.value.notes
      });
    }
    closeModal();
    await store.fetchExecutingCompaniesDues();
  } catch (e) {
    // Error handled by store toast
  } finally {
    loading.value = false;
  }
};

onMounted(loadData);
</script>
