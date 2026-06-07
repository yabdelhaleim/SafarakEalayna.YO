<template>
  <div class="animate-in fade-in duration-500 pb-16">
    <header class="bg-gradient-to-br from-[#0a1628] to-[#111827] border-b border-white/5 py-10 px-4 sm:px-6 lg:px-8">
      <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div>
          <router-link :to="{ name: 'bus.companies' }" class="flex items-center gap-2 text-white/40 hover:text-blue-400 text-xs font-bold uppercase tracking-widest transition mb-4">
            <ArrowRight class="w-4 h-4 rotate-180" />
            العودة للشركات
          </router-link>
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-blue-500/20 border border-blue-500/30 flex items-center justify-center">
              <Building2 class="w-6 h-6 text-blue-400" />
            </div>
            <div>
              <h1 class="text-3xl font-black text-white">{{ company?.name || 'كشف حساب الشركة' }}</h1>
              <p class="text-white/40 text-sm mt-1">سجل الحركات المالية والمديونيات لشركة الباص</p>
            </div>
          </div>
        </div>
        <div v-if="company" class="flex items-center gap-6">
          <button
            v-if="Number(company.balance) < 0"
            type="button"
            @click="openPaymentModal"
            class="flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-5 py-3 text-sm font-bold text-emerald-400 transition hover:bg-emerald-500 hover:text-black shadow-lg shadow-emerald-500/10"
          >
            <Wallet class="h-4 w-4"/> تسديد دين
          </button>
          <div class="flex flex-col items-end">
            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-white/30 mb-1">الرصيد الحالي</p>
            <p :class="[Number(company.balance) < 0 ? 'text-red-400' : 'text-emerald-400', 'text-4xl font-black tabular-nums']">
              {{ Number(Math.abs(company.balance)).toLocaleString('ar-EG') }}
              <span class="text-sm font-normal text-white/20 mr-1">ج.م</span>
            </p>
            <p class="text-[11px] font-bold mt-1" :class="Number(company.balance) < 0 ? 'text-red-400/60' : 'text-emerald-400/60'">
              {{ Number(company.balance) < 0 ? 'مديونية مستحقة' : 'رصيد دائن' }}
            </p>
          </div>
        </div>
      </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8 space-y-8">
      
      <!-- Filters/Actions -->
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-white flex items-center gap-2">
          <Clock class="w-5 h-5 text-blue-400" />
          سجل الحركات
        </h2>
        <button @click="reload" :disabled="loading" class="p-2 rounded-xl bg-white/5 border border-white/10 text-white/60 hover:text-white transition">
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
        </button>
      </div>

      <!-- Transactions Table -->
      <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.02]">
        <table class="min-w-full text-right text-sm">
          <thead class="border-b border-white/5 bg-black/20">
            <tr class="text-[11px] uppercase tracking-widest text-white/40">
              <th class="px-5 py-4 font-bold">التاريخ</th>
              <th class="px-5 py-4 font-bold">النوع</th>
              <th class="px-5 py-4 font-bold">المبلغ</th>
              <th class="px-5 py-4 font-bold">البيان / الحساب المقابل</th>
              <th class="px-5 py-4 font-bold">بواسطة</th>
              <th class="px-5 py-4 font-bold">ملاحظات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <tr v-for="tx in processedTransactions" :key="tx.id" class="hover:bg-white/[0.03] transition-colors">
              <td class="px-5 py-4 font-mono text-xs text-white/40">{{ formatDt(tx.created_at) }}</td>
              <td class="px-5 py-4">
                <div class="flex flex-col gap-1.5 items-start">
                  <span :class="[tx.to_account_id === company_account_id ? 'text-emerald-400' : 'text-red-400', 'text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-current/10']">
                    {{ tx.to_account_id === company_account_id ? 'إيداع / تسديد' : 'سحب / فاتورة' }}
                  </span>
                  <span v-if="tx.is_paid" class="inline-flex items-center gap-1 text-[9px] font-black text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                    <CheckCircle class="w-3 h-3" /> مسددة
                  </span>
                  <span v-if="tx.is_payment_for_booking" class="inline-flex items-center gap-1 text-[9px] font-black text-blue-400 bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 rounded-full">
                    <Link class="w-3 h-3" /> سداد لحجز
                  </span>
                </div>
              </td>
              <td class="px-5 py-4 font-mono font-black text-white tabular-nums text-sm">
                {{ Number(tx.amount).toLocaleString('ar-EG') }}
              </td>
              <td class="px-5 py-4">
                <div class="text-white/60 text-xs">
                  {{ tx.from_account_id === company_account_id ? (tx.to_account?.name || '—') : (tx.from_account?.name || '—') }}
                </div>
                <!-- Booking details shown on both rows if resolved -->
                <div v-if="tx.resolved_related" class="mt-1.5 flex flex-col gap-0.5">
                  <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center rounded-lg bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 text-[9px] font-black text-blue-400">
                      حجز باص #{{ tx.resolved_related.id }}
                    </span>
                    <span v-if="tx.resolved_related.customer?.full_name" class="text-[10px] font-bold text-white/70">
                      {{ tx.resolved_related.customer.full_name }}
                    </span>
                  </div>
                  <div v-if="tx.resolved_related.inventory?.route" class="text-[10px] text-white/40">
                    المسار: {{ tx.resolved_related.inventory.route }}
                  </div>
                </div>
                <!-- Fallback if only booking ID is parsed from notes but details aren't resolved -->
                <div v-else-if="tx.parsed_booking_id" class="mt-1.5">
                  <span class="inline-flex items-center rounded-lg bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 text-[9px] font-black text-blue-400">
                    حجز باص #{{ tx.parsed_booking_id }}
                  </span>
                </div>

                <!-- Invoice (سحب) details shown when viewing the payment row -->
                <div v-if="tx.is_payment_for_booking" class="mt-2.5 rounded-xl border border-blue-500/20 bg-blue-500/5 p-2.5 text-[10px] text-blue-400/90 max-w-[280px] space-y-1 shadow-inner shadow-black/20">
                  <div class="font-black flex items-center gap-1 text-xs">
                    <Clock class="w-3.5 h-3.5" /> تفاصيل الفاتورة المرتبطة:
                  </div>
                  <div>تاريخ الفاتورة: <span class="font-mono text-white/80 font-bold">{{ formatDt(tx.cost_date) }}</span></div>
                  <div>قيمة التكلفة: <span class="text-white/80 font-bold font-mono">{{ Number(tx.amount).toLocaleString('ar-EG') }} ج.م</span></div>
                </div>

                <!-- Payment (تسديد) details shown when viewing the invoice row -->
                <div v-if="tx.is_paid" class="mt-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-2.5 text-[10px] text-emerald-400/90 max-w-[280px] space-y-1 shadow-inner shadow-black/20">
                  <div class="font-black flex items-center gap-1 text-xs">
                    <Wallet class="w-3.5 h-3.5" /> تفاصيل التسديد:
                  </div>
                  <div>تاريخ التسديد: <span class="font-mono text-white/80 font-bold">{{ formatDt(tx.payment_date) }}</span></div>
                  <div>من حساب: <span class="text-white/80 font-bold">{{ tx.payment_account_name }}</span></div>
                </div>
              </td>
              <td class="px-5 py-4 text-white/40 text-[11px]">{{ tx.created_by?.name || 'النظام' }}</td>
              <td class="px-5 py-4 text-white/30 text-[11px] max-w-[200px]" :title="tx.notes">
                <div class="truncate">{{ tx.notes || '—' }}</div>
                <div v-if="tx.payment_notes && tx.payment_notes !== tx.notes" class="text-emerald-400/60 mt-1 text-[10px] truncate" :title="tx.payment_notes">
                  دفع: {{ tx.payment_notes }}
                </div>
              </td>
            </tr>
            <tr v-if="!processedTransactions.length && !loading">
              <td colspan="6" class="px-5 py-20 text-center text-white/20">لا توجد حركات مالية مسجلة لهذه الشركة</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-4">
        <button
          :disabled="meta.current_page <= 1"
          @click="loadPage(meta.current_page - 1)"
          class="px-4 py-2 rounded-xl border border-white/10 bg-white/5 text-xs font-bold text-white/60 hover:text-white transition disabled:opacity-20"
        >السابق</button>
        <span class="text-xs text-white/30">{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button
          :disabled="meta.current_page >= meta.last_page"
          @click="loadPage(meta.current_page + 1)"
          class="px-4 py-2 rounded-xl border border-white/10 bg-white/5 text-xs font-bold text-white/60 hover:text-white transition disabled:opacity-20"
        >التالي</button>
      </div>

    </div>

    <!-- ══════════ PAYMENT MODAL ══════════ -->
    <Teleport to="body">
      <div v-if="showPaymentModal"
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        @click.self="closePaymentModal">
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-white/10 bg-[#0d0d0d] shadow-2xl" dir="rtl">
          <!-- Modal header -->
          <div class="flex items-center justify-between border-b border-white/5 bg-emerald-500/5 px-6 py-5">
            <h3 class="flex items-center gap-3 text-lg font-black text-emerald-400">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/15">
                <Wallet class="h-5 w-5"/>
              </div>
              تسديد دين شركة النقل
            </h3>
            <button type="button" @click="closePaymentModal"
              class="flex h-8 w-8 items-center justify-center rounded-lg text-white/30 hover:bg-white/10 hover:text-white transition">
              ✕
            </button>
          </div>

          <form @submit.prevent="submitPayment" class="space-y-5 p-6">
            <!-- Company + debt info -->
            <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.04] px-4 py-4">
              <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/15 text-sm font-black text-red-400">
                  {{ company?.name?.charAt(0) || '?' }}
                </div>
                <div>
                  <p class="text-[10px] text-white/30 uppercase tracking-wider">الشركة</p>
                  <p class="font-bold text-white">{{ company?.name }}</p>
                </div>
              </div>
              <div class="text-left">
                <p class="text-[10px] text-white/30 uppercase tracking-wider">الدين الحالي</p>
                <p class="font-mono text-lg font-black text-red-400">
                  {{ Number(Math.abs(company?.balance || 0)).toLocaleString('ar-EG') }} ج.م
                </p>
              </div>
            </div>

            <!-- Link to Booking (Optional) -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">ربط الدفع بحجز باص معين (اختياري)</label>
              <select v-model="paymentForm.booking_id" @change="onBookingChange"
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50">
                <option value="">— دفعة عامة بدون ربط بحجز —</option>
                <option v-for="b in companyBookings" :key="b.id" :value="b.id">
                  حجز #{{ b.id }} — العميل: {{ b.customer?.full_name || '—' }} — القيمة: {{ b.total_price }} ج.م
                </option>
              </select>
            </div>

            <!-- Source account -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">حساب الدفع <span class="text-red-400">*</span></label>
              <select v-model="paymentForm.from_account_id" required
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50">
                <option value="">— اختر حساب مصدر الدفع —</option>
                <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} — {{ acc.balance }} ج.م
                </option>
              </select>
            </div>

            <!-- Amount -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">المبلغ المراد تسديده <span class="text-red-400">*</span></label>
              <div class="relative">
                <input
                  v-model.number="paymentForm.amount"
                  type="number" step="0.01" required
                  :max="Math.abs(Number(company?.balance) || 0)"
                  class="w-full rounded-xl border border-white/15 bg-white/[0.05] py-3 pr-4 pl-16 font-mono text-white outline-none transition focus:border-emerald-500/50"
                />
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/30">ج.م</span>
              </div>
            </div>

            <!-- Notes -->
            <div>
              <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-white/40">ملاحظات</label>
              <input
                v-model="paymentForm.notes"
                type="text"
                placeholder="مثال: تسديد دفعة تذاكر مايو 2025"
                class="w-full rounded-xl border border-white/15 bg-white/[0.05] px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-500/50"
              />
            </div>

            <div class="flex gap-3 pt-2">
              <button type="submit" :disabled="submitting || !paymentForm.from_account_id || !paymentForm.amount"
                class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-black text-black shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40">
                <Loader2 v-if="submitting" class="h-4 w-4 animate-spin"/>
                <CheckCircle v-else class="h-4 w-4"/>
                {{ submitting ? 'جاري التسديد...' : 'تأكيد التسديد' }}
              </button>
              <button type="button" @click="closePaymentModal"
                class="rounded-xl border border-white/10 px-6 py-3 text-sm text-white/50 hover:bg-white/5 transition">
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
import { onMounted, ref, watch, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useBusStore } from '@/stores/busStore';
import { ArrowRight, Building2, Clock, RefreshCw, Wallet, Loader2, CheckCircle, Link } from 'lucide-vue-next';
import axios from 'axios';
const route = useRoute();
const store = useBusStore();

