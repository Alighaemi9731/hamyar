import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRightIcon, CheckIcon, TrendingUpIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
import { toLatinDigits } from '@/lib/digits';
import { RIAL_PER_TOMAN } from '@/lib/money';
import type { MoneyValue } from '@/types';

interface Level {
  id: number;
  code: string;
  label: string;
  is_default: boolean;
}

interface VariantRow {
  id: number;
  product_name: string;
  variant_name: string;
  sku: string | null;
  barcode: string | null;
  /** Rial, keyed by price level id. A missing key means no price at that level. */
  prices: Record<string, number>;
}

interface PreviewRow {
  variant_id: number;
  name: string;
  from: MoneyValue | null;
  to: MoneyValue;
  from_rial: number | null;
  to_rial: number;
}

interface Props {
  levels: Level[];
  variants: { rows: VariantRow[]; links: PaginationLink[]; total: number };
  filters: { q: string; category_id: number | null; brand_id: number | null };
  categories: { id: number; label: string }[];
  brands: { id: number; label: string }[];
  can: { manage_prices: boolean };
}

/**
 * The price grid.
 *
 * Iranian prices move weekly, so this screen is opened constantly and is built for
 * typing rather than clicking: every cell is an input, Enter saves it, and the bulk
 * panel above can move a whole filtered set at once.
 *
 * The bulk flow always goes through a preview, and the preview's rows are what gets
 * applied — not a recomputation. That is the guarantee that nobody approves one set of
 * changes and gets another because a price moved in between.
 */
