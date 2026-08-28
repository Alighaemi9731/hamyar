import { Head, Link, router } from '@inertiajs/react';
import { InboxIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Pagination, type PaginationLink } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';

interface Submission {
  id: number;
  type: 'main' | 'cancel' | 'correction';
  status: 'pending' | 'sending' | 'accepted' | 'rejected' | 'failed';
  invoice_number: string | null;
  invoice_url: string | null;
  reference: string | null;
  tax_id: string | null;
  error_code: string | null;
  error_message: string | null;
  attempts: number;
  sent_at: string;
}

interface Props {
  enabled: boolean;
  platform_enabled: boolean;
  shop_enabled: boolean;
  provider: string;
  status: string | null;
  counts: { pending: number; accepted: number; rejected: number; failed: number };
  submissions: { data: Submission[]; links: PaginationLink[]; total: number };
  can_manage: boolean;
}

const STATUS_LABEL: Record<Submission['status'], string> = {
  pending: 'در صف',
  sending: 'در حال ارسال',
  accepted: 'پذیرفته‌شده',
  rejected: 'رد شده',
  failed: 'ناموفق',
};

const STATUS_TONE: Record<Submission['status'], string> = {
  pending: 'bg-muted text-muted-foreground',
  sending: 'bg-muted text-muted-foreground',
  accepted: 'bg-success/10 text-success',
  rejected: 'bg-danger/10 text-danger',
  failed: 'bg-warning/10 text-warning',
};

/**
 * Submissions and the error inbox.
 *
 * ## The two switches are reported separately
 *
 * A shop that turned theirs on still submits nothing while the deployment-wide flag is off,
 * and «چرا کار نمی‌کند؟» needs an answer that tells those apart. Collapsing them into one
 * "disabled" state sends the shop to change a setting that was never the problem.
 */
export default function MoadianIndex({
  enabled,
  platform_enabled: platformEnabled,
  shop_enabled: shopEnabled,
  status,
  counts,
  submissions,
  can_manage: canManage,
}: Props) {
  const filter = (next: string | null) => {
    router.get('/moadian', next ? { status: next } : {}, {
      preserveState: true,
      preserveScroll: true,
    });
  };

  return (
    <AppShell title="سامانه مودیان">
      <Head title="سامانه مودیان" />

      <div className="space-y-6">
        <header>
          <h1 className="text-2xl font-bold">سامانه مودیان</h1>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
            وضعیت ارسال صورتحساب‌های الکترونیکی به سازمان امور مالیاتی.
          </p>
        </header>

        {!enabled && <DisabledNotice platformEnabled={platformEnabled} shopEnabled={shopEnabled} />}

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Count label="در صف" value={counts.pending} onClick={() => filter('pending')} />
          <Count label="پذیرفته‌شده" value={counts.accepted} onClick={() => filter('accepted')} />
          <Count label="رد شده" value={counts.rejected} tone="danger" onClick={() => filter('rejected')} />
          <Count label="ناموفق" value={counts.failed} tone="warning" onClick={() => filter('failed')} />
        </div>

        {status ? (
          <Button variant="ghost" size="sm" onClick={() => filter(null)}>
            نمایش همه
          </Button>
        ) : null}

        {submissions.data.length === 0 ? (
          <EmptyState
            icon={InboxIcon}
            title="هنوز سندی ارسال نشده است"
            description="با نهایی شدن هر فاکتور، سند الکترونیکی آن به‌صورت خودکار در صف ارسال قرار می‌گیرد."
          />
        ) : (
          <div className="overflow-x-auto rounded-card border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-surface-muted text-muted-foreground">
                  <th className="p-3 text-start font-medium">فاکتور</th>
                  <th className="p-3 text-start font-medium">نوع</th>
                  <th className="p-3 text-start font-medium">وضعیت</th>
                  <th className="p-3 text-start font-medium">شناسه مالیاتی</th>
                  <th className="p-3 text-start font-medium">تلاش</th>
                  <th className="p-3 text-start font-medium">تاریخ</th>
                  <th className="p-3 text-start font-medium" />
                </tr>
              </thead>
              <tbody>
                {submissions.data.map((row) => (
                  <tr key={row.id} className="border-b last:border-0 align-top">
                    <td className="p-3">
                      {row.invoice_url ? (
                        <Link href={row.invoice_url} className="text-primary hover:underline">
                          {row.invoice_number ?? '—'}
                        </Link>
                      ) : (
                        (row.invoice_number ?? '—')
                      )}
                    </td>
                    <td className="p-3">{row.type === 'cancel' ? 'ابطال' : 'اصلی'}</td>
                    <td className="p-3">
                      <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_TONE[row.status]}`}>
                        {STATUS_LABEL[row.status]}
                      </span>
                      {/* The reason, in Persian, next to the thing it happened to — the
                          spec's point about silent failure being the worst outcome. */}
                      {row.error_message ? (
                        <p className="mt-1 max-w-md text-xs text-danger text-pretty">
                          {row.error_message}
                          {row.error_code ? ` (${row.error_code})` : ''}
                        </p>
                      ) : null}
                    </td>
                    <td className="p-3 font-mono text-xs tabular-nums" dir="ltr">
                      {row.tax_id ?? '—'}
                    </td>
                    <td className="p-3 tabular-nums">{row.attempts}</td>
                    <td className="p-3 tabular-nums">{row.sent_at}</td>
                    <td className="p-3 text-end">
                      {canManage && row.status !== 'accepted' ? (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() =>
                            router.post(`/moadian/${row.id}/resend`, {}, { preserveScroll: true })
                          }
                        >
                          ارسال دوباره
                        </Button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Pagination links={submissions.links} total={submissions.total} unit="سند" />
      </div>
    </AppShell>
  );
}

function DisabledNotice({
  platformEnabled,
  shopEnabled,
}: {
  platformEnabled: boolean;
  shopEnabled: boolean;
}) {
  return (
    <div className="rounded-card border border-warning/25 bg-warning/5 p-4 text-sm">
      <p className="font-semibold">ارسال به سامانه مودیان هنوز فعال نیست.</p>

      {!platformEnabled ? (
        <p className="mt-2 text-pretty">
          این قابلیت <strong>به‌زودی</strong> فعال می‌شود. اتصال به یکی از شرکت‌های معتمد
          مالیاتی هنوز انتخاب نشده است و تا آن زمان هیچ سندی ارسال نمی‌شود — نه از این
          فروشگاه و نه از هیچ فروشگاه دیگری.
        </p>
      ) : !shopEnabled ? (
        <p className="mt-2 text-pretty">
          سکوی همیار آماده است، اما این فروشگاه هنوز اطلاعات مالیاتی‌اش را وارد نکرده و
          کلید ارسال را روشن نکرده است.
        </p>
      ) : null}
    </div>
  );
}

function Count({
  label,
  value,
  tone,
  onClick,
}: {
  label: string;
  value: number;
  tone?: 'danger' | 'warning';
  onClick: () => void;
}) {
  const accent =
    tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : 'text-foreground';

  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-card border p-4 text-start transition hover:border-primary/40"
    >
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`mt-1 text-xl font-semibold tabular-nums ${accent}`}>{value}</p>
    </button>
  );
}
