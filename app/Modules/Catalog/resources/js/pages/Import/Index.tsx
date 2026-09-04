import { Head, Link, router } from '@inertiajs/react';
import {
  AlertTriangleIcon,
  ArrowRightIcon,
  CheckCircle2Icon,
  DownloadIcon,
  InfoIcon,
  UploadIcon,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { FileDrop } from '@/components/domain/file-drop';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { SettingsSection } from '@/components/settings-section';
import { FormErrors } from '@/components/domain/form-errors';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { AppShell } from '@/layouts/app-shell';
import { cn } from '@/lib/utils';

interface IgnoredField {
  label: string;
  reason: string;
  instead: string;
}

interface Props {
  /** field key => Persian label */
  fields: Record<string, string>;
  /** Columns the screen offers and the importer refuses, with the reason. */
  ignoredFields: Record<string, IgnoredField>;
  types: { value: string; label: string }[];
  extensions: string[];
}

interface Analysis {
  token: string;
  filename: string;
  headers: string[];
  samples: string[][];
  mapping: Record<string, number | null>;
  encoding: string;
  repairedText: boolean;
}

interface DryRunRow {
  line: number;
  name: string | null;
  barcode: string | null;
  price: number | null;
  outcome: string;
  message: string | null;
}

interface DryRun {
  counts: Record<string, number>;
  rows: DryRunRow[];
  truncated: boolean;
  total: number;
}

const OUTCOMES: Record<string, { label: string; tone: string }> = {
  create: { label: 'کالای جدید', tone: 'text-success' },
  update: { label: 'به‌روزرسانی کالای موجود', tone: 'text-info' },
  duplicate_in_file: { label: 'تکراری در همین فایل', tone: 'text-warning' },
  error: { label: 'خطا — وارد نمی‌شود', tone: 'text-danger' },
};

const UNMAPPED = 'none';

/** No default and no inference. An unpicked unit is the whole point. */
const UNIT_UNSET = '';

/**
 * The products-import wizard: template, upload, map the columns, see exactly what would
 * happen, then commit.
 *
 * The dry run is not a summary — it is the import itself, stopped before the write, so
 * what it reports and what happens cannot differ.
 *
 * Two things here differ from the customer import, both deliberate:
 *
 * - **The currency unit has no default.** The customer wizard reads the tenant's display
 *   preference; this one refuses to. A price column is quoted in toman most of the time
 *   and rial the rest, nothing in the file says which, and guessing wrong is a ten-fold
 *   error across the entire catalogue. A required choice with nothing pre-selected is the
 *   only version that cannot be missed.
 * - **The quantity column is shown and refused.** Every real export has «موجودی» and an
 *   operator will look for it. Leaving it out reads as a bug; showing it greyed with the
 *   reason and the correct path reads as a decision.
 */
export default function ProductImportIndex({ fields, ignoredFields, types, extensions }: Props) {
  const [analysis, setAnalysis] = useState<Analysis | null>(null);
  const [dryRun, setDryRun] = useState<DryRun | null>(null);
  const [unit, setUnit] = useState<string>(UNIT_UNSET);
  const [type, setType] = useState('standard');
  const [busy, setBusy] = useState(false);

  const unitChosen = unit === 'rial' || unit === 'toman';

  const matchable =
    analysis !== null &&
    (analysis.mapping.barcode !== null || analysis.mapping.sku !== null) &&
    (analysis.mapping.barcode !== undefined || analysis.mapping.sku !== undefined);

  async function post(url: string, body: FormData | object): Promise<Response> {
    const isForm = body instanceof FormData;

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      },
      body: isForm ? body : JSON.stringify(body),
    });
  }

  async function upload(file: File): Promise<void> {
    setBusy(true);
    setDryRun(null);

    try {
      const form = new FormData();
      form.append('file', file);

      const response = await post('/catalog/import/analyse', form);
      const payload = await response.json();

      if (!response.ok) {
        toast.error(payload.message ?? 'فایل خوانده نشد.');

        return;
      }

      setAnalysis(payload);
    } catch {
      toast.error('بارگذاری انجام نشد.');
    } finally {
      setBusy(false);
    }
  }

  async function check(): Promise<void> {
    if (!analysis || !unitChosen) return;

    setBusy(true);

    try {
      const response = await post('/catalog/import/dry-run', {
        token: analysis.token,
        unit,
        type,
        mapping: analysis.mapping,
      });

      if (!response.ok) {
        toast.error('بررسی انجام نشد. آیا ستون «نام کالا» انتخاب شده است؟');

        return;
      }

      setDryRun(await response.json());
    } catch {
      toast.error('بررسی انجام نشد.');
    } finally {
      setBusy(false);
    }
  }

  const [errors, setErrors] = useState<Record<string, string>>({});

  function commit(): void {
    setErrors({});
    if (!analysis || !unitChosen) return;

    router.post(
      '/catalog/import',
      {
        token: analysis.token,
        unit,
        type,
        mapping: analysis.mapping,
      },
      {
        // A refused import — the token expired, the quota is spent, a column the
        // preview accepted the server does not — came back as a redirect that
        // re-rendered this page identically, with the preview still showing.
        onError: (received) => setErrors(received as Record<string, string>),
      }
    );
  }

  return (
    <AppShell
      title="ورود گروهی کالاها"
      actions={
        <Button variant="outline" asChild>
          <Link href="/catalog">
            <ArrowRightIcon className="size-4" />
            بازگشت
          </Link>
        </Button>
      }
    >
      <Head title="ورود گروهی کالاها" />

      <FormErrors errors={errors} />

      <div className="space-y-6">
        <SettingsSection
          title="۱ — فایل را بارگذاری کنید"
          description={`فایل ${extensions.join('، ')} با یک سطر عنوان در بالا. اگر فایل آماده‌ای ندارید، قالب خالی را بگیرید و پر کنید.`}
        >
          <FileDrop extensions={extensions} busy={busy} onFile={(file) => void upload(file)}>
            <Button variant="outline" asChild>
              <a href="/catalog/import/template">
                <DownloadIcon className="size-4" />
                دریافت قالب خالی
              </a>
            </Button>
          </FileDrop>

          {analysis && (
            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
              <span className="font-semibold">{analysis.filename}</span>
              <span className="text-muted-foreground">
                <Num value={analysis.headers.length} /> ستون
              </span>

              {analysis.encoding === 'windows-1256' && (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-info/25 bg-info/10 px-2.5 py-1 text-info">
                  <InfoIcon className="size-3.5" aria-hidden />
                  این فایل با کدپیج قدیمی ذخیره شده و اصلاح شد
                </span>
              )}

              {analysis.repairedText && (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-info/25 bg-info/10 px-2.5 py-1 text-info">
                  حرف‌های «ی» و «ک» استاندارد شد
                </span>
              )}
            </div>
          )}

          {analysis?.repairedText && (
            <p className="mt-2 text-2xs text-muted-foreground">
              متن فایل شما با حروف عربی ذخیره شده بود و به فارسی استاندارد تبدیل شد. نمونهٔ سطرها را
              پایین ببینید تا مطمئن شوید درست خوانده شده است.
            </p>
          )}
        </SettingsSection>

        {analysis && (
          <SettingsSection
            title="۲ — واحد مبلغ را انتخاب کنید"
            description="این مورد پیش‌فرض ندارد. اگر اشتباه انتخاب شود، قیمت همهٔ کالاها ده برابر یا یک‌دهم می‌شود."
          >
            <div className="flex flex-wrap gap-3">
              {[
                { value: 'toman', label: 'تومان' },
                { value: 'rial', label: 'ریال' },
              ].map((option) => (
                <Button
                  key={option.value}
                  type="button"
                  variant={unit === option.value ? 'default' : 'outline'}
                  aria-pressed={unit === option.value}
                  className={cn(!unitChosen && 'border-danger')}
                  onClick={() => {
                    setUnit(option.value);
                    setDryRun(null);
                  }}
                >
                  قیمت‌ها به {option.label} است
                </Button>
              ))}
            </div>

            {!unitChosen && (
              <p className="mt-2 flex items-center gap-1.5 text-xs text-danger">
                <AlertTriangleIcon className="size-4" aria-hidden />
                تا واحد مبلغ انتخاب نشود، ادامه ممکن نیست.
              </p>
            )}
          </SettingsSection>
        )}

        {analysis && (
          <SettingsSection
            title="۳ — ستون‌ها را تطبیق دهید"
            description="حدس اولیه از روی عنوان ستون‌ها زده شده است؛ هر کدام را که لازم بود تغییر دهید. فقط «نام کالا» الزامی است."
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {Object.entries(fields).map(([field, label]) => (
                <label key={field} className="space-y-1.5">
                  <span className="text-2xs text-muted-foreground">
                    {label}
                    {field === 'name' && <span className="text-danger"> *</span>}
                  </span>
                  <Select
                    value={
                      analysis.mapping[field] === null || analysis.mapping[field] === undefined
                        ? UNMAPPED
                        : String(analysis.mapping[field])
                    }
                    onValueChange={(value) => {
                      setAnalysis({
                        ...analysis,
                        mapping: {
                          ...analysis.mapping,
                          [field]: value === UNMAPPED ? null : Number(value),
                        },
                      });
                      setDryRun(null);
                    }}
                  >
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent dir="rtl">
                      <SelectItem value={UNMAPPED}>— وارد نشود —</SelectItem>
                      {analysis.headers.map((header, index) => (
                        <SelectItem key={index} value={String(index)}>
                          {header || `ستون ${index + 1}`}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </label>
              ))}

              <label className="space-y-1.5">
                <span className="text-2xs text-muted-foreground">نوع همهٔ کالاهای این فایل</span>
                <Select
                  value={type}
                  onValueChange={(value) => {
                    setType(value);
                    setDryRun(null);
                  }}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent dir="rtl">
                    {types.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </label>

              {/*
                Shown, greyed, and refused. Every real export has this column and an
                operator will look for it; leaving it out reads as a bug, while showing
                it with the reason and the correct path reads as a decision.
              */}
              {Object.entries(ignoredFields).map(([field, ignored]) => (
                <div key={field} className="space-y-1.5 opacity-60">
                  <span className="text-2xs text-muted-foreground">{ignored.label}</span>
                  <div
                    className="flex h-9 w-full items-center rounded-control border border-dashed border-border px-3 text-xs text-muted-foreground"
                    aria-disabled="true"
                  >
                    وارد نمی‌شود
                  </div>
                  <p className="text-2xs text-muted-foreground">{ignored.instead}</p>
                </div>
              ))}
            </div>

            {!matchable && (
              <p className="mt-4 flex items-start gap-1.5 text-xs text-warning">
                <AlertTriangleIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>
                  ستون بارکد و کد کالا انتخاب نشده است. بدون آن‌ها راهی برای تشخیص کالای تکراری نیست
                  و اگر همین فایل را دوباره وارد کنید، همهٔ کالاها دوباره ساخته می‌شوند.
                </span>
              </p>
            )}

            {analysis.samples.length > 0 && (
              <div className="mt-6 overflow-x-auto rounded-control border border-border">
                <Table>
                  <caption className="sr-only">نمونه سطرهای فایل</caption>
                  <TableHeader>
                    <TableRow>
                      {analysis.headers.map((header, index) => (
                        <TableHead key={index}>{header || `ستون ${index + 1}`}</TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {analysis.samples.map((row, rowIndex) => (
                      <TableRow key={rowIndex}>
                        {analysis.headers.map((_, cellIndex) => (
                          <TableCell key={cellIndex} className="whitespace-nowrap text-xs">
                            {row[cellIndex] ?? ''}
                          </TableCell>
                        ))}
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            <Button type="button" className="mt-6" onClick={check} disabled={busy || !unitChosen}>
              <UploadIcon className="size-4" />
              بررسی بدون ثبت
            </Button>
          </SettingsSection>
        )}

        {dryRun && (
          <SettingsSection
            title="۴ — نتیجه بررسی"
            description="هیچ چیزی هنوز ثبت نشده است. همین سطرها با زدن دکمه پایین نوشته می‌شوند."
          >
            <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
              {Object.entries(OUTCOMES).map(([key, outcome]) => (
                <span key={key} className={outcome.tone}>
                  <Num value={dryRun.counts[key] ?? 0} /> {outcome.label}
                </span>
              ))}
            </div>

            <div className="mt-4 max-h-96 overflow-auto rounded-control border border-border">
              <Table>
                <caption className="sr-only">گزارش سطر به سطر</caption>
                <TableHeader>
                  <TableRow>
                    <TableHead>سطر</TableHead>
                    <TableHead>نام کالا</TableHead>
                    <TableHead>بارکد</TableHead>
                    <TableHead>قیمت</TableHead>
                    <TableHead>نتیجه</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {dryRun.rows.map((row) => (
                    <TableRow key={row.line}>
                      <TableCell className="tabular text-xs">
                        <Num value={row.line} variant="table" />
                      </TableCell>
                      <TableCell className="text-sm">{row.name ?? '—'}</TableCell>
                      <TableCell className="text-xs">
                        {row.barcode ? <Num value={row.barcode} variant="ltr" /> : '—'}
                      </TableCell>
                      <TableCell className="text-xs">
                        {row.price === null ? '—' : <Money rial={row.price} digits="latin" />}
                      </TableCell>
                      <TableCell>
                        <span
                          className={cn(
                            'text-xs',
                            OUTCOMES[row.outcome]?.tone ?? 'text-muted-foreground'
                          )}
                        >
                          {OUTCOMES[row.outcome]?.label ?? row.outcome}
                          {row.message && (
                            /*
                              Wraps rather than widening the row. A verdict like «قیمت
                              «۱۸۰۰۰۰ ریال» خوانده نشد…» is a sentence, and left to size
                              the column it pushed the table past its container and the
                              end of the reason was clipped — which loses precisely the
                              half that says what to do about it.
                            */
                            <span className="block max-w-[32ch] whitespace-normal text-2xs text-balance text-muted-foreground">
                              {row.message}
                            </span>
                          )}
                        </span>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {dryRun.truncated && (
              <p className="mt-3 text-xs text-muted-foreground">
                فقط ۲۰۰ سطر اول گزارش نمایش داده شده است؛ همهٔ سطرهای سالم ثبت می‌شوند.
              </p>
            )}

            <div className="mt-6 flex flex-wrap items-center gap-3">
              <Button
                type="button"
                onClick={commit}
                disabled={busy || (dryRun.counts.create ?? 0) + (dryRun.counts.update ?? 0) === 0}
              >
                <CheckCircle2Icon className="size-4" />
                ثبت نهایی
              </Button>

              {(dryRun.counts.error ?? 0) > 0 && (
                <span className="inline-flex items-center gap-1.5 text-xs text-warning">
                  <AlertTriangleIcon className="size-4" aria-hidden />
                  سطرهای دارای خطا نادیده گرفته می‌شوند.
                </span>
              )}
            </div>
          </SettingsSection>
        )}
      </div>
    </AppShell>
  );
}
