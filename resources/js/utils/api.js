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

export default api;
