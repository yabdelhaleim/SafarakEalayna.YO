<template>
  <div class="space-y-8 animate-in fade-in pb-10 duration-700">
    <!-- Header Section -->
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-gold/90">النظام المالي والمحاسبي</p>
          <h1 class="font-display text-4xl font-black tracking-tight text-text-main">الخزينة العامة الموحدة</h1>
        </div>
        <div class="flex items-center gap-3">
          <button 
            @click="fetchOverview"
            class="p-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10"
            title="تحديث البيانات"
          >
            <RefreshCw class="w-5 h-5" :class="{ 'animate-spin': loading }" />
          </button>
          <button 
            @click="openTransferModal"
            class="btn-airline inline-flex items-center gap-2 px-6 py-3 text-sm font-black shadow-xl shadow-gold/10 hover:shadow-gold/20 transition-all duration-500"
          >
            <ArrowRightLeft class="w-5 h-5" />
            تحويل بين الخزن والمحافظ
          </button>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="grid grid-cols-1 gap-6 mt-10 md:grid-cols-4">
        <div class="flight-panel p-6 relative overflow-hidden group border-l-4 border-l-gold">
          <div class="absolute top-0 right-0 p-4 opacity-[0.03] group-hover:opacity-[0.08] transition-opacity duration-700">
            <Banknote class="w-24 h-24" />
          </div>
          <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي السيولة</p>
          <p class="mt-2 font-mono text-3xl font-black text-gold">{{ formatCurrency(stats.total_liquidity || totalLiquidity) }}</p>
        </div>
        <div class="flight-panel p-6 border-l-4 border-l-sky-500">
          <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي البنوك</p>
          <p class="mt-2 font-mono text-3xl font-black text-text-main">{{ formatCurrency(totalBanks) }}</p>
        </div>
        <div class="flight-panel p-6 border-l-4 border-l-purple-500">
          <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي المحافظ</p>
          <p class="mt-2 font-mono text-3xl font-black text-text-main">{{ formatCurrency(totalWallets) }}</p>
        </div>
        <div class="flight-panel p-6 border-l-4 border-l-emerald-500">
          <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي البريد</p>
          <p class="mt-2 font-mono text-3xl font-black text-text-main">{{ formatCurrency(totalPost) }}</p>
        </div>
      </div>

      <!-- Category Selection Tabs -->
      <div class="mt-12 flex justify-center">
        <div class="bg-white/5 border border-white/10 p-1.5 rounded-2xl flex items-center gap-1">
          <button 
            v-for="cat in categories" 
            :key="cat.id"
            @click="selectedCategory = cat.id"
            class="px-8 py-3 rounded-xl text-sm font-black transition-all duration-500 flex items-center gap-3"
            :class="selectedCategory === cat.id ? 'bg-gold text-black shadow-xl' : 'text-text-muted hover:text-white'"
          >
            <component :is="cat.icon" class="w-5 h-5" />
            {{ cat.label }}
          </button>
        </div>
      </div>

      <!-- Liquidity Distribution Section -->
      <div class="mt-8 flight-panel p-8">
        <div class="flex items-center justify-between mb-8">
          <div>
            <h2 class="text-xl font-black text-text-main">توزيع السيولة ({{ currentCategoryLabel }})</h2>
            <p class="text-xs text-text-muted mt-1">عرض مرئي لنسبة مساهمة موديولات القسم في إجمالي السيولة</p>
          </div>
          <PieChart class="w-6 h-6 text-gold" />
        </div>
        <div class="space-y-6">
          <div v-for="(module, key) in filteredModules" :key="'dist-'+key" class="space-y-2">
            <div class="flex items-center justify-between text-xs">
              <div class="flex items-center gap-2">
                <component :is="getModuleIcon(key)" class="w-4 h-4 text-gold/60" />
                <span class="font-bold text-text-main">{{ module.label }}</span>
              </div>
              <div class="flex items-center gap-4">
                <span class="font-mono text-text-muted">{{ formatCurrency(getModuleTotal(module.accounts)) }}</span>
                <span class="font-black text-gold">{{ getModulePercentage(module.accounts) }}%</span>
              </div>
            </div>
            <div class="h-1.5 w-full bg-white/5 rounded-full overflow-hidden">
              <div 
                class="h-full bg-gradient-to-l from-gold to-amber-500 transition-all duration-1000 ease-out"
                :style="{ width: getModulePercentage(module.accounts) + '%' }"
              ></div>
            </div>
          </div>
          <div v-if="!Object.keys(filteredModules).length" class="text-center py-4 text-text-muted italic text-sm">
            لا توجد حسابات مسجلة لهذا القسم حالياً
          </div>
        </div>
      </div>

      <!-- Modules Grid -->
      <div class="grid grid-cols-1 gap-8 mt-12 lg:grid-cols-2">
        <div 
          v-for="(module, key) in filteredModules" 
          :key="key"
          class="flight-panel !p-0 overflow-hidden border border-white/5 hover:border-gold/20 transition-all duration-500 group shadow-2xl shadow-black/20"
        >
          <!-- Module Header -->
          <div class="px-6 py-5 bg-white/[0.03] border-b border-white/5 flex items-center justify-between group-hover:bg-gold/[0.02] transition-colors">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-gold/10 flex items-center justify-center border border-gold/20 text-gold shadow-lg shadow-gold/5">
                <component :is="getModuleIcon(key)" class="w-6 h-6" />
              </div>
              <div>
                <h3 class="font-display text-lg font-black text-text-main group-hover:text-gold transition-colors">{{ module.label }}</h3>
                <p class="text-[10px] text-text-muted font-bold uppercase tracking-wider">قسم العمليات والتحصيل</p>
              </div>
            </div>
            <div class="text-left">
              <p class="text-[10px] text-text-muted font-bold uppercase tracking-wider mb-0.5">صافي الرصيد</p>
              <p class="font-mono text-lg font-black text-text-main">{{ formatCurrency(getModuleTotal(module.accounts)) }}</p>
            </div>
          </div>

          <!-- Module Accounts -->
          <div class="divide-y divide-white/5">
            <div 
              v-for="acc in module.accounts" 
              :key="acc.id"
              class="px-6 py-4 flex items-center justify-between hover:bg-white/[0.02] transition-all duration-300"
            >
              <div class="flex items-center gap-4">
                <div 
                  class="w-2 h-2 rounded-full"
                  :class="getTypeColor(acc.type)"
                ></div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="font-bold text-sm text-text-main">{{ acc.name }}</span>
                    <span v-if="acc.is_vault" class="text-[9px] px-1.5 py-0.5 rounded bg-gold/20 text-gold font-black uppercase">الرئيسية</span>
                  </div>
                  <span class="text-[10px] text-text-muted font-medium">{{ acc.type_label }}</span>
                </div>
              </div>
              <div class="flex items-center gap-6 text-left">
                <p class="font-mono text-base font-bold" :class="acc.balance >= 0 ? 'text-success' : 'text-error'">
                  {{ formatCurrency(acc.balance, acc.currency) }}
                </p>
                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                  <button 
                    @click="viewStatement(acc.id)"
                    class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-sky-400 hover:bg-white/10 transition-all"
                    title="كشف الحساب"
                  >
                    <FileText class="w-4 h-4" />
                  </button>
                  <button 
                    @click="quickTransfer(acc)"
                    class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold hover:bg-white/10 transition-all"
                    title="تحويل سريع"
                  >
                    <ArrowRightLeft class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Transfers History Section -->
      <div class="mt-16 space-y-6">
        <div class="flex items-center justify-between px-2">
          <div>
            <h2 class="text-2xl font-black text-text-main">أحدث التحويلات المالية</h2>
            <p class="text-sm text-text-muted">سجل العمليات التي تمت بين الخزن والمحافظ مؤخراً</p>
          </div>
          <History class="w-8 h-8 text-white/10" />
        </div>

        <div class="flight-panel !p-0 overflow-hidden border border-white/5 shadow-2xl">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-widest border-b border-white/5">
                <th class="px-6 py-4">التاريخ</th>
                <th class="px-6 py-4">من (المصدر)</th>
                <th class="px-6 py-4 text-center"><ArrowRight class="w-4 h-4 inline" /></th>
                <th class="px-6 py-4">إلى (المستهدف)</th>
                <th class="px-6 py-4">المبلغ</th>
                <th class="px-6 py-4">المسؤول</th>
                <th class="px-6 py-4">ملاحظات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-if="!recentTransfers.length" class="hover:bg-white/[0.01]">
                <td colspan="7" class="px-6 py-12 text-center text-text-muted italic">لا توجد عمليات تحويل مسجلة حالياً</td>
              </tr>
              <tr v-for="t in recentTransfers" :key="t.id" class="hover:bg-white/[0.02] transition-colors group">
                <td class="px-6 py-4 text-xs font-bold text-text-muted">{{ t.date }}</td>
                <td class="px-6 py-4 text-sm font-bold text-text-main">{{ t.from_account }}</td>
                <td class="px-6 py-4 text-center">
                  <div class="w-6 h-6 rounded-full bg-gold/10 flex items-center justify-center mx-auto">
                    <ChevronLeft class="w-3 h-3 text-gold" />
                  </div>
                </td>
                <td class="px-6 py-4 text-sm font-bold text-text-main">{{ t.to_account }}</td>
                <td class="px-6 py-4">
                  <span class="font-mono text-sm font-black text-gold">{{ formatCurrency(t.amount) }}</span>
                </td>
                <td class="px-6 py-4 text-xs font-bold text-sky-400">{{ t.user }}</td>
                <td class="px-6 py-4 text-xs text-text-muted max-w-[200px] truncate" :title="t.notes">{{ t.notes || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Transfer Modal -->
    <teleport to="body">
      <div 
        v-if="showTransferModal" 
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-md animate-in fade-in duration-300"
        @click.self="closeTransferModal"
      >
        <div class="flight-panel w-full max-w-2xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300">
          <!-- Modal Header -->
          <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center border border-gold/20 text-gold shadow-2xl shadow-gold/5">
                <ArrowRightLeft class="w-7 h-7" />
              </div>
              <div>
                <h3 class="font-display text-2xl font-black text-text-main">تحويل مالي بين الموديولات</h3>
                <p class="text-sm text-text-muted">نقل الأرصدة والسيولة بين الخزن والمحافظ بكل احترافية</p>
              </div>
            </div>
            <button @click="closeTransferModal" class="p-2 text-text-muted hover:text-text-main transition-colors">
              <X class="w-6 h-6" />
            </button>
          </div>

          <form @submit.prevent="executeTransfer" class="p-8 space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
              <!-- Source -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">من حساب (المصدر)</label>
                <div class="relative group">
                  <select 
                    v-model="transferForm.from_account_id" 
                    required
                    class="flight-select w-full !pl-11 font-bold group-hover:border-gold/30 transition-all text-white bg-black"
                  >
                    <option value="" disabled>اختر حساب المصدر</option>
                    <optgroup v-for="(module, mKey) in safeModules" :key="'src-'+mKey" :label="module.label" class="text-gold bg-black">
                      <option v-for="acc in module.accounts" :key="'src-acc-'+acc.id" :value="acc.id" class="text-white bg-black">
                        {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                      </option>
                    </optgroup>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-hover:text-gold transition-colors">
                    <Upload class="w-5 h-5" />
                  </div>
                </div>
              </div>

              <!-- Destination -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">إلى حساب (المستهدف)</label>
                <div class="relative group">
                  <select 
                    v-model="transferForm.to_account_id" 
                    required
                    class="flight-select w-full !pl-11 font-bold group-hover:border-emerald-500/30 transition-all text-white bg-black"
                  >
                    <option value="" disabled>اختر حساب المستهدف</option>
                    <optgroup v-for="(module, mKey) in safeModules" :key="'dst-'+mKey" :label="module.label" class="text-gold bg-black">
                      <option v-for="acc in module.accounts" :key="'dst-acc-'+acc.id" :value="acc.id" class="text-white bg-black">
                        {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                      </option>
                    </optgroup>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-hover:text-emerald-500 transition-colors">
                    <Download class="w-5 h-5" />
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">
                المبلغ المراد تحويله
                <span v-if="transferFromAccount" class="text-gold normal-case">({{ transferFromAccount.currency }})</span>
              </label>
              <div class="relative group">
                <input
                  v-model.number="transferForm.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  placeholder="0.00"
                  class="flight-input w-full font-mono text-xl font-black group-hover:border-gold/30 transition-all"
                />
                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gold">
                  <Banknote class="w-6 h-6" />
                </div>
              </div>
            </div>

            <div
              v-if="transferFromAccount && transferToAccount && !currenciesMatch(transferFromAccount.currency, transferToAccount.currency)"
              class="space-y-2"
            >
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">
                سعر الصرف
                <span class="text-rose-400">*</span>
              </label>
              <div class="flex items-center gap-2">
                <span class="text-sm text-text-muted whitespace-nowrap">1 {{ transferFromAccount.currency }} =</span>
                <input
                  v-model.number="transferForm.exchange_rate"
                  type="number"
                  step="0.000001"
                  min="0.000001"
                  required
                  class="flight-input flex-1 font-mono font-bold"
                />
                <span class="text-sm text-text-muted whitespace-nowrap">{{ transferToAccount.currency }}</span>
              </div>
              <p class="text-sm text-text-muted">
                المبلغ المضاف للحساب المستلم:
                <span class="text-gold font-bold">{{ formatCurrency(transferConvertedAmount, transferToAccount.currency) }}</span>
              </p>
            </div>

            <div
              v-if="transferFromAccount && transferToAccount"
              class="rounded-2xl border border-white/10 bg-white/[0.03] p-4 space-y-2 text-sm"
            >
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">من</span>
                <span class="font-bold text-text-main">{{ transferFromAccount.name }}</span>
              </div>
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">إلى</span>
                <span class="font-bold text-text-main">{{ transferToAccount.name }}</span>
              </div>
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">المبلغ المخصوم</span>
                <span class="font-bold text-gold">{{ formatCurrency(transferForm.amount, transferFromAccount.currency) }}</span>
              </div>
              <div
                v-if="!currenciesMatch(transferFromAccount.currency, transferToAccount.currency)"
                class="flex justify-between gap-4"
              >
                <span class="text-text-muted">المبلغ المضاف</span>
                <span class="font-bold text-emerald-400">{{ formatCurrency(transferConvertedAmount, transferToAccount.currency) }}</span>
              </div>
            </div>

            <p
              v-if="transferError"
              class="text-sm text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3"
            >
              {{ transferError }}
            </p>

            <div class="space-y-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">البيان / ملاحظات العملية</label>
              <textarea 
                v-model="transferForm.notes"
                rows="3" 
                class="flight-input w-full resize-none placeholder:text-text-muted/30"
                placeholder="اكتب تفاصيل عملية التحويل هنا لتسهيل عملية المراجعة لاحقاً..."
              ></textarea>
            </div>

            <div class="flex gap-4 pt-4">
              <button
                type="submit"
                :disabled="submitting || !canExecuteTransfer"
                class="btn-airline flex-1 py-4 text-base font-black shadow-2xl disabled:opacity-50 flex items-center justify-center gap-3"
              >
                <span v-if="submitting" class="w-5 h-5 border-2 border-white/30 border-t-white animate-spin rounded-full"></span>
                {{ submitting ? 'جاري تنفيذ العملية...' : 'اعتماد التحويل المالي' }}
              </button>
              <button 
                type="button" 
                @click="closeTransferModal"
                class="btn-airline-ghost px-8 py-4 text-base font-bold rounded-2xl"
              >
                إلغاء
              </button>
            </div>
          </form>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import {
  buildTransferApiPayload,
  canExecuteCrossCurrencyTransfer,
  computeConvertedAmount,
  currenciesMatch,
  findTreasuryAccount,
} from '@/composables/useCrossCurrencyTransfer';
import { 
  RefreshCw, 
  ArrowRightLeft, 
  Banknote, 
  FileText,
  X,
  Upload,
  Download,
  Send,
  Globe,
  Ticket,
  IdCard,
  Monitor,
  Building2,
  ListTodo,
  History,
  ArrowRight,
  ChevronLeft,
  PieChart,
  Briefcase
} from 'lucide-vue-next';

const router = useRouter();
const loading = ref(false);
const submitting = ref(false);

// State
const overview = ref({});
const recentTransfers = ref([]);
const stats = ref({});
const showTransferModal = ref(false);
const transferError = ref('');
const selectedCategory = ref('office'); // 'office' or 'tourism'

const categories = [
  { id: 'office', label: 'المكتب العام', icon: Building2 },
  { id: 'tourism', label: 'السياحة والطيران', icon: Briefcase },
];

const transferForm = ref({
  from_account_id: '',
  to_account_id: '',
  amount: null,
  exchange_rate: 1,
  notes: '',
});

// Safe modules for template
const safeModules = computed(() => {
  const res = {};
  const raw = overview.value;
  if (raw && typeof raw === 'object') {
    Object.keys(raw).forEach(key => {
      const mod = raw[key];
      if (mod && typeof mod === 'object') {
        res[key] = {
          label: mod.label || key,
          category: mod.category || 'office',
          accounts: Array.isArray(mod.accounts) ? mod.accounts : []
        };
      }
    });
  }
  return res;
});

const treasuryAccountsFlat = computed(() => {
  const list = [];
  Object.values(safeModules.value).forEach((mod) => {
    (mod.accounts || []).forEach((acc) => list.push(acc));
  });
  return list;
});

const transferFromAccount = computed(() =>
  findTreasuryAccount(treasuryAccountsFlat.value, transferForm.value.from_account_id)
);

const transferToAccount = computed(() =>
  findTreasuryAccount(treasuryAccountsFlat.value, transferForm.value.to_account_id)
);

const transferConvertedAmount = computed(() => {
  if (!transferFromAccount.value || !transferToAccount.value) return 0;
  return computeConvertedAmount(
    transferForm.value.amount,
    transferForm.value.exchange_rate,
    transferFromAccount.value.currency,
    transferToAccount.value.currency
  );
});

const canExecuteTransfer = computed(() =>
  canExecuteCrossCurrencyTransfer({
    fromAccountId: transferForm.value.from_account_id,
    toAccountId: transferForm.value.to_account_id,
    fromAccount: transferFromAccount.value,
    toAccount: transferToAccount.value,
    amount: transferForm.value.amount,
    exchangeRate: transferForm.value.exchange_rate,
  })
);

const currentCategoryLabel = computed(() => {
  return categories.find(c => c.id === selectedCategory.value)?.label || '';
});

const filteredModules = computed(() => {
  const res = {};
  const modules = safeModules.value;
  Object.keys(modules).forEach(key => {
    const mod = modules[key];
    // Map 'flights' to 'tourism' for consistency
    const cat = mod.category === 'flights' ? 'tourism' : mod.category;
    if (cat === selectedCategory.value) {
      res[key] = mod;
    }
  });
  return res;
});

// Stats Computed
const totalLiquidity = computed(() => {
  let total = 0;
  Object.values(safeModules.value).forEach(module => {
    module.accounts.forEach(acc => {
      if (acc && typeof acc.balance === 'number') {
        total += acc.balance;
      }
    });
  });
  return total;
});

const totalBanks = computed(() => {
  let total = 0;
  Object.values(safeModules.value).forEach(module => {
    module.accounts.forEach(acc => {
      if (acc && acc.type === 'bank' && typeof acc.balance === 'number') {
        total += acc.balance;
      }
    });
  });
  return total;
});

const totalWallets = computed(() => {
  let total = 0;
  Object.values(safeModules.value).forEach(module => {
    module.accounts.forEach(acc => {
      if (acc && acc.type === 'wallet' && typeof acc.balance === 'number') {
        total += acc.balance;
      }
    });
  });
  return total;
});

const totalPost = computed(() => {
  let total = 0;
  Object.values(safeModules.value).forEach(module => {
    module.accounts.forEach(acc => {
      if (acc && acc.type === 'post' && typeof acc.balance === 'number') {
        total += acc.balance;
      }
    });
  });
  return total;
});

async function fetchOverview() {
  loading.value = true;
  try {
    const response = await axios.get('/api/v1/finance/treasuries/get-overview', {
      params: { _t: Date.now() },
    });
    const data = response.data?.data || {};
    overview.value = data.modules && typeof data.modules === 'object' ? data.modules : {};
    recentTransfers.value = Array.isArray(data.recent_transfers) ? data.recent_transfers : [];
    stats.value = data.stats && typeof data.stats === 'object' ? data.stats : {};
  } catch (err) {
    console.error('Failed to fetch treasury overview:', err);
    if (window.addToast) window.addToast('فشل في تحميل بيانات الخزينة', 'error');
  } finally {
    loading.value = false;
  }
}

function getModuleTotal(accounts) {
  if (!Array.isArray(accounts)) return 0;
  return accounts.reduce((sum, acc) => sum + (Number(acc.balance) || 0), 0);
}

function getModulePercentage(accounts) {
  // Use category-specific total if we want, but global total is fine for overview
  const total = totalLiquidity.value || 1;
  const moduleTotal = getModuleTotal(accounts);
  return Math.round((moduleTotal / total) * 100);
}

function getModuleIcon(key) {
  const icons = {
    flights: Send,
    flight: Send,
    bus: Ticket,
    visa: IdCard,
    visas: IdCard,
    hajj_umra: Globe,
    online: Monitor,
    general: Building2,
    office: Building2,
    other: ListTodo,
    fawry: RefreshCw,
    wallet: Banknote,
    wallet_transfer: Banknote,
  };
  return icons[key] || Building2;
}

function getTypeColor(type) {
  const colors = {
    cashbox: 'bg-gold shadow-[0_0_8px_rgba(234,179,8,0.4)]',
    bank: 'bg-sky-500 shadow-[0_0_8px_rgba(14,165,233,0.4)]',
    wallet: 'bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.4)]',
    post: 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]',
    treasury: 'bg-amber-600 shadow-[0_0_8px_rgba(217,119,6,0.4)]',
  };
  return colors[type] || 'bg-white/20';
}

function openTransferModal() {
  showTransferModal.value = true;
}

function closeTransferModal() {
  showTransferModal.value = false;
  transferError.value = '';
  transferForm.value = {
    from_account_id: '',
    to_account_id: '',
    amount: null,
    exchange_rate: 1,
    notes: '',
  };
}

function quickTransfer(acc) {
  if (!acc) return;
  transferForm.value.from_account_id = acc.id;
  showTransferModal.value = true;
}

function viewStatement(id) {
  if (!id) return;
  router.push({ name: 'finance.accounts.statement.detail', params: { id } });
}

async function executeTransfer() {
  transferError.value = '';

  if (!canExecuteTransfer.value) {
    transferError.value = 'تحقق من الحسابات والمبلغ وسعر الصرف والرصيد المتاح';
    return;
  }

  submitting.value = true;
  try {
    const payload = buildTransferApiPayload({
      from_account_id: transferForm.value.from_account_id,
      to_account_id: transferForm.value.to_account_id,
      amount: transferForm.value.amount,
      notes: transferForm.value.notes,
      exchange_rate: transferForm.value.exchange_rate,
      fromAccount: transferFromAccount.value,
      toAccount: transferToAccount.value,
    });

    await axios.post('/api/v1/finance/transfers', payload);

    if (window.addToast) window.addToast('تم تنفيذ التحويل المالي بنجاح', 'success');
    closeTransferModal();
    await fetchOverview();
  } catch (err) {
    console.error('Transfer failed:', err);
    const msg = err.response?.data?.message || 'فشل في تنفيذ عملية التحويل';
    transferError.value = msg;
    if (window.addToast) window.addToast(msg, 'error');
  } finally {
    submitting.value = false;
  }
}

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(Number(amount) || 0);
}

onMounted(() => {
  fetchOverview();
});
</script>

<style scoped>
.flight-panel {
  background-color: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(24px);
  border-radius: 1.5rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  padding: 2rem;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.flight-input {
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  padding: 0.75rem 1.25rem;
  color: #f9fafb;
  transition: all 0.3s ease;
  outline: none;
}
.flight-input:focus {
  border-color: rgba(212, 168, 67, 0.5);
}

.flight-select {
  background-color: #000;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  padding: 0.75rem 1.25rem;
  color: #f9fafb;
  transition: all 0.3s ease;
  outline: none;
  appearance: none;
}
.flight-select:focus {
  border-color: rgba(212, 168, 67, 0.5);
}

.btn-airline {
  background: linear-gradient(to right, #d4a843, #f59e0b);
  color: #000;
  border-radius: 1rem;
  transition: all 0.3s ease;
  cursor: pointer;
}
.btn-airline:hover {
  transform: scale(1.02);
}
.btn-airline:active {
  transform: scale(0.98);
}
.btn-airline:disabled {
  filter: grayscale(1);
  cursor: not-allowed;
}

.btn-airline-ghost {
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #f9fafb;
  transition: all 0.3s ease;
}
.btn-airline-ghost:hover {
  background-color: rgba(255, 255, 255, 0.1);
}

/* Custom Scrollbar */
::-webkit-scrollbar {
  width: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 9999px;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>
