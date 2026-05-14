import { defineStore } from 'pinia';
import axios from 'axios';

export const useReportStore = defineStore('report', {
  state: () => ({
    // Reports
    reports: [],

    // Financial Reports
    financialReports: {
      revenue: [],
      expenses: [],
      profit: [],
      byModule: [],
      byAccount: [],
    },

    // Operations Reports
    operationsReports: {
      bookings: [],
      orders: [],
      transactions: [],
      byStatus: [],
      byPeriod: [],
    },

    // Employee Reports
    employeeReports: {
      attendance: [],
      performance: [],
      bonuses: [],
      deductions: [],
    },

    // Customer Reports
    customerReports: {
      topCustomers: [],
      customerStats: [],
      bookingHistory: [],
    },

    // Filters
    filters: {
      date_from: null,
      date_to: null,
      period: 'this_month', // today, this_week, this_month, this_year, custom
      module: 'all',
    },

    // Loading States
    loading: {
      financial: false,
      operations: false,
      employees: false,
      customers: false,
    },

    // Errors
    errors: {},
  }),

  getters: {
    // Period options
    periodOptions: () => [
      { value: 'today', label: 'اليوم' },
      { value: 'this_week', label: 'هذا الأسبوع' },
      { value: 'this_month', label: 'هذا الشهر' },
      { value: 'this_year', label: 'هذه السنة' },
      { value: 'custom', label: 'مخصص' },
    ],

    // Module options
    moduleOptions: () => [
      { value: 'all', label: 'جميع الوحدات' },
      { value: 'flights', label: 'الطيران' },
      { value: 'bus', label: 'الباصات' },
      { value: 'services', label: 'الخدمات' },
      { value: 'online', label: 'Online' },
    ],

    // Total revenue
    totalRevenue: (state) => {
      return state.financialReports.revenue.reduce((sum, r) => sum + (r.amount || 0), 0);
    },

    // Total expenses
    totalExpenses: (state) => {
      return state.financialReports.expenses.reduce((sum, e) => sum + (e.amount || 0), 0);
    },

    // Net profit
    netProfit() {
      return this.totalRevenue - this.totalExpenses;
    },

    // Profit margin
    profitMargin() {
      if (this.totalRevenue === 0) return 0;
      return ((this.netProfit / this.totalRevenue) * 100).toFixed(1);
    },

    // Total bookings
    totalBookings: (state) => {
      return state.operationsReports.bookings.reduce((sum, b) => sum + (b.count || 0), 0);
    },

    // Completion rate
    completionRate: (state) => {
      const total = state.operationsReports.bookings.reduce((sum, b) => sum + (b.count || 0), 0);
      const completed = state.operationsReports.byStatus.find((s) => s.status === 'completed')?.count || 0;
      if (total === 0) return 0;
      return ((completed / total) * 100).toFixed(1);
    },

    // Average attendance rate
    averageAttendanceRate: (state) => {
      const total = state.employeeReports.attendance.reduce((sum, a) => sum + (a.total || 0), 0);
      const present = state.employeeReports.attendance.reduce((sum, a) => sum + (a.present || 0), 0);
      if (total === 0) return 0;
      return ((present / total) * 100).toFixed(1);
    },

    // Top customer
    topCustomer: (state) => {
      return state.customerReports.topCustomers[0] || null;
    },
  },

  actions: {
    // Fetch Financial Reports
    async fetchFinancialReports(params = {}) {
      this.loading.financial = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/reports/financial', {
          params: { ...this.filters, ...params },
        });

        this.financialReports = response.data.data || response.data;
      } catch (error) {
        console.error('Failed to fetch financial reports:', error);
        this.errors = {
          financial: error.response?.data?.message || 'Failed to load financial reports',
        };
        this.financialReports = {
          revenue: [],
          expenses: [],
          profit: [],
          byModule: [],
          byAccount: [],
        };
      } finally {
        this.loading.financial = false;
      }
    },

    // Fetch Operations Reports
    async fetchOperationsReports(params = {}) {
      this.loading.operations = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/reports/operations', {
          params: { ...this.filters, ...params },
        });

        this.operationsReports = response.data.data || response.data;
      } catch (error) {
        console.error('Failed to fetch operations reports:', error);
        this.errors = {
          operations: error.response?.data?.message || 'Failed to load operations reports',
        };
        this.operationsReports = {
          bookings: [],
          orders: [],
          transactions: [],
          byStatus: [],
          byPeriod: [],
        };
      } finally {
        this.loading.operations = false;
      }
    },

    // Fetch Employee Reports
    async fetchEmployeeReports(params = {}) {
      this.loading.employees = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/reports/employees', {
          params: { ...this.filters, ...params },
        });

        this.employeeReports = response.data.data || response.data;
      } catch (error) {
        console.error('Failed to fetch employee reports:', error);
        this.errors = {
          employees: error.response?.data?.message || 'Failed to load employee reports',
        };
        this.employeeReports = {
          attendance: [],
          performance: [],
          bonuses: [],
          deductions: [],
        };
      } finally {
        this.loading.employees = false;
      }
    },

    // Fetch Customer Reports
    async fetchCustomerReports(params = {}) {
      this.loading.customers = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/reports/customers', {
          params: { ...this.filters, ...params },
        });

        this.customerReports = response.data.data || response.data;
      } catch (error) {
        console.error('Failed to fetch customer reports:', error);
        this.errors = {
          customers: error.response?.data?.message || 'Failed to load customer reports',
        };
        this.customerReports = {
          topCustomers: [],
          customerStats: [],
          bookingHistory: [],
        };
      } finally {
        this.loading.customers = false;
      }
    },

    // Export Report
    async exportReport(type, format = 'pdf') {
      try {
        const response = await axios.get(`/api/v1/reports/${type}/export`, {
          params: { ...this.filters, format },
          responseType: 'blob',
        });

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `${type}-report.${format}`);
        document.body.appendChild(link);
        link.click();
        link.remove();

        return true;
      } catch (error) {
        console.error('Failed to export report:', error);
        this.errors = {
          export: error.response?.data?.message || 'Failed to export report',
        };
        return false;
      }
    },

    // Add toast notification
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },
  },
});
