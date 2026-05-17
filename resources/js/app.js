import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './bootstrap';
import '../css/app.css';

console.log('App starting...');

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

// Global Vue Error Handler
app.config.errorHandler = (err, instance, info) => {
    // Log internally but don't crash
    console.error('[Vue Error]:', err);
    console.error('[Info]:', info);

    // Provide friendly UI feedback
    if (window.addToast) {
        window.addToast('عذراً، حدث خطأ أثناء عرض جزء من الصفحة. يرجى محاولة تحديث الصفحة.', 'error');
    }
};

// Global Promise Rejection Handler
window.addEventListener('unhandledrejection', (event) => {
    // Prevent unhandled promise rejections from leaking info
    const reason = event.reason;
    console.error('[Unhandled Rejection]:', reason);
    
    // Friendly UI feedback for network/API errors that weren't caught locally
    if (window.addToast) {
        const message = reason?.response?.data?.message || 'حدث خطأ غير متوقع في العمليات الخلفية.';
        window.addToast(message, 'error');
    }
});

try {
    app.mount('#app');
} catch (e) {
    console.error('Critical Mount Error:', e);
}
