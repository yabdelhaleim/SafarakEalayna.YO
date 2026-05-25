import { defineStore } from 'pinia';
import axios from 'axios';

export const useWalletStore = defineStore('wallet', {
  state: () => ({
    transactions: [],
    currentTransaction: null,
    walletTypes: [],

    stats: {
      total_transactions: 0,
      send_count: 0,
      receive_count: 0,
      total_sent: 0,
      total_received: 0,
      total_fees: 0,
    },

    loading: {
      transactions: false,
      walletTypes: false,
      create: false,
      update: false,
      delete: false,
    },

    errors: {},

    filters: {
      search: '',
      type: '',
      wallet_type_id: '',
      from_date: '',
      to_date: '',
      page: 1,
      per_page: 20,
    },

    pagination: {
      total: 0,
      current_page: 1,
      last_page: 1,
      per_page: 20,
    },
  }),

  getters: {
    activeWalletTypes: (state) =>
      state.walletTypes.filter((t) => t.is_active),

    transactionTypeOptions: () => [
      { value: 'send',    label: 'إرسال رصيد',    color: 'warning', icon: 'ArrowUpCircle' },
      { value: 'receive', label: 'استقبال رصيد',  color: 'success', icon: 'ArrowDownCircle' },
    ],

    totalProfit: (state) =>
      state.transactions.reduce((sum, t) => sum + parseFloat(t.service_fee || 0), 0),
  },

  actions: {
    // ────────────────────────────────────────────────
    //  Wallet Types
    // ────────────────────────────────────────────────
    async fetchWalletTypes(params = {}) {
      if (this.loading.walletTypes) return;
      this.loading.walletTypes = true;
      const controller = new AbortController();
      try {
        const res = await axios.get('/api/v1/wallet/types', {
          params,
          signal: controller.signal,
        });
        this.walletTypes = res.data?.data || [];
      } catch (err) {
        if (axios.isCancel(err)) return;
        console.error('Failed to fetch wallet types:', err);
        this.walletTypes = [];
      } finally {
        this.loading.walletTypes = false;
      }
    },

    // ────────────────────────────────────────────────
    //  Transactions
    // ────────────────────────────────────────────────
    async fetchTransactions(params = {}) {
      if (this.loading.transactions) return;
      this.loading.transactions = true;
      this.errors = {};
      const controller = new AbortController();
      try {
        const res = await axios.get('/api/v1/wallet/transactions', {
          params: { ...this.filters, ...params },
          signal: controller.signal,
        });
        const data       = res.data?.data || {};
        this.transactions = data.items || (Array.isArray(data) ? data : []);
        const pg          = data.pagination || {};
        this.pagination   = {
          total:        pg.total        || res.data?.total        || this.transactions.length,
          current_page: pg.current_page || res.data?.current_page || 1,
          last_page:    pg.last_page    || res.data?.last_page    || 1,
          per_page:     pg.per_page     || res.data?.per_page     || 20,
        };
      } catch (err) {
        if (axios.isCancel(err)) return;
        console.error('Failed to fetch wallet transactions:', err);
        this.errors = { fetch: 'حدث خطأ أثناء تحميل العمليات' };
        this.transactions = [];
      } finally {
        this.loading.transactions = false;
      }
    },

    async fetchTransaction(id) {
      try {
        const res = await axios.get(`/api/v1/wallet/transactions/${id}`);
        this.currentTransaction = res.data?.data || res.data;
        return this.currentTransaction;
      } catch (err) {
        console.error('Failed to fetch transaction:', err);
        throw err;
      }
    },

    async createTransaction(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};
      try {
        const res = await axios.post('/api/v1/wallet/transactions', payload);
        const created = res.data?.data || res.data;
        this.transactions.unshift(created);
        this.addToast('تم تسجيل العملية بنجاح', 'success');
        await this.fetchDailySummary();
        return created;
      } catch (err) {
        this.errors = err.response?.data?.errors || { message: 'حدث خطأ، حاول مرة أخرى' };
        this.addToast(err.response?.data?.message || 'حدث خطأ أثناء الحفظ', 'error');
        throw err;
      } finally {
        this.loading.create = false;
      }
    },

    async updateTransaction(id, payload) {
      if (this.loading.update) return;
      this.loading.update = true;
      this.errors = {};
      try {
        const res     = await axios.put(`/api/v1/wallet/transactions/${id}`, payload);
        const updated = res.data?.data || res.data;
        const idx     = this.transactions.findIndex((t) => t.id === id);
        if (idx !== -1) this.transactions[idx] = updated;
        this.addToast('تم التحديث بنجاح', 'success');
        return updated;
      } catch (err) {
        this.errors = err.response?.data?.errors || { message: 'حدث خطأ، حاول مرة أخرى' };
        this.addToast('حدث خطأ أثناء التحديث', 'error');
        throw err;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteTransaction(id) {
      if (this.loading.delete) return;
      this.loading.delete = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/wallet/transactions/${id}`);
        this.transactions = this.transactions.filter((t) => t.id !== id);
        this.addToast('تم الحذف بنجاح', 'success');
      } catch (err) {
        this.errors = { delete: 'حدث خطأ أثناء الحذف' };
        this.addToast('حدث خطأ أثناء الحذف', 'error');
        throw err;
      } finally {
        this.loading.delete = false;
      }
    },

    async fetchDailySummary(date = null) {
      try {
        const params = date ? { date } : {};
        const res    = await axios.get('/api/v1/wallet/transactions/daily-summary', { params });
        this.stats   = res.data?.data || this.stats;
      } catch (err) {
        console.error('Failed to fetch daily summary:', err);
      }
    },

    async fetchTransferDashboard() {
      try {
        const res = await axios.get('/api/v1/wallet/dashboard');
        return res.data?.data;
      } catch (err) {
        console.error('Failed to fetch transfer dashboard:', err);
        throw err;
      }
    },

    async fetchTransferTreasury() {
      try {
        const res = await axios.get('/api/v1/wallet/treasury/overview');
        return res.data?.data;
      } catch (err) {
        console.error('Failed to fetch transfer treasury:', err);
        throw err;
      }
    },

    async fetchAccountTransactions(accountId) {
      try {
        const res = await axios.get(`/api/v1/wallet/treasury/accounts/${accountId}/transactions`);
        return res.data?.data;
      } catch (err) {
        console.error('Failed to fetch account transactions:', err);
        throw err;
      }
    },

    setFilter(key, value) {
      this.filters[key] = value;
      this.filters.page = 1;
    },

    resetFilters() {
      this.filters = {
        search: '', type: '', wallet_type_id: '',
        from_date: '', to_date: '', page: 1, per_page: 20,
      };
    },

    addToast(message, type = 'success') {
      if (window.addToast) window.addToast(message, type);
    },
  },
});
