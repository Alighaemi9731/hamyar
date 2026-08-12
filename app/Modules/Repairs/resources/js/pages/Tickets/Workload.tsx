import { Head, Link } from '@inertiajs/react';

import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { cn } from '@/lib/utils';

interface Row {
  id: number | null;
  name: string;
  open: number;
  urgent: number;
  overdue: number;
}

interface Props {
  rows: Row[];
}

/**
 * Who is carrying what.
 *
 * ## Open work only
 *
 * `ready` is finished from the bench's point of view — the ball is in the customer's
 * court. Counting it would make a technician with ten collected-tomorrow devices look
 * busier than one with two broken ones, which is the opposite of what this screen is for.
 *
 * ## Unassigned is a row, not an omission
 *
 * The pile nobody owns is the first thing a manager should see, so it sorts to the top
 * and is named rather than blank.
 */
export default function TicketsWorkload({ rows }: Props) {
  return (
    <AppShell
      title="بار کاری تعمیرکاران"
      actions={
        <Button asChild variant="outline">
          <Link href="/repairs/board">تخته</Link>
        </Button>
      }
    >
      <Head title="بار کاری تعمیرکاران" />

      {rows.length === 0 ? (
        <EmptyState
          title="کار بازی روی میز نیست"
          description="هر دستگاهی که پذیرش شود اینجا دیده می‌شود."
          action={
            <Button asChild>
              <Link href="/repairs/intake">پذیرش دستگاه</Link>
            </Button>
          }
        />
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => (
            <li
              key={row.id ?? 'unassigned'}
              className={cn(
                'flex flex-wrap items-center justify-between gap-3 rounded-card border p-4',
                row.id === null ? 'border-warning/40 bg-warning/5' : 'border-border'
              )}
            >
              <span className="font-medium">{row.name}</span>

              <span className="flex flex-wrap items-center gap-4 text-sm">
                <span>
                  <Num value={row.open} variant="prose" />{' '}
                  <span className="text-2xs text-muted-foreground">کار باز</span>
                </span>

                {row.urgent > 0 && (
                  <span className="text-danger">
                    <Num value={row.urgent} variant="prose" />{' '}
                    <span className="text-2xs">فوری</span>
                  </span>
                )}

                {row.overdue > 0 && (
                  <span className="text-danger">
                    <Num value={row.overdue} variant="prose" />{' '}
                    <span className="text-2xs">از وعده گذشته</span>
                  </span>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
    </AppShell>
  );
}
