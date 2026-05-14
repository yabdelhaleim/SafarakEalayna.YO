<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          مخزون الباصات
        </h1>
        <p class="text-text-muted mt-1">
          إدارة رحلات الباصات والكراسي المتاحة
        </p>
      </div>
      <button
        type="button"
        @click="openCreateModal"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <Plus class="w-5 h-5" />
        رحلة جديدة
      </button>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-blue-500/10 rounded-lg">
            <Route class="w-4 h-4 text-blue-500" />
          </div>
          <span class="text-sm text-text-muted">إجمالي الرحلات</span>
        </div>
        <p class="text-2xl font-bold font-mono text-blue-500">
          {{ store.inventory.length }}
        </p>
        <p class="text-xs text-text-muted mt-1">رحلة نشطة</p>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-success/10 rounded-lg">
            <Armchair class="w-4 h-4 text-success" />
          </div>
          <span class="text-sm text-text-muted">المقاعد المتاحة</span>
        </div>
        <p class="text-2xl font-bold font-mono text-success">
          {{ totalAvailableSeats }}
        </p>
        <p class="text-xs text-text-muted mt-1">مقعد</p>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl">
        <div class="flex items-center gap-3 mb-2">
          <div class="p-2 bg-gold/10 rounded-lg">
            <Users class="w-4 h-4 text-gold" />
          </div>
          <span class="text-sm text-text-muted">إجمالي المقاعد</span>
        </div>
        <p class="text-2xl font-bold font-mono text-gold">
          {{ totalSeats }}
        </p>
        <p class="text-xs text-text-muted mt-1">مقعد</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-4">
      <select
        v-model="filters.company_id"
        @change="applyFilters"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer min-w-[140px]"
      >
        <option value="">جميع الشركات</option>
        <option
          v-for="company in store.companies"
          :key="company.id"
          :value="company.id"
        >
          {{ company.name }}
        </option>
      </select>

      <input
        v-model="filters.date_from"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="applyFilters"
      />

      <input
        v-model="filters.date_to"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        @change="applyFilters"
      />

      <button
        @click="clearFilters"
        class="text-sm text-text-muted hover:text-gold transition-colors px-4 py-2"
      >
        مسح الفلاتر
      </button>
    </div>

    <div
      v-if="store.errors?.fetch"
      class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-error/30 bg-error/10 px-4 py-3 text-sm text-error"
    >
      <span>{{ store.errors.fetch }}</span>
      <button type="button" class="font-bold text-gold hover:underline" @click="reloadInventory">إعادة المحاولة</button>
    </div>

    <!-- Inventory Table -->
    <div class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-white/5 text-xs text-text-muted uppercase tracking-widest border-b border-white/10">
              <th class="px-6 py-4 font-semibold">الشركة</th>
              <th class="px-6 py-4 font-semibold">الرحلة</th>
              <th class="px-6 py-4 font-semibold">التاريخ</th>
              <th class="px-6 py-4 font-semibold">الوقت</th>
              <th class="px-6 py-4 font-semibold">المقاعد</th>
              <th class="px-6 py-4 font-semibold">السعر</th>
              <th class="px-6 py-4 font-semibold text-right">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="store.loading.inventory">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td v-for="j in 7" :key="j" class="px-6 py-4">
                  <div class="h-4 animate-shimmer rounded w-full"></div>
                </td>
              </tr>
            </template>
            <template v-else-if="filteredInventory.length > 0">
              <tr
                v-for="(item, index) in filteredInventory"
                :key="item.id"
                class="border-b border-white/5 hover:bg-white/5 transition-colors group"
              >
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-500/10 rounded-lg">
                      <Building2 class="w-4 h-4 text-blue-500" />
                    </div>
                    <span class="font-semibold text-sm">{{ item.bus_company?.name }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2 font-semibold text-sm">
                    <MapPin class="w-4 h-4 text-gold" />
                    {{ item.route_from }}
                    <ArrowRight class="w-4 h-4 text-text-muted" />
                    {{ item.route_to }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm">{{ formatDate(item.travel_date) }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm font-mono">{{ formatTime(item.departure_time) }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="flex-1 h-2 bg-white/5 rounded-full overflow-hidden">
                      <div
                        class="h-full rounded-full transition-all"
                        :class="getSeatsColorClass(item)"
                        :style="{ width: getSeatsPercentage(item) + '%' }"
                      ></div>
                    </div>
                    <span class="text-sm font-mono whitespace-nowrap">
                      {{ item.available_seats }} / {{ item.total_seats }}
                    </span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="font-mono font-bold text-gold text-sm">
                    {{ item.seat_price }} جنيه
                  </span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <router-link
                      :to="{ name: 'bus.create', query: { inventory_id: String(item.id) } }"
                      class="px-3 py-2 bg-gold hover:bg-gold/90 text-black rounded-lg text-sm font-semibold transition-all"
                    >
                      حجز
                    </router-link>
                    <button
                      @click="editItem(item)"
                      class="p-2 hover:bg-white/10 rounded-lg text-text-muted hover:text-gold transition-all"
                      title="تعديل"
                    >
                      <Edit2 class="w-4 h-4" />
                    </button>
                    <button
                      @click="confirmDelete(item)"
                      class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all"
                      title="حذف"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-else>
              <td colspan="7" class="px-6 py-20 text-center">
                <div class="flex flex-col items-center gap-4">
                  <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center">
                    <Armchair class="w-10 h-10 text-white/10" />
                  </div>
                  <div class="max-w-xs">
                    <h3 class="text-xl font-bold text-text-main">لا توجد رحلات</h3>
                    <p class="text-text-muted text-sm mt-1">
                      ابدأ بإضافة رحلة جديدة للمخزون
                    </p>
                  </div>
                  <button
                    type="button"
                    class="mt-2 px-6 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all"
                    @click="openCreateModal"
                  >
                    إضافة رحلة
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div
      v-if="showCreateModal"
      class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
    >
      <div class="bg-card-bg border border-white/10 rounded-2xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-2">
          {{ editingId ? 'تعديل رحلة' : 'إضافة رحلة جديدة' }}
        </h3>
        <p class="mb-6 text-xs text-text-muted">
          تُنشأ الرحلة عبر الـ API (مطابقة لـ Filament): للشراء الآجل اختر «آجل»؛ للدفع الفوري للشركة اختر «نقدي» مع حساب.
        </p>

        <form @submit.prevent="saveInventory" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                شركة النقل *
              </label>
              <select
                v-model="inventoryForm.company_id"
                required
                :disabled="!!editingId"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm disabled:opacity-50"
              >
                <option value="">اختر الشركة</option>
                <option
                  v-for="company in store.companies"
                  :key="company.id"
                  :value="company.id"
                >
                  {{ company.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                من مدينة *
              </label>
              <input
                v-model="inventoryForm.route_from"
                type="text"
                required
                placeholder="مثال: القاهرة"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                إلى مدينة *
              </label>
              <input
                v-model="inventoryForm.route_to"
                type="text"
                required
                placeholder="مثال: الإسكندرية"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                تاريخ الرحلة *
              </label>
              <input
                v-model="inventoryForm.travel_date"
                type="date"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                وقت المغادرة *
              </label>
              <input
                v-model="inventoryForm.departure_time"
                type="time"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                عدد المقاعد الكلي *
              </label>
              <input
                v-model.number="inventoryForm.total_seats"
                type="number"
                min="1"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                سعر البيع للمقعد (جنيه) *
              </label>
              <input
                v-model.number="inventoryForm.seat_price"
                type="number"
                step="0.01"
                min="0.01"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>

            <div v-if="!editingId">
              <label class="block text-sm font-semibold text-text-main mb-2">
                تكلفة المقعد من الشركة (شراء) *
              </label>
              <input
                v-model.number="inventoryForm.cost_per_ticket"
                type="number"
                step="0.01"
                min="0.01"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>

            <div v-if="!editingId" class="md:col-span-2">
              <label class="block text-sm font-semibold text-text-main mb-2">طريقة تسديد تكلفة التذاكر للشركة</label>
              <select
                v-model="inventoryForm.payment_type"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              >
                <option value="deferred">آجل (دين على الشركة)</option>
                <option value="cash">نقدي (خصم من حساب الآن)</option>
              </select>
            </div>

            <div v-if="!editingId && inventoryForm.payment_type === 'cash'" class="md:col-span-2">
              <label class="block text-sm font-semibold text-text-main mb-2">حساب الدفع *</label>
              <select
                v-model="inventoryForm.account_id"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              >
                <option value="">— اختر الحساب —</option>
                <option v-for="acc in financeAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
              </select>
              <p v-if="!financeAccounts.length && !loadingAccounts" class="mt-1 text-xs text-warning">
                لا توجد حسابات. أضف حسابات من لوحة Filament.
              </p>
            </div>

            <div class="md:col-span-2">
              <label class="block text-sm font-semibold text-text-main mb-2">ملاحظات</label>
              <textarea
                v-model="inventoryForm.notes"
                rows="2"
                class="w-full resize-none rounded-xl border border-white/10 bg-input-bg px-4 py-3 text-sm focus:border-gold focus:outline-none"
              />
            </div>
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="submit"
              :disabled="store.loading.create || store.loading.update || saveBlocked"
              class="flex-1 px-4 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all disabled:opacity-50"
            >
              {{
                store.loading.create || store.loading.update
                  ? 'جاري الحفظ...'
                  : editingId
                    ? 'تحديث الرحلة'
                    : 'حفظ الرحلة'
              }}
            </button>
            <button
              type="button"
              @click="closeModal"
              class="flex-1 px-4 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
            >
              إلغاء
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { useBusStore } from '@/stores/busStore';
import {
  Plus,
  Route,
  Armchair,
  Users,
  Building2,
  MapPin,
  ArrowRight,
  Edit2,
  Trash2,
} from 'lucide-vue-next';

const store = useBusStore();

const showCreateModal = ref(false);
const editingId = ref(null);
const financeAccounts = ref([]);
const loadingAccounts = ref(false);

const filters = ref({
  company_id: '',
  date_from: '',
  date_to: '',
});

const defaultForm = () => ({
  company_id: '',
  route_from: '',
  route_to: '',
  travel_date: '',
  departure_time: '',
  total_seats: 40,
  seat_price: 300,
  cost_per_ticket: 250,
  payment_type: 'deferred',
  account_id: '',
  notes: '',
});

const inventoryForm = ref(defaultForm());

const saveBlocked = computed(() => {
  if (editingId.value) return false;
  if (inventoryForm.value.payment_type !== 'cash') return false;
  return !inventoryForm.value.account_id;
});

// Filtered inventory
const filteredInventory = computed(() => {
  let filtered = [...store.inventory];

  if (filters.value.company_id) {
    filtered = filtered.filter((i) => i.bus_company_id === filters.value.company_id);
  }

  if (filters.value.date_from) {
    filtered = filtered.filter(
      (i) => new Date(i.travel_date) >= new Date(filters.value.date_from)
    );
  }

  if (filters.value.date_to) {
    filtered = filtered.filter(
      (i) => new Date(i.travel_date) <= new Date(filters.value.date_to)
    );
  }

  return filtered;
});

// Total available seats
const totalAvailableSeats = computed(() => {
  return store.inventory.reduce((sum, i) => sum + (i.available_seats || 0), 0);
});

// Total seats
const totalSeats = computed(() => {
  return store.inventory.reduce((sum, i) => sum + (i.total_seats || 0), 0);
});

// Get seats percentage
const getSeatsPercentage = (item) => {
  if (!item.total_seats) return 0;
  return ((item.available_seats / item.total_seats) * 100).toFixed(0);
};

// Get seats color class
const getSeatsColorClass = (item) => {
  const percentage = (item.available_seats / item.total_seats) * 100;
  if (percentage > 50) return 'bg-success';
  if (percentage > 20) return 'bg-warning';
  return 'bg-error';
};

// Format date
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

// Format time
const formatTime = (timeString) => {
  if (!timeString) return '';
  return timeString;
};

// Apply filters
const applyFilters = () => {
  // Filters are reactive via computed
};

// Clear filters
const clearFilters = () => {
  filters.value = {
    company_id: '',
    date_from: '',
    date_to: '',
  };
};

const travelDateInput = (raw) => {
  if (!raw) return '';
  const s = String(raw);
  return s.length >= 10 ? s.slice(0, 10) : s;
};

const fetchFinanceAccounts = async () => {
  loadingAccounts.value = true;
  try {
    const res = await axios.get('/api/v1/finance/accounts', {
      params: { per_page: 100, types: 'cashbox,wallet,bank,treasury', is_active: 1 },
    });
    let raw = res.data?.data;
    if (raw && !Array.isArray(raw) && Array.isArray(raw.data)) raw = raw.data;
    financeAccounts.value = Array.isArray(raw) ? raw : [];
  } catch {
    financeAccounts.value = [];
  } finally {
    loadingAccounts.value = false;
  }
};

const openCreateModal = async () => {
  editingId.value = null;
  inventoryForm.value = defaultForm();
  await fetchFinanceAccounts();
  showCreateModal.value = true;
};

const editItem = async (item) => {
  editingId.value = item.id;
  await fetchFinanceAccounts();
  inventoryForm.value = {
    company_id: item.bus_company_id || item.company_id || item.company?.id || '',
    route_from: item.route_from || '',
    route_to: item.route_to || '',
    travel_date: travelDateInput(item.travel_date),
    departure_time: String(item.departure_time || '').slice(0, 5),
    total_seats: item.total_seats ?? item.total_tickets ?? 40,
    seat_price: item.seat_price ?? item.selling_price ?? 300,
    cost_per_ticket: item.cost_per_ticket ?? 250,
    payment_type: 'deferred',
    account_id: '',
    notes: item.notes || '',
  };
  showCreateModal.value = true;
};

const confirmDelete = async (item) => {
  if (!confirm(`حذف الرحلة ${item.route_from} → ${item.route_to}؟ لا يمكن الحذف إن وُجدت حجوزات.`)) return;
  try {
    await store.deleteInventory(item.id);
    store.addToast('تم حذف الرحلة');
    await store.fetchInventory();
  } catch {
    store.addToast(store.errors?.delete || 'فشل حذف الرحلة', 'error');
  }
};

const saveInventory = async () => {
  try {
    if (editingId.value) {
      await store.updateInventory(editingId.value, inventoryForm.value);
      store.addToast('تم تحديث الرحلة');
    } else {
      await store.createInventory(inventoryForm.value);
      store.addToast('تم إضافة الرحلة');
    }
    closeModal();
    await store.fetchInventory();
  } catch {
    const msg = store.errors?.message || (editingId.value ? 'فشل التحديث' : 'فشل الإضافة');
    store.addToast(typeof msg === 'string' ? msg : 'فشل الحفظ', 'error');
  }
};

const closeModal = () => {
  showCreateModal.value = false;
  editingId.value = null;
  inventoryForm.value = defaultForm();
};

const reloadInventory = async () => {
  store.errors = {};
  await Promise.all([store.fetchInventory(), store.fetchCompanies()]);
};

watch(
  () => inventoryForm.value.seat_price,
  (sp) => {
    if (editingId.value) return;
    const n = Number(sp) || 0;
    if (n > 0 && (!inventoryForm.value.cost_per_ticket || inventoryForm.value.cost_per_ticket > n)) {
      inventoryForm.value.cost_per_ticket = Math.round(n * 0.85 * 100) / 100;
    }
  }
);

onMounted(async () => {
  await reloadInventory();
});
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

.text-gold {
  color: var(--gold);
}

.bg-gold {
  background-color: var(--gold);
}

.text-success {
  color: var(--success);
}

.text-warning {
  color: var(--warning);
}

.text-error {
  color: var(--error);
}

.text-blue-500 {
  color: #4F8EF7;
}

.bg-success {
  background-color: var(--success);
}

.bg-warning {
  background-color: var(--warning);
}

.bg-error {
  background-color: var(--error);
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