const company = ref(null);
const transactions = ref([]);
const meta = ref(null);
const loading = ref(true);
const company_account_id = ref(null);

const showPaymentModal  = ref(false);
const submitting        = ref(false);
const treasuryAccounts  = ref([]);
const companyBookings   = ref([]);
const paymentForm = ref({ amount: 0, from_account_id: '', notes: '', booking_id: '' });

const processedTransactions = computed(() => {
  const list = transactions.value;
  const companyAccId = company_account_id.value;
  if (!companyAccId) return list;

  // Step 1: Extract all available booking info from the list to map them
  const bookingMap = new Map();
  list.forEach(tx => {
    if (tx.related && tx.related.id) {
      bookingMap.set(Number(tx.related.id), tx.related);
    }
  });

  // Step 2: Map each transaction to parse booking ID from notes if empty, and resolve related booking info
  let mapped = list.map(tx => {
    let bookingId = tx.related_id ? Number(tx.related_id) : null;
    
    if (!bookingId && tx.notes) {
      // Try to parse booking ID from notes (e.g. "حجز #301", "حجز رقم 301", "باص #301")
      const match = tx.notes.match(/(?:حجز رقم|حجز #|باص #|رقم\s*)\s*(\d+)/);
      if (match) {
        bookingId = Number(match[1]);
      }
    }

    let related = tx.related;
    if (!related && bookingId && bookingMap.has(bookingId)) {
      related = bookingMap.get(bookingId);
    }

    return {
      ...tx,
      parsed_booking_id: bookingId,
      resolved_related: related
    };
  });

  // Step 3: Heuristic auto-link for unmatched payment rows that have the same amount as an invoice
  mapped.forEach(tx => {
    // If it is a payment (to company account) and has no booking ID
    if (tx.to_account_id === companyAccId && !tx.parsed_booking_id) {
      // Find an invoice (from company account) that has the same amount and has a parsed_booking_id, but hasn't been linked yet
      const matchingInvoice = mapped.find(other => 
        other.from_account_id === companyAccId && 
        Math.abs(Number(other.amount) - Number(tx.amount)) < 0.01 && 
        other.parsed_booking_id &&
        !mapped.some(p => p.to_account_id === companyAccId && p.id !== tx.id && p.parsed_booking_id === other.parsed_booking_id)
      );
      if (matchingInvoice) {
        tx.parsed_booking_id = matchingInvoice.parsed_booking_id;
        tx.resolved_related = matchingInvoice.resolved_related;
      }
    }
  });

  // Step 4: Identify which payment transactions to merge/hide from the list
  const mergedPaymentIds = new Set();
  mapped.forEach(tx => {
    if (tx.to_account_id === companyAccId && tx.parsed_booking_id) {
      // Check if the cost invoice is also on the page
      const hasCostTx = mapped.some(other => 
        other.from_account_id === companyAccId && 
        other.parsed_booking_id === tx.parsed_booking_id
      );
      if (hasCostTx) {
        mergedPaymentIds.add(tx.id);
      }
    }
  });

  // Step 5: Filter and map the final list
  return mapped.filter(tx => !mergedPaymentIds.has(tx.id)).map(tx => {
    const bookingId = tx.parsed_booking_id;
    if (bookingId && tx.from_account_id === companyAccId) {
      // Find the corresponding payment transaction in the full mapped list
      const payTx = mapped.find(other => 
        other.to_account_id === companyAccId && 
        other.parsed_booking_id === bookingId
      );
      if (payTx) {
        return {
          ...tx,
          is_paid: true,
          payment_date: payTx.created_at,
          payment_account_name: payTx.from_account?.name || '—',
          payment_tx_id: payTx.id,
          payment_by: payTx.createdBy?.name || 'النظام',
          payment_notes: payTx.notes,
          linked_tx_id: payTx.id
        };
      }
    }
    return {
      ...tx,
      is_paid: false
    };
  });
});

const loadAccounts = async () => {
  try {
    const res = await axios.get('/api/v1/finance/accounts', { params: { per_page: 200 } });
    let raw = res.data?.data;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      if (Array.isArray(raw.items)) {
        raw = raw.items;
      } else if (raw.items && Array.isArray(raw.items.data)) {
        raw = raw.items.data;
      } else if (Array.isArray(raw.data)) {
        raw = raw.data;
      }
    }
    const all = Array.isArray(raw) ? raw : [];
    treasuryAccounts.value = all.filter(a => {
      const t = String(a.type?.value || a.type || '').toLowerCase();
      return ['cashbox', 'bank', 'wallet', 'treasury'].includes(t);
    });
  } catch (e) { console.error('loadAccounts', e); }
};

const fetchCompanyBookings = async () => {
  try {
    const res = await axios.get('/api/v1/bus/bookings', {
      params: { company_id: route.params.id, per_page: 500 }
    });
    let raw = res.data?.data;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      if (Array.isArray(raw.items)) raw = raw.items;
      else if (raw.items && Array.isArray(raw.items.data)) raw = raw.items.data;
      else if (Array.isArray(raw.data)) raw = raw.data;
    }
    companyBookings.value = Array.isArray(raw) ? raw.filter(b => b.status !== 'cancelled') : [];
  } catch (e) {
    console.error('fetchCompanyBookings', e);
  }
};

