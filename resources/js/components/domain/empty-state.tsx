import type { LucideIcon } from 'lucide-react';
import { InboxIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface EmptyStateProps {
  /** What is not here — always a noun phrase, never "خطا" or "چیزی یافت نشد". */
  title: string;
  /** Why it is empty and what happens next. One sentence. */
  description?: string;
  /** The action that fixes it. An empty state without an action is a dead end. */
  action?: ReactNode;
  icon?: LucideIcon;
  /** "search" softens the copy for a filtered-to-nothing list. */
  variant?: 'empty' | 'search';
  className?: string;
}

/**
 * Empty states are a first-class screen state, not a fallback (design-system rule 6).
 *
 * The copy rule: say what is missing and give the user the next action. "موردی یافت
 * نشد" tells a shop owner nothing; «هنوز گوشی‌ای ثبت نشده — با اسکن IMEI شروع کنید»
 * tells them what to do.
 */
export function EmptyState({
  title,
  description,
  action,
  icon: Icon = InboxIcon,
  variant = 'empty',
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 rounded-card border border-dashed border-border bg-surface/50 px-6 py-12 text-center',
        className
      )}
    >
      <div
        aria-hidden
        className={cn(
          'flex size-12 items-center justify-center rounded-full',
          variant === 'search'
            ? 'bg-muted text-muted-foreground'
            : 'bg-accent text-accent-foreground'
        )}
      >
        <Icon className="size-6" />
      </div>

      <div className="space-y-1">
        <p className="font-display text-base font-bold text-foreground">{title}</p>
        {description && (
          <p className="mx-auto max-w-sm text-xs text-muted-foreground">{description}</p>
        )}
      </div>

      {action && <div className="pt-1">{action}</div>}
    </div>
  );
}
