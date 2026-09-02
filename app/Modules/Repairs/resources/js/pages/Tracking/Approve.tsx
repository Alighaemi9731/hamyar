import { Head, useForm } from '@inertiajs/react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';
import { useState } from 'react';

interface Props {
  ticket: {
    code: string;
    device: string;
    reported_issue: string;
    quoted_amount: MoneyValue;
    requested_at: string | null;
  };
  shop: { name: string; phone: string | null };
  token: string;
}

/**
 * The customer approving a repair quote from their phone.
 *
 * ## The figure is the page
 *
 * Somebody is being asked to commit money to a device they cannot see. The amount gets
 * the most weight, the work being paid for is stated in the words they used at the
 * counter, and the two buttons are unambiguous. No small print, no upsell, nothing else
 * to read.
 *
 * ## The amount shown is frozen, not live
 *
 * It is `approval_quoted_amount`, captured when the link was minted — never the current
 * estimate. If the shop re-quotes, this link stops working and a new one is sent, so
 * nobody can approve a number they were not shown. See `QuoteApproval`.
 *
 * ## Declining does not close the ticket
 *
 * It records the answer and stops the work. What happens to the device next — returned
 * unrepaired, re-quoted lower, collected as-is — is a conversation, and the software
 * should not make that decision on the shop's behalf.
 *
 * ## A refusal is shown, on the one page where silence is worst
 *
 * Both submits posted with no error region. The server can decline either one — the link
 * has expired, the shop re-quoted and this token is stale, the answer was already given
 * from another phone — and every one of those came back as a redirect that re-rendered
 * this page identically. A customer holding their phone at arm's length tapped «تأیید
 * می‌کنم», nothing changed, and they concluded the shop's software was broken. On the page
 * that authorises spending, that is the worst possible outcome; `<FormErrors>` takes the
 * whole bag so a key nobody thought to place still has somewhere to appear.
 */
export default function TicketApprove({ ticket, shop, token }: Props) {
  const [declining, setDeclining] = useState(false);

  const approve = useForm({});
  const decline = useForm({ note: '' });

  return (
    <div className="min-h-dvh bg-muted/30 px-4 py-8">
      <Head title={`تأیید هزینه تعمیر ${ticket.code}`} />

      <div className="mx-auto max-w-md space-y-4">
        <header className="space-y-1 text-center">
          <h1 className="text-lg font-bold">{shop.name}</h1>
          {shop.phone && (
            <p className="text-2xs text-muted-foreground">
              <Num value={shop.phone} variant="ltr" />
            </p>
          )}
        </header>

        <div className="space-y-4 rounded-card border border-border bg-background p-6">
          <div className="space-y-1 text-center">
            <p className="text-sm text-muted-foreground">هزینه تعمیر دستگاه شما</p>
            <p className="text-3xl font-bold" data-testid="quoted-amount">
              <Money rial={ticket.quoted_amount.value} digits="latin" withUnit />
            </p>
          </div>

          <dl className="space-y-2 border-t border-border pt-4 text-sm">
            <div className="flex items-baseline justify-between gap-2">
              <dt className="text-muted-foreground">شماره قبض</dt>
              <dd>
                <Num value={ticket.code} variant="ltr" />
              </dd>
            </div>
            <div className="flex items-baseline justify-between gap-2">
              <dt className="text-muted-foreground">دستگاه</dt>
              <dd>{ticket.device}</dd>
            </div>
            <div className="flex items-baseline justify-between gap-2">
              <dt className="shrink-0 text-muted-foreground">ایراد</dt>
              {/* `text-start`, not `text-end`: this is Persian prose, and in RTL `text-end`
                  is the physical left — a two-line description was ragged on its
                  reading edge. */}
              <dd className="min-w-0 text-start">{ticket.reported_issue}</dd>
            </div>
            {ticket.requested_at && (
              <div className="flex items-baseline justify-between gap-2">
                <dt className="text-muted-foreground">تاریخ برآورد</dt>
                <dd>{formatJalali(ticket.requested_at)}</dd>
              </div>
            )}
          </dl>

          {!declining ? (
            <div className="space-y-2 border-t border-border pt-4">
              <FormErrors errors={approve.errors} />

              <Button
                type="button"
                className="h-12 w-full text-base"
                disabled={approve.processing}
                onClick={() => approve.post(`/a/${token}/approve`)}
                data-testid="approve"
              >
                تأیید می‌کنم، تعمیر شود
              </Button>

              <Button
                type="button"
                variant="ghost"
                className="w-full"
                onClick={() => setDeclining(true)}
              >
                فعلاً نه
              </Button>
            </div>
          ) : (
            <form
              className="space-y-3 border-t border-border pt-4"
              onSubmit={(event) => {
                event.preventDefault();
                decline.post(`/a/${token}/decline`);
              }}
            >
              <FormErrors errors={decline.errors} handled={['note']} />

              <div className="space-y-2">
                <Label htmlFor="decline-note">اگر توضیحی دارید بنویسید (اختیاری)</Label>
                <Input
                  id="decline-note"
                  aria-invalid={Boolean(decline.errors.note)}
                  aria-describedby={decline.errors.note ? 'decline-note-error' : undefined}
                  value={decline.data.note}
                  onChange={(event) => decline.setData('note', event.target.value)}
                  placeholder="مثلاً: فعلاً منصرف شدم"
                />
                {/* `handled={['note']}` above is a promise that this key has a home. The
                    first draft made the promise and rendered nothing — the server refused a
                    301-character note, `aria-invalid` went on, and no text appeared. */}
                {decline.errors.note && (
                  <p id="decline-note-error" role="alert" className="text-sm text-destructive">
                    {decline.errors.note}
                  </p>
                )}
              </div>

              <Button
                type="submit"
                variant="destructive"
                className="w-full"
                disabled={decline.processing}
              >
                ثبت پاسخ
              </Button>

              <Button
                type="button"
                variant="ghost"
                className="w-full"
                onClick={() => setDeclining(false)}
              >
                بازگشت
              </Button>
            </form>
          )}
        </div>

        <p className="text-center text-2xs text-muted-foreground">
          پس از تأیید، کار روی دستگاه شروع می‌شود. برای هر سؤالی با فروشگاه تماس بگیرید.
        </p>
      </div>
    </div>
  );
}
