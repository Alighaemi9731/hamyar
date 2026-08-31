import { cn } from '@/lib/utils';

export type ShareTone = 'brand' | 'success' | 'warning' | 'danger' | 'neutral';

export interface ShareBarProps {
  /** This slice, in the same unit as `total`. Integer rial when it is money. */
  value: number;
  /** The whole the slice is part of. A zero or negative whole renders nothing. */
  total: number;
  tone?: ShareTone;
  className?: string;
}

const TONE_FILL: Record<ShareTone, string> = {
  brand: 'bg-brand',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-muted-foreground',
};

/**
 * One hairline bar saying how big a slice is against its whole.
 *
 * ## Why a bar rather than the percentage alone
 *
 * Six balances in Persian digits can only be ranked by counting digit groups. A bar is
 * read pre-attentively — the eye finds the biggest without reading a single number,
 * which is the whole job when somebody opens a treasury page to see where the money
 * actually is.
 *
 * ## It is decoration for the screen reader, deliberately
 *
 * `aria-hidden`, because every caller states the same proportion in text beside it. A
 * bar that also announced itself would read the figure twice.
 *
 * ## Guards, because money is signed
 *
 * A whole of zero has no slices, so the component renders nothing rather than dividing
 * by zero. A negative slice — an overdrawn account — clamps to an empty track rather
 * than a negative width: the card beside it already colours the figure, and a bar that
 * grew leftwards would read as "large" for the one case that means the opposite.
 */
export function ShareBar({ value, total, tone = 'brand', className }: ShareBarProps) {
  if (total <= 0) {
    return null;
  }

  const fraction = Math.min(Math.max(value / total, 0), 1);

  return (
    <div
      aria-hidden
      // `border-strong`, not `muted`: in the dark theme `--muted` and `--card` are both
      // #1d1d1f, so a track painted with `bg-muted` *was* the card behind it — measured
      // at 1:1. The bar lost its "out of what": a 10% slice read as a floating blue dash
      // and a zero slice disappeared. The border tokens are alpha, so they composite
      // visibly over whatever ground they land on, in either theme.
      className={cn('h-1 w-full overflow-hidden rounded-pill bg-border-strong', className)}
    >
      <div
        className={cn('h-full rounded-pill transition-[width] duration-500', TONE_FILL[tone])}
        style={{ width: `${fraction * 100}%` }}
      />
    </div>
  );
}

/**
 * The percentage a `ShareBar` is showing, rounded for display.
 *
 * Exported so a caller cannot round it differently from the bar it sits under — a label
 * reading «۰٪» beside a visible sliver is the kind of small dishonesty that makes a
 * whole screen feel untrustworthy. Anything above zero floors at 1.
 */
export function sharePercent(value: number, total: number): number {
  if (total <= 0 || value <= 0) {
    return 0;
  }

  const exact = (value / total) * 100;

  return exact < 1 ? 1 : Math.round(exact);
}
