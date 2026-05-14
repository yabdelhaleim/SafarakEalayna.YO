import { defineStore } from 'pinia';
import axios from 'axios';

export const useFawryStore = defineStore('fawry', {
  state: () => ({
    // Transactions
    transactions: [],
    currentTransaction: null,

    // Settings (from API)
    operationTypes: [],
    paymentMethods: [],
    currencies: [],

    // Stats
    stats: {
      total_transactions: 0,
      today_transactions: 0,
      total_revenue: 0,
      total_profit: 0,
      today_revenue: 0,
      today_profit: 0,
      by_operation_type: {
        withdrawal: 0,
        deposit: 0,
        payment: 0,
        travel_permit: 0,
      },
    },

    // Loading States
    loading: {
      transactions: false,
      transaction: false,
      create: false,
      update: false,
      delete: false,
      daily_summary: false,
      settings: false,
    },

    // Errors
    errors: {},

    /** آخر جسم استجابة كامل من `/api/v1/fawry/...` (عرض في الواجهة) */
    lastApiEnvelope: null,

    // Filters
    filters: {
      search: '',
      operation_type: '',
      payment_method: '',
      employee_id: '',
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

    /** Fawry Treasury Overview */
    treasuryOverview: null,
  }),

  getters: {
    // Filtered transactions
    filteredTransactions: (state) => {
      let filtered = Array.isArray(state.transactions) ? [...state.transactions] : [];

      if (state.filters.search) {
        const query = state.filters.search.toLowerCase();
        filtered = filtered.filter(
          (t) =>
            t.id?.toString().includes(query) ||
            t.client_name?.toLowerCase().includes(query) ||
            t.reference_number?.toLowerCase().includes(query)
        );
      }

      if (state.filters.operation_type) {
        filtered = filtered.filter((t) => t.operation_type === state.filters.operation_type);
      }

      if (state.filters.payment_method) {
        filtered = filtered.filter((t) => t.payment_method === state.filters.payment_method);
      }

      if (state.filters.employee_id) {
        filtered = filtered.filter((t) => t.employee?.id == state.filters.employee_id);
      }

      if (state.filters.date_from) {
        filtered = filtered.filter(
          (t) => new Date(t.created_at) >= new Date(state.filters.date_from)
        );
      }

      if (state.filters.date_to) {
        filtered = filtered.filter(
          (t) => new Date(t.created_at) <= new Date(state.filters.date_to)
        );
      }

      return filtered;
    },

    // Recent transactions
    recentTransactions: (state) => {
      const transactions = Array.isArray(state.transactions) ? state.transactions : [];
      return transactions.slice(0, 10);
    },

    // Transactions by operation type
    transactionsByOperationType: (state) => (operationType) => {
      const transactions = Array.isArray(state.transactions) ? state.transactions : [];
      return transactions.filter((t) => t.operation_type === operationType);
    },

    // Get operation type label
    getOperationTypeLabel: (state) => (operationType) => {
      const type = state.operationTypes.find((t) => t.value === operationType);
      return type ? type.label : operationType;
    },

    // Get payment method label
    getPaymentMethodLabel: (state) => (paymentMethod) => {
      const method = state.paymentMethods.find((m) => m.value === paymentMethod);
      return method ? method.label : paymentMethod;
    },
  },

  actions: {
    // Fetch Settings (Payment Methods & Operation Types)
    async fetchSettings() {
      this.loading.settings = true;
      try {
        const response = await axios.get('/api/v1/fawry/settings/all');
        const settings = response.data?.data || {};

        this.paymentMethods = settings.paymentMethods || [];
        this.operationTypes = settings.operationTypes || [];
        this.currencies = settings.currencies || [];
      } catch (error) {
        console.error('Failed to fetch fawry settings:', error);
        // Fallback to defaults if API fails
        this.paymentMethods = [
          { value: 'cash', label: 'نقدي', labelEn: 'Cash', color: 'success' },
          { value: 'bank_transfer', label: 'تحويل بنكي', labelEn: 'Bank Transfer', color: 'info' },
          { value: 'cash_wallet', label: 'محفظة كاش', labelEn: 'Cash Wallet', color: 'purple' },
          { value: 'office_safe', label: 'خزينة المكتب', labelEn: 'Office Safe', color: 'warning' },
          { value: 'office_drawer', label: 'درج المكتب', labelEn: 'Office Drawer', color: 'gray' },
        ];
        this.operationTypes = [
          { value: 'withdrawal', label: 'سحب', labelEn: 'Withdrawal', color: 'error' },
          { value: 'deposit', label: 'إيداع', labelEn: 'Deposit', color: 'success' },
          { value: 'payment', label: 'سداد', labelEn: 'Payment', color: 'info' },
          { value: 'travel_permit', label: 'تصريح سفر', labelEn: 'Travel Permit', color: 'warning' },
        ];
        this.currencies = [
          { value: 'EGP', label: 'جنيه مصري', labelEn: 'Egyptian Pound', symbol: 'ج.م' },
        ];
      } finally {
        this.loading.settings = false;
      }
    },

    // Fetch Transactions
    async fetchTransactions(params = {}) {
      this.loading.transactions = true;
      this.errors = {};

      try {
        const response = await axios.get('/api/v1/fawry/transactions', {
          params: { ...this.filters, ...params },
        });
        this.lastApiEnvelope = response.data ?? null;
        const data = response.data?.data || response.data;
        this.transactions = data.items || (Array.isArray(data) ? data : []);

        const pagination = data.pagination || response.data?.pagination || {};
        this.pagination = {
          total: pagination.total || response.data?.total || this.transactions.length,
          current_page: pagination.current_page || response.data?.current_page || 1,
          last_page: pagination.last_page || response.data?.last_page || 1,
          per_page: pagination.per_page || response.data?.per_page || 15,
        };

        await this.calculateStats();
      } catch (error) {
        console.error('Failed to fetch fawry transactions:', error);
        this.lastApiEnvelope = error.response?.data ?? null;
        this.errors = {
          fetch: 'حدث خطأ أثناء تحميل معاملات فوري',
        };
        this.transactions = [];
        await this.calculateStats();
      } finally {
        this.loading.transactions = false;
      }
    },

    // Create Transaction
    async createTransaction(payload) {
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/fawry/transactions', apiPayload);
        this.lastApiEnvelope = response.data ?? null;
        const newTx = response.data?.data || response.data;
        if (!Array.isArray(this.transactions)) this.transactions = [];
        this.transactions.unshift(newTx);
        await this.calculateStats();
        this.addToast('تمت العملية بنجاح', 'success');
        return newTx;
      } catch (error) {
        this.lastApiEnvelope = error.response?.data ?? null;
        this.errors = error.response?.data?.errors || {
          message: 'حدث خطأ، حاول مرة أخرى',
        };
        this.addToast('حدث خطأ أثناء إنشاء المعاملة', 'error');
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Update Transaction
    async updateTransaction(id, payload) {
      this.loading.update = true;
      this.errors = {};

      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.put(`/api/v1/fawry/transactions/${id}`, apiPayload);
        this.lastApiEnvelope = response.data ?? null;
        const updated = response.data?.data || response.data;
        if (Array.isArray(this.transactions)) {
          const index = this.transactions.findIndex((t) => t.id === id);
          if (index !== -1) {
            this.transactions[index] = updated;
          }
        }
        await this.calculateStats();
        this.addToast('تم التحديث بنجاح', 'success');
        return updated;
      } catch (error) {
        this.lastApiEnvelope = error.response?.data ?? null;
        this.errors = error.response?.data?.errors || {
          message: 'حدث خطأ، حاول مرة أخرى',
        };
        this.addToast('حدث خطأ أثناء التحديث', 'error');
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    // Delete Transaction
    async deleteTransaction(id) {
      this.loading.delete = true;
      this.errors = {};

      try {
        const response = await axios.delete(`/api/v1/fawry/transactions/${id}`);
        this.lastApiEnvelope = response.data ?? null;
        if (Array.isArray(this.transactions)) {
          this.transactions = this.transactions.filter((t) => t.id !== id);
        }
        await this.calculateStats();
        this.addToast('تم الحذف بنجاح', 'success');
      } catch (error) {
        this.lastApiEnvelope = error.response?.data ?? null;
        this.errors = {
          delete: 'حدث خطأ أثناء الحذف، حاول مرة أخرى',
        };
        this.addToast('حدث خطأ أثناء الحذف', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    async fetchTransactionById(id) {
      this.loading.transaction = true;
      this.errors = {};
      try {
        const response = await axios.get(`/api/v1/fawry/transactions/${id}`);
        this.lastApiEnvelope = response.data ?? null;
        const item = response.data?.data;
        if (item) {
          if (!Array.isArray(this.transactions)) {
            this.transactions = [];
          }
          const idx = this.transactions.findIndex((t) => t.id === item.id);
          if (idx === -1) {
            this.transactions.unshift(item);
          } else {
            this.transactions[idx] = item;
          }
        }
        return item;
      } catch (error) {
        this.lastApiEnvelope = error.response?.data ?? null;
        throw error;
      } finally {
        this.loading.transaction = false;
      }
    },

    clearLastApiEnvelope() {
      this.lastApiEnvelope = null;
    },

    // Fetch Daily Summary
    async fetchDailySummary(date = null) {
      this.loading.daily_summary = true;
      this.errors = {};

      try {
        const targetDate = date || new Date().toISOString().split('T')[0];
        const response = await axios.get('/api/v1/fawry/transactions/daily-summary', {
          params: { date: targetDate },
        });
        const summary = response.data?.data || response.data;
        return summary;
      } catch (error) {
        console.error('Failed to fetch daily summary:', error);
        this.errors = {
          daily_summary: 'حدث خطأ أثناء تحميل الملخص اليومي',
        };
        throw error;
      } finally {
        this.loading.daily_summary = false;
      }
    },

    // Calculate Stats
    async calculateStats() {
      try {
        const transactions = Array.isArray(this.transactions) ? this.transactions : [];
        const today = new Date().toDateString();

        this.stats = {
          total_transactions: transactions.length,
          today_transactions: transactions.filter(
            (t) => new Date(t.created_at).toDateString() === today
          ).length,
          total_revenue: transactions.reduce((sum, t) => sum + (parseFloat(t.selling_price) || 0), 0),
          total_profit: transactions.reduce((sum, t) => sum + (parseFloat(t.profit) || 0), 0),
          today_revenue: transactions
            .filter((t) => new Date(t.created_at).toDateString() === today)
            .reduce((sum, t) => sum + (parseFloat(t.selling_price) || 0), 0),
          today_profit: transactions
            .filter((t) => new Date(t.created_at).toDateString() === today)
            .reduce((sum, t) => sum + (parseFloat(t.profit) || 0), 0),
          by_operation_type: {
            withdrawal: transactions.filter((t) => t.operation_type === 'withdrawal').length,
            deposit: transactions.filter((t) => t.operation_type === 'deposit').length,
            payment: transactions.filter((t) => t.operation_type === 'payment').length,
            travel_permit: transactions.filter((t) => t.operation_type === 'travel_permit').length,
          },
        };
      } catch (error) {
        console.error('Failed to calculate stats:', error);
      }
    },

    /**
     * Transform frontend camelCase payload to backend snake_case format
     */
    transformPayloadForApi(payload) {
      const rawCurrency = payload.currency_id ?? payload.currencyId;
      let currencyId = null;
      if (rawCurrency !== null && rawCurrency !== undefined && rawCurrency !== '') {
        const n = Number(rawCurrency);
        if (Number.isFinite(n) && n > 0) {
          currencyId = n;
        }
      }

      const rawAccount = payload.account_id ?? payload.accountId ?? payload.account?.id;
      let accountId = null;
      if (rawAccount !== null && rawAccount !== undefined && rawAccount !== '') {
        const n = Number(rawAccount);
        if (Number.isFinite(n) && n > 0) {
          accountId = n;
        }
      }

      const rawEmployee = payload.employee_id ?? payload.employeeId ?? payload.employee?.id;
      let employeeId = null;
      if (rawEmployee !== null && rawEmployee !== undefined && rawEmployee !== '') {
        const n = Number(rawEmployee);
        if (Number.isFinite(n) && n > 0) {
          employeeId = n;
        }
      }

      const clientIdRaw = payload.client_id ?? payload.clientId;
      let clientId = null;
      if (clientIdRaw !== null && clientIdRaw !== undefined && clientIdRaw !== '') {
        const n = Number(clientIdRaw);
        if (Number.isFinite(n) && n > 0) {
          clientId = n;
        }
      }

      return {
        client_id: clientId,
        client_name: payload.client_name ?? payload.clientName ?? '',
        operation_type: payload.operation_type || payload.operationType || '',
        currency_id: currencyId,
        client_amount: payload.client_amount || payload.clientAmount || 0,
        fawry_price: payload.fawry_price || payload.fawryPrice || 0,
        selling_price: payload.selling_price || payload.sellingPrice || 0,
        employee_id: employeeId,
        account_id: accountId,
        payment_method: payload.payment_method || payload.paymentMethod || '',
        amount: payload.amount || payload.selling_price || payload.sellingPrice || 0,
        reference_number: payload.reference_number || payload.referenceNumber || null,
        notes: payload.notes || null,
        payment_details: payload.payment_details || payload.paymentDetails || {},
      };
    },

    // Add toast notification
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },

    /** Fawry Treasury Actions */
    async fetchFawryTreasuryOverview() {
      this.loading.transactions = true;
      try {
        const { data } = await axios.get('/api/v1/fawry/treasury/overview');
        this.treasuryOverview = data?.data ?? null;
        return this.treasuryOverview;
      } catch (e) {
        console.error('fetchFawryTreasuryOverview failed', e);
        throw e;
      } finally {
        this.loading.transactions = false;
      }
    },

    async fetchAccountFawryTransactions(accountId, params = {}) {
      try {
        const { data } = await axios.get(`/api/v1/fawry/treasury/accounts/${accountId}/transactions`, { params });
        return data?.data;
      } catch (e) {
        console.error('fetchAccountFawryTransactions failed', e);
        throw e;
      }
    },

    async fetchFawryDashboard() {
      this.loading.transactions = true;
      try {
        const { data } = await axios.get('/api/v1/fawry/dashboard');
        return data?.data;
      } catch (e) {
        console.error('fetchFawryDashboard failed', e);
        throw e;
      } finally {
        this.loading.transactions = false;
      }
    },
  },
});
