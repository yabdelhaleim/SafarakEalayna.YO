import { defineStore } from 'pinia';
import axios from 'axios';
import {
  getStoredToken,
  setStoredToken,
  clearStoredToken,
  getStoredExpiryMinutes,
  isValidToken,
} from '@/utils/authStorage';

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
    token: getStoredToken(),
    loading: {
      login: false,
      register: false,
      logout: false,
      me: false,
    },
    errors: {},
  }),

  getters: {
    isAuthenticated: (state) => isValidToken(state.token),
    userName: (state) => state.user?.name || 'مستخدم',
    userRole: (state) => state.user?.role || 'employee',
    userInitial: (state) => {
      const name = state.user?.name || '';
      return name.charAt(0) || 'م';
    },
    // Treat 'admin' and 'owner' as privileged roles (matches the
    // `authStore.isAdmin || authStore.user?.role === 'owner'` workaround
    // already used across the app — keeps one source of truth).
    isAdmin: (state) => ['admin', 'owner'].includes(state.user?.role),
  },

  actions: {
    /**
     * Persist the auth token securely using unified authStorage utility.
     */
    setToken(token, expiresInMinutes = null) {
      if (!isValidToken(token)) {
        return false;
      }
      this.token = token.trim();
      setStoredToken(this.token, expiresInMinutes);
      axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
      return true;
    },

    /**
     * Clear all auth state — called on logout or 401.
     */
    clearAuth() {
      clearTokenRefreshTimer();
      this.token = null;
      this.user = null;
      clearStoredToken();
      delete axios.defaults.headers.common['Authorization'];
    },

    scheduleTokenRefresh(expiresInMinutes) {
      clearTokenRefreshTimer();

      const minutes = Number(expiresInMinutes);
      if (!minutes || minutes <= 0) {
        return;
      }

      // Refresh at 80 % of lifetime, minimum every 60 seconds
      const intervalMs = Math.max(Math.floor(minutes * 0.8 * 60 * 1000), 60_000);
      tokenRefreshTimer = setInterval(() => {
        this.refreshToken().catch(() => {});
      }, intervalMs);
    },

    async refreshToken() {
      if (!isValidToken(this.token)) {
        return { success: false };
      }

      try {
        const response = await axios.post('/api/v1/auth/refresh');
        const result = response.data;

        if (result.success || result.status) {
          const { token, user, expires_in_minutes: expiresInMinutes } = response.data.data;
          if (isValidToken(token)) {
            this.setToken(token, expiresInMinutes);
            if (user) {
              this.user = user;
            }
            this.scheduleTokenRefresh(expiresInMinutes);
            return { success: true };
          }
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
          if (isValidToken(token)) {
            this.setToken(token, expiresInMinutes);
            this.user = user;
            this.scheduleTokenRefresh(expiresInMinutes);
            return { success: true };
          }
          this.errors = { message: 'استجابة تسجيل الدخول غير صالحة' };
          return { success: false, message: 'استجابة تسجيل الدخول غير صالحة' };
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
          // SECURITY HARDENING: In registration, new accounts default to inactive (is_active: false)
          // and no token is returned. Only set token if a valid token is explicitly provided.
          const tokenData = response.data?.data;
          if (tokenData?.token && isValidToken(tokenData.token)) {
            const { token, user, expires_in_minutes: expiresInMinutes } = tokenData;
            this.setToken(token, expiresInMinutes);
            this.user = user;
            this.scheduleTokenRefresh(expiresInMinutes);
          } else if (tokenData?.user) {
            this.user = tokenData.user;
          }
          return { success: true, message: response.data?.message };
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
     * Logout — revoke token on server + clear local state
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
     * Fetch current user info from the server
     */
    async fetchMe(force = false) {
      const activeToken = this.token || getStoredToken();
      if (!isValidToken(activeToken)) return;
      if (this.user && !force) return;
      if (this.loading.me) return;

      this.loading.me = true;

      try {
        // Ensure token is set in headers
        axios.defaults.headers.common['Authorization'] = `Bearer ${activeToken}`;

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
      const stored = getStoredToken();
      if (isValidToken(stored)) {
        this.token = stored;
        axios.defaults.headers.common['Authorization'] = `Bearer ${stored}`;
        if (!this.user) {
          await this.fetchMe();
        }

        const storedExpiry = getStoredExpiryMinutes();
        if (storedExpiry && storedExpiry > 0) {
          this.scheduleTokenRefresh(storedExpiry);
        }
      } else {
        this.clearAuth();
      }
    },
  },
});
