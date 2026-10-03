import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

// Uploaded files are stored as same-origin paths (/media/..., /storage/...). In production the
// SPA and Laravel share the domain; in dev Vite (:3000) forwards them to Laravel so images load
// instead of getting the app shell back.
function backendOrigin(apiBase: string | undefined): string {
  try {
    return new URL(apiBase || 'http://127.0.0.1:8000/api/v1/hellom').origin;
  } catch {
    return 'http://127.0.0.1:8000';
  }
}

export default defineConfig(({ mode }) => {
  const env = { ...loadEnv(mode, process.cwd(), 'VITE_'), ...process.env };
  const backend = backendOrigin(env.VITE_HELLOM_API_BASE);
  return {
    base: '/',
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    build: {
      outDir: '../backend/public/hellom',
      emptyOutDir: true,
      rollupOptions: {
        output: {
          manualChunks: {
            'vendor-react': ['react', 'react-dom', 'react-router-dom'],
            'vendor-ui': ['lucide-react'],
            // charts are only used by admin/POS dashboards: keep them out of the initial load
            'vendor-charts': ['recharts'],
            'vendor-forms': ['react-hook-form', '@hookform/resolvers', 'zod'],
          },
        },
      },
    },
    server: {
      hmr: process.env.DISABLE_HMR !== 'true',
      host: '0.0.0.0',
      port: 3000,
      proxy: {
        '/media': { target: backend, changeOrigin: true },
        '/storage': { target: backend, changeOrigin: true },
      },
    },
  };
});
