import { defineStore } from 'pinia';
import axios from 'axios';

export const useCustomerStore = defineStore('customer', {
  state: () => ({
    customers: [],
    currentCustomer: null,
    loading: {
      list: false,
      create: false,
      update: false,
      delete: false
    },
    errors: {},
    toasts: [],
    filters: {
      search: '',
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
    filteredCustomers: (state) => {
      let filtered = Array.isArray(state.customers) ? [...state.customers] : [];
      if (state.filters.search) {
        const query = state.filters.search.toLowerCase();
        filtered = filtered.filter(c =>
          c.full_name?.toLowerCase().includes(query) ||
          c.name?.toLowerCase().includes(query) ||
          c.phone?.toLowerCase().includes(query)
        );
      }
      return filtered;
    }
  },

  actions: {
    addToast(message, type = 'success') {
      if (window.addToast) {
        window.addToast(message, type);
      }
      const id = Date.now();
      this.toasts.push({ id, message, type });
      setTimeout(() => {
        this.toasts = this.toasts.filter(t => t.id !== id);
      }, 4000);
    },

    async fetchCustomers(filters = {}) {
      if (this.fetchCustomersController) {
        this.fetchCustomersController.abort();
      }
      const controller = new AbortController();
      this.fetchCustomersController = controller;

      this.loading.list = true;
      this.customers = []; // Reset list before fetching
      try {
        const response = await axios.get('/api/v1/customers', { 
          params: filters,
          signal: controller.signal
        });
        const rawData = response.data?.data || response.data;
        const items = rawData.items || (Array.isArray(rawData) ? rawData : []);
        this.customers = items.map(c => ({
          ...c,
          name: c.name || c.full_name
        }));
        this.pagination = {
          total: response.data?.pagination?.total || response.data?.total || this.customers.length,
          currentPage: response.data?.pagination?.current_page || response.data?.current_page || 1,
          lastPage: response.data?.pagination?.last_page || response.data?.last_page || 1,
          perPage: response.data?.pagination?.per_page || response.data?.per_page || 15
        };
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch customers', error);
        this.errors = { fetch: 'حدث خطأ أثناء تحميل العملاء' };
        this.customers = [];
      } finally {
        if (this.fetchCustomersController === controller) {
          this.loading.list = false;
        }
      }
    },

    async fetchCustomerById(id) {
      if (this.fetchCustomerByIdController) {
        this.fetchCustomerByIdController.abort();
      }
      const controller = new AbortController();
      this.fetchCustomerByIdController = controller;

      this.loading.list = true;
      this.currentCustomer = null; // Reset before fetching
      try {
        const response = await axios.get(`/api/v1/customers/${id}`, {
          signal: controller.signal
        });
        this.currentCustomer = {
          ...response.data.data,
          name: response.data.data.name || response.data.data.full_name
        };
      } catch (error) {
        if (axios.isCancel(error)) {
          return;
        }
        console.error('Failed to fetch customer', error);
      } finally {
        if (this.fetchCustomerByIdController === controller) {
          this.loading.list = false;
        }
      }
    },

    async createCustomer(payload) {
      if (this.loading.create) return;
      this.loading.create = true;
      this.errors = {};
      try {
        const response = await axios.post('/api/v1/customers', payload);
        const newCustomer = {
          ...response.data.data,
          name: response.data.data.name || response.data.data.full_name
        };
        this.customers.unshift(newCustomer);
        this.addToast('تم إنشاء العميل بنجاح', 'success');
        return newCustomer;
      } catch (error) {
        this.errors = error.response?.data?.errors || { message: 'حدث خطأ' };
        this.addToast('حدث خطأ أثناء إنشاء العميل', 'error');
        throw error;
      } finally {
        this.loading.create = false;
      }
    },

    async updateCustomer(id, payload) {
      if (this.loading.update) return;
      this.loading.update = true;
      this.errors = {};
      try {
        const response = await axios.put(`/api/v1/customers/${id}`, payload);
        const updatedCustomer = {
          ...response.data.data,
          name: response.data.data.name || response.data.data.full_name
        };
        const index = this.customers.findIndex(c => c.id === id);
        if (index !== -1) this.customers[index] = updatedCustomer;
        this.addToast('تم تحديث العميل بنجاح', 'success');
        return updatedCustomer;
      } catch (error) {
        this.errors = error.response?.data?.errors || { message: 'حدث خطأ' };
        this.addToast('حدث خطأ أثناء تحديث العميل', 'error');
        throw error;
      } finally {
        this.loading.update = false;
      }
    },

    async deleteCustomer(id) {
      if (this.loading.delete) return;
      this.loading.delete = true;
      this.errors = {};
      try {
        await axios.delete(`/api/v1/customers/${id}`);
        this.customers = this.customers.filter(c => c.id !== id);
        this.addToast('تم حذف العميل بنجاح', 'success');
      } catch (error) {
        this.errors = { delete: 'حدث خطأ أثناء الحذف' };
        this.addToast('حدث خطأ أثناء حذف العميل', 'error');
        throw error;
      } finally {
        this.loading.delete = false;
      }
    },

    reset() {
      this.customers = [];
      this.currentCustomer = null;
      this.loading = {
        list: false,
        create: false,
        update: false,
        delete: false
      };
      this.errors = {};
      this.toasts = [];
      this.filters = {
        search: '',
        page: 1,
        perPage: 15
      };
      this.pagination = {
        total: 0,
        currentPage: 1,
        lastPage: 1,
        perPage: 15
      };
    }
  }
});
