import { Head, Link } from '@inertiajs/react';
import { BanIcon, CheckCircle2Icon, WalletIcon, XCircleIcon } from 'lucide-react';

import { DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface MessageRow {
  id: number;
  to: string;
  party_name: string | null;
  template_key: string | null;
  template_label: string | null;
  status: string;
  error: string | null;
  cost: MoneyValue;
  queued_at: string;
  sent_at: string | null;
}

interface Props {
  balance: MoneyValue;
  status: string;
  counts: { sent: number; suppressed: number; failed: number };
  messages: { data: MessageRow[]; links: PaginationLink[]; total: number };
}

const STATUS: Record<string, { label: string; className: string }> = {
  sent: { label: 'ارسال شد', className: 'text-success' },
  queued: { label: 'در صف', className: 'text-muted-foreground' },
  suppressed: { label: 'ارسال نشد', className: 'text-warning' },
  failed: { label: 'ناموفق', className: 'text-destructive' },
};

/**
 * What went out, what did not, and why not.
 *
 * ## The rows that never left are the useful ones
 *
 * «چرا برای این مشتری پیامک نرفت؟» is the question a shopkeeper asks, and it is only
 * answerable because a suppressed attempt is still recorded — opted out, no credit, bad
 * number. A log listing only successes turns every failure into a silence somebody has to
 * guess at.
 *
 * ## The wallet is at the top
 *
 * The commonest reason messages stop is an empty one, and a shop that learns that from a
 * customer complaint has already stopped trusting the feature.
 *
 * ## The cost column was aligned on the wrong edge
 *
 * `text-end` in an RTL table is physical **left**, which lines up the most-significant
 * digits of a Latin numeral and leaves the units ragged. `DataTable`'s `numeric` is
 * physical right; the flag's docblock carries the measurements.
 */
export default function MessagingIndex({ balance, status, counts, messages }: Props) {
  return (
    <AppShell
      header={
        <PageHeader
          title="پیامک‌ها"
          actions={
            <div className="flex items-center gap-2 rounded-card border border-border px-3 py-2">
              <WalletIcon className="size-4 text-muted-foreground" aria-hidden />
              <span className="text-sm text-muted-foreground">اعتبار پیامک</span>
              <span className="font-semibold">
                <Money rial={balance.value} withUnit />
              </span>
            </div>
          }
        />
      }
    >
      <Head title="پیامک‌ها" />

      <div className="flex flex-wrap gap-2">
        <Filter current={status} value="" label={`همه (${messages.total})`} />
        <Filter
          current={status}
          value="sent"
          label={`ارسال‌شده (${counts.sent})`}
          icon={CheckCircle2Icon}
        />
        <Filter
          current={status}
          value="suppressed"
          label={`ارسال‌نشده (${counts.suppressed})`}
          icon={BanIcon}
        />
        <Filter
          current={status}
          value="failed"
          label={`ناموفق (${counts.failed})`}
          icon={XCircleIcon}
        />
      </div>

      <DataTable
        className="mt-6"
        caption="پیامک‌های ثبت‌شده، تازه‌ترین اول — شامل آن‌هایی که ارسال نشدند و دلیلش."
        rows={messages.data}
        rowKey={(row) => row.id}
        empty={
          <EmptyState
            title="پیامکی ثبت نشده است"
            description="با ثبت فروش، تعمیر یا یادآوری، پیامک‌های خودکار اینجا فهرست می‌شوند."
          />
        }
        columns={[
          {
            key: 'to',
            header: 'گیرنده',
            cell: (row) => (
              <>
                {row.party_name ?? '—'}
                <Num value={row.to} variant="ltr" className="ms-2 text-2xs text-muted-foreground" />
              </>
            ),
          },
          {
            key: 'template',
            header: 'بابت',
            cell: (row) => row.template_label ?? '—',
          },
          {
            key: 'at',
            header: 'زمان',
            cell: (row) => formatJalali(row.sent_at ?? row.queued_at, { withTime: true }),
            secondary: true,
          },
          {
            key: 'status',
            header: 'وضعیت',
            cell: (row) => {
              const state = STATUS[row.status] ?? { label: row.status, className: '' };

              return (
                <>
                  <span className={state.className}>{state.label}</span>
                  {/* The reason a message never left is the useful half of this log. */}
                  {row.error && (
                    <span className="block text-2xs text-muted-foreground">{row.error}</span>
                  )}
                </>
              );
            },
          },
          {
            key: 'cost',
            header: 'هزینه',
            numeric: true,
            cell: (row) =>
              row.cost.value > 0 ? (
                <Money rial={row.cost.value} digits="latin" />
              ) : (
                <span className="text-muted-foreground">—</span>
              ),
          },
        ]}
      />

      <Pagination links={messages.links} total={messages.total} className="mt-4" />
    </AppShell>
  );
}

function Filter({
  current,
  value,
  label,
  icon: Icon,
}: {
  current: string;
  value: string;
  label: string;
  icon?: typeof CheckCircle2Icon;
}) {
  return (
    // No `size="sm"`. These were 28px anchor-buttons, and they are the only way to narrow
    // this log — the control a shopkeeper reaches for when asking «چرا پیامک نرفت؟».
    <Button variant={current === value ? 'default' : 'outline'} asChild>
      <Link href={value === '' ? '/messaging' : `/messaging?status=${value}`}>
        {Icon && <Icon className="size-4" aria-hidden />}
        {label}
      </Link>
    </Button>
  );
}
