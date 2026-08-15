import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { cn } from '@/lib/utils';

export interface BarChartPoint {
  /** Already-formatted Jalali label — the chart does no date maths. */
  label: string;
  /** Integer RIAL. Golden rule 2. */
  value: number;
}

export interface BarChartProps {
  points: BarChartPoint[];
  /** Names the series. One series needs no legend — the title is the legend. */
  title: string;
  /** Plot height in px. */
  height?: number;
  /** Shown in the readout when nothing is hovered. Defaults to the sum. */
  emptyLabel?: string;
  className?: string;
}

/**
 * One series of money over time, as bars.
 *
 * ## Why bars and not a line
 *
 * Daily takings are discrete events, not a continuous quantity. A line between the
 * 3rd and the 5th draws a value for the 4th that the shop did not have, and on a
 * 30-day card that interpolation covers every Friday the shop was shut.
 *
 * ## One series, one colour
 *
 * `brand` and nothing else. The visual language has exactly one accent (ADR 0008), so
 * a second series would need either a second accent — which does not exist — or a
 * semantic colour, which means something specific here and would be a lie. If two
 * measures ever need comparing, that is two charts, not two colours on one.
 *
 * ## The readout is fixed, not floating
 *
 * A tooltip that follows the pointer over thirty 6px bars spends most of its life
 * covering the bars either side of the one being read, and at 390px it leaves the
 * card. So the hovered day is reported in a fixed line above the plot, which also
 * makes the chart usable by touch: a tap reads out, rather than needing a hover state
 * a phone cannot produce.
 *
 * ## RTL
 *
 * The bars are a flex row in an RTL document, so the first point renders at the right
 * and time runs right-to-left — the direction a Persian reader's eye travels. Nothing
 * here is positioned physically; changing the document direction mirrors it correctly.
 */
export function BarChart({ points, title, height = 96, emptyLabel, className }: BarChartProps) {
  const [hovered, setHovered] = useState<number | null>(null);

  const max = points.reduce((peak, point) => Math.max(peak, point.value), 0);
  const total = points.reduce((sum, point) => sum + point.value, 0);
  const active = hovered === null ? null : (points[hovered] ?? null);

  return (
    <figure className={cn('flex flex-col gap-3', className)}>
      <figcaption className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <span className="text-sm text-muted-foreground">{active ? active.label : title}</span>
        <span className="text-sm font-semibold">
          {active ? (
            <Money rial={active.value} withUnit />
          ) : max === 0 ? (
            <span className="font-normal text-muted-foreground">
              {emptyLabel ?? 'در این بازه فروشی ثبت نشده است'}
            </span>
          ) : (
            <Money rial={total} withUnit />
          )}
        </span>
      </figcaption>

      {/*
        `items-end` anchors every bar to the baseline; `gap-[2px]` is the surface gap
        that keeps neighbouring fills from reading as one block.
      */}
      <ul
        className="flex items-end gap-[2px]"
        style={{ height: `${height}px` }}
        onPointerLeave={() => setHovered(null)}
      >
        {points.map((point, index) => {
          // A day with sales must be visibly taller than a day without, even when it is
          // a rounding error beside the month's peak — otherwise the chart reports
          // "closed" for a day the shop traded. Zero keeps a ghost stub so the column
          // is still visible and still hoverable.
          const ratio = max === 0 ? 0 : point.value / max;
          const percent = point.value === 0 ? 2 : Math.max(8, Math.round(ratio * 100));

          return (
            <li
              key={point.label}
              className="group relative flex h-full flex-1 items-end"
              onPointerEnter={() => setHovered(index)}
            >
              {/* The hit target is the full column height, not the bar: a 3px bar on a
                  quiet day is unhittable, and the quiet days are the interesting ones. */}
              <span className="sr-only">
                {point.label}: <Money rial={point.value} withUnit />
              </span>

              <span
                aria-hidden
                className={cn(
                  'w-full rounded-t-[4px] bg-brand/25 transition-colors',
                  'group-hover:bg-brand',
                  hovered === index && 'bg-brand'
                )}
                style={{ height: `${percent}%` }}
              />
            </li>
          );
        })}
      </ul>

      <div className="flex justify-between text-xs text-muted-foreground">
        <span className="tabular">{points.at(0)?.label ?? ''}</span>
        <span className="tabular">{points.at(-1)?.label ?? ''}</span>
      </div>
    </figure>
  );
}
