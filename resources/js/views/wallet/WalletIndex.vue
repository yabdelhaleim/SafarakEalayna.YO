<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          المحافظ والتحويلات
        </h1>
        <p class="text-text-muted mt-1">
          إدارة عمليات المحافظ الإلكترونية
        </p>
      </div>
      <router-link
        to="/wallet/create"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <Plus class="w-5 h-5" />
        عملية جديدة
      </router-link>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-gold/10 rounded-xl text-gold group-hover:scale-110 transition-transform">
            <Layers class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-gold/10 text-gold">إجمالي</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">العمليات</div>
          <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">
            {{ stats.total_transactions }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-warning/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-warning/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-warning/10 rounded-xl text-warning group-hover:scale-110 transition-transform">
            <ArrowUpCircle class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-warning/10 text-warning">إرسال</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">عدد الإرسال</div>
          <div class="text-2xl font-bold font-mono group-hover:text-warning transition-colors">
            {{ stats.send_count }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <ArrowDownCircle class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">استقبال</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">عدد الاستقبال</div>
          <div class="text-2xl font-bold font-mono group-hover:text-success transition-colors">
            {{ stats.receive_count }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-info/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-info/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-info/10 rounded-xl text-info group-hover:scale-110 transition-transform">
            <TrendingUp class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-info/10 text-info">مرسل</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">مجموع المرسل</div>
          <div class="text-lg font-bold font-mono group-hover:text-info transition-colors">
            {{ formatCurrency(stats.total_sent) }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-purple/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-purple/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-purple/10 rounded-xl text-purple group-hover:scale-110 transition-transform">
            <Banknote class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-purple/10 text-purple">مستقبل</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">مجموع المستقبل</div>
          <div class="text-lg font-bold font-mono group-hover:text-purple transition-colors">
            {{ formatCurrency(stats.total_received) }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <Wallet class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">خدمات</span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الخدمات</div>
          <div class="text-lg font-bold font-mono group-hover:text-success transition-colors">
            {{ formatCurrency(stats.total_fees) }}
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="lg:col-span-2">
          <label class="block text-sm font-medium text-text-muted mb-2">بحث</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-text-muted" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="اسم العميل أو رقم المحفظة..."
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
              @input="debouncedFetch"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">نوع العملية</label>
          <select v-model="filters.type" class="form-select-dark py-2.5" @change="applyFilters">
            <option value="">كل العمليات</option>
            <option value="send">إرسال رصيد</option>
            <option value="receive">استقبال رصيد</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">نوع المحفظة</label>
          <select v-model="filters.wallet_type_id" class="form-select-dark py-2.5" @change="applyFilters">
            <option value="">كل المحافظ</option>
            <option v-for="wt in walletTypes" :key="wt.id" :value="wt.id">{{ wt.name }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">من</label>
            <input
              v-model="filters.from_date"
              type="date"
              class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm"
              @change="applyFilters"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-text-muted mb-2">إلى</label>
            <input
              v-model="filters.to_date"
              type="date"
              class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all text-sm"
              @change="applyFilters"
            />
          </div>
        </div>
      </div>
      <div class="flex gap-3 mt-4">
        <button
          type="button"
          class="px-6 py-2.5 bg-gold hover:bg-gold/90 text-black rounded-xl font-semibold transition-all"
          @click="applyFilters"
        >
          تطبيق الفلاتر
        </button>
        <button
          type="button"
          class="px-6 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
          @click="resetAndFetch"
        >
          إعادة تعيين
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div v-if="loading.transactions" class="flex items-center justify-center py-20">
        <Loader2 class="w-10 h-10 text-gold animate-spin" />
      </div>

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-white/10 bg-white/5">
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">#</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">النوع</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">المحفظة</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">العميل</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">الرقم</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">المبلغ</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">الخدمة</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">الإجمالي</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">التاريخ</th>
                <th class="px-5 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="tx in transactions"
                :key="tx.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors"
              >
                <td class="px-5 py-4 text-sm text-text-muted font-mono">{{ tx.id }}</td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'px-3 py-1 rounded-full text-xs font-semibold inline-flex items-center gap-1.5',
                      getTypeBadgeClass(tx.type),
                    ]"
                  >
                    <component :is="tx.type === 'send' ? ArrowUpCircle : ArrowDownCircle" class="w-3.5 h-3.5" />
                    {{ tx.type_label }}
                  </span>
                </td>
                <td class="px-5 py-4">
                  <span class="px-3 py-1 rounded-full text-xs font-semibold bg-info/10 text-info">
                    {{ tx.wallet_type?.name ?? '—' }}
                  </span>
                </td>
                <td class="px-5 py-4 font-semibold text-text-main">{{ tx.customer_name }}</td>
                <td class="px-5 py-4 text-sm text-text-muted font-mono">{{ tx.wallet_number }}</td>
                <td class="px-5 py-4 font-mono font-semibold text-text-main">{{ formatCurrency(tx.amount) }}</td>
                <td class="px-5 py-4 font-mono font-semibold text-success">+{{ formatCurrency(tx.service_fee) }}</td>
                <td class="px-5 py-4 font-mono font-bold text-text-main">{{ formatCurrency(tx.total_amount) }}</td>
                <td class="px-5 py-4 text-sm text-text-muted">{{ formatDateTime(tx.created_at) }}</td>
                <td class="px-5 py-4">
                  <button
                    type="button"
                    class="p-2 bg-error/10 hover:bg-error/20 text-error rounded-lg transition-colors"
                    title="حذف"
                    @click="confirmDelete(tx)"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="transactions.length === 0" class="text-center py-16 border-t border-white/5">
          <Wallet class="w-16 h-16 mx-auto text-text-muted/30 mb-4" />
          <h3 class="text-xl font-semibold text-text-main mb-2">لا توجد عمليات</h3>
          <p class="text-text-muted mb-6">ابدأ بتسجيل عملية محفظة جديدة</p>
          <router-link
            to="/wallet/create"
            class="inline-flex items-center gap-2 px-6 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-semibold transition-all"
          >
            <Plus class="w-5 h-5" />
            عملية جديدة
          </router-link>
        </div>

        <div
          v-if="pagination.last_page > 1"
          class="px-6 py-4 border-t border-white/10 flex items-center justify-between flex-wrap gap-3"
        >
          <div class="text-sm text-text-muted">
            عرض {{ (pagination.current_page - 1) * pagination.per_page + 1 }}
            إلى {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}
            من {{ pagination.total }} عملية
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              :disabled="pagination.current_page === 1"
              class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              @click="goToPage(pagination.current_page - 1)"
            >
              السابق
            </button>
            <button
              type="button"
              :disabled="pagination.current_page === pagination.last_page"
              class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              @click="goToPage(pagination.current_page + 1)"
            >
              التالي
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- Delete modal -->
    <div
      v-if="deletingTx"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="deletingTx = null"
    >
      <div class="bg-card-bg border border-white/10 rounded-2xl shadow-2xl p-8 w-full max-w-md">
        <div class="text-center mb-6">
          <div class="w-14 h-14 bg-error/15 rounded-full flex items-center justify-center mx-auto mb-4">
            <Trash2 class="w-7 h-7 text-error" />
          </div>
          <h3 class="text-lg font-bold text-text-main mb-2">تأكيد الحذف</h3>
          <p class="text-sm text-text-muted leading-relaxed">
            هل أنت متأكد من حذف عملية
            <strong class="text-text-main">{{ deletingTx?.type_label }}</strong>
            بمبلغ <strong class="text-text-main">{{ formatCurrency(deletingTx?.amount) }}</strong>؟
            <br />
            سيتم عكس القيود المحاسبية تلقائياً.
          </p>
        </div>
        <div class="flex gap-3">
          <button
            type="button"
            :disabled="loading.delete"
            class="flex-1 py-2.5 bg-error hover:bg-error/90 text-white rounded-xl font-semibold disabled:opacity-60 transition-all"
            @click="executeDelete"
          >
            {{ loading.delete ? 'جاري الحذف...' : 'نعم، احذف' }}
          </button>
          <button
            type="button"
            class="flex-1 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 text-text-main rounded-xl font-semibold transition-all"
            @click="deletingTx = null"
          >
            إلغاء
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useWalletStore } from '@/stores/walletStore';
import {
  Plus,
  Search,
  Layers,
  ArrowUpCircle,
  ArrowDownCircle,
  TrendingUp,
  Banknote,
  Wallet,
  Trash2,
  Loader2,
} from 'lucide-vue-next';

const store = useWalletStore();
const { transactions, walletTypes, stats, loading, filters, pagination } = storeToRefs(store);

const deletingTx = ref(null);
let debounceTimer = null;

onMounted(async () => {
  await Promise.all([store.fetchWalletTypes(), store.fetchTransactions(), store.fetchDailySummary()]);
});

function debouncedFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(applyFilters, 400);
}

async function applyFilters() {
  store.filters.page = 1;
  await store.fetchTransactions();
}

async function resetAndFetch() {
  store.resetFilters();
  await store.fetchTransactions();
  await store.fetchDailySummary();
}

async function goToPage(page) {
  store.filters.page = page;
  await store.fetchTransactions();
}

function confirmDelete(tx) {
  deletingTx.value = tx;
}

async function executeDelete() {
  if (!deletingTx.value) return;
  try {
    await store.deleteTransaction(deletingTx.value.id);
    deletingTx.value = null;
    await store.fetchDailySummary();
  } catch {
    //
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
  }).format(Number(amount) || 0);
}

function formatDateTime(dateString) {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function getTypeBadgeClass(type) {
  if (type === 'send') return 'bg-warning/10 text-warning';
  if (type === 'receive') return 'bg-success/10 text-success';
  return 'bg-white/10 text-text-muted';
}
</script>
