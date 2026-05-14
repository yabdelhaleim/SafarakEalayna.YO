import { defineStore } from 'pinia';
import axios from 'axios';

const round = (n) => Math.round((Number(n) || 0) * 100) / 100;

export const useVisaStore = defineStore('visa', {
  state: () => ({
    bookings: [],
    currentBooking: null,
    customers: [],
    accounts: [],
    agents: [],
    treasuryOverview: null,
    agentsFinance: [],
    durations: [],
    statuses: { hajj_umra: [], visa: [], visa_types: [], visa_entry_types: [] },
    loading: {
      list: false,
      detail: false,
      create: false,
      update: false,
      delete: false,
      customers: false,
      settings: false,
      accounts: false,
    },
    errors: {},
    toasts: [],
    pagination: { total: 0, currentPage: 1, lastPage: 1, perPage: 15 },
    filters: { page: 1, perPage: 15 },
  }),

  getters: {
    bookingStats: (state) => {
      const list = Array.isArray(state.bookings) ? state.bookings : [];
      return {
        total: list.length,
        revenue: round(
          list.reduce((s, b) => s + (b.pricing?.selling_price || 0) + (b.pricing?.service_fee || 0), 0),
        ),
        profit: round(list.reduce((s, b) => s + (b.pricing?.profit || 0), 0)),
        approved: list.filter((b) => ['approved', 'issued'].includes(b.status)).length,
        pending: list.filter((b) => ['submitted', 'under_review', 'draft'].includes(b.status)).length,
        active: list.filter((b) => !['cancelled', 'rejected', 'refunded'].includes(b.status)).length,
      };
    },

    filteredBookings: (state) => (filters = {}) => {
      let list = Array.isArray(state.bookings) ? [...state.bookings] : [];
      if (filters.search) {
        const q = String(filters.search).toLowerCase();
        list = list.filter(
          (b) =>
            String(b.id).includes(q) ||
            (b.customer?.full_name || '').toLowerCase().includes(q) ||
            (b.customer?.phone || '').includes(q) ||
            (b.visa_detail?.country || '').toLowerCase().includes(q),
        );
      }
      if (filters.status) list = list.filter((b) => b.status === filters.status);
      if (filters.country) list = list.filter((b) => b.visa_detail?.country === filters.country);
      if (filters.dateFrom) list = list.filter((b) => new Date(b.created_at) >= new Date(filters.dateFrom));
      if (filters.dateTo) list = list.filter((b) => new Date(b.created_at) <= new Date(filters.dateTo));
      return list;
    },
  },

  actions: {
    addToast(message, type = 'success') {
      const id = Date.now() + Math.random();
      this.toasts.push({ id, message, type });
      setTimeout(() => {
        this.toasts = this.toasts.filter((t) => t.id !== id);
      }, 4000);
    },

    /** Add flat aliases for legacy views */
    _enrich(b) {
      if (!b || typeof b !== 'object') return b;
      const flat = {
        ...b,
        selling_price: b.pricing?.selling_price ?? 0,
        purchase_price: b.pricing?.purchase_price ?? 0,
        service_fee: b.pricing?.service_fee ?? 0,
        profit: b.pricing?.profit ?? 0,
        currency: b.pricing?.currency ?? 'EGP',
        total_paid: b.finance?.paid_amount ?? 0,
        remaining: b.finance?.remaining_amount ?? 0,
        is_fully_paid: b.finance?.is_fully_paid ?? false,
      };
      if (flat.customer && !flat.customer.name) {
        flat.customer = { ...flat.customer, name: flat.customer.full_name };
      }
      return flat;
    },

    async fetchBookings(filters = {}) {
      this.loading.list = true;
      try {
        const { data } = await axios.get('/api/v1/visa/bookings', { params: filters });
        const items = (data?.data?.items ?? []).map((b) => this._enrich(b));
        this.bookings = items;
        const p = data?.data?.pagination ?? {};
        this.pagination = {
          total: p.total ?? items.length,
          currentPage: p.current_page ?? 1,
          lastPage: p.last_page ?? 1,
          perPage: p.per_page ?? 15,
        };
      } catch (e) {
        console.error('fetchBookings visa failed', e);
        this.errors = { fetch: 'فشل تحميل طلبات التأشيرة' };
        this.bookings = [];
      } finally {
        this.loading.list = false;
      }
    },

    async fetchBookingById(id) {
      this.loading.detail = true;
      try {
        const { data } = await axios.get(`/api/v1/visa/bookings/${id}`);
        this.currentBooking = this._enrich(data?.data) ?? null;
        return this.currentBooking;
      } catch (e) {
        console.error('fetchBookingById failed', e);
        this.errors = { fetch: 'فشل تحميل التأشيرة' };
        throw e;
      } finally {
        this.loading.detail = false;
      }
    },

    async createBooking(payload) {
      this.loading.create = true;
      this.errors = {};
      try {
        const { data } = await axios.post('/api/v1/visa/bookings', payload);
        const created = this._enrich(data?.data);
        if (created) this.bookings.unshift(created);
        return created;
      } catch (e) {
        this.errors = e.response?.data?.errors || { message: e.response?.data?.message || 'فشل الإنشاء' };
        throw e;
      } finally {
        this.loading.create = false;
      }
    },

    async updateBooking(id, payload) {
      this.loading.update = true;
      this.errors = {};
      try {
        const { data } = await axios.put(`/api/v1/visa/bookings/${id}`, payload);
        const updated = this._enrich(data?.data);
        const i = this.bookings.findIndex((b) => b.id === id);
        if (i !== -1 && updated) this.bookings[i] = updated;
        if (this.currentBooking?.id === id) this.currentBooking = updated;
        return updated;
      } catch (e) {
        this.errors = e.response?.data?.errors || { message: e.response?.data?.message || 'فشل التعديل' };
        throw e;
      } finally {
        this.loading.update = false;
      }
    },

    async cancelBooking(id, reason = '') {
      this.loading.delete = true;
      try {
        const { data } = await axios.delete(`/api/v1/visa/bookings/${id}`, { data: { reason } });
        const updated = this._enrich(data?.data);
        const i = this.bookings.findIndex((b) => b.id === id);
        if (i !== -1 && updated) this.bookings[i] = updated;
        return updated;
      } catch (e) {
        this.errors = { delete: 'فشل الإلغاء' };
        throw e;
      } finally {
        this.loading.delete = false;
      }
    },

    async addPayment(bookingId, paymentData) {
      this.errors = {};
      try {
        const { data } = await axios.post(
          `/api/v1/visa/bookings/${bookingId}/payments`,
          paymentData,
        );
        const enrichedBooking = this._enrich(data?.data?.booking);
        if (enrichedBooking) {
          const i = this.bookings.findIndex((b) => b.id === bookingId);
          if (i !== -1) this.bookings[i] = enrichedBooking;
          if (this.currentBooking?.id === bookingId) this.currentBooking = enrichedBooking;
        }
        return data?.data;
      } catch (e) {
        this.errors = e.response?.data?.errors || { message: 'فشل تسجيل الدفعة' };
        throw e;
      }
    },

    async fetchCustomers(search = '') {
      this.loading.customers = true;
      try {
        const { data } = await axios.get('/api/v1/customers', { params: { search, per_page: 25 } });
        const items = data?.data?.items ?? data?.data ?? [];
        this.customers = Array.isArray(items) ? items : [];
      } catch (e) {
        this.customers = [];
      } finally {
        this.loading.customers = false;
      }
    },

    async createCustomer(payload) {
      const { data } = await axios.post('/api/v1/customers', payload);
      const created = data?.data ?? data;
      if (created) this.customers.unshift(created);
      return created;
    },

    async fetchSettings() {
      this.loading.settings = true;
      try {
        const [agents, durations, statuses] = await Promise.all([
          axios.get('/api/v1/visa/settings/agents'),
          axios.get('/api/v1/visa/settings/durations'),
          axios.get('/api/v1/visa/settings/statuses'),
        ]);
        this.agents = agents.data?.data ?? [];
        this.durations = durations.data?.data ?? [];
        this.statuses = statuses.data?.data ?? this.statuses;
      } catch (e) {
        console.error('fetchSettings visa failed', e);
      } finally {
        this.loading.settings = false;
      }
    },

    async fetchAccounts() {
      this.loading.accounts = true;
      try {
        const { data } = await axios.get('/api/v1/finance/accounts', {
          params: { per_page: 100, is_active: 1, module: 'visa' },
        });
        const items = data?.data?.items ?? data?.data ?? [];
        this.accounts = Array.isArray(items) ? items : [];
      } catch (e) {
        this.accounts = [];
      } finally {
        this.loading.accounts = false;
      }
    },

    /** Backwards-compat shim */
    async deleteBooking(id) {
      return this.cancelBooking(id);
    },

    /** Visa Treasury & Agents Finance */
    async fetchVisaTreasuryOverview() {
      this.loading.list = true;
      try {
        const { data } = await axios.get('/api/v1/visa/treasury/overview');
        this.treasuryOverview = data?.data ?? null;
        return this.treasuryOverview;
      } catch (e) {
        console.error('fetchVisaTreasuryOverview failed', e);
        throw e;
      } finally {
        this.loading.list = false;
      }
    },

    async fetchAccountVisaTransactions(accountId, params = {}) {
      try {
        const { data } = await axios.get(`/api/v1/visa/treasury/accounts/${accountId}/transactions`, { params });
        return data?.data;
      } catch (e) {
        console.error('fetchAccountVisaTransactions failed', e);
        throw e;
      }
    },

    async fetchVisaAgentsDues() {
      this.loading.list = true;
      try {
        const { data } = await axios.get('/api/v1/visa/agents/dues');
        this.agentsFinance = data?.data?.items ?? [];
        return this.agentsFinance;
      } catch (e) {
        console.error('fetchVisaAgentsDues failed', e);
        throw e;
      } finally {
        this.loading.list = false;
      }
    },

    async recordVisaAgentWithdraw(agentId, payload) {
      try {
        const { data } = await axios.post(`/api/v1/visa/agents/${agentId}/withdraw`, payload);
        this.addToast('تم تسجيل السحب بنجاح');
        return data?.data;
      } catch (e) {
        const msg = e.response?.data?.message || 'فشل تسجيل السحب';
        this.addToast(msg, 'error');
        throw e;
      }
    },

    async recordVisaAgentRepay(agentId, payload) {
      try {
        const { data } = await axios.post(`/api/v1/visa/agents/${agentId}/repay`, payload);
        this.addToast('تم تسجيل السداد بنجاح');
        return data?.data;
      } catch (e) {
        const msg = e.response?.data?.message || 'فشل تسجيل السداد';
        this.addToast(msg, 'error');
        throw e;
      }
    },
  },
});
