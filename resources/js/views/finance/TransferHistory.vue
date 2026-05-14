<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          سجل التحويلات
        </h1>
        <p class="text-text-muted mt-1">
          عرض جميع عمليات التحويل بين الحسابات
        </p>
      </div>
      <router-link
        to="/finance/transfers/create"
        class="px-4 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all flex items-center gap-2"
      >
        <ArrowRightLeft class="w-4 h-4" />
        تحويل جديد
      </router-link>
    </div>

    <!-- Filters -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-6">
      <div class="flex flex-col md:flex-row gap-4">
        <div class="flex-1">
          <label class="block text-sm font-semibold text-text-main mb-2">من تاريخ</label>
          <input
            v-model="filters.from_date"
            type="date"
            class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          />
        </div>
        <div class="flex-1">
          <label class="block text-sm font-semibold text-text-main mb-2">إلى تاريخ</label>
          <input
            v-model="filters.to_date"
            type="date"
            class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          />
        </div>
        <div class="flex-1">
          <label class="block text-sm font-semibold text-text-main mb-2">حساب المرسل</label>
          <select
            v-model="filters.from_account_id"
            class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          >
            <option value="">جميع الحسابات</option>
            <option
              v-for="account in accounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.name }}
            </option>
          </select>
        </div>
        <div class="flex-1">
          <label class="block text-sm font-semibold text-text-main mb-2">حساب المستلم</label>
          <select
            v-model="filters.to_account_id"
            class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
          >
            <option value="">جميع الحسابات</option>
            <option
              v-for="account in accounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.name }}
            </option>
          </select>
        </div>
        <div class="flex items-end">
          <button
            @click="fetchTransfers"
            class="px-6 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all flex items-center gap-2"
          >
            <Search class="w-4 h-4" />
            بحث
          </button>
        </div>
      </div>
    </div>

    <!-- Transfers Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-4 py-4 font-semibold">التاريخ</th>
              <th class="px-4 py-4 font-semibold">الحساب المرسل</th>
              <th class="px-4 py-4 font-semibold">الحساب المستلم</th>
              <th class="px-4 py-4 font-semibold">المبلغ</th>
              <th class="px-4 py-4 font-semibold">البيان</th>
              <th class="px-4 py-4 font-semibold">المستخدم</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.transfers">
              <tr v-for="i in 10" :key="i" class="border-b border-white/5">
                <td v-for="j in 6" :key="j" class="px-4 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="transfers.length > 0">
              <tr
                v-for="item in transfers"
                :key="item.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors"
              >
                <td class="px-4 py-3 text-sm">{{ formatDate(item.created_at) }}</td>
                <td class="px-4 py-3">
                  <span class="text-error font-medium">{{ item.from_account?.name || '---' }}</span>
                </td>
                <td class="px-4 py-3">
                  <span class="text-success font-medium">{{ item.to_account?.name || '---' }}</span>
                </td>
                <td class="px-4 py-3 font-mono font-bold text-gold">
                  {{ item.amount?.toLocaleString() || 0 }}
                </td>
                <td class="px-4 py-3 text-sm text-text-muted">{{ item.notes || '-' }}</td>
                <td class="px-4 py-3 text-sm">{{ item.createdBy?.name || '---' }}</td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="6" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center">
                    <ArrowRightLeft class="w-8 h-8 text-white/10" />
                  </div>
                  <p class="text-text-muted text-sm">لا توجد عمليات تحويل حتى الآن</p>
                  <router-link
                    to="/finance/transfers/create"
                    class="px-4 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold text-sm transition-all"
                  >
                    إنشاء أول تحويل
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total > pagination.per_page" class="p-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-sm text-text-muted">
          صفحة {{ pagination.current_page }} من {{ pagination.last_page }}
        </p>
        <div class="flex items-center gap-2">
          <button
            @click="prevPage"
            :disabled="pagination.current_page === 1"
            class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-sm font-semibold transition-all disabled:opacity-50"
          >
            السابق
          </button>
          <button
            @click="nextPage"
            :disabled="pagination.current_page === pagination.last_page"
            class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-sm font-semibold transition-all disabled:opacity-50"
          >
            التالي
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useFinanceStore } from '@/stores/financeStore'
import axios from 'axios'
import { ArrowRightLeft, Search } from 'lucide-vue-next'

const store = useFinanceStore()
const toast = useToast()

const transfers = ref([])
const accounts = ref([])
const pagination = ref({
  total: 0,
  current_page: 1,
  last_page: 1,
  per_page: 20,
})

const filters = ref({
  from_date: '',
  to_date: '',
  from_account_id: '',
  to_account_id: '',
})

const fetchTransfers = async () => {
  try {
    store.loading.transfers = true
    const params = {
      ...filters.value,
      page: pagination.value.current_page,
      per_page: pagination.value.per_page,
    }
    const response = await axios.get('/api/v1/finance/transfers', { params })
    const data = response.data
    transfers.value = Array.isArray(data) ? data : data.data || []
    if (data.pagination) {
      pagination.value = { ...data.pagination }
    }
  } catch (error) {
    console.error('Failed to fetch transfers:', error)
  } finally {
    store.loading.transfers = false
  }
}

const fetchAccounts = async () => {
  if (store.accounts.length === 0) {
    await store.fetchAccounts()
  }
  accounts.value = store.accounts
}

const prevPage = () => {
  if (pagination.value.current_page > 1) {
    pagination.value.current_page--
    fetchTransfers()
  }
}

const nextPage = () => {
  if (pagination.value.current_page < pagination.value.last_page) {
    pagination.value.current_page++
    fetchTransfers()
  }
}

const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return date.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// Watchers
watch(
  filters,
  () => {
    pagination.value.current_page = 1
    fetchTransfers()
  },
  { deep: true }
)

// Lifecycle
onMounted(async () => {
  await fetchAccounts()
  await fetchTransfers()
})
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}

.bg-input-bg {
  background-color: var(--input-bg);
}

.text-text-main {
  color: var(--text-main);
}

.text-text-muted {
  color: var(--text-muted);
}

.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
