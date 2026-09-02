import { Head, Link, useForm } from '@inertiajs/react';
import { EyeIcon, LoaderCircleIcon } from 'lucide-react';
import { useState } from 'react';

import { DataTable } from '@/components/domain/data-table';
import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { ApprovalPanel } from '../../pos/approval-panel';
import { PartsPanel } from '../../pos/parts-panel';

import { MoneyLadder, MoneyRow } from '@/components/domain/money-ladder';
import { PageHeader } from '@/components/domain/page-header';
import { Card } from '@/components/ui/card';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface Props {
  ticket: {
    id: number;
    code: string;
    status: string;
    status_label: string;
    device: string;
    device_brand: string | null;
    device_colour: string | null;
    device_imei: string | null;
    party_name: string | null;
    technician_name: string | null;
    branch_name: string;
    priority: number;
    promised_at: string | null;
    created_at: string | null;
    ready_at: string | null;
    reported_issue: string;
    accessories: string[];
    estimate_amount: MoneyValue;
    approved_amount: MoneyValue | null;
    approved_at: string | null;
    approved_via: string | null;
    declined_at: string | null;
    quoted_amount: MoneyValue | null;
    approval_url: string | null;
    prepaid_amount: MoneyValue;
    warranty_days: number;
    /** A boolean. The code itself is never in these props — see the docblock below. */
    has_passcode: boolean;
    checklist: Array<{ label: string; answer: string; note: string | null }>;
    parts: Array<{
      id: number;
      name: string;
      variant_name: string | null;
      quantity: number;
      state: string;
      unit_price: MoneyValue;
    }>;
    history: Array<{
      id: number;
      from: string | null;
      to: string;
      actor: string | null;
      note: string | null;
      at: string | null;
    }>;
  };
  transitions: Array<{ value: string; label: string }>;
  can: { update: boolean; reveal_passcode: boolean; deliver: boolean };
  errors: Record<string, string>;
}

/**
 * One device, and everything that has happened to it.
 *
 * ## The passcode is not on this page
 *
 * `has_passcode` tells the UI whether to offer the button; the code itself is fetched
 * on demand from an endpoint that audits every read. That indirection is the point — a
 * value shipped in props is in the page source, in browser memory and in any screenshot
 * of the screen, whether or not a component draws asterisks over it.
 *
 * It is also deliberately not held in state after it is shown: closing the reveal drops
 * it, so a page left open on a counter does not keep somebody's unlock code alive in a
 * tab for the rest of the afternoon.
 */
