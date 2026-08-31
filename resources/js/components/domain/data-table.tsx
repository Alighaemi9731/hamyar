import { ArrowDownIcon, ArrowUpIcon, SearchIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableFooter,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

export interface Column<TRow> {
  key: string;
  header: string;
  /** Cell renderer. Return a domain component — never a hand-formatted number. */
  cell: (row: TRow) => ReactNode;
  /**
   * A column of figures — money, counts — aligned on its units digit.
   *
   * **`text-start`, not `text-end`, and that is not a typo.** Latin numerals are an
   * LTR run whose units digit sits at its *physical right*, so a column of them only
   * reads as a column when their right edges line up. In an RTL table `text-end`
   * resolves to physical **left**, which lines up the most-significant digits instead
   * and leaves the units ragged — measured at 37px of spread across three figures on
   * the treasury headings table, which is precisely what `tabular-nums` exists to
   * prevent. `text-start` is physical right here, and the same three figures align to
   * 0px.
   *
   * The original comment on this flag read "right-align in visual terms is `text-end`"
   * — the intent was always physical right; only the resolution was wrong under `dir="rtl"`.
   */
  numeric?: boolean;
  sortable?: boolean;
  /** Hidden below `sm`. Use for columns that are context rather than identity. */
  secondary?: boolean;
  className?: string;
}

export interface DataTableProps<TRow> {
  columns: Column<TRow>[];
  rows: TRow[];
  rowKey: (row: TRow) => string | number;
  caption: string;
  /** Renders a search box above the table and reports what was typed. */
  search?: { value: string; onChange: (value: string) => void; placeholder?: string };
  sort?: { key: string; direction: 'asc' | 'desc'; onChange: (key: string) => void };
  onRowClick?: (row: TRow) => void;
  loading?: boolean;
  /** Shown when there are no rows and no search term. */
  empty?: ReactNode;
  /**
   * A totals row, rendered per column so its figures sit under the ones they total.
   *
   * Returns the cell for a column, or `undefined` for a column that totals nothing —
   * an «تأییدنشده» column with no meaningful sum gets an empty cell rather than a zero,
   * because a zero there is a claim and a blank is not.
   *
   * Deliberately not `ReactNode`: a hand-built `<tr>` would have to repeat the column
   * order, and the first time somebody reorders the columns the totals would silently
   * line up under the wrong headings.
   */
  footer?: (column: Column<TRow>) => ReactNode;
  className?: string;
}

/**
 * The one table.
 *
 * Every list screen in this product shows rows of records with money, dates and
 * statuses in them, and five hand-rolled tables diverge on exactly the details that
 * matter — column alignment, empty state, what happens on a narrow screen. Building it
 * once is the difference between a system and a pile of pages.
 *
 * Three decisions worth knowing:
 *
 * - **Horizontal scroll is contained.** The wrapper scrolls, never the page. A table
 *   that widens the document breaks every RTL layout around it.
 * - **`secondary` columns disappear below `sm`** rather than the table shrinking them
 *   to unreadability. On a 390px phone, four legible columns beat eight cramped ones.
 * - **Empty and searching-empty are different states.** "No products yet" wants a
 *   call to action; "nothing matched" wants the search term back and no action at all.
 */
export function DataTable<TRow>({
  columns,
  rows,
  rowKey,
  caption,
  search,
  sort,
  onRowClick,
  loading = false,
  empty,
  footer,
  className,
}: DataTableProps<TRow>) {
  const searching = Boolean(search?.value.trim());

  return (
    <div className={cn('space-y-4', className)}>
      {search ? (
        <div className="relative max-w-sm">
          <SearchIcon
            className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground"
            aria-hidden
          />
          <Input
            value={search.value}
            onChange={(event) => search.onChange(event.target.value)}
            placeholder={search.placeholder ?? 'جستجو…'}
            className="ps-9"
            type="search"
          />
        </div>
      ) : null}

      {/* The wrapper scrolls, not the page. */}
      <div className="overflow-x-auto rounded-card border border-border">
        <Table>
          <caption className="sr-only">{caption}</caption>

          <TableHeader>
            <TableRow>
              {columns.map((column) => (
                <TableHead
                  key={column.key}
                  // Every header here labels a column. Without it a screen reader reads
                  // a grid of unlabelled numbers (WCAG 1.3.1) — inference usually saves
                  // it, but "usually" is not the accessibility floor this system sets.
                  scope="col"
                  className={cn(
                    column.numeric && 'text-start',
                    column.secondary && 'hidden sm:table-cell',
                    column.className
                  )}
                  aria-sort={
                    sort?.key === column.key
                      ? sort.direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                      : undefined
                  }
                >
                  {column.sortable && sort ? (
                    <button
                      type="button"
                      onClick={() => sort.onChange(column.key)}
                      // `min-h-10` and negative inline margin: sorting a column was a
                      // 21px target inside a header cell that is already 40px tall, so
                      // the row had the height and the control did not use it. The
                      // margin keeps the label optically aligned with the cells beneath.
                      className="-mx-2 inline-flex min-h-10 items-center gap-1 rounded-control px-2 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                      {column.header}
                      {sort.key === column.key ? (
                        sort.direction === 'asc' ? (
                          <ArrowUpIcon className="size-3.5" aria-hidden />
                        ) : (
                          <ArrowDownIcon className="size-3.5" aria-hidden />
                        )
                      ) : null}
                    </button>
                  ) : (
                    column.header
                  )}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>

          <TableBody>
            {loading ? (
              // Skeleton rows rather than a spinner: the layout does not jump when the
              // data lands, which is what makes a list feel fast rather than busy.
              Array.from({ length: 5 }).map((_, index) => (
                <TableRow key={`skeleton-${index}`}>
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      className={cn(column.secondary && 'hidden sm:table-cell')}
                    >
                      <Skeleton className="h-4 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={columns.length} className="p-0">
                  {searching ? (
                    <EmptyState
                      variant="search"
                      title="نتیجه‌ای پیدا نشد"
                      description={`هیچ رکوردی با «${search?.value}» مطابقت نداشت.`}
                    />
                  ) : (
                    (empty ?? (
                      <EmptyState
                        title="موردی برای نمایش نیست"
                        description="هنوز رکوردی ثبت نشده است."
                      />
                    ))
                  )}
                </TableCell>
              </TableRow>
            ) : (
              rows.map((row) => (
                <TableRow
                  key={rowKey(row)}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                  className={cn(onRowClick && 'cursor-pointer')}
                >
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      className={cn(
                        column.numeric && 'text-start tabular',
                        column.secondary && 'hidden sm:table-cell',
                        column.className
                      )}
                    >
                      {column.cell(row)}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>

          {/* Only when there are rows: a totals row under an empty state is a sum of
              nothing, presented as a fact. */}
          {footer && rows.length > 0 && !loading && (
            <TableFooter>
              <TableRow>
                {columns.map((column) => (
                  <TableCell
                    key={column.key}
                    className={cn(
                      'font-semibold',
                      column.numeric && 'text-start tabular',
                      column.secondary && 'hidden sm:table-cell',
                      column.className
                    )}
                  >
                    {footer(column)}
                  </TableCell>
                ))}
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </div>
    </div>
  );
}
