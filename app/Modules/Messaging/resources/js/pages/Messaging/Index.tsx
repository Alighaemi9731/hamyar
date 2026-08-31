import { Head, Link } from '@inertiajs/react';
import { BanIcon, CheckCircle2Icon, WalletIcon, XCircleIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
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
 */
export default function MessagingIndex({ balance, status, counts, messages }: Props) {
  return (
    <AppShell>
      <Head title="پیامک‌ها" />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">پیامک‌ها</h1>

        <div className="flex items-center gap-2 rounded-card border border-border px-3 py-2">
          <WalletIcon className="size-4 text-muted-foreground" aria-hidden />
          <span className="text-sm text-muted-foreground">اعتبار پیامک</span>
          <span className="font-semibold">
            <Money rial={balance.value} withUnit />
          </span>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
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

      <div className="mt-6 overflow-x-auto rounded-card border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-2xs text-muted-foreground">
              <th className="p-3 text-start font-medium">گیرنده</th>
              <th className="p-3 text-start font-medium">بابت</th>
              <th className="p-3 text-start font-medium">زمان</th>
              <th className="p-3 text-start font-medium">وضعیت</th>
              <th className="p-3 text-end font-medium">هزینه</th>
            </tr>
          </thead>
          <tbody>
            {messages.data.map((row) => {
              const state = STATUS[row.status] ?? { label: row.status, className: '' };

              return (
                <tr key={row.id} className="border-b border-border last:border-0">
                  <td className="p-3">
                    {row.party_name ?? '—'}
                    <span className="tabular ms-2 text-2xs text-muted-foreground" dir="ltr">
                      {row.to}
                    </span>
                  </td>
                  <td className="p-3">{row.template_label ?? '—'}</td>
                  <td className="p-3 whitespace-nowrap text-2xs">
                    {formatJalali(row.sent_at ?? row.queued_at, { withTime: true })}
                  </td>
                  <td className="p-3">
                    <span className={state.className}>{state.label}</span>
                    {row.error && (
                      <span className="block text-2xs text-muted-foreground">{row.error}</span>
                    )}
                  </td>
                  <td className="p-3 text-end tabular">
                    {row.cost.value > 0 ? (
                      <Money rial={row.cost.value} />
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </td>
                </tr>
              );
            })}

            {messages.data.length === 0 && (
              <tr>
                <td colSpan={5} className="p-6 text-center text-sm text-muted-foreground">
                  پیامکی ثبت نشده است.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

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
    <Button variant={current === value ? 'default' : 'outline'} size="sm" asChild>
      <Link href={value === '' ? '/messaging' : `/messaging?status=${value}`}>
        {Icon && <Icon className="size-4" aria-hidden />}
        {label}
      </Link>
    </Button>
  );
}
