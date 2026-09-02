import { Head } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface Props {
  ticket: {
    code: string;
    status: string;
    status_label: string;
    is_ready: boolean;
    device: string;
    received_at: string | null;
    promised_at: string | null;
    ready_at: string | null;
    amount_due: MoneyValue;
    is_estimate: boolean;
    timeline: Array<{ id: number; label: string; at: string | null }>;
  };
  shop: { name: string; address: string | null; phone: string | null };
}

/**
 * The page a customer opens from the QR on their repair receipt.
 *
 * ## Not inside `AppShell`
 *
 * There is no signed-in user and no navigation. Every link into the shop's application
 * would be a link offered to whoever is holding a piece of paper.
 *
 * ## Designed for the one question being asked
 *
 * Nobody opens this to browse. They open it to find out whether their phone is ready, so
 * the status is the largest thing on the screen and «آماده تحویل» gets a colour and a
 * tick that nothing else on the page competes with. Everything below it is context for
 * that one answer.
 *
 * ## What it does not show, and why
 *
 * No IMEI, no customer name, no technician, no internal notes, no other ticket. See
 * `PublicTrackingController` — the short version is that a repair status tells a reader
 * that a named person's device is out of their hands, which is worth more to the wrong
 * reader than an invoice ever is.
 */
export default function TrackingShow({ ticket, shop }: Props) {
  return (
    <div className="min-h-dvh bg-muted/30 px-4 py-8">
      <Head title={`پیگیری تعمیر ${ticket.code}`} />

      <div className="mx-auto max-w-md space-y-4">
        <header className="space-y-1 text-center">
          <h1 className="text-lg font-bold">{shop.name}</h1>
          {shop.phone && (
            <p className="text-2xs text-muted-foreground">
              <Num value={shop.phone} variant="ltr" />
            </p>
          )}
        </header>

        {/* The one question. */}
        <div
          className={cn(
            'rounded-card border p-6 text-center',
            ticket.is_ready ? 'border-success/30 bg-success/5' : 'border-border bg-background'
          )}
        >
          {ticket.is_ready && (
            <span className="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-success/15">
              <CheckIcon className="size-6 text-success" aria-hidden />
            </span>
          )}

          <p className="text-2xs text-muted-foreground">وضعیت دستگاه شما</p>
          <p
            className={cn('mt-1 text-2xl font-bold', ticket.is_ready && 'text-success')}
            data-testid="tracking-status"
          >
            {ticket.status_label}
          </p>

          {ticket.is_ready && (
            <p className="mt-2 text-sm">دستگاه آماده تحویل است؛ با همراه داشتن قبض مراجعه کنید.</p>
          )}
        </div>

        <dl className="space-y-2 rounded-card border border-border bg-background p-4 text-sm">
          <Row label="شماره قبض">
            <Num value={ticket.code} variant="ltr" />
          </Row>
          <Row label="دستگاه">{ticket.device}</Row>
          <Row label="تاریخ پذیرش">{formatJalali(ticket.received_at)}</Row>
          {ticket.promised_at && <Row label="وعده تحویل">{formatJalali(ticket.promised_at)}</Row>}
          {ticket.amount_due.value > 0 && (
            <Row label={ticket.is_estimate ? 'برآورد اولیه' : 'مبلغ قابل پرداخت'}>
              <Money rial={ticket.amount_due.value} digits="latin" withUnit />
            </Row>
          )}
        </dl>

        <section className="space-y-2 rounded-card border border-border bg-background p-4">
          <h2 className="text-sm font-semibold">مراحل</h2>
          <ol className="space-y-2">
            {ticket.timeline.map((entry, index) => (
              <li key={entry.id} className="flex items-baseline justify-between gap-3 text-sm">
                <span className="flex items-baseline gap-2">
                  <span
                    className={cn(
                      'inline-block size-2 shrink-0 rounded-full',
                      index === ticket.timeline.length - 1 ? 'bg-primary' : 'bg-border'
                    )}
                    aria-hidden
                  />
                  {entry.label}
                </span>
                <span className="text-2xs text-muted-foreground">{formatJalali(entry.at)}</span>
              </li>
            ))}
          </ol>
        </section>

        {shop.address && (
          <p className="text-center text-2xs text-muted-foreground">{shop.address}</p>
        )}

        <p className="text-center text-2xs text-muted-foreground">
          برای هر سؤالی با فروشگاه تماس بگیرید.
        </p>
      </div>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-2">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      {/* `text-start`, not `text-end`: in RTL `text-end` is the physical left, so a device
          name that wraps was ragged on its reading edge. An LTR number inside isolates
          itself and is unaffected either way. */}
      <dd className="min-w-0 text-start">{children}</dd>
    </div>
  );
}
