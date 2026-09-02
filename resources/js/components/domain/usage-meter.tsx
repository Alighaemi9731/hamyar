import { Num } from '@/components/domain/num';
import { cn } from '@/lib/utils';
import type { UsageMeterState } from '@/types';

const TONE = {
  // `bg-muted-foreground/15`, not `bg-muted`: the meter sits on a card, and in dark mode
  // `bg-muted` is the card's own ground — the track vanished and «۱ از ۶۰» had no bar,
  // only a 6px thumb floating on nothing. The alpha idiom the other tones already use
  // reads against either ground.
  ok: { track: 'bg-muted-foreground/15', fill: 'bg-brand', text: 'text-muted-foreground' },
  warning: { track: 'bg-warning/15', fill: 'bg-warning', text: 'text-warning' },
  reached: { track: 'bg-warning/15', fill: 'bg-warning', text: 'text-warning' },
  blocked: { track: 'bg-danger/15', fill: 'bg-danger', text: 'text-danger' },
} as const;

export interface UsageMeterProps {
  meter: UsageMeterState;
  /** One line, for the dashboard row and the shell. The full form is for /billing. */
  compact?: boolean;
  className?: string;
}

/**
 * How much of one monthly credit a shop has spent.
 *
 * ## Why the bar is hand-rolled
 *
 * A progress bar is four lines of CSS and a `role="meter"`, and the shadcn component
 * would arrive with its own colour scale to reconcile with the design tokens. The tones
 * here have to mean the same thing as everywhere else in the product — amber is "look at
 * this", red is "this stopped you" — and a component that brings its own palette is how
 * two reds end up on one screen.
 *
 * ## Red only after a refusal
 *
 * `level` comes from the server so one rule decides it everywhere. A meter at 100% is
 * amber, not red: a shop that spent exactly what it bought and went home has done nothing
 * wrong, and a red bar tells it otherwise. Red is reserved for a credit that has actually
 * refused work — the server knows, because the refusal stamped `blocked_at`.
 *
 * An unlimited credit renders as «نامحدود» with no bar at all. A bar with no end is a
 * decoration pretending to be information.
 */
export function UsageMeter({ meter, compact = false, className }: UsageMeterProps) {
  const tone = TONE[meter.level];
  const limit = meter.limit;
  const unlimited = limit === null;
  const ratio = limit === null ? 0 : Math.min(1, limit === 0 ? 1 : meter.used / limit);

  return (
    <div className={cn('min-w-0', className)}>
      <div className="flex items-baseline justify-between gap-2 text-sm">
        <span className="truncate">{meter.label}</span>

        <span className={cn('shrink-0 tabular', unlimited ? 'text-muted-foreground' : tone.text)}>
          {unlimited ? (
            'نامحدود'
          ) : (
            <>
              <Num value={meter.used} /> از <Num value={limit ?? 0} />
            </>
          )}
        </span>
      </div>

      {unlimited ? null : (
        <div
          className={cn('mt-1.5 h-1.5 w-full overflow-hidden rounded-full', tone.track)}
          role="meter"
          aria-label={meter.label}
          aria-valuenow={meter.used}
          aria-valuemin={0}
          aria-valuemax={limit ?? 0}
        >
          <div
            className={cn('h-full rounded-full transition-[width] duration-300', tone.fill)}
            style={{ inlineSize: `${Math.round(ratio * 100)}%` }}
          />
        </div>
      )}

      {compact || meter.window !== 'month' ? null : (
        <p className="mt-1 text-xs text-muted-foreground">
          {meter.unit} در ماه · تازه می‌شود {formatReset(meter.resets_at)}
        </p>
      )}
    </div>
  );
}

/**
 * A date, not a countdown. A month is long enough that «۹ روز دیگر» is a number nobody
 * plans around, while «۱ مهر» is one a shopkeeper already has in their head.
 */
function formatReset(resetsAt: string | null): string {
  if (resetsAt === null) {
    return '—';
  }

  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    day: 'numeric',
    month: 'long',
  }).format(new Date(resetsAt));
}
