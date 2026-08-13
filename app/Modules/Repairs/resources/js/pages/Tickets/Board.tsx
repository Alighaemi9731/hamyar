import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';

interface Card {
  id: number;
  code: string;
  status: string;
  device: string;
  party_name: string | null;
  technician_name: string | null;
  priority: number;
  promised_at: string | null;
  created_at: string | null;
}

interface Column {
  value: string;
  label: string;
  /** Statuses this column's cards may be dragged to. Straight from the state machine. */
  allows: string[];
}

interface Props {
  columns: Column[];
  tickets: Record<string, Card[]>;
  counts: Record<string, number>;
  limit: number;
  filters: { mine: boolean };
  can: { create: boolean; update: boolean };
}

/**
 * The bench, as a board.
 *
 * ## Drag targets come from the state machine, not from the board
 *
 * Each column carries the statuses its cards may move to, generated from
 * `TicketStatus::allowedTransitions()`. So an illegal drop is not *offered* — the column
 * does not light up and the card does not lift — rather than being attempted and bounced.
 * A card that springs back is a card the operator tries again, harder.
 *
 * The server re-checks anyway. This is convenience; `TicketStateMachine` is the rule.
 *
 * ## `delivered` and `rejected` are not columns
 *
 * A board is a picture of work in the shop. Those two only grow, and a column nobody
 * reads costs the same horizontal space as one somebody does.
 *
 * ## The count is the truth, the column is a window
 *
 * Columns render at most `limit` cards and say so when there are more. A shop with three
 * hundred queued tickets has a problem no amount of DOM will fix, and a board that
 * silently shows the first fifty lies about the size of the queue.
 */
export default function TicketsBoard({ columns, tickets, counts, limit, filters, can }: Props) {
  const [dragging, setDragging] = useState<Card | null>(null);
  const [over, setOver] = useState<string | null>(null);

  function move(card: Card, to: string): void {
    setDragging(null);
    setOver(null);

    if (card.status === to) {
      return;
    }

    router.post(`/repairs/tickets/${card.id}/transition`, { status: to }, { preserveScroll: true });
  }

  return (
    <AppShell
      title="تخته تعمیرات"
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            size="sm"
            variant={filters.mine ? 'default' : 'outline'}
            onClick={() =>
              router.get('/repairs/board', { mine: !filters.mine }, { preserveState: true })
            }
          >
            کارهای من
          </Button>

          <Button asChild variant="outline">
            <Link href="/repairs">فهرست</Link>
          </Button>

          {can.create && (
            <Button asChild>
              <Link href="/repairs/intake">
                <PlusIcon className="size-4" aria-hidden />
                پذیرش
              </Link>
            </Button>
          )}
        </div>
      }
    >
      <Head title="تخته تعمیرات" />

      {/* Horizontal scroll on the BOARD, never on the page. Six columns do not fit a
          phone, and squeezing them would make every card unreadable. */}
      <div className="overflow-x-auto pb-4">
        <div className="flex min-w-max gap-3">
          {columns.map((column) => {
            const cards = tickets[column.value] ?? [];
            const total = counts[column.value] ?? 0;
            const droppable =
              dragging !== null &&
              (columns.find((c) => c.value === dragging.status)?.allows ?? []).includes(
                column.value
              );

            return (
              <section
                key={column.value}
                aria-label={column.label}
                onDragOver={(event) => {
                  if (!droppable) return;
                  event.preventDefault();
                  setOver(column.value);
                }}
                onDragLeave={() =>
                  setOver((current) => (current === column.value ? null : current))
                }
                onDrop={() => {
                  if (droppable && dragging) move(dragging, column.value);
                }}
                className={cn(
                  'w-72 shrink-0 rounded-card border p-2 transition-colors',
                  over === column.value && droppable
                    ? 'border-primary bg-primary/5'
                    : 'border-border',
                  // Dimmed while a card is in the air and this column cannot take it —
                  // the board says no before the drop rather than after.
                  dragging !== null &&
                    !droppable &&
                    dragging.status !== column.value &&
                    'opacity-40'
                )}
              >
                <header className="mb-2 flex items-baseline justify-between px-1">
                  <h2 className="text-sm font-semibold">{column.label}</h2>
                  <span className="text-2xs text-muted-foreground">
                    <Num value={total} variant="prose" />
                  </span>
                </header>

                <ul className="space-y-2">
                  {cards.map((card) => (
                    <li key={card.id}>
                      <Link
                        href={`/repairs/tickets/${card.id}`}
                        draggable={can.update}
                        onDragStart={() => setDragging(card)}
                        onDragEnd={() => {
                          setDragging(null);
                          setOver(null);
                        }}
                        className={cn(
                          'block rounded-control border border-border bg-background p-2.5 hover:bg-muted/40',
                          can.update && 'cursor-grab active:cursor-grabbing',
                          dragging?.id === card.id && 'opacity-50'
                        )}
                      >
                        <span className="flex flex-wrap items-baseline gap-x-2">
                          <span className="tabular text-sm font-medium text-primary">
                            {card.code}
                          </span>
                          {card.priority === 1 && (
                            <span className="rounded-pill bg-danger/10 px-1.5 text-2xs text-danger">
                              فوری
                            </span>
                          )}
                        </span>

                        <span className="mt-0.5 block truncate text-sm">{card.device}</span>

                        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                          <span className="truncate">{card.party_name ?? 'مشتری گذری'}</span>
                          {card.technician_name && (
                            <>
                              <span aria-hidden>·</span>
                              <span className="truncate">{card.technician_name}</span>
                            </>
                          )}
                        </span>

                        {card.promised_at && (
                          <span
                            className={cn(
                              'mt-1 block text-2xs',
                              new Date(card.promised_at) < new Date()
                                ? 'text-danger'
                                : 'text-muted-foreground'
                            )}
                          >
                            وعده {formatJalali(card.promised_at)}
                          </span>
                        )}
                      </Link>
                    </li>
                  ))}

                  {total > cards.length && (
                    <li className="px-1 py-2 text-2xs text-muted-foreground">
                      و <Num value={total - cards.length} variant="prose" /> مورد دیگر — برای دیدن
                      همه از{' '}
                      <Link href={`/repairs?status=${column.value}`} className="text-primary">
                        فهرست
                      </Link>{' '}
                      استفاده کنید.
                    </li>
                  )}

                  {total === 0 && (
                    <li className="px-1 py-4 text-center text-2xs text-muted-foreground">خالی</li>
                  )}
                </ul>
              </section>
            );
          })}
        </div>
      </div>

      <p className="mt-2 text-2xs text-muted-foreground">
        هر ستون حداکثر <Num value={limit} variant="prose" /> کارت نشان می‌دهد؛ عدد بالای ستون، تعداد
        واقعی است.
      </p>
    </AppShell>
  );
}
