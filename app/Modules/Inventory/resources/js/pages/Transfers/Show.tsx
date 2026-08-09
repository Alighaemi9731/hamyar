import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRightIcon, PackageCheckIcon, SendIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type UnitOption, UnitPicker } from '@/components/domain/unit-picker';
import { type VariantOption, VariantPicker } from '@/components/domain/variant-picker';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { formError } from '@/lib/forms';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';

interface Transfer {
  id: number;
  number: string;
  status: string;
  from: string;
  from_warehouse_id: number;
  to: string;
  notes: string | null;
  dispatched_at: string | null;
  received_at: string | null;
  is_draft: boolean;
  is_dispatched: boolean;
}

interface Line {
  id: number;
  product_name: string;
  variant_name: string;
  is_serialized: boolean;
  imei: string | null;
  quantity: number;
  received_quantity: number | null;
  available: number;
}

interface Props {
  transfer: Transfer;
  lines: Line[];
  can: { manage: boolean };
}

/**
 * One transfer, through its two steps.
 *
 * The receipt form asks what actually arrived rather than assuming the dispatched
 * quantity. Five sent and three received is not a rounding error to smooth over — it
 * is a van, a driver and a missing pair of phones, and the shortfall is recorded so
 * somebody can go and ask.
 */
export default function TransferShow({ transfer, lines, can }: Props) {
  return (
    <AppShell
      title={`حواله ${transfer.number}`}
      actions={
        <Button variant="outline" asChild>
          <Link href="/inventory/transfers">
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت
          </Link>
        </Button>
      }
    >
      <Head title={`حواله ${transfer.number}`} />

      <section className="rounded-card border border-border bg-card p-6 sm:p-8">
        <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="از انبار">{transfer.from}</Fact>
          <Fact label="به انبار">{transfer.to}</Fact>
          <Fact label="ارسال">
            {transfer.dispatched_at ? (
              <span className="tabular">
                {formatJalali(transfer.dispatched_at, { longMonth: true })}
              </span>
            ) : (
              <span className="text-muted-foreground">هنوز ارسال نشده</span>
            )}
          </Fact>
          <Fact label="تحویل">
            {transfer.received_at ? (
              <span className="tabular">
                {formatJalali(transfer.received_at, { longMonth: true })}
              </span>
            ) : (
              <span className="text-muted-foreground">هنوز تحویل نشده</span>
            )}
          </Fact>
        </dl>

        {transfer.is_dispatched && (
          <p className="mt-6 rounded-control border border-warning/25 bg-warning/10 px-4 py-3 text-sm text-warning">
            این کالا در راه است: از انبار مبدأ خارج شده و هنوز به مقصد نرسیده، بنابراین در هیچ‌کدام
            قابل فروش نیست.
          </p>
        )}
      </section>

      <div className="mt-6 space-y-6">
        {transfer.is_draft && can.manage && (
          <AddLine transferId={transfer.id} warehouseId={transfer.from_warehouse_id} />
        )}

        <Lines transfer={transfer} lines={lines} canManage={can.manage} />
      </div>
    </AppShell>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <dt className="text-2xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

/* --------------------------------------------------------------- add line -- */

function AddLine({ transferId, warehouseId }: { transferId: number; warehouseId: number }) {
  const [unit, setUnit] = useState<UnitOption | null>(null);
  const [variant, setVariant] = useState<VariantOption | null>(null);
  const [quantity, setQuantity] = useState('1');
  const form = useForm({});

  function submit(payload: Record<string, number | null>): void {
    form.transform(() => payload);
    form.post(`/inventory/transfers/${transferId}/lines`, {
      preserveScroll: true,
      onSuccess: () => {
        setUnit(null);
        setVariant(null);
        setQuantity('1');
      },
    });
  }

  return (
    <SettingsSection
      title="افزودن ردیف"
      description="دستگاه سریال‌دار را اسکن کنید یا کالای عادی و تعدادش را انتخاب کنید. فقط کالایی که در انبار مبدأ هست قابل حواله‌کردن است."
    >
      {formError(form.errors, 'line') && (
        <p className="mb-4 text-sm text-danger">{formError(form.errors, 'line')}</p>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-2">
          <Label>اسکن دستگاه</Label>
          <UnitPicker
            value={unit}
            onChange={(next) => {
              setUnit(next);
              if (next) submit({ product_unit_id: next.id });
            }}
            warehouseId={warehouseId}
          />
          <p className="text-xs text-muted-foreground">
            با اسکن، دستگاه بلافاصله به حواله اضافه می‌شود.
          </p>
        </div>

        <form
          className="grid gap-3 sm:grid-cols-[1fr_7rem_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            submit({
              product_variant_id: variant?.id ?? null,
              quantity: Number(toLatinDigits(quantity).replace(/\D/g, '') || '0'),
            });
          }}
        >
          <div className="space-y-2">
            <Label htmlFor="transfer-variant">کالای عادی</Label>
            <VariantPicker id="transfer-variant" value={variant} onChange={setVariant} />
          </div>

          <div className="space-y-2">
            <Label htmlFor="transfer-quantity">تعداد</Label>
            <Input
              id="transfer-quantity"
              dir="ltr"
              inputMode="numeric"
              className="tabular h-11 text-center"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
            />
          </div>

          <Button type="submit" disabled={form.processing || variant === null}>
            افزودن
          </Button>
        </form>
      </div>
    </SettingsSection>
  );
}

/* ------------------------------------------------------------------ lines -- */

