import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangleIcon,
  ArrowRightIcon,
  CheckCircle2Icon,
  PackageCheckIcon,
  PrinterIcon,
  ScanLineIcon,
  Trash2Icon,
  XCircleIcon,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { FormErrors } from '@/components/domain/form-errors';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { type VariantOption, VariantPicker } from '@/components/domain/variant-picker';
import { SettingsSection } from '@/components/settings-section';
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
import { Textarea } from '@/components/ui/textarea';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { formError } from '@/lib/forms';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface Invoice {
  id: number;
  number: string;
  status: string;
  warehouse: string;
  supplier: { label: string; url: string | null } | null;
  issued_at: string | null;
  received_at: string | null;
  notes: string | null;
  subtotal: MoneyValue;
  landed_total: MoneyValue;
  total: MoneyValue;
  is_draft: boolean;
}

interface StandardLine {
  id: number;
  product_name: string;
  variant_name: string;
  quantity: number;
  unit_cost: MoneyValue;
  line_total: MoneyValue;
}

interface UnitLine {
  id: number;
  product_name: string;
  variant_name: string;
  imei1: string | null;
  condition: string;
  grade: string | null;
  unit_cost: MoneyValue;
  product_unit_id: number | null;
}

interface LandedCostRow {
  id: number;
  type: string;
  amount: MoneyValue;
  allocation: string;
  description: string | null;
}

interface ParsedLine {
  input: string;
  imei: string | null;
  status: string;
  unit_id: number | null;
}

interface ParseResult {
  lines: ParsedLine[];
  counts: { accepted: number; invalid: number; duplicate_in_batch: number; exists: number };
  clean: boolean;
}

interface Props {
  invoice: Invoice;
  standard_lines: StandardLine[];
  unit_lines: UnitLine[];
  landed_costs: LandedCostRow[];
  can: { edit: boolean; receive: boolean };
}

const VERDICTS: Record<string, { label: string; tone: string }> = {
  accepted: { label: 'قابل ثبت', tone: 'text-success' },
  invalid: { label: 'رقم کنترلی نامعتبر', tone: 'text-danger' },
  duplicate_in_batch: { label: 'تکراری در همین لیست', tone: 'text-warning' },
  exists: { label: 'قبلاً در فروشگاه ثبت شده', tone: 'text-danger' },
};

/**
 * The intake screen — the reason this module exists.
 *
 * A shop receives twenty phones and should be able to paste twenty IMEIs and be done.
 * That only works if the screen is forgiving about format and merciless about
 * validity, so the paste box accepts any separator and Persian digits, and every line
 * comes back with a verdict of its own: valid, mistyped, twice in this paste, or
 * already registered to a device the shop owns (with a link to it).
 *
 * Nothing is written until the batch is clean or the operator explicitly skips the bad
 * rows — a half-received shipment is how stock stops reconciling, and it surfaces
 * weeks later with no way to tell which phone was missed. The server re-parses on
 * commit rather than trusting these verdicts.
 */
export default function InvoiceEdit({
  invoice,
  standard_lines: standardLines,
  unit_lines: unitLines,
  landed_costs: landedCosts,
  can,
}: Props) {
  const editable = invoice.is_draft && can.edit;

  return (
    <AppShell
      title={`فاکتور خرید ${invoice.number}`}
      actions={
        <>
          <Button variant="outline" asChild>
            <Link href="/purchasing">
              <ArrowRightIcon className="size-4 rtl:rotate-180" />
              بازگشت
            </Link>
          </Button>
          {!invoice.is_draft && (
            <Button variant="outline" asChild>
              <Link href={`/purchasing/invoices/${invoice.id}/grn`}>
                <PrinterIcon className="size-4" />
                رسید انبار
              </Link>
            </Button>
          )}
        </>
      }
    >
      <Head title={`فاکتور خرید ${invoice.number}`} />

      <Summary invoice={invoice} canReceive={can.receive} unitCount={unitLines.length} />

      <div className="mt-6 space-y-6">
        {editable && <ImeiIntake invoiceId={invoice.id} />}

        {unitLines.length > 0 && (
          <UnitLines lines={unitLines} invoiceId={invoice.id} editable={editable} />
        )}

        {editable && <StandardLineForm invoiceId={invoice.id} />}

        {standardLines.length > 0 && (
          <StandardLines lines={standardLines} invoiceId={invoice.id} editable={editable} />
        )}

        <LandedCosts
          rows={landedCosts}
          invoiceId={invoice.id}
          editable={editable}
          hasLines={unitLines.length + standardLines.length > 0}
        />
      </div>
    </AppShell>
  );
}

