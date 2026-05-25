<template>
  <div class="relative">
    <!-- Selected Customer Card -->
    <div v-if="modelValue" class="p-4 bg-input rounded-xl border border-gold/30 flex justify-between items-center animate-in fade-in slide-in-from-top-2">
      <div>
        <h3 class="font-bold text-gold">{{ modelValue.name || modelValue.full_name }}</h3>
        <p class="text-sm text-muted">
          {{ modelValue.phone }}
          <span v-if="modelValue.national_id"> | الرقم القومي: {{ modelValue.national_id }}</span>
          <span v-if="modelValue.travel_country"> | دولة السفر: {{ modelValue.travel_country }}</span>
        </p>
      </div>
      <button @click="$emit('update:modelValue', null)" class="text-xs bg-error/10 text-error px-3 py-1 rounded-lg hover:bg-error hover:text-white transition-colors">
        تغيير
      </button>
    </div>

    <!-- Search Input -->
    <div v-else class="relative">
      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <Search class="h-5 w-5 text-muted" />
      </div>
      <input
        v-model="searchQuery"
        type="text"
        placeholder="ابحث عن العميل بالاسم، رقم الهاتف، أو الرقم القومي..."
        class="w-full pl-10 pr-4 py-3 bg-input border border-white/10 rounded-xl focus:border-gold focus:ring-1 focus:ring-gold outline-none transition-all text-right"
        dir="rtl"
        @input="onSearch"
        @keydown="handleKeydown"
      />

      <!-- Dropdown -->
      <div v-if="showDropdown" class="absolute z-50 w-full mt-2 bg-card border border-white/10 rounded-xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200 text-right" dir="rtl">
        <div v-if="loading" class="p-4 text-center text-muted italic">
          جاري البحث...
        </div>
        <div v-else-if="customers.length === 0" class="p-4 text-center">
          <p class="text-muted mb-3">لم يتم العثور على عملاء</p>
          <button @click="showCreateModal = true" class="w-full py-2 bg-gold/10 text-gold rounded-lg border border-gold/20 hover:bg-gold hover:text-black transition-all">
            + إضافة عميل جديد
          </button>
        </div>
        <div v-else class="max-h-60 overflow-y-auto">
          <button
            v-for="(customer, idx) in customers"
            :key="customer.id"
            @click="selectCustomer(customer)"
            :class="['w-full p-3 text-right hover:bg-white/5 border-b border-white/5 last:border-0 transition-colors flex items-center gap-3', highlightedIndex === idx ? 'bg-white/5' : '']"
          >
            <div class="w-10 h-10 rounded-full bg-gold/10 text-gold flex items-center justify-center font-bold text-sm flex-shrink-0">
              {{ getInitials(customer.name || customer.full_name) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-medium truncate text-right">{{ customer.name || customer.full_name }}</div>
              <div class="text-xs text-muted text-right">
                {{ customer.phone }}
                <span v-if="customer.national_id"> | الرقم القومي: {{ customer.national_id }}</span>
              </div>
            </div>
          </button>
          <button @click="showCreateModal = true" class="w-full p-3 text-gold text-sm font-medium hover:bg-gold/5 text-center">
            + إضافة عميل جديد
          </button>
        </div>
      </div>
    </div>

    <!-- Create Customer Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-300">
      <div class="bg-card w-full max-w-2xl border border-white/10 rounded-2xl p-6 shadow-2xl animate-in zoom-in-95 duration-300 text-right" dir="rtl">
        <h2 class="text-xl font-bold mb-6 text-gold">إضافة عميل جديد (كوانتر)</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-muted mb-1">الاسم بالكامل*</label>
            <input v-model="newCustomer.full_name" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right" placeholder="أدخل الاسم بالكامل" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">رقم الهاتف*</label>
            <input v-model="newCustomer.phone" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left" dir="ltr" placeholder="01xxxxxxxxx" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">الرقم القومي</label>
            <input v-model="newCustomer.national_id" type="text" maxlength="14" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left" dir="ltr" placeholder="14 رقم" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">رقم الواتساب</label>
            <input v-model="newCustomer.whatsapp_number" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left" dir="ltr" placeholder="01xxxxxxxxx" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">المدينة</label>
            <input v-model="newCustomer.city" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right" placeholder="أدخل المدينة" />
          </div>
          <div>
            <label class="block text-sm text-muted mb-1">دولة السفر</label>
            <input v-model="newCustomer.travel_country" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right" placeholder="أدخل دولة السفر" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm text-muted mb-1">الجهة التابع لها</label>
            <input v-model="newCustomer.affiliation" type="text" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right" placeholder="الشركة أو الجهة التابع لها" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm text-muted mb-1">ملاحظات</label>
            <textarea v-model="newCustomer.notes" rows="3" class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right" placeholder="أية ملاحظات إضافية"></textarea>
          </div>
        </div>
        <div class="flex gap-3 mt-8">
          <button @click="showCreateModal = false" class="flex-1 py-3 bg-white/5 rounded-xl hover:bg-white/10 transition-all text-sm">إلغاء</button>
          <button @click="saveCustomer" :disabled="savingCustomer" class="flex-1 py-3 bg-gold text-black font-bold rounded-xl hover:bg-gold/80 transition-all shadow-lg shadow-gold/20 disabled:opacity-50 text-sm">
            {{ savingCustomer ? 'جارٍ الحفظ...' : 'حفظ العميل' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useFlightStore } from '@/stores/flightStore';
import { Search } from 'lucide-vue-next';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
  modelValue: Object,
  type: {
    type: String,
    default: 'regular' // 'regular' or 'counter'
  }
});
const emit = defineEmits(['update:modelValue']);

const store = useFlightStore();
const searchQuery = ref('');
const showDropdown = ref(false);
const showCreateModal = ref(false);
const loading = ref(false);
const customers = ref([]);
const highlightedIndex = ref(-1);

const newCustomer = ref({
  full_name: '',
  phone: '',
  national_id: '',
  whatsapp_number: '',
  city: '',
  travel_country: '',
  affiliation: '',
  notes: '',
  type: props.type
});
const savingCustomer = ref(false);

const getInitials = (name) => {
  if (!name) return '?';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

const onSearch = useDebounceFn(async () => {
  if (searchQuery.value.length < 2) {
    customers.value = [];
    showDropdown.value = false;
    highlightedIndex.value = -1;
    return;
  }

  loading.value = true;
  showDropdown.value = true;
  highlightedIndex.value = -1;
  try {
    await store.fetchCustomers({ 
      search: searchQuery.value,
      type: props.type
    });
    customers.value = store.customers;
  } finally {
    loading.value = false;
  }
}, 300);

const selectCustomer = (customer) => {
  emit('update:modelValue', customer);
  searchQuery.value = '';
  showDropdown.value = false;
  highlightedIndex.value = -1;
};

const saveCustomer = async () => {
  if (!newCustomer.value.full_name || !newCustomer.value.phone) {
    store.addToast('يرجى ملء الاسم ورقم الهاتف على الأقل', 'error');
    return;
  }

  savingCustomer.value = true;
  try {
    const saved = await store.createCustomer(newCustomer.value);
    selectCustomer(saved);
    showCreateModal.value = false;
    newCustomer.value = {
      full_name: '',
      phone: '',
      national_id: '',
      whatsapp_number: '',
      city: '',
      travel_country: '',
      affiliation: '',
      notes: '',
      type: props.type
    };
    store.addToast('تم إنشاء العميل بنجاح!', 'success');
  } catch (error) {
    console.error('Failed to save customer', error);
    let errorMsg = 'حدث خطأ أثناء حفظ العميل';
    if (error.response?.data?.errors) {
      const firstError = Object.values(error.response.data.errors)[0];
      errorMsg = Array.isArray(firstError) ? firstError[0] : firstError;
    }
    store.addToast(errorMsg, 'error');
  } finally {
    savingCustomer.value = false;
  }
};

// Keyboard navigation
const handleKeydown = (e) => {
  if (!showDropdown.value || customers.value.length === 0) return;

  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault();
      highlightedIndex.value = Math.min(highlightedIndex.value + 1, customers.value.length - 1);
      break;
    case 'ArrowUp':
      e.preventDefault();
      highlightedIndex.value = Math.max(highlightedIndex.value - 1, -1);
      break;
    case 'Enter':
      e.preventDefault();
      if (highlightedIndex.value >= 0 && customers.value[highlightedIndex.value]) {
        selectCustomer(customers.value[highlightedIndex.value]);
      }
      break;
    case 'Escape':
      showDropdown.value = false;
      highlightedIndex.value = -1;
      break;
  }
};

watch(searchQuery, () => {
  highlightedIndex.value = -1;
});

// Close dropdown on click outside
if (typeof window !== 'undefined') {
  window.addEventListener('click', (e) => {
    if (!e.target.closest('.relative')) {
      showDropdown.value = false;
    }
  });
}
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.border-gold { border-color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
</style>
