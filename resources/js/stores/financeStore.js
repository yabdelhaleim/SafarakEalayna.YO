import { defineStore } from 'pinia';
import axios from 'axios';
import { unwrapAccountItems } from '@/composables/useTreasuryAccountGroups';

function unwrapAccountsList(payload) {
  return unwrapAccountItems(payload);
}

function unwrapTransferItems(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function unwrapTransferPagination(payload, fallback = {}) {
  if (payload?.pagination && typeof payload.pagination === 'object') {
    return payload.pagination;
  }
  return fallback;
}

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
    transferSummary: {
      total_amount: 0,
      today_count: 0,
    },
    currentTransfer: null,

    // Account Entries
    entries: [],

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
      loading: false,
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
      return state.transactions;
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
      if (this.fetchSettingsMetaController) {
        this.fetchSettingsMetaController.abort();
      }
      const controller = new AbortController();
      this.fetchSettingsMetaController = controller;

      try {
        const [a, t, m, c] = await Promise.all([
          axios.get('/api/v1/settings/account-types', { signal: controller.signal }),
          axios.get('/api/v1/settings/transaction-types', { signal: controller.signal }),
          axios.get('/api/v1/settings/transaction-modules', { signal: controller.signal }),
          axios.get('/api/v1/settings/currencies', { signal: controller.signal }),
        ]);
        this.meta.accountTypes = a.data?.data || [];
        this.meta.transactionTypes = t.data?.data || [];
        this.meta.transactionModules = m.data?.data || [];
        this.meta.currencies = c.data?.data || [];
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch finance meta from settings API:', error);
        this.meta.accountTypes = [];
        this.meta.transactionTypes = [];
        this.meta.transactionModules = [];
        this.meta.currencies = [];
      }
    },

    // Fetch Accounts
    async fetchAccounts(params = {}) {
      if (this.fetchAccountsController) {
        this.fetchAccountsController.abort();
      }
      const controller = new AbortController();
      this.fetchAccountsController = controller;

      this.loading.accounts = true;
      this.errors = {};
      this.accounts = [];

      try {
        const response = await axios.get('/api/v1/finance/accounts', {
          params: { per_page: 100, ...params },
          signal: controller.signal,
        });
        const responseData = response.data?.data || response.data;
        this.accounts = unwrapAccountsList(responseData);
        if (!this.meta.accountTypes.length) {
          await this.fetchSettingsMeta();
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch accounts:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load accounts',
        };
        this.accounts = [];
      } finally {
        if (this.fetchAccountsController === controller) {
          this.loading.accounts = false;
        }
      }
    },

    // Fetch Transactions
    async fetchTransactions(params = {}) {
      if (this.fetchTransactionsController) {
        this.fetchTransactionsController.abort();
      }
      const controller = new AbortController();
      this.fetchTransactionsController = controller;

      this.loading.transactions = true;
      this.errors = {};
      this.transactions = [];

      try {
        const storeFilters = this.filters;
        const apiParams = {
          per_page: params.per_page ?? storeFilters.per_page ?? 15,
          page: params.page ?? storeFilters.page ?? 1,
          from_date: params.from_date ?? params.date_from ?? storeFilters.date_from ?? undefined,
          to_date: params.to_date ?? params.date_to ?? storeFilters.date_to ?? undefined,
          search: params.search ?? storeFilters.search ?? undefined,
          type: params.type ?? storeFilters.type ?? undefined,
          module: params.module ?? storeFilters.module ?? undefined,
          account_id: params.account_id ?? storeFilters.account_id ?? undefined,
          _t: Date.now(),
        };
        const response = await axios.get('/api/v1/reports/transactions', {
          params: apiParams,
          signal: controller.signal,
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
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch transactions:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load transactions',
        };
        this.transactions = [];
      } finally {
        if (this.fetchTransactionsController === controller) {
          this.loading.transactions = false;
        }
      }
    },

    // Fetch Account Entries
    async fetchAccountEntries(params = {}) {
      if (this.fetchAccountEntriesController) {
        this.fetchAccountEntriesController.abort();
      }
      const controller = new AbortController();
      this.fetchAccountEntriesController = controller;

      this.loading.loading = true;
      this.errors = {};
      this.entries = [];

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
          signal: controller.signal
        });
        const responseData = response.data?.data || {};
        this.entries = responseData.items || responseData || [];
        
        const pagination = responseData.pagination || {};
        this.pagination = {
          total: pagination.total || this.entries.length,
          current_page: pagination.current_page || 1,
          last_page: pagination.last_page || 1,
          per_page: pagination.per_page || 20,
        };
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch account entries:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load account entries',
        };
        this.entries = [];
        this.pagination = {
          total: 0,
          current_page: 1,
          last_page: 1,
          per_page: 20,
        };
      } finally {
        if (this.fetchAccountEntriesController === controller) {
          this.loading.loading = false;
        }
      }
    },

    // Fetch Transfers
    async fetchTransfers(params = {}) {
      if (this.fetchTransfersController) {
        this.fetchTransfersController.abort();
      }
      const controller = new AbortController();
      this.fetchTransfersController = controller;

      this.loading.transfers = true;
      this.errors = {};
      this.transfers = [];

      try {
        const response = await axios.get('/api/v1/finance/transfers', {
          params: { per_page: 20, ...params },
          signal: controller.signal,
        });
        const body = response.data?.data ?? response.data;
        this.transfers = unwrapTransferItems(body);
        const pagination = unwrapTransferPagination(body, this.pagination);
        if (body?.summary && typeof body.summary === 'object') {
          this.transferSummary = {
            total_amount: Number(body.summary.total_amount) || 0,
            today_count: Number(body.summary.today_count) || 0,
          };
        }
        if (pagination.total != null) {
          this.pagination = {
            total: pagination.total ?? this.transfers.length,
            current_page: pagination.current_page ?? 1,
            last_page: pagination.last_page ?? 1,
            per_page: pagination.per_page ?? params.per_page ?? 20,
          };
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch transfers:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'Failed to load transfers',
        };
        this.transfers = [];
      } finally {
        if (this.fetchTransfersController === controller) {
          this.loading.transfers = false;
        }
      }
    },

    // Fetch Finance Stats
    async fetchStats(extra = {}) {
      if (this.fetchStatsController) {
        this.fetchStatsController.abort();
      }
      const controller = new AbortController();
      this.fetchStatsController = controller;

      this.loading.stats = true;
      this.errors = {};
      this.stats = {
        total_balance: 0,
        total_income: 0,
        total_expense: 0,
        net_profit: 0,
        accounts_count: 0,
        transactions_count: 0,
      };

      try {
        const summaryParams = { _t: Date.now() };
        const from = extra.from_date ?? extra.date_from ?? this.filters.date_from;
        const to = extra.to_date ?? extra.date_to ?? this.filters.date_to;
        const module = extra.module ?? this.filters.module;
        const category = extra.category ?? this.filters.category;
        if (from) {
          summaryParams.from_date = from;
        }
        if (to) {
          summaryParams.to_date = to;
        }
        if (module) {
          summaryParams.module = module;
        }
        if (category) {
          summaryParams.category = category;
        }

        const [summaryRes, balRes] = await Promise.all([
          axios.get('/api/v1/reports/financial/summary', { params: summaryParams, signal: controller.signal }),
          axios.get('/api/v1/reports/financial/accounts-balance', { params: { _t: Date.now() }, signal: controller.signal }),
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
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch stats:', error);
        // Calculate from local data
        this.stats = {
          total_balance: Array.isArray(this.accounts) ? this.accounts.reduce((sum, a) => sum + (a.balance || 0), 0) : 0,
          total_income: Array.isArray(this.transactions)
            ? this.transactions
                .filter((t) => t.type === 'income')
                .reduce((sum, t) => sum + (t.amount || 0), 0)
            : 0,
          total_expense: Array.isArray(this.transactions)
            ? this.transactions
                .filter((t) => t.type === 'expense')
                .reduce((sum, t) => sum + (t.amount || 0), 0)
            : 0,
          net_profit: 0,
          accounts_count: Array.isArray(this.accounts) ? this.accounts.length : 0,
          transactions_count: Array.isArray(this.transactions) ? this.transactions.length : 0,
        };
        this.stats.net_profit = this.stats.total_income - this.stats.total_expense;
      } finally {
        if (this.fetchStatsController === controller) {
          this.loading.stats = false;
        }
      }
    },

    // Create Transaction
    buildTransactionPayload(payload) {
      const accountId = payload.account_id ?? payload.accountId;
      return {
        type: payload.type,
        amount: Number(payload.amount),
        account_id: accountId ? Number(accountId) : null,
        module: payload.module || 'general',
        description: payload.description || payload.notes || '',
        notes: payload.notes || null,
        reference: payload.reference || null,
        date: payload.date || new Date().toISOString().split('T')[0],
      };
    },

    async createTransaction(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      const apiPayload = this.buildTransactionPayload(payload);
      if (!apiPayload.account_id) {
        this.errors = { account_id: ['يجب اختيار الحساب'] };
        this.loading.create = false;
        throw new Error('account_id required');
      }

      try {
        const response = await axios.post('/api/v1/finance/transactions', apiPayload);
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
      if (this.loading.create) return;
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
      if (this.loading.update) return;
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
      if (this.loading.delete) return;
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
      const fromId =
        payload.fromAccount?.id ??
        payload.fromAccountId ??
        payload.from_account_id;
      const toId =
        payload.toAccount?.id ??
        payload.toAccountId ??
        payload.to_account_id;

      const apiPayload = {
        from_account_id: fromId != null ? Number(fromId) : null,
        to_account_id: toId != null ? Number(toId) : null,
        amount: Number(payload.amount) || 0,
        notes: payload.notes ?? payload.description ?? '',
      };

      if (payload.converted_amount != null) {
        apiPayload.converted_amount = Number(payload.converted_amount);
      }
      if (payload.exchange_rate != null) {
        apiPayload.exchange_rate = Number(payload.exchange_rate);
      }
      if (payload.module) {
        apiPayload.module = payload.module;
      }
      if (payload.type) {
        apiPayload.type = payload.type;
      }

      return apiPayload;
    },

    // Add toast notification
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },

    reset() {
      this.accounts = [];
      this.currentAccount = null;
      this.transactions = [];
      this.currentTransaction = null;
      this.transfers = [];
      this.transferSummary = { total_amount: 0, today_count: 0 };
      this.currentTransfer = null;
      this.entries = [];
      this.stats = {
        total_balance: 0,
        total_income: 0,
        total_expense: 0,
        net_profit: 0,
        accounts_count: 0,
        transactions_count: 0,
      };
      this.incomeByModule = [];
      this.expenseByModule = [];
      this.loading = {
        accounts: false,
        transactions: false,
        transfers: false,
        stats: false,
        create: false,
        update: false,
        delete: false,
        loading: false,
      };
      this.errors = {};
      this.filters = {
        search: '',
        account_id: '',
        type: '',
        module: '',
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
    },
  },
});
