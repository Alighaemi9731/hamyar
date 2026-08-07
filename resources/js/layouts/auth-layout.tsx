import { usePage } from '@inertiajs/react';
import { StoreIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import type { SharedProps } from '@/types';

interface AuthLayoutProps {
  title: string;
  description?: string;
  /** `lg` for the onboarding form, which has twice the fields. */
  width?: 'sm' | 'lg';
  children: ReactNode;
  footer?: ReactNode;
}

/**
 * Frame for every unauthenticated screen.
 *
 * Shows the shop's own name when a tenant is resolved, so a user who followed the
 * wrong bookmark notices before typing their password into another shop's page.
 * Uses semantic surface tokens only, so a change to the design tokens restyles all of
 * these at once without touching a page.
 */
export function AuthLayout({ title, description, width = 'sm', children, footer }: AuthLayoutProps) {
  const { tenant } = usePage<SharedProps>().props;

  return (
    <div className="flex min-h-dvh items-center justify-center bg-background px-5 py-16">
      <div className={width === 'lg' ? 'w-full max-w-lg' : 'w-full max-w-sm'}>
        <div className="mb-10 flex flex-col items-center gap-3 text-center">
          <span className="flex size-12 items-center justify-center rounded-card bg-primary text-primary-foreground shadow-low">
            <StoreIcon className="size-5" />
          </span>

          {tenant && (
            <span className="text-2xs text-muted-foreground">{tenant.name}</span>
          )}

          <h1 className="text-2xl font-bold">{title}</h1>

          {description && <p className="text-sm text-muted-foreground">{description}</p>}
        </div>

        <div className="space-y-5 rounded-card border border-border bg-surface p-7 shadow-low sm:p-8">
          {children}
        </div>

        {footer && <div className="mt-6 text-center text-xs text-muted-foreground">{footer}</div>}
      </div>
    </div>
  );
}
