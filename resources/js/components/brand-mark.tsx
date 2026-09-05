import letterH from '../../brand/mark-h.svg?raw';
import wordmark from '../../brand/wordmark.svg?raw';

import { cn } from '@/lib/utils';

export interface BrandMarkProps {
  /** Size it with `h-*`; the wordmark keeps its own aspect ratio (8.87 : 1). */
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
 * ## A drawing, not a typeface
 *
 * `resources/brand/wordmark.svg` is the owner's commissioned design, sent as a raster on
 * 2026-09-05 and re-drawn as paths measured off that artwork — not a face set in caps.
 * The identity is in the letterforms: a cut corner on the H and the M, each A's crossbar
 * standing free below a gap, points where the M meets, a slot through the R. Nothing that
 * ships types those, which is the point of having them.
 *
 * It replaced HAMYAR outlined from Outfit Bold, and it is **8.87 : 1 where that was
 * 6.5 : 1** — 36% wider at the same height. That is why `BrandLetter` exists.
 *
 * The file inherits `currentColor`, so brand blue on white, ink on a light band and white
 * on navy are all one file. Re-derive it from the artwork rather than editing the path
 * data; the method is in `docs/design-system.md` §The mark.
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

/**
 * The wordmark's first letter, for the places the whole word does not fit.
 *
 * The collapsed sidebar rail is 4rem. At a height anyone can read, the wordmark is nearly
 * twice that wide and spills over the page beside it; shrunk to fit, it is 7px tall. One
 * letter at a legible size says more than six clipped ones, and it is the same letter the
 * browser tab already shows, so the rail and the tab agree.
 *
 * Not a second logo — `mark-h.svg` holds the identical path, so this is the wordmark with
 * five letters cropped off, not a monogram somebody drew.
 */
export function BrandLetter({ className }: BrandMarkProps) {
  return (
    <span
      aria-hidden
      className={cn('inline-flex [&>svg]:h-full [&>svg]:w-auto', className)}
      dangerouslySetInnerHTML={{ __html: letterH }}
    />
  );
}
