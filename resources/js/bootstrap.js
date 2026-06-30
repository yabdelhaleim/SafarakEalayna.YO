import axios from 'axios';
import { isRequestCanceled } from './utils/api.js';

// Global Locale Override to force English numerals (Latin numbering system) in Arabic formatting
(function () {
    try {
        const patchLocale = (locales) => {
            if (locales === undefined || locales === null) {
                const navLang = typeof navigator !== 'undefined' ? (navigator.language || '') : '';
                if (navLang.startsWith('ar')) {
                    return 'ar-EG-u-nu-latn';
                }
                return undefined;
            }
            if (typeof locales === 'string') {
                if (locales.startsWith('ar-EG') || locales === 'ar') {
                    return 'ar-EG-u-nu-latn';
                }
            } else if (Array.isArray(locales)) {
                return locales.map(l => typeof l === 'string' && (l.startsWith('ar-EG') || l === 'ar') ? 'ar-EG-u-nu-latn' : l);
            }
            return locales;
        };

        // 1. Patch Intl.NumberFormat
        const OriginalIntlNumberFormat = Intl.NumberFormat;
        const patchedNumberFormat = function(locales, options) {
            return new OriginalIntlNumberFormat(patchLocale(locales), options);
        };
        patchedNumberFormat.prototype = OriginalIntlNumberFormat.prototype;
        Object.getOwnPropertyNames(OriginalIntlNumberFormat).forEach(prop => {
            if (prop !== 'prototype' && prop !== 'length' && prop !== 'name') {
                Object.defineProperty(patchedNumberFormat, prop, {
                    value: OriginalIntlNumberFormat[prop],
                    writable: true,
                    configurable: true,
                    enumerable: true
                });
            }
        });
        Intl.NumberFormat = patchedNumberFormat;

        // 2. Patch Intl.DateTimeFormat
        const OriginalIntlDateTimeFormat = Intl.DateTimeFormat;
        const patchedDateTimeFormat = function(locales, options) {
            return new OriginalIntlDateTimeFormat(patchLocale(locales), options);
        };
        patchedDateTimeFormat.prototype = OriginalIntlDateTimeFormat.prototype;
        Object.getOwnPropertyNames(OriginalIntlDateTimeFormat).forEach(prop => {
            if (prop !== 'prototype' && prop !== 'length' && prop !== 'name') {
                Object.defineProperty(patchedDateTimeFormat, prop, {
                    value: OriginalIntlDateTimeFormat[prop],
                    writable: true,
                    configurable: true,
                    enumerable: true
                });
            }
        });
        Intl.DateTimeFormat = patchedDateTimeFormat;

        // 3. Patch Number.prototype.toLocaleString
        const originalNumberToLocaleString = Number.prototype.toLocaleString;
        Number.prototype.toLocaleString = function(locales, options) {
            return originalNumberToLocaleString.call(this, patchLocale(locales), options);
        };

        // 4. Patch Date.prototype.toLocaleString
        const originalDateToLocaleString = Date.prototype.toLocaleString;
        Date.prototype.toLocaleString = function(locales, options) {
            return originalDateToLocaleString.call(this, patchLocale(locales), options);
        };

        // 5. Patch Date.prototype.toLocaleDateString
        const originalDateToLocaleDateString = Date.prototype.toLocaleDateString;
        Date.prototype.toLocaleDateString = function(locales, options) {
            return originalDateToLocaleDateString.call(this, patchLocale(locales), options);
        };

        // 6. Patch Date.prototype.toLocaleTimeString
        const originalDateToLocaleTimeString = Date.prototype.toLocaleTimeString;
        Date.prototype.toLocaleTimeString = function(locales, options) {
            return originalDateToLocaleTimeString.call(this, patchLocale(locales), options);
        };
    } catch (e) {
        console.error('Failed to patch global locale formatting:', e);
    }
})();

window.axios = axios;
window.isRequestCanceled = isRequestCanceled;

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
const CACHE_TTL = 1500; // 1.5 seconds (prevents stale database reads while keeping request deduplication)

// Request cancellation tracking on route change
let activeRequests = [];
window.cancelPendingRequests = () => {
    activeRequests.forEach(item => {
        try {
            // Do not abort login/logout/register requests on page changes
            if (item.url && (item.url.includes('/auth/login') || item.url.includes('/auth/logout') || item.url.includes('/auth/register') || item.url.includes('/auth/refresh'))) {
                return;
            }
            item.cancel();
        } catch (e) {}
    });
    activeRequests = activeRequests.filter(item => {
        return item.url && (item.url.includes('/auth/login') || item.url.includes('/auth/logout') || item.url.includes('/auth/register') || item.url.includes('/auth/refresh'));
    });
};

// ✅ Fix 3: بيقرأ التوكن من localStorage في كل request مش مرة واحدة بس
axios.interceptors.request.use((config) => {
    // Clear API cache on any state-changing request (POST, PUT, PATCH, DELETE) to prevent stale data
    if (config.method && config.method.toLowerCase() !== 'get') {
        apiCache.clear();
        pendingPromises.clear();
    }

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
    const requestItem = { cancel, url: config.url };
    activeRequests.push(requestItem);
    config.cancelTokenTracker = requestItem;

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

    // Enforce JSON headers so FormRequest works correctly in Laravel
    config.headers['Accept'] = 'application/json';
    if (config.data && typeof config.data === 'object') {
        config.headers['Content-Type'] = 'application/json';
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
const originalAdapter = typeof axios.getAdapter === 'function' 
    ? axios.getAdapter(axios.defaults.adapter) 
    : (typeof axios.defaults.adapter === 'function' ? axios.defaults.adapter : null);

axios.defaults.adapter = async (config) => {
    if (config.method === 'get' && !config.adapter) {
        const key = config.url + '?' + new URLSearchParams(config.params || {}).toString();
        
        if (pendingPromises.has(key)) {
            return pendingPromises.get(key);
        }
        
        const promise = (originalAdapter ? originalAdapter(config) : Promise.reject(new Error('No adapter found'))).then(response => {
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
    return originalAdapter ? originalAdapter(config) : Promise.reject(new Error('No adapter found'));
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
        
        // Check if the request was cancelled (AbortController / legacy cancel token)
        if (isRequestCanceled(error)) {
            return Promise.reject(error);
        }

        const status = error.response?.status;
        if (status === 401) {
            const requestUrl = error.config?.url || '';
            const isPublicAuthRequest =
                requestUrl.includes('/auth/login') ||
                requestUrl.includes('/auth/register');

            if (
                !isPublicAuthRequest &&
                !error.config?._authRetried &&
                localStorage.getItem('auth_token') &&
                !requestUrl.includes('/auth/refresh')
            ) {
                error.config._authRetried = true;
                try {
                    const refreshResponse = await axios.post('/api/v1/auth/refresh');
                    const newToken = refreshResponse.data?.data?.token;
                    if (newToken) {
                        localStorage.setItem('auth_token', newToken);
                        axios.defaults.headers.common['Authorization'] = `Bearer ${newToken}`;
                        error.config.headers = error.config.headers || {};
                        error.config.headers['Authorization'] = `Bearer ${newToken}`;
                        return axios(error.config);
                    }
                } catch {
                    // سيتم تسجيل الخروج أدناه
                }
            }

            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_token_expires_minutes');
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