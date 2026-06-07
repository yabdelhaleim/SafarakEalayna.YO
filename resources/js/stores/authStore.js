import { defineStore } from 'pinia';
import axios from 'axios';

let tokenRefreshTimer = null;

function clearTokenRefreshTimer() {
  if (tokenRefreshTimer) {
    clearInterval(tokenRefreshTimer);
    tokenRefreshTimer = null;
  }
}

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
    setToken(token, expiresInMinutes = null) {
      this.token = token;
      localStorage.setItem('auth_token', token);
      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      if (expiresInMinutes != null && expiresInMinutes > 0) {
        localStorage.setItem('auth_token_expires_minutes', String(expiresInMinutes));
      }
    },

    /**
     * Clear auth state
     */
    clearAuth() {
      clearTokenRefreshTimer();
      this.token = null;
      this.user = null;
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_token_expires_minutes');
      delete axios.defaults.headers.common['Authorization'];
    },

    scheduleTokenRefresh(expiresInMinutes) {
      clearTokenRefreshTimer();

      const minutes = Number(expiresInMinutes);
      if (!minutes || minutes <= 0) {
        return;
      }

      const intervalMs = Math.max(Math.floor(minutes * 0.8 * 60 * 1000), 60_000);
      tokenRefreshTimer = setInterval(() => {
        this.refreshToken().catch(() => {});
      }, intervalMs);
    },

    async refreshToken() {
      if (!this.token) {
        return { success: false };
      }

      try {
        const response = await axios.post('/api/v1/auth/refresh');
        const result = response.data;

        if (result.success || result.status) {
          const { token, user, expires_in_minutes: expiresInMinutes } = response.data.data;
          this.setToken(token, expiresInMinutes);
          if (user) {
            this.user = user;
          }
          this.scheduleTokenRefresh(expiresInMinutes);
          return { success: true };
        }
      } catch (error) {
        console.error('Token refresh failed:', error);
      }

      return { success: false };
    },

    /**
     * Login with email and password
     */
    async login(credentials) {
      this.loading.login = true;
      this.errors = {};

      try {
        const response = await axios.post('/api/v1/auth/login', credentials);

        const result = response.data;
        if (result.success || result.status) {
          const { token, user, expires_in_minutes: expiresInMinutes } = response.data.data;
          this.setToken(token, expiresInMinutes);
          this.user = user;
          this.scheduleTokenRefresh(expiresInMinutes);
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

        const result = response.data;
        if (result.success || result.status) {
          const { token, user, expires_in_minutes: expiresInMinutes } = response.data.data;
          this.setToken(token, expiresInMinutes);
          this.user = user;
          this.scheduleTokenRefresh(expiresInMinutes);
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
    async fetchMe(force = false) {
      if (!this.token) return;
      if (this.user && !force) return;
      if (this.loading.me) return;

      this.loading.me = true;

      try {
        // Ensure token is set in headers
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;

        const response = await axios.get('/api/v1/auth/me');

        const result = response.data;
        if (result.success || result.status) {
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
        if (!this.user) {
          await this.fetchMe();
        }

        const storedExpiry = parseInt(localStorage.getItem('auth_token_expires_minutes') || '', 10);
        if (storedExpiry > 0) {
          this.scheduleTokenRefresh(storedExpiry);
        }
      }
    },
  },
});
