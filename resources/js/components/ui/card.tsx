import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import type * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * A bordered surface.
 *
 * ## Why this exists
 *
 * `rounded-card` appears at 141 sites across the app in **25 distinct class strings**. Not
 * variations with a reason — variations because each was typed from memory:
 *
 *     rounded-card border border-border p-4          (13x)
 *     rounded-card border border-border              (13x)
 *     rounded-card border border-border bg-surface p-6 sm:p-7   (6x)
 *     rounded-card border border-border bg-card p-6 sm:p-8      (6x)
 *     rounded-card border bg-card p-5                (6x)
 *     rounded-card border p-5                        (3x)
 *     ...
 *
 * Three grounds, five padding scales, and `border` versus `border border-border` — which
 * are the same thing, because the base layer already sets every element's border colour to
 * `--border`. Two of those padding scales differ by 4px at `sm`, which is drift rather than
 * a decision anybody made.
 *
 * ## What it deliberately does not do
 *
 * **No tone.** Nine of those sites are toned callouts — `border-danger/40 bg-danger/5
 * text-danger` and its warning sibling — and they are not cards with a colour, they are
 * notices. Folding them in would give this component two jobs and a `tone` prop that only
 * makes sense for a third of its uses. They stay as they are until a `Notice` earns its own
 * extraction.
 *
 * **No header/title/footer slots.** shadcn's card ships them; nothing in this app renders
 * that shape, and `SettingsSection` already owns the one title-plus-body pattern that
 * recurs. Composing a heading above `children` is not the kind of repetition that needs a
 * component.
 */
const cardVariants = cva('rounded-card border', {
  variants: {
    /**
     * `card` sits on the page ground, `surface` is the settings/section treatment, and
     * `none` leaves the ground to the caller — which is what the thirteen bare
     * `rounded-card border border-border` sites want.
     */
    ground: {
      card: 'bg-card text-card-foreground',
      surface: 'bg-surface text-surface-foreground',
      none: '',
    },
    /**
     * `lg` is `sm:p-7`, matching `SettingsSection` exactly, because it has nine consumers
     * and this phase is not the place to move nine pages by four pixels.
     *
     * There is a second generous step in the codebase — `p-6 sm:p-8`, on six `bg-card`
     * sites including the treasury summary — and the two want reconciling. They are not
     * reconciled here: doing it correctly means looking at both treatments on a rebuilt
     * page, not picking the larger number and repainting screens nobody is reviewing. A
     * card component that silently restyles the app is worse than two padding scales.
     */
    padding: {
      none: '',
      sm: 'p-4',
      md: 'p-5',
      lg: 'p-6 sm:p-7',
    },
    /** Depth is ground contrast first; this is the rare card that also lifts. */
    elevated: {
      true: 'shadow-low',
      false: '',
    },
    /**
     * For a card that is itself the link or button. The states are lifted verbatim from the
     * treasury account card, which is where they were worked out: border first, then ground,
     * then a press that is felt rather than seen.
     */
    interactive: {
      true: 'transition-colors hover:border-border-strong hover:bg-accent/40 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:bg-accent/60',
      false: '',
    },
  },
  defaultVariants: {
    ground: 'card',
    padding: 'md',
    elevated: false,
    interactive: false,
  },
});

export interface CardProps extends React.ComponentProps<'div'>, VariantProps<typeof cardVariants> {
  /** Render as the child element — a `<Link>`, an `<article>`, a `<button>`. */
  asChild?: boolean;
}

export function Card({
  className,
  ground,
  padding,
  elevated,
  interactive,
  asChild = false,
  ...props
}: CardProps) {
  const Comp = asChild ? Slot.Root : 'div';

  return (
    <Comp
      data-slot="card"
      className={cn(cardVariants({ ground, padding, elevated, interactive }), className)}
      {...props}
    />
  );
}

export { cardVariants };
