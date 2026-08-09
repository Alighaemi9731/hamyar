import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRightIcon, CheckCheckIcon, EyeOffIcon, ListPlusIcon } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
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

interface Count {
  id: number;
  number: string;
  status: string;
  warehouse: string;
  warehouse_id: number;
  is_blind: boolean;
  is_open: boolean;
  notes: string | null;
  applied_at: string | null;
  reveals_expected: boolean;
  variance: number | null;
}

interface Line {
  id: number;
  product_name: string;
  variant_name: string;
  counted_quantity: number | null;
  expected_quantity: number | null;
  variance: number | null;
}

interface Props {
  count: Count;
  lines: Line[];
  can: { manage: boolean };
}

/**
 * One count sheet.
 *
 * While the sheet is open and blind, the expected quantity is not on this page at all
 * — not hidden with CSS, simply not sent. Hiding it in the DOM would put it one
 * devtools panel away from the person whose independence blind counting exists to
 * protect.
 *
 * Applying writes the *difference* as an adjustment movement. Uncounted lines stay
 * null and are skipped: an unvisited shelf is not an empty shelf.
 */
export default function CountShow({ count, lines, can }: Props) {
  const [counted, setCounted] = useState<Record<number, string>>(() =>
    Object.fromEntries(
      lines.map((line) => [
        line.id,
        line.counted_quantity === null ? '' : String(line.counted_quantity),
      ])
    )
  );
  const [applying, setApplying] = useState(false);

  const save = useForm({});
  const apply = useForm({});
  const fill = useForm({});

  const countedCount = Object.values(counted).filter((value) => value.trim() !== '').length;

  return (
    <AppShell
      title={`انبارگردانی ${count.number}`}
      actions={
        <Button variant="outline" asChild>
          <Link href="/inventory/counts">
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت
          </Link>
        </Button>
      }
    >
      <Head title={`انبارگردانی ${count.number}`} />

      <section className="rounded-card border border-border bg-card p-6 sm:p-8">
        <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="انبار">{count.warehouse}</Fact>
          <Fact label="نوع شمارش">
            {count.is_blind ? (
              <span className="inline-flex items-center gap-1.5">
                <EyeOffIcon className="size-3.5" aria-hidden />
                کور
              </span>
            ) : (
              'با نمایش موجودی'
            )}
          </Fact>
          <Fact label="شمارش‌شده">
            <span className="tabular">
              <Num value={countedCount} /> از <Num value={lines.length} />
            </span>
          </Fact>
          <Fact label="وضعیت">
            {count.is_open ? (
              <span className="text-warning">باز</span>
            ) : (
              <span className="text-success">
                اعمال شد
                {count.applied_at && (
                  <span className="ms-2 text-2xs text-muted-foreground tabular">
                    {formatJalali(count.applied_at)}
                  </span>
                )}
              </span>
            )}
          </Fact>
        </dl>

        {count.is_open && count.is_blind && (
          <p className="mt-6 rounded-control border border-border bg-muted px-4 py-3 text-xs text-muted-foreground">
            موجودی مورد انتظار تا پایان شمارش نمایش داده نمی‌شود — و اصلاً به این صفحه فرستاده
            نمی‌شود. بعد از اعمال، اختلاف‌ها را همین‌جا می‌بینید.
          </p>
        )}

        {count.variance !== null && count.variance !== 0 && (
          <p
            className={cn(
              'mt-6 rounded-control px-4 py-3 text-sm',
              count.variance < 0
                ? 'border border-danger/25 bg-danger/10 text-danger'
                : 'border border-success/25 bg-success/10 text-success'
            )}
          >
            {/* The magnitude, with the direction carried by the word. A signed figure
                next to «کسری» says "minus two short", which reads as a surplus. */}
            مجموع اختلاف: <Num value={Math.abs(count.variance)} /> عدد
            {count.variance < 0 ? ' کسری' : ' اضافه'}.
          </p>
        )}
      </section>

      <div className="mt-6 space-y-6">
        {count.is_open && can.manage && (
          <SettingsSection
            title="افزودن کالا به برگه"
            description="کالا را یکی‌یکی اضافه کنید، یا همه کالاهای عادی را یک‌جا به برگه بیاورید."
          >
            <AddLine countId={count.id} />

            <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-4">
              <Button
                type="button"
                variant="outline"
                disabled={fill.processing}
                onClick={() =>
                  fill.post(`/inventory/counts/${count.id}/fill`, { preserveScroll: true })
                }
              >
                <ListPlusIcon className="size-4" />
                افزودن همه کالاهای عادی
              </Button>
              <p className="text-xs text-muted-foreground">
                دستگاه‌های سریال‌دار در انبارگردانی تعدادی شمرده نمی‌شوند؛ هرکدام رکورد خودشان را
                دارند.
              </p>
            </div>
          </SettingsSection>
        )}

        {lines.length === 0 ? (
          <SettingsSection>
            <EmptyState
              title="برگه شمارش خالی است"
              description="کالاهایی را که می‌خواهید بشمارید اضافه کنید."
            />
          </SettingsSection>
        ) : (
          <SettingsSection title="برگه شمارش" variant="flush">
            <ul className="mt-6 divide-y divide-border border-t border-border">
              {lines.map((line) => (
                <li
                  key={line.id}
                  className="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-2 px-6 py-3 sm:px-7"
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">{line.product_name}</span>
                    <span className="block truncate text-2xs text-muted-foreground">
                      {line.variant_name}
                    </span>
                  </span>

                  {count.is_open && can.manage ? (
                    <label className="flex items-center gap-2">
                      <span className="text-2xs text-muted-foreground">شمرده‌شده</span>
                      <Input
                        dir="ltr"
                        inputMode="numeric"
                        className="tabular h-10 w-24 text-center"
                        placeholder="—"
                        value={counted[line.id] ?? ''}
                        onChange={(event) =>
                          setCounted({ ...counted, [line.id]: event.target.value })
                        }
                      />
                    </label>
                  ) : (
                    <span className="text-sm tabular">
                      {line.counted_quantity === null ? (
                        <span className="text-muted-foreground">شمرده نشد</span>
                      ) : (
                        <Num value={line.counted_quantity} variant="table" />
                      )}
                    </span>
                  )}

                  {count.reveals_expected && line.expected_quantity !== null && (
                    <span className="text-2xs text-muted-foreground tabular">
                      سیستم: <Num value={line.expected_quantity} variant="table" />
                    </span>
                  )}

                  {line.variance !== null && line.variance !== 0 && (
                    <span
                      className={cn(
                        'text-2xs font-medium',
                        line.variance < 0 ? 'text-danger' : 'text-success'
                      )}
                    >
                      <bdi>
                        {line.variance > 0 ? '+' : ''}
                        <Num value={line.variance} variant="table" />
                      </bdi>
                    </span>
                  )}
                </li>
              ))}
            </ul>

            {count.is_open && can.manage && (
              <div className="flex flex-wrap items-center gap-3 p-6 sm:p-7">
                <Button
                  type="button"
                  variant="outline"
                  disabled={save.processing}
                  onClick={() => {
                    save.transform(() => ({
                      counted: Object.fromEntries(
                        Object.entries(counted).map(([id, value]) => [
                          id,
                          value.trim() === ''
                            ? null
                            : Number(toLatinDigits(value).replace(/\D/g, '') || '0'),
                        ])
                      ),
                    }));

                    save.put(`/inventory/counts/${count.id}/counted`, { preserveScroll: true });
                  }}
                >
                  ذخیره شمارش
                </Button>

                <Button
                  type="button"
                  onClick={() => setApplying(true)}
                  disabled={countedCount === 0}
                >
                  <CheckCheckIcon className="size-4" />
                  اعمال و بستن
                </Button>

                <p className="text-xs text-muted-foreground">
                  ابتدا شمارش را ذخیره کنید؛ اعمال، اختلاف را به‌عنوان تعدیل ثبت می‌کند.
                </p>

                {formError(apply.errors, 'apply') && (
                  <p className="text-sm text-danger">{formError(apply.errors, 'apply')}</p>
                )}
              </div>
            )}
          </SettingsSection>
        )}
      </div>

      <ConfirmDialog
        open={applying}
        onOpenChange={setApplying}
        destructive={false}
        title={`اعمال انبارگردانی ${count.number}`}
        description="برای هر ردیف شمرده‌شده، اختلاف با موجودی سیستم به‌عنوان یک تعدیل ثبت می‌شود. ردیف‌های شمرده‌نشده دست‌نخورده می‌مانند. این برگه پس از اعمال بسته می‌شود."
        confirmLabel="اعمال و بستن"
        processing={apply.processing}
        onConfirm={() =>
          apply.post(`/inventory/counts/${count.id}/apply`, {
            preserveScroll: true,
            onFinish: () => setApplying(false),
          })
        }
      />
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

function AddLine({ countId }: { countId: number }) {
  const [variant, setVariant] = useState<VariantOption | null>(null);
  const form = useForm({});

  return (
    <form
      className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform(() => ({ product_variant_id: variant?.id ?? null }));
        form.post(`/inventory/counts/${countId}/lines`, {
          preserveScroll: true,
          onSuccess: () => setVariant(null),
        });
      }}
    >
      <div className="space-y-2">
        <Label htmlFor="count-variant">کالا</Label>
        <VariantPicker id="count-variant" value={variant} onChange={setVariant} />
      </div>

      <Button type="submit" disabled={form.processing || variant === null}>
        افزودن
      </Button>

      {formError(form.errors, 'line') && (
        <p className="text-sm text-danger sm:col-span-2">{formError(form.errors, 'line')}</p>
      )}
    </form>
  );
}
