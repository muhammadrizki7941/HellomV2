import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    const vitePort = Number(env.VITE_PORT || 5173);
    const devServerUrl = (env.VITE_DEV_SERVER_URL || '').trim();
    const hmrHost = (env.VITE_HMR_HOST || '').trim();

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
        ],
        server: {
            host: true,
            port: Number.isFinite(vitePort) ? vitePort : 5173,
            strictPort: true,
            origin: devServerUrl || undefined,
            hmr: hmrHost ? { host: hmrHost } : undefined,
        },
    };
});
