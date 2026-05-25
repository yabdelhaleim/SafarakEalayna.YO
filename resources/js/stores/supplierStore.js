import { defineStore } from 'pinia';
import axios from 'axios';

export const useSupplierStore = defineStore('supplier', {
  state: () => ({
    suppliers: [],
    currentSupplier: null,

    // Loading States
    loading: {
      list: false,
      show: false,
      create: false,
      update: false,
      delete: false,
    },

    // Errors
    errors: {},

    // Pagination
    pagination: {
      total: 0,
      current_page: 1,
      last_page: 1,
      per_page: 15,
    },

    // Filters
    filters: {
      search: '',
      type: '',
      is_active: '',
      page: 1,
      per_page: 15,
    },
  }),

  getters: {
    activeSuppliers: (state) => Array.isArray(state.suppliers) ? state.suppliers.filter(s => s.is_active) : [],
    totalDebt: (state) => Array.isArray(state.suppliers) ? state.suppliers.reduce((sum, s) => sum + parseFloat(s.current_debt || 0), 0) : 0,
    suppliersWithDebt: (state) => Array.isArray(state.suppliers) ? state.suppliers.filter(s => parseFloat(s.current_debt || 0) > 0) : [],
    suppliersByType: (state) => (type) => Array.isArray(state.suppliers) ? state.suppliers.filter(s => s.type === type) : [],

    typeLabels: () => ({
      airline: 'شركة طيران',
      bus_company: 'شركة باصات',
      hotel: 'فندق',
      visa_provider: 'مزود تأشيرات',
      service_provider: 'مزود خدمات',
      other: 'أخرى',
    }),

    paymentTermLabels: () => ({
      cash: 'نقدي',
      credit_30: 'آجل 30 يوم',
      credit_60: 'آجل 60 يوم',
      credit_90: 'آجل 90 يوم',
    }),
  },

  actions: {
    /**
     * Fetch all suppliers with filters
     */
    async fetchSuppliers(params = {}) {
      if (this.fetchSuppliersController) {
        this.fetchSuppliersController.abort();
      }
      const controller = new AbortController();
      this.fetchSuppliersController = controller;

      this.loading.list = true;
      this.errors = {};
      this.suppliers = []; // Reset before fetching

      try {
        const response = await axios.get('/api/v1/suppliers', {
          params: { ...this.filters, ...params },
          signal: controller.signal
        });

        const responseData = response.data?.data || response.data;

        // Handle standardized items & pagination response
        if (responseData.items && Array.isArray(responseData.items)) {
          this.suppliers = responseData.items;
          const pg = responseData.pagination || {};
          this.pagination = {
            total: pg.total || 0,
            current_page: pg.current_page || 1,
            last_page: pg.last_page || 1,
            per_page: pg.per_page || 15,
          };
        }
        // Handle legacy/fallback paginated response
        else if (responseData.data && Array.isArray(responseData.data)) {
          this.suppliers = responseData.data;
          this.pagination = {
            total: responseData.total || 0,
            current_page: responseData.current_page || 1,
            last_page: responseData.last_page || 1,
            per_page: responseData.per_page || 15,
          };
        } else if (Array.isArray(responseData)) {
          this.suppliers = responseData;
          this.pagination.total = responseData.length;
        } else {
          this.suppliers = [];
        }
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch suppliers:', error);
        if (error.response?.status === 403) {
          this.errors = { fetch: 'ليس لديك صلاحية لعرض الموردين' };
        } else {
          this.errors = {
            fetch: error.response?.data?.message || 'فشل تحميل الموردين',
          };
        }
        this.suppliers = [];
        this.pagination = {
          total: 0,
          current_page: 1,
          last_page: 1,
          per_page: this.pagination?.per_page || 15,
        };
      } finally {
        if (this.fetchSuppliersController === controller) {
          this.loading.list = false;
        }
      }
    },

    /**
     * Fetch single supplier
     */
    async fetchSupplier(id) {
      if (this.fetchSupplierController) {
        this.fetchSupplierController.abort();
      }
      const controller = new AbortController();
      this.fetchSupplierController = controller;

      this.loading.show = true;
      this.errors = {};
      this.currentSupplier = null; // Reset before fetching

      try {
        const response = await axios.get(`/api/v1/suppliers/${id}`, {
          signal: controller.signal
        });
        this.currentSupplier = response.data?.data || response.data;
        return this.currentSupplier;
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch supplier:', error);
        this.errors = {
          fetch: error.response?.data?.message || 'فشل تحميل بيانات المورد',
        };
        throw error;
      } finally {
        if (this.fetchSupplierController === controller) {
          this.loading.show = false;
        }
      }
    },

    /**
     * Create new supplier
     */
    async createSupplier(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};

      try {
        const response = await axios.post('/api/v1/suppliers', payload);
        const supplier = response.data?.data || response.data;
        this.suppliers.unshift(supplier);
        this.addToast('تم إضافة المورد بنجاح', 'success');
        return supplier;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: error.response?.data?.message || 'فشل إنشاء المورد',
        };
        this.addToast('فشل إنشاء المورد', 'error');
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    /**
     * Update supplier
     */
    async updateSupplier(id, payload) {
      if (this.loading.update) return;
      this.loading.update = true;
      this.errors = {};

      try {
        const response = await axios.put(`/api/v1/suppliers/${id}`, payload);
        const supplier = response.data?.data || response.data;
        const index = this.suppliers.findIndex(s => s.id === id);
        if (index !== -1) {
          this.suppliers[index] = supplier;
        }
        this.addToast('تم تحديث بيانات المورد بنجاح', 'success');
        return supplier;
      } catch (error) {
        this.errors = error.response?.data?.errors || {
          message: error.response?.data?.message || 'فشل تحديث المورد',
        };
        this.addToast('فشل تحديث المورد', 'error');
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    /**
     * Delete supplier
     */
    async deleteSupplier(id) {
      if (this.loading.delete) return;
      this.loading.delete = true;
      this.errors = {};

      try {
        await axios.delete(`/api/v1/suppliers/${id}`);
        this.suppliers = this.suppliers.filter(s => s.id !== id);
        this.addToast('تم حذف المورد بنجاح', 'success');
      } catch (error) {
        this.errors = {
          delete: error.response?.data?.message || 'فشل حذف المورد',
        };
        this.addToast('فشل حذف المورد', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    /**
     * Update filters and refetch
     */
    setFilters(newFilters) {
      this.filters = { ...this.filters, ...newFilters, page: 1 };
      this.fetchSuppliers();
    },

    /**
     * Go to page
     */
    goToPage(page) {
      this.filters.page = page;
      this.fetchSuppliers();
    },

    // Add toast notification
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
    },

    reset() {
      this.suppliers = [];
      this.currentSupplier = null;
      this.loading = {
        list: false,
        show: false,
        create: false,
        update: false,
        delete: false,
      };
      this.errors = {};
      this.pagination = {
        total: 0,
        current_page: 1,
        last_page: 1,
        per_page: 15,
      };
      this.filters = {
        search: '',
        type: '',
        is_active: '',
        page: 1,
        per_page: 15,
      };
    },
  },
});
