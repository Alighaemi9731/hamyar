import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

import { Num } from '@/components/domain/num';
import { cn } from '@/lib/utils';

/** One entry of Laravel's `linkCollection()`. */
export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginationProps {
  links: PaginationLink[];
  /** Row count across all pages — the number people actually want. */
  total: number;
  /** Noun for the total, e.g. "کالا". */
  unit?: string;
  className?: string;
}

const PREVIOUS = 'pagination.previous';
const NEXT = 'pagination.next';

/**
 * Page navigation for the list screens.
 *
 * Laravel labels its first and last links with translated HTML entities
 * («&laquo; Previous»), which is neither Persian nor safe to render. They are detected
 * by position — first and last are always the arrows — and replaced with icons, so no
 * page has to sanitise a label or ship a translation for a chevron.
 *
 * Written for LTR and mirrored by `rtl:rotate-180`: in a right-to-left page the
 * previous page sits on the right, which is where the reader's eye came from.
 */
export function Pagination({ links, total, unit = 'ردیف', className }: PaginationProps) {
  // One page of results needs no controls; the total still does.
  const numbered = links.slice(1, -1);
  const previous = links[0];
  const next = links[links.length - 1];

  return (
    <div className={cn('flex flex-wrap items-center justify-between gap-4', className)}>
      <p className="text-xs text-muted-foreground">
        <Num value={total} /> {unit}
      </p>

      {numbered.length > 1 && (
        <nav aria-label="صفحه‌بندی" className="flex flex-wrap items-center gap-1">
          <Arrow link={previous} direction={PREVIOUS} />

          {numbered.map((link, index) => (
            <PageLink key={`${link.label}-${index}`} link={link} />
          ))}

          <Arrow link={next} direction={NEXT} />
        </nav>
      )}
    </div>
  );
}

function PageLink({ link }: { link: PaginationLink }) {
  // A gap in the sequence. Laravel sends it as a literal "..." with no url.
  if (link.url === null) {
    return (
      <span className="px-2 text-xs text-muted-foreground" aria-hidden>
        …
      </span>
    );
  }

  return (
    <Link
      href={link.url}
      preserveScroll
      preserveState
      aria-current={link.active ? 'page' : undefined}
      className={cn(
        'flex h-10 min-w-10 items-center justify-center rounded-pill px-3 text-sm tabular transition-colors',
        link.active
          ? 'bg-primary text-primary-foreground font-semibold'
          : 'text-foreground/75 hover:bg-accent'
      )}
    >
      <Num value={link.label} variant="table" grouped={false} />
    </Link>
  );
}

function Arrow({ link, direction }: { link: PaginationLink | undefined; direction: string }) {
  const label = direction === PREVIOUS ? 'صفحه قبل' : 'صفحه بعد';
  const Icon = direction === PREVIOUS ? ChevronLeftIcon : ChevronRightIcon;

  if (!link?.url) {
    return (
      <span
        aria-disabled
        className="flex size-10 items-center justify-center text-muted-foreground/40"
      >
        <Icon className="size-4 rtl:rotate-180" aria-hidden />
        <span className="sr-only">{label}</span>
      </span>
    );
  }

  return (
    <Link
      href={link.url}
      preserveScroll
      preserveState
      aria-label={label}
      className="flex size-10 items-center justify-center rounded-pill text-foreground/75 transition-colors hover:bg-accent"
    >
      <Icon className="size-4 rtl:rotate-180" aria-hidden />
    </Link>
  );
}
