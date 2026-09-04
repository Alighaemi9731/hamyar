import { globSync } from 'node:fs';
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
        // The public landing is a separate entry on purpose: its own grounds and its
        // scroll choreography must never be pulled into the authenticated bundle, and
        // the app's components must never be pulled into the landing (ADR 0016). The
        // two share exactly one leaf, `resources/css/brand.css` (tokens and fonts);
        // `bin/check-bundle-boundary` refuses any other crossing.
        'resources/landing/landing.css',
        'resources/landing/landing.js',
        // Gate 16.2's direction comps: the brand layer alone, so the old landing's look cannot
        // leak into the candidates meant to replace it. Removed with the comps in 16.3.
        'resources/landing/gate.css',
        // Real product screenshots, referenced from the landing via Vite::asset().
        // Listed as inputs rather than dropped in public/ so they are content-hashed:
        // a re-captured screenshot invalidates its own URL instead of hiding behind a
        // cached one. A glob, so `bin/shots` can add a screen without editing this file.
        ...globSync('resources/landing/shots/*.webp').sort(),
        // Fonts are inputs for the same reason, plus one more: the document heads
        // preload the two files the first paint needs via Vite::asset(), and an input
        // is what puts a file in the manifest. The `url()` in fonts.css resolves to the
        // same hashed asset, so the preload and the stylesheet agree on one URL.
        ...globSync('resources/fonts/*.woff2').sort(),
        // The og:image, captured by `bin/shots og` — hashed like the shots, so a re-capture
        // invalidates the URL every unfurler has cached.
        ...globSync('resources/landing/og/*.png').sort(),
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
