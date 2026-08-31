import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface SettingsSectionProps {
  title?: string;
  description?: string;
  /** `flush` for lists that own their own dividers and padding. */
  variant?: 'padded' | 'flush';
  className?: string;
  children: ReactNode;
}

/**
 * One card in a settings screen.
 *
 * Exists so the settings pages share a single set of spacing, radius and hairline
 * decisions instead of each choosing its own — which is exactly how a design system
 * drifts back into per-page styling (ADR 0008).
 */
export function SettingsSection({
  title,
  description,
  variant = 'padded',
  className,
  children,
}: SettingsSectionProps) {
  return (
    <Card
      asChild
      ground="surface"
      // `flush` lists own their padding, so the card provides none and the header below
      // pays for its own.
      padding={variant === 'padded' ? 'lg' : 'none'}
      className={cn('overflow-hidden', className)}
    >
      <section>
        {(title || description) && (
          <div className={cn('space-y-1.5', variant === 'flush' && 'p-6 pb-0 sm:p-7 sm:pb-0')}>
            {title && <h2 className="text-lg font-bold">{title}</h2>}
            {description && (
              <p className="max-w-xl text-sm leading-relaxed text-muted-foreground">
                {description}
              </p>
            )}
          </div>
        )}

        <div className={cn((title || description) && variant === 'padded' && 'mt-6')}>
          {children}
        </div>
      </section>
    </Card>
  );
}
