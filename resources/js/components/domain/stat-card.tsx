import type { LucideIcon } from 'lucide-react';
import { TrendingDownIcon, TrendingUpIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type StatTone = 'neutral' | 'success' | 'warning' | 'danger';

/**
 * The card's identity colour.
 *
 * A hue says *what this card is about* — it is not a state. The three semantics stay
 * out of this list on purpose: there is exactly one green, one amber and one red in
 * this product and they mean paid, due and overdue. A card whose figure is genuinely
 * bad news asks for `tone="danger"`, which is a different axis.
 */
export type StatAccent =
  | 'blue'
  | 'indigo'
  | 'violet'
  | 'magenta'
  | 'rose'
  | 'orange'
  | 'amber'
  | 'lime'
  | 'emerald'
  | 'teal'
  | 'cyan'
  | 'slate';

export interface StatCardProps {
  label: string;
  /**
   * Integer RIAL when the figure is money; a plain count otherwise.
   *
   * **Null is not zero.** A card for a figure nobody has decided yet — an unset credit
   * limit, an unmeasured target — renders an em-dash. Printing `۰` there states a
   * decision the shop never made, which is the same null-vs-zero distinction the
   * columns behind these cards are careful to keep.
   */
  value: number | null;
  /** Renders through `<Money/>` instead of `<Num/>`. */
  isMoney?: boolean;
  /** Short qualifier under the figure — "۱۲ فروشگاه فعال". */
  hint?: string;
  icon?: LucideIcon;
  /**
   * Percentage change against the previous period. Positive is not automatically
   * good — see `invertTrend`.
   */
  trend?: number;
  /**
   * Flips the trend colouring for figures where up is bad: returns, overdue
   * balances, shrinkage. Without it, a rising bad number renders green.
   */
  invertTrend?: boolean;
  tone?: StatTone;
  /** Identity colour for the icon tile and the corner wash. Defaults to the brand blue. */
  accent?: StatAccent;
  className?: string;
}

const TONE_RING: Record<StatTone, string> = {
  neutral: 'border-border',
  success: 'border-success/25',
  warning: 'border-warning/25',
  danger: 'border-danger/25',
};

const TONE_ICON: Record<StatTone, string> = {
  neutral: 'text-muted-foreground',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
};

/**
 * One number, with just enough context to be worth putting on a dashboard.
 *
 * Deliberately spare: a label, a figure, an optional qualifier and an optional trend.
 * Dashboards fail by crowding, and every extra element here is repeated four or five
 * times across a row.
 *
 * `invertTrend` exists because "up" is not universally good. Overdue balances, returns
 * and shrinkage rising is bad news, and rendering that in green is worse than showing
 * no colour at all.
 */
export function StatCard({
  label,
  value,
  isMoney = false,
  hint,
  icon: Icon,
  trend,
  invertTrend = false,
  tone = 'neutral',
  accent = 'blue',
  className,
}: StatCardProps) {
  const hasTrend = typeof trend === 'number' && trend !== 0;
  const rising = (trend ?? 0) > 0;
  const good = invertTrend ? !rising : rising;
  const TrendIcon = rising ? TrendingUpIcon : TrendingDownIcon;

  return (
    <Card asChild className={cn('stat-card', TONE_RING[tone], className)}>
      <article data-accent={accent}>
        <div className="flex items-start justify-between gap-3">
          <p className="text-sm text-muted-foreground">{label}</p>
          {/*
            The mark sits on a tile rather than floating as a bare glyph — the same
            object as a sidebar chip, lit the same way, so a dashboard and the nav read
            as one system. `tone` still wins when it is not neutral: a card about an
            overdue balance shows red whatever its identity hue is, because a state
            outranks an identity.
          */}
          {Icon ? (
            <span
              aria-hidden
              className={cn(
                'stat-tile grid size-11 shrink-0 place-items-center rounded-chip',
                tone !== 'neutral' && TONE_ICON[tone]
              )}
            >
              <Icon className="size-5" strokeWidth={2.25} />
            </span>
          ) : null}
        </div>

        {/*
        Sized down from 2xl, with the unit stacked underneath. A nine-digit toman figure
        plus its unit needs ~270px and a quarter-width card at 1280 gives 158 — it
        overflowed and was overlapped by the neighbouring card until a browser check
        measured it. The pair has no break opportunity between them, so wrapping alone
        did not help; the unit has to leave the line.
      */}
        <p className="mt-3 text-xl font-semibold tracking-tight">
          {value === null ? (
            <span className="text-muted-foreground" aria-label="تعیین نشده">
              —
            </span>
          ) : isMoney ? (
            <Money rial={value} withUnit unitPlacement="block" />
          ) : (
            <Num value={value} />
          )}
        </p>

        {hint || hasTrend ? (
          <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            {hasTrend ? (
              <span
                className={cn(
                  'inline-flex items-center gap-1',
                  good ? 'text-success' : 'text-danger'
                )}
              >
                <TrendIcon className="size-4" aria-hidden />
                {/* bdi: a bare sign next to Persian text jumps to the wrong side. */}
                <bdi className="tabular">
                  <Num value={Math.abs(trend ?? 0)} />٪
                </bdi>
              </span>
            ) : null}

            {hint ? <span className="text-muted-foreground">{hint}</span> : null}
          </div>
        ) : null}
      </article>
    </Card>
  );
}
