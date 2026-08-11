import { Head, Link, router, useForm } from '@inertiajs/react';
import { BellIcon, CheckIcon, RotateCcwIcon, Trash2Icon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
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
 */
export default function FollowUpsIndex({ follow_ups: followUps, filters }: Props) {
  const toggle = useForm({});
  const remove = useForm({});

  function visit(changes: Record<string, boolean>): void {
    router.get(
      '/crm/follow-ups',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  return (
    <AppShell
      title="میز پیگیری"
      actions={
        <>
          <Button
            variant={filters.mine ? 'default' : 'outline'}
            onClick={() => visit({ mine: !filters.mine })}
          >
            فقط موارد من
          </Button>
          <Button
            variant={filters.done ? 'default' : 'outline'}
            onClick={() => visit({ done: !filters.done })}
          >
            {filters.done ? 'انجام‌شده‌ها' : 'بازها'}
          </Button>
        </>
      }
    >
      <Head title="میز پیگیری" />

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
                  <Link href={`/crm/parties/${followUp.party.id}`} className="text-primary">
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
                  onClick={() =>
                    remove.delete(`/crm/follow-ups/${followUp.id}`, { preserveScroll: true })
                  }
                >
                  <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <Pagination className="mt-6" links={followUps.links} total={followUps.total} unit="پیگیری" />
    </AppShell>
  );
}
