'use client';

import { CheckIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';

import { cn } from '@/lib/utils';

/**
 * The one checkbox.
 *
 * ## What it replaces
 *
 * Fourteen hand-rolled copies of the same six lines, across Storefront, POS, trade-in,
 * returns, products, labels, parties, branches, stock counts and the treasury statement:
 *
 * ```tsx
 * <label className="flex items-center gap-2 text-sm">
 *   <input type="checkbox" className="size-4 accent-primary" checked={x} onChange={…} />
 *   <span>…</span>
 * </label>
 * ```
 *
 * They had already drifted — `gap-2` and `gap-3`, `items-center` and `items-start` and
 * `items-end`, four of them wrapped in a `min-h-11` label reaching for the touch floor by
 * hand and ten of them not.
 *
 * ## `size-4` is a 16px target, and the floor is 40
 *
 * That is the defect underneath the duplication. A 16px box is a quarter of the area rule 9
 * requires, and it is the control a shopkeeper taps to say a returned handset may go back
 * on the shelf, or that a price list is public.
 *
 * The fix is not a bigger box — a 40px tick would look absurd next to a 40px input. **The
 * label is the target.** The row is `min-h-10` and the whole of it, box and text together,
 * activates the control; the box itself stays a 20px mark. That is also why `label` is a
 * prop rather than something callers compose: a checkbox whose label is not part of its hit
 * area is the bug this component exists to stop being re-typed.
 *
 * The unlabelled form — a table cell, a toolbar — has no row to grow into, so it carries a
 * transparent `::before` that extends its hit area to 40px without changing what the layout
 * sees. `data-hit-area="expanded"` says so out loud, because a pseudo-element is invisible
 * to `getBoundingClientRect` and therefore to any tooling that measures touch targets: the
 * attribute is how an automated sweep can tell a real 20px target from this one. Verified by
 * clicking 15px clear of the box and watching it toggle.
 *
 * ## Why Radix rather than a styled native input
 *
 * `accent-primary` cannot be themed past the browser's own rendering, so the fourteen
 * copies were the one control in the product that did not follow the token layer — visibly
 * so in dark mode. Radix's Root is a `<button role="checkbox">`, and `button` is a labelable
 * element, so `htmlFor`/`id` association works exactly as it does for an input; it also
 * renders a hidden native input inside a form, so anything that submits keeps working.
 */
export interface CheckboxProps extends Omit<
  React.ComponentProps<typeof CheckboxPrimitive.Root>,
  'children'
> {
  /**
   * The text beside the box, and part of the target.
   *
   * Optional only for a checkbox in a table cell or a toolbar, where the accessible name
   * comes from `aria-label` and the surrounding row supplies the meaning.
   */
  label?: ReactNode;
  /** A second, quieter line — what ticking this actually does. */
  description?: ReactNode;
  /** Applied to the row, not the box. */
  className?: string;
}

export function Checkbox({ label, description, className, ...props }: CheckboxProps) {
  const box = (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      data-hit-area="expanded"
      className={cn(
        'peer relative size-5 shrink-0 rounded-[6px] border border-input bg-transparent shadow-none transition-colors outline-none',
        // A 42px target around a 20px mark; the layout still measures 20px, which is why
        // the box sits correctly beside its label. `-inset-[11px]` rather than `-inset-2.5`
        // because the box is border-box 20px and the inset resolves against its padding
        // box — 10px each side measured 38px, just under the floor it exists to clear.
        "before:absolute before:-inset-[11px] before:content-['']",
        'focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50',
        'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20',
        label === undefined && className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator className="flex items-center justify-center text-current">
        <CheckIcon className="size-3.5" strokeWidth={3} aria-hidden />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  );

  if (label === undefined) {
    return box;
  }

  return (
    // `items-start` with `pt-*` on the box rather than `items-center`: a two-line label
    // would otherwise centre the box against the whole block and leave it floating beside
    // the gap between the lines.
    <label
      className={cn(
        'flex min-h-10 cursor-pointer items-start gap-2.5 py-1.5 text-sm select-none',
        'has-disabled:cursor-not-allowed has-disabled:opacity-60',
        className
      )}
    >
      <span className="flex h-7 shrink-0 items-center">{box}</span>

      <span className="min-w-0 self-center">
        <span className="block">{label}</span>
        {description && (
          <span className="mt-0.5 block text-2xs text-muted-foreground">{description}</span>
        )}
      </span>
    </label>
  );
}
