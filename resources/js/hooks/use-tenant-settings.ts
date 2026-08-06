import { usePage } from '@inertiajs/react';

import type { SharedProps, TenantSettings } from '@/types';

/**
 * Tenant display preferences, with defaults that hold on pages rendered before a
 * tenant exists (central onboarding, the /design gallery, error pages).
 */
const DEFAULTS: TenantSettings = {
  currency_display: 'toman',
  digits: 'fa',
};

export function useTenantSettings(): TenantSettings {
  const page = usePage<SharedProps>();

  return { ...DEFAULTS, ...(page.props.tenant?.settings ?? {}) };
}
