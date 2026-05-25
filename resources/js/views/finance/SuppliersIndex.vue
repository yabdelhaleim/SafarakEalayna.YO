<template>
  <div class="space-y-6 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-extrabold text-text-main tracking-tight">الموردون</h1>
        <p class="text-text-muted mt-1">إدارة بيانات الموردين والمديونيات</p>
      </div>
      <button @click="showModal = true; resetForm()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-l from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/20 transition-all">
        <Plus class="w-5 h-5" />
        إضافة مورد
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-card-bg border border-white/10 rounded-2xl p-5">
        <p class="text-text-muted text-sm mb-1">إجمالي الموردين</p>
        <p class="text-2xl font-bold text-text-main">{{ store.suppliers.length }}</p>
      </div>
      <div class="bg-card-bg border border-white/10 rounded-2xl p-5">
        <p class="text-text-muted text-sm mb-1">النشطين</p>
        <p class="text-2xl font-bold text-green-400">{{ store.activeSuppliers.length }}</p>
      </div>
      <div class="bg-card-bg border border-white/10 rounded-2xl p-5">
        <p class="text-text-muted text-sm mb-1">إجمالي المديونية</p>
        <p class="text-2xl font-bold text-red-400">{{ formatCurrency(store.totalDebt) }}</p>
      </div>
      <div class="bg-card-bg border border-white/10 rounded-2xl p-5">
        <p class="text-text-muted text-sm mb-1">مدينون</p>
        <p class="text-2xl font-bold text-amber-400">{{ store.suppliersWithDebt.length }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-4">
      <div class="flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
          <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
          <input v-model="search" @input="debouncedSearch" type="text" placeholder="بحث بالاسم أو الكود..."
            class="w-full pr-10 pl-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main placeholder-text-muted focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition" />
        </div>
        <select v-model="typeFilter" @change="applyFilters"
          class="px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main focus:border-blue-500 outline-none">
          <option value="">كل الأنواع</option>
          <option value="airline">شركة طيران</option>
          <option value="bus_company">شركة باصات</option>
          <option value="hotel">فندق</option>
          <option value="visa_provider">مزود تأشيرات</option>
          <option value="service_provider">مزود خدمات</option>
          <option value="other">أخرى</option>
        </select>
        <select v-model="statusFilter" @change="applyFilters"
          class="px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main focus:border-blue-500 outline-none">
          <option value="">كل الحالات</option>
          <option value="1">نشط</option>
          <option value="0">غير نشط</option>
        </select>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <!-- Skeleton -->
      <template v-if="asyncState === 'loading'">
        <div class="divide-y divide-white/5">
          <div v-for="i in 8" :key="i" class="flex items-center gap-4 px-5 py-4">
            <div class="h-4 bg-white/5 animate-pulse rounded w-16"></div>
            <div class="h-4 bg-white/5 animate-pulse rounded flex-1"></div>
            <div class="h-4 bg-white/5 animate-pulse rounded w-24"></div>
            <div class="h-4 bg-white/5 animate-pulse rounded w-32"></div>
            <div class="h-4 bg-white/5 animate-pulse rounded w-20"></div>
            <div class="h-4 bg-white/5 animate-pulse rounded w-16"></div>
          </div>
        </div>
      </template>

      <div v-else-if="asyncState === 'error'" class="p-12 text-center">
        <AlertCircle class="w-12 h-12 text-red-400 mx-auto mb-3" />
        <p class="text-red-400">حدث خطأ أثناء تحميل الموردين</p>
        <button @click="loadData" class="mt-3 text-blue-400 hover:underline">إعادة المحاولة</button>
      </div>

      <div v-else-if="asyncState === 'empty' || (asyncState === 'success' && filteredSuppliers.length === 0)" class="p-12 text-center">
        <Users class="w-12 h-12 text-white/10 mx-auto mb-3" />
        <p class="text-text-muted">لا يوجد موردون</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-white/10 text-text-muted">
              <th class="text-right px-5 py-3.5 font-semibold">الكود</th>
              <th class="text-right px-5 py-3.5 font-semibold">الاسم</th>
              <th class="text-right px-5 py-3.5 font-semibold">النوع</th>
              <th class="text-right px-5 py-3.5 font-semibold">جهة الاتصال</th>
              <th class="text-right px-5 py-3.5 font-semibold">المديونية</th>
              <th class="text-right px-5 py-3.5 font-semibold">حد الائتمان</th>
              <th class="text-right px-5 py-3.5 font-semibold">الحالة</th>
              <th class="text-right px-5 py-3.5 font-semibold">إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in filteredSuppliers" :key="s.id"
              class="border-b border-white/5 hover:bg-white/[.03] transition-colors">
              <td class="px-5 py-3.5 font-mono text-blue-400 text-xs">{{ s.code }}</td>
              <td class="px-5 py-3.5 font-medium text-text-main">{{ s.name }}</td>
              <td class="px-5 py-3.5">
                <span class="px-2.5 py-1 text-xs rounded-full" :class="typeClass(s.type)">
                  {{ store.typeLabels[s.type] || s.type }}
                </span>
              </td>
              <td class="px-5 py-3.5 text-text-muted">
                <div>{{ s.contact_person || '—' }}</div>
                <div v-if="s.mobile" class="text-xs mt-0.5">{{ s.mobile }}</div>
              </td>
              <td class="px-5 py-3.5">
                <span :class="parseFloat(s.current_debt) > 0 ? 'text-red-400 font-semibold' : 'text-green-400'">
                  {{ formatCurrency(s.current_debt) }}
                </span>
              </td>
              <td class="px-5 py-3.5 text-text-muted">{{ formatCurrency(s.credit_limit) }}</td>
              <td class="px-5 py-3.5">
                <span class="px-2.5 py-1 text-xs rounded-full font-semibold"
                  :class="s.is_active ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400'">
                  {{ s.is_active ? 'نشط' : 'غير نشط' }}
                </span>
              </td>
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-2">
                  <button @click="editSupplier(s)" class="p-1.5 hover:bg-white/10 rounded-lg text-blue-400 transition" title="تعديل">
                    <Pencil class="w-4 h-4" />
                  </button>
                  <button @click="confirmDelete(s)" class="p-1.5 hover:bg-white/10 rounded-lg text-red-400 transition" title="حذف">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-[200] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-card-bg border border-white/10 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto shadow-2xl">
          <div class="sticky top-0 bg-card-bg border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <h3 class="text-lg font-bold text-text-main">{{ editingId ? 'تعديل مورد' : 'إضافة مورد جديد' }}</h3>
            <button @click="showModal = false" class="p-1 hover:bg-white/10 rounded-lg transition"><X class="w-5 h-5 text-text-muted" /></button>
          </div>
          <form @submit.prevent="saveSupplier" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div class="col-span-2">
                <label class="block text-sm text-text-muted mb-1">اسم المورد *</label>
                <input v-model="form.name" required class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">النوع *</label>
                <select v-model="form.type" required class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500">
                  <option value="airline">شركة طيران</option>
                  <option value="bus_company">شركة باصات</option>
                  <option value="hotel">فندق</option>
                  <option value="visa_provider">مزود تأشيرات</option>
                  <option value="service_provider">مزود خدمات</option>
                  <option value="other">أخرى</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">شروط الدفع</label>
                <select v-model="form.payment_terms" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500">
                  <option value="cash">نقدي</option>
                  <option value="credit_30">آجل 30 يوم</option>
                  <option value="credit_60">آجل 60 يوم</option>
                  <option value="credit_90">آجل 90 يوم</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">جهة الاتصال</label>
                <input v-model="form.contact_person" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">البريد الإلكتروني</label>
                <input v-model="form.email" type="email" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">الهاتف</label>
                <input v-model="form.phone" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">الموبايل</label>
                <input v-model="form.mobile" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">المدينة</label>
                <input v-model="form.city" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">الدولة</label>
                <input v-model="form.country" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div>
                <label class="block text-sm text-text-muted mb-1">حد الائتمان</label>
                <input v-model.number="form.credit_limit" type="number" min="0" step="0.01" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div class="flex items-end">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded accent-blue-500" />
                  <span class="text-sm text-text-main">نشط</span>
                </label>
              </div>
              <div class="col-span-2">
                <label class="block text-sm text-text-muted mb-1">العنوان</label>
                <input v-model="form.address" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500" />
              </div>
              <div class="col-span-2">
                <label class="block text-sm text-text-muted mb-1">ملاحظات</label>
                <textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 bg-input-bg border border-white/10 rounded-xl text-text-main outline-none focus:border-blue-500 resize-none"></textarea>
              </div>
            </div>

            <!-- Validation errors -->
            <div v-if="Object.keys(store.errors).length" class="p-3 bg-red-500/10 border border-red-500/20 rounded-xl">
              <p v-for="(err, key) in store.errors" :key="key" class="text-red-400 text-sm">{{ Array.isArray(err) ? err[0] : err }}</p>
            </div>

            <div class="flex gap-3 pt-2">
              <button type="submit" :disabled="store.loading.create || store.loading.update"
                class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white font-semibold rounded-xl transition">
                {{ (store.loading.create || store.loading.update) ? 'جاري الحفظ...' : 'حفظ' }}
              </button>
              <button type="button" @click="showModal = false" class="px-6 py-2.5 bg-white/5 hover:bg-white/10 text-text-muted rounded-xl transition">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </teleport>

    <!-- Delete Confirm -->
    <teleport to="body">
      <div v-if="showDeleteConfirm" class="fixed inset-0 z-[200] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showDeleteConfirm = false"></div>
        <div class="relative bg-card-bg border border-white/10 rounded-2xl w-full max-w-sm p-6 shadow-2xl text-center">
          <Trash2 class="w-12 h-12 text-red-400 mx-auto mb-4" />
          <h3 class="text-lg font-bold text-text-main mb-2">حذف المورد</h3>
          <p class="text-text-muted mb-6">هل أنت متأكد من حذف <strong class="text-text-main">{{ deletingSupplier?.name }}</strong>؟</p>
          <div class="flex gap-3">
            <button @click="doDelete" :disabled="store.loading.delete"
              class="flex-1 py-2.5 bg-red-600 hover:bg-red-500 disabled:opacity-50 text-white font-semibold rounded-xl transition">
              {{ store.loading.delete ? 'جاري الحذف...' : 'حذف' }}
            </button>
            <button @click="showDeleteConfirm = false" class="flex-1 py-2.5 bg-white/5 hover:bg-white/10 text-text-muted rounded-xl transition">إلغاء</button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useSupplierStore } from '@/stores/supplierStore'
