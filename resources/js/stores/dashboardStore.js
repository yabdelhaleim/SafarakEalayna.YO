import { defineStore } from 'pinia';
import axios from 'axios';

export const useDashboardStore = defineStore('dashboard', {
  state: () => ({
    // Overview Data
    overview: {
      today: {
        flights: 0,
        buses: 0,
        services: 0,
        online: 0,
      },
      this_month: {
        flights: 0,
        buses: 0,
        services: 0,
        online: 0,
      },
      total_customers: 0,
      total_employees: 0,
      pending_invoices: 0,
      overdue_invoices: 0,
    },

    // Financial Data
    financial: {
      total_income: 0,
      total_expense: 0,
      net_profit: 0,
      profit_margin: 0,
      transactions_count: 0,
    },

    // Bookings Data
    bookings: {
      flights: {
        total: 0,
        confirmed: 0,
      },
      buses: {
        total: 0,
        paid: 0,
      },
      services: {
        total: 0,
        completed: 0,
      },
      online: {
        total: 0,
        success: 0,
      },
    },

    // Top Customers
    top_customers: [],

    // Recent Activities
    recent_activities: [],

    // Alerts
    alerts: [],

    // Loading States
    loading: {
      overview: false,
      financial: false,
      bookings: false,
      top_customers: false,
      recent_activities: false,
    },

    // Error States
    errors: {},

    // Filters
    filters: {
      from_date: null,
      to_date: null,
    },
  }),

  getters: {
    // Total bookings across all modules
    totalBookings: (state) => {
      return (
        (state.bookings?.flights?.total || 0) +
        (state.bookings?.buses?.total || 0) +
        (state.bookings?.services?.total || 0) +
        (state.bookings?.online?.total || 0)
      );
    },

    // Total revenue (income)
    totalRevenue: (state) => {
      return state.financial?.total_income || 0;
    },

    // Profit percentage
    profitPercentage: (state) => {
      return state.financial?.profit_margin || 0;
    },

    // Pending tasks count
    pendingTasks: (state) => {
      return (
        (state.overview?.pending_invoices || 0) +
        (state.overview?.overdue_invoices || 0) +
        (state.alerts?.length || 0)
      );
    },

    // Stats cards for dashboard
    statsCards: (state) => {
      return [
        {
          label: 'إجمالي الإيرادات',
          value: state.financial?.total_income || 0,
          format: 'currency',
          icon: 'DollarSign',
        },
        {
          label: 'إجمالي الأرباح',
          value: state.financial?.net_profit || 0,
          format: 'currency',
          icon: 'TrendingUp',
        },
        {
          label: 'حجوزات الطيران',
          value: state.bookings?.flights?.total || 0,
          format: 'number',
          icon: 'Plane',
        },
        {
          label: 'حجوزات الباصات',
          value: state.bookings?.buses?.total || 0,
          format: 'number',
          icon: 'Bus',
        },
        {
          label: 'طلبات الخدمات',
          value: state.bookings?.services?.total || 0,
          format: 'number',
          icon: 'ConciergeBell',
        },
        {
          label: 'المعاملات Online',
          value: state.bookings?.online?.total || 0,
          format: 'number',
          icon: 'Globe',
        },
      ];
    },

    // Chart data for revenue
    revenueChartData: (state) => {
      const income = Number(state.financial?.total_income || 0);
      const expense = Number(state.financial?.total_expense || 0);
      return {
        labels: ['الفترة'],
        datasets: [
          {
            label: 'الإيرادات',
            data: [income],
            borderColor: '#D4A843',
            backgroundColor: 'rgba(212, 168, 67, 0.1)',
            tension: 0.4,
            fill: true,
          },
          {
            label: 'المصروفات',
            data: [expense],
            borderColor: '#F04545',
            backgroundColor: 'rgba(240, 69, 69, 0.1)',
            tension: 0.4,
            fill: true,
          },
        ],
      };
    },

    // Chart data for bookings distribution
    bookingsDistribution: (state) => {
      return {
        labels: ['طيران', 'باصات', 'خدمات', 'Online'],
        data: [
          state.bookings?.flights?.total || 0,
          state.bookings?.buses?.total || 0,
          state.bookings?.services?.total || 0,
          state.bookings?.online?.total || 0,
        ],
        backgroundColor: [
          '#D4A843', // Gold
          '#0ECFD4', // Teal
          '#4F8EF7', // Blue
          '#10D98C', // Green
        ],
      };
    },
  },

  actions: {
    async fetchFullDashboard() {
      if (this.fetchFullDashboardController) {
        this.fetchFullDashboardController.abort();
      }
      const controller = new AbortController();
      this.fetchFullDashboardController = controller;

      this.loading.overview = true;
      this.loading.financial = true;
      this.loading.bookings = true;
      this.loading.top_customers = true;
      this.loading.recent_activities = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/dashboard', {
          signal: controller.signal
        });

        if (response.data.success || response.data.status) {
          const data = response.data.data;

          // Update all state
          this.overview = data.overview || this.overview;
          this.financial = data.financial || this.financial;
          this.bookings = data.bookings || this.bookings;
          this.top_customers = data.top_customers || [];
          this.recent_activities = data.recent_activities || [];
          this.alerts = data.alerts || [];
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch dashboard data:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load dashboard data',
        };
      } finally {
        if (this.fetchFullDashboardController === controller) {
          this.loading.overview = false;
          this.loading.financial = false;
          this.loading.bookings = false;
          this.loading.top_customers = false;
          this.loading.recent_activities = false;
        }
      }
    },

    async fetchOverview() {
      if (this.fetchOverviewController) {
        this.fetchOverviewController.abort();
      }
      const controller = new AbortController();
      this.fetchOverviewController = controller;

      this.loading.overview = true;
      try {
        const response = await axios.get('/api/v1/dashboard/overview', {
          signal: controller.signal
        });
        if (response.data.success || response.data.status) {
          this.overview = response.data.data;
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch overview:', error);
      } finally {
        if (this.fetchOverviewController === controller) {
          this.loading.overview = false;
        }
      }
    },

    async fetchFinancialStats(fromDate, toDate) {
      if (this.fetchFinancialStatsController) {
        this.fetchFinancialStatsController.abort();
      }
      const controller = new AbortController();
      this.fetchFinancialStatsController = controller;

      this.loading.financial = true;
      try {
        const response = await axios.get('/api/v1/dashboard/financial', {
          params: {
            from_date: fromDate,
            to_date: toDate,
          },
          signal: controller.signal
        });
        if (response.data.success || response.data.status) {
          this.financial = response.data.data;
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch financial stats:', error);
      } finally {
        if (this.fetchFinancialStatsController === controller) {
          this.loading.financial = false;
        }
      }
    },

    async fetchBookingsStats(fromDate, toDate) {
      if (this.fetchBookingsStatsController) {
        this.fetchBookingsStatsController.abort();
      }
      const controller = new AbortController();
      this.fetchBookingsStatsController = controller;

      this.loading.bookings = true;
      try {
        const response = await axios.get('/api/v1/dashboard/bookings', {
          params: {
            from_date: fromDate,
            to_date: toDate,
          },
          signal: controller.signal
        });
        if (response.data.success || response.data.status) {
          this.bookings = response.data.data;
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch bookings stats:', error);
      } finally {
        if (this.fetchBookingsStatsController === controller) {
          this.loading.bookings = false;
        }
      }
    },

    async fetchRecentActivities(limit = 10) {
      if (this.fetchRecentActivitiesController) {
        this.fetchRecentActivitiesController.abort();
      }
      const controller = new AbortController();
      this.fetchRecentActivitiesController = controller;

      this.loading.recent_activities = true;
      try {
        const response = await axios.get('/api/v1/dashboard/activities', {
          params: { limit },
          signal: controller.signal
        });
        if (response.data.success || response.data.status) {
          this.recent_activities = response.data.data;
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch recent activities:', error);
      } finally {
        if (this.fetchRecentActivitiesController === controller) {
          this.loading.recent_activities = false;
        }
      }
    },

    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },

    reset() {
      this.overview = {
        today: { flights: 0, buses: 0, services: 0, online: 0 },
        this_month: { flights: 0, buses: 0, services: 0, online: 0 },
        total_customers: 0,
        total_employees: 0,
        pending_invoices: 0,
        overdue_invoices: 0,
      };
      this.financial = {
        total_income: 0,
        total_expense: 0,
        net_profit: 0,
        profit_margin: 0,
        transactions_count: 0,
      };
      this.bookings = {
        flights: { total: 0, confirmed: 0 },
        buses: { total: 0, paid: 0 },
        services: { total: 0, completed: 0 },
        online: { total: 0, success: 0 },
      };
      this.top_customers = [];
      this.recent_activities = [];
      this.alerts = [];
      this.loading = {
        overview: false,
        financial: false,
        bookings: false,
        top_customers: false,
        recent_activities: false,
      };
      this.errors = {};
      this.filters = {
        from_date: null,
        to_date: null,
      };
    },
  },
});
