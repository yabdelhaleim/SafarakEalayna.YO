<template>
  <div class="space-y-8 animate-in fade-in duration-700 pb-12">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-card-bg border border-white/10 p-6 rounded-3xl relative overflow-hidden">
      <!-- Background Glow -->
      <div class="absolute top-0 left-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl -ml-20 -mt-20 pointer-events-none"></div>
      
      <div class="relative z-10">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-tight flex items-center gap-3">
          <ShieldCheck class="w-10 h-10 text-indigo-400" />
          إدارة الصلاحيات والمستخدمين
        </h1>
        <p class="text-white/60 mt-2 font-medium text-lg">
          إنشاء حسابات الموظفين وتحديد الصلاحيات والموديولات المسموح لهم بالوصول إليها.
        </p>
      </div>

      <div class="relative z-10">
        <button 
          @click="openModal()"
          class="bg-indigo-500 hover:bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold transition-all shadow-lg shadow-indigo-500/25 flex items-center gap-2"
        >
          <UserPlus class="w-5 h-5" />
          إضافة مستخدم جديد
        </button>
      </div>
    </div>

    <div v-if="loadError" class="p-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 text-rose-300">
      {{ loadError }}
      <button class="mr-3 underline font-bold" @click="fetchUsers">إعادة المحاولة</button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-20">
      <div class="flex flex-col items-center gap-4">
        <Loader2 class="w-12 h-12 text-indigo-500 animate-spin" />
        <p class="text-indigo-400 font-bold animate-pulse">جاري تحميل بيانات المستخدمين...</p>
      </div>
    </div>

    <template v-else>
      <!-- Users Table -->
      <div class="bg-card-bg border border-white/10 rounded-3xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-right">
            <thead>
              <tr class="bg-white/5 border-b border-white/10 text-sm text-text-muted font-bold">
                <th class="p-4">المستخدم</th>
                <th class="p-4">الدور (Role)</th>
                <th class="p-4">الصلاحيات الممنوحة</th>
                <th class="p-4 text-center">الحالة</th>
                <th class="p-4 text-center">تاريخ الإنشاء</th>
                <th class="p-4 text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody class="text-white divide-y divide-white/5">
              <tr v-if="users.length === 0" class="text-center">
                <td colspan="6" class="p-8 text-white/40">لا يوجد مستخدمين مضافين بعد</td>
              </tr>
              <tr v-for="user in users" :key="user.id" class="hover:bg-white/5 transition-colors group">
                <td class="p-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold shadow-lg">
                      {{ user.name.charAt(0) }}
                    </div>
                    <div>
                      <div class="font-bold text-base">{{ user.name }}</div>
                      <div class="text-xs text-white/50">{{ user.email }}</div>
                    </div>
                  </div>
                </td>
                <td class="p-4">
                  <span v-if="user.role === 'admin'" class="px-3 py-1 bg-rose-500/20 text-rose-400 rounded-lg text-xs font-bold border border-rose-500/20">مدير نظام (Admin)</span>
                  <span v-else-if="user.role === 'owner'" class="px-3 py-1 bg-gold/20 text-gold rounded-lg text-xs font-bold border border-gold/20">المالك (Owner)</span>
                  <span v-else class="px-3 py-1 bg-blue-500/20 text-blue-400 rounded-lg text-xs font-bold border border-blue-500/20">موظف (Employee)</span>
                </td>
                <td class="p-4 max-w-xs">
                  <div class="flex flex-wrap gap-1">
                    <span v-if="user.role === 'admin' || user.role === 'owner'" class="px-2 py-0.5 bg-gold/10 text-gold rounded text-xs font-bold">
                      صلاحيات كاملة
                    </span>
                    <template v-else-if="displayPermissions(user).length">
                      <span v-for="perm in displayPermissions(user)" :key="perm" class="px-2 py-0.5 bg-white/10 text-white/80 rounded text-xs">
                        {{ getPermissionLabel(perm) }}
                      </span>
                    </template>
                    <span v-else class="text-white/30 text-xs">لا يوجد صلاحيات محددة</span>
                  </div>
                </td>
                <td class="p-4 text-center">
                  <span v-if="user.is_active" class="inline-flex items-center gap-1 text-emerald-400 text-sm font-bold">
                    <CheckCircle2 class="w-4 h-4" /> نشط
                  </span>
                  <span v-else class="inline-flex items-center gap-1 text-rose-400 text-sm font-bold">
                    <XCircle class="w-4 h-4" /> موقوف
                  </span>
                </td>
                <td class="p-4 text-center text-sm text-white/60">
                  {{ new Date(user.created_at).toLocaleDateString('ar-EG') }}
                </td>
                <td class="p-4 text-center">
                  <div class="flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button @click="openModal(user)" class="p-2 bg-white/10 hover:bg-indigo-500/20 text-white hover:text-indigo-400 rounded-lg transition-colors" title="تعديل">
                      <Edit class="w-4 h-4" />
                    </button>
                    <button
                      v-if="user.role !== 'owner'"
                      @click="deleteUser(user.id)"
                      class="p-2 bg-white/10 hover:bg-rose-500/20 text-white hover:text-rose-400 rounded-lg transition-colors"
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
      </div>
    </template>

    <!-- User Modal -->
    <div v-if="modal.isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-2xl rounded-3xl shadow-2xl relative z-10 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-white/10 bg-indigo-500/10 shrink-0">
          <h3 class="text-2xl font-bold text-white flex items-center gap-2">
            <UserPlus v-if="!modal.isEditing" class="w-6 h-6 text-indigo-400" />
            <Edit v-else class="w-6 h-6 text-indigo-400" />
            {{ modal.isEditing ? 'تعديل بيانات المستخدم' : 'إضافة مستخدم جديد' }}
          </h3>
        </div>
        
        <div class="p-6 overflow-y-auto">
          <form @submit.prevent="saveUser" class="space-y-6">
            <div v-if="modal.error" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-4 rounded-xl text-sm">
              {{ modal.error }}
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Basic Info -->
              <div class="space-y-4">
                <h4 class="text-gold font-bold mb-2">البيانات الأساسية</h4>
                
                <div>
                  <label class="block text-sm font-medium text-white/70 mb-2">الاسم بالكامل</label>
                  <input type="text" v-model="modal.form.name" required class="w-full bg-black/40 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-indigo-500 transition-colors" />
                </div>

                <div>
                  <label class="block text-sm font-medium text-white/70 mb-2">البريد الإلكتروني (تسجيل الدخول)</label>
                  <input type="email" v-model="modal.form.email" required class="w-full bg-black/40 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-indigo-500 transition-colors" />
                </div>

                <div>
                  <label class="block text-sm font-medium text-white/70 mb-2">كلمة المرور <span v-if="modal.isEditing" class="text-xs text-white/40">(اتركها فارغة إذا لم ترد التغيير)</span></label>
                  <input type="password" v-model="modal.form.password" :required="!modal.isEditing" class="w-full bg-black/40 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-indigo-500 transition-colors" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-white/70 mb-2">الدور</label>
                    <select v-model="modal.form.role" required class="w-full bg-black/40 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-indigo-500 transition-colors">
                      <option value="employee">موظف (Employee)</option>
                      <option value="admin">مدير (Admin)</option>
                    </select>
                  </div>
                  
                  <div class="flex items-center pt-8">
                    <label class="flex items-center gap-3 cursor-pointer">
                      <input type="checkbox" v-model="modal.form.is_active" class="w-5 h-5 rounded border-white/20 bg-black/40 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0" />
                      <span class="text-white font-medium">الحساب نشط</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Permissions -->
              <div class="bg-black/20 p-4 rounded-2xl border border-white/5 space-y-4">
                <h4 class="text-gold font-bold mb-2">الصلاحيات والموديولات</h4>
                <p class="text-xs text-white/50 mb-4">حدد ما يمكن للمستخدم رؤيته والعمل عليه في البرنامج.</p>
                
                <div class="space-y-3 max-h-[300px] overflow-y-auto pr-2">
                  <p class="text-xs font-bold text-indigo-300">موديولات التشغيل</p>
                  <label v-for="perm in modulePermissions" :key="perm.id" class="flex items-center gap-3 p-3 rounded-xl hover:bg-white/5 cursor-pointer transition-colors border border-transparent hover:border-white/10">
                    <input type="checkbox" :value="perm.id" v-model="modal.form.permissions" class="w-5 h-5 rounded border-white/20 bg-black/40 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0" />
                    <div class="flex flex-col">
                      <span class="text-white font-bold">{{ perm.name }}</span>
                      <span class="text-white/40 text-xs">{{ perm.desc }}</span>
                    </div>
                  </label>

                  <p class="text-xs font-bold text-sky-300 pt-2">الإدارة والمالية</p>
                  <label v-for="perm in adminPermissions" :key="perm.id" class="flex items-center gap-3 p-3 rounded-xl hover:bg-white/5 cursor-pointer transition-colors border border-transparent hover:border-white/10">
                    <input type="checkbox" :value="perm.id" v-model="modal.form.permissions" class="w-5 h-5 rounded border-white/20 bg-black/40 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0" />
                    <div class="flex flex-col">
                      <span class="text-white font-bold">{{ perm.name }}</span>
                      <span class="text-white/40 text-xs">{{ perm.desc }}</span>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </form>
        </div>

        <div class="p-6 border-t border-white/10 flex gap-3 shrink-0 bg-card-bg">
          <button type="button" @click="closeModal" class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors">
            إلغاء
          </button>
          <button type="button" @click="saveUser" :disabled="modal.isSubmitting" class="flex-1 bg-indigo-500 hover:bg-indigo-600 text-white py-3 rounded-xl font-bold transition-all flex justify-center items-center gap-2">
            <span v-if="modal.isSubmitting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
            <span v-else>حفظ التغييرات</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
  USER_PERMISSIONS,
  DEFAULT_EMPLOYEE_MODULE_PERMISSIONS,
  getPermissionLabel,
} from '@/constants/userPermissions';
import {
  ShieldCheck,
  UserPlus,
  Loader2,
  Edit,
  Trash2,
  CheckCircle2,
  XCircle
} from 'lucide-vue-next';