export default function TicketShow({ ticket, transitions, can, errors }: Props) {
  const move = useForm<{ status: string; note: string }>({ status: '', note: '' });

  return (
    <AppShell
      header={
        <PageHeader
          eyebrow="تیکت تعمیر"
          title={ticket.device}
          back={{ href: '/repairs', label: 'همه تیکت‌ها' }}
          meta={
            <>
              <StatusBadge status={ticket.status} />
              <span className="text-sm text-muted-foreground">
                <Num value={ticket.code} variant="ltr" />
              </span>
              {ticket.device_imei && (
                <span className="text-sm text-muted-foreground">
                  <Num value={ticket.device_imei} variant="ltr" />
                </span>
              )}
              <span className="text-sm text-muted-foreground">
                {ticket.party_name ?? 'مشتری گذری'}
              </span>
            </>
          }
          actions={
            can.update && (
              <Button asChild>
                <Link href={`/repairs/tickets/${ticket.id}/deliver`}>تحویل دستگاه</Link>
              </Button>
            )
          }
        />
      }
    >
      <Head title={`تیکت ${ticket.code}`} />

      {/*
        Split at `xl`, not `lg`. The shell's sidebar appears at `lg` (1024), so the content
        column is *narrower* at 1024 than at 768 — a two-column split there squeezes the
        rail holding nine-digit rial figures. Same rule as the treasury summary.
      */}
      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="space-y-6">
          <Card asChild>
            <section className="space-y-2">
              <h2 className="text-sm font-semibold">ایراد اعلام‌شده</h2>
              <p className="text-sm whitespace-pre-line">{ticket.reported_issue}</p>
            </section>
          </Card>

          {ticket.checklist.length > 0 && (
            <section className="space-y-3">
              <h2 className="text-sm font-semibold">وضعیت دستگاه هنگام پذیرش</h2>

              {/*
                The record that settles «صفحه از قبل شکسته بود» three weeks later, so it is
                a real table with real headers: the raw one here had no `<thead>` at all,
                which left a screen reader reading three unlabelled cells per row and no way
                to tell the answer from the note.
              */}
              <DataTable
                caption="وضعیت دستگاه در زمان پذیرش، همان‌طور که هنگام تحویل گرفتن ثبت شد."
                rows={ticket.checklist}
                rowKey={(item) => item.label}
                columns={[
                  { key: 'label', header: 'مورد', cell: (item) => item.label },
                  { key: 'answer', header: 'وضعیت', cell: (item) => item.answer },
                  {
                    key: 'note',
                    header: 'توضیح',
                    // Most rows have none, and a column of dashes reads as missing data
                    // rather than as nothing to say.
                    cell: (item) => item.note ?? '',
                    secondary: true,
                  },
                ]}
              />
            </section>
          )}

          <ApprovalPanel
            ticketId={ticket.id}
            estimate={ticket.estimate_amount}
            quoted={ticket.quoted_amount}
            approvedAmount={ticket.approved_amount}
            approvedVia={ticket.approved_via}
            approvedAt={ticket.approved_at}
            declinedAt={ticket.declined_at}
            approvalUrl={ticket.approval_url}
            editable={can.update && transitions.length > 0}
            error={errors.approval}
          />

          <PartsPanel
            ticketId={ticket.id}
            parts={ticket.parts}
            // A closed ticket still shows what was fitted — the customer paid for those
            // parts and the record has to survive — but nothing on it can be moved.
            editable={can.update && transitions.length > 0}
            error={errors.parts}
          />

          <section className="space-y-3">
            <h2 className="text-sm font-semibold">تاریخچه</h2>
            <ol className="space-y-2">
              {ticket.history.map((entry) => (
                <li
                  key={entry.id}
                  className="flex flex-wrap items-baseline justify-between gap-2 rounded-control border border-border px-3 py-2 text-sm"
                >
                  <span>
                    {entry.from ? `${entry.from} ← ${entry.to}` : entry.to}
                    {entry.note && (
                      <span className="ms-2 text-2xs text-muted-foreground">{entry.note}</span>
                    )}
                  </span>
                  <span className="text-2xs text-muted-foreground">
                    {entry.actor} · {formatJalali(entry.at, { withTime: true })}
                  </span>
                </li>
              ))}
            </ol>
          </section>
        </div>

        <aside className="space-y-6">
          {/*
            Status, device, IMEI and customer moved up into the page header, where the
            identity of the record belongs. What is left here is the detail somebody looks
            up rather than reads on arrival.
          */}
          <Card asChild>
            <dl className="space-y-2 text-sm">
              <Row label="تعمیرکار">{ticket.technician_name ?? '—'}</Row>
              <Row label="پذیرش">{formatJalali(ticket.created_at)}</Row>
              {ticket.promised_at && (
                <Row label="وعده تحویل">{formatJalali(ticket.promised_at)}</Row>
              )}
            </dl>
          </Card>

          <Card asChild>
            <section className="space-y-3">
              <h2 className="text-sm font-semibold">مبالغ</h2>

              {/* A ladder, so the three figures share one right edge and can be compared
                  at a glance — which is the only reason to print them together. */}
              <MoneyLadder className="text-sm">
                <MoneyRow label="برآورد" rial={ticket.estimate_amount.value} />
                {ticket.approved_amount && (
                  <MoneyRow label="تأییدشده" rial={ticket.approved_amount.value} />
                )}
                {ticket.prepaid_amount.value > 0 && (
                  <MoneyRow label="پیش‌پرداخت" rial={ticket.prepaid_amount.value} />
                )}
              </MoneyLadder>
            </section>
          </Card>

          {ticket.has_passcode && (
            <PasscodePanel ticketId={ticket.id} allowed={can.reveal_passcode} />
          )}

          {can.update && transitions.length > 0 && (
            <Card asChild>
              <form
                className="space-y-4"
                onSubmit={(event) => {
                  event.preventDefault();
                  move.post(`/repairs/tickets/${ticket.id}/transition`, {
                    onSuccess: () => move.reset(),
                  });
                }}
              >
                <h2 className="text-sm font-semibold">تغییر وضعیت</h2>

                <Select value={move.data.status} onValueChange={(v) => move.setData('status', v)}>
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder="وضعیت جدید" />
                  </SelectTrigger>
                  <SelectContent dir="rtl">
                    {transitions.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>

                {move.errors.status && (
                  <p role="alert" className="text-sm text-destructive">
                    {move.errors.status}
                  </p>
                )}

                {/* `note` is validated by both approval endpoints and rendered by neither.
                  A note over 255 characters — a technician pasting a diagnosis — was
                  refused with a 302 and no visible change. */}
                <FormErrors errors={move.errors} handled={['status']} />

                <div className="space-y-2">
                  <Label htmlFor="move-note">یادداشت</Label>
                  <Input
                    id="move-note"
                    value={move.data.note}
                    onChange={(event) => move.setData('note', event.target.value)}
                  />
                </div>

                <Button type="submit" disabled={!move.data.status || move.processing}>
                  ثبت تغییر
                </Button>
              </form>
            </Card>
          )}
        </aside>
      </div>
    </AppShell>
  );
}

/**
 * The reveal.
 *
 * Fetched on demand, never held in props, and dropped again when the panel closes. The
 * shop's activity log records every press of this button.
 */
function PasscodePanel({ ticketId, allowed }: { ticketId: number; allowed: boolean }) {
  const [code, setCode] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);

  if (!allowed) {
    return (
      <p className="rounded-control border border-dashed border-border px-3 py-3 text-2xs text-muted-foreground">
        برای این دستگاه رمز ثبت شده است. دسترسی مشاهده آن را ندارید.
      </p>
    );
  }

  return (
    <Card className="space-y-2">
      <h2 className="text-sm font-semibold">رمز دستگاه</h2>

      {code === null ? (
        <>
          <Button
            type="button"
            variant="outline"
            disabled={loading}
            onClick={() => {
              setLoading(true);
              setFailed(false);

              fetch(`/repairs/tickets/${ticketId}/passcode`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              })
                .then((response) => {
                  if (!response.ok) throw new Error(String(response.status));

                  return response.json() as Promise<{ passcode: string | null }>;
                })
                .then((payload) => setCode(payload.passcode ?? '—'))
                .catch(() => setFailed(true))
                .finally(() => setLoading(false));
            }}
          >
            {loading ? (
              <LoaderCircleIcon className="size-4 animate-spin" aria-hidden />
            ) : (
              <EyeIcon className="size-4" aria-hidden />
            )}
            نمایش رمز
          </Button>

          <p className="text-2xs text-muted-foreground">
            هر بار مشاهده رمز در گزارش فعالیت فروشگاه ثبت می‌شود.
          </p>
        </>
      ) : (
        <>
          <p className="tabular text-lg font-semibold" dir="ltr">
            {code}
          </p>
          <Button type="button" variant="ghost" onClick={() => setCode(null)}>
            پنهان کردن
          </Button>
        </>
      )}

      {failed && (
        <p role="alert" className="text-sm text-destructive">
          رمز دریافت نشد. دوباره تلاش کنید.
        </p>
      )}
    </Card>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-2">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end">{children}</dd>
    </div>
  );
}
