


    <!-- Filters -->
    <div class="bg-card border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="md:col-span-2 lg:col-span-1">
          <label class="block text-sm font-medium text-muted mb-2">اسم المجموعة أو الكود</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="البحث..."
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-400 transition-all text-white"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-muted mb-2">حالة الرصيد</label>
          <select
            v-model="filters.status"
            class="w-full px-4 py-2.5 bg-input border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 text-sm text-white cursor-pointer"
          >
            <option value="all">الكل</option>
            <option value="payable">مستحق لهم (علينا)</option>
            <option value="receivable">مستحق لنا</option>
            <option value="zero">مسوّى</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-muted mb-2">الترتيب</label>
          <select
            v-model="filters.sort"
            class="w-full px-4 py-2.5 bg-input border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/50 text-sm text-white cursor-pointer"
          >
            <option value="name_asc">الاسم (أ → ي)</option>
            <option value="balance_desc">الرصيد (الأعلى أولاً)</option>
            <option value="balance_asc">الرصيد (الأقل أولاً)</option>
          </select>
        </div>
        <div class="flex items-end">
          <button
            type="button"
            @click="resetFilters"
            class="w-full px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-sm text-white/60 hover:text-white transition-all flex items-center justify-center gap-2"
          >
            <RotateCcw class="w-4 h-4" />
            إعادة تعيين
          </button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-card border border-white/10 rounded-2xl overflow-hidden shadow-xl">
      <div v-if="loading" class="flex flex-col items-center justify-center py-20">
        <Loader2 class="w-10 h-10 animate-spin text-sky-400 mb-4" />
        <span class="text-muted">جاري تحميل المجموعات...</span>
      </div>

      <div v-else-if="filteredRecords.length === 0" class="text-center py-20">
        <LayoutGrid class="w-16 h-16 mx-auto text-muted/30 mb-4" />
        <h3 class="text-xl font-bold text-white">لا توجد مجموعات</h3>
        <p class="text-muted mt-2">لا توجد مجموعات طيران مطابقة للمعايير المحددة.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-black/20 text-[11px] uppercase tracking-[0.18em] text-muted">
              <th class="px-6 py-5 font-bold">المجموعة</th>
              <th class="px-6 py-5 font-bold">شركة الطيران</th>
              <th class="px-6 py-5 font-bold">مسؤول التواصل</th>
              <th class="px-6 py-5 font-bold text-error/80">المديونية</th>
              <th class="px-6 py-5 font-bold text-left">إجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/[0.04]">
            <tr
              v-for="row in paginatedRecords"
              :key="row.id"
              class="group transition-colors hover:bg-white/[0.025]"
            >
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sm font-black text-sky-300 border border-sky-500/20">
                    {{ (row.name || '?').charAt(0) }}
                  </div>
                  <div>
                    <p class="font-bold text-white text-sm">{{ row.name || '—' }}</p>
                    <p v-if="row.code" class="text-xs text-muted font-mono mt-0.5">{{ row.code }}</p>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4">
                <div v-if="row.carrier" class="flex items-center gap-1.5 text-sm text-sky-400">
                  <Plane class="w-3.5 h-3.5" />
                  <span>{{ row.carrier.name }}</span>
                </div>
                <span v-else class="text-muted/40">—</span>
              </td>
              <td class="px-6 py-4 text-xs text-muted">
                <div>{{ row.contact_person || '—' }}</div>
                <div v-if="row.contact_phone" class="font-mono mt-0.5">{{ row.contact_phone }}</div>
              </td>
              <td class="px-6 py-4">
                <span :class="['font-mono text-sm font-black', groupBalanceMeta(row.balance).class]">
                  {{ groupBalanceMeta(row.balance).text }}
                </span>
                <span v-if="groupBalanceMeta(row.balance).label" class="mr-2 text-[10px] font-bold text-muted">
                  {{ groupBalanceMeta(row.balance).label }}
                </span>
              </td>
              <td class="px-6 py-4 text-left">
                <div class="flex gap-2 justify-end flex-wrap">
                  <button
                    v-if="Number(row.balance) > 0"
                    type="button"
                    @click="openPayModal(row, 'payment')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-bold text-amber-300 transition hover:bg-amber-500 hover:text-black cursor-pointer"
                  >
                    <ArrowUp class="w-3.5 h-3.5" />
                    سند صرف
                  </button>
                  <button
                    v-if="Number(row.balance) < 0"
                    type="button"
                    @click="openPayModal(row, 'debt')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-success/30 bg-success/10 px-3 py-1.5 text-xs font-bold text-success transition hover:bg-success hover:text-black cursor-pointer"
                  >
                    <ArrowDown class="w-3.5 h-3.5" />
                    سند قبض
                  </button>
                  <button
                    type="button"
                    @click="openStatement(row)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/60 transition hover:border-sky-400/40 hover:bg-sky-500/10 hover:text-sky-300 cursor-pointer"
                  >
                    <Eye class="w-3.5 h-3.5" />
                    كشف الحساب
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="filteredRecords.length > perPage" class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <div class="text-sm text-muted">
          عرض {{ (currentPage - 1) * perPage + 1 }} إلى {{ Math.min(currentPage * perPage, filteredRecords.length) }} من {{ filteredRecords.length }} مجموعة
        </div>
        <div class="flex gap-2">
          <button
            type="button"
            :disabled="currentPage === 1"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 text-white text-xs font-bold"
            @click="currentPage--"
          >
            السابق
          </button>
          <button
            type="button"
            :disabled="currentPage * perPage >= filteredRecords.length"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 text-white text-xs font-bold"
            @click="currentPage++"
          >
            التالي
          </button>
        </div>
      </div>
    </div>

    <!-- Statement Modal -->
    <div
      v-if="statementOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
      @click.self="statementOpen = false"
    >
      <div class="bg-[#1A1A1A] border border-white/15 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh]">
        <div class="p-6 border-b border-white/10 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-white">كشف حساب المجموعة</h3>
            <p class="text-xs text-muted mt-1">{{ selectedRow?.name }}</p>
          </div>
          <button type="button" class="text-muted hover:text-white" @click="statementOpen = false">
            <X class="w-6 h-6" />
          </button>
        </div>

        <div v-if="statementLoading" class="flex items-center justify-center py-16">
          <Loader2 class="w-8 h-8 animate-spin text-sky-400" />
        </div>

        <div v-else class="p-6 overflow-y-auto space-y-6 flex-1">
          <div class="grid grid-cols-3 gap-4 bg-white/[0.02] border border-white/5 rounded-xl p-4">
            <div class="text-center border-l border-white/5">
              <span class="text-xs text-muted block mb-1">مشتريات بالأجل (علينا)</span>
              <span class="font-mono text-lg font-extrabold text-error">{{ formatMoney(statementStats.total_debt) }}</span>
            </div>
            <div class="text-center border-l border-white/5">
              <span class="text-xs text-muted block mb-1">مدفوعاتنا لهم</span>
              <span class="font-mono text-lg font-extrabold text-success">{{ formatMoney(statementStats.total_payment) }}</span>
            </div>
            <div class="text-center">
              <span class="text-xs text-muted block mb-1">الرصيد المتبقي</span>
              <span class="font-mono text-lg font-extrabold" :class="statementStats.balance > 0 ? 'text-error' : 'text-success'">
                {{ formatMoney(statementStats.balance) }}
              </span>
            </div>
          </div>

          <div v-if="statementItems.length" class="border border-white/10 rounded-xl overflow-hidden">
            <table class="w-full text-right border-collapse text-xs">
              <thead>
                <tr class="bg-black/20 text-muted border-b border-white/10">
                  <th class="px-4 py-3 font-bold">التاريخ</th>
                  <th class="px-4 py-3 font-bold">النوع</th>
                  <th class="px-4 py-3 font-bold">البيان</th>
                  <th class="px-4 py-3 font-bold">مدين (علينا)</th>
                  <th class="px-4 py-3 font-bold">دائن (سداد)</th>
                  <th class="px-4 py-3 font-bold">الرصيد</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/[0.04]">
                <tr v-for="(item, idx) in statementItems" :key="idx">
                  <td class="px-4 py-3 text-muted font-mono">{{ formatDate(item.created_at) }}</td>
                  <td class="px-4 py-3">
                    <span class="rounded px-2 py-0.5 text-[10px] font-bold" :class="item.debit > 0 ? 'bg-error/10 text-error' : 'bg-success/10 text-success'">
                      {{ item.debit > 0 ? 'شراء بالأجل' : 'سداد' }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-white max-w-md whitespace-normal break-words leading-relaxed">{{ item.description }}</td>
                  <td class="px-4 py-3 font-mono text-error">{{ item.debit > 0 ? formatMoney(item.debit) : '—' }}</td>
                  <td class="px-4 py-3 font-mono text-success">{{ item.credit > 0 ? formatMoney(item.credit) : '—' }}</td>
                  <td class="px-4 py-3 font-mono font-bold text-gold">{{ formatMoney(item.balance_after) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="text-center py-10 text-muted">لا توجد حركات مسجلة</div>
        </div>

        <div class="p-6 border-t border-white/10 flex justify-end gap-3">
          <button
            v-if="selectedRow && Number(selectedRow.balance) > 0"
            type="button"
            class="px-5 py-2.5 bg-amber-500/20 text-amber-300 rounded-xl text-xs font-bold"
            @click="openPayModal(selectedRow, 'payment'); statementOpen = false"
          >
            سند صرف للمجموعة
          </button>
          <button type="button" class="px-6 py-2.5 bg-white/5 hover:bg-white/10 rounded-xl font-bold text-white text-xs" @click="statementOpen = false">
            إغلاق
          </button>
        </div>
      </div>
    </div>

    <!-- Pay Modal -->
    <div
      v-if="payOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
      @click.self="payOpen = false"
    >
      <div class="bg-[#1A1A1A] border border-white/15 w-full max-w-lg rounded-2xl overflow-hidden shadow-2xl">
        <div class="p-5 border-b border-white/10 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-white">
              {{ payForm.type === 'payment' ? 'سند صرف — دفع للمجموعة' : 'سند قبض — تحصيل من المجموعة' }}
            </h3>
            <p class="text-xs text-muted mt-1">{{ payForm.name }}</p>
          </div>
          <button type="button" class="text-muted hover:text-white" @click="payOpen = false">
            <X class="w-5 h-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <div class="p-4 rounded-xl border flex justify-between items-center" :class="payForm.type === 'payment' ? 'bg-amber-500/10 border-amber-500/30' : 'bg-success/10 border-success/30'">
            <span class="text-xs font-bold text-muted">الرصيد الحالي:</span>
            <span class="font-mono text-lg font-black" :class="groupBalanceMeta(payForm.current_balance).class">
              {{ groupBalanceMeta(payForm.current_balance).text }}
              <span class="text-[10px] mr-1">{{ groupBalanceMeta(payForm.current_balance).label }}</span>
            </span>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2 font-bold">نوع حساب الصرف/التحصيل</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="chip in settlementChips"
                :key="chip.id"
                type="button"
                @click="settlementCategory = chip.id"
                :class="[
                  'flex items-center gap-2 px-3 py-2 rounded-lg border text-xs font-bold',
                  settlementCategory === chip.id ? 'bg-white/10 border-gold text-gold' : 'border-white/10 text-muted'
                ]"
              >
                <component :is="chip.icon" class="h-3.5 w-3.5" />
                {{ chip.label }}
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2 font-semibold">
              {{ payForm.type === 'payment' ? 'الحساب الصادر منه *' : 'الحساب المستلم *' }}
            </label>
            <select v-model="payForm.account_id" class="w-full p-3 bg-input border border-white/10 rounded-xl text-white text-xs">
              <option :value="null">اختر الحساب...</option>
              <option v-for="a in filteredAccounts" :key="a.id" :value="a.id">
                {{ a.name }} — {{ formatMoney(a.balance, a.currency) }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs text-muted mb-2 font-semibold">المبلغ (EGP) *</label>
            <input
              v-model.number="payForm.amount"
              type="number"
              min="0.01"
              step="0.01"
              class="w-full p-3 bg-input border border-white/10 rounded-xl text-white font-mono text-sm"
            />
          </div>

          <div>
            <label class="block text-xs text-muted mb-2 font-semibold">ملاحظات</label>
            <textarea v-model="payForm.notes" rows="2" class="w-full p-3 bg-input border border-white/10 rounded-xl text-white text-xs" />
          </div>
        </div>

        <div class="p-5 border-t border-white/10 flex justify-end gap-3">
          <button type="button" class="px-5 py-2.5 bg-white/5 rounded-xl text-white text-xs font-bold" @click="payOpen = false">
            إلغاء
          </button>
          <button
            type="button"
            :disabled="submitting || !payForm.account_id || payForm.amount <= 0"
            class="px-6 py-2.5 bg-sky-500 text-white font-bold rounded-xl disabled:opacity-40 flex items-center gap-2 text-xs"
            @click="submitPayment"
          >
            <Loader2 v-if="submitting" class="w-4 h-4 animate-spin" />
            حفظ السند
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useCustomerStore } from '@/stores/customerStore';
import {
  Search, Loader2, LayoutGrid, Plane, Eye, X, RotateCcw,
  AlertCircle, TrendingDown, ArrowUp, ArrowDown, Banknote, Wallet, Landmark,
} from 'lucide-vue-next';

const store = useCustomerStore();

const loading = ref(false);
const records = ref([]);
const filters = ref({ search: '', status: 'all', sort: 'name_asc' });
const currentPage = ref(1);
const perPage = 15;

const statementOpen = ref(false);
const statementLoading = ref(false);
const selectedRow = ref(null);
const statementItems = ref([]);
const statementStats = ref({ total_debt: 0, total_payment: 0, balance: 0 });

const payOpen = ref(false);
const submitting = ref(false);
const settlementCategory = ref('cash');
const settlementChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote },
  { id: 'wallet', label: 'محافظ', icon: Wallet },
  { id: 'bank', label: 'بنك', icon: Landmark },
];

