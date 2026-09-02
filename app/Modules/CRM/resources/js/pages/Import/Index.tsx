import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangleIcon, ArrowRightIcon, CheckCircle2Icon, UploadIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Num } from '@/components/domain/num';
import { SettingsSection } from '@/components/settings-section';
import { FormErrors } from '@/components/domain/form-errors';
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { cn } from '@/lib/utils';

interface Props {
  /** field key => Persian label */
  fields: Record<string, string>;
  kinds: { value: string; label: string }[];
  extensions: string[];
}

interface Analysis {
  token: string;
  headers: string[];
  samples: string[][];
  mapping: Record<string, number | null>;
}

interface DryRunRow {
  line: number;
  name: string | null;
  mobile: string | null;
  outcome: string;
  message: string | null;
}

interface DryRun {
  counts: Record<string, number>;
  rows: DryRunRow[];
  truncated: boolean;
}

const OUTCOMES: Record<string, { label: string; tone: string }> = {
  create: { label: 'ثبت جدید', tone: 'text-success' },
  update: { label: 'تکمیل مورد موجود', tone: 'text-info' },
  duplicate_in_file: { label: 'تکراری در همین فایل', tone: 'text-warning' },
  error: { label: 'خطا', tone: 'text-danger' },
};

const UNMAPPED = 'none';

/**
 * The customer-import wizard: upload, map the columns, see exactly what would happen,
 * then commit.
 *
 * The dry run is not a summary — it is the import itself, stopped before the write, so
 * what it reports and what happens cannot differ. A shop handing over a list of five
 * hundred customers is handing over their balances; discovering afterwards that the
 * mapping was one column out is not recoverable by hand.
 */
export default function ImportIndex({ fields, kinds, extensions }: Props) {
  const settings = useTenantSettings();

  const [analysis, setAnalysis] = useState<Analysis | null>(null);
  const [dryRun, setDryRun] = useState<DryRun | null>(null);
  const [kind, setKind] = useState('customer');
  const [busy, setBusy] = useState(false);

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

      const response = await post('/crm/import/analyse', form);
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
    if (!analysis) return;

    setBusy(true);

    try {
      const response = await post('/crm/import/dry-run', {
        token: analysis.token,
        kind,
        unit: settings.currency_display,
        mapping: analysis.mapping,
      });

      if (!response.ok) {
        toast.error('بررسی انجام نشد. آیا ستون «نام» انتخاب شده است؟');

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
    if (!analysis) return;

    router.post(
      '/crm/import',
      {
        token: analysis.token,
        kind,
        unit: settings.currency_display,
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
      title="ورود گروهی مشتریان"
      actions={
        <Button variant="outline" asChild>
          <Link href="/crm">
            <ArrowRightIcon className="size-4" />
            بازگشت
          </Link>
        </Button>
      }
    >
      <Head title="ورود گروهی مشتریان" />

      <FormErrors errors={errors} />

      <div className="space-y-6">
        <SettingsSection
          title="۱ — فایل را بارگذاری کنید"
          description={`فایل ${extensions.join('، ')} با یک سطر عنوان در بالا. مبالغ به ${
            settings.currency_display === 'toman' ? 'تومان' : 'ریال'
          } خوانده می‌شوند.`}
        >
          <div className="flex flex-wrap items-center gap-4">
            <Input
              type="file"
              accept={extensions.map((extension) => `.${extension}`).join(',')}
              className="max-w-sm"
              onChange={(event) => {
                const file = event.target.files?.[0];

                if (file) void upload(file);
              }}
            />
            {busy && <span className="text-xs text-muted-foreground">در حال پردازش…</span>}
          </div>
        </SettingsSection>

        {analysis && (
          <SettingsSection
            title="۲ — ستون‌ها را تطبیق دهید"
            description="حدس اولیه از روی عنوان ستون‌ها زده شده است؛ هر کدام را که لازم بود تغییر دهید. فقط «نام» الزامی است."
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
                    onValueChange={(value) =>
                      setAnalysis({
                        ...analysis,
                        mapping: {
                          ...analysis.mapping,
                          [field]: value === UNMAPPED ? null : Number(value),
                        },
                      })
                    }
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
                <span className="text-2xs text-muted-foreground">نوع طرف حساب</span>
                <Select value={kind} onValueChange={setKind}>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent dir="rtl">
                    {kinds.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </label>
            </div>

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

            <Button type="button" className="mt-6" onClick={check} disabled={busy}>
              <UploadIcon className="size-4" />
              بررسی بدون ثبت
            </Button>
          </SettingsSection>
        )}

        {dryRun && (
          <SettingsSection
            title="۳ — نتیجه بررسی"
            description="هیچ چیزی هنوز ثبت نشده است. همین سطرها با زدن دکمه پایین نوشته می‌شوند."
          >
            <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
              {Object.entries(OUTCOMES).map(([key, outcome]) => (
                <span key={key} className={outcome.tone}>
                  <Num value={dryRun.counts[key] ?? 0} /> {outcome.label}
                </span>
              ))}
            </div>

            <div className="mt-4 max-h-96 overflow-y-auto rounded-control border border-border">
              <Table>
                <caption className="sr-only">گزارش سطر به سطر</caption>
                <TableHeader>
                  <TableRow>
                    <TableHead>سطر</TableHead>
                    <TableHead>نام</TableHead>
                    <TableHead>شماره</TableHead>
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
                        {row.mobile ? <Num value={row.mobile} variant="ltr" /> : '—'}
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
                            <span className="block text-2xs text-muted-foreground">
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
                فقط ۲۰۰ سطر اول گزارش نمایش داده شده است؛ همه سطرها ثبت می‌شوند.
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
