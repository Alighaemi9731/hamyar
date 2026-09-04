import { Link, router } from '@inertiajs/react';
import { ArrowLeftIcon, CheckIcon, XIcon } from 'lucide-react';

import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface SetupStep {
  key: string;
  label: string;
  description: string;
  /** The screen that completes the step. */
  href: string;
  done: boolean;
}

export interface SetupProgress {
  steps: SetupStep[];
  done: number;
  total: number;
}

/**
 * The first morning's checklist, on the dashboard until the shop is set up.
 *
 * ## One next step, lit
 *
 * Six things to do is a list; one thing to do next is a plan. The first undone step
 * carries the brand border and the filled button, the rest stay outlined, and the done
 * ones fold to a tick and a muted line. The order is the shop's own — a product before a
 * purchase, a purchase before a sale — so "next" is usually also "possible".
 *
 * ## «بعداً» is a tenant setting
 *
 * Dismissing is a POST that the server remembers for the shop, not `localStorage`: the
 * owner who closes it on the counter PC should not meet it again on their phone, and
 * the manager they invite tomorrow should not meet it at all.
 */
export function SetupChecklist({ setup }: { setup: SetupProgress }) {
  const next = setup.steps.find((step) => !step.done);

  return (
    <section
      aria-labelledby="setup-title"
      className="reveal rounded-card border border-border bg-card p-6 shadow-low sm:p-8"
    >
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-2xs font-medium tracking-wide text-muted-foreground">
            راه‌اندازی فروشگاه
          </p>
          <h2 id="setup-title" className="mt-1 font-display text-lg font-bold">
            <Num value={setup.done} variant="prose" /> از{' '}
            <Num value={setup.total} variant="prose" /> قدم انجام شده
          </h2>
        </div>

        {/* form-errors-allow: a bare POST with no fields. The server has no bag to send
            back; a refusal is a 403 page, not a key nobody placed. */}
        <Button
          type="button"
          variant="ghost"
          onClick={() => router.post('/dashboard/setup/dismiss', {}, { preserveScroll: true })}
        >
          <XIcon aria-hidden />
          بعداً
        </Button>
      </div>

      <div
        role="progressbar"
        aria-label="پیشرفت راه‌اندازی"
        aria-valuemin={0}
        aria-valuemax={setup.total}
        aria-valuenow={setup.done}
        className="mt-4 h-1.5 overflow-hidden rounded-pill bg-muted"
      >
        <div
          className="h-full rounded-pill bg-brand transition-[width] duration-(--duration-base) ease-(--ease-out)"
          style={{ width: `${(setup.done / setup.total) * 100}%` }}
        />
      </div>

      <ol className="mt-6 grid gap-3 md:grid-cols-2">
        {setup.steps.map((step, index) => {
          const isNext = step === next;

          return (
            <li
              key={step.key}
              className={cn(
                'flex items-center gap-3 rounded-control border p-3 sm:p-4',
                step.done && 'border-border bg-surface/50',
                isNext && 'border-brand/40 bg-brand/5',
                !step.done && !isNext && 'border-border'
              )}
            >
              <span
                aria-hidden
                className={cn(
                  'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                  step.done ? 'bg-success/15 text-success' : 'bg-muted text-muted-foreground'
                )}
              >
                {step.done ? <CheckIcon className="size-4" /> : <Num value={index + 1} />}
              </span>

              <span className="min-w-0 flex-1">
                <span
                  className={cn(
                    'block text-sm font-semibold',
                    step.done && 'font-medium text-muted-foreground'
                  )}
                >
                  {step.label}
                  {step.done && <span className="sr-only"> — انجام شد</span>}
                </span>
                {!step.done && (
                  <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                    {step.description}
                  </span>
                )}
              </span>

              {!step.done && (
                <Button asChild variant={isNext ? 'default' : 'outline'} className="shrink-0">
                  <Link href={step.href}>
                    {isNext ? 'شروع' : 'رفتن'}
                    {/* Points to the reading end — physical left in RTL — without a flip. */}
                    <ArrowLeftIcon aria-hidden />
                  </Link>
                </Button>
              )}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