const payForm = ref({
  group_id: null,
  name: '',
  current_balance: 0,
  amount: 0,
  account_id: null,
  type: 'payment',
  notes: '',
});

const filteredRecords = computed(() => {
  let r = [...records.value];
  const q = filters.value.search.trim().toLowerCase();
  if (q) {
    r = r.filter((row) =>
      (row.name || '').toLowerCase().includes(q) ||
      (row.code || '').toLowerCase().includes(q) ||
      (row.contact_person || '').toLowerCase().includes(q) ||
      (row.contact_phone || '').toLowerCase().includes(q)
    );
  }
  if (filters.value.status === 'payable') {
    r = r.filter((row) => Number(row.balance) > 0);
  } else if (filters.value.status === 'receivable') {
    r = r.filter((row) => Number(row.balance) < 0);
  } else if (filters.value.status === 'zero') {
    r = r.filter((row) => Number(row.balance) === 0);
  }
  if (filters.value.sort === 'balance_desc') {
    r.sort((a, b) => Number(b.balance) - Number(a.balance));
  } else if (filters.value.sort === 'balance_asc') {
    r.sort((a, b) => Number(a.balance) - Number(b.balance));
  } else {
    r.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'ar'));
  }
  return r;
});

const paginatedRecords = computed(() => {
  const start = (currentPage.value - 1) * perPage;
  return filteredRecords.value.slice(start, start + perPage);
});