const loading = ref(true);
const loadError = ref('');
const users = ref([]);
const availablePermissions = ref([...USER_PERMISSIONS]);
let fetchController = null;

const modulePermissions = computed(() => availablePermissions.value.filter((perm) => perm.group === 'modules'));
const adminPermissions = computed(() => availablePermissions.value.filter((perm) => perm.group === 'admin'));

const displayPermissions = (user) => {
  if (user.permissions?.length) {
    return user.permissions;
  }
  return user.effective_permissions || [];
};

const modal = ref({
  isOpen: false,
  isEditing: false,
  isSubmitting: false,
  error: '',
  form: {
    id: null,
    name: '',
    email: '',
    password: '',
    role: 'employee',
    is_active: true,
    permissions: []
  }
});

const fetchUsers = async () => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  loading.value = true;
  loadError.value = '';
  try {
    const res = await axios.get('/api/v1/users', {
      params: { _t: Date.now() },
      signal,
    });
    const payload = res.data?.data || {};
    users.value = Array.isArray(payload) ? payload : (payload.users || []);
    if (payload.available_permissions?.length) {
      availablePermissions.value = payload.available_permissions;
    }
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Failed to fetch users', error);
    loadError.value = error.response?.data?.message || 'حدث خطأ أثناء جلب المستخدمين';
    if (window.addToast) window.addToast(loadError.value, 'error');
  } finally {
    loading.value = false;
  }
};

