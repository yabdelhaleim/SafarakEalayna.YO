import { defineStore } from 'pinia';
import axios from 'axios';

const PAYMENT_STATUS_META = [
  { value: 'pending', label: 'معلق', color: 'warning' },
  { value: 'partial', label: 'مدفوع جزئياً', color: 'blue' },
  { value: 'paid', label: 'مدفوع', color: 'success' },
  { value: 'overdue', label: 'متأخر', color: 'error' },
];

export const useBusStore = defineStore('bus', {
  state: () => ({
    bookings: [],
    currentBooking: null,

    inventory: [],
    currentInventory: null,

    companies: [],

    treasuryOverview: null,

    stats: {
      total_bookings: 0,
      paid_bookings: 0,
      pending_bookings: 0,
      cancelled_bookings: 0,
      total_revenue: 0,
      pending_payments: 0,
      active_routes: 0,
    },

    loading: {
      bookings: false,
      inventory: false,
      companies: false,
      payments: false,
      create: false,
      update: false,
      delete: false,
    },

    errors: {},

    filters: {
      search: '',
      status: '',
      company_id: '',
      route_from: '',
      route_to: '',
      date_from: '',
      date_to: '',
      page: 1,
      per_page: 15,
    },

    pagination: {
      total: 0,
      current_page: 1,
      last_page: 1,
      per_page: 15,
    },
  }),

  getters: {
    /** Server-side filters already applied — current page rows only. */
    filteredBookings: (state) => [...(state.bookings || [])],

    bookingStatuses: () => [
      { value: 'pending', label: 'معلق', color: 'warning' },
      { value: 'paid', label: 'مدفوع', color: 'success' },
      { value: 'cancelled', label: 'ملغي', color: 'error' },
    ],

    paymentStatuses: () => PAYMENT_STATUS_META,

    getPaymentStatusLabel: () => (status) =>
      PAYMENT_STATUS_META.find((s) => s.value === status)?.label || status,

    getPaymentStatusColor: () => (status) =>
      PAYMENT_STATUS_META.find((s) => s.value === status)?.color || 'gray',

    recentBookings: (state) => (state.bookings || []).slice(0, 10),

    availableInventory: (state) =>
      (state.inventory || []).filter((i) => (i.available_seats || 0) > 0),

    totalCompanyDebt: (state) =>
      (state.companies || []).reduce((sum, c) => sum + (c.debt || 0), 0),
  },

  actions: {
    mapBooking(b) {
      if (!b) return null;
      let routeFrom = '';
      let routeTo = '';
      if (b.inventory?.route) {
        const parts = String(b.inventory.route).split('-');
        routeFrom = parts[0]?.trim() || '';
        routeTo = parts[1]?.trim() || '';
      }
      const st = typeof b.status === 'object' && b.status?.value != null ? b.status.value : b.status;
      const ps =
        typeof b.payment_status === 'object' && b.payment_status?.value != null
          ? b.payment_status.value
          : b.payment_status;

      return {
        ...b,
        status: st,
        payment_status: ps,
        booking_number: b.booking_number || b.id,
        travel_date: b.inventory?.travel_date || b.travel_date,
        seats_count: b.quantity || b.seats_count,
        paid_amount: Number(b.paid_amount) || 0,
        remaining_amount: Math.max(0, (Number(b.total_price) || 0) - (Number(b.paid_amount) || 0)),
        inventory: b.inventory
          ? {
              ...b.inventory,
              route_from: routeFrom,
              route_to: routeTo,
              bus_company: b.company || b.inventory?.company || {},
              bus_company_id:
                b.inventory?.company_id ?? b.company?.id ?? b.inventory?.company?.id,
            }
          : null,
      };
    },

    mapInventory(i) {
      if (!i) return null;
      let routeFrom = '';
      let routeTo = '';
      if (i.route) {
        const parts = String(i.route).split('-');
        routeFrom = parts[0]?.trim() || '';
        routeTo = parts[1]?.trim() || '';
      }
      return {
        ...i,
        route_from: routeFrom,
        route_to: routeTo,
        available_seats: i.available_tickets ?? i.available_seats,
        total_seats: i.total_tickets ?? i.total_seats,
        seat_price: i.selling_price || i.seat_price,
        bus_company: i.company || {},
        bus_company_id: i.company_id ?? i.company?.id,
      };
    },

    transformPayloadForApi(payload) {
      return {
        inventory_id: payload.inventory?.id || payload.inventoryId || payload.inventory_id,
        customer_id: payload.customer?.id || payload.customerId || payload.customer_id || null,
        customer_name: payload.customer_name || payload.customer?.name || null,
        customer_phone: payload.customer_phone || payload.customer?.phone || null,
        employee_id: payload.employee_id ?? undefined,
        quantity: payload.quantity || payload.seats_count || payload.seatsCount || 1,
        notes: payload.notes || null,
      };
    },

    async fetchBookingStats() {
      try {
        const res = await axios.get('/api/v1/bus/bookings/stats');
        const d = res.data?.data || res.data || {};
        this.stats.total_bookings = Number(d.total_bookings) || 0;
        this.stats.paid_bookings = Number(d.paid_bookings) || 0;
        this.stats.pending_bookings = Number(d.pending_bookings) || 0;
        this.stats.cancelled_bookings = Number(d.cancelled_bookings) || 0;
        this.stats.total_revenue = Number(d.total_revenue) || 0;
        this.stats.pending_payments = Number(d.pending_payments) || 0;
      } catch (e) {
        if (axios.isCancel(e)) return;
        console.error('fetchBookingStats', e);
      }
    },

    async fetchBookings(params = {}) {
      if (this.loading.bookings) return;
      this.loading.bookings = true;
      this.errors = {};
      const controller = new AbortController();
      try {
        const q = { ...this.filters, ...params };
        if (q.company_id === '') delete q.company_id;
        if (q.status === '') delete q.status;
        if (q.search === '') delete q.search;
        if (q.date_from === '') delete q.date_from;
        if (q.date_to === '') delete q.date_to;

        const response = await axios.get('/api/v1/bus/bookings', {
          params: q,
          signal: controller.signal,
        });
        const responseData = response.data?.data || response.data;
        const items = responseData.items || (Array.isArray(responseData) ? responseData : []);
        this.bookings = items.map((b) => this.mapBooking(b));

        const pagination = responseData.pagination || response.data?.pagination || {};
        this.pagination = {
          total: pagination.total || response.data?.total || this.bookings.length,
          current_page: pagination.current_page || response.data?.current_page || 1,
          last_page: pagination.last_page || response.data?.last_page || 1,
          per_page: pagination.per_page || response.data?.per_page || 15,
        };
      } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to fetch bookings:', error);
        this.errors = { fetch: error.response?.data?.message || 'فشل تحميل الحجوزات' };
        this.bookings = [];
      } finally {
        this.loading.bookings = false;
      }
    },

    async fetchBooking(id) {
      this.loading.bookings = true;
      this.errors = {};
      try {
        const response = await axios.get(`/api/v1/bus/bookings/${id}`);
        const raw = response.data?.data || response.data;
        const b = this.mapBooking(raw);
        this.currentBooking = b;
        return b;
      } catch (error) {
        console.error('Failed to fetch booking:', error);
        this.errors = { fetch: error.response?.data?.message || 'فشل تحميل الحجز' };
        this.currentBooking = null;
        throw error;
      } finally {
        this.loading.bookings = false;
      }
    },

    async fetchInventory(params = {}) {
      if (this.loading.inventory) return;
      this.loading.inventory = true;
      this.errors = {};
      const controller = new AbortController();
      try {
        const response = await axios.get('/api/v1/bus/inventories', {
          params: { per_page: 200, ...params },
          signal: controller.signal,
        });
        const data = response.data?.data || response.data;
        const items = data.items || (Array.isArray(data) ? data : []);
        this.inventory = items.map((i) => this.mapInventory(i));
      } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to fetch inventory:', error);
        this.errors = { fetch: error.response?.data?.message || 'فشل تحميل المخزون' };
        this.inventory = [];
      } finally {
        this.loading.inventory = false;
      }
    },

    /** Load one inventory row by id (e.g. deep link from رحلات الباص). Merges into `inventory` if missing. */
    async fetchInventoryItem(id) {
      const response = await axios.get(`/api/v1/bus/inventories/${id}`);
      const raw = response.data?.data || response.data;
      const inv = this.mapInventory(raw);
      if (inv && !(this.inventory || []).some((x) => String(x.id) === String(inv.id))) {
        this.inventory = [inv, ...(this.inventory || [])];
      }
      return inv;
    },

    async fetchCompanies(params = {}) {
      if (this.loading.companies) return;
      this.loading.companies = true;
      this.errors = {};
      const controller = new AbortController();
      try {
        const response = await axios.get('/api/v1/bus/companies', {
          params: { per_page: 100, ...params },
          signal: controller.signal,
        });
        const data = response.data?.data || response.data;
        this.companies = data.items || (Array.isArray(data) ? data : []);
      } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to fetch companies:', error);
        this.errors = { fetch: error.response?.data?.message || 'فشل تحميل الشركات' };
        this.companies = [];
      } finally {
        this.loading.companies = false;
      }
    },

    async createInventory(form) {
      this.loading.create = true;
      this.errors = {};
      try {
        const routeStr = `${String(form.route_from || '').trim()} - ${String(form.route_to || '').trim()}`;
        const body = {
          company_id: parseInt(String(form.company_id), 10),
          route: routeStr,
          travel_date: form.travel_date,
          departure_time: form.departure_time || null,
          total_tickets: Number(form.total_seats),
          cost_per_ticket: Number(form.cost_per_ticket),
          selling_price: Number(form.seat_price),
          payment_type: form.payment_type || 'deferred',
          account_id: form.payment_type === 'cash' ? parseInt(String(form.account_id), 10) : null,
          notes: form.notes || null,
        };
        const response = await axios.post('/api/v1/bus/inventories', body);
        const inv = this.mapInventory(response.data?.data || response.data);
        if (inv) this.inventory.unshift(inv);
        await this.fetchStats();
        return inv;
      } catch (error) {
        const api = error.response?.data;
        const msg =
          (typeof api?.message === 'string' && api.message) ||
          (api?.errors && typeof api.errors === 'object' && Object.values(api.errors).flat()[0]) ||
          'فشل إنشاء الرحلة';
        this.errors = { message: msg, ...(typeof api?.errors === 'object' ? api.errors : {}) };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateInventory(id, form) {
      this.loading.update = true;
      this.errors = {};
      try {
        const routeStr = `${String(form.route_from || '').trim()} - ${String(form.route_to || '').trim()}`;
        const body = {
          route: routeStr,
          travel_date: form.travel_date,
          departure_time: form.departure_time || null,
          selling_price: Number(form.seat_price),
          notes: form.notes || null,
        };
        const response = await axios.put(`/api/v1/bus/inventories/${id}`, body);
        const inv = this.mapInventory(response.data?.data || response.data);
        const idx = this.inventory.findIndex((x) => x.id === id);
        if (idx !== -1 && inv) this.inventory[idx] = inv;
        return inv;
      } catch (error) {
        const api = error.response?.data;
        const msg =
          (typeof api?.message === 'string' && api.message) ||
          (api?.errors && typeof api.errors === 'object' && Object.values(api.errors).flat()[0]) ||
          'فشل تحديث الرحلة';
        this.errors = { message: msg, ...(typeof api?.errors === 'object' ? api.errors : {}) };
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteInventory(id) {
      this.loading.delete = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/bus/inventories/${id}`);
        this.inventory = this.inventory.filter((i) => i.id !== id);
      } catch (error) {
        this.errors = { delete: error.response?.data?.message || 'فشل حذف الرحلة' };
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    async createBooking(payload) {
      this.loading.create = true;
      this.errors = {};
      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/bus/bookings', apiPayload);
        const newBooking = this.mapBooking(response.data?.data || response.data);
        if (newBooking) this.bookings.unshift(newBooking);
        await this.fetchStats();
        return newBooking;
      } catch (error) {
        this.errors = error.response?.data?.errors || { message: 'فشل إنشاء الحجز' };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async cancelBooking(id) {
      this.loading.update = true;
      this.errors = {};
      try {
        const response = await axios.post(`/api/v1/bus/bookings/${id}/cancel`);
        const updated = this.mapBooking(response.data?.data || response.data);
        const index = this.bookings.findIndex((b) => b.id === id);
        if (index !== -1 && updated) this.bookings[index] = updated;
        await this.fetchStats();
        return updated;
      } catch (error) {
        const api = error.response?.data;
        const msg =
          (typeof api?.message === 'string' && api.message) ||
          (api?.errors && typeof api.errors === 'object' && Object.values(api.errors).flat()[0]) ||
          'فشل إلغاء الحجز';
        this.errors = { message: msg, ...(typeof api?.errors === 'object' ? api.errors : {}) };
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteBooking(id) {
      this.loading.delete = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/bus/bookings/${id}`);
        this.bookings = this.bookings.filter((b) => b.id !== id);
        await this.fetchStats();
      } catch (error) {
        this.errors = { delete: error.response?.data?.message || 'فشل حذف الحجز' };
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    async payBooking(id, payload) {
      this.loading.payments = true;
      this.errors = {};
      try {
        const apiPayload = {
          amount: Number(payload.amount) || 0,
          payment_method: payload.payment_method || 'cash',
          account_id:
            payload.account_id != null && payload.account_id !== ''
              ? parseInt(String(payload.account_id), 10)
              : null,
          notes: payload.notes || null,
        };
        const response = await axios.post(`/api/v1/bus/bookings/${id}/pay`, apiPayload);
        const paidBooking = this.mapBooking(response.data?.data || response.data);
        const index = this.bookings.findIndex((b) => b.id === id);
        if (index !== -1 && paidBooking) this.bookings[index] = paidBooking;
        await this.fetchStats();
        return paidBooking;
      } catch (error) {
        const api = error.response?.data;
        const msg =
          (typeof api?.message === 'string' && api.message) ||
          (api?.errors?.amount && (Array.isArray(api.errors.amount) ? api.errors.amount[0] : api.errors.amount)) ||
          (api?.errors && typeof api.errors === 'object' && Object.values(api.errors).flat()[0]) ||
          'فشل تسجيل الدفع';
        this.errors = { message: msg, ...(typeof api?.errors === 'object' ? api.errors : {}) };
        throw error;
      } finally {
        this.loading.payments = false;
      }
    },

    async fetchStats() {
      await this.fetchBookingStats();
      const inventory = this.inventory || [];
      this.stats.active_routes = inventory.filter((i) => (i.available_seats || 0) > 0).length;
    },

    async createCompany(payload) {
      this.loading.create = true;
      try {
        const response = await axios.post('/api/v1/bus/companies', payload);
        const newCompany = response.data?.data || response.data;
        this.companies.unshift(newCompany);
        return newCompany;
      } catch (error) {
        console.error('Failed to create company', error);
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateCompany(id, payload) {
      this.loading.update = true;
      try {
        const response = await axios.put(`/api/v1/bus/companies/${id}`, payload);
        const updated = response.data?.data || response.data;
        const index = this.companies.findIndex((c) => c.id === id);
        if (index !== -1) this.companies[index] = updated;
        return updated;
      } catch (error) {
        console.error('Failed to update company', error);
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteCompany(id) {
      this.loading.delete = true;
      try {
        await axios.delete(`/api/v1/bus/companies/${id}`);
        this.companies = this.companies.filter((c) => c.id !== id);
      } catch (error) {
        console.error('Failed to delete company', error);
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    async payCompanyDebt(companyId, payload) {
      this.loading.payments = true;
      try {
        // Debt payment is usually a transaction to the company's account
        const response = await axios.post(`/api/v1/bus/companies/${companyId}/pay-debt`, payload);
        await this.fetchCompanies();
        return response.data;
      } catch (error) {
        console.error('Failed to pay company debt', error);
        throw error;
      } finally {
        this.loading.payments = false;
      }
    },

    async fetchBusDashboard() {
      try {
        const response = await axios.get('/api/v1/bus/dashboard');
        return response.data?.data ?? null;
      } catch (error) {
        console.error('Failed to fetch bus dashboard', error);
        return null;
      }
    },

    async fetchBusTreasuryOverview() {
      this.loading.payments = true;
      try {
        const response = await axios.get('/api/v1/bus/treasury/overview');
        this.treasuryOverview = response.data?.data ?? null;
        return this.treasuryOverview;
      } catch (error) {
        console.error('Failed to fetch bus treasury overview', error);
        return null;
      } finally {
        this.loading.payments = false;
      }
    },

    async fetchAccountBusTransactions(accountId, params = {}) {
      try {
        const response = await axios.get(`/api/v1/bus/treasury/accounts/${accountId}/bus-transactions`, { params });
        return response.data?.data ?? null;
      } catch (error) {
        console.error('Failed to fetch account bus transactions', error);
        return null;
      }
    },

    async fetchCompanyBusStatement(companyId, params = {}) {
      try {
        const response = await axios.get(`/api/v1/bus/companies/${companyId}/statement`, { params });
        return response.data?.data ?? null;
      } catch (error) {
        console.error('Failed to fetch company bus statement', error);
        throw error;
      }
    },

    async fetchRefundTreasuries(currency = '') {
      try {
        const res = await axios.get('/api/v1/bus/refunds/treasuries', { params: { currency } });
        return res.data?.data || [];
      } catch (e) {
        console.error('fetchRefundTreasuries', e);
        return [];
      }
    },

    async createRefund(payload) {
      this.loading.create = true;
      try {
        const res = await axios.post('/api/v1/bus/refunds', payload);
        return res.data?.data || res.data;
      } catch (e) {
        console.error('createRefund', e);
        throw e;
      } finally {
        this.loading.create = false;
      }
    },

    async processRefund(id) {
      this.loading.update = true;
      try {
        const res = await axios.post(`/api/v1/bus/refunds/${id}/process`);
        await this.fetchStats();
        return res.data?.data || res.data;
      } catch (e) {
        console.error('processRefund', e);
        throw e;
      } finally {
        this.loading.update = false;
      }
    },

    addToast(message, type = 'success') {
      if (window.addToast) window.addToast(message, type);
    },
  },
});