function Lines({
  transfer,
  lines,
  canManage,
}: {
  transfer: Transfer;
  lines: Line[];
  canManage: boolean;
}) {
  const [confirmingDispatch, setConfirmingDispatch] = useState(false);
  const dispatchForm = useForm({});

  // Pre-filled with what was sent: the common case is that everything arrived, and
  // making someone retype nine numbers to say so is how they stop checking.
  const [counted, setCounted] = useState<Record<number, string>>(() =>
    Object.fromEntries(
      lines.map((line) => [line.id, String(line.received_quantity ?? line.quantity)])
    )
  );
  const receiveForm = useForm({});

  if (lines.length === 0) {
    return (
      <SettingsSection>
        <EmptyState
          title="این حواله هنوز ردیفی ندارد"
          description="یک دستگاه اسکن کنید یا کالای عادی اضافه کنید."
        />
      </SettingsSection>
    );
  }

  const shortfall = lines.reduce(
    (total, line) => total + Math.max(0, line.quantity - (line.received_quantity ?? line.quantity)),
    0
  );

  return (
    <SettingsSection title="ردیف‌های حواله" variant="flush">
      <ul className="mt-6 divide-y divide-border border-t border-border">
        {lines.map((line) => (
          <li
            key={line.id}
            className="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-2 px-6 py-3 sm:px-7"
          >
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">{line.product_name}</span>
              <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                <span className="truncate">{line.variant_name}</span>
                {line.imei && (
                  <>
                    <span aria-hidden>·</span>
                    <Num value={line.imei} variant="ltr" />
                  </>
                )}
              </span>
            </span>

            <span className="text-sm tabular">
              <Num value={line.quantity} variant="table" />
              {!line.is_serialized && transfer.is_draft && (
                <span
                  className={cn(
                    'ms-2 text-2xs',
                    line.available < line.quantity ? 'text-danger' : 'text-muted-foreground'
                  )}
                >
                  موجودی مبدأ: <Num value={line.available} variant="table" />
                </span>
              )}
            </span>

            {transfer.is_dispatched && canManage && (
              <label className="flex items-center gap-2">
                <span className="text-2xs text-muted-foreground">رسیده</span>
                <Input
                  dir="ltr"
                  inputMode="numeric"
                  className="tabular h-10 w-20 text-center"
                  value={counted[line.id] ?? ''}
                  onChange={(event) => setCounted({ ...counted, [line.id]: event.target.value })}
                />
              </label>
            )}

            {line.received_quantity !== null && (
              <span
                className={cn(
                  'text-2xs',
                  line.received_quantity < line.quantity ? 'text-danger' : 'text-success'
                )}
              >
                {line.received_quantity < line.quantity ? (
                  <>
                    <Num value={line.quantity - line.received_quantity} /> عدد کسری
                  </>
                ) : (
                  'کامل تحویل شد'
                )}
              </span>
            )}

            {transfer.is_draft && canManage && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="حذف ردیف"
                className="group"
                onClick={() =>
                  router.delete(`/inventory/transfers/${transfer.id}/lines/${line.id}`, {
                    preserveScroll: true,
                  })
                }
              >
                <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
              </Button>
            )}
          </li>
        ))}
      </ul>

      {canManage && (transfer.is_draft || transfer.is_dispatched) && (
        <div className="flex flex-wrap items-center gap-3 p-6 sm:p-7">
          {transfer.is_draft && (
            <>
              <Button onClick={() => setConfirmingDispatch(true)}>
                <SendIcon className="size-4" />
                ارسال
              </Button>
              <p className="text-xs text-muted-foreground">
                با ارسال، کالا از انبار مبدأ خارج می‌شود و حواله دیگر قابل ویرایش نیست.
              </p>
            </>
          )}

          {transfer.is_dispatched && (
            <>
              <Button
                disabled={receiveForm.processing}
                onClick={() => {
                  receiveForm.transform(() => ({
                    counted: Object.fromEntries(
                      Object.entries(counted).map(([id, value]) => [
                        id,
                        Number(toLatinDigits(value).replace(/\D/g, '') || '0'),
                      ])
                    ),
                  }));

                  receiveForm.post(`/inventory/transfers/${transfer.id}/receive`, {
                    preserveScroll: true,
                  });
                }}
              >
                <PackageCheckIcon className="size-4" />
                ثبت تحویل
              </Button>
              <p className="text-xs text-muted-foreground">
                عددها را با آنچه واقعاً رسیده مطابقت دهید؛ کسری ثبت می‌شود، نه نادیده گرفته.
              </p>
            </>
          )}

          {formError(receiveForm.errors, 'receive') && (
            <p className="text-sm text-danger">{formError(receiveForm.errors, 'receive')}</p>
          )}
          {formError(dispatchForm.errors, 'dispatch') && (
            <p className="text-sm text-danger">{formError(dispatchForm.errors, 'dispatch')}</p>
          )}
        </div>
      )}

      {transfer.status === 'received' && shortfall > 0 && (
        <p className="border-t border-border p-6 text-sm text-danger sm:p-7">
          در مجموع <Num value={shortfall} /> عدد کمتر از آنچه ارسال شده بود تحویل گرفته شد.
        </p>
      )}

      <ConfirmDialog
        open={confirmingDispatch}
        onOpenChange={setConfirmingDispatch}
        destructive={false}
        title={`ارسال حواله ${transfer.number}`}
        description="کالا همین حالا از انبار مبدأ کم می‌شود و تا ثبت تحویل، در هیچ انباری قابل فروش نیست."
        confirmLabel="ارسال"
        processing={dispatchForm.processing}
        onConfirm={() =>
          dispatchForm.post(`/inventory/transfers/${transfer.id}/dispatch`, {
            preserveScroll: true,
            onFinish: () => setConfirmingDispatch(false),
          })
        }
      />
    </SettingsSection>
  );
}
