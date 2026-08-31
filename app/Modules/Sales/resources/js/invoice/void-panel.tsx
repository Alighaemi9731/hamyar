import { useForm } from '@inertiajs/react';
import { BanIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

import type { Invoice } from './types';

/** The keys `InvoiceController::void` validates. Only `reason` has a field. */
const FIELD_KEYS = ['reason'];

/**
 * Cancelling a sale, at the foot of the page and behind a dialog.
 *
 * ## Why it is not in the header
 *
 * Voiding returns stock, reverses the accounting entries and cannot be undone. It used
 * to sit in a row of six identical outlined buttons, one tab away from «چاپ A5». Moving
 * it to its own region at the end of the document makes reaching it a decision rather
 * than a reflex, and lets the region say what the action does before anybody opens it.
 *
 * ## Why not `ConfirmDialog`
 *
 * The shared confirmation is title / description / confirm — it has no field. The server
 * requires a reason (`required`, `min:3`, `max:500`) and stores it, because an invoice
 * cancelled for no recorded reason is one nobody can defend a year later. Widening
 * `ConfirmDialog` to take an input would change a component six other pages depend on to
 * serve one caller, so the dialog is composed here from the same primitives it uses.
 *
 * ## The refusal has somewhere to land
 *
 * The reason renders its own error, and `<FormErrors>` catches anything else the server
 * refuses — including the `invoice` key a `RuntimeException` from `VoidInvoice` arrives
 * under. The dialog stays open on failure; without that the message would render into a
 * panel nobody can see.
 *
 * The POST body is unchanged: `{ reason }` to `/sales/invoices/{id}/void`.
 */
export function VoidPanel({ invoice }: { invoice: Invoice }) {
  const [open, setOpen] = useState(false);
  const form = useForm({ reason: '' });

  function close(): void {
    setOpen(false);
    form.reset();
    form.clearErrors();
  }

  return (
    <section
      aria-labelledby="invoice-danger-heading"
      className="rounded-card border border-danger/25 bg-danger/5 p-5"
    >
      <h2 id="invoice-danger-heading" className="font-display text-base font-bold text-danger">
        ابطال فاکتور
      </h2>

      <p className="mt-1 max-w-prose text-sm text-muted-foreground">
        کالاها به انبار برمی‌گردند و اسناد حسابداری برگشت می‌خورند. شماره فاکتور حفظ می‌شود و سابقه
        باقی می‌ماند. این کار برگشت‌پذیر نیست.
      </p>

      <Button type="button" variant="destructive" className="mt-4" onClick={() => setOpen(true)}>
        <BanIcon className="size-4" aria-hidden />
        ابطال این فاکتور
      </Button>

      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (next) {
            setOpen(true);
          } else {
            close();
          }
        }}
      >
        <DialogContent dir="rtl">
          <form
            onSubmit={(event) => {
              event.preventDefault();
              form.post(`/sales/invoices/${invoice.id}/void`, {
                preserveScroll: true,
                // Explicit: if the dialog unmounted on a refusal the errors below would
                // render where nobody can read them.
                preserveState: true,
                onSuccess: close,
              });
            }}
          >
            <DialogHeader>
              <DialogTitle>ابطال فاکتور {invoice.number ?? ''}</DialogTitle>
              <DialogDescription>
                مبلغ <Money rial={invoice.totals.total.value} withUnit digits="latin" /> برگشت
                می‌خورد، کالاها به انبار بازمی‌گردند و سند حسابداری معکوس ثبت می‌شود.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3 py-2">
              <FormErrors errors={form.errors} handled={FIELD_KEYS} />

              <div className="space-y-1.5">
                <Label htmlFor="void-reason">دلیل ابطال</Label>
                <Textarea
                  id="void-reason"
                  rows={3}
                  autoFocus
                  value={form.data.reason}
                  aria-invalid={Boolean(form.errors.reason)}
                  aria-describedby={form.errors.reason ? 'void-reason-error' : undefined}
                  onChange={(event) => {
                    form.setData('reason', event.target.value);
                    form.clearErrors('reason');
                  }}
                />
                {form.errors.reason ? (
                  <p id="void-reason-error" role="alert" className="text-xs text-danger">
                    {form.errors.reason}
                  </p>
                ) : (
                  <p className="text-2xs text-muted-foreground">
                    این متن روی سابقهٔ فاکتور می‌ماند و بعداً قابل ویرایش نیست.
                  </p>
                )}
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={close}>
                انصراف
              </Button>
              <Button type="submit" variant="destructive" disabled={form.processing}>
                {form.processing ? 'در حال ابطال…' : 'ابطال فاکتور'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </section>
  );
}
