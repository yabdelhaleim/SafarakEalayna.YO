import axios from 'axios';
window.axios = axios;

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.withCredentials = false;
axios.defaults.baseURL = import.meta.env?.VITE_API_URL || '/';

const csrfToken = document.head.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken.content;
}

// ✅ Request deduplication and caching layer
const pendingPromises = new Map();
const apiCache = new Map();
const CACHE_TTL = 15000; // 15 seconds

// Request cancellation tracking on route change
let activeRequests = [];
window.cancelPendingRequests = () => {
    activeRequests.forEach(cancel => {
        try {
            cancel();
        } catch (e) {}
    });
    activeRequests = [];
};

// ✅ Fix 3: بيقرأ التوكن من localStorage في كل request مش مرة واحدة بس
axios.interceptors.request.use((config) => {
    // Generate abort signal if not already present
    const controller = new AbortController();
    if (!config.signal) {
        config.signal = controller.signal;
    }

    const cancel = () => {
        try {
            controller.abort();
        } catch (e) {}
    };
    activeRequests.push(cancel);
    config.cancelTokenTracker = cancel;

    if (config.method === 'get') {
        const key = config.url + '?' + new URLSearchParams(config.params || {}).toString();
        
        // Soft cache check
        if (apiCache.has(key)) {
            const cached = apiCache.get(key);
            if (Date.now() - cached.timestamp < CACHE_TTL) {
                // Return cached response via custom adapter
                config.adapter = () => Promise.resolve({
                    data: cached.data,
                    status: 200,
                    statusText: 'OK',
                    headers: cached.headers,
                    config,
                    request: {}
                });
                return config;
            } else {
                apiCache.delete(key);
            }
        }
    }

    const token = localStorage.getItem('auth_token');
    if (token) {
        config.headers['Authorization'] = `Bearer ${token}`;
    } else {
        delete config.headers['Authorization'];
    }
    return config;
});

let isRedirectingToLogin = false; // ✅ منع redirect مكرر

// Custom adapter wrapper to handle promise deduplication for GET requests
const originalAdapter = axios.defaults.adapter || axios.defaults.adapter;
axios.defaults.adapter = async (config) => {
    if (config.method === 'get' && !config.adapter) {
        const key = config.url + '?' + new URLSearchParams(config.params || {}).toString();
        
        if (pendingPromises.has(key)) {
            return pendingPromises.get(key);
        }
        
        const promise = originalAdapter(config).then(response => {
            apiCache.set(key, {
                data: response.data,
                headers: response.headers,
                timestamp: Date.now()
            });
            pendingPromises.delete(key);
            return response;
        }).catch(error => {
            pendingPromises.delete(key);
            throw error;
        });
        
        pendingPromises.set(key, promise);
        return promise;
    }
    
    // Fallback if custom adapter was set (like our cache adapter)
    if (config.adapter && config.adapter !== axios.defaults.adapter) {
        return config.adapter(config);
    }
    return originalAdapter(config);
};
axios.interceptors.response.use(
    (response) => {
        if (response.config?.cancelTokenTracker) {
            activeRequests = activeRequests.filter(c => c !== response.config.cancelTokenTracker);
        }
        return response;
    },
    async (error) => {
        if (error.config?.cancelTokenTracker) {
            activeRequests = activeRequests.filter(c => c !== error.config.cancelTokenTracker);
        }
        
        // Check if the request was cancelled
        if (axios.isCancel(error)) {
            return Promise.reject(error);
        }

        const status = error.response?.status;
        if (status === 401) {
            localStorage.removeItem('auth_token');
            delete axios.defaults.headers.common['Authorization'];

            if (!isRedirectingToLogin) {
                isRedirectingToLogin = true;
                if (window.addToast) {
                    window.addToast('انتهت جلستك، سيتم تحويلك لتسجيل الدخول', 'warning');
                }
                setTimeout(() => {
                    isRedirectingToLogin = false;
                    window.location.href = '/login';
                }, 1500);
            }

        } else if (status === 419) {
            // ✅ Fix 2: جدد الـ CSRF وعيد الـ Request تلقائي بدون ما المستخدم يحس
            try {
                await axios.get('/sanctum/csrf-cookie');
                const newCsrf = document.head.querySelector('meta[name="csrf-token"]');
                if (newCsrf) {
                    error.config.headers['X-CSRF-TOKEN'] = newCsrf.content;
                }
                return axios(error.config); // إعادة نفس الـ Request
            } catch {
                if (window.addToast) {
                    window.addToast('انتهت صلاحية الجلسة، يرجى تحديث الصفحة', 'warning');
                }
            }

        } else if (status === 403) {
            if (window.addToast) {
                window.addToast('ليس لديك الصلاحية للقيام بهذا الإجراء', 'error');
            }

        } else if (status >= 500) {
            if (window.addToast) {
                window.addToast('حدث خطأ فني في الخادم، يرجى المحاولة لاحقاً', 'error');
            } else {
                console.error('Internal Server Error');
            }

        } else if (!error.response) {
            if (window.addToast) {
                window.addToast('تعذر الاتصال بالخادم، يرجى التحقق من اتصالك بالإنترنت', 'error');
            }
        }

        return Promise.reject(error);
    }
);