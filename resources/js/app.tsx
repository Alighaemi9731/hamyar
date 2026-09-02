import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import { Direction } from 'radix-ui';

import { TooltipProvider } from '@/components/ui/tooltip';
import { resolvePage } from '@/lib/pages';

const appName = import.meta.env.VITE_APP_NAME ?? 'سامانه همیار';

void createInertiaApp({
  title: (title) => (title ? `${title} — ${appName}` : appName),
  resolve: resolvePage,
  setup({ el, App, props }) {
    // Radix requires a TooltipProvider above every Tooltip. Mounting it once at the
    // root means a page can drop a tooltip anywhere without remembering to wrap it —
    // and a missing provider throws at render time, blanking the whole page.
    createRoot(el).render(
      // Every Radix primitive reads its direction from this provider. Without it each
      // one defaults to LTR, and sixty-one call sites were passing `dir="rtl"` by hand
      // to the portals they remembered — a menu, a select, a sheet — while the ones they
      // forgot opened mirrored. This is the whole product's direction, so it is set once,
      // at the root, beside the other provider Radix insists on.
      <Direction.Provider dir="rtl">
        <TooltipProvider delayDuration={200}>
          <App {...props} />
        </TooltipProvider>
      </Direction.Provider>
    );
  },
  progress: {
    /*
      Read from the token rather than pinned to a hex.

      This was `#0FA3A8` — a teal — with a comment claiming it was `--color-brand`. It has
      not been: the brand is `#0066cc` (ADR 0008, and `#0071e3` was rejected for failing AA
      on the grey ground). So every page navigation in the product drew a teal loading bar
      across the top of a blue application, which is also the one raw hex the design system
      forbids outright.

      Inertia's progress bar renders outside React and takes a string, so it cannot use a
      CSS variable directly — but it can be handed the computed value of one. Read once at
      setup, after `app.css` is in the document, which is enough: the bar is chrome that
      appears for a few hundred milliseconds, not a surface that has to restyle on a theme
      switch. The fallback is the light-mode brand step, for the case where the stylesheet
      has not parsed yet.
    */
    color:
      getComputedStyle(document.documentElement).getPropertyValue('--color-brand').trim() ||
      '#0066cc',
  },
});
