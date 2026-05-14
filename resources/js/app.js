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

console.log('Mounting...');
try {
    app.mount('#app');
    console.log('Mounted.');
} catch (e) {
    console.error('Mount error:', e);
}
