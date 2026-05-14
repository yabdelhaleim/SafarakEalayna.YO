import { defineStore } from 'pinia';
import axios from 'axios';

export const useFinanceStore = defineStore('finance', {
  state: () => ({
    // Accounts
    accounts: [],
    currentAccount: null,

    // Transactions
    transactions: [],
    currentTransaction: null,

    // Transfers
    transfers: [],
    currentTransfer: null,

    // Dashboard Stats
    stats: {
      total_balance: 0,
      total_income: 0,
      total_expense: 0,
      net_profit: 0,
      accounts_count: 0,
      transactions_count: 0,
    },

    // Charts Data
    incomeByModule: [],
    expenseByModule: [],

    // Loading States
    loading: {
      accounts: false,
      transactions: false,
      transfers: false,
      stats: false,
      create: false,
      update: false,
      delete: false,
    },

    // Errors
    errors: {},

    // Filters
    filters: {
      search: '',
      account_id: '',
      type: '',
      module: '',
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

    // From /api/v1/settings/* (Filament-backed settings models + enums)
    meta: {
      accountTypes: [],
      transactionTypes: [],
      transactionModules: [],
      currencies: [],
    },
  }),

  getters: {
    // Filtered transactions
    filteredTransactions: (state) => {
      let filtered = [...state.transactions];

      if (state.filters.search) {
        const query = state.filters.search.toLowerCase();
        filtered = filtered.filter((t) =>
          t.description?.toLowerCase().includes(query) ||
          t.id?.toString().includes(query)
        );
      }

      if (state.filters.type) {
        filtered = filtered.filter((t) => t.type === state.filters.type);
      }

      if (state.filters.module) {
        filtered = filtered.filter((t) => t.module === state.filters.module);
      }

      if (state.filters.account_id) {
        filtered = filtered.filter((t) =>
          t.entries?.some((e) => e.account_id === state.filters.account_id)
        );
      }

      if (state.filters.date_from) {
        filtered = filtered.filter(
          (t) => new Date(t.date) >= new Date(state.filters.date_from)
        );
      }

      if (state.filters.date_to) {
        filtered = filtered.filter(
          (t) => new Date(t.date) <= new Date(state.filters.date_to)
        );
      }

      return filtered;
    },

    // Accounts by type
    accountsByType: (state) => (type) => {
      return state.accounts.filter((a) => a.type === type);
    },

    // Total balance by account type
    balanceByType: (state) => (type) => {
      return state.accounts
        .filter((a) => a.type === type)
        .reduce((sum, a) => sum + (a.balance || 0), 0);
    },

    // Income vs Expense for chart
    incomeExpenseData: (state) => {
      return {
        labels: state.incomeByModule.map((item) => item.module),
        income: state.incomeByModule.map((item) => item.total),
        expense: state.expenseByModule.map((item) => item.total),
      };
    },

    // Recent transactions (last 10)
    recentTransactions: (state) => {
      return state.transactions.slice(0, 10);
    },

    accountTypes: (state) => state.meta.accountTypes,

    transactionTypes: (state) => state.meta.transactionTypes,

    transactionModules: (state) => state.meta.transactionModules,
  },

  actions: {
    async fetchSettingsMeta() {
      try {
        const [a, t, m, c] = await Promise.all([
          axios.get('/api/v1/settings/account-types'),
          axios.get('/api/v1/settings/transaction-types'),
          axios.get('/api/v1/settings/transaction-modules'),
          axios.get('/api/v1/settings/currencies'),
        ]);
        this.meta.accountTypes = a.data?.data || [];
        this.meta.transactionTypes = t.data?.data || [];
        this.meta.transactionModules = m.data?.data || [];
        this.meta.currencies = c.data?.data || [];
      } catch (error) {
        console.error('Failed to fetch finance meta from settings API:', error);
        this.meta.accountTypes = [];
        this.meta.transactionTypes = [];
        this.meta.transactionModules = [];
        this.meta.currencies = [];
      }
    },

    // Fetch Accounts
    async fetchAccounts(params = {}) {
      this.loading.accounts = true;
      this.errors = {};
      try {
        const response = await axios.get('/api/v1/finance/accounts', { params });
        this.accounts = response.data.data || response.data;
        if (!this.meta.accountTypes.length) {
          await this.fetchSettingsMeta();
        }
      } catch (error) {
        console.error('Failed to fetch accounts:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load accounts',
        };
        this.accounts = [];
      } finally {
        this.loading.accounts = false;
      }
    },

    // Fetch Transactions
    async fetchTransactions(params = {}) {
      this.loading.transactions = true;
      this.errors = {};

      try {
        const filters = this.filters;
        const apiParams = {
          ...params,
          type: filters.type || params.type || undefined,
          module: filters.module || params.module || undefined,
          account_id: filters.account_id || params.account_id || undefined,
          per_page: params.per_page ?? filters.per_page ?? 15,
          page: params.page ?? filters.page ?? 1,
          from_date: params.from_date ?? filters.date_from ?? undefined,
          to_date: params.to_date ?? filters.date_to ?? undefined,
        };
        const response = await axios.get('/api/v1/reports/transactions', {
          params: apiParams,
        });
        const responseData = response.data?.data || response.data;
        this.transactions = responseData.items || (Array.isArray(responseData) ? responseData : []);
        
        const pagination = responseData.pagination || response.data?.pagination || {};
        this.pagination = {
          total: pagination.total || response.data?.total || this.transactions.length,
          current_page: pagination.current_page || response.data?.current_page || 1,
          last_page: pagination.last_page || response.data?.last_page || 1,
          per_page: pagination.per_page || response.data?.per_page || 15,
        };
        if (!this.meta.accountTypes.length) {
          await this.fetchSettingsMeta();
        }
      } catch (error) {
        console.error('Failed to fetch transactions:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load transactions',
        };
        this.transactions = [];
      } finally {
        this.loading.transactions = false;
      }
    },

    // Fetch Account Entries
    async fetchAccountEntries(params = {}) {
      this.loading.loading = true
      this.errors = {}

      try {
        const response = await axios.get('/api/v1/finance/accounts/' + params.account_id + '/statement', {
          params: {
            from_date: params.from_date,
            to_date: params.to_date,
            type: params.type,
            module: params.module,
            per_page: params.per_page || 20,
            page: params.page || 1,
          },
        })
        const responseData = response.data?.data || {}
        this.entries = responseData.items || responseData || []
        
        const pagination = responseData.pagination || {}
        this.pagination = {
          total: pagination.total || this.entries.length,
          current_page: pagination.current_page || 1,
          last_page: pagination.last_page || 1,
          per_page: pagination.per_page || 20,
        }
      } catch (error) {
        console.error('Failed to fetch account entries:', error)
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load account entries',
        }
        this.entries = []
        this.pagination = {
          total: 0,
          current_page: 1,
          last_page: 1,
          per_page: 20,
        }
      } finally {
        this.loading.loading = false
      }
    },

    // Fetch Transfers
    async fetchTransfers(params = {}) {
      this.loading.transfers = true
      this.errors = {}

      try {
        const response = await axios.get('/api/v1/finance/transfers', {
          params,
        })
        this.transfers = response.data.data || response.data
      } catch (error) {
        console.error('Failed to fetch transfers:', error)
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load transfers',
        }
        this.transfers = []
      } finally {
        this.loading.transfers = false
      }
    },

    // Fetch Finance Stats
    async fetchStats(extra = {}) {
      this.loading.stats = true;
      this.errors = {};

      try {
        const summaryParams = {};
        const from = extra.from_date ?? this.filters.date_from;
        const to = extra.to_date ?? this.filters.date_to;
        const module = extra.module ?? this.filters.module;
        if (from) {
          summaryParams.from_date = from;
        }
        if (to) {
          summaryParams.to_date = to;
        }
        if (module) {
          summaryParams.module = module;
        }

        const [summaryRes, balRes] = await Promise.all([
          axios.get('/api/v1/reports/financial/summary', { params: summaryParams }),
          axios.get('/api/v1/reports/financial/accounts-balance'),
        ]);

        const data = summaryRes.data?.data || {};
        const bal = balRes.data?.data || {};

        this.stats = {
          total_balance: Number(bal.grand_total ?? 0),
          total_income: Number(data.total_income) || 0,
          total_expense: Number(data.total_expense) || 0,
          net_profit: Number(data.net_profit) || 0,
          accounts_count: this.accounts.length,
          transactions_count: this.transactions.length,
        };
      } catch (error) {
        console.error('Failed to fetch stats:', error);
        // Calculate from local data
        this.stats = {
          total_balance: this.accounts.reduce((sum, a) => sum + (a.balance || 0), 0),
          total_income: this.transactions
            .filter((t) => t.type === 'income')
            .reduce((sum, t) => sum + (t.amount || 0), 0),
          total_expense: this.transactions
            .filter((t) => t.type === 'expense')
            .reduce((sum, t) => sum + (t.amount || 0), 0),
          net_profit: 0,
          accounts_count: this.accounts.length,
          transactions_count: this.transactions.length,
        };
        this.stats.net_profit = this.stats.total_income - this.stats.total_expense;
      } finally {
        this.loading.stats = false;
      }
    },

    // Create Transaction
    async createTransaction(payload) {
      this.loading.create = true;
      this.errors = {};

      try {
        const response = await axios.post('/api/v1/finance/transactions', payload);
        this.transactions.unshift(response.data.data || response.data);
        await this.fetchStats();
        return response.data.data || response.data;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to create transaction',
        };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    // Create Transfer
    async createTransfer(payload) {
      this.loading.create = true;
      this.errors = {};

      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/finance/transfers', apiPayload);
        this.transfers.unshift(response.data.data || response.data);
        await this.fetchAccounts();
        await this.fetchStats();
        return response.data.data || response.data;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to create transfer',
        };
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
        const response = await axios.put(
          `/api/v1/finance/transactions/${id}`,
          payload
        );
        const index = this.transactions.findIndex((t) => t.id === id);
        if (index !== -1) {
          this.transactions[index] = response.data.data || response.data;
        }
        await this.fetchStats();
        return response.data.data || response.data;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: 'Failed to update transaction',
        };
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
        await axios.delete(`/api/v1/finance/transactions/${id}`);
        this.transactions = this.transactions.filter((t) => t.id !== id);
        await this.fetchStats();
      } catch (error) {
        this.errors = {
          delete: error.response?.data?.message || 'Failed to delete transaction',
        };
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    /**
     * Transform frontend camelCase payload to backend snake_case format
     */
    transformPayloadForApi(payload) {
      return {
        from_account_id: payload.fromAccount?.id || payload.fromAccountId || payload.from_account_id,
        to_account_id: payload.toAccount?.id || payload.toAccountId || payload.to_account_id,
        amount: payload.amount || 0,
        description: payload.description || payload.notes || '',
        date: payload.date || new Date().toISOString().split('T')[0],
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
