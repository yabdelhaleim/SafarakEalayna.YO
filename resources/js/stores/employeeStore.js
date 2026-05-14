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
      let filtered = [...state.employees];

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
      return state.employees.filter((e) => e.is_active);
    },

    // Employees on leave
    employeesOnLeave: (state) => {
      return state.employees.filter((e) => e.status === 'on_leave');
    },

    // Present today
    presentToday: (state) => {
      const today = new Date().toDateString();
      return state.attendance.filter((a) =>
        new Date(a.date).toDateString() === today && a.present
      );
    },

    // Absent today
    absentToday: (state) => {
      const today = new Date().toDateString();
      return state.employees.filter((e) => {
        const attendance = state.attendance.find((a) =>
          a.employee_id === e.id && new Date(a.date).toDateString() === today
        );
        return !attendance || !attendance.present;
      });
    },
  },

  actions: {
    async fetchEmployeeReferenceData() {
      try {
        const response = await axios.get('/api/v1/employee/employees/reference-data');
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
        console.error('Failed to fetch employee reference data:', error);
        this.departmentsList = [];
        this.employmentStatusesList = [];
      }
    },

    // Fetch Employees
    async fetchEmployees(params = {}) {
      this.loading.employees = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/employee/employees', {
          params,
        });
        const data = response.data?.data || response.data;
        this.employees = data.items || (Array.isArray(data) ? data : []);
        await this.fetchEmployeeReferenceData();
      } catch (error) {
        console.error('Failed to fetch employees:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load employees',
        };
        this.employees = [];
      } finally {
        this.loading.employees = false;
      }
    },

    // Create Employee
    async createEmployee(payload) {
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
      this.loading.attendance = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/employee/attendances', {
          params,
        });
        const data = response.data?.data || response.data;
        this.attendance = data.items || (Array.isArray(data) ? data : []);
      } catch (error) {
        console.error('Failed to fetch attendance:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load attendance',
        };
        this.attendance = [];
      } finally {
        this.loading.attendance = false;
      }
    },

    // Mark Attendance
    async markAttendance(payload) {
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
          total_employees: this.employees.length,
          active_employees: this.employees.filter((e) => e.is_active).length,
          present_today: this.presentToday.length,
          absent_today: this.absentToday.length,
          total_bonuses: this.bonuses.reduce((sum, b) => sum + (b.amount || 0), 0),
          total_deductions: this.deductions.reduce((sum, d) => sum + (d.amount || 0), 0),
          net_payroll: this.employees.reduce((sum, e) => sum + (e.salary || 0), 0),
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
  },
});
