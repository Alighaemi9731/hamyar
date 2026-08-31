import { Link } from '@inertiajs/react';
import { ArrowRightIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface PageHeaderProps {
  /** The `<h1>`. Exactly one per page — see the note below. */
  title: string;
  /**
   * Context the title alone does not carry: «فاکتور فروش» above `INV-۰۰۴۲۱`, «شعبهٔ
   * مرکزی» above a stock count. Reads before the title and is deliberately quiet.
   */
  eyebrow?: string;
  /** One sentence. What this screen is for, when the title cannot say it alone. */
  description?: string;
  /** Where "up" is. A detail screen without one is a dead end on a phone. */
  back?: { href: string; label: string };
  /** Status badges, dates, counts — the facts about *this* record. */
  meta?: ReactNode;
  /** The page's actions. One brand-filled button among them, at most. */
  actions?: ReactNode;
  /**
   * The heading element. **Leave this alone on a page.**
   *
   * It exists for one caller: `/design`, which renders three specimens side by side and
   * would otherwise put three `<h1>`s in a document that already has the gallery's own —
   * the component built to enforce one heading per page, breaking it in the surface that
   * demonstrates it. A specimen of a page header is genuinely not that page's heading.
   *
   * Anywhere else, a lower level means the screen has no `<h1>`, which is the defect this
   * component exists to remove. `AppShell`'s union cannot catch that one, so it is written
   * down here instead.
   */
  headingLevel?: 'h1' | 'h2';
  className?: string;
}

/**
 * A page's own header, for screens that need more than a title.
 *
 * ## The defect this exists to end
 *
 * `AppShell` takes a `title` and renders the page's `<h1>`. That covers a list screen and
 * nothing else, so thirteen pages went around it:
 *
 * - **Five** passed no `title` at all and hand-rolled their own `<h1>` — Cheques,
 *   Collections, Messaging, and both Treasury sub-screens — which means those pages have
 *   no heading in the shell's own row and their heading scrolls away with the content.
 * - **Eight** passed a `title` *and* rendered a second `<h1>`, so the document had two
 *   page headings. Since the shell's title was demoted to 28px in `#64`, the louder of the
 *   two was usually the duplicate, at 40px.
 *
 * Both are the same missing thing: nowhere to put an eyebrow, a description, a back link
 * or a row of status badges. Given no slot, a page builds its own header and the shell's
 * becomes redundant.
 *
 * ## One `<h1>`, enforced by the type system
 *
 * `AppShell` accepts `title` **or** `header`, never both — a discriminated union, so
 * passing both is a compile error rather than a code-review note. This component renders
 * the `<h1>`; the shell renders none when a header is supplied.
 *
 * ## The title keeps the shell's size
 *
 * 21px on a phone, 28px from `sm`, matching `AppShell` exactly. A page header is still
 * chrome, and `text-2xl` (40px) and `text-3xl` (56px) stay reserved for the one figure a
 * screen exists to show — a treasury total, an invoice's grand total. A header that takes
 * the 40px step is a header competing with its own page.
 */
export function PageHeader({
  title,
  eyebrow,
  description,
  back,
  meta,
  actions,
  headingLevel: Heading = 'h1',
  className,
}: PageHeaderProps) {
  return (
    <div className={cn('no-print mb-10', className)}>
      {back && (
        // Above everything, because it is where you came from rather than part of what
        // you arrived at. The arrow points to the reading start — physical right in RTL —
        // so it needs no `rtl:` flip; `ArrowRight` already points that way.
        <Link
          href={back.href}
          className="mb-3 inline-flex h-10 items-center gap-1.5 -ms-2 rounded-pill px-2 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
          <ArrowRightIcon className="size-4 shrink-0" aria-hidden />
          {back.label}
        </Link>
      )}

      {/*
        `flex-wrap` for the reason the shell records: a long title moves the action group
        to its own line, and the group itself must wrap too, or three buttons on a products
        list come to 553px inside a 375px viewport and push the page sideways.
      */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          {eyebrow && (
            <p className="mb-1 text-2xs font-medium tracking-wide text-muted-foreground">
              {eyebrow}
            </p>
          )}

          <Heading className="font-display text-lg font-bold tracking-tight sm:text-xl">
            {title}
          </Heading>

          {description && (
            <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
              {description}
            </p>
          )}

          {meta && <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">{meta}</div>}
        </div>

        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  );
}
