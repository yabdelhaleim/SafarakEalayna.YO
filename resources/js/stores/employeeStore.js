import { defineStore } from 'pinia';
import axios from 'axios';

const DEFAULT_DEPARTMENTS = [
  { value: 'sales', label: 'المبيعات' },
  { value: 'finance', label: 'المالية' },
  { value: 'operations', label: 'العمليات' },
  { value: 'customer_service', label: 'خدمة العملاء' },
  { value: 'admin', label: 'الإدارة' },
  { value: 'it', label: 'تقنية المعلومات' },
];

const parseAmount = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

const mapAttendance = (item) => {
  if (!item || typeof item !== 'object') return null;
  const rawStatus = item.status;
  const status = typeof rawStatus === 'object' && rawStatus !== null && 'value' in rawStatus
    ? rawStatus.value
    : rawStatus;
  const isPresent = item.present !== undefined
    ? item.present
    : (status === 'present' || status === 'late');
  return {
    ...item,
    date: item.date || item.attendance_date,
    attendance_date: item.attendance_date || item.date,
    check_in: item.check_in || null,
    check_out: item.check_out || null,
    present: isPresent,
    status,
  };
};

const normalizeListItems = (rawItems) => {
  if (!Array.isArray(rawItems)) {
    return [];
  }
  return rawItems.filter((item) => item && typeof item === 'object');
};

const mapEmployee = (item) => {
  if (!item) return item;
  const personal = item.personal_info || {};
  const contact = item.contact_info || {};
  const employment = item.employment_info || {};
  const department = item.department || employment.department || null;
  const position = item.position || employment.position || employment.job_title || null;
  return {
    ...item,
    name: item.name
      || item.full_name
      || (item.user && item.user.name)
      || personal.full_name
      || `${personal.first_name || ''} ${personal.last_name || ''}`.trim()
      || '—',
    phone: item.phone || contact.phone || (item.user && item.user.phone) || '—',
    email: item.email || contact.email || (item.user && item.user.email) || '—',
    address: item.address || contact.address || '',
    department,
    position,
    hire_date: item.hire_date || employment.hire_date || null,
    salary: parseAmount(item.salary),
    is_active: item.is_active ?? (item.status === 'active'),
  };
};

