import { Head, Link, router } from '@inertiajs/react';
import { MoveHorizontalIcon, PlusIcon, WrenchIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
 *
 * ## Dragging is not the only way to move a card, because dragging is a mouse
 *
 * HTML5 drag-and-drop does not fire on touch, and `draggable` has no keyboard equivalent
 * at all. So on the tablet at the bench — the device this board is most likely to be read
 * on — and for anybody working by keyboard, the board's entire purpose was unreachable:
 * every card was a link to somewhere else and nothing on the screen could move one.
 *
 * Each card now carries a menu of the moves that column allows. It is fed by exactly the
 * same `allows` array the drop targets use and filtered to the columns on screen, so the
 * two paths cannot disagree and neither can reach a status the board does not show —
 * `delivered` in particular, which belongs to the delivery form because it writes an
 * invoice.
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

  // Nothing in any column: a bench with no work on it, or a technician with none of it
  // assigned. Six empty columns say neither; one state that names the next step does.
  const idle = columns.every((column) => (counts[column.value] ?? 0) === 0);

  return (
    <AppShell
      title="تخته تعمیرات"
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant={filters.mine ? 'default' : 'outline'}
            aria-pressed={filters.mine}
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

      {idle ? (
        filters.mine ? (
          <EmptyState
            variant="search"
            icon={WrenchIcon}
            title="کاری به شما سپرده نشده است"
            description="دستگاه‌هایی که تکنسین آن‌ها شما باشید اینجا می‌آیند. برای دیدن کار همهٔ همکاران، فیلتر را بردارید."
            action={
              <Button
                type="button"
                variant="outline"
                onClick={() =>
                  router.get('/repairs/board', { mine: false }, { preserveState: true })
                }
              >
                همهٔ کارها
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={WrenchIcon}
            title="دستگاهی روی میز تعمیر نیست"
            description="با پذیرش اولین دستگاه، قبض پذیرش چاپ می‌شود و کارت آن در همین تخته، ستون به ستون، تا تحویل جلو می‌رود."
            action={
              can.create ? (
                <Button asChild>
                  <Link href="/repairs/intake">
                    <PlusIcon className="size-4" aria-hidden />
                    پذیرش دستگاه
                  </Link>
                </Button>
              ) : undefined
            }
          />
        )
      ) : (
        <>
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

                // The same `allows` the drop targets read, narrowed to columns that are on the
                // board. A move the board cannot show is a move the board must not offer.
                const moves = columns.filter((target) => column.allows.includes(target.value));

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
                        <li
                          key={card.id}
                          className={cn(
                            'relative rounded-control border border-border bg-background',
                            dragging?.id === card.id && 'opacity-50'
                          )}
                        >
                          <Link
                            href={`/repairs/tickets/${card.id}`}
                            draggable={can.update}
                            onDragStart={() => setDragging(card)}
                            onDragEnd={() => {
                              setDragging(null);
                              setOver(null);
                            }}
                            className={cn(
                              // `pe-12` reserves the lane the move button sits in, so a long
                              // device name runs under it instead of behind it.
                              'block rounded-control p-2.5 hover:bg-muted/40',
                              can.update && 'cursor-grab active:cursor-grabbing pe-12'
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

                          {can.update && moves.length > 0 && (
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  // Absolutely placed rather than inside the link, because a
                                  // button nested in an anchor is not valid and the click would
                                  // navigate before the menu could open.
                                  className="absolute inset-block-start-1 inset-inline-end-1"
                                  aria-label={`جابه‌جایی ${card.code}`}
                                >
                                  <MoveHorizontalIcon aria-hidden />
                                </Button>
                              </DropdownMenuTrigger>

                              <DropdownMenuContent align="end">
                                <DropdownMenuLabel>انتقال به</DropdownMenuLabel>
                                {moves.map((target) => (
                                  <DropdownMenuItem
                                    key={target.value}
                                    onSelect={() => move(card, target.value)}
                                  >
                                    {target.label}
                                  </DropdownMenuItem>
                                ))}
                              </DropdownMenuContent>
                            </DropdownMenu>
                          )}
                        </li>
                      ))}

                      {total > cards.length && (
                        <li className="px-1 py-2 text-2xs text-muted-foreground">
                          و <Num value={total - cards.length} variant="prose" /> مورد دیگر — برای
                          دیدن همه از{' '}
                          <Link href={`/repairs?status=${column.value}`} className="text-primary">
                            فهرست
                          </Link>{' '}
                          استفاده کنید.
                        </li>
                      )}

                      {total === 0 && (
                        <li className="px-1 py-4 text-center text-2xs text-muted-foreground">
                          خالی
                        </li>
                      )}
                    </ul>
                  </section>
                );
              })}
            </div>
          </div>

          <p className="mt-2 text-2xs text-muted-foreground">
            هر ستون حداکثر <Num value={limit} variant="prose" /> کارت نشان می‌دهد؛ عدد بالای ستون،
            تعداد واقعی است.
          </p>
        </>
      )}
    </AppShell>
  );
}
