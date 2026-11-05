import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', '');
    const appUrl = new URL(env.APP_URL || 'http://localhost:8080');

    return {
        plugins: [
            react(),
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: appUrl.origin,
            hmr: {
                host: appUrl.hostname,
                clientPort: Number(appUrl.port || (appUrl.protocol === 'https:' ? 443 : 80)),
                protocol: appUrl.protocol === 'https:' ? 'wss' : 'ws',
                path: '/hmr',
            },
            watch: {
                usePolling: true,
            },
        },
    };
});
