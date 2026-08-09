/**
 * Shape of the props every Inertia page receives.
 *
 * Kept in sync by hand with App\Http\Middleware\HandleInertiaRequests::share().
 * If you add a shared prop there, add it here — pages are written against this type.
 */

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  mobile: string | null;
  /** Permission names in `module.action` form, already flattened for the UI. */
  permissions: string[];
  roles: string[];
}

export interface TenantSettings {
  /** Money display unit. Storage is always integer rial regardless (golden rule 2). */
  currency_display: 'rial' | 'toman';
  /** Persian digits in tables and invoices, or Latin tabular ones. */
  digits: 'fa' | 'latin';
}

export interface Tenant {
  id: number;
  name: string;
  subdomain: string;
  settings: TenantSettings;
}

export interface FlashMessages {
  success?: string;
  error?: string;
  warning?: string;
  info?: string;
}

/**
 * Feature flags resolved from the tenant's plan and add-ons (Pennant, Phase 2).
 * Keys look like `module:repairs` or `limit:invoices_per_month`.
 *
 * Hiding UI with these is a convenience, never authorization — the route is also
 * guarded by EnsureModuleEnabled (golden rule 7).
 */
export type Features = Record<string, boolean>;

export interface Announcement {
  id: number;
  title: string;
  body: string;
  level: 'info' | 'warning' | 'critical';
}

export interface SharedProps {
  auth: {
    user: AuthUser | null;
  };
  tenant: Tenant | null;
  features: Features;
  flash: FlashMessages;
  /** Live platform notices for this shop. Usually empty. */
  announcements: Announcement[];
  /** Current URL path, for marking the active nav item. */
  location: string;
  [key: string]: unknown;
}

/**
 * Money crosses the wire as an integer plus a pre-formatted string, so the client
 * never does money arithmetic and never sees a float (golden rule 2).
 */
export interface MoneyValue {
  amount: number;
  formatted: string;
}

declare module '@inertiajs/core' {
  interface PageProps extends SharedProps {}
}
