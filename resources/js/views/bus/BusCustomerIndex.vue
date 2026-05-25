<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          عملاء الباصات (الآجل والمتبقي)
        </h1>
        <p class="text-text-muted mt-1">
          قائمة بالعملاء الذين قاموا بحجوزات باصات مع إجمالي المسحوبات والمدفوع والمديونية (الآجل) المتبقية.
        </p>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex items-center gap-4">
      <div class="flex-1 relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="search"
          type="text"
          placeholder="ابحث بالاسم أو رقم الهاتف..."
          class="w-full pl-10 pr-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm text-text-main"
          @input="debouncedSearch"
        />
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-right border-collapse" dir="rtl">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold text-right">العميل</th>
              <th class="px-6 py-4 font-semibold text-right">رقم الهاتف</th>
              <th class="px-6 py-4 font-semibold text-center">عدد الحجوزات</th>
              <th class="px-6 py-4 font-semibold text-left">إجمالي المبيعات</th>
              <th class="px-6 py-4 font-semibold text-left">إجمالي المدفوع</th>
              <th class="px-6 py-4 font-semibold text-left text-error">الآجل (المديونية)</th>
              <th class="px-6 py-4 font-semibold text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading" class="border-b border-white/5">
              <td colspan="7" class="px-6 py-10 text-center text-text-muted">
                جاري التحميل...
              </td>
            </tr>
            <tr v-else-if="customers.length === 0" class="border-b border-white/5">
              <td colspan="7" class="px-6 py-10 text-center text-text-muted">
                لا يوجد عملاء مطابقين للبحث.
              </td>
            </tr>
            <tr
              v-else
              v-for="customer in customers"
              :key="customer.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors group"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="font-semibold text-sm text-text-main">{{ customer.full_name }}</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-sm text-text-muted">{{ customer.phone || '—' }}</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-center">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-500/10 text-blue-400">
                  {{ customer.total_bus_bookings }} رحلات
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-left">
                <span class="font-mono text-sm text-text-main">{{ formatCurrency(customer.total_bus_amount) }}</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-left">
                <span class="font-mono text-sm text-success">{{ formatCurrency(customer.total_bus_paid) }}</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-left">
                <span class="font-mono text-sm font-bold text-error">{{ formatCurrency(customer.bus_remaining_debt) }}</span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-left text-sm font-medium">
                <router-link 
                  :to="{ name: 'bus.list', query: { search: customer.phone } }" 
                  class="text-gold hover:underline"
                >
                  عرض الحجوزات
                </router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="!loading && totalPages > 1"
        class="flex flex-col gap-3 border-t border-white/10 bg-white/5 px-6 py-4 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between"
      >
        <div>
          عرض {{ pageFrom }}–{{ pageTo }} من {{ totalItems }} عميل
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="rounded-lg border border-white/10 px-3 py-1.5 font-semibold hover:border-gold/40 disabled:opacity-40"
            :disabled="currentPage <= 1 || loading"
            @click="prevPage"
          >
            السابق
          </button>
          <span class="px-2 font-mono text-xs">
            {{ currentPage }} / {{ totalPages || 1 }}
          </span>
          <button
            type="button"
            class="rounded-lg border border-white/10 px-3 py-1.5 font-semibold hover:border-gold/40 disabled:opacity-40"
            :disabled="currentPage >= totalPages || loading"
            @click="nextPage"
          >
            التالي
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/utils/api'
import { Search } from 'lucide-vue-next'

const customers = ref([])
const loading = ref(true)
const search = ref('')

const currentPage = ref(1)
const totalPages = ref(1)
const totalItems = ref(0)
const perPage = ref(30)

let searchTimeout = null

const pageFrom = computed(() => {
  if (!totalItems.value) return 0
  return (currentPage.value - 1) * perPage.value + 1
})

const pageTo = computed(() =>
  Math.min(currentPage.value * perPage.value, totalItems.value)
)

const fetchCustomers = async (page = 1) => {
  loading.value = true
  try {
    const response = await api.get('/bus/customers', {
      params: {
        page,
        per_page: perPage.value,
        search: search.value || undefined
      }
    })
    
    customers.value = response.data.data.customers.data
    currentPage.value = response.data.data.customers.current_page
    totalPages.value = response.data.data.customers.last_page
    totalItems.value = response.data.data.customers.total
  } catch (error) {
    console.error('Failed to fetch bus customers:', error)
  } finally {
    loading.value = false
  }
}

const debouncedSearch = () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    fetchCustomers(1)
  }, 500)
}

const prevPage = () => {
  if (currentPage.value > 1) {
    fetchCustomers(currentPage.value - 1)
  }
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    fetchCustomers(currentPage.value + 1)
  }
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP' }).format(value || 0)
}

onMounted(() => {
  fetchCustomers()
})
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.bg-input-bg { background-color: var(--input-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning); }
.text-blue-500 { color: #4F8EF7; }
.bg-success { background-color: var(--success); }
.bg-error { background-color: var(--error); }
.bg-warning { background-color: var(--warning); }
.font-mono { font-family: 'IBM Plex Sans Arabic', sans-serif; }
.font-display { font-family: 'IBM Plex Sans Arabic', sans-serif; }
</style>