const openModal = (user = null) => {
  modal.value.error = '';
  modal.value.isSubmitting = false;
  if (user) {
    modal.value.isEditing = true;
    modal.value.form = {
      id: user.id,
      name: user.name,
      email: user.email,
      password: '', // leave empty if no change
      role: user.role,
      is_active: user.is_active,
      permissions: user.permissions ? [...user.permissions] : []
    };
  } else {
    modal.value.isEditing = false;
    modal.value.form = {
      id: null,
      name: '',
      email: '',
      password: '',
      role: 'employee',
      is_active: true,
      permissions: [...DEFAULT_EMPLOYEE_MODULE_PERMISSIONS],
    };
  }
  modal.value.isOpen = true;
};

const closeModal = () => {
  modal.value.isOpen = false;
};

const saveUser = async () => {
  modal.value.isSubmitting = true;
  modal.value.error = '';
  
  try {
    const { id, ...payload } = modal.value.form;

    if (modal.value.isEditing) {
      if (!payload.password) delete payload.password;
      await axios.put(`/api/v1/users/${id}`, payload);
      if(window.addToast) window.addToast('تم تحديث المستخدم بنجاح', 'success');
    } else {
      await axios.post('/api/v1/users', payload);
      if(window.addToast) window.addToast('تم إضافة المستخدم بنجاح', 'success');
    }
    
    closeModal();
    fetchUsers();
  } catch (error) {
    modal.value.error = error.response?.data?.message || 'حدث خطأ أثناء حفظ المستخدم';
  } finally {
    modal.value.isSubmitting = false;
  }
};

const deleteUser = async (id) => {
  if (confirm('هل أنت متأكد من حذف هذا المستخدم؟ لا يمكن التراجع عن هذا الإجراء.')) {
    try {
      await axios.delete(`/api/v1/users/${id}`);
      if(window.addToast) window.addToast('تم حذف المستخدم بنجاح', 'success');
      fetchUsers();
    } catch (error) {
      if(window.addToast) window.addToast(error.response?.data?.message || 'لا يمكن حذف هذا المستخدم', 'error');
    }
  }
};

onMounted(() => {
  fetchUsers();
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
});
</script>

<style scoped>
.bg-card-bg {
  background-color: var(--card-bg);
}
</style>
