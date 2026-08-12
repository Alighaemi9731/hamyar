import { Head, useForm } from '@inertiajs/react';
import { EyeIcon, LoaderCircleIcon } from 'lucide-react';
import { useState } from 'react';

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
import { PartsPanel } from '../../pos/parts-panel';

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
    <AppShell title={`تیکت ${ticket.code}`}>
      <Head title={`تیکت ${ticket.code}`} />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="space-y-6">
          <section className="space-y-2 rounded-card border border-border p-4">
            <h2 className="text-sm font-semibold">ایراد اعلام‌شده</h2>
            <p className="text-sm whitespace-pre-line">{ticket.reported_issue}</p>
          </section>

          {ticket.checklist.length > 0 && (
            <section className="space-y-2">
              <h2 className="text-sm font-semibold">وضعیت دستگاه هنگام پذیرش</h2>
              <div className="overflow-x-auto rounded-card border border-border">
                <table className="w-full text-sm">
                  <tbody>
                    {ticket.checklist.map((item) => (
                      <tr key={item.label} className="border-b border-border last:border-0">
                        <td className="p-3 text-muted-foreground">{item.label}</td>
                        <td className="p-3">{item.answer}</td>
                        <td className="p-3 text-2xs text-muted-foreground">{item.note}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          <PartsPanel
            ticketId={ticket.id}
            parts={ticket.parts}
            // A closed ticket still shows what was fitted — the customer paid for those
            // parts and the record has to survive — but nothing on it can be moved.
            editable={can.update && transitions.length > 0}
            error={errors.parts}
          />

          <section className="space-y-2">
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

        <aside className="space-y-5">
          <dl className="space-y-2 rounded-card border border-border p-4 text-sm">
            <Row label="وضعیت">
              <StatusBadge status={ticket.status} />
            </Row>
            <Row label="دستگاه">{ticket.device}</Row>
            {ticket.device_imei && (
              <Row label="IMEI">
                <Num value={ticket.device_imei} variant="ltr" />
              </Row>
            )}
            <Row label="مشتری">{ticket.party_name ?? 'مشتری گذری'}</Row>
            <Row label="تعمیرکار">{ticket.technician_name ?? '—'}</Row>
            <Row label="پذیرش">{formatJalali(ticket.created_at)}</Row>
            {ticket.promised_at && <Row label="وعده تحویل">{formatJalali(ticket.promised_at)}</Row>}
          </dl>

          <dl className="space-y-1 rounded-card border border-border p-4 text-sm">
            <div className="flex items-baseline justify-between">
              <dt className="text-muted-foreground">برآورد</dt>
              <dd>
                <Money rial={ticket.estimate_amount.value} digits="latin" />
              </dd>
            </div>
            {ticket.approved_amount && (
              <div className="flex items-baseline justify-between">
                <dt className="text-muted-foreground">تأییدشده</dt>
                <dd>
                  <Money rial={ticket.approved_amount.value} digits="latin" />
                </dd>
              </div>
            )}
            {ticket.prepaid_amount.value > 0 && (
              <div className="flex items-baseline justify-between">
                <dt className="text-muted-foreground">پیش‌پرداخت</dt>
                <dd>
                  <Money rial={ticket.prepaid_amount.value} digits="latin" />
                </dd>
              </div>
            )}
          </dl>

          {ticket.has_passcode && (
            <PasscodePanel ticketId={ticket.id} allowed={can.reveal_passcode} />
          )}

          {can.update && transitions.length > 0 && (
            <form
              className="space-y-3 rounded-card border border-border p-4"
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
    <div className="space-y-2 rounded-card border border-border p-4">
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
          <Button type="button" variant="ghost" size="sm" onClick={() => setCode(null)}>
            پنهان کردن
          </Button>
        </>
      )}

      {failed && (
        <p role="alert" className="text-sm text-destructive">
          رمز دریافت نشد. دوباره تلاش کنید.
        </p>
      )}
    </div>
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
