import axios from 'axios';
window.axios = axios;

// Configure defaults on the axios module itself (same instance used by `import axios from 'axios'` in stores)
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.withCredentials = false; // Using Bearer tokens, not cookies
axios.defaults.baseURL = import.meta.env?.VITE_API_URL || '/';

// Get CSRF token from meta tag
let csrfToken = document.head.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken.content;
}

// Add Authorization token from localStorage
const authToken = localStorage.getItem('auth_token');
if (authToken) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${authToken}`;
}

// Global Response Interceptor
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            // Unauthorized - clear token and redirect to login
            localStorage.removeItem('auth_token');
            delete axios.defaults.headers.common['Authorization'];
            if (window.location.pathname !== '/login' && window.location.pathname !== '/register') {
                window.location.href = '/login';
            }
        } else if (error.response?.status === 500) {
            // Server Error
            if (window.addToast) {
                window.addToast('حدث خطأ في الخادم، يرجى المحاولة لاحقاً', 'error');
            } else {
                console.error('حدث خطأ في الخادم، يرجى المحاولة لاحقاً');
            }
        }
        return Promise.reject(error);
    }
);
