import { defineStore } from 'pinia';
import axios from 'axios';

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
      const today = new Date().toDateString();
      const attendance = Array.isArray(state.attendance) ? state.attendance : [];
      return attendance.filter((a) =>
        new Date(a.date).toDateString() === today && a.present
      );
    },

    // Absent today
    absentToday: (state) => {
      const today = new Date().toDateString();
      const employees = Array.isArray(state.employees) ? state.employees : [];
      const attendanceList = Array.isArray(state.attendance) ? state.attendance : [];
      return employees.filter((e) => {
        const attendance = attendanceList.find((a) =>
          a.employee_id === e.id && new Date(a.date).toDateString() === today
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
        this.departmentsList = data.departments || [];
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

      this.loading.employees = true;
      this.errors = {};
      this.employees = []; // Reset before fetching

      try {
        const response = await axios.get('/api/v1/employee/employees', {
          params,
          signal: controller.signal
        });
        const data = response.data?.data || response.data;
        this.employees = data.items || (Array.isArray(data) ? data : []);
        await this.fetchEmployeeReferenceData();
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch employees:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load employees',
        };
        this.employees = [];
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
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/employee/employees', apiPayload);
        this.employees.unshift(response.data.data || response.data);
        await this.fetchStats();
        return response.data.data || response.data;
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
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.put(`/api/v1/employee/employees/${id}`, apiPayload);
        const index = this.employees.findIndex((e) => e.id === id);
        if (index !== -1) {
          this.employees[index] = response.data.data || response.data;
        }
        return response.data.data || response.data;
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

      this.loading.attendance = true;
      this.errors = {};
      this.attendance = []; // Reset before fetching

      try {
        const response = await axios.get('/api/v1/employee/attendances', {
          params,
          signal: controller.signal
        });
        const data = response.data?.data || response.data;
        this.attendance = data.items || (Array.isArray(data) ? data : []);
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch attendance:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load attendance',
        };
        this.attendance = [];
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
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/employee/attendances', apiPayload);
        this.attendance.unshift(response.data.data || response.data);
        await this.fetchStats();
        return response.data.data || response.data;
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
        const stats = {
          total_employees: Array.isArray(this.employees) ? this.employees.length : 0,
          active_employees: Array.isArray(this.employees) ? this.employees.filter((e) => e.is_active).length : 0,
          present_today: this.presentToday.length,
          absent_today: this.absentToday.length,
          total_bonuses: Array.isArray(this.bonuses) ? this.bonuses.reduce((sum, b) => sum + (b.amount || 0), 0) : 0,
          total_deductions: Array.isArray(this.deductions) ? this.deductions.reduce((sum, d) => sum + (d.amount || 0), 0) : 0,
          net_payroll: Array.isArray(this.employees) ? this.employees.reduce((sum, e) => sum + (e.salary || 0), 0) : 0,
        };
        this.stats = stats;
      } catch (error) {
        console.error('Failed to calculate stats:', error);
      }
    },

    /**
     * Transform frontend camelCase payload to backend snake_case format
     */
    transformPayloadForApi(payload) {
      return {
        name: payload.name || '',
        email: payload.email || '',
        phone: payload.phone || '',
        department: payload.department || '',
        position: payload.position || '',
        salary: payload.salary || 0,
        hire_date: payload.hireDate || payload.hire_date || null,
        is_active: payload.isActive ?? payload.is_active ?? true,
        address: payload.address || '',
        status: payload.status || 'active',
        amount: payload.amount || 0,
        reason: payload.reason || '',
        date: payload.date || null,
        check_in: payload.checkIn || payload.check_in || null,
        check_out: payload.checkOut || payload.check_out || null,
        present: payload.present ?? true,
        notes: payload.notes || null,
      };
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
