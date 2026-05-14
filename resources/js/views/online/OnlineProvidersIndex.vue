<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">مزودو الخدمات الأونلاين</h1>
        <p class="text-text-muted mt-1">
          الجهات التي تنفّذ الخدمة للعميل. يُحدد لكل مزود حساب التسوية الافتراضي (اختياري) ويُدار من فيليمنت.
        </p>
      </div>
      <button
        class="bg-gold hover:bg-gold/90 text-black px-5 py-2.5 rounded-xl font-bold flex items-center gap-2 transition-all shadow-lg shadow-gold/20"
        @click="openCreate"
      >
        <Plus class="w-4 h-4" />
        مزود جديد
      </button>
    </div>

    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap gap-3 items-center">
      <div class="flex-1 min-w-[260px] relative">
        <Search class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="search"
          type="text"
          placeholder="بحث بالاسم أو الكود..."
          class="w-full px-10 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm focus:border-gold outline-none"
          @input="onSearch"
        />
      </div>

      <select
        v-model="statusFilter"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl text-sm cursor-pointer min-w-[140px]"
        @change="onSearch"
      >
        <option value="">الكل</option>
        <option value="1">نشط</option>
        <option value="0">غير نشط</option>
      </select>
    </div>

    <div v-if="store.loading.providers" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="i in 6" :key="i" class="h-52 bg-card-bg border border-white/10 rounded-2xl animate-pulse" />
    </div>

    <div v-else-if="store.providers.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="p in store.providers"
        :key="p.id"
        class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden hover:border-gold/30 transition-all"
      >
        <div class="p-5 border-b border-white/5">
          <div class="flex items-start justify-between mb-3">
            <span
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase"
              :style="{ backgroundColor: (p.color ?? '#6B7280') + '22', color: p.color ?? '#9CA3AF' }"
            >
              {{ p.code }}
            </span>
            <span
              class="text-[10px] font-bold px-2 py-1 rounded-full"
              :class="p.is_active ? 'bg-success/10 text-success' : 'bg-error/10 text-error'"
            >
              {{ p.is_active ? 'نشط' : 'غير نشط' }}
            </span>
          </div>
          <h3 class="font-bold text-lg text-text-main mb-1">{{ p.name_ar ?? p.name }}</h3>
          <p class="text-xs text-text-muted line-clamp-2 min-h-[32px]">
            {{ p.description_ar ?? p.description ?? '' }}
          </p>

          <dl class="mt-3 text-xs space-y-1">
            <div v-if="p.contact_phone" class="flex items-center gap-2 text-text-muted">
              <Phone class="w-3.5 h-3.5" />
              <span class="font-mono">{{ p.contact_phone }}</span>
            </div>
            <div v-if="p.contact_account" class="flex items-center gap-2 text-text-muted">
              <Hash class="w-3.5 h-3.5" />
              <span class="font-mono">{{ p.contact_account }}</span>
            </div>
            <div v-if="p.default_purchase_account" class="flex items-center gap-2 text-text-muted">
              <Wallet class="w-3.5 h-3.5" />
              <span>تسوية افتراضية: {{ p.default_purchase_account.name }}</span>
            </div>
          </dl>
        </div>
        <div class="p-3 bg-white/[0.02] flex items-center gap-2">
          <button
            class="flex-1 px-3 py-2 bg-gold hover:bg-gold/90 text-black rounded-lg text-sm font-bold transition-all"
            @click="openEdit(p)"
          >
            تعديل
          </button>
          <button
            class="p-2 hover:bg-error/10 rounded-lg text-text-muted hover:text-error transition-all"
            title="حذف"
            @click="confirmDelete(p)"
          >
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <div v-else class="bg-card-bg border border-white/10 rounded-2xl p-12 text-center">
      <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-6">
        <Network class="w-10 h-10 text-white/20" />
      </div>
      <h3 class="text-xl font-bold mb-2">لا يوجد مزودون</h3>
      <p class="text-text-muted max-w-md mx-auto mb-6">يمكنك إضافة مزود جديد، أو إدارتهم من فيليمنت.</p>
      <button class="inline-flex items-center gap-2 px-5 py-2 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold" @click="openCreate">
        <Plus class="w-4 h-4" />
        إضافة
      </button>
    </div>

    <div
      v-if="showModal"
      class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4"
      @click.self="closeModal"
    >
      <div class="bg-card-bg border border-white/10 rounded-2xl w-full max-w-xl p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="font-bold text-xl text-text-main mb-6">
          {{ isEditing ? 'تعديل مزود' : 'إضافة مزود' }}
        </h3>

        <form class="space-y-4" @submit.prevent="submit">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold mb-2">الكود <span class="text-error">*</span></label>
              <input
                v-model="form.code"
                type="text"
                required
                placeholder="fawry"
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-semibold mb-2">اللون</label>
              <input
                v-model="form.color"
                type="text"
                placeholder="#F59E0B"
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm font-mono"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold mb-2">الاسم بالعربية <span class="text-error">*</span></label>
              <input
                v-model="form.name_ar"
                type="text"
                required
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm focus:border-gold outline-none"
              />
            </div>
            <div>
              <label class="block text-sm font-semibold mb-2">الاسم بالإنجليزية <span class="text-error">*</span></label>
              <input
                v-model="form.name_en"
                type="text"
                required
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm focus:border-gold outline-none"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold mb-2">الوصف</label>
            <textarea
              v-model="form.description_ar"
              rows="2"
              class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm focus:border-gold outline-none resize-none"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold mb-2">رقم تواصل</label>
              <input
                v-model="form.contact_phone"
                type="text"
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm font-mono"
              />
            </div>
            <div>
              <label class="block text-sm font-semibold mb-2">رقم/كود الحساب</label>
              <input
                v-model="form.contact_account"
                type="text"
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm font-mono"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold mb-2">حساب التسوية الافتراضي</label>
            <select
              v-model="form.default_purchase_account_id"
              class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm cursor-pointer"
            >
              <option :value="null">— بدون تحديد (يُستخدم حساب التحصيل) —</option>
              <option v-for="a in store.accounts" :key="a.id" :value="a.id">
                {{ a.name }} ({{ a.type }})
              </option>
            </select>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold mb-2">الترتيب</label>
              <input
                v-model.number="form.order"
                type="number"
                min="0"
                class="w-full px-3 py-2.5 bg-input-bg border border-white/10 rounded-xl text-sm font-mono"
              />
            </div>
            <div class="flex items-end">
              <label class="flex items-center gap-3 cursor-pointer">
                <input
                  v-model="form.is_active"
                  type="checkbox"
                  class="w-5 h-5 rounded border-white/10 bg-input-bg text-gold focus:ring-gold"
                />
                <span class="text-sm">نشط</span>
              </label>
            </div>
          </div>

          <div class="flex gap-3 pt-2">
            <button
              type="submit"
              :disabled="store.loading.create || store.loading.update"
              class="flex-1 px-4 py-2.5 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold disabled:opacity-50"
            >
              {{ store.loading.create || store.loading.update ? '...' : (isEditing ? 'حفظ' : 'إضافة') }}
            </button>
            <button
              type="button"
              class="px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold"
              @click="closeModal"
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
import { onMounted, ref } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { Plus, Search, Trash2, Network, Phone, Hash, Wallet } from 'lucide-vue-next';
import { useOnlineStore } from '@/stores/onlineStore';

