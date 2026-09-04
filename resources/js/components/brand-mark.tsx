import mark from '../../brand/mark.svg?raw';

import { cn } from '@/lib/utils';

export interface BrandMarkProps {
  /**
   * `color` keeps the brand dot; `mono` hands the dot to `currentColor` so the mark
   * holds on a brand-filled tile, where a brand dot would vanish into its ground.
   */
  tone?: 'color' | 'mono';
  /** Size it with `size-*`; the SVG fills the box. */
  className?: string;
}

/**
 * The product's mark, from `resources/brand/mark.svg` and nowhere else.
 *
 * Imported as text at build time and inlined, so it inherits `currentColor` and needs no
 * request, no `<img>` sizing, and no second copy for dark mode. The source is our own
 * file under `resources/brand`; no user input reaches the attribute below.
 *
 * The file is provisional until Gate 16.2 chooses a candidate — the choice is a file
 * swap, which is the point of having one import site.
 */
export function BrandMark({ tone = 'color', className }: BrandMarkProps) {
  return (
    <span
      aria-hidden
      className={cn(
        'inline-flex shrink-0 [&>svg]:size-full',
        tone === 'mono' && '[&_circle]:fill-current',
        className
      )}
      dangerouslySetInnerHTML={{ __html: mark }}
    />
  );
}
