<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <router-link
        to="/employees"
        class="p-2 hover:bg-white/10 rounded-lg transition-all"
      >
        <ArrowRight class="w-5 h-5 text-text-muted" />
      </router-link>
      <div>
        <h1 class="text-4xl font-extrabold text-text-main tracking-tight">
          موظف جديد
        </h1>
        <p class="text-text-muted mt-1">إضافة موظف جديد للنظام</p>
      </div>
    </div>

    <!-- Form -->
    <div class="bg-card-bg border border-white/10 rounded-2xl p-8">
      <form @submit.prevent="handleSubmit" class="space-y-6">
        <!-- Basic Info -->
        <div>
          <h3 class="font-display font-extrabold text-lg text-gold mb-4">
            المعلومات الأساسية
          </h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                الاسم الكامل *
              </label>
              <input
                v-model="form.name"
                type="text"
                required
                placeholder="أحمد محمد"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                البريد الإلكتروني *
              </label>
              <input
                v-model="form.email"
                type="email"
                required
                placeholder="employee@example.com"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                رقم الهاتف *
              </label>
              <input
                v-model="form.phone"
                type="tel"
                required
                placeholder="+20 100 123 4567"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                العنوان
              </label>
              <input
                v-model="form.address"
                type="text"
                placeholder="القاهرة، مصر"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>
          </div>
        </div>

        <!-- Work Info -->
        <div>
          <h3 class="font-display font-extrabold text-lg text-gold mb-4">
            معلومات العمل
          </h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                القسم *
              </label>
              <select
                v-model="form.department"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer"
              >
                <option value="">اختر القسم</option>
                <option
                  v-for="dept in store.departments"
                  :key="dept.value"
                  :value="dept.value"
                >
                  {{ dept.label }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                المسمى الوظيفي *
              </label>
              <input
                v-model="form.position"
                type="text"
                required
                placeholder="مدير مبيعات"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                الراتب الشهري *
              </label>
              <div class="relative">
                <input
                  v-model.number="form.salary"
                  type="number"
                  step="0.01"
                  min="0"
                  required
                  placeholder="0.00"
                  class="w-full pl-4 pr-16 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
                />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted text-sm">
                  جنيه
                </span>
              </div>
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                تاريخ التعيين *
              </label>
              <input
                v-model="form.hire_date"
                type="date"
                required
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
              />
            </div>
          </div>
        </div>

        <!-- Additional Settings -->
        <div>
          <h3 class="font-display font-extrabold text-lg text-gold mb-4">
            إعدادات إضافية
          </h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                ملاحظات
              </label>
              <textarea
                v-model="form.notes"
                rows="3"
                placeholder="أي ملاحظات أو معلومات إضافية..."
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm resize-none"
              ></textarea>
            </div>

            <div class="flex items-center gap-3">
              <input
                v-model="form.is_active"
                type="checkbox"
                id="is_active"
                class="w-5 h-5 rounded border-white/10 bg-input-bg text-gold focus:ring-gold"
              />
              <label for="is_active" class="text-sm text-text-main">
                موظف نشط (يمكنه تسجيل الدخول)
              </label>
            </div>
          </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex gap-4 pt-4">
          <button
            type="submit"
            :disabled="store.loading.create"
            class="flex-1 px-6 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all disabled:opacity-50 flex items-center justify-center gap-2"
          >
            <CheckCircle v-if="!store.loading.create" class="w-4 h-4" />
            <Loader2 v-else class="w-4 h-4 animate-spin" />
            {{ store.loading.create ? 'جاري الحفظ...' : 'حفظ الموظف' }}
          </button>
          <router-link
            to="/employees"
            class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl font-semibold transition-all"
          >
            إلغاء
          </router-link>
        </div>
      </form>
    </div>

    <!-- Info Box -->
    <div class="p-4 bg-blue-500/10 border border-blue-500/20 rounded-xl">
      <div class="flex items-start gap-3">
        <Info class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
        <div class="text-sm">
          <p class="font-semibold text-blue-500 mb-1">معلومات مهمة</p>
          <p class="text-text-muted">
            سيتم إرسال بريد إلكتروني للموظف يحتوي على بيانات الدخول الخاصة به بعد الحفظ.
            تأكد من صحة البريد الإلكتروني.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useEmployeeStore } from '@/stores/employeeStore';
import {
  ArrowRight,
  CheckCircle,
  Loader2,
  Info,
} from 'lucide-vue-next';

const router = useRouter();
const store = useEmployeeStore();

const form = ref({
  name: '',
  email: '',
  phone: '',
  address: '',
  department: '',
  position: '',
  salary: null,
  hire_date: new Date().toISOString().split('T')[0],
  notes: '',
  is_active: true,
});

const handleSubmit = async () => {
  try {
    await store.createEmployee(form.value);
    store.addToast('تم إضافة الموظف بنجاح');
    router.push('/employees');
  } catch (error) {
    store.addToast('فشل إضافة الموظف', 'error');
  }
};

onMounted(async () => {
  await store.fetchEmployeeReferenceData();
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

.text-blue-500 {
  color: #4F8EF7;
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
