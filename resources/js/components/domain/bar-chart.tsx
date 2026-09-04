import { useEffect, useRef, useState } from 'react';

import { Money } from '@/components/domain/money';
import { toPersianDigits } from '@/lib/digits';
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
  /** Plot height in px, gridlines included. */
  height?: number;
  /** Shown in the readout when nothing is hovered. Defaults to the sum. */
  emptyLabel?: string;
  className?: string;
}

/** Bars never fill their slot; the band's leftover is air (dataviz mark spec). */
const MAX_BAR = 24;
/** The surface gap between touching marks, in the surface colour. */
const GAP = 2;
/** The rounded data-end; the baseline end stays square. */
const RADIUS = 4;
/** Room for the tick labels on the reading edge. */
const AXIS = 56;

/**
 * One series of money over time, as bars — drawn, not stacked divs.
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
 * ## What the SVG buys over the div version it replaces
 *
 * A scale. The div bars had no axis, so a reader could see the shape of the month and
 * not one number on it; the 2026-09-03 baseline called it a stub. Now three gridlines
 * carry clean tick values, the peak day is labelled at its cap, bars are capped at
 * 24px thick with a 4px rounded top and a square base, and neighbours are separated by
 * a 2px surface gap rather than a border — the mark specs a chart is read by.
 *
 * ## The readout is fixed, not floating
 *
 * A tooltip that follows the pointer over thirty 6px bars spends most of its life
 * covering the bars either side of the one being read, and at 390px it leaves the
 * card. So the hovered day is reported in a fixed line above the plot, which also
 * makes the chart usable by touch: a tap reads out, rather than needing a hover state
 * a phone cannot produce. The hit target is the whole column, not the bar.
 *
 * ## RTL
 *
 * SVG geometry does not follow `direction`, so the first point is placed at the right
 * edge explicitly and time runs right-to-left — the direction a Persian reader's eye
 * travels. Tick labels sit on the reading edge for the same reason.
 */
