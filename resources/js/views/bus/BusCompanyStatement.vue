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
        <div v-if="company" class="flex flex-col items-end">
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
      <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.02]">
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
            <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-white/[0.03] transition-colors">
              <td class="px-5 py-4 font-mono text-xs text-white/40">{{ formatDt(tx.created_at) }}</td>
              <td class="px-5 py-4">
                <span :class="[tx.to_account_id === company_account_id ? 'text-emerald-400' : 'text-red-400', 'text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-current/10']">
                  {{ tx.to_account_id === company_account_id ? 'إيداع / تسديد' : 'سحب / فاتورة' }}
                </span>
              </td>
              <td class="px-5 py-4 font-mono font-black text-white tabular-nums text-sm">
                {{ Number(tx.amount).toLocaleString('ar-EG') }}
              </td>
              <td class="px-5 py-4 text-white/60 text-xs">
                {{ tx.from_account_id === company_account_id ? (tx.to_account?.name || '—') : (tx.from_account?.name || '—') }}
              </td>
              <td class="px-5 py-4 text-white/40 text-[11px]">{{ tx.created_by?.name || 'النظام' }}</td>
              <td class="px-5 py-4 text-white/30 text-[11px] max-w-[200px] truncate">{{ tx.notes || '—' }}</td>
            </tr>
            <tr v-if="!transactions.length && !loading">
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
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useBusStore } from '@/stores/busStore';
import { ArrowRight, Building2, Clock, RefreshCw } from 'lucide-vue-next';
import axios from 'axios';
const route = useRoute();
const store = useBusStore();

const company = ref(null);
const transactions = ref([]);
const meta = ref(null);
const loading = ref(true);
const company_account_id = ref(null);

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
    
    // استخدام علامة الاستفهام ? يمنع انهيار الصفحة إذا كانت البيانات ناقصة
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
