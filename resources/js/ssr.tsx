import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';

import { TooltipProvider } from '@/components/ui/tooltip';
import { resolvePage } from '@/lib/pages';

const appName = 'MobiShop';

/**
 * SSR entry.
 *
 * Not enabled by default — the tenant panel is behind a login and gains little from
 * it. It exists so the public pages that DO need it (repair tracking, storefront,
 * reseller price lists, and the landing page in Phase 11) can be server-rendered
 * without restructuring the build.
 */
createServer((page) =>
  createInertiaApp({
    page,
    render: ReactDOMServer.renderToString,
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: resolvePage,
    setup: ({ App, props }) => (
      <TooltipProvider delayDuration={200}>
        <App {...props} />
      </TooltipProvider>
    ),
  })
);
