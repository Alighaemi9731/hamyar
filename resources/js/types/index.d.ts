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

/**
 * One monthly credit's standing, as the server computed it.
 *
 * `level` is decided server-side on purpose: one rule for the whole product, and the
 * distinction between "full" (amber — the shop used what it bought) and "blocked" (red —
 * a credit actually refused work) needs a fact only the server has.
 */
export interface UsageMeterState {
  key: string;
  label: string;
  unit: string;
  module: string;
  used: number;
  /** null = unlimited on this plan. Renders as «نامحدود», with no bar. */
  limit: number | null;
  window: 'month' | 'total';
  /** UTC ISO. When this credit refills; null for a standing capacity. */
  resets_at: string | null;
  level: 'ok' | 'warning' | 'reached' | 'blocked';
}

export interface UsageState {
  plan: { code: string; name: string; lapsed: boolean };
  meters: UsageMeterState[];
  /** Metric keys at warning or worse — what the banner is about. */
  attention: string[];
}

/**
 * Left in the session by a refusal, and rendered once by the shell.
 *
 * `due` is the PRORATED price of upgrading today, from the same calculator that writes
 * the invoice (ADR 0006) — a figure quoted here that disagreed with the gateway would be
 * worse than no figure.
 */
export interface QuotaBlockState {
  metric: string;
  label: string;
  message: string;
  used: number;
  limit: number | null;
  requested: number;
  resets_at: string | null;
  next_plan: {
    code: string;
    name: string;
    limit: number | null;
    price: MoneyValue;
    due: MoneyValue;
  } | null;
  can_upgrade: boolean;
}

export interface Announcement {
  id: number;
  title: string;
  body: string;
  level: 'info' | 'warning' | 'critical';
}

export interface BranchState {
  current: number | null;
  can_consolidate: boolean;
  options: { id: number; name: string }[];
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
  /**
   * Which branch this user is viewing and which they may switch to.
   *
   * Empty (`{}`) outside a tenant and on the public invoice page, which renders through
   * the same middleware — the switcher treats a missing option list as "nothing to
   * switch between" and renders nothing.
   */
  branch?: BranchState;
  /**
   * Monthly credits and what is left of them. Empty (`{}`) outside a tenant and for
   * non-staff, like `branch` — it is commercial information about the shop.
   */
  usage?: UsageState;
  /** Present only on the request that follows a refusal. */
  quota_block?: QuotaBlockState | null;
  /** Current URL path, for marking the active nav item. */
  location: string;
  [key: string]: unknown;
}

/**
 * Money crosses the wire as an integer plus a pre-formatted string, so the client
 * never does money arithmetic and never sees a float (golden rule 2).
 */
export interface MoneyValue {
  /** Integer rial. The only number the client compares or does arithmetic on. */
  value: number;
  formatted: string;
}

declare module '@inertiajs/core' {
  interface PageProps extends SharedProps {}
}
