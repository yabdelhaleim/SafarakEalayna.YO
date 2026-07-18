import { defineStore } from 'pinia';
import axios from 'axios';
import { isRequestCanceled } from '@/utils/api';

const ensureArray = (val) => {
  if (!val) return [];
  if (typeof val === 'string') {
    try {
      return JSON.parse(val);
    } catch (e) {
      return [];
    }
  }
  return Array.isArray(val) ? val : Object.values(val);
};

export const useFlightStore = defineStore('flight', {
  state: () => ({
    bookings: [],
    currentBooking: null,
    customers: [],
    carriers: [],
    systems: [],
    groups: [],
    airports: [],
    popularAirports: [],
    tripTypes: [],
    currencies: [],
    /** @type {Array<{value: string, label: string}>} */
    bookingStatuses: [],
    /** @type {Array<{value: string, label: string}>} */
    paymentFilterStatuses: [],
    /** @type {Array<{value: string, label: string}>} Dashboard / analytics system_type filter */
    systemTypeEnumOptions: [],
    /** @type {Array<{value: string, label: string}>} */
    passengerTypes: [],
    systemTypes: [],
    airlineAccounts: [],
    /** @type {null | { systems: [], carriers: [], settlement_accounts: [], recent_flight_transactions: [] }} */
    treasuryOverview: null,
    loading: {
      list: false,
      create: false,
      update: false,
      delete: false,
      customers: false,
      carriers: false,
      systems: false,
      groups: false,
      airports: false,
      tripTypes: false,
      currencies: false,
      flightReference: false,
      airlineAccounts: false,
      systemTypes: false,
      treasuryOverview: false
    },
    errors: {},
    toasts: [],
    filters: {
      search: '',
      airline: '',
      status: '',
      dateFrom: '',
      dateTo: '',
      page: 1,
      perPage: 15
    },
    pagination: {
      total: 0,
      currentPage: 1,
      lastPage: 1,
      perPage: 15
    }
  }),

  getters: {
    filteredBookings: (state) => (filters) => {
      let filtered = Array.isArray(state.bookings) ? [...state.bookings] : [];

      if (filters.search) {
        const query = filters.search.toLowerCase();
        filtered = filtered.filter(b =>
          b.bookingNumber?.toLowerCase().includes(query) ||
          b.customer?.name?.toLowerCase().includes(query) ||
          b.pnr?.toLowerCase().includes(query)
        );
      }

      if (filters.status) {
        filtered = filtered.filter(b => b.status === filters.status);
      }

      const tripTypeFilter = filters.trip_type || filters.tripType;
      if (tripTypeFilter) {
        filtered = filtered.filter(
          (b) => (b.trip_type || b.tripType) === tripTypeFilter
        );
      }

      if (filters.currency) {
        const code = String(filters.currency).toUpperCase();
        filtered = filtered.filter(
          (b) => (b.purchaseCurrency || b.pricing?.purchaseCurrency || 'EGP').toUpperCase() === code
        );
      }

      if (filters.flight_system_id) {
        filtered = filtered.filter(b => b.flight_system_id === parseInt(filters.flight_system_id));
      }

      if (filters.flight_carrier_id) {
        filtered = filtered.filter(b => b.flight_carrier_id === parseInt(filters.flight_carrier_id));
      }

      if (filters.customer_id) {
        filtered = filtered.filter(b => b.customer_id === parseInt(filters.customer_id));
      }

      if (filters.departure_date_from) {
        filtered = filtered.filter(b => {
          const departureDate = new Date(b.departure_date);
          return departureDate >= new Date(filters.departure_date_from);
        });
      }

      if (filters.departure_date_to) {
        filtered = filtered.filter(b => {
          const departureDate = new Date(b.departure_date);
          return departureDate <= new Date(filters.departure_date_to);
        });
      }

      return filtered;
    },

    paginatedBookings: (state) => {
      const start = (state.filters.page - 1) * state.filters.perPage;
      const end = start + state.filters.perPage;
      const bookings = Array.isArray(state.bookings) ? state.bookings : [];
      return bookings.slice(start, end);
    },

    bookingStats: (state) => {
      const bookings = Array.isArray(state.bookings) ? state.bookings : [];
      const total = bookings.length;
      const revenue = bookings.reduce((sum, b) => sum + (b.pricing?.sellingPrice || 0), 0);
      const profit = bookings.reduce((sum, b) => sum + (b.pricing?.profit || 0), 0);
      // ✅ Vue Bug Fix: API يُرجع 'CONFIRMED' (uppercase) — يقبل الحالتين (lowercase + uppercase)
      const active = bookings.filter(b => {
        if (!b) return false;
        const s = String(b.status || '').toLowerCase();
        return ['confirmed', 'ticketed'].includes(s);
      }).length;
      return { total, revenue, profit, active };
    },

    profitStatus: (state) => {
      const profit = state.currentBooking?.pricing?.profit || 0;
      if (profit > 0) return 'positive';
      if (profit < 0) return 'negative';
      return 'break-even';
    }
  },

  actions: {
    addToast(message, type = 'success') {
      const id = Date.now();
      this.toasts.push({ id, message, type });
      setTimeout(() => {
        this.toasts = this.toasts.filter(t => t.id !== id);
      }, 4000);
    },

    async fetchBookings(filters = {}) {
      this.loading.list = true;
      try {
        const response = await axios.get('/api/v1/flight/bookings', { params: filters });
        const rawData = response.data?.data || response.data;
        const items = rawData.items || (Array.isArray(rawData) ? rawData : []);
        // Map API response (snake_case) to frontend format (camelCase with nested objects)
        this.bookings = items.map(b => this.mapBooking(b));
        this.pagination = {
          total: rawData?.pagination?.total || response.data?.total || this.bookings.length,
          currentPage: rawData?.pagination?.current_page || response.data?.current_page || 1,
          lastPage: rawData?.pagination?.last_page || response.data?.last_page || 1,
          perPage: rawData?.pagination?.per_page || response.data?.per_page || 15
        };
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch bookings', error);
        this.errors = { fetch: 'حدث خطأ أثناء تحميل الحجوزات، حاول مرة أخرى.' };
        this.bookings = [];
      } finally {
        this.loading.list = false;
      }
    },

    // Map a single booking from API snake_case to frontend camelCase
    mapBooking(b) {
      const segmentsArr = ensureArray(b.segments);
      const passengersArr = ensureArray(b.passengers);
      const paymentsArr = ensureArray(b.payments);

      const mapped = {
        id: b.id,
        bookingNumber: b.booking_number || b.bookingNumber || '',
        systemType: b.system_type || b.systemType || 'manual',
        systemTypeLabel: b.system_type_label || '',
        systemDisplay: b.system_display || '',
        status: (b.status || 'pending').toLowerCase(),
        createdAt: b.created_at || b.createdAt || null,
        updatedAt: b.updated_at || b.updatedAt || null,
        customer: b.customer ? {
          id: b.customer.id,
          name: b.customer.name || b.customer.full_name || '',
          phone: b.customer.phone || '',
          email: b.customer.email || '',
          balance: parseFloat(b.customer.balance ?? 0),
          type: b.customer.type || 'regular',
        } : null,
        employee: b.employee ? {
          id: b.employee.id,
          name: b.employee.user?.name || '',
        } : null,
        account: b.account ? {
          id: b.account.id,
          name: b.account.name || '',
        } : null,
        segments: segmentsArr.length ? segmentsArr.map(s => ({
          id: s.id,
          airline: s.airline_name || s.airline || '',
          flightNumber: s.flight_number || '',
          from: s.from_airport || s.from || '',
          to: s.to_airport || s.to || '',
          departureDate: s.departure_date ? new Date(s.departure_date).toISOString().split('T')[0] : '',
          departureTime: s.departure_time || '',
          arrivalTime: s.arrival_time || '',
          baggage: s.baggage_allowance || s.baggage || '',
          flightClass: s.flight_class || 'economy',
        })) : [{
          from: b.from_airport || '',
          to: b.to_airport || '',
          airline: b.airline_name || '',
          departureDate: b.departure_date || '',
          departureTime: b.departure_time || '',
          arrivalTime: b.arrival_time || '',
        }],
        passengers: passengersArr.map(p => ({
          id: p.id,
          firstName: p.first_name || '',
          lastName: p.last_name || '',
          name: p.name || `${p.first_name || ''} ${p.last_name || ''}`.trim(),
          type: (p.passenger_type || p.type || 'adult').toLowerCase(),
          passportNumber: p.passport_number || '',
          nationalId: p.national_id || '',
          dateOfBirth: p.date_of_birth || '',
          baggageAllowanceKg: Number(p.baggage_allowance_kg) || 0,
        })),
        payments: paymentsArr.map(p => ({
          id: p.id,
          amount: parseFloat(p.amount || 0),
          paymentMethod: p.payment_method || p.method || '',
          methodLabel: p.method_label || '',
          account: p.account ? { id: p.account.id, name: p.account.name } : null,
          createdAt: p.created_at || '',
        })),
        refund: b.refund ? {
          id: b.refund.id,
          refundAmount: parseFloat(b.refund.refund_amount || 0),
          airlinePenalty: parseFloat(b.refund.airline_penalty || 0),
          officePenalty: parseFloat(b.refund.office_penalty || 0),
          status: b.refund.status || '',
        } : null,
        pricing: {
          purchasePrice: parseFloat(b.purchase_price_egp ?? b.purchase_price ?? b.pricing?.purchasePrice ?? 0),
          sellingPrice: parseFloat(b.selling_price ?? b.pricing?.sellingPrice ?? 0),
          profit: parseFloat(b.profit ?? b.pricing?.profit ?? 0),
          purchaseCurrency: String(
            b.purchase_currency || b.currency || b.pricing?.purchaseCurrency || 'EGP'
          ).toUpperCase(),
          currency: String(b.selling_currency || b.pricing?.currency || 'EGP').toUpperCase(),
          purchasePriceForeign: b.purchase_price_foreign != null ? parseFloat(b.purchase_price_foreign) : null,
          exchangeRate: b.exchange_rate != null ? parseFloat(b.exchange_rate) : null,
        },
        currency: String(b.selling_currency || 'EGP').toUpperCase(),
        purchaseCurrency: String(b.purchase_currency || b.currency || 'EGP').toUpperCase(),
        route: b.route || `${b.from_airport || ''} → ${b.to_airport || ''}`,
        fromAirportCity: b.from_airport_city || b.from_airport || '',
        toAirportCity: b.to_airport_city || b.to_airport || '',
        createdByName: b.created_by_name || '',
        passengersCount: b.passengers_count || passengersArr.length || 0,
        trip_type: b.trip_type || null,
        tripType: b.trip_type || null,
        from_airport: b.from_airport || '',
        to_airport: b.to_airport || '',
        departure_date: b.departure_date || null,
        departureDate: b.departure_date || null,
        return_date: b.return_date || null,
        returnDate: b.return_date || null,
        departure_time: b.departure_time || null,
        departureTime: b.departure_time || null,
        arrival_time: b.arrival_time || null,
        arrivalTime: b.arrival_time || null,
        return_time: b.return_time || null,
        returnTime: b.return_time || null,
        customer_id: b.customer_id ?? null,
        employee_id: b.employee_id ?? null,
        from_airport_id: b.from_airport_id ?? null,
        to_airport_id: b.to_airport_id ?? null,
        account_id: b.account_id ?? null,
        baggage_allowance_kg: (() => {
          const fromBooking = Number(b.baggage_allowance_kg) || 0;
          if (fromBooking > 0) return fromBooking;
          const passengers = passengersArr;
          return passengers.reduce(
            (sum, p) => sum + (Number(p.baggage_allowance_kg ?? p.baggageAllowanceKg) || 0),
            0
          );
        })(),
        baggageAllowanceKg: (() => {
          const fromBooking = Number(b.baggage_allowance_kg) || 0;
          if (fromBooking > 0) return fromBooking;
          const passengers = passengersArr;
          return passengers.reduce(
            (sum, p) => sum + (Number(p.baggage_allowance_kg ?? p.baggageAllowanceKg) || 0),
            0
          );
        })(),
        flight_group_id: b.flight_group_id ?? null,
        purchase_price_foreign: b.purchase_price_foreign != null ? parseFloat(b.purchase_price_foreign) : null,
        exchange_rate: b.exchange_rate != null ? parseFloat(b.exchange_rate) : null,
        purchase_price_egp: b.purchase_price_egp != null ? parseFloat(b.purchase_price_egp) : null,
        flight_system_id: b.flight_system_id ?? null,
        flight_carrier_id: b.flight_carrier_id ?? null,
        purchase_balance_source: b.purchase_balance_source ?? null,
        flightSystem: b.flight_system
          ? {
              id: b.flight_system.id,
              name: b.flight_system.name,
              code: b.flight_system.code,
              type: b.flight_system.type,
              currency: b.flight_system.currency,
              balance: parseFloat(b.flight_system.balance ?? 0),
              creditLimit: parseFloat(b.flight_system.credit_limit ?? 0),
              availableBalance: parseFloat(b.flight_system.available_balance ?? 0),
            }
          : null,
        flightCarrier: b.flight_carrier
          ? {
              id: b.flight_carrier.id,
              name: b.flight_carrier.name,
              currency: b.flight_carrier.currency,
              balance: parseFloat(b.flight_carrier.balance ?? 0),
              availableBalance: parseFloat(b.flight_carrier.available_balance ?? 0),
            }
          : null,
        flightGroup: b.flight_group
          ? {
              id: b.flight_group.id,
              name: b.flight_group.name,
              code: b.flight_group.code,
            }
          : null,
        airlineName: b.airline_name || '',
        tripDetails: b.trip_details || '',
        notes: b.notes || '',
        pnr: b.pnr || '',
        totalPaid: parseFloat(b.total_paid || 0),
        remaining: parseFloat(b.remaining || 0),
        paymentStatus: b.payment_status || 'unpaid',
        paymentStatusLabel: b.payment_status_label || '',
        createdBy: b.created_by_name || '',
      };

      return mapped;
    },

    async fetchBookingById(id) {
      this.loading.list = true;
      try {
        const response = await axios.get(`/api/v1/flight/bookings/${id}`);

        if (!response.data?.success && !response.data?.status) {
          throw new Error('Invalid API response structure');
        }

        const rawData = response.data?.data;

        if (!rawData) {
          throw new Error('No booking data received');
        }

        const mappedBooking = this.mapBooking(rawData);
        this.currentBooking = mappedBooking;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        if (import.meta.env.DEV) {
          console.error('Failed to fetch booking', error);
        }
        const local = this.bookings.find(b => b.id === id);
        if (local) {
          this.currentBooking = { ...local };
        }
      } finally {
        this.loading.list = false;
      }
    },

    async createBooking(payload) {
      this.loading.create = true;
      this.errors = {};
      try {
        // Transform camelCase frontend payload to snake_case for backend
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.post('/api/v1/flight/bookings', apiPayload);
        const newBooking = response.data?.data || response.data;
        const bookingId = newBooking?.id;
        if (bookingId) {
          await this.fetchBookingById(bookingId);
        }
        const mapped = bookingId && this.currentBooking?.id
          ? { ...this.currentBooking }
          : this.mapBooking(newBooking);
        if (!Array.isArray(this.bookings)) this.bookings = [];
        const existingIdx = this.bookings.findIndex((b) => String(b.id) === String(bookingId));
        if (existingIdx >= 0) {
          this.bookings[existingIdx] = mapped;
        } else {
          this.bookings.unshift(mapped);
        }
        return bookingId ? { ...newBooking, id: bookingId } : newBooking;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = error.response?.data?.errors || { message: 'حدث خطأ، حاول مرة أخرى' };
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    /**
     * تسجيل دفعة إضافية على حجز موجود.
     * @param {number|string} bookingId
     * @param {{ amount: number, payment_method: string, account_id: number, notes?: string }} payload
     */
    async addBookingPayment(bookingId, payload) {
      this.errors = {};
      const response = await axios.post(`/api/v1/flight/bookings/${bookingId}/payments`, {
        amount: payload.amount,
        payment_method: payload.payment_method,
        account_id: payload.account_id,
        notes: payload.notes ?? null,
      });
      const raw = response.data?.data;
      if (raw && this.currentBooking && String(this.currentBooking.id) === String(bookingId)) {
        this.currentBooking = this.mapBooking(raw);
      }
      if (Array.isArray(this.bookings)) {
        const idx = this.bookings.findIndex((b) => String(b.id) === String(bookingId));
        if (idx !== -1 && raw) {
          this.bookings[idx] = this.mapBooking(raw);
        }
      }
      return raw;
    },

    async updateBooking(id, payload) {
      this.loading.update = true;
      this.errors = {};
      try {
        const apiPayload = this.transformPayloadForApi(payload);
        const response = await axios.put(`/api/v1/flight/bookings/${id}`, apiPayload);
        const updatedBooking = response.data?.data || response.data;
        if (Array.isArray(this.bookings)) {
          const index = this.bookings.findIndex(b => b.id === id);
          if (index !== -1) this.bookings[index] = this.mapBooking(updatedBooking);
        }
        return updatedBooking;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = error.response?.data?.errors || { message: 'حدث خطأ، حاول مرة أخرى' };
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async confirmBooking(id) {
      this.errors = {};
      const response = await axios.post(`/api/v1/flight/bookings/${id}/confirm`);
      const raw = response.data?.data || response.data;
      if (raw && this.currentBooking && String(this.currentBooking.id) === String(id)) {
        this.currentBooking = this.mapBooking(raw);
      }
      if (Array.isArray(this.bookings)) {
        const idx = this.bookings.findIndex((b) => String(b.id) === String(id));
        if (idx !== -1 && raw) {
          this.bookings[idx] = this.mapBooking(raw);
        }
      }
      return raw;
    },

    async deleteBooking(id) {
      this.loading.delete = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/flight/bookings/${id}`);
        if (Array.isArray(this.bookings)) {
          this.bookings = this.bookings.filter(b => b.id !== id);
        }
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = { delete: 'حدث خطأ أثناء الحذف، حاول مرة أخرى' };
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    /**
     * إلغاء حجز واسترداد المبلغ للعميل (خصم الطيران + عمولة الوكيل).
     * @param {number|string} bookingId
     * @param {{ airline_penalty: number, office_penalty: number, account_id?: number, notes?: string }} payload
     */
    async cancelBooking(bookingId, payload) {
      this.errors = {};
      try {
        const response = await axios.post(`/api/v1/flight/bookings/${bookingId}/cancel`, payload);
        const raw = response.data?.data;
        if (raw && this.currentBooking && String(this.currentBooking.id) === String(bookingId)) {
          this.currentBooking = this.mapBooking(raw);
        }
        if (Array.isArray(this.bookings)) {
          const idx = this.bookings.findIndex((b) => String(b.id) === String(bookingId));
          if (idx !== -1 && raw) {
            this.bookings[idx] = this.mapBooking(raw);
          }
        }
        return raw;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = error.response?.data?.errors || {
          message: error.response?.data?.message || 'فشل تنفيذ الاسترداد',
        };
        throw error;
      }
    },

    async fetchAirlineAccounts(filters = {}) {
      this.loading.airlineAccounts = true;
      this.errors = {};
      try {
        const response = await axios.get('/api/v1/flight/airline-accounts', { params: filters });
        const rawData = response.data?.data || response.data;
        const accounts = rawData.accounts || (Array.isArray(rawData) ? rawData : []);
        this.airlineAccounts = accounts.map(a => ({
          id: a.id,
          name: a.name,
          code: a.code,
          systemType: a.system_type,
          currency: a.currency,
          balance: a.balance,
          creditLimit: a.credit_limit,
          availableBalance: a.available_balance,
          isActive: a.is_active,
          notes: a.notes,
          transactionsCount: a.transactions_count || 0,
          latestTransactions: a.latest_transactions || [],
        }));
      } catch (error) {
        if (isRequestCanceled(error)) return;
        this.errors = { airlineAccounts: 'حدث خطأ أثناء تحميل حسابات شركات الطيران' };
        console.error('Failed to fetch airline accounts', error);
        this.airlineAccounts = [];
      } finally {
        this.loading.airlineAccounts = false;
      }
    },

    async addCreditToAccount(accountId, payload, refreshFilters = {}) {
      this.loading.airlineAccounts = true;
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/flight/airline-accounts/add-credit', {
          airline_account_id: accountId,
          amount: payload.amount,
          description: payload.description,
        });
        await this.fetchAirlineAccounts(refreshFilters);
        this.addToast('تم شحن الحساب بنجاح');
        return response.data?.data || response.data;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        const msg = error.response?.data?.message || 'حدث خطأ أثناء شحن الحساب';
        this.errors = { addCredit: msg };
        throw error;
      } finally {
        this.loading.airlineAccounts = false;
      }
    },

    async createAirlineAccount(payload) {
      this.loading.airlineAccounts = true;
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/flight/airline-accounts', {
          name: payload.name,
          code: payload.code,
          system_type: payload.system_type,
          currency: payload.currency,
          balance: payload.balance ?? 0,
          credit_limit: payload.credit_limit ?? 0,
          is_active: payload.is_active ?? true,
          notes: payload.notes || null,
        });
        this.addToast('تم إنشاء حساب شركة الطيران بنجاح');
        return response.data?.data || response.data;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        const msg =
          error.response?.data?.message ||
          (error.response?.data?.errors && Object.values(error.response.data.errors).flat().join(' ')) ||
          'فشل إنشاء الحساب';
        this.errors = { airlineAccountForm: msg };
        throw error;
      } finally {
        this.loading.airlineAccounts = false;
      }
    },

    async updateAirlineAccount(id, payload) {
      this.loading.airlineAccounts = true;
      this.errors = {};
      try {
        const response = await axios.put(`/api/v1/flight/airline-accounts/${id}`, {
          name: payload.name,
          code: payload.code,
          system_type: payload.system_type,
          currency: payload.currency,
          credit_limit: payload.credit_limit,
          is_active: payload.is_active,
          notes: payload.notes,
        });
        this.addToast('تم تحديث الحساب بنجاح');
        return response.data?.data || response.data;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        const msg =
          error.response?.data?.message ||
          (error.response?.data?.errors && Object.values(error.response.data.errors).flat().join(' ')) ||
          'فشل تحديث الحساب';
        this.errors = { airlineAccountForm: msg };
        throw error;
      } finally {
        this.loading.airlineAccounts = false;
      }
    },

    async deleteAirlineAccount(id) {
      this.loading.airlineAccounts = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/flight/airline-accounts/${id}`);
        this.addToast('تم حذف الحساب بنجاح');
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        const msg = error.response?.data?.message || 'فشل حذف الحساب';
        this.errors = { airlineAccountDelete: msg };
        throw error;
      } finally {
        this.loading.airlineAccounts = false;
      }
    },

    async createCustomer(payload) {
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/customers', payload);
        const newCustomer = response.data?.data || response.data;
        if (!Array.isArray(this.customers)) this.customers = [];
        this.customers.push(newCustomer);
        return newCustomer;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = error.response?.data?.errors || { customer: 'حدث خطأ، حاول مرة أخرى' };
        throw error;
      }
    },

    async updateCustomer(id, payload) {
      this.errors = {};
      try {
        const response = await axios.put(`/api/v1/customers/${id}`, payload);
        const updated = response.data?.data || response.data;
        if (Array.isArray(this.customers)) {
          const idx = this.customers.findIndex((c) => Number(c.id) === Number(id));
          if (idx >= 0) this.customers[idx] = updated;
        }
        return updated;
      } catch (error) {
        if (isRequestCanceled(error)) throw error;
        this.errors = error.response?.data?.errors || { customer: 'حدث خطأ، حاول مرة أخرى' };
        throw error;
      }
    },

    /**
     * Transform frontend camelCase payload to backend snake_case format
     */
    transformPayloadForApi(payload) {
      const fromAirportObj = payload.from_airport || payload.fromAirport;
      const toAirportObj = payload.to_airport || payload.toAirport;
      const purchaseEgp =
        payload.pricing?.purchasePrice ??
        payload.purchasePrice ??
        payload.purchase_price_egp ??
        payload.purchase_price ??
        0;

      const bookingSource =
        payload.booking_source ||
        payload.bookingSource ||
        (payload.flight_group_id || payload.flightGroupId
          ? 'group'
          : payload.flight_system_id || payload.flightSystemId
            ? 'system'
            : 'direct');

      const explicitPurchaseSource = String(
        payload.purchase_balance_source || payload.purchaseBalanceSource || ''
      )
        .trim()
        .toLowerCase();

      let purchaseBalanceSource = 'carrier';
      let bookingChannelType = 'SIGN';

      if (explicitPurchaseSource === 'group' || bookingSource === 'group') {
        bookingChannelType = 'GROUP';
        purchaseBalanceSource = 'group';
      } else if (explicitPurchaseSource === 'system' || bookingSource === 'system') {
        bookingChannelType = 'SYSTEM';
        purchaseBalanceSource = 'system';
      } else if (explicitPurchaseSource === 'carrier' || bookingSource === 'direct') {
        bookingChannelType = 'SIGN';
        purchaseBalanceSource = 'carrier';
      }

      const data = {
        customer_id: payload.customer?.id || payload.customerId || payload.customer_id,
        system_type: payload.systemType || payload.system_type || 'manual',
        airline_name: payload.airlineName || payload.airline_name || null,
        airline_account_id: payload.airlineAccount?.id || payload.airlineAccountId || payload.airline_account_id || null,
        pnr: payload.pnr || null,
        trip_type: payload.trip_type || payload.tripType || null,
        from_airport_id: payload.from_airport_id || fromAirportObj?.id || null,
        to_airport_id: payload.to_airport_id || toAirportObj?.id || null,
        from_airport: fromAirportObj?.iata_code || payload.segments?.[0]?.from || payload.from_airport || null,
        to_airport: toAirportObj?.iata_code || payload.segments?.[0]?.to || payload.to_airport || null,
        departure_date: payload.departure_date || payload.segments?.[0]?.departureDate || payload.departureDate || null,
        return_date: payload.return_date || payload.returnDate || null,
        return_time: payload.return_time || payload.returnTime || null,
        departure_time: payload.segments?.[0]?.departureTime || payload.departureTime || payload.departure_time || null,
        arrival_time: payload.segments?.[0]?.arrivalTime || payload.arrivalTime || payload.arrival_time || null,
        trip_details: payload.tripDetails || payload.trip_details || null,
        purchase_price: purchaseEgp,
        purchase_price_egp: payload.purchase_price_egp ?? purchaseEgp,
        purchase_price_foreign: payload.purchase_price_foreign ?? null,
        exchange_rate: payload.exchange_rate ?? null,
        selling_price: payload.pricing?.sellingPrice ?? payload.sellingPrice ?? payload.selling_price ?? 0,
        currency: payload.pricing?.currency || payload.currency || 'EGP',
        account_id: payload.account_id || payload.accountId || null,
        flight_system_id: payload.flight_system_id || payload.flightSystemId || null,
        flight_carrier_id: payload.flight_carrier_id || payload.flightCarrierId || null,
        flight_group_id: payload.flight_group_id || payload.flightGroupId || null,
        purchase_balance_source: purchaseBalanceSource,
        booking_channel_type: bookingChannelType,
        pnr: payload.pnr || null,
        employee_id: payload.employee_id || payload.employeeId || null,
        baggage_allowance_kg: payload.baggage_allowance_kg ?? payload.baggageAllowanceKg ?? null,
        notes: payload.notes || null,
        initial_payment: payload.initial_payment ?? payload.initialPayment ?? 0,
        payment_method: payload.payment_method || payload.paymentMethod || null,
      };

      // Map passengers (camelCase → snake_case)
      if (payload.passengers && payload.passengers.length > 0) {
        data.passengers = payload.passengers.map(p => ({
          first_name: p.first_name || p.firstName || '',
          last_name: p.last_name || p.lastName || '',
          name: p.name || `${p.first_name || p.firstName || ''} ${p.last_name || p.lastName || ''}`.trim(),
          type: p.type || p.passengerType || p.passenger_type || 'adult',
          passenger_type: p.passengerType || p.passenger_type || p.type || 'adult',
          national_id: (p.national_id || p.nationalId || '').toString().trim() || null,
          passport_number: p.passportNumber || p.passport_number || null,
          nationality: p.nationality || null,
          date_of_birth: p.dateOfBirth || p.date_of_birth || null,
          baggage_allowance_kg: Number(p.baggage_allowance_kg || p.baggageAllowanceKg || 0),
        }));
        data.passengers_count = data.passengers.length;
      }

      // Map segments (camelCase → snake_case)
      if (payload.segments && payload.segments.length > 0) {
        data.segments = payload.segments.map(s => ({
          airline_name: s.airline || s.airlineName || s.airline_name || '',
          flight_number: s.flightNumber || s.flight_number || null,
          from_airport: s.from || s.fromAirport || s.from_airport || null,
          to_airport: s.to || s.toAirport || s.to_airport || null,
          departure_date: s.departureDate || s.departure_date || null,
          departure_time: s.departureTime || s.departure_time || null,
          arrival_time: s.arrivalTime || s.arrival_time || null,
          baggage_allowance: s.baggage || s.baggageAllowance || s.baggage_allowance || null,
          flight_class: s.flightClass || s.flight_class || 'economy',
        }));
      }

      const initPay = parseFloat(payload.initial_payment ?? payload.initialPayment ?? 0);
      if (initPay > 0) {
        data.payment = {
          amount: initPay,
          payment_method: (payload.payment_method || payload.paymentMethod || 'cash').toLowerCase().replace(/\s+/g, '_'),
          account_id: payload.account_id || payload.accountId,
          notes: payload.payment_notes || null,
        };
      }

      return data;
    },

    async generateBookingNumber() {
      try {
        const response = await axios.get('/api/v1/aviation/next-number');
        return response.data.number;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to generate booking number, using client-side fallback', error);
        const year = new Date().getFullYear();
        const sequence = Math.floor(1000 + Math.random() * 9000);
        return `BK-${year}-${sequence}`;
      }
    },

    async fetchSystemTypes(params = {}) {
      this.loading.systemTypes = true;
      try {
        const response = await axios.get('/api/v1/flight/system-types', { params });
        this.systemTypes = response.data.data || response.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch system types', error);
        this.errors.systemTypes = 'فشل تحميل أنظمة الحجز';
      } finally {
        this.loading.systemTypes = false;
      }
    },

    async fetchCarriers(params = {}) {
      this.loading.carriers = true;
      try {
        const response = await axios.get('/api/v1/flight/carriers', { params });
        this.carriers = response.data.data || response.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch carriers', error);
        this.errors.carriers = 'فشل تحميل شركات الطيران';
      } finally {
        this.loading.carriers = false;
      }
    },

    async fetchCustomers(params = {}) {
      this.loading.customers = true;
      try {
        const response = await axios.get('/api/v1/customers', { params });
        const d = response.data?.data;
        this.customers = d?.items || (Array.isArray(d) ? d : []) || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch customers', error);
        this.errors.customers = 'فشل تحميل العملاء';
      } finally {
        this.loading.customers = false;
      }
    },

    setCurrentBooking(booking) {
      this.currentBooking = booking;
    },

    // ========== NEW API METHODS FOR PHASE 3 ==========

    async fetchTripTypes() {
      this.loading.tripTypes = true;
      try {
        const response = await axios.get('/api/v1/settings/trip-types');
        this.tripTypes = response.data?.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch trip types', error);
        this.errors.tripTypes = 'فشل تحميل أنواع الرحلات';
        this.tripTypes = [];
      } finally {
        this.loading.tripTypes = false;
      }
    },

    async fetchCurrencies() {
      this.loading.currencies = true;
      try {
        const response = await axios.get('/api/v1/settings/currencies');
        this.currencies = response.data?.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch currencies', error);
        this.errors.currencies = 'فشل تحميل العملات';
        this.currencies = [];
      } finally {
        this.loading.currencies = false;
      }
    },

    async fetchFlightBookingReference() {
      this.loading.flightReference = true;
      try {
        const response = await axios.get('/api/v1/settings/flight-booking-reference');
        const d = response.data?.data || {};
        this.bookingStatuses = d.booking_statuses || [];
        this.paymentFilterStatuses = d.payment_statuses || [];
        this.systemTypeEnumOptions = d.system_types || [];
        this.passengerTypes = d.passenger_types || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch flight booking reference', error);
        this.bookingStatuses = [];
        this.paymentFilterStatuses = [];
        this.systemTypeEnumOptions = [];
        this.passengerTypes = [];
      } finally {
        this.loading.flightReference = false;
      }
    },

    async fetchSystems(params = {}) {
      this.loading.systems = true;
      try {
        const response = await axios.get('/api/v1/flight/systems', { params });
        this.systems = response.data?.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch flight systems', error);
        this.errors.systems = 'فشل تحميل أنظمة الطيران';
      } finally {
        this.loading.systems = false;
      }
    },

    async fetchFlightTreasuryOverview() {
      this.loading.treasuryOverview = true;
      try {
        const response = await axios.get('/api/v1/flight/treasury/overview');
        this.treasuryOverview = response.data?.data ?? null;
        return this.treasuryOverview;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch flight treasury overview', error);
        this.treasuryOverview = null;
        return null;
      } finally {
        this.loading.treasuryOverview = false;
      }
    },

    async fetchFlightDashboard() {
      this.loading.treasuryOverview = true; // reusing loading state
      try {
        const response = await axios.get('/api/v1/flight/dashboard');
        return response.data?.data ?? null;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch flight dashboard', error);
        return null;
      } finally {
        this.loading.treasuryOverview = false;
      }
    },

    /**
     * شحن رصيد نظام حجز من حساب تحصيل (محفظة/بنك/خزينة).
     *
     * @param {number|string} systemId
     * @param {{ from_account_id: number, amount: number|string, notes?: string|null }} payload
     */
    async rechargeFlightSystem(systemId, payload) {
      const response = await axios.post(`/api/v1/flight/treasury/systems/${systemId}/recharge`, {
        from_account_id: payload.from_account_id,
        amount: payload.amount,
        notes: payload.notes || null,
      });
      return response.data?.data ?? null;
    },

    /**
     * شحن رصيد ناقل طيران من حساب تحصيل (محفظة/بنك/خزينة).
     *
     * @param {number|string} carrierId
     * @param {{ from_account_id: number, amount: number|string, notes?: string|null }} payload
     */
    async rechargeCarrier(carrierId, payload) {
      const response = await axios.post(`/api/v1/flight/carriers/${carrierId}/recharge`, {
        from_account_id: payload.from_account_id,
        amount: payload.amount,
        notes: payload.notes || null,
      });
      return response.data?.data ?? null;
    },

    /**
     * @param {number|string} systemId
     * @param {Record<string, unknown>} [params]
     */
    async fetchFlightSystemTransactions(systemId, params = {}) {
      const response = await axios.get(`/api/v1/flight/treasury/systems/${systemId}/transactions`, { params });
      return response.data?.data ?? null;
    },

    /**
     * @param {number|string} carrierId
     * @param {Record<string, unknown>} [params]
     */
    async fetchFlightCarrierTransactions(carrierId, params = {}) {
      const response = await axios.get(`/api/v1/flight/treasury/carriers/${carrierId}/transactions`, { params });
      return response.data?.data ?? null;
    },

    /**
     * @param {number|string} accountId
     * @param {Record<string, unknown>} [params]
     */
    async fetchAccountFlightTransactions(accountId, params = {}) {
      const response = await axios.get(`/api/v1/flight/treasury/accounts/${accountId}/flight-transactions`, { params });
      return response.data?.data ?? null;
    },

    async fetchGroupsByCarrier(carrierId) {
      this.loading.groups = true;
      try {
        const response = await axios.get(`/api/v1/flight/carriers/${carrierId}/groups`);
        this.groups = response.data?.data || [];
        return this.groups;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch groups', error);
        this.errors.groups = 'فشل تحميل المجموعات';
        this.groups = [];
        return [];
      } finally {
        this.loading.groups = false;
      }
    },

    async fetchGroups() {
      this.loading.groups = true;
      try {
        const response = await axios.get('/api/v1/flight/groups');
        this.groups = response.data?.data || [];
        return this.groups;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch groups', error);
        this.errors.groups = 'فشل تحميل المجموعات';
        this.groups = [];
        return [];
      } finally {
        this.loading.groups = false;
      }
    },

    async fetchAirports(params = {}) {
      this.loading.airports = true;
      try {
        const response = await axios.get('/api/v1/flight/airports', { params });
        this.airports = response.data?.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch airports', error);
        this.errors.airports = 'فشل تحميل المطارات';
      } finally {
        this.loading.airports = false;
      }
    },

    async searchAirports(query, countryCode = null) {
      try {
        const params = { q: query };
        if (countryCode) params.country_code = countryCode;
        const response = await axios.get('/api/v1/flight/airports/search', { params });
        return response.data?.data || [];
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to search airports', error);
        return [];
      }
    },

    async fetchPopularAirports(limit = 10) {
      try {
        const response = await axios.get('/api/v1/flight/airports/popular', { params: { limit } });
        this.popularAirports = response.data?.data || [];
        return this.popularAirports;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch popular airports', error);
        return [];
      }
    },

    async fetchCarriersBySystem(systemId) {
      this.loading.carriers = true;
      try {
        const response = await axios.get('/api/v1/flight/carriers', {
          params: { system_id: systemId }
        });
        this.carriers = response.data?.data || [];
        return this.carriers;
      } catch (error) {
        if (isRequestCanceled(error)) return;
        console.error('Failed to fetch carriers by system', error);
        this.errors.carriers = 'فشل تحميل شركات الطيران';
        this.carriers = [];
        return [];
      } finally {
        this.loading.carriers = false;
      }
    },

    getTripTypeLabel(value) {
      const type = this.tripTypes.find(t => t.value === value);
      return type?.label || value;
    },

    getAirportName(iataCode) {
      const airport = this.airports.find(a => a.iata_code === iataCode);
      return airport ? airport.full_name : iataCode;
    },

    getCarrierName(id) {
      const carrier = this.carriers.find(c => c.id === id);
      return carrier?.name || '';
    },

    getSystemName(id) {
      const system = this.systems.find(s => s.id === id);
      return system?.name || '';
    }
  }
});