const summaryStats = computed(() => {
  const payable = records.value.filter((r) => Number(r.balance) > 0);
  const totalPayable = payable.reduce((s, r) => s + (Number(r.balance) || 0), 0);
  return [
    {
      label: 'إجمالي المجموعات',
      value: `${records.value.length} مجموعة`,
      icon: LayoutGrid,
      glow: 'bg-sky-500',
      iconBg: 'bg-sky-500/10',
      iconColor: 'text-sky-400',
      valueColor: 'text-white',
    },
    {
      label: 'مجموعات علينا مديونية',
      value: `${payable.length} مجموعة`,
      icon: AlertCircle,
      glow: 'bg-red-500',
      iconBg: 'bg-red-500/10',
      iconColor: 'text-red-400',
      valueColor: 'text-red-400',
    },
    {
      label: 'إجمالي المستحق للمجموعات',
      value: formatMoney(totalPayable),
      icon: TrendingDown,
      glow: 'bg-amber-500',
      iconBg: 'bg-amber-500/10',
      iconColor: 'text-amber-300',
      valueColor: 'text-amber-300',
    },
    {
      label: 'مسوّى بالكامل',
      value: `${records.value.filter((r) => Number(r.balance) === 0).length} مجموعة`,
      icon: LayoutGrid,
      glow: 'bg-emerald-500',
      iconBg: 'bg-emerald-500/10',
      iconColor: 'text-emerald-400',
      valueColor: 'text-emerald-400',
    },
  ];
});