export const useEmployeeStore = defineStore('employee', {
  state: () => ({
    // Employees
    employees: [],
    currentEmployee: null,

    // Attendance
    attendance: [],

    // Bonuses and Deductions
    bonuses: [],
    deductions: [],

    // Stats
    stats: {
      total_employees: 0,
      active_employees: 0,
      present_today: 0,
      absent_today: 0,
      total_bonuses: 0,
      total_deductions: 0,
      net_payroll: 0,
    },

    // Loading States
    loading: {
      employees: false,
      attendance: false,
      bonuses: false,
      create: false,
      update: false,
      delete: false,
    },

    // Errors
    errors: {},

    // Filters
    filters: {
      search: '',
      department: '',
      status: '',
      date_from: '',
      date_to: '',
      page: 1,
      per_page: 15,
    },

    // Pagination
    pagination: {
      total: 0,
      current_page: 1,
      last_page: 1,
      per_page: 15,
    },

    departmentsList: [],
    employmentStatusesList: [],
    attendanceRequestId: 0,
    employeesRequestId: 0,
  }),

  getters: {
    // Filtered employees
    filteredEmployees: (state) => {
      let filtered = Array.isArray(state.employees) ? [...state.employees] : [];

      if (state.filters.search) {
        const query = state.filters.search.toLowerCase();
        filtered = filtered.filter(
          (e) =>
            e.name?.toLowerCase().includes(query) ||
            e.phone?.includes(query) ||
            e.email?.toLowerCase().includes(query)
        );
      }

      if (state.filters.department) {
        filtered = filtered.filter((e) => e.department === state.filters.department);
      }

      if (state.filters.status) {
        filtered = filtered.filter((e) =>
          state.filters.status === 'active' ? e.is_active : !e.is_active
        );
      }

      return filtered;
    },

    departments: (state) => state.departmentsList,

    employeeStatuses: (state) => state.employmentStatusesList,

    // Active employees
    activeEmployees: (state) => {
      const employees = Array.isArray(state.employees) ? state.employees : [];
      return employees.filter((e) => e.is_active);
    },

    // Employees on leave
    employeesOnLeave: (state) => {
      const employees = Array.isArray(state.employees) ? state.employees : [];
      return employees.filter((e) => e.status === 'on_leave');
    },

    // Present today
    presentToday: (state) => {
      const today = new Date().toISOString().split('T')[0];
      const attendance = Array.isArray(state.attendance) ? state.attendance : [];
      return attendance.filter((a) =>
        (a.date === today || a.attendance_date === today) && a.present
      );
    },

    // Absent today
    absentToday: (state) => {
      const today = new Date().toISOString().split('T')[0];
      const employees = Array.isArray(state.employees) ? state.employees : [];
      const attendanceList = Array.isArray(state.attendance) ? state.attendance : [];
      return employees.filter((e) => {
        const attendance = attendanceList.find((a) =>
          a.employee_id === e.id && (a.date === today || a.attendance_date === today)
        );
        return !attendance || !attendance.present;
      });
    },
  },

  actions: {
    async fetchEmployeeReferenceData() {
      if (this.fetchReferenceDataController) {
        this.fetchReferenceDataController.abort();
      }
      const controller = new AbortController();
      this.fetchReferenceDataController = controller;

      try {
        const response = await axios.get('/api/v1/employee/employees/reference-data', {
          signal: controller.signal
        });
        const data = response.data?.data || {};
        const fetchedDepartments = data.departments || [];
        const merged = [...DEFAULT_DEPARTMENTS];
        fetchedDepartments.forEach((dept) => {
          if (!merged.some((d) => d.value === dept.value)) {
            merged.push(dept);
          }
        });
        this.departmentsList = merged;
        const rawStatuses = data.employment_statuses || [];
        this.employmentStatusesList = rawStatuses.map((s) => ({
          ...s,
          color:
            s.value === 'active'
              ? 'success'
              : s.value === 'on_leave'
                ? 'warning'
                : 'error',
        }));
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch employee reference data:', error);
        this.departmentsList = [];
        this.employmentStatusesList = [];
      }
    },

    // Fetch Employees
    async fetchEmployees(params = {}) {
      if (this.fetchEmployeesController) {
        this.fetchEmployeesController.abort();
      }
      const controller = new AbortController();
      this.fetchEmployeesController = controller;
      const requestId = ++this.employeesRequestId;

      this.loading.employees = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/employee/employees', {
          params,
          signal: controller.signal
        });
        if (requestId !== this.employeesRequestId) {
          return;
        }

        const data = response.data?.data || response.data;
        let rawItems = data.items || data.data || (Array.isArray(data) ? data : []);
        if (rawItems?.data && Array.isArray(rawItems.data)) {
          rawItems = rawItems.data;
        }
        this.employees = normalizeListItems(rawItems).map(mapEmployee);
        if (data && (data.meta || data.pagination)) {
          const meta = data.meta || data.pagination;
          this.pagination = {
            total: meta.total || 0,
            current_page: meta.current_page || 1,
            last_page: meta.last_page || 1,
            per_page: meta.per_page || 15,
          };
        }
        await this.fetchEmployeeReferenceData();
        await this.fetchStats();
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        if (requestId !== this.employeesRequestId) {
          return;
        }
        console.error('Failed to fetch employees:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load employees',
        };
      } finally {
        if (this.fetchEmployeesController === controller) {
          this.loading.employees = false;
        }
      }
    },

    // Create Employee
    async createEmployee(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = this.transformEmployeePayload(payload);
        const response = await axios.post('/api/v1/employee/employees', apiPayload);
        const record = response.data.data || response.data;
        const mappedRecord = mapEmployee(record);
        this.employees.unshift(mappedRecord);
        await this.fetchStats();
        return mappedRecord;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to create employee',
        };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Update Employee
    async updateEmployee(id, payload) {
      if (this.loading.update) return;
      this.loading.update = true;
      this.errors = {};

      try {
        const apiPayload = this.transformEmployeePayload(payload);
        const response = await axios.put(`/api/v1/employee/employees/${id}`, apiPayload);
        const record = response.data.data || response.data;
        const mappedRecord = mapEmployee(record);
        const index = this.employees.findIndex((e) => e.id === id);
        if (index !== -1) {
          this.employees[index] = mappedRecord;
        }
        await this.fetchStats();
        return mappedRecord;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to update employee',
        };
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    // Delete Employee
    async deleteEmployee(id) {
      if (this.loading.delete) return;
      this.loading.delete = true;
      this.errors = {};

      try {
        await axios.delete(`/api/v1/employee/employees/${id}`);
        this.employees = this.employees.filter((e) => e.id !== id);
        await this.fetchStats();
      } catch (error) {
        this.errors = {
          delete: error.response?.data?.message || 'Failed to delete employee',
        };
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    // Fetch Attendance
    async fetchAttendance(params = {}) {
      if (this.fetchAttendanceController) {
        this.fetchAttendanceController.abort();
      }
      const controller = new AbortController();
      this.fetchAttendanceController = controller;
      const requestId = ++this.attendanceRequestId;

      this.loading.attendance = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/employee/attendances', {
          params,
          signal: controller.signal
        });
        if (requestId !== this.attendanceRequestId) {
          return;
        }

        const data = response.data?.data || response.data;
        let rawItems = data.items || data.data || (Array.isArray(data) ? data : []);
        if (rawItems?.data && Array.isArray(rawItems.data)) {
          rawItems = rawItems.data;
        }

        this.attendance = normalizeListItems(rawItems)
          .map(mapAttendance)
          .filter(Boolean);
        await this.fetchStats();
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        if (requestId !== this.attendanceRequestId) {
          return;
        }
        console.error('Failed to fetch attendance:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load attendance',
        };
      } finally {
        if (this.fetchAttendanceController === controller) {
          this.loading.attendance = false;
        }
      }
    },

    // Mark Attendance
    async markAttendance(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = {
          employee_id: payload.employee_id,
          attendance_date: payload.date || payload.attendance_date,
          status: payload.status || (payload.present === false ? 'absent' : 'present'),
          check_in: payload.check_in || null,
          check_out: payload.check_out || null,
          notes: payload.notes || null,
        };
        const response = await axios.post('/api/v1/employee/attendances', apiPayload);
        const record = response.data.data || response.data;

        const mappedRecord = mapAttendance({
          ...record,
          date: record.attendance_date,
        });

        this.attendance.unshift(mappedRecord);
        await this.fetchStats();
        return mappedRecord;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to mark attendance',
        };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Add Bonus
    async addBonus(employeeId, payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/employee/bonuses', { ...apiPayload, employee_id: employeeId });
        this.bonuses.unshift(response.data.data || response.data);
        await this.fetchStats();
        return response.data.data || response.data;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to add bonus',
        };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Add Deduction
    async addDeduction(employeeId, payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/employee/bonuses', { ...apiPayload, employee_id: employeeId, type: 'deduction' });
        this.deductions.unshift(response.data.data || response.data);
        await this.fetchStats();
        return response.data.data || response.data;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to add deduction',
        };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Fetch Stats
    async fetchStats() {
      try {
        const today = new Date().toISOString().split('T')[0];
        const attendance = Array.isArray(this.attendance) ? this.attendance : [];
        const employees = Array.isArray(this.employees) ? this.employees : [];
        const presentToday = attendance.filter(
          (a) => a && (a.date === today || a.attendance_date === today) && a.present
        );
        const absentToday = employees.filter((e) => {
          const record = attendance.find(
            (a) => a && a.employee_id === e.id && (a.date === today || a.attendance_date === today)
          );
          return !record || !record.present;
        });

        const stats = {
          total_employees: employees.length,
          active_employees: employees.filter((e) => e.is_active).length,
          present_today: presentToday.length,
          absent_today: absentToday.length,
          total_bonuses: Array.isArray(this.bonuses)
            ? this.bonuses.reduce((sum, b) => sum + parseAmount(b.amount), 0)
            : 0,
          total_deductions: Array.isArray(this.deductions)
            ? this.deductions.reduce((sum, d) => sum + parseAmount(d.amount), 0)
            : 0,
          net_payroll: Array.isArray(this.employees)
            ? this.employees.reduce((sum, e) => sum + parseAmount(e.salary), 0)
            : 0,
        };
        this.stats = stats;
      } catch (error) {
        console.error('Failed to calculate stats:', error);
      }
    },

    /**
     * Transform frontend camelCase payload to backend snake_case format
     */
    abortPendingRequests() {
      this.fetchEmployeesController?.abort();
      this.fetchAttendanceController?.abort();
      this.fetchReferenceDataController?.abort();
      this.employeesRequestId += 1;
      this.attendanceRequestId += 1;
    },

    transformEmployeePayload(payload) {
      return {
        full_name: payload.full_name || payload.name || '',
        phone: payload.phone || null,
        department: payload.department || null,
        position: payload.position || null,
        salary: parseAmount(payload.salary),
        hire_date: payload.hireDate || payload.hire_date || null,
        is_active: payload.isActive ?? payload.is_active ?? true,
        notes: payload.notes || null,
      };
    },

    transformPayloadForApi(payload) {
      if (payload.amount !== undefined || payload.reason !== undefined) {
        return {
          amount: parseAmount(payload.amount),
          reason: payload.reason || '',
          date: payload.date || null,
          type: payload.type || undefined,
        };
      }

      return this.transformEmployeePayload(payload);
    },

    // Add toast notification
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },

    reset() {
      this.employees = [];
      this.currentEmployee = null;
      this.attendance = [];
      this.bonuses = [];
      this.deductions = [];
      this.stats = {
        total_employees: 0,
        active_employees: 0,
        present_today: 0,
        absent_today: 0,
        total_bonuses: 0,
        total_deductions: 0,
        net_payroll: 0,
      };
      this.loading = {
        employees: false,
        attendance: false,
        bonuses: false,
        create: false,
        update: false,
        delete: false,
      };
      this.errors = {};
      this.filters = {
        search: '',
        department: '',
        status: '',
        date_from: '',
        date_to: '',
        page: 1,
        per_page: 15,
      };
      this.pagination = {
        total: 0,
        current_page: 1,
        last_page: 1,
        per_page: 15,
      };
      this.departmentsList = [];
      this.employmentStatusesList = [];
    },
  },
});