export default function PricesIndex({ levels, variants, filters, categories, brands, can }: Props) {
  const [term, setTerm] = useState(filters.q);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;

      return;
    }

    const timer = window.setTimeout(
      () =>
        router.get(
          '/catalog/prices',
          { q: term, category_id: filters.category_id, brand_id: filters.brand_id },
          { preserveState: true, preserveScroll: true, replace: true }
        ),
      300
    );

    return () => window.clearTimeout(timer);
  }, [term, filters.category_id, filters.brand_id]);

  return (
    <AppShell
      title="قیمت‌ها"
      actions={
        <Button variant="outline" asChild>
          <Link href="/catalog">
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت به کالاها
          </Link>
        </Button>
      }
    >
      <Head title="قیمت‌ها" />

      <div className="space-y-6">
        {can.manage_prices && (
          <BulkPanel
            levels={levels}
            filters={{ ...filters, q: term }}
            categories={categories}
            brands={brands}
          />
        )}

        <SettingsSection variant="flush">
          <div className="p-6 pb-0 sm:p-7 sm:pb-0">
            <Input
              type="search"
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="نام کالا…"
              className="max-w-sm"
            />
          </div>

          {variants.rows.length === 0 ? (
            <div className="p-6 sm:p-7">
              <EmptyState
                title="کالایی برای قیمت‌گذاری نیست"
                description="اول کالا و تنوع‌هایش را بسازید؛ قیمت به تنوع وصل می‌شود، نه به خود کالا."
                action={
                  <Button asChild>
                    <Link href="/catalog/products/create">ثبت کالا</Link>
                  </Button>
                }
              />
            </div>
          ) : (
            <div className="mt-6 overflow-x-auto border-t border-border">
              <Table>
                <caption className="sr-only">جدول قیمت کالاها به تفکیک سطح قیمت</caption>
                <TableHeader>
                  <TableRow>
                    <TableHead>کالا</TableHead>
                    {levels.map((level) => (
                      <TableHead key={level.id} className="text-end">
                        {level.label}
                        {level.is_default && (
                          <span className="ms-1 text-2xs text-muted-foreground">(پیش‌فرض)</span>
                        )}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>

                <TableBody>
                  {variants.rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        <span className="flex min-w-0 flex-col">
                          <span className="truncate text-sm font-medium">{row.product_name}</span>
                          <span className="truncate text-2xs text-muted-foreground">
                            {row.variant_name}
                            {row.barcode && (
                              <>
                                {' · '}
                                <Num value={row.barcode} variant="ltr" />
                              </>
                            )}
                          </span>
                        </span>
                      </TableCell>

                      {levels.map((level) => (
                        <TableCell key={level.id} className="text-end">
                          <PriceCell
                            variantId={row.id}
                            levelId={level.id}
                            rial={row.prices[String(level.id)] ?? null}
                            editable={can.manage_prices}
                          />
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          <div className="p-6 sm:p-7">
            <Pagination links={variants.links} total={variants.total} unit="تنوع" />
          </div>
        </SettingsSection>
      </div>
    </AppShell>
  );
}

/* ------------------------------------------------------------------- cell -- */

/**
 * One editable price.
 *
 * Saves on Enter or on blur, and only when the number actually changed — a grid that
 * writes a price row every time the cursor passes through would fill the append-only
 * price history with noise.
 */
function PriceCell({
  variantId,
  levelId,
  rial,
  editable,
}: {
  variantId: number;
  levelId: number;
  rial: number | null;
  editable: boolean;
}) {
  const settings = useTenantSettings();
  const unit = settings.currency_display;

  // Grouped, because these are nine-digit figures: `82000000` in a cell is a number
  // nobody can read at a glance, and reading it is the whole job of this grid. Typing
  // is unaffected — `save()` strips separators, and the box only reformats on blur, so
  // the cursor never jumps mid-entry.
  const asDisplayed = (value: number | null): string =>
    value === null
      ? ''
      : (unit === 'toman' ? value / RIAL_PER_TOMAN : value).toLocaleString('en-US');

  const [draft, setDraft] = useState(asDisplayed(rial));
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  // A page visit (filter, paging) brings new figures; the box must follow them.
  useEffect(() => setDraft(asDisplayed(rial)), [rial, unit]);

  if (!editable) {
    return rial === null ? (
      <span className="text-muted-foreground">—</span>
    ) : (
      <Money rial={rial} digits="latin" />
    );
  }

  function save(): void {
    const normalised = toLatinDigits(draft).replace(/[,\s]/g, '');

    if (normalised === '' || !/^\d+$/.test(normalised)) {
      setDraft(asDisplayed(rial));

      return;
    }

    const amount = Number(normalised);

    if (amount === (unit === 'toman' && rial !== null ? rial / RIAL_PER_TOMAN : rial)) {
      return;
    }

    setSaving(true);

    router.put(
      `/catalog/prices/${variantId}`,
      // The unit travels with the amount so the rial conversion happens once, on the
      // server, instead of in every screen that shows a price box.
      { price_level_id: levelId, amount, unit },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          setSaved(true);
          window.setTimeout(() => setSaved(false), 1500);
        },
        onError: () => setDraft(asDisplayed(rial)),
        onFinish: () => setSaving(false),
      }
    );
  }

  return (
    <span className="relative inline-flex items-center">
      <Input
        dir="ltr"
        inputMode="numeric"
        aria-label="قیمت"
        disabled={saving}
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        onBlur={save}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            event.currentTarget.blur();
          }
        }}
        className="tabular h-10 w-32 text-end"
        placeholder="—"
      />
      {saved && (
        <CheckIcon className="absolute inset-y-0 end-2 my-auto size-4 text-success" aria-hidden />
      )}
    </span>
  );
}

/* ------------------------------------------------------------------- bulk -- */

const MODES = [
  { value: 'percent', label: 'درصد تغییر' },
  { value: 'amount', label: 'مبلغ ثابت (کم/زیاد)' },
  { value: 'set', label: 'قیمت جدید ثابت' },
];

function BulkPanel({
  levels,
  filters,
  categories,
  brands,
}: {
  levels: Level[];
  filters: { q: string; category_id: number | null; brand_id: number | null };
  categories: { id: number; label: string }[];
  brands: { id: number; label: string }[];
}) {
  const settings = useTenantSettings();

  const [levelId, setLevelId] = useState(
    String(levels.find((l) => l.is_default)?.id ?? levels[0]?.id ?? '')
  );
  const [mode, setMode] = useState('percent');
  const [value, setValue] = useState('');
  const [preview, setPreview] = useState<{
    rows: PreviewRow[];
    unchanged: number;
    skipped: number;
  } | null>(null);
  const [loading, setLoading] = useState(false);

  const apply = useForm({});

  const scope = {
    price_level_id: Number(levelId),
    mode,
    value: Number(toLatinDigits(value).replace(/[,\s]/g, '') || '0'),
    unit: settings.currency_display,
    q: filters.q,
    category_id: filters.category_id,
    brand_id: filters.brand_id,
  };

  async function runPreview(): Promise<void> {
    setLoading(true);

    try {
      const response = await fetch('/catalog/prices/preview', {
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
        body: JSON.stringify(scope),
      });

      if (!response.ok) {
        throw new Error(String(response.status));
      }

      setPreview(await response.json());
    } catch {
      toast.error('پیش‌نمایش گرفته نشد. دوباره تلاش کنید.');
    } finally {
      setLoading(false);
    }
  }

  const category = categories.find((c) => c.id === filters.category_id)?.label;
  const brand = brands.find((b) => b.id === filters.brand_id)?.label;

  return (
    <SettingsSection
      title="تغییر گروهی قیمت"
      description="روی همان کالاهایی اعمال می‌شود که با فیلترهای پایین می‌بینید. پیش از اعمال، فهرست دقیق تغییرات نمایش داده می‌شود."
    >
      <div className="grid gap-4 md:grid-cols-4 md:items-end">
        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">سطح قیمت</span>
          <Select value={levelId} onValueChange={setLevelId}>
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              {levels.map((level) => (
                <SelectItem key={level.id} value={String(level.id)}>
                  {level.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>

        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">نوع تغییر</span>
          <Select value={mode} onValueChange={setMode}>
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              {MODES.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>

        <div className="space-y-1.5">
          <Label htmlFor="bulk-value">
            {mode === 'percent'
              ? 'درصد (مثلاً ۸ یا ۵-)'
              : settings.currency_display === 'toman'
                ? 'مبلغ به تومان'
                : 'مبلغ به ریال'}
          </Label>
          <Input
            id="bulk-value"
            dir="ltr"
            inputMode="numeric"
            className="tabular"
            value={value}
            onChange={(event) => setValue(event.target.value)}
          />
        </div>

        <Button type="button" onClick={runPreview} disabled={loading || value.trim() === ''}>
          <TrendingUpIcon className="size-4" />
          {loading ? 'در حال محاسبه…' : 'پیش‌نمایش'}
        </Button>
      </div>

      {(category || brand || filters.q) && (
        <p className="mt-4 text-xs text-muted-foreground">
          دامنه فعلی: {[category, brand, filters.q && `«${filters.q}»`].filter(Boolean).join(' · ')}
        </p>
      )}

      <Dialog open={preview !== null} onOpenChange={(open) => !open && setPreview(null)}>
        <DialogContent dir="rtl" className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>پیش‌نمایش تغییر قیمت</DialogTitle>
            <DialogDescription>
              دقیقاً همین ردیف‌ها اعمال می‌شوند — نه محاسبه دوباره‌ای از آن‌ها.
            </DialogDescription>
          </DialogHeader>

          {preview && (
            <>
              <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                <span>
                  <Num value={preview.rows.length} /> ردیف تغییر می‌کند
                </span>
                <span>
                  <Num value={preview.unchanged} /> ردیف بدون تغییر
                </span>
                {preview.skipped > 0 && (
                  <span className="text-warning">
                    <Num value={preview.skipped} /> ردیف قیمت پایه ندارد و رد شد
                  </span>
                )}
              </div>

              <div className="max-h-80 overflow-y-auto rounded-control border border-border">
                <Table>
                  <caption className="sr-only">ردیف‌های تغییر قیمت</caption>
                  <TableHeader>
                    <TableRow>
                      <TableHead>کالا</TableHead>
                      <TableHead className="text-end">فعلی</TableHead>
                      <TableHead className="text-end">جدید</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {preview.rows.map((row) => (
                      <TableRow key={row.variant_id}>
                        <TableCell className="text-sm">{row.name}</TableCell>
                        <TableCell className="text-end tabular">
                          {row.from ? (
                            <Money rial={row.from.value} digits="latin" />
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="text-end tabular font-medium">
                          <Money rial={row.to.value} digits="latin" />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setPreview(null)}>
              انصراف
            </Button>
            <Button
              type="button"
              disabled={apply.processing || (preview?.rows.length ?? 0) === 0}
              onClick={() => {
                apply.transform(() => ({
                  ...scope,
                  rows: (preview?.rows ?? []).map((row) => ({
                    variant_id: row.variant_id,
                    name: row.name,
                    from: row.from_rial,
                    to: row.to_rial,
                  })),
                }));

                apply.post('/catalog/prices/apply', {
                  preserveScroll: true,
                  onSuccess: () => setPreview(null),
                });
              }}
            >
              {apply.processing ? 'در حال اعمال…' : 'اعمال تغییرات'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </SettingsSection>
  );
}
