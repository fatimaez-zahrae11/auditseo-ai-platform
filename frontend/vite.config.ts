import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import {defineConfig, loadEnv} from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const configuredApiUrl = process.env.VITE_API_BASE_URL?.trim() || env.VITE_API_BASE_URL?.trim();
  if (mode === 'production') {
    if (!configuredApiUrl) throw new Error('VITE_API_BASE_URL is required for production builds.');
    let apiUrl: URL;
    try {
      apiUrl = new URL(configuredApiUrl);
    } catch {
      throw new Error('VITE_API_BASE_URL must be a valid absolute URL.');
    }
    if (apiUrl.protocol !== 'https:') throw new Error('VITE_API_BASE_URL must use HTTPS in production.');
  }

  return {
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      },
    },
    server: {
      // HMR is disabled in AI Studio via DISABLE_HMR env var.
      // File watching can be disabled in constrained preview environments.
      hmr: process.env.DISABLE_HMR !== 'true',
      // Disable file watching when DISABLE_HMR is true to save CPU during agent edits.
      watch: process.env.DISABLE_HMR === 'true' ? null : {},
    },
  };
});
