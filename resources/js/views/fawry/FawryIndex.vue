<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          معاملات فوري
        </h1>
        <p class="text-text-muted mt-1">
          إدارة ومتابعة معاملات فوري
        </p>
      </div>
      <div class="flex gap-3">
        <button
          @click="exportReport"
          class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold flex items-center justify-center gap-2 transition-all"
        >
          <Download class="w-5 h-5" />
          تصدير تقرير
        </button>
        <router-link
          to="/fawry/create"
          class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
        >
          <Plus class="w-5 h-5" />
          معاملة جديدة
        </router-link>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Total Transactions -->
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-gold/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-gold/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-gold/10 rounded-xl text-gold group-hover:scale-110 transition-transform">
            <CreditCard class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-gold/10 text-gold">
            إجمالي
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي المعاملات</div>
          <div class="text-2xl font-bold font-mono group-hover:text-gold transition-colors">
            {{ store.stats.total_transactions }}
          </div>
        </div>
      </div>

      <!-- Today Transactions -->
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-info/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-info/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-info/10 rounded-xl text-info group-hover:scale-110 transition-transform">
            <Calendar class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-info/10 text-info">
            اليوم
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">معاملات اليوم</div>
          <div class="text-2xl font-bold font-mono group-hover:text-info transition-colors">
            {{ store.stats.today_transactions }}
          </div>
        </div>
      </div>

      <!-- Total Revenue -->
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <Banknote class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">
            إيرادات
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الإيرادات</div>
          <div class="text-2xl font-bold font-mono group-hover:text-success transition-colors">
            {{ formatCurrency(store.stats.total_revenue) }}
          </div>
        </div>
      </div>

      <!-- Total Profit -->
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-purple/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-purple/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-purple/10 rounded-xl text-purple group-hover:scale-110 transition-transform">
            <TrendingUp class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-purple/10 text-purple">
            أرباح
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">إجمالي الأرباح</div>
          <div class="text-2xl font-bold font-mono group-hover:text-purple transition-colors">
            {{ formatCurrency(store.stats.total_profit) }}
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
        <!-- Search -->
        <div class="lg:col-span-2">
          <label class="block text-sm font-medium text-text-muted mb-2">بحث</label>
          <div class="relative">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-text-muted" />
            <input
              v-model="store.filters.search"
              type="text"
              placeholder="اسم العميل، رقم المرجع..."
              class="w-full pr-10 pl-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
            />
          </div>
        </div>

        <!-- Operation Type -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">نوع العملية</label>
          <select
            v-model="store.filters.operation_type"
            class="form-select-dark py-2.5"
          >
            <option value="">الكل</option>
            <option v-for="type in store.operationTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
        </div>

        <!-- Payment Method -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">طريقة الدفع</label>
          <select
            v-model="store.filters.payment_method"
            class="form-select-dark py-2.5"
          >
            <option value="">الكل</option>
            <option v-for="method in store.paymentMethods" :key="method.value" :value="method.value">
              {{ method.label }}
            </option>
          </select>
        </div>

        <!-- Date From -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">من تاريخ</label>
          <input
            v-model="store.filters.date_from"
            type="date"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
          />
        </div>

        <!-- Date To -->
        <div>
          <label class="block text-sm font-medium text-text-muted mb-2">إلى تاريخ</label>
          <input
            v-model="store.filters.date_to"
            type="date"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-gold/50 focus:border-gold transition-all"
          />
        </div>
      </div>

      <!-- Filter Actions -->
      <div class="flex gap-3 mt-4">
        <button
          @click="applyFilters"
          class="px-6 py-2.5 bg-gold hover:bg-gold/90 text-black rounded-xl font-semibold transition-all"
        >
          تطبيق الفلاتر
        </button>
        <button
          @click="resetFilters"
          class="px-6 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
        >
          إعادة تعيين
        </button>
      </div>
    </div>

    <!-- Transactions Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b border-white/10 bg-white/5">
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">العميل</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">نوع العملية</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">المبلغ</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">الربح</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">طريقة الدفع</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">التاريخ</th>
              <th class="px-6 py-4 text-right text-sm font-semibold text-text-muted uppercase tracking-wider">إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="transaction in store.filteredTransactions"
              :key="transaction.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors"
            >
              <td class="px-6 py-4">
                <div class="font-semibold text-text-main">{{ transaction.client_name }}</div>
                <div class="text-sm text-text-muted">{{ transaction.reference_number }}</div>
              </td>
              <td class="px-6 py-4">
                <span
                  :class="[
                    'px-3 py-1 rounded-full text-xs font-semibold',
                    getOperationTypeBadgeClass(transaction.operation_type)
                  ]"
                >
                  {{ transaction.operation_type_label || store.getOperationTypeLabel(transaction.operation_type) }}
                </span>
              </td>
              <td class="px-6 py-4 font-mono font-semibold text-text-main">
                {{ formatCurrency(transaction.selling_price) }}
              </td>
              <td class="px-6 py-4 font-mono font-semibold text-success">
                +{{ formatCurrency(transaction.profit) }}
              </td>
              <td class="px-6 py-4">
                <span
                  :class="[
                    'px-3 py-1 rounded-full text-xs font-semibold',
                    getPaymentMethodBadgeClass(transaction.payment_method)
                  ]"
                >
                  {{ transaction.payment_method_label || store.getPaymentMethodLabel(transaction.payment_method) }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-text-muted">
                {{ formatDate(transaction.created_at) }}
              </td>
              <td class="px-6 py-4">
                <div class="flex gap-2">
                  <router-link
                    :to="`/fawry/${transaction.id}`"
                    class="p-2 bg-info/10 hover:bg-info/20 text-info rounded-lg transition-colors"
                    title="عرض التفاصيل"
                  >
                    <Eye class="w-4 h-4" />
                  </router-link>
                  <router-link
                    :to="`/fawry/${transaction.id}/edit`"
                    class="p-2 bg-warning/10 hover:bg-warning/20 text-warning rounded-lg transition-colors"
                    title="تعديل"
                  >
                    <Pencil class="w-4 h-4" />
                  </router-link>
                  <button
                    @click="confirmDelete(transaction)"
                    class="p-2 bg-error/10 hover:bg-error/20 text-error rounded-lg transition-colors"
                    title="حذف"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Empty State -->
      <div v-if="store.filteredTransactions.length === 0" class="text-center py-16">
        <CreditCard class="w-16 h-16 mx-auto text-text-muted/30 mb-4" />
        <h3 class="text-xl font-semibold text-text-main mb-2">لا توجد معاملات</h3>
        <p class="text-text-muted mb-6">ابدأ بإنشاء معاملة فورية جديدة</p>
        <router-link
          to="/fawry/create"
          class="inline-flex items-center gap-2 px-6 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-semibold transition-all"
        >
          <Plus class="w-5 h-5" />
          معاملة جديدة
        </router-link>
      </div>

      <!-- Pagination -->
      <div v-if="store.pagination.total > store.pagination.per_page" class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <div class="text-sm text-text-muted">
          عرض {{ (store.pagination.current_page - 1) * store.pagination.per_page + 1 }}
          إلى {{ Math.min(store.pagination.current_page * store.pagination.per_page, store.pagination.total) }}
          من {{ store.pagination.total }} معاملة
        </div>
        <div class="flex gap-2">
          <button
            @click="goToPage(store.pagination.current_page - 1)"
            :disabled="store.pagination.current_page === 1"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            السابق
          </button>
          <button
            @click="goToPage(store.pagination.current_page + 1)"
            :disabled="store.pagination.current_page === store.pagination.last_page"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            التالي
          </button>
        </div>
      </div>
    </div>

    <FawryApiResponsePanel
      :envelope="store.lastApiEnvelope"
      @clear="store.clearLastApiEnvelope()"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useFawryStore } from '@/stores/fawryStore';