/* ---------------------------------------------------------------- summary -- */

function Summary({
  invoice,
  canReceive,
  unitCount,
}: {
  invoice: Invoice;
  canReceive: boolean;
  unitCount: number;
}) {
  const [confirming, setConfirming] = useState(false);
  const receive = useForm({});

  return (
    <section className="rounded-card border border-border bg-card p-6 sm:p-8">
      {/* Receiving posts stock into the warehouse; a refusal here — a spent quota, a line
          without a cost, a closed period — arrived as a redirect that re-rendered an
          identical page. */}
      <FormErrors errors={receive.errors} className="mb-6" />

      <div className="flex flex-wrap items-start justify-between gap-4">
        <dl className="grid flex-1 gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="تأمین‌کننده">
            {invoice.supplier?.label ?? <span className="text-muted-foreground">موجودی اولیه</span>}
          </Fact>
          <Fact label="انبار مقصد">{invoice.warehouse}</Fact>
          <Fact label="تاریخ">
            <span className="tabular">{formatJalali(invoice.issued_at, { longMonth: true })}</span>
          </Fact>
          <Fact label="وضعیت">
            <StatusBadge status={invoice.status === 'received' ? 'final' : invoice.status} />
          </Fact>
        </dl>

        <div className="space-y-1 text-end">
          <p className="text-2xs text-muted-foreground">مبلغ کل</p>
          <p className="text-lg font-semibold">
            <Money rial={invoice.total.value} withUnit />
          </p>
          {invoice.landed_total.value > 0 && (
            // Prose, so it follows the tenant's digit setting like the figure above it
            // — `digits="latin"` belongs in tables and on invoices, not in a sentence.
            <p className="text-2xs text-muted-foreground">
              شامل <Money rial={invoice.landed_total.value} /> سربار
            </p>
          )}
        </div>
      </div>

      {canReceive && (
        <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-border pt-6">
          <Button onClick={() => setConfirming(true)} disabled={receive.processing}>
            <PackageCheckIcon className="size-4" />
            دریافت کالا
          </Button>
          <p className="text-xs text-muted-foreground">
            با دریافت، موجودی و بدهی به تأمین‌کننده هم‌زمان ثبت می‌شود و فاکتور دیگر قابل ویرایش
            نیست.
          </p>
        </div>
      )}

      {invoice.received_at && (
        <p className="mt-6 border-t border-border pt-6 text-xs text-success">
          در {formatJalali(invoice.received_at, { longMonth: true, withTime: true })} دریافت شد.
        </p>
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        destructive={false}
        title={`دریافت فاکتور ${invoice.number}`}
        description={
          <>
            {unitCount > 0 ? (
              <>
                <Num value={unitCount} /> دستگاه با شناسنامه ساخته می‌شود و وارد انبار می‌شود.{' '}
              </>
            ) : null}
            موجودی، بهای تمام‌شده و بدهی به تأمین‌کننده در یک تراکنش ثبت می‌شوند. این کار برگشت‌پذیر
            نیست.
          </>
        }
        confirmLabel="دریافت کالا"
        processing={receive.processing}
        onConfirm={() =>
          receive.post(`/purchasing/invoices/${invoice.id}/receive`, {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
          })
        }
      />
    </section>
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

/* ------------------------------------------------------------ imei intake -- */

function ImeiIntake({ invoiceId }: { invoiceId: number }) {
  const settings = useTenantSettings();

  const [variant, setVariant] = useState<VariantOption | null>(null);
  const [blob, setBlob] = useState('');
  const [cost, setCost] = useState('');
  const [condition, setCondition] = useState('new');
  const [grade, setGrade] = useState('');
  const [result, setResult] = useState<ParseResult | null>(null);
  const [checking, setChecking] = useState(false);

  const commit = useForm({});

  async function check(): Promise<void> {
    setChecking(true);

    try {
      const response = await fetch(`/purchasing/invoices/${invoiceId}/imeis/parse`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
          ),
        },
        body: JSON.stringify({ imeis: blob }),
      });

      if (!response.ok) throw new Error(String(response.status));

      setResult(await response.json());
    } catch {
      toast.error('بررسی انجام نشد. دوباره تلاش کنید.');
    } finally {
      setChecking(false);
    }
  }

  function submit(skipRejected: boolean): void {
    const amount = Number(toLatinDigits(cost).replace(/[,\s]/g, '') || '0');

    commit.transform(() => ({
      imeis: blob,
      product_variant_id: variant?.id ?? null,
      amount,
      unit: settings.currency_display,
      condition,
      grade: grade || null,
      skip_rejected: skipRejected,
    }));

    commit.post(`/purchasing/invoices/${invoiceId}/imeis`, {
      preserveScroll: true,
      onSuccess: () => {
        setBlob('');
        setResult(null);
      },
    });
  }

  const rejected = result === null ? 0 : result.lines.length - result.counts.accepted;

  return (
    <SettingsSection
      title="ثبت دستگاه با IMEI"
      description="شماره‌ها را بچسبانید یا با بارکدخوان اسکن کنید — هر جداکننده‌ای (خط جدید، فاصله، ویرگول) و ارقام فارسی پذیرفته می‌شود. پیش از ثبت، وضعیت هر سطر جداگانه بررسی می‌شود."
    >
      <div className="grid gap-5 lg:grid-cols-[1fr_20rem] lg:items-start">
        <div className="space-y-2">
          <Label htmlFor="imei-blob">شماره‌های IMEI</Label>
          <Textarea
            id="imei-blob"
            dir="ltr"
            rows={8}
            className="tabular font-mono"
            placeholder={'356938035643809\n351234567890123\n…'}
            value={blob}
            onChange={(event) => {
              setBlob(event.target.value);
              // The verdicts describe the text that produced them; keeping them on
              // screen after an edit shows an answer to a question nobody asked.
              setResult(null);
            }}
          />
          {formError(commit.errors, 'imeis') && (
            <p className="text-sm text-danger">{formError(commit.errors, 'imeis')}</p>
          )}
        </div>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="imei-variant">مدل دستگاه</Label>
            <VariantPicker
              id="imei-variant"
              value={variant}
              onChange={setVariant}
              serialized
              invalid={Boolean(formError(commit.errors, 'product_variant_id'))}
            />
            {formError(commit.errors, 'product_variant_id') && (
              <p className="text-sm text-danger">
                {formError(commit.errors, 'product_variant_id')}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="imei-cost">
              بهای خرید هر دستگاه ({settings.currency_display === 'toman' ? 'تومان' : 'ریال'})
            </Label>
            <Input
              id="imei-cost"
              dir="ltr"
              inputMode="numeric"
              className="tabular"
              value={cost}
              onChange={(event) => setCost(event.target.value)}
            />
            {formError(commit.errors, 'amount') && (
              <p className="text-sm text-danger">{formError(commit.errors, 'amount')}</p>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="imei-condition">وضعیت</Label>
              <Select value={condition} onValueChange={setCondition}>
                <SelectTrigger id="imei-condition" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  <SelectItem value="new">نو</SelectItem>
                  <SelectItem value="used">دست‌دوم</SelectItem>
                  <SelectItem value="refurb">بازسازی‌شده</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {condition !== 'new' && (
              <div className="space-y-2">
                <Label htmlFor="imei-grade">درجه</Label>
                <Input
                  id="imei-grade"
                  maxLength={2}
                  value={grade}
                  onChange={(event) => setGrade(event.target.value)}
                />
              </div>
            )}
          </div>

          <Button
            type="button"
            variant="outline"
            className="w-full"
            disabled={checking || blob.trim() === ''}
            onClick={check}
          >
            <ScanLineIcon className="size-4" />
            {checking ? 'در حال بررسی…' : 'بررسی شماره‌ها'}
          </Button>
        </div>
      </div>

      {result && (
        <div className="mt-6 space-y-4 border-t border-border pt-6">
          <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs">
            <span className="text-success">
              <Num value={result.counts.accepted} /> قابل ثبت
            </span>
            {result.counts.invalid > 0 && (
              <span className="text-danger">
                <Num value={result.counts.invalid} /> نامعتبر
              </span>
            )}
            {result.counts.duplicate_in_batch > 0 && (
              <span className="text-warning">
                <Num value={result.counts.duplicate_in_batch} /> تکراری در لیست
              </span>
            )}
            {result.counts.exists > 0 && (
              <span className="text-danger">
                <Num value={result.counts.exists} /> از قبل ثبت‌شده
              </span>
            )}
          </div>

          <ul className="max-h-72 divide-y divide-border overflow-y-auto rounded-control border border-border">
            {result.lines.map((line, index) => (
              <li
                key={`${line.input}-${index}`}
                className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
              >
                {line.status === 'accepted' ? (
                  <CheckCircle2Icon className="size-4 shrink-0 text-success" aria-hidden />
                ) : (
                  <XCircleIcon
                    className={cn(
                      'size-4 shrink-0',
                      line.status === 'duplicate_in_batch' ? 'text-warning' : 'text-danger'
                    )}
                    aria-hidden
                  />
                )}

                <Num value={line.input} variant="ltr" className="text-sm" />

                <span
                  className={cn(
                    'ms-auto text-2xs',
                    VERDICTS[line.status]?.tone ?? 'text-muted-foreground'
                  )}
                >
                  {VERDICTS[line.status]?.label ?? line.status}
                </span>

                {/* A device that already exists is findable, not just rejected: the
                    operator almost always wants to see which handset it is. */}
                {line.unit_id && (
                  <Link href={`/inventory/units/${line.unit_id}`} className="text-2xs text-primary">
                    دیدن شناسنامه
                  </Link>
                )}
              </li>
            ))}
          </ul>

          {/*
            Exactly one filled button, and it is always the action that is actually
            available. A dirty batch cannot be committed whole, so the plain commit is
            not rendered at all — leaving it there as a disabled brand-filled button
            puts the eye on the one thing that will not work (design-system rule 7).
          */}
          {/* Three keys have a home beside their input above; everything else the server
              can refuse on had none. */}
          <FormErrors errors={commit.errors} handled={['imeis', 'product_variant_id', 'amount']} />

          <div className="flex flex-wrap items-center gap-3">
            {result.clean ? (
              <Button type="button" disabled={commit.processing} onClick={() => submit(false)}>
                ثبت <Num value={result.counts.accepted} /> دستگاه
              </Button>
            ) : result.counts.accepted > 0 ? (
              <Button type="button" disabled={commit.processing} onClick={() => submit(true)}>
                <AlertTriangleIcon className="size-4" />
                ثبت <Num value={result.counts.accepted} /> سطر سالم و نادیده‌گرفتن{' '}
                <Num value={rejected} /> سطر
              </Button>
            ) : null}

            {result.counts.accepted === 0 && (
              <p className="text-xs text-danger">هیچ سطر قابل ثبتی در این لیست نیست.</p>
            )}
          </div>
        </div>
      )}
    </SettingsSection>
  );
}

/* ------------------------------------------------------------------ lines -- */

function UnitLines({
  lines,
  invoiceId,
  editable,
}: {
  lines: UnitLine[];
  invoiceId: number;
  editable: boolean;
}) {
  return (
    <SettingsSection
      title="دستگاه‌های این فاکتور"
      description="هر سطر یک دستگاه فیزیکی است، با بهای خرید خودش. پس از دریافت، هرکدام شناسنامه‌ای مستقل پیدا می‌کنند."
      variant="flush"
    >
      <ul className="mt-6 divide-y divide-border border-t border-border">
        {lines.map((line) => (
          <li
            key={line.id}
            className="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-1 px-6 py-3 sm:px-7"
          >
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">{line.product_name}</span>
              <span className="block truncate text-2xs text-muted-foreground">
                {line.variant_name}
              </span>
            </span>

            {line.imei1 && <Num value={line.imei1} variant="ltr" className="text-sm" />}

            <Money rial={line.unit_cost.value} digits="latin" />

            {line.product_unit_id ? (
              <Link
                href={`/inventory/units/${line.product_unit_id}`}
                className="text-2xs text-primary"
              >
                شناسنامه
              </Link>
            ) : editable ? (
              <RemoveLine invoiceId={invoiceId} kind="unit" lineId={line.id} />
            ) : null}
          </li>
        ))}
      </ul>
    </SettingsSection>
  );
}

function StandardLines({
  lines,
  invoiceId,
  editable,
}: {
  lines: StandardLine[];
  invoiceId: number;
  editable: boolean;
}) {
  return (
    <SettingsSection title="کالاهای عادی" variant="flush">
      <ul className="mt-6 divide-y divide-border border-t border-border">
        {lines.map((line) => (
          <li
            key={line.id}
            className="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-1 px-6 py-3 sm:px-7"
          >
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">{line.product_name}</span>
              <span className="block truncate text-2xs text-muted-foreground">
                {line.variant_name}
              </span>
            </span>

            <span className="text-sm tabular">
              <Num value={line.quantity} variant="table" /> ×{' '}
              <Money rial={line.unit_cost.value} digits="latin" />
            </span>

            <Money rial={line.line_total.value} digits="latin" className="font-medium" />

            {editable && <RemoveLine invoiceId={invoiceId} kind="standard" lineId={line.id} />}
          </li>
        ))}
      </ul>
    </SettingsSection>
  );
}

function StandardLineForm({ invoiceId }: { invoiceId: number }) {
  const settings = useTenantSettings();
  const [variant, setVariant] = useState<VariantOption | null>(null);
  const [quantity, setQuantity] = useState('1');
  const [cost, setCost] = useState('');
  const form = useForm({});

  return (
    <SettingsSection
      title="افزودن کالای عادی"
      description="لوازم جانبی و قطعات — چیزهایی که تعداد دارند، نه IMEI."
    >
      <form
        className="grid gap-4 lg:grid-cols-[1fr_8rem_12rem_auto] lg:items-end"
        onSubmit={(event) => {
          event.preventDefault();

          form.transform(() => ({
            product_variant_id: variant?.id ?? null,
            quantity: Number(toLatinDigits(quantity).replace(/\D/g, '') || '0'),
            amount: Number(toLatinDigits(cost).replace(/[,\s]/g, '') || '0'),
            unit: settings.currency_display,
          }));

          form.post(`/purchasing/invoices/${invoiceId}/lines`, {
            preserveScroll: true,
            onSuccess: () => {
              setVariant(null);
              setQuantity('1');
              setCost('');
            },
          });
        }}
      >
        <div className="space-y-2">
          <Label htmlFor="line-variant">کالا</Label>
          <VariantPicker id="line-variant" value={variant} onChange={setVariant} />
        </div>

        <div className="space-y-2">
          <Label htmlFor="line-quantity">تعداد</Label>
          <Input
            id="line-quantity"
            dir="ltr"
            inputMode="numeric"
            className="tabular h-11 text-center"
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="line-cost">
            بهای هر واحد ({settings.currency_display === 'toman' ? 'تومان' : 'ریال'})
          </Label>
          <Input
            id="line-cost"
            dir="ltr"
            inputMode="numeric"
            className="tabular h-11"
            value={cost}
            onChange={(event) => setCost(event.target.value)}
          />
        </div>

        {/* Nothing on this form has a field for a refusal to sit beside, and 878 lines of
            editor rendered no error region at all. */}
        <FormErrors errors={form.errors} />

        <Button type="submit" disabled={form.processing || variant === null}>
          افزودن
        </Button>

        {(formError(form.errors, 'product_variant_id') ??
          formError(form.errors, 'amount') ??
          formError(form.errors, 'quantity')) && (
          <p className="text-sm text-danger lg:col-span-4">
            {formError(form.errors, 'product_variant_id') ??
              formError(form.errors, 'amount') ??
              formError(form.errors, 'quantity')}
          </p>
        )}
      </form>
    </SettingsSection>
  );
}

/* ----------------------------------------------------------- landed costs -- */

const COST_TYPES = [
  { value: 'freight', label: 'حمل' },
  { value: 'customs', label: 'گمرک' },
  { value: 'courier', label: 'پیک' },
  { value: 'other', label: 'سایر' },
];

function LandedCosts({
  rows,
  invoiceId,
  editable,
  hasLines,
}: {
  rows: LandedCostRow[];
  invoiceId: number;
  editable: boolean;
  hasLines: boolean;
}) {
  const settings = useTenantSettings();
  const [type, setType] = useState('freight');
  const [allocation, setAllocation] = useState('by_value');
  const [amount, setAmount] = useState('');
  const form = useForm({});

  if (!editable && rows.length === 0) {
    return null;
  }

  return (
    <SettingsSection
      title="هزینه‌های سربار"
      description="حمل و گمرک روی بهای تمام‌شده هر ردیف سرشکن می‌شوند — «حمل» معمولاً بر اساس تعداد و «گمرک» بر اساس ارزش. بهای خرید هر دستگاه در شناسنامه‌اش شامل همین سهم است."
    >
      {rows.length > 0 && (
        <ul className="mb-6 divide-y divide-border rounded-control border border-border">
          {rows.map((row) => (
            <li key={row.id} className="flex min-h-11 items-center gap-3 px-3 py-2">
              <span className="flex-1 text-sm">
                {COST_TYPES.find((option) => option.value === row.type)?.label ?? row.type}
                <span className="ms-2 text-2xs text-muted-foreground">
                  {row.allocation === 'by_value' ? 'بر اساس ارزش' : 'بر اساس تعداد'}
                </span>
              </span>
              <Money rial={row.amount.value} digits="latin" />
              {editable && <RemoveLine invoiceId={invoiceId} kind="landed" lineId={row.id} />}
            </li>
          ))}
        </ul>
      )}

      {editable && (
        <form
          className="grid gap-4 lg:grid-cols-[10rem_12rem_1fr_auto] lg:items-end"
          onSubmit={(event) => {
            event.preventDefault();

            form.transform(() => ({
              type,
              allocation,
              amount: Number(toLatinDigits(amount).replace(/[,\s]/g, '') || '0'),
              unit: settings.currency_display,
            }));

            form.post(`/purchasing/invoices/${invoiceId}/landed-costs`, {
              preserveScroll: true,
              onSuccess: () => setAmount(''),
            });
          }}
        >
          <div className="space-y-2">
            <Label htmlFor="landed-type">نوع</Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger id="landed-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                {COST_TYPES.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="landed-allocation">سرشکن بر اساس</Label>
            <Select value={allocation} onValueChange={setAllocation}>
              <SelectTrigger id="landed-allocation" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value="by_value">ارزش ردیف</SelectItem>
                <SelectItem value="by_quantity">تعداد</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="landed-amount">
              مبلغ ({settings.currency_display === 'toman' ? 'تومان' : 'ریال'})
            </Label>
            <Input
              id="landed-amount"
              dir="ltr"
              inputMode="numeric"
              className="tabular h-11"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          </div>

          <FormErrors errors={form.errors} />

          <Button type="submit" disabled={form.processing || !hasLines || amount.trim() === ''}>
            افزودن
          </Button>

          {!hasLines && (
            <p className="text-xs text-muted-foreground lg:col-span-4">
              اول ردیف‌های فاکتور را اضافه کنید؛ هزینه سربار باید روی چیزی سرشکن شود.
            </p>
          )}

          {formError(form.errors, 'amount') && (
            <p className="text-sm text-danger lg:col-span-4">{formError(form.errors, 'amount')}</p>
          )}
        </form>
      )}
    </SettingsSection>
  );
}

function RemoveLine({
  invoiceId,
  kind,
  lineId,
}: {
  invoiceId: number;
  kind: string;
  lineId: number;
}) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  return (
    <>
      <FormErrors errors={errors} />

      <Button
        type="button"
        variant="ghost"
        size="icon"
        aria-label="حذف ردیف"
        className="group"
        onClick={() => setConfirming(true)}
      >
        <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
      </Button>

      {/*
        This deleted on a single click and handled no refusal. A purchase line carries a
        quantity and a cost that the stock ledger and every margin after it are computed
        from, so removing one is not a tidy-up — and on a received invoice the server may
        well refuse, which arrived as a redirect that changed nothing on screen.
      */}
      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title="حذف ردیف فاکتور خرید"
        description="این ردیف و بهای آن از فاکتور برداشته می‌شود و مبلغ کل دوباره محاسبه می‌گردد. اگر فاکتور رسید خورده باشد، حذف انجام نمی‌شود."
        confirmLabel="حذف ردیف"
        processing={busy}
        onConfirm={() => {
          setBusy(true);
          setErrors({});

          router.delete(`/purchasing/invoices/${invoiceId}/lines/${kind}/${lineId}`, {
            preserveScroll: true,
            onError: (received) => setErrors(received as Record<string, string>),
            onFinish: () => {
              setBusy(false);
              setConfirming(false);
            },
          });
        }}
      />
    </>
  );
}
