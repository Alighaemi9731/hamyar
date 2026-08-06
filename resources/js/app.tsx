import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import { TooltipProvider } from '@/components/ui/tooltip';
import { resolvePage } from '@/lib/pages';

const appName = import.meta.env.VITE_APP_NAME ?? 'MobiShop';

void createInertiaApp({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: resolvePage,
  setup({ el, App, props }) {
    // Radix requires a TooltipProvider above every Tooltip. Mounting it once at the
    // root means a page can drop a tooltip anywhere without remembering to wrap it —
    // and a missing provider throws at render time, blanking the whole page.
    createRoot(el).render(
      <TooltipProvider delayDuration={200}>
        <App {...props} />
      </TooltipProvider>
    );
  },
  progress: {
    color: '#0FA3A8', // --color-brand; the progress bar renders outside React.
  },
});
