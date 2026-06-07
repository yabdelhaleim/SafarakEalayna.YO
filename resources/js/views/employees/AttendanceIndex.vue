<template>
  <div class="space-y-8 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-text-main tracking-tight">
          الحضور والانصراف
        </h1>
        <p class="text-text-muted mt-1">
          إدارة ومتابعة سجل الحضور والانصراف
        </p>
      </div>
      <button
        @click="openMarkModal"
        class="bg-gold hover:bg-gold/90 text-black px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-gold/20 hover:scale-[1.02] active:scale-[0.98]"
      >
        <CheckCircle class="w-5 h-5" />
        تسجيل حضور
      </button>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-success/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-success/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-success/10 rounded-xl text-success group-hover:scale-110 transition-transform">
            <UserCheck class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-success/10 text-success">
            حاضر
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">الحاضرون اليوم</div>
          <div class="text-2xl font-bold font-mono group-hover:text-success transition-colors">
            {{ store.stats.present_today }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-error/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-error/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-error/10 rounded-xl text-error group-hover:scale-110 transition-transform">
            <UserX class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-error/10 text-error">
            غائب
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">الغائبون اليوم</div>
          <div class="text-2xl font-bold font-mono group-hover:text-error transition-colors">
            {{ store.stats.absent_today }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-warning/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-warning/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-warning/10 rounded-xl text-warning group-hover:scale-110 transition-transform">
            <CalendarClock class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-warning/10 text-warning">
            إجازة
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">في إجازة</div>
          <div class="text-2xl font-bold font-mono group-hover:text-warning transition-colors">
            {{ employeesOnLeave }}
          </div>
        </div>
      </div>

      <div class="p-6 bg-card-bg border border-white/10 rounded-2xl flex flex-col justify-between group hover:border-blue-400/30 transition-all hover:-translate-y-0.5 hover:shadow-lg hover:shadow-blue-400/10">
        <div class="flex justify-between items-start mb-4">
          <div class="p-3 bg-blue-500/10 rounded-xl text-blue-500 group-hover:scale-110 transition-transform">
            <Percent class="w-6 h-6" />
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-full bg-blue-500/10 text-blue-500">
            نسبة
          </span>
        </div>
        <div>
          <div class="text-sm text-text-muted uppercase tracking-widest mb-1">نسبة الحضور</div>
          <div class="text-2xl font-bold font-mono group-hover:text-blue-400 transition-colors">
            {{ attendancePercentage }}%
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="p-4 bg-card-bg border border-white/10 rounded-2xl flex flex-wrap items-center gap-4">
      <div class="flex-1 min-w-[240px] relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
        <input
          v-model="searchQuery"
          type="text"
          placeholder="بحث بالاسم..."
          class="w-full pl-10 pr-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
        />
      </div>

      <input
        v-model="selectedDate"
        type="date"
        class="px-4 py-2.5 bg-input-bg border border-white/5 rounded-xl focus:border-gold outline-none text-sm"
      />

      <button
        @click="goToToday"
        class="text-sm text-gold hover:text-gold/80 transition-colors px-4 py-2"
      >
        اليوم
      </button>
    </div>

    <!-- Attendance Table -->
    <div v-if="store.loading.attendance" class="bg-card-bg border border-white/10 rounded-2xl p-12">
      <div class="flex items-center justify-center">
        <Loader2 class="w-8 h-8 text-gold animate-spin" />
      </div>
    </div>

    <div v-else class="bg-card-bg border border-white/10 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b border-white/5">
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الموظف
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                القسم
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                الحضور
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                وقت الحضور
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                وقت الانصراف
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                ساعات العمل
              </th>
              <th class="px-6 py-4 text-right text-xs font-bold text-text-muted uppercase tracking-wider">
                ملاحظات
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="employeeRecord in filteredRecords"
              :key="employeeRecord.employee.id"
              class="border-b border-white/5 hover:bg-white/5 transition-colors"
            >
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-full bg-gold/10 flex items-center justify-center text-gold font-bold text-sm">
                    {{ getInitials(employeeRecord.employee.name) }}
                  </div>
                  <div>
                    <div class="font-semibold text-sm">{{ employeeRecord.employee.name }}</div>
                    <div class="text-xs text-text-muted">
                      {{ employeeRecord.employee.position }}
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4">
                <span class="text-sm">{{ getDepartmentLabel(employeeRecord.employee.department) }}</span>
              </td>
              <td class="px-6 py-4">
                <span
                  :class="[
                    'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase',
                    getAttendanceStatusClass(employeeRecord)
                  ]"
                >
                  <component
                    :is="getAttendanceStatusIcon(employeeRecord)"
                    class="w-3 h-3"
                  />
                  {{ getAttendanceStatusLabel(employeeRecord) }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="font-mono text-sm">
                  {{ employeeRecord.record?.check_in || '--:--' }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="font-mono text-sm">
                  {{ employeeRecord.record?.check_out || '--:--' }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="font-mono font-bold text-sm" :class="getHoursClass(employeeRecord)">
                  {{ calculateWorkHours(employeeRecord) }}
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="text-sm text-text-muted">
                  {{ employeeRecord.record?.notes || '-' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mark Attendance Modal -->
    <div
      v-if="showMarkModal"
      class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
    >
      <div class="bg-card-bg border border-white/10 rounded-2xl w-full max-w-md p-6">
        <h3 class="font-display font-extrabold text-xl text-text-main mb-6">
          تسجيل حضور
        </h3>
        <form @submit.prevent="submitAttendance" class="space-y-4">
          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              الموظف *
            </label>
            <select
              v-model="attendanceForm.employee_id"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer"
            >
              <option value="">اختر الموظف</option>
              <option
                v-for="employee in activeEmployees"
                :key="employee.id"
                :value="employee.id"
              >
                {{ employee.name }} - {{ employee.position }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              التاريخ *
            </label>
            <input
              v-model="attendanceForm.date"
              type="date"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                وقت الحضور
              </label>
              <input
                v-model="attendanceForm.check_in"
                type="time"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-text-main mb-2">
                وقت الانصراف
              </label>
              <input
                v-model="attendanceForm.check_out"
                type="time"
                class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              الحالة *
            </label>
            <select
              v-model="attendanceForm.present"
              required
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm appearance-none cursor-pointer"
            >
              <option :value="true">حاضر</option>
              <option :value="false">غائب</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-text-main mb-2">
              ملاحظات
            </label>
            <textarea
              v-model="attendanceForm.notes"
              rows="2"
              placeholder="أي ملاحظات..."
              class="w-full px-4 py-3 bg-input-bg border border-white/10 rounded-xl focus:border-gold outline-none text-sm resize-none"
            ></textarea>
          </div>

          <div class="flex gap-3">
            <button
              type="submit"
              :disabled="store.loading.create"
              class="flex-1 px-4 py-3 bg-gold hover:bg-gold/90 text-black rounded-xl font-bold transition-all disabled:opacity-50"
            >
              {{ store.loading.create ? 'جاري التسجيل...' : 'تسجيل' }}
            </button>
            <button
              type="button"
              @click="closeMarkModal"
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
import { useEmployeeStore } from '@/stores/employeeStore';
import {
  Search,
  CheckCircle,
  Loader2,
  UserCheck,
  UserX,
  CalendarClock,
  Percent,
  Clock,
  XCircle,
  AlertCircle,
} from 'lucide-vue-next';

const store = useEmployeeStore();

const searchQuery = ref('');
const selectedDate = ref(new Date().toISOString().split('T')[0]);
const showMarkModal = ref(false);

const attendanceForm = ref({
  employee_id: null,
  date: new Date().toISOString().split('T')[0],
  check_in: '',
  check_out: '',
  present: true,
  notes: '',
});

// Active employees
const activeEmployees = computed(() => store.activeEmployees);

// Employees on leave
const employeesOnLeave = computed(() => store.employeesOnLeave.length);

// Attendance percentage
const attendancePercentage = computed(() => {
  const total = store.stats.total_employees;
  const present = store.stats.present_today;
  if (total === 0) return 0;
  return Math.round((present / total) * 100);
});

// Employee records with attendance
const filteredRecords = computed(() => {
  let records = store.activeEmployees.map((employee) => {
    const attendance = store.attendance.find((a) =>
      a.employee_id === employee.id &&
      (a.date === selectedDate.value || a.attendance_date === selectedDate.value)
    );

    return {
      employee,
      record: attendance || null,
    };
  });

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    records = records.filter((r) =>
      r.employee.name?.toLowerCase().includes(query)
    );
  }

  return records;
});

// Get initials
const getInitials = (name) => {
  if (!name) return '؟';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
};

// Get department label
const getDepartmentLabel = (department) => {
  const dept = store.departments.find((d) => d.value === department);
  return dept?.label || department;
};

// Get attendance status class
const getAttendanceStatusClass = (employeeRecord) => {
  if (!employeeRecord.record) return 'bg-gray-500/10 text-gray-400';
  if (!employeeRecord.record.present) return 'bg-error/10 text-error';
  if (employeeRecord.employee.status === 'on_leave') return 'bg-warning/10 text-warning';
  return 'bg-success/10 text-success';
};

// Get attendance status icon
const getAttendanceStatusIcon = (employeeRecord) => {
  if (!employeeRecord.record) return AlertCircle;
  if (!employeeRecord.record.present) return XCircle;
  if (employeeRecord.employee.status === 'on_leave') return CalendarClock;
  return CheckCircle;
};

// Get attendance status label
const getAttendanceStatusLabel = (employeeRecord) => {
  if (!employeeRecord.record) return 'غير مسجل';
  if (!employeeRecord.record.present) return 'غائب';
  if (employeeRecord.employee.status === 'on_leave') return 'إجازة';
  return 'حاضر';
};

// Calculate work hours
const calculateWorkHours = (employeeRecord) => {
  if (!employeeRecord.record?.check_in || !employeeRecord.record?.check_out) {
    return '--';
  }

  const checkIn = new Date(`2000-01-01T${employeeRecord.record.check_in}`);
  const checkOut = new Date(`2000-01-01T${employeeRecord.record.check_out}`);
  const diff = (checkOut - checkIn) / (1000 * 60 * 60);

  return `${diff.toFixed(1)} ساعة`;
};

// Get hours class
const getHoursClass = (employeeRecord) => {
  const hours = parseFloat(calculateWorkHours(employeeRecord));
  if (hours === '--') return 'text-text-muted';
  if (hours >= 8) return 'text-success';
  if (hours >= 6) return 'text-warning';
  return 'text-error';
};

// Go to today
const goToToday = () => {
  selectedDate.value = new Date().toISOString().split('T')[0];
};

// Open mark modal
const openMarkModal = () => {
  attendanceForm.value = {
    employee_id: null,
    date: selectedDate.value,
    check_in: '',
    check_out: '',
    present: true,
    notes: '',
  };
  showMarkModal.value = true;
};

// Close mark modal
const closeMarkModal = () => {
  showMarkModal.value = false;
};

// Submit attendance
const submitAttendance = async () => {
  try {
    await store.markAttendance(attendanceForm.value);
    store.addToast('تم تسجيل الحضور بنجاح');
    closeMarkModal();
    await store.fetchAttendance();
    await store.fetchStats();
  } catch (error) {
    store.addToast('فشل تسجيل الحضور', 'error');
  }
};

watch(selectedDate, async (newDate) => {
  if (newDate) {
    await store.fetchAttendance({ from_date: newDate, to_date: newDate, per_page: 100 });
  }
});

onMounted(async () => {
  await store.fetchEmployees();
  await store.fetchAttendance({ from_date: selectedDate.value, to_date: selectedDate.value, per_page: 100 });
  await store.fetchStats();
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

.bg-success {
  background-color: var(--success);
}

.text-error {
  color: var(--error);
}

.bg-error {
  background-color: var(--error);
}

.text-warning {
  color: var(--warning);
}

.bg-warning {
  background-color: var(--warning);
}

.text-blue-400 {
  color: #4F8EF7;
}

.text-blue-500 {
  color: #4F8EF7;
}

.bg-blue-500 {
  background-color: #4F8EF7;
}

.text-gray-400 {
  color: #9CA3AF;
}

.bg-gray-500 {
  background-color: #6B7280;
}

.font-mono {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}

.font-display {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
}
</style>
