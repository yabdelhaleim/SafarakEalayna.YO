<template>
  <div class="max-w-6xl mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">العملاء</h1>
        <p class="text-muted mt-1">إدارة جميع العملاء</p>
      </div>
      <button @click="showCreateModal = true" class="px-6 py-3 bg-gold text-black font-bold rounded-xl hover:bg-gold/90 transition-all shadow-lg shadow-gold/20 flex items-center gap-2">
        <Plus class="w-5 h-5" />
        إضافة عميل جديد
      </button>
    </div>

    <!-- Search Bar -->
    <div class="relative">
      <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
        <Search class="h-5 w-5 text-muted" />
      </div>
      <input
        v-model="filters.search"
        type="text"
        placeholder="بحث بالاسم أو رقم الهاتف..."
        class="w-full pl-12 pr-4 py-4 bg-input border border-white/10 rounded-2xl focus:border-gold focus:ring-1 focus:ring-gold outline-none transition-all"
        @input="onSearch"
      />
    </div>

    <!-- Customers List -->
    <div class="bg-card border border-white/10 rounded-3xl overflow-hidden shadow-2xl">
      <!-- Skeleton -->
      <template v-if="asyncState === 'loading'">
        <div v-for="i in 6" :key="i" class="p-6 border-b border-white/5 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-white/5 animate-pulse"></div>
            <div class="space-y-2">
              <div class="h-4 bg-white/5 animate-pulse rounded w-36"></div>
              <div class="h-3 bg-white/5 animate-pulse rounded w-24"></div>
            </div>
          </div>
          <div class="flex gap-2">
            <div class="w-9 h-9 bg-white/5 animate-pulse rounded-lg"></div>
            <div class="w-9 h-9 bg-white/5 animate-pulse rounded-lg"></div>
          </div>
        </div>
      </template>
      <div v-else-if="asyncState === 'empty' || (asyncState === 'success' && filteredCustomers.length === 0)" class="p-12 text-center">
        <Users class="h-16 w-16 text-muted mx-auto mb-4" />
        <h3 class="text-lg font-bold text-white mb-2">لا يوجد عملاء</h3>
        <p class="text-muted mb-6">لم يتم إضافة أي عملاء بعد</p>
        <button @click="showCreateModal = true" class="px-6 py-2 bg-gold/10 text-gold rounded-lg border border-gold/20 hover:bg-gold hover:text-black transition-all">
          إضافة أول عميل
        </button>
      </div>
      <div v-else class="divide-y divide-white/10">
        <div v-for="customer in filteredCustomers" :key="customer.id" class="p-6 hover:bg-white/5 transition-colors flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-gold/10 text-gold flex items-center justify-center font-bold text-lg flex-shrink-0">
              {{ getInitials(customer.name || customer.full_name) }}
            </div>
            <div>
              <h3 class="font-bold text-white">{{ customer.name || customer.full_name }}</h3>
              <p class="text-sm text-muted">{{ customer.phone }}{{ customer.email ? ' | ' + customer.email : '' }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button @click="editCustomer(customer)" class="p-2 hover:bg-white/10 rounded-lg text-muted hover:text-white transition-all">
              <Pen class="w-5 h-5" />
            </button>
            <button @click="deleteCustomer(customer)" class="p-2 hover:bg-error/10 rounded-lg text-muted hover:text-error transition-all">
              <Trash2 class="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Customer Modal -->
    <div v-if="showCreateModal || showEditModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-300">
      <div class="bg-card w-full max-w-md border border-white/10 rounded-2xl p-6 shadow-2xl animate-in zoom-in-95 duration-300">
        <h2 class="text-xl font-bold mb-6 text-gold">{{ showEditModal ? 'تعديل العميل' : 'إضافة عميل جديد' }}</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm text-muted mb-1">الاسم الكامل*</label>
            <input v-model="form.full_name" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">رقم الهاتف*</label>
            <input v-model="form.phone" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">رقم الهوية</label>
            <input v-model="form.national_id" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">رقم جواز السفر</label>
            <input v-model="form.passport_number" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">تاريخ الميلاد</label>
            <input v-model="form.date_of_birth" type="date" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">المدينة</label>
            <input v-model="form.city" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">ملاحظات</label>
            <textarea v-model="form.notes" rows="3" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none resize-none"></textarea>
          </div>
        </div>
        <div class="flex gap-3 mt-8">
          <button @click="closeModal" class="flex-1 py-3 bg-white/5 rounded-xl hover:bg-white/10 transition-all">إلغاء</button>
          <button @click="saveCustomer" :disabled="saving" class="flex-1 py-3 bg-gold text-black font-bold rounded-xl hover:bg-gold/80 transition-all shadow-lg shadow-gold/20 disabled:opacity-50">
            {{ saving ? 'جارٍ الحفظ...' : 'حفظ' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCustomerStore } from '@/stores/customerStore';
import { useAsyncState } from '@/composables/useAsyncState';
import { Search, Users, Plus, Pen, Trash2 } from 'lucide-vue-next';
import { useDebounceFn } from '@vueuse/core';

const store = useCustomerStore();
const { state: asyncState, setLoading, setSuccess, setError } = useAsyncState();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const saving = ref(false);
const editingId = ref(null);

const filters = ref({
  search: ''
});

const form = ref({
  full_name: '',
  phone: '',
  national_id: '',
  passport_number: '',
  date_of_birth: '',
  city: '',
  notes: ''
});

const filteredCustomers = computed(() => {
  let filtered = [...store.customers];
  if (filters.value.search) {
    const query = filters.value.search.toLowerCase();
    filtered = filtered.filter(c =>
      (c.name || c.full_name)?.toLowerCase().includes(query) ||
      c.phone?.toLowerCase().includes(query)
    );
  }
  return filtered;
});

const getInitials = (name) => {
  if (!name) return '?';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

const onSearch = useDebounceFn(async () => {
  // Local filtering for now
}, 300);

const editCustomer = (customer) => {
  editingId.value = customer.id;
  form.value = {
    full_name: customer.full_name || customer.name,
    phone: customer.phone,
    national_id: customer.national_id || '',
    passport_number: customer.passport_number || '',
    date_of_birth: customer.date_of_birth || '',
    city: customer.city || '',
    notes: customer.notes || ''
  };
  showEditModal.value = true;
};

const deleteCustomer = async (customer) => {
  if (!confirm('هل أنت متأكد من حذف هذا العميل؟')) return;
  try {
    await store.deleteCustomer(customer.id);
  } catch (error) {
    console.error('Failed to delete customer', error);
  }
};

const closeModal = () => {
  showCreateModal.value = false;
  showEditModal.value = false;
  editingId.value = null;
  form.value = {
    full_name: '',
    phone: '',
    national_id: '',
    passport_number: '',
    date_of_birth: '',
    city: '',
    notes: ''
  };
};

const saveCustomer = async () => {
  if (!form.value.full_name || !form.value.phone) return;
  saving.value = true;
  try {
    if (showEditModal.value) {
      await store.updateCustomer(editingId.value, form.value);
    } else {
      await store.createCustomer(form.value);
    }
    closeModal();
  } catch (error) {
    console.error('Failed to save customer', error);
  } finally {
    saving.value = false;
  }
};

onMounted(async () => {
  try {
    setLoading();
    await store.fetchCustomers();
    setSuccess(store.customers.length === 0);
  } catch (e) {
    setError(e);
  }
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
</style>
