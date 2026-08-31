import type { LucideIcon } from 'lucide-react';
import { InboxIcon, LockIcon } from 'lucide-react';
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
  /**
   * - `empty` — nothing here yet, and the action creates the first one.
   * - `search` — a filter matched nothing; softer copy, and the term echoed back.
   * - `permission` — there *is* something here and this account may not see it.
   *
   * `permission` is a different screen state from `empty`, and conflating them is how a
   * shop concludes their data is gone. Three screens already wrote it by hand — the
   * settings hub, the dashboard and the report index — each with the same shape: name the
   * permission, and name who can grant it. It is the manager, never support, and never a
   * dead end.
   */
  variant?: 'empty' | 'search' | 'permission';
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
  icon,
  variant = 'empty',
  className,
}: EmptyStateProps) {
  // A padlock reads as "not yours to see" without a word being read, which is the whole
  // job when the sentence beneath it is the one people skip.
  const Icon = icon ?? (variant === 'permission' ? LockIcon : InboxIcon);

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
          variant === 'empty' && 'bg-accent text-accent-foreground',
          variant === 'search' && 'bg-muted text-muted-foreground',
          // Muted rather than `danger`: being outside a permission is an ordinary fact
          // about a role, not a failure, and painting it red tells a salesperson they
          // have broken something by opening the reports page.
          variant === 'permission' && 'bg-muted text-muted-foreground'
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
