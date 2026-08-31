import { useForm } from '@inertiajs/react';
import { CheckIcon, CopyIcon, SendIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { Money } from '@/components/domain/money';
import { MoneyField } from '@/components/domain/money-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import type { MoneyValue } from '@/types';

interface ApprovalPanelProps {
  ticketId: number;
  estimate: MoneyValue;
  /** The figure the customer was actually shown, null until a quote is sent. */
  quoted: MoneyValue | null;
  approvedAmount: MoneyValue | null;
  approvedVia: string | null;
  approvedAt: string | null;
  declinedAt: string | null;
  /** The live link, or null once it has been used or was never minted. */
  approvalUrl: string | null;
  editable: boolean;
  error?: string;
}

/**
 * Asking the customer, and writing down what they said.
 *
 * ## The figure is frozen when the question is asked
 *
 * `quoted` is what the customer is looking at. The estimate on the ticket can move
 * afterwards — a technician opens the device and finds worse — and when it does, the
 * link already sent must stop working, because the person holding it would otherwise be
 * agreeing to a number nobody showed them. Re-quoting mints a new token and blanks any
 * previous answer.
 *
 * That is why the "resend" control is labelled as a re-quote and shows the amount: it is
 * not a convenience button, it is a new question.
 *
 * ## Phone approval is a separate button, not a shortcut
 *
 * Most shops settle this by phone. Recording it through the same control as a link tap
 * would lose `approved_via`, which is the thing that answers "how do we know they agreed"
 * when somebody disputes the bill.
 */
export function ApprovalPanel({
  ticketId,
  estimate,
  quoted,
  approvedAmount,
  approvedVia,
  approvedAt,
  declinedAt,
  approvalUrl,
  editable,
  error,
}: ApprovalPanelProps) {
  const toman = useTenantSettings().currency_display === 'toman';
  const [copied, setCopied] = useState(false);

  const quote = useForm<{ quoted_amount: number }>({ quoted_amount: estimate.value });
  const phone = useForm<{ note: string }>({ note: '' });

  const answered = approvedAt !== null || declinedAt !== null;

  return (
    <section className="space-y-3 rounded-card border border-border p-4">
      <h2 className="text-sm font-semibold">تأیید مشتری</h2>

      {error && (
        <p
          role="alert"
          className="rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {error}
        </p>
      )}

      {approvedAt && approvedAmount && (
        <p className="rounded-control bg-success/10 px-3 py-2 text-sm">
          مشتری مبلغ <Money rial={approvedAmount.value} digits="latin" withUnit /> را تأیید کرد
          {approvedVia === 'phone' ? ' (تلفنی)' : ' (لینک)'}.
        </p>
      )}

      {declinedAt && (
        <p className="rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive">
          مشتری این برآورد را تأیید نکرد.
        </p>
      )}

      {quoted && !answered && (
        <p className="text-sm text-muted-foreground">
          مبلغ اعلام‌شده به مشتری: <Money rial={quoted.value} digits="latin" withUnit />
        </p>
      )}

      {approvalUrl && !answered && (
        <div className="space-y-1">
          <Label htmlFor="approval-url">لینک تأیید</Label>
          <div className="flex gap-2">
            <Input id="approval-url" readOnly value={approvalUrl} dir="ltr" className="text-2xs" />
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => {
                navigator.clipboard?.writeText(approvalUrl);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 2000);
              }}
            >
              {copied ? (
                <CheckIcon className="size-4" aria-hidden />
              ) : (
                <CopyIcon className="size-4" aria-hidden />
              )}
              {copied ? 'کپی شد' : 'کپی'}
            </Button>
          </div>
          <p className="text-2xs text-muted-foreground">
            یک‌بارمصرف است و با تغییر برآورد باطل می‌شود.
          </p>
        </div>
      )}

      {editable && (
        <>
          <form
            className="space-y-2 border-t border-border pt-3"
            onSubmit={(event) => {
              event.preventDefault();
              quote.post(`/repairs/tickets/${ticketId}/approval/request`, { preserveScroll: true });
            }}
          >
            <Label htmlFor="quoted-amount">
              {quoted ? 'برآورد جدید (لینک قبلی باطل می‌شود)' : 'مبلغ برآورد'}
            </Label>
            <MoneyField
              id="quoted-amount"
              toman={toman}
              value={quote.data.quoted_amount}
              onChange={(value) => quote.setData('quoted_amount', value)}
            />
            {quote.errors.quoted_amount && (
              <p role="alert" className="text-sm text-destructive">
                {quote.errors.quoted_amount}
              </p>
            )}
            <Button type="submit" size="sm" disabled={quote.processing}>
              <SendIcon className="size-4" aria-hidden />
              {quoted ? 'ارسال برآورد جدید' : 'درخواست تأیید مشتری'}
            </Button>
          </form>

          {quoted && !answered && (
            <form
              className="space-y-2 border-t border-border pt-3"
              onSubmit={(event) => {
                event.preventDefault();
                phone.post(`/repairs/tickets/${ticketId}/approval/approve`, {
                  preserveScroll: true,
                });
              }}
            >
              <Label htmlFor="approval-note">تأیید تلفنی</Label>
              <Input
                id="approval-note"
                value={phone.data.note}
                placeholder="مثلاً: با آقای رضایی تماس گرفته شد"
                onChange={(event) => phone.setData('note', event.target.value)}
              />
              <div className="flex gap-2">
                <Button type="submit" size="sm" variant="secondary" disabled={phone.processing}>
                  <CheckIcon className="size-4" aria-hidden />
                  مشتری تأیید کرد
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() =>
                    phone.post(`/repairs/tickets/${ticketId}/approval/decline`, {
                      preserveScroll: true,
                    })
                  }
                >
                  <XIcon className="size-4" aria-hidden />
                  تأیید نکرد
                </Button>
              </div>
            </form>
          )}
        </>
      )}
    </section>
  );
}
