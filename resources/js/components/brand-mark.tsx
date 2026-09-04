import wordmark from '../../brand/wordmark.svg?raw';

import { cn } from '@/lib/utils';

export interface BrandMarkProps {
  /** Size it with `h-*`; the wordmark keeps its own aspect ratio (6.5 : 1). */
  className?: string;
}

/**
 * The product's logo: HAMYAR, set as a wordmark.
 *
 * ## Why a wordmark and no symbol
 *
 * There was a symbol — two bracket forms making a handset silhouette with the brand dot
 * between them — carried provisionally from the 16.2 gate. The owner retired it on
 * 2026-09-04: «لوگو فعلی سامانمون هم خوب نیست؛ همین اسم سامانه با یک فونت و حالت خاص کافی
 * است». A name a reader can say is worth more than a shape they have to learn, which is
 * why most software in this category signs itself with its name and nothing else.
 *
 * ## Outlines, not text
 *
 * `resources/brand/wordmark.svg` holds HAMYAR converted from IBM Plex Sans Arabic Bold —
 * the product's own typeface (ADR 0020) — to paths, at +62/1000em tracking. Outlines
 * rather than `<text>`: it is crisp at any size, it cannot reflow, and it does not wait
 * for a webfont. Regenerate it rather than editing it by hand; the command is in
 * `docs/design-system.md`.
 *
 * It inherits `currentColor`, so the brand blue on white, the ink on a light band and
 * white on navy are all the same file.
 */
export function BrandMark({ className }: BrandMarkProps) {
  return (
    <span
      aria-hidden
      className={cn('inline-flex [&>svg]:h-full [&>svg]:w-auto', className)}
      // Our own SVG under resources/brand, imported as text at build time.
      dangerouslySetInnerHTML={{ __html: wordmark }}
    />
  );
}