const openPaymentModal = async () => {
  await Promise.all([loadAccounts(), fetchCompanyBookings()]);
  paymentForm.value = {
    amount: Math.abs(Number(company.value?.balance) || 0),
    from_account_id: '',
    notes: '',
    booking_id: '',
  };
  showPaymentModal.value = true;
};

const closePaymentModal = () => {
  showPaymentModal.value = false;
};

const onBookingChange = () => {
  const selected = companyBookings.value.find(b => b.id === paymentForm.value.booking_id);
  if (selected) {
    const ticketCost = Number(selected.inventory?.cost_per_ticket || selected.unit_price || 0);
    const qty = Number(selected.quantity || 0);
    paymentForm.value.amount = ticketCost * qty;
    paymentForm.value.notes = `تسديد تكلفة حجز باص #${selected.id} — العميل: ${selected.customer?.full_name || '—'}`;
  } else {
    paymentForm.value.amount = Math.abs(Number(company.value?.balance) || 0);
    paymentForm.value.notes = '';
  }
};

const submitPayment = async () => {
  if (!paymentForm.value.from_account_id) {
    store.addToast('يرجى اختيار حساب مصدر الدفع', 'error');
    return;
  }
  submitting.value = true;
  try {
    await store.payCompanyDebt(company.value.id, {
      amount: paymentForm.value.amount,
      from_account_id: paymentForm.value.from_account_id,
      notes: paymentForm.value.notes || 'تسديد دين شركة باصات',
      booking_id: paymentForm.value.booking_id || null,
    });
    store.addToast('تم تسديد الدين بنجاح ✓', 'success');
    closePaymentModal();
    await loadPage(meta.value?.current_page || 1);
  } catch (e) {
    store.addToast('فشل التسديد. تحقق من الرصيد المتاح.', 'error');
  } finally {
    submitting.value = false;
  }
};

const formatDt = (iso) => {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return iso;
  }
};

const loadPage = async (page = 1) => {
  loading.value = true;
  try {
    const res = await store.fetchCompanyBusStatement(route.params.id, { page });
    
    company.value = res?.company || null;
    transactions.value = res?.transactions?.data || [];
    meta.value = {
      current_page: res?.transactions?.current_page || 1,
      last_page: res?.transactions?.last_page || 1
    };

    if (!company_account_id.value) {
        const fullComp = await axios.get(`/api/v1/bus/companies/${route.params.id}`);
        company_account_id.value = fullComp.data?.data?.account_id || null;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};
const reload = () => loadPage(meta.value?.current_page || 1);

onMounted(() => {
  loadPage();
});
</script>
