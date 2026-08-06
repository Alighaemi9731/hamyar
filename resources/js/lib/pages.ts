import type { ComponentType } from 'react';

/**
 * Page resolution for Inertia.
 *
 * Pages live in two places, and both are searched:
 *
 *   resources/js/pages/**              app-level pages (auth, dashboard, /design)
 *   app/Modules/<Name>/resources/js/** module-owned pages
 *
 * A module page is referenced as `Repairs::Tickets/Index`, mirroring how Blade
 * namespaces module views. Keeping pages inside the module is what stops the
 * frontend from quietly becoming one flat folder that ignores module boundaries
 * (ADR 0003).
 */

type PageModule = { default: ComponentType<Record<string, unknown>> };

const appPages = import.meta.glob<PageModule>('../pages/**/*.tsx');

const modulePages = import.meta.glob<PageModule>(
  '../../../app/Modules/*/resources/js/pages/**/*.tsx'
);

export async function resolvePage(name: string): Promise<PageModule> {
  const [maybeModule, maybePath] = name.split('::');

  const path =
    maybePath === undefined
      ? `../pages/${maybeModule}.tsx`
      : `../../../app/Modules/${maybeModule}/resources/js/pages/${maybePath}.tsx`;

  const loader = maybePath === undefined ? appPages[path] : modulePages[path];

  if (!loader) {
    throw new Error(
      `Inertia page [${name}] not found. Expected a file at ${path.replace('../', 'resources/js/')}.`
    );
  }

  return loader();
}
