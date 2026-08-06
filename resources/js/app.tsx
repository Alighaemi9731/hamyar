import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import { resolvePage } from '@/lib/pages';

const appName = import.meta.env.VITE_APP_NAME ?? 'MobiShop';

void createInertiaApp({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: resolvePage,
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
  progress: {
    color: '#0FA3A8', // --color-brand; the progress bar renders outside React.
  },
});
