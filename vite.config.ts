import { fileURLToPath, URL } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.tsx',
        // The public landing is a separate entry on purpose: its dark theme, its fonts
        // (arabic subsets only) and its scroll choreography must never be pulled into
        // the authenticated bundle, and the app's design system must never be pulled
        // into the landing (ADR 0016).
        'resources/landing/landing.css',
        'resources/landing/landing.js',
        // Real product screenshots, referenced from landing.blade.php via Vite::asset().
        // They are listed as inputs rather than dropped in public/ so they are
        // content-hashed like everything else: a re-captured screenshot invalidates its
        // own URL instead of hiding behind a cached one.
        'resources/landing/shots/pos.webp',
        'resources/landing/shots/repairs.webp',
        'resources/landing/shots/installments.webp',
        'resources/landing/shots/sms.webp',
        'resources/landing/shots/profit.webp',
        'resources/landing/shots/imei.webp',
      ],
      ssr: 'resources/js/ssr.tsx',
      refresh: true,
    }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  server: {
    // PHP runs in a container while Vite runs on the host, so the dev server has to
    // be reachable from outside localhost and must advertise a host the browser can
    // resolve — otherwise @vite emits URLs that only work inside the container.
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
    cors: true,
    watch: {
      ignored: ['**/storage/framework/views/**', '**/vendor/**'],
    },
  },
});
