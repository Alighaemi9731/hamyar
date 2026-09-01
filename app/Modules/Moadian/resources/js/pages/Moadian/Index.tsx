import { Head, Link, router } from '@inertiajs/react';
import { InboxIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Pagination, type PaginationLink } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/domain/data-table';
import { FormErrors } from '@/components/domain/form-errors';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
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
 *
 * ## «ارسال دوباره» could fail with nothing on screen
 *
 * It posted and handled no refusal — no `onError`, no error region. A resend to the tax
 * authority can be declined for a dozen reasons, and every one of them came back as a
 * redirect that re-rendered an identical page. The shop pressed the button, watched
 * nothing change, and had no way to tell "sent again" from "refused again" on the one
 * screen whose entire subject is whether the tax office received something.
 *
 * ## The status labels stay local, deliberately
 *
 * `StatusBadge` would be the obvious component here and it would be wrong. Its map is a
 * single flat key space shared by every module: `rejected` there means «مرجوع بدون تعمیر»,
 * a repairs outcome, and `pending` means «در انتظار پرداخت». Both keys exist here with
 * entirely different meanings — «رد شده» by the tax authority, and «در صف». Adopting the
 * shared component would print the wrong Persian on a tax screen, which is worse than
 * having two maps.
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
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [resending, setResending] = useState<number | null>(null);

  function resend(id: number): void {
    setResending(id);
    setErrors({});

    router.post(
      `/moadian/${id}/resend`,
      {},
      {
        preserveScroll: true,
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setResending(null),
      }
    );
  }

  const filter = (next: string | null) => {
    router.get('/moadian', next ? { status: next } : {}, {
      preserveState: true,
      preserveScroll: true,
    });
  };

  return (
    <AppShell
      header={
        <PageHeader
          title="سامانه مودیان"
          description="وضعیت ارسال صورتحساب‌های الکترونیکی به سازمان امور مالیاتی."
        />
      }
    >
      <Head title="سامانه مودیان" />

      <div className="space-y-6">
        {/* A refused resend belongs to the document, not to any input. */}
        <FormErrors errors={errors} />

        {!enabled && <DisabledNotice platformEnabled={platformEnabled} shopEnabled={shopEnabled} />}

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Count label="در صف" value={counts.pending} onClick={() => filter('pending')} />
          <Count label="پذیرفته‌شده" value={counts.accepted} onClick={() => filter('accepted')} />
          <Count
            label="رد شده"
            value={counts.rejected}
            tone="danger"
            onClick={() => filter('rejected')}
          />
          <Count
            label="ناموفق"
            value={counts.failed}
            tone="warning"
            onClick={() => filter('failed')}
          />
        </div>

        {status ? (
          <Button variant="ghost" onClick={() => filter(null)}>
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
          <DataTable
            caption="اسناد ارسال‌شده به سامانه مودیان، تازه‌ترین اول."
            rows={submissions.data}
            rowKey={(row) => row.id}
            columns={[
              {
                key: 'invoice',
                header: 'فاکتور',
                cell: (row) =>
                  row.invoice_url ? (
                    <Link href={row.invoice_url} className="text-primary hover:underline">
                      <Num value={row.invoice_number ?? '—'} variant="ltr" />
                    </Link>
                  ) : (
                    <Num value={row.invoice_number ?? '—'} variant="ltr" />
                  ),
              },
              {
                key: 'type',
                header: 'نوع',
                cell: (row) => (row.type === 'cancel' ? 'ابطال' : 'اصلی'),
                secondary: true,
              },
              {
                key: 'status',
                header: 'وضعیت',
                cell: (row) => (
                  <>
                    <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_TONE[row.status]}`}>
                      {STATUS_LABEL[row.status]}
                    </span>
                    {/* The reason, in Persian, next to the thing it happened to — the
                        spec's point about silent failure being the worst outcome. */}
                    {row.error_message ? (
                      <p className="mt-1 max-w-md text-xs text-pretty text-danger">
                        {row.error_message}
                        {row.error_code ? ` (${row.error_code})` : ''}
                      </p>
                    ) : null}
                  </>
                ),
              },
              {
                key: 'tax_id',
                header: 'شناسه مالیاتی',
                cell: (row) => <Num value={row.tax_id ?? '—'} variant="ltr" />,
                secondary: true,
              },
              {
                key: 'attempts',
                header: 'تلاش',
                numeric: true,
                cell: (row) => <Num value={row.attempts} />,
                secondary: true,
              },
              {
                key: 'sent_at',
                header: 'تاریخ',
                cell: (row) => row.sent_at,
                secondary: true,
              },
              {
                key: 'resend',
                header: '',
                cell: (row) =>
                  canManage && row.status !== 'accepted' ? (
                    <Button
                      variant="outline"
                      disabled={resending === row.id}
                      onClick={() => resend(row.id)}
                    >
                      ارسال دوباره
                    </Button>
                  ) : null,
              },
            ]}
          />
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
          این قابلیت <strong>به‌زودی</strong> فعال می‌شود. اتصال به یکی از شرکت‌های معتمد مالیاتی
          هنوز انتخاب نشده است و تا آن زمان هیچ سندی ارسال نمی‌شود — نه از این فروشگاه و نه از هیچ
          فروشگاه دیگری.
        </p>
      ) : !shopEnabled ? (
        <p className="mt-2 text-pretty">
          سکوی همیار آماده است، اما این فروشگاه هنوز اطلاعات مالیاتی‌اش را وارد نکرده و کلید ارسال
          را روشن نکرده است.
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