import FawryApiResponsePanel from '@/views/fawry/FawryApiResponsePanel.vue';
import {
  CreditCard,
  Plus,
  Download,
  Search,
  Calendar,
  Banknote,
  TrendingUp,
  Eye,
  Pencil,
  Trash2,
} from 'lucide-vue-next';

const store = useFawryStore();

// Methods
const formatCurrency = (amount) => {
  const n = Number(amount) || 0;
  return `${n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ج.م`;
};

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const getOperationTypeBadgeClass = (type) => {
  const classes = {
    withdrawal: 'bg-error/10 text-error',
    deposit: 'bg-success/10 text-success',
    payment: 'bg-info/10 text-info',
    travel_permit: 'bg-warning/10 text-warning',
  };
  return classes[type] || 'bg-gray/10 text-gray';
};

const getPaymentMethodBadgeClass = (method) => {
  const classes = {
    cash: 'bg-success/10 text-success',
    bank_transfer: 'bg-info/10 text-info',
    cash_wallet: 'bg-purple/10 text-purple',
    office_safe: 'bg-warning/10 text-warning',
    office_drawer: 'bg-gray/10 text-gray',
  };
  return classes[method] || 'bg-gray/10 text-gray';
};

const applyFilters = async () => {
  await store.fetchTransactions();
};

const resetFilters = () => {
  store.filters = {
    search: '',
    operation_type: '',
    payment_method: '',
    employee_id: '',
    date_from: '',
    date_to: '',
    page: 1,
    per_page: 15,
  };
  store.fetchTransactions();
};

const goToPage = (page) => {
  store.filters.page = page;
  store.fetchTransactions();
};

const confirmDelete = (transaction) => {
  if (confirm(`هل أنت متأكد من حذف معاملة "${transaction.client_name}"؟`)) {
    store.deleteTransaction(transaction.id);
  }
};

const exportReport = () => {
  // TODO: Implement export functionality
  alert('سيتم تنفيذ تصدير التقرير قريباً');
};

// Lifecycle
onMounted(async () => {
  // Fetch settings first (payment methods, operation types)
  await store.fetchSettings();
  // Then fetch transactions
  store.fetchTransactions();
});
</script>