const filteredAccounts = computed(() => {
  // ✅ Phase 3.5b Fix: 'treasury' و 'post' تم حذفهم من AccountType enum
  // فلدينا فقط cashbox, bank, wallet كـ liquidity types
  const typeMap = {
    cash: ['cashbox'],
    wallet: ['wallet'],
    bank: ['bank'],
  };
  const allowed = typeMap[settlementCategory.value] || ['cashbox', 'wallet', 'bank'];
  return (store.accounts || []).filter((a) => allowed.includes(a.type));
});

function groupBalanceMeta(balance) {
  const bal = Number(balance) || 0;
  if (bal > 0) {
    return { text: formatMoney(bal), label: '(مستحق لهم)', class: 'text-error' };
  }
  if (bal < 0) {
    return { text: formatMoney(Math.abs(bal)), label: '(مستحق لنا)', class: 'text-success' };
  }
  return { text: '0.00 — مسوّى', label: '', class: 'text-muted' };
}

function formatMoney(n, curr = 'EGP') {
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(Number(n) || 0);
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

async function fetchGroups() {
  loading.value = true;
  try {
    const { data } = await axios.get('/api/v1/flight/groups');
    records.value = (data?.data || []).map((g) => ({
      id: g.id,
      name: g.name,
      code: g.code,
      contact_person: g.contact_person,
      contact_phone: g.contact_phone,
      carrier: g.carrier,
      balance: Number(g.balance ?? 0),
    }));
    currentPage.value = 1;
  } catch (e) {
    console.error(e);
    store.addToast('فشل تحميل مجموعات الطيران', 'error');
  } finally {
    loading.value = false;
  }
}

function resetFilters() {
  filters.value = { search: '', status: 'all', sort: 'name_asc' };
  currentPage.value = 1;
}

async function openStatement(row) {
  selectedRow.value = row;
  statementOpen.value = true;
  statementLoading.value = true;
  statementItems.value = [];
  try {
    const { data } = await axios.get(`/api/v1/flight/groups/${row.id}/statement`);
    const payload = data?.data;
    if (payload) {
      const mapped = (payload.transactions || []).map((tx) => {
        const isDebt = tx.type === 'debt';
        return {
          created_at: tx.created_at,
          description: tx.description || tx.notes || (isDebt ? `شراء — ${tx.booking?.booking_number || ''}` : 'سداد للمجموعة'),
          debit: isDebt ? parseFloat(tx.amount) : 0,
          credit: isDebt ? 0 : parseFloat(tx.amount),
        };
      });
      const sorted = [...mapped].reverse();
      let running = 0;
      sorted.forEach((item) => {
        running += item.debit - item.credit;
        item.balance_after = running;
      });
      statementItems.value = sorted.reverse();
      statementStats.value = {
        total_debt: parseFloat(payload.summary?.total_debt || 0),
        total_payment: parseFloat(payload.summary?.total_payment || 0),
        balance: parseFloat(payload.summary?.balance || 0),
      };
    }
  } catch (e) {
    console.error(e);
    store.addToast('فشل تحميل كشف المجموعة', 'error');
  } finally {
    statementLoading.value = false;
  }
}

function openPayModal(row, type) {
  payForm.value = {
    group_id: row.id,
    name: row.name,
    current_balance: Number(row.balance),
    amount: Math.abs(Number(row.balance)) || 0,
    account_id: null,
    type,
    notes: type === 'payment'
      ? `سند صرف — دفع لمجموعة طيران: ${row.name}`
      : `سند قبض — تحصيل من مجموعة طيران: ${row.name}`,
  };
  settlementCategory.value = 'cash';
  payOpen.value = true;
}

async function submitPayment() {
  if (!payForm.value.account_id || payForm.value.amount <= 0) return;
  submitting.value = true;
  try {
    await axios.post(`/api/v1/flight/groups/${payForm.value.group_id}/pay-debt`, {
      amount: payForm.value.amount,
      account_id: payForm.value.account_id,
      notes: payForm.value.notes?.trim() || null,
      type: payForm.value.type,
    });
    store.addToast('تم تسجيل السند بنجاح ✓');
    payOpen.value = false;
    await fetchGroups();
  } catch (e) {
    store.addToast(e.response?.data?.message || 'فشل حفظ السند', 'error');
  } finally {
    submitting.value = false;
  }
}

function exportCsv() {
  if (!filteredRecords.value.length) return;
  const headers = ['المجموعة', 'الكود', 'شركة الطيران', 'مسؤول التواصل', 'الهاتف', 'الرصيد', 'الحالة'];
  const rows = filteredRecords.value.map((r) => [
    r.name || '',
    r.code || '',
    r.carrier?.name || '',
    r.contact_person || '',
    r.contact_phone || '',
    r.balance || 0,
    Number(r.balance) > 0 ? 'مستحق لهم' : Number(r.balance) < 0 ? 'مستحق لنا' : 'مسوّى',
  ]);
  const csv = '\uFEFF' + headers.join(',') + '\n' + rows.map((e) => e.join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `مديونيات_المجموعات_${new Date().toISOString().split('T')[0]}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

defineExpose({ exportCsv, fetchGroups });

onMounted(async () => {
  await Promise.all([fetchGroups(), store.fetchAccounts()]);
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-error { color: var(--error); }
.text-success { color: var(--success); }
</style>