import { useAsyncState } from '@/composables/useAsyncState'
import { Plus, Search, Pencil, Trash2, X, AlertCircle, Users } from 'lucide-vue-next'
import { debounce } from 'lodash-es'

const store = useSupplierStore()
const { state: asyncState, setLoading, setSuccess, setError } = useAsyncState()

const showModal = ref(false)
const showDeleteConfirm = ref(false)
const editingId = ref(null)
const deletingSupplier = ref(null)
const search = ref('')
const typeFilter = ref('')
const statusFilter = ref('')

const form = ref(getEmptyForm())

function getEmptyForm() {
  return {
    name: '', type: 'airline', contact_person: '', email: '', phone: '', mobile: '',
    address: '', city: '', country: 'مصر', credit_limit: 0, payment_terms: 'cash',
    is_active: true, notes: '',
  }
}

function resetForm() {
  editingId.value = null
  form.value = getEmptyForm()
  store.errors = {}
}

const filteredSuppliers = computed(() => {
  let list = store.suppliers
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(s => s.name?.toLowerCase().includes(q) || s.code?.toLowerCase().includes(q))
  }
  if (typeFilter.value) list = list.filter(s => s.type === typeFilter.value)
  if (statusFilter.value !== '') list = list.filter(s => String(s.is_active ? 1 : 0) === statusFilter.value)
  return list
})

