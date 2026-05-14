import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('auth_token') || null,
    loading: {
      login: false,
      register: false,
      logout: false,
      me: false,
    },
    errors: {},
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    userName: (state) => state.user?.name || 'مستخدم',
    userRole: (state) => state.user?.role || 'employee',
    userInitial: (state) => {
      const name = state.user?.name || '';
      return name.charAt(0) || 'م';
    },
    isAdmin: (state) => state.user?.role === 'admin',
  },

  actions: {
    /**
     * Set the auth token in localStorage and axios defaults
     */
    setToken(token) {
      this.token = token;
      localStorage.setItem('auth_token', token);
      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    },

    /**
     * Clear auth state
     */
    clearAuth() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('auth_token');
      delete axios.defaults.headers.common['Authorization'];
    },

    /**
     * Login with email and password
     */
    async login(credentials) {
      this.loading.login = true;
      this.errors = {};

      try {
        const response = await axios.post('/api/v1/auth/login', credentials);

        if (response.data.success) {
          const { token, user } = response.data.data;
          this.setToken(token);
          this.user = user;
          return { success: true };
        } else {
          this.errors = { message: response.data.message || 'فشل تسجيل الدخول' };
          return { success: false, message: response.data.message };
        }
      } catch (error) {
        const data = error.response?.data;
        const fieldErrors =
          data?.errors && typeof data.errors === 'object' && !Array.isArray(data.errors) ? data.errors : {};
        const message =
          data?.message ||
          (fieldErrors.email && (Array.isArray(fieldErrors.email) ? fieldErrors.email[0] : fieldErrors.email)) ||
          'حدث خطأ أثناء تسجيل الدخول';
        this.errors = Object.keys(fieldErrors).length ? { ...fieldErrors, message } : { message };
        return { success: false, message };
      } finally {
        this.loading.login = false;
      }
    },

    /**
     * Register a new account
     */
    async register(data) {
      this.loading.register = true;
      this.errors = {};

      try {
        const response = await axios.post('/api/v1/auth/register', data);

        if (response.data.success) {
          const { token, user } = response.data.data;
          this.setToken(token);
          this.user = user;
          return { success: true };
        } else {
          this.errors = { message: response.data.message || 'فشل إنشاء الحساب' };
          return { success: false, message: response.data.message };
        }
      } catch (error) {
        const data = error.response?.data;
        const fieldErrors =
          data?.errors && typeof data.errors === 'object' && !Array.isArray(data.errors) ? data.errors : {};
        const message =
          data?.message ||
          (fieldErrors.email && (Array.isArray(fieldErrors.email) ? fieldErrors.email[0] : fieldErrors.email)) ||
          'حدث خطأ أثناء إنشاء الحساب';
        this.errors = Object.keys(fieldErrors).length ? { ...fieldErrors, message } : { message };
        return { success: false, message };
      } finally {
        this.loading.register = false;
      }
    },

    /**
     * Logout - revoke token
     */
    async logout() {
      this.loading.logout = true;

      try {
        await axios.post('/api/v1/auth/logout');
      } catch (error) {
        console.error('Logout API error (proceeding with local cleanup):', error);
      } finally {
        this.clearAuth();
        this.loading.logout = false;
      }
    },

    /**
     * Fetch current user info
     */
    async fetchMe() {
      if (!this.token) return;

      this.loading.me = true;

      try {
        // Ensure token is set in headers
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;

        const response = await axios.get('/api/v1/auth/me');

        if (response.data.success) {
          this.user = response.data.data;
        }
      } catch (error) {
        if (error.response?.status === 401) {
          this.clearAuth();
        }
        console.error('Failed to fetch user info:', error);
      } finally {
        this.loading.me = false;
      }
    },

    /**
     * Initialize auth state on app load
     */
    async initAuth() {
      if (this.token) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        await this.fetchMe();
      }
    },
  },
});
