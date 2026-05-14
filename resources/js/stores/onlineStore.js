import { defineStore } from 'pinia';
import axios from 'axios';

const initialFilters = {
  search: '',
  status: '',
  service_type_id: '',
  provider_id: '',
  payment_method: '',
  account_id: '',
  customer_id: '',
  employee_id: '',
  from_date: '',
  to_date: '',
  per_page: 15,
  page: 1,
};

const ENVELOPE = (response) => response?.data?.data ?? response?.data ?? null;

function firstValidationMessage(errors) {
  if (!errors || typeof errors !== 'object') {
    return null;
  }
  for (const messages of Object.values(errors)) {
    if (Array.isArray(messages) && messages.length > 0) {
      return messages[0];
    }
  }
  return null;
}

export const useOnlineStore = defineStore('online', {
  state: () => ({
    /* ---------- Master data (managed in Filament, fetched dynamically) ---------- */
    serviceTypes: [],
    providers: [],
    paymentMethods: [],
    accounts: [],
    customers: [],
    employees: [],
    statuses: [],

    /* ---------- Operations ---------- */
    transactions: [],
    currentTransaction: null,

    /* ---------- Stats ---------- */
    stats: {
      total_transactions: 0,
      total_purchase: 0,
      total_selling: 0,
      total_profit: 0,
      pending_transactions: 0,
      completed_transactions: 0,
      failed_transactions: 0,
    },

    /* ---------- UI flags ---------- */
    loading: {
      settings: false,
      types: false,
      providers: false,
      transactions: false,
      create: false,
      update: false,
      delete: false,
      summary: false,
    },
    errors: {},

    filters: { ...initialFilters },

    pagination: {
      total: 0,
      current_page: 1,
      last_page: 1,
      per_page: 15,
    },
  }),

  getters: {
    activeServiceTypes: (state) => state.serviceTypes.filter((t) => t.is_active),
    activeProviders: (state) => state.providers.filter((p) => p.is_active),
    typesById: (state) => Object.fromEntries(state.serviceTypes.map((t) => [t.id, t])),
    providersById: (state) => Object.fromEntries(state.providers.map((p) => [p.id, p])),
    paymentMethodByCode: (state) =>
      Object.fromEntries(state.paymentMethods.map((m) => [m.code ?? m.value, m])),
  },

  actions: {
    /* ===========================================================
     * MASTER DATA — comes from Filament; never hardcode in Vue
     * =========================================================== */
    async fetchAllSettings() {
      this.loading.settings = true;
      try {
        const response = await axios.get('/api/v1/online/settings/all');
        const data = ENVELOPE(response) ?? {};
        this.serviceTypes = (data.service_types ?? []).map(this.normalizeMaster);
        this.providers = (data.providers ?? []).map(this.normalizeMaster);
        this.paymentMethods = data.payment_methods ?? [];
        this.accounts = data.accounts ?? [];
        this.statuses = data.statuses ?? [];
      } catch (error) {
        console.error('fetchAllSettings failed', error);
        this.addToast('فشل تحميل إعدادات الخدمات الأونلاين', 'error');
      } finally {
        this.loading.settings = false;
      }
    },

    async fetchCustomers() {
      try {
        const response = await axios.get('/api/v1/online/settings/customers');
        this.customers = ENVELOPE(response) ?? [];
      } catch (error) {
        console.error('fetchCustomers failed', error);
        this.customers = [];
      }
    },

    async fetchEmployees() {
      try {
        const response = await axios.get('/api/v1/online/settings/employees');
        this.employees = ENVELOPE(response) ?? [];
      } catch (error) {
        console.error('fetchEmployees failed', error);
        this.employees = [];
      }
    },

    /**
     * Normalise master entries coming from /settings/all so that the UI can rely
     * on `id`, `name`, `is_active`, plus original fields.
     */
    normalizeMaster(item) {
      return {
        ...item,
        name: item.label ?? item.name ?? item.name_ar ?? '',
        is_active: item.is_active ?? true,
      };
    },

    /* ===========================================================
     * SERVICE TYPES — full CRUD against /service-types
     * =========================================================== */
    async fetchServiceTypes(params = {}) {
      this.loading.types = true;
      try {
        const response = await axios.get('/api/v1/online/service-types', { params });
        const payload = ENVELOPE(response) ?? {};
        this.serviceTypes = payload.items ?? payload ?? [];
      } catch (error) {
        console.error('fetchServiceTypes failed', error);
        this.serviceTypes = [];
      } finally {
        this.loading.types = false;
      }
    },

    async createServiceType(payload) {
      this.loading.create = true;
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/online/service-types', payload);
        const created = ENVELOPE(response);
        if (created) this.serviceTypes.unshift(created);
        this.addToast('تم إنشاء نوع الخدمة بنجاح');
        return created;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        this.addToast(error.response?.data?.message ?? 'فشل إنشاء نوع الخدمة', 'error');
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateServiceType(id, payload) {
      this.loading.update = true;
      this.errors = {};
      try {
        const response = await axios.put(`/api/v1/online/service-types/${id}`, payload);
        const updated = ENVELOPE(response);
        const idx = this.serviceTypes.findIndex((s) => s.id === id);
        if (idx > -1 && updated) this.serviceTypes[idx] = updated;
        this.addToast('تم تحديث نوع الخدمة بنجاح');
        return updated;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        this.addToast(error.response?.data?.message ?? 'فشل تحديث نوع الخدمة', 'error');
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteServiceType(id) {
      this.loading.delete = true;
      try {
        await axios.delete(`/api/v1/online/service-types/${id}`);
        this.serviceTypes = this.serviceTypes.filter((s) => s.id !== id);
        this.addToast('تم حذف نوع الخدمة بنجاح');
      } catch (error) {
        this.addToast(error.response?.data?.message ?? 'فشل حذف نوع الخدمة', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    /* ===========================================================
     * SERVICE PROVIDERS — full CRUD against /providers
     * =========================================================== */
    async fetchProviders(params = {}) {
      this.loading.providers = true;
      try {
        const response = await axios.get('/api/v1/online/providers', { params });
        const payload = ENVELOPE(response) ?? {};
        this.providers = payload.items ?? payload ?? [];
      } catch (error) {
        console.error('fetchProviders failed', error);
        this.providers = [];
      } finally {
        this.loading.providers = false;
      }
    },

    async createProvider(payload) {
      this.loading.create = true;
      try {
        const response = await axios.post('/api/v1/online/providers', payload);
        const created = ENVELOPE(response);
        if (created) this.providers.unshift(created);
        this.addToast('تم إنشاء مزود الخدمة بنجاح');
        return created;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        this.addToast(error.response?.data?.message ?? 'فشل إنشاء مزود الخدمة', 'error');
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateProvider(id, payload) {
      this.loading.update = true;
      try {
        const response = await axios.put(`/api/v1/online/providers/${id}`, payload);
        const updated = ENVELOPE(response);
        const idx = this.providers.findIndex((p) => p.id === id);
        if (idx > -1 && updated) this.providers[idx] = updated;
        this.addToast('تم تحديث مزود الخدمة بنجاح');
        return updated;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        this.addToast(error.response?.data?.message ?? 'فشل تحديث مزود الخدمة', 'error');
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteProvider(id) {
      this.loading.delete = true;
      try {
        await axios.delete(`/api/v1/online/providers/${id}`);
        this.providers = this.providers.filter((p) => p.id !== id);
        this.addToast('تم حذف مزود الخدمة بنجاح');
      } catch (error) {
        this.addToast(error.response?.data?.message ?? 'فشل حذف مزود الخدمة', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    /* ===========================================================
     * TRANSACTIONS — full CRUD against /transactions
     * =========================================================== */
    async fetchTransactions(params = {}) {
      this.loading.transactions = true;
      try {
        const merged = { ...this.filters, ...params };
        Object.keys(merged).forEach((k) => {
          if (merged[k] === '' || merged[k] === null) delete merged[k];
        });
        const response = await axios.get('/api/v1/online/transactions', { params: merged });
        const payload = ENVELOPE(response) ?? {};
        this.transactions = payload.items ?? [];
        this.pagination = {
          total: payload.pagination?.total ?? 0,
          current_page: payload.pagination?.current_page ?? 1,
          last_page: payload.pagination?.last_page ?? 1,
          per_page: payload.pagination?.per_page ?? 15,
        };
      } catch (error) {
        console.error('fetchTransactions failed', error);
        this.transactions = [];
      } finally {
        this.loading.transactions = false;
      }
    },

    async createTransaction(payload) {
      this.loading.create = true;
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/online/transactions', payload);
        const created = ENVELOPE(response);
        if (created) this.transactions.unshift(created);
        this.addToast('تم تنفيذ المعاملة بنجاح');
        await this.fetchDailySummary();
        return created;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        const detail = firstValidationMessage(this.errors);
        this.addToast(
          detail || error.response?.data?.message || 'فشل تنفيذ المعاملة',
          'error',
        );
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateTransaction(id, payload) {
      this.loading.update = true;
      try {
        const response = await axios.put(`/api/v1/online/transactions/${id}`, payload);
        const updated = ENVELOPE(response);
        const idx = this.transactions.findIndex((t) => t.id === id);
        if (idx > -1 && updated) this.transactions[idx] = updated;
        this.addToast('تم تحديث المعاملة بنجاح');
        return updated;
      } catch (error) {
        this.errors = error.response?.data?.errors ?? {};
        const detail = firstValidationMessage(this.errors);
        this.addToast(
          detail || error.response?.data?.message || 'فشل تحديث المعاملة',
          'error',
        );
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteTransaction(id) {
      this.loading.delete = true;
      try {
        await axios.delete(`/api/v1/online/transactions/${id}`);
        this.transactions = this.transactions.filter((t) => t.id !== id);
        this.addToast('تم حذف المعاملة بنجاح');
        await this.fetchDailySummary();
      } catch (error) {
        this.addToast(error.response?.data?.message ?? 'فشل حذف المعاملة', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    async fetchDailySummary(date = null) {
      this.loading.summary = true;
      try {
        const today = date ?? new Date().toISOString().slice(0, 10);
        const response = await axios.get('/api/v1/online/transactions/daily-summary', {
          params: { date: today },
        });
        const data = ENVELOPE(response) ?? {};
        this.stats = {
          ...this.stats,
          total_transactions: data.total_transactions ?? 0,
          total_purchase: data.total_purchase ?? 0,
          total_selling: data.total_selling ?? 0,
          total_profit: data.total_profit ?? 0,
        };
      } catch (error) {
        console.error('fetchDailySummary failed', error);
      } finally {
        this.loading.summary = false;
      }
    },

    resetFilters() {
      this.filters = { ...initialFilters };
    },

    addToast(message, type = 'success') {
      if (typeof window !== 'undefined' && window.addToast) {
        window.addToast(message, type);
      }
    },
  },
});
