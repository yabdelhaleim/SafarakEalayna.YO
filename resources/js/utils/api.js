import axios from 'axios';

export function isRequestCanceled(error) {
    return axios.isCancel?.(error)
        || error?.code === 'ERR_CANCELED'
        || error?.name === 'CanceledError';
}

const api = axios.create();

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

/**
 * Translates any backend (Laravel) error into a user-facing Arabic message.
 *
 * Laravel returns errors in a few shapes:
 *  - {success: false, message: '...'}        (ApiResponse::error)
 *  - {message: '...'}                       (straight Laravel)
 *  - {errors: {field: [...]}, message: '...'} (Form-request 422)
 *  - {data: null} + status 4xx/5xx           (catch-all)
 *
 * We surface the most specific message we can find; otherwise a generic Arabic
 * fallback.  Returns {message, type, status} — `type` is 'error' | 'warning'.
 */
export function translateApiError(error) {
    if (!error) {
        return { message: 'حدث خطأ غير متوقع', type: 'error', status: null };
    }
    if (isRequestCanceled(error)) {
        return { message: 'تم إلغاء الطلب', type: 'warning', status: null };
    }

    const status = error.response?.status ?? null;
    const data = error.response?.data;

    // Network errors
    if (error.code === 'ERR_NETWORK') {
        return { message: 'تعذّر الاتصال بالخادم — تحقّق من الإنترنت', type: 'error', status: null };
    }
    if (status === null) {
        return { message: 'تعذّر الاتصال بالخادم — حاول مرة أخرى', type: 'error', status: null };
    }

    // 401: not authenticated
    if (status === 401) {
        return { message: 'انتهت الجلسة — يرجى تسجيل الدخول مرة أخرى', type: 'error', status };
    }
    // 403: forbidden
    if (status === 403) {
        return { message: 'ليست لديك صلاحية لهذا الإجراء', type: 'error', status };
    }
    // 404: not found
    if (status === 404) {
        return { message: 'العنصر المطلوب غير موجود', type: 'warning', status };
    }
    // 419: CSRF (Laravel)
    if (status === 419) {
        return { message: 'انتهت صلاحية الجلسة — حدّث الصفحة', type: 'warning', status };
    }
    // 422 / 400: validation error
    if (status === 422 || status === 400) {
        // 1) ApiResponse::error envelope
        if (data?.message && typeof data.message === 'string') {
            return { message: data.message, type: 'error', status };
        }
        // 2) Form-request validation errors { errors: {field: [..]} }
        if (data?.errors && typeof data.errors === 'object') {
            const firstField = Object.keys(data.errors)[0];
            const firstMsg = Array.isArray(data.errors[firstField])
                ? data.errors[firstField][0]
                : data.errors[firstField];
            return { message: `${firstField}: ${firstMsg}`, type: 'error', status };
        }
        return { message: 'البيانات المُرسلة غير صحيحة', type: 'error', status };
    }
    // 429: rate-limit
    if (status === 429) {
        return { message: 'تم تجاوز حد الطلبات — حاول بعد قليل', type: 'warning', status };
    }
    // 500 / 502 / 503: server error
    if (status >= 500) {
        return { message: 'خطأ في الخادم — حاول مرة أخرى أو تواصل مع الدعم', type: 'error', status };
    }
    // Generic fallback
    const fallback = data?.message ?? error.message ?? 'حدث خطأ غير متوقع';
    return { message: fallback, type: 'error', status };
}

/**
 * Tiny event bus for toasts. Views subscribe via `onGlobalError(handler)`
 * and call `notifyGlobalError(message, type)` from anywhere (e.g. the
 * axios response interceptor).
 *
 * Implementation: a Set of listeners stored on `window.__toastListeners__`
 * so the bus survives router transitions and HMR reloads.
 */
if (! window.__toastListeners__) {
    window.__toastListeners__ = new Set();
}

export function onGlobalError(handler) {
    window.__toastListeners__.add(handler);
    return () => window.__toastListeners__.delete(handler);
}

export function notifyGlobalError(message, type = 'error') {
    for (const fn of window.__toastListeners__) {
        try {
            fn(message, type);
        } catch (e) {
            // listener crashed — log + continue
            console.error('[toast bus] listener failed', e);
        }
    }
}

/**
 * Auto-install response interceptor: every backend error becomes a
 * translated Arabic message that flashes in the global toast.
 *
 * IMPORTANT: respects `config.skipGlobalErrorToast` so callers can silence
 * the toast when they plan to handle the error inline (e.g. in a form's
 * "save changes" handler that already shows its own feedback).
 */
api.interceptors.response.use(
    (resp) => resp,
    (error) => {
        // Skip global toast for cancelled requests (browser nav, HMR)
        if (isRequestCanceled(error)) {
            return Promise.reject(error);
        }
        const config = error?.config ?? {};
        const silent = config.skipGlobalErrorToast === true;

        if (! silent) {
            const { message, type } = translateApiError(error);
            notifyGlobalError(message, type);
        }

        // Console-log for developers regardless of UI choice
        if (import.meta.env.DEV) {
            const { status } = translateApiError(error);
            console.warn(`[api ${status ?? '???'}] ${config.method?.toUpperCase()} ${config.url}`, error);
        }

        return Promise.reject(error);
    },
);

export default api;
