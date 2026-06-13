import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const apiProxyTarget = env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8000';

    return {
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    server: {
        host: '127.0.0.1',
        watch: {
            ignored: [
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/vendor/**',
                '**/safarakealayna*',
                '**/*.db',
                '**/*.sqlite',
                '**/appDataDir/**',
                '**/.gemini/**'
            ],
        },
        proxy: {
            '/api': {
                target: apiProxyTarget,
                changeOrigin: true,
                secure: false,
            },
            '/sanctum': {
                target: apiProxyTarget,
                changeOrigin: true,
                secure: false,
            },
        },
    },
};
});
