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
        const status = error.response?.status;
        
        if (status === 401) {
            // Unauthorized - clear token
            localStorage.removeItem('auth_token');
            delete axios.defaults.headers.common['Authorization'];
            // We let the Vue Router handle the redirection to avoid hard reload loops
        } else if (status === 403) {
            // Forbidden
            if (window.addToast) {
                window.addToast('ليس لديك الصلاحية للقيام بهذا الإجراء', 'error');
            }
        } else if (status === 419) {
            // CSRF / Session Expired
            if (window.addToast) {
                window.addToast('انتهت صلاحية الجلسة، يرجى إعادة تسجيل الدخول لتجنب فقدان البيانات.', 'warning');
            }
        } else if (status >= 500) {
            // Server Error
            if (window.addToast) {
                window.addToast('حدث خطأ فني في الخادم، يرجى المحاولة لاحقاً', 'error');
            } else {
                console.error('Internal Server Error');
            }
        } else if (!error.response) {
            // Network Error (No response)
            if (window.addToast) {
                window.addToast('تعذر الاتصال بالخادم، يرجى التحقق من اتصالك بالإنترنت', 'error');
            }
        }
        
        return Promise.reject(error);
    }
);