const store = useOnlineStore();

const search = ref('');
const statusFilter = ref('');
const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const emptyForm = () => ({
  code: '',
  name_ar: '',
  name_en: '',
  description_ar: '',
  color: '#6B7280',
  contact_phone: '',
  contact_account: '',
  default_purchase_account_id: null,
  order: 0,
  is_active: true,
});

const form = ref(emptyForm());

const onSearch = useDebounceFn(() => fetch(), 300);

const fetch = async () => {
  await store.fetchProviders({
    search: search.value || undefined,
    is_active: statusFilter.value === '' ? undefined : statusFilter.value,
    per_page: 50,
  });
};

const openCreate = () => {
  isEditing.value = false;
  editingId.value = null;
  form.value = emptyForm();
  showModal.value = true;
};

const openEdit = (p) => {
  isEditing.value = true;
  editingId.value = p.id;
  form.value = {
    code: p.code,
    name_ar: p.name_ar ?? p.name ?? '',
    name_en: p.name_en ?? '',
    description_ar: p.description_ar ?? p.description ?? '',
    color: p.color ?? '#6B7280',
    contact_phone: p.contact_phone ?? '',
    contact_account: p.contact_account ?? '',
    default_purchase_account_id: p.default_purchase_account_id ?? null,
    order: p.order ?? 0,
    is_active: p.is_active ?? true,
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = emptyForm();
};

const submit = async () => {
  try {
    if (isEditing.value) {
      await store.updateProvider(editingId.value, form.value);
    } else {
      await store.createProvider(form.value);
    }
    closeModal();
    await fetch();
  } catch (error) {
    /* toast handled */
  }
};

const confirmDelete = async (p) => {
  if (!window.confirm(`حذف المزود "${p.name_ar ?? p.name}"؟`)) return;
  try {
    await store.deleteProvider(p.id);
  } catch (error) {
    /* handled */
  }
};

onMounted(async () => {
  await store.fetchAllSettings();
  await fetch();
});
</script>

<style scoped>
.bg-card-bg { background-color: var(--card-bg); }
.bg-input-bg { background-color: var(--input-bg); }
.text-text-main { color: var(--text-main); }
.text-text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.bg-success\/10 { background-color: color-mix(in srgb, var(--success) 10%, transparent); }
.text-error { color: var(--error); }
.bg-error\/10 { background-color: color-mix(in srgb, var(--error) 10%, transparent); }
.font-mono { font-family: 'IBM Plex Sans Arabic', sans-serif; }
</style>
