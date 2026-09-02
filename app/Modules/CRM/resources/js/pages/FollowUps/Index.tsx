import { Head, Link, router, useForm } from '@inertiajs/react';
import { BellIcon, CheckIcon, RotateCcwIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { EmptyState } from '@/components/domain/empty-state';
import { FormErrors } from '@/components/domain/form-errors';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';

interface FollowUpRow {
  id: number;
  title: string;
  body: string | null;
  due_at: string;
  done_at: string | null;
  is_overdue: boolean;
  assignee: string | null;
  party: { id: number; name: string };
}

interface Props {
  follow_ups: { rows: FollowUpRow[]; links: PaginationLink[]; total: number };
  filters: { mine: boolean; done: boolean };
}

/**
 * The follow-up desk.
 *
 * The screen the feature exists for: a reminder that only appears on one customer's
 * page is a reminder nobody sees. The question staff actually ask is "who am I
 * supposed to call today", which is a list across parties, soonest first.
 *
 * ## Still a list, not a table
 *
 * The register family is `DataTable` everywhere else and this stays a list on purpose. A
 * follow-up is a task, not a record: it has one line of subject, a due date that is read
 * as "late or not" rather than compared, and a pair of actions per row. The strike-through
 * on a completed item and the «سررسید گذشته» marker are the whole information design, and
 * a table would spread five columns over what is really one sentence and two buttons.
 *
 * ## Deleting asked nothing
 *
 * The bin icon called `remove.delete()` on a single click. A follow-up is somebody's note
 * to ring a customer back, it is not recoverable from this screen, and the button sits
 * directly beside «انجام شد» — one row apart, both icon-only. `ConfirmDialog` is what six
 * other pages already use for this, including this list's own sibling in Catalog.
 *
 * ## Both actions could fail silently
 *
 * `toggle.put` and `remove.delete` rendered no error region. A refusal on either — a
 * follow-up already closed by a colleague, a permission that changed — came back as a
 * redirect that re-rendered an identical row.
 */
export default function FollowUpsIndex({ follow_ups: followUps, filters }: Props) {
  const toggle = useForm({});
  const remove = useForm({});

  const [confirming, setConfirming] = useState<FollowUpRow | null>(null);

  function visit(changes: Record<string, boolean>): void {
    router.get(
      '/crm/follow-ups',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      header={
        <PageHeader
          title={filters.done ? 'میز پیگیری — انجام‌شده' : 'میز پیگیری'}
          description="کسانی که قرار است امروز با آن‌ها تماس بگیرید، نزدیک‌ترین سررسید اول."
          actions={
            <>
              <Button
                variant={filters.mine ? 'default' : 'outline'}
                onClick={() => visit({ mine: !filters.mine })}
              >
                فقط موارد من
              </Button>
              {/* The label names where the button GOES, not where you are — a toggle
              labelled with its current state reads as an action and sends people the
              wrong way. Which list you are on is carried by the heading below. */}
              <Button variant="outline" onClick={() => visit({ done: !filters.done })}>
                {filters.done ? 'نمایش بازها' : 'نمایش انجام‌شده‌ها'}
              </Button>
            </>
          }
        />
      }
    >
      <Head title="میز پیگیری" />

      {/* Neither a toggle nor a delete has a field for its refusal to sit beside. */}
      <FormErrors errors={{ ...toggle.errors, ...remove.errors }} className="mb-6" />

      {followUps.rows.length === 0 ? (
        <EmptyState
          icon={BellIcon}
          title={filters.done ? 'پیگیری انجام‌شده‌ای نیست' : 'پیگیری بازی نیست'}
          description="از صفحه هر طرف حساب می‌توانید قرار تماس بعدی را ثبت کنید."
        />
      ) : (
        <ul className="divide-y divide-border rounded-card border border-border bg-card">
          {followUps.rows.map((followUp) => (
            <li
              key={followUp.id}
              className="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6"
            >
              <span className="min-w-0 flex-1">
                <span
                  className={cn(
                    'block text-sm font-medium',
                    followUp.done_at && 'text-muted-foreground line-through'
                  )}
                >
                  {followUp.title}
                </span>
                <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                  <Link
                    href={`/crm/parties/${followUp.party.id}`}
                    className="inline-flex min-h-10 items-center text-primary"
                  >
                    {followUp.party.name}
                  </Link>
                  <span aria-hidden>·</span>
                  <span className="tabular">
                    {formatJalali(followUp.due_at, { longMonth: true })}
                  </span>
                  {followUp.assignee && (
                    <>
                      <span aria-hidden>·</span>
                      <span>{followUp.assignee}</span>
                    </>
                  )}
                  {followUp.is_overdue && <span className="text-danger">· سررسید گذشته</span>}
                </span>
              </span>

              <span className="flex items-center gap-1">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={followUp.done_at ? 'بازکردن دوباره' : 'انجام شد'}
                  disabled={toggle.processing}
                  onClick={() =>
                    toggle.put(`/crm/follow-ups/${followUp.id}`, { preserveScroll: true })
                  }
                >
                  {followUp.done_at ? (
                    <RotateCcwIcon className="size-4" />
                  ) : (
                    <CheckIcon className="size-4 text-success" />
                  )}
                </Button>

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="حذف پیگیری"
                  className="group"
                  disabled={remove.processing}
                  onClick={() => setConfirming(followUp)}
                >
                  <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <Pagination className="mt-6" links={followUps.links} total={followUps.total} unit="پیگیری" />

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
        title={confirming ? `حذف «${confirming.title}»` : ''}
        description="این یادآوری برای همیشه پاک می‌شود. اگر فقط انجام شده، به‌جای حذف آن را «انجام شد» بزنید تا سابقه‌اش بماند."
        confirmLabel="حذف پیگیری"
        processing={remove.processing}
        onConfirm={() => {
          if (!confirming) return;

          remove.delete(`/crm/follow-ups/${confirming.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirming(null),
          });
        }}
      />
    </AppShell>
  );
}
