import type { ReactNode } from 'react';

import { Money } from '@/components/domain/money';
import { cn } from '@/lib/utils';

/**
 * A column of money that actually lines up.
 *
 * ## The defect
 *
 * `flex justify-between` pins each row's *label* and lets the figure float to whatever
 * width its digits need, so a column of amounts ends up with as many right edges as it has
 * rows — measured on the invoice summary at 1440, the figures scattered across **99px**,
 * and «گرد کردن ۱» sat under the leading 8 of 88,970,000.
 *
 * `tabular-nums` cannot rescue that. It equalises digit *widths*; it does not give the
 * numbers a shared edge to align on. A two-column grid does.
 *
 * ## Three decisions that look like details and are not
 *
 * **`text-start` in the value cell.** In RTL that resolves to physical right, which is
 * where Latin numerals must align so their units digits line up. `text-end` would align
 * their most-significant digits and leave the units ragged — the same bug `DataTable`'s
 * `numeric` flag carries a paragraph about.
 *
 * **A fixed `9ch` track, not `max-content`.** `max-content` derives the track from the
 * widest figure *in that grid*, so two ladders in one rail landed on axes 6px apart, and
 * the axis moved when the data did — a shorter ladder shifted it 2.25px. `9ch` is the same
 * in every ladder on every screen. `ch` is the width of a `0` in the current font, so it
 * scales with the type rather than being a magic pixel count.
 *
 * **The gap is padding, not `gap-x`.** A column gap is unruled, so a `border-t` set on both
 * cells drew as two segments with a 24px notch between them. `ps-6` on the value cell puts
 * the space *inside* a cell the border crosses, and the rule runs continuously.
 *
 * ## Why it lives here now
 *
 * It was two constants inside `Sales/invoice/summary.tsx`, correct and unreachable. The
 * treasury day-close renders a profit-and-loss ladder — revenue, cost of goods, gross
 * margin, net profit — with `flex justify-between`, which is the defect above, on the one
 * screen in the product whose entire job is arithmetic somebody checks by eye.
 */
export function MoneyLadder({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <dl className={cn('grid grid-cols-[1fr_9ch] items-baseline gap-y-1.5', className)}>
      {children}
    </dl>
  );
}

export interface MoneyRowProps {
  label: string;
  /** Integer rial, as everything money is (golden rule 2). */
  rial: number;
  /** A semantic class for a row that means something — a loss, a total. */
  tone?: string;
  /** Rules both cells, opening a new group without splitting the list into a second grid. */
  divider?: boolean;
  /**
   * Latin tabular digits by default, because a ladder is a column and design-system rule 4
   * gives columns Latin figures so they align on their stems. A ladder inside prose can
   * override it.
   */
  digits?: 'fa' | 'latin';
  /** Colour a negative as a loss. Off by default — not every negative is bad. */
  signed?: boolean;
  withUnit?: boolean;
}

/** One rung. Renders two grid cells, so it must be a direct child of {@see MoneyLadder}. */
export function MoneyRow({
  label,
  rial,
  tone,
  divider = false,
  digits = 'latin',
  signed = false,
  withUnit = false,
}: MoneyRowProps) {
  const rule = divider ? 'mt-1 border-t border-border pt-3' : undefined;

  return (
    <>
      <dt className={cn('text-muted-foreground', rule, tone && `${tone} font-medium`)}>{label}</dt>
      <dd className={cn('ps-6 text-start tabular', rule, tone && `${tone} font-semibold`)}>
        <Money rial={rial} digits={digits} signed={signed} withUnit={withUnit} />
      </dd>
    </>
  );
}
