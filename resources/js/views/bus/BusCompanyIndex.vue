  <template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-16">
    <!-- Header -->
    <header class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-black text-white tracking-tight">
          شركات الباصات
        </h1>
        <p class="text-white/50 mt-1">
          إدارة شركات النقل، مديونيات التذاكر، وكشوف الحسابات المالية.
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
          {{ store.companies.length }}
        </p>
      </div>

      <div class="p-6 bg-white/[0.02] border border-white/10 rounded-2xl relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/5 rounded-full blur-2xl group-hover:bg-red-500/10 transition"></div>
        <div class="flex items-center gap-3 mb-4">
          <div class="p-2 bg-red-500/10 rounded-xl">
            <AlertTriangle class="w-5 h-5 text-red-400" />
          </div>
          <span class="text-xs font-bold uppercase tracking-widest text-white/40">إجمالي المديونيات</span>
        </div>
        <p class="text-3xl font-black font-mono text-red-400 tabular-nums">
          {{ Number(totalDebt).toLocaleString('ar-EG') }}
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
          {{ activeCompanies }}
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
              <th class="px-6 py-5 font-bold">الرصيد / الدين</th>
              <th class="px-6 py-5 font-bold">الحالة</th>
              <th class="px-6 py-5 font-bold text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <template v-if="store.loading.companies">
              <tr v-for="i in 6" :key="i">
                <td v-for="j in 5" :key="j" class="px-6 py-6">
                  <div class="h-4 animate-pulse rounded bg-white/5 w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="store.companies.length > 0">
              <tr
                v-for="company in store.companies"
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
                  <div class="flex flex-col gap-1">
                    <div v-if="company.phone" class="flex items-center gap-2 text-xs text-white/60">
                      <Phone class="w-3 h-3 text-white/20" />
                      <span>{{ company.phone }}</span>
                    </div>
                    <div v-if="company.contact_person" class="flex items-center gap-2 text-xs text-white/60">
                      <User class="w-3 h-3 text-white/20" />
                      <span>{{ company.contact_person }}</span>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-5">
                  <div class="flex flex-col">
                    <span
                      :class="[
                        'font-mono font-black text-sm tabular-nums',
                        Number(company.balance) < 0 ? 'text-red-400' : 'text-emerald-400'
                      ]"
                    >
                      {{ Number(Math.abs(company.balance || 0)).toLocaleString('ar-EG') }} ج.م
                    </span>
                    <p class="text-[10px] font-bold uppercase tracking-tighter mt-0.5" :class="Number(company.balance) < 0 ? 'text-red-400/50' : 'text-emerald-400/50'">
                      {{ Number(company.balance) < 0 ? 'مديونية' : 'رصيد دائن' }}
                    </p>
                  </div>
                </td>
                <td class="px-6 py-5">
                  <span
                    :class="[
                      'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider',
                      company.is_active
                        ? 'bg-emerald-500/10 text-emerald-400'
                        : 'bg-red-500/10 text-red-400'
                    ]"
                  >
                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                    {{ company.is_active ? 'نشط' : 'متوقف' }}
                  </span>
                </td>
                <td class="px-6 py-5 text-left">
                  <div class="flex items-center justify-end gap-2">
                    <router-link
                      :to="{ name: 'bus.companies.statement', params: { id: company.id } }"
                      class="p-2 rounded-lg bg-white/5 border border-white/5 text-white/40 hover:text-blue-400 hover:border-blue-500/30 transition-all"
                      title="كشف الحساب"
                    >
                      <FileText class="w-4 h-4" />
                    </router-link>
                    <button
                      v-if="Number(company.balance) < 0"
                      @click="openPaymentModal(company)"
                      class="p-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500 hover:text-black transition-all"
                      title="تسديد دين"
                    >
                      <Wallet class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="5" class="px-6 py-24 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center border border-white/5">
                    <Building2 class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-white">لا توجد شركات مسجلة</h3>
                    <p class="text-white/30 text-sm mt-1">
                      ابدأ بإضافة أول شركة باصات لإدارة رحلاتها وديونها.
                    </p>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>


    <!-- Payment Modal -->
    <Teleport to="body">
      <div v-if="showPaymentModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-[#0b1220] border border-white/10 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
          <div class="px-6 py-5 border-b border-white/5 flex items-center justify-between bg-emerald-500/5">
            <h3 class="text-lg font-black text-emerald-400 flex items-center gap-2">
              <Wallet class="w-5 h-5" />
              تسديد دين الشركة
            </h3>
            <button @click="closePaymentModal" class="text-white/20 hover:text-white">✕</button>
          </div>

          <form @submit.prevent="submitPayment" class="p-6 space-y-5">
            <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30">الشركة</p>
                <p class="text-sm font-bold text-white">{{ selectedCompany?.name }}</p>
              </div>
              <div class="text-left">
                <p class="text-[10px] font-bold uppercase tracking-widest text-white/30">الدين الحالي</p>
                <p class="text-sm font-black text-red-400 tabular-nums">{{ Number(Math.abs(selectedCompany?.balance || 0)).toLocaleString('ar-EG') }} ج.م</p>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">من حساب (المصدر) *</label>
              <select v-model="paymentForm.from_account_id" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white outline-none focus:border-emerald-500/50 transition">
                <option value="">-- اختر حساب الدفع --</option>
                <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">{{ acc.name }} ({{ acc.balance }} ج.م)</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">المبلغ المراد تسديده *</label>
              <div class="relative">
                <input v-model.number="paymentForm.amount" type="number" step="0.01" required :max="Math.abs(selectedCompany?.balance || 0)" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white font-mono outline-none focus:border-emerald-500/50 transition" />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-white/20 text-xs">ج.م</span>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/40 mb-2">ملاحظات</label>
              <input v-model="paymentForm.notes" type="text" placeholder="مثال: تسديد دفعة من مديونية تذاكر شهر مايو" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white text-xs outline-none focus:border-emerald-500/50 transition" />
            </div>

            <div class="flex gap-3 pt-4">
              <button type="submit" :disabled="submitting" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-black py-3 rounded-xl transition disabled:opacity-40 shadow-lg shadow-emerald-900/20">
                {{ submitting ? 'جاري التنفيذ...' : 'تأكيد التسديد' }}
              </button>
              <button type="button" @click="closePaymentModal" class="px-6 py-3 border border-white/10 text-white/60 font-bold rounded-xl hover:bg-white/5 transition">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useBusStore } from '@/stores/busStore';