const debouncedSearch = debounce(() => applyFilters(), 300)

function applyFilters() {
  store.setFilters({ search: search.value, type: typeFilter.value, is_active: statusFilter.value })
}

function editSupplier(s) {
  editingId.value = s.id
  form.value = { ...s }
  showModal.value = true
}

async function saveSupplier() {
  try {
    if (editingId.value) {
      await store.updateSupplier(editingId.value, form.value)
    } else {
      await store.createSupplier(form.value)
    }
    showModal.value = false
    resetForm()
  } catch (e) { /* errors handled in store */ }
}

function confirmDelete(s) {
  deletingSupplier.value = s
  showDeleteConfirm.value = true
}

async function doDelete() {
  try {
    await store.deleteSupplier(deletingSupplier.value.id)
    showDeleteConfirm.value = false
  } catch (e) { /* errors handled in store */ }
}

function typeClass(type) {
  const map = {
    airline: 'bg-blue-500/15 text-blue-400',
    bus_company: 'bg-purple-500/15 text-purple-400',
    hotel: 'bg-amber-500/15 text-amber-400',
    visa_provider: 'bg-cyan-500/15 text-cyan-400',
    service_provider: 'bg-green-500/15 text-green-400',
    other: 'bg-white/10 text-text-muted',
  }
  return map[type] || map.other
}

function formatCurrency(v) {
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP' }).format(v || 0)
}

async function loadData() {
  try {
    setLoading();
    await store.fetchSuppliers();
    setSuccess(store.suppliers.length === 0);
  } catch (e) {
    setError(e);
  }
}

onMounted(() => loadData())
</script>