export function BarChart({ points, title, height = 120, emptyLabel, className }: BarChartProps) {
  const [hovered, setHovered] = useState<number | null>(null);
  const [width, setWidth] = useState(0);
  const host = useRef<HTMLDivElement>(null);

  // Draw in real pixels: text inside a scaled viewBox would scale with it.
  useEffect(() => {
    const element = host.current;

    if (!element) return;

    const observer = new ResizeObserver(([entry]) => {
      if (entry) setWidth(Math.round(entry.contentRect.width));
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const max = points.reduce((peak, point) => Math.max(peak, point.value), 0);
  const total = points.reduce((sum, point) => sum + point.value, 0);
  const active = hovered === null ? null : (points[hovered] ?? null);
  const peakIndex = max === 0 ? -1 : points.findIndex((point) => point.value === max);

  const ceiling = niceCeiling(max);
  const ticks = ceiling === 0 ? [0] : [0, ceiling / 2, ceiling];

  const plotWidth = Math.max(0, width - AXIS);
  const plotTop = 18; // room for the peak label above the tallest bar
  const plotBottom = height - 4;
  const plotHeight = plotBottom - plotTop;
  const slot = points.length === 0 ? 0 : plotWidth / points.length;
  const barWidth = Math.max(2, Math.min(MAX_BAR, slot - GAP));

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

      <div ref={host} className="w-full" onPointerLeave={() => setHovered(null)}>
        {/* A screen reader gets the list; the drawing is presentational. */}
        <ul className="sr-only">
          {points.map((point) => (
            <li key={point.label}>
              {point.label}: <Money rial={point.value} withUnit />
            </li>
          ))}
        </ul>

        {width > 0 && (
          <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            aria-hidden
            className="block overflow-visible"
          >
            {/* Gridlines and their ticks: clean numbers, recessive ink, labels on the
                reading edge. They carry the values the bars do not say. */}
            {ticks.map((tick) => {
              const y = ceiling === 0 ? plotBottom : plotBottom - (tick / ceiling) * plotHeight;

              return (
                <g key={tick}>
                  <line
                    x1={0}
                    x2={plotWidth}
                    y1={y}
                    y2={y}
                    className={tick === 0 ? 'stroke-border-strong' : 'stroke-border'}
                    strokeWidth={1}
                  />
                  {/* `text-anchor` follows reading direction: in this RTL drawing `start` pins
                      the label's right edge to the axis; `end` would start it there and run it
                      off the card. */}
                  <text
                    x={width}
                    y={y}
                    dy={tick === ceiling ? 10 : tick === 0 ? -3 : 4}
                    textAnchor="start"
                    className="tabular fill-muted-foreground text-2xs"
                  >
                    {compactToman(tick)}
                  </text>
                </g>
              );
            })}

            {points.map((point, index) => {
              // First point on the right: time runs the way the eye does.
              const slotStart = plotWidth - (index + 1) * slot;
              const x = slotStart + (slot - barWidth) / 2;
              // A day with sales must be visibly taller than a day without, even when it
              // is a rounding error beside the month's peak — otherwise the chart reports
              // "closed" for a day the shop traded. Zero keeps a 2px stub.
              const ratio = ceiling === 0 ? 0 : point.value / ceiling;
              const barHeight = point.value === 0 ? 2 : Math.max(6, ratio * plotHeight);
              const y = plotBottom - barHeight;
              const isActive = hovered === index;
              const isPeak = index === peakIndex && hovered === null;

              return (
                <g key={point.label}>
                  {/* The hit target is the full column; the mark is what it lights. */}
                  <rect
                    x={slotStart}
                    y={plotTop - 12}
                    width={slot}
                    height={plotHeight + 12}
                    fill="transparent"
                    onPointerEnter={() => setHovered(index)}
                    onPointerDown={() => setHovered(index)}
                  />
                  <path
                    d={roundedTop(
                      x,
                      y,
                      barWidth,
                      barHeight,
                      Math.min(RADIUS, barWidth / 2, barHeight)
                    )}
                    className={cn(
                      'transition-[fill] duration-(--duration-fast) ease-(--ease-out)',
                      isActive || isPeak ? 'fill-brand' : 'fill-brand/25'
                    )}
                    style={{ pointerEvents: 'none' }}
                  />
                  {isPeak && (
                    <text
                      x={x + barWidth / 2}
                      y={y - 5}
                      textAnchor="middle"
                      className="tabular fill-foreground text-2xs font-semibold"
                    >
                      {compactToman(point.value)}
                    </text>
                  )}
                </g>
              );
            })}
          </svg>
        )}
      </div>

      <div
        className="flex justify-between text-xs text-muted-foreground"
        style={{ paddingInlineStart: AXIS }}
      >
        <span className="tabular">{points.at(0)?.label ?? ''}</span>
        <span className="tabular">{points.at(-1)?.label ?? ''}</span>
      </div>
    </figure>
  );
}

/** A bar with a rounded data-end and a square base, as a path. */
function roundedTop(x: number, y: number, w: number, h: number, r: number): string {
  if (h <= r) {
    return `M${x},${y + h} v${-h} h${w} v${h} z`;
  }

  return [
    `M${x},${y + h}`,
    `v${-(h - r)}`,
    `a${r},${r} 0 0 1 ${r},${-r}`,
    `h${w - 2 * r}`,
    `a${r},${r} 0 0 1 ${r},${r}`,
    `v${h - r}`,
    'z',
  ].join(' ');
}

/** The next clean number above a value: 1, 2 or 5 × 10^n, so ticks read as round. */
function niceCeiling(value: number): number {
  if (value <= 0) return 0;

  const magnitude = 10 ** Math.floor(Math.log10(value));
  const leading = value / magnitude;
  const step = leading <= 1 ? 1 : leading <= 2 ? 2 : leading <= 5 ? 5 : 10;

  return step * magnitude;
}

/**
 * Rial → a tick label in toman, compact: «۱۲۰ میلیون», «۴۵۰ هزار», «۰». Ticks carry a
 * scale, not a figure; the exact value lives in the readout and the sr-only list.
 */
function compactToman(rial: number): string {
  const toman = Math.round(rial / 10);

  if (toman === 0) return toPersianDigits('0');
  if (toman >= 1_000_000_000) return `${toPersianDigits(trim(toman / 1_000_000_000))} میلیارد`;
  if (toman >= 1_000_000) return `${toPersianDigits(trim(toman / 1_000_000))} میلیون`;
  if (toman >= 1_000) return `${toPersianDigits(trim(toman / 1_000))} هزار`;

  return toPersianDigits(String(toman));
}

/** One decimal at most, with the Persian decimal separator («۱۲۲٫۹»), never a Latin point. */
function trim(value: number): string {
  return (Math.round(value * 10) / 10).toString().replace('.', '٫');
}