import {
  Plus,
  Building2,
  AlertTriangle,
  CheckCircle,
  Phone,
  User,
  Edit2,
  Trash2,
  FileText,
  Wallet,
  Landmark,
} from 'lucide-vue-next';
import axios from 'axios';

const store = useBusStore();

const showPaymentModal = ref(false);
const submitting = ref(false);
const selectedCompany = ref(null);

const accounts = ref([]); // All accounts for linking
const treasuryAccounts = ref([]); // Only cashboxes/banks/wallets for payment source

const paymentForm = ref({
  amount: 0,
  from_account_id: '',
  notes: '',
});

const totalDebt = computed(() => {
  return store.companies.reduce((sum, c) => {
    const b = Number(c.balance || 0);
    return b < 0 ? sum + Math.abs(b) : sum;
  }, 0);
});

const activeCompanies = computed(() => {
  return store.companies.filter((c) => c.is_active).length;
});

const loadAccounts = async () => {
    try {
        const res =await axios.get('/api/v1/finance/accounts', { params: { per_page: 100 } })

        accounts.value = res.data?.data?.items || res.data?.data || [];
        // For payments, usually we use accounts with module_type 'bus' or general treasury
        treasuryAccounts.value = accounts.value.filter(a => ['cashbox', 'bank', 'wallet', 'treasury'].includes(String(a.type?.value || a.type).toLowerCase()));
    } catch (e) {
        console.error('Failed to load accounts', e);
    }
}

const openPaymentModal = (company) => {
  selectedCompany.value = company;
  paymentForm.value = {
    amount: Math.abs(Number(company.balance || 0)),
    from_account_id: '',
    notes: '',
  };
  showPaymentModal.value = true;
};

const closePaymentModal = () => {
  showPaymentModal.value = false;
  selectedCompany.value = null;
};

const submitPayment = async () => {
  if (!paymentForm.value.from_account_id) {
      store.addToast('يرجى اختيار حساب مصدر الدفع', 'error');
      return;
  }
  submitting.value = true;
  try {
    await store.payCompanyDebt(selectedCompany.value.id, paymentForm.value);
    store.addToast('تم تنفيذ عملية التسديد وتحديث الرصيد بنجاح');
    closePaymentModal();
    await store.fetchCompanies();
  } catch (error) {
    store.addToast('فشل تنفيذ عملية التسديد. تحقق من الرصيد المتاح في حساب المصدر.', 'error');
  } finally {
    submitting.value = false;
  }
};

onMounted(async () => {
  await Promise.all([store.fetchCompanies(), loadAccounts()]);
});
</script>
