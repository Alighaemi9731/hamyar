import { Head, Link } from '@inertiajs/react';
import { ArrowRightIcon, PrinterIcon, SearchIcon, XIcon } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { PickerSkeleton } from '@/components/domain/picker-skeleton';
import { PrintLayout, printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { endpointSearch, useRemoteSearch } from '@/hooks/use-remote-search';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface LabelVariant {
  id: number;
  product_name: string;
  variant_name: string;
  barcode: string | null;
  sku: string | null;
  price: MoneyValue | null;
  /** Inline SVG fragment rendered on the server, or null when there is no code. */
  barcode_svg: string | null;
}

interface Props {
  levels: { id: number; label: string; is_default: boolean }[];
}

/** The two adhesive stocks Iranian shops actually buy, in millimetres. */
const SIZES = {
  small: { label: 'کوچک — ۳۸×۲۵ میلی‌متر', width: 38, height: 25, columns: 5 },
  large: { label: 'بزرگ — ۵۰×۳۰ میلی‌متر', width: 50, height: 30, columns: 4 },
} as const;

type SizeKey = keyof typeof SIZES;

interface Selection {
  variant: LabelVariant;
  count: number;
}

/**
 * Price and barcode labels, single or batch.
 *
 * The sheet below the controls is the sheet that prints — the controls carry
 * `no-print` and vanish. That is the whole design: a separate preview route is where
 * label printing goes wrong, because two renderings drift and the operator only finds
 * out after feeding a sheet of adhesive stock through the printer.
 *
 * Barcodes are rendered as SVG on the server (see `BarcodeRenderer`) so that what the
 * scanner reads is generated from the same string the database holds, by one encoder.
 */
export default function LabelsIndex({ levels }: Props) {
  const [levelId, setLevelId] = useState(
    String(levels.find((level) => level.is_default)?.id ?? levels[0]?.id ?? '')
  );
  const [size, setSize] = useState<SizeKey>('small');
  const [showName, setShowName] = useState(true);
  const [showPrice, setShowPrice] = useState(true);
  const [selection, setSelection] = useState<Selection[]>([]);

  const search = useMemo(
    () => endpointSearch<LabelVariant>('/catalog/labels/search', { price_level_id: levelId }),
    [levelId]
  );

  const { term, setTerm, results, status } = useRemoteSearch(search);

  const add = useCallback((variant: LabelVariant) => {
    setSelection((current) =>
      current.some((row) => row.variant.id === variant.id)
        ? current
        : [...current, { variant, count: 1 }]
    );
  }, []);

  // One flat list of labels, so the sheet is a plain grid and a variant asked for
  // twelve times simply appears twelve times.
  const labels = selection.flatMap((row) =>
    Array.from({ length: row.count }, (_, index) => ({
      ...row.variant,
      key: `${row.variant.id}-${index}`,
    }))
  );

  const spec = SIZES[size];

  return (
    <AppShell
      title="چاپ برچسب"
      actions={
        <>
          <Button variant="outline" asChild>
            <Link href="/catalog">
              <ArrowRightIcon className="size-4" />
              بازگشت به کالاها
            </Link>
          </Button>
          <Button onClick={printSheet} disabled={labels.length === 0}>
            <PrinterIcon className="size-4" />
            چاپ
          </Button>
        </>
      }
    >
      <Head title="چاپ برچسب" />

      {/*
        `min-w-0` on both tracks, and it is load-bearing.

        A grid item's minimum width is `auto`, which resolves to its *min-content* width —
        so a column holding an unbreakable row (a price with `whitespace-nowrap`, a button
        that is `shrink-0`, and a `gap-3` between them) refuses to shrink below that sum.
        Measured at 375: the picker section held itself at 539px and pushed the page to
        555px, escaping to the physical left as RTL overflow does.
      */}
      <div className="no-print mb-8 grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-start">
        <section className="min-w-0 space-y-4 rounded-card border border-border bg-surface p-6">
          <div className="relative max-w-md">
            <SearchIcon
              className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground"
              aria-hidden
            />
            <Input
              type="search"
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="نام کالا، بارکد یا کد کالا…"
              className="ps-9"
            />
          </div>

          {status === 'loading' && <PickerSkeleton />}

          {status === 'ready' && results.length === 0 && (
            <p className="py-6 text-center text-xs text-muted-foreground">
              {term.trim()
                ? `کالایی با «${term.trim()}» پیدا نشد.`
                : 'برای افزودن برچسب، نام یا بارکد کالا را بنویسید.'}
            </p>
          )}

          {status === 'ready' && results.length > 0 && (
            <ul className="divide-y divide-border">
              {results.map((variant) => (
                // `flex-wrap`, so the price and the button drop to a second line on a
                // phone instead of holding the row open.
                <li key={variant.id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                      {variant.product_name}
                    </span>
                    <span className="block truncate text-2xs text-muted-foreground">
                      {variant.variant_name}
                      {variant.barcode && (
                        <>
                          {' · '}
                          <Num value={variant.barcode} variant="ltr" />
                        </>
                      )}
                    </span>
                  </span>

                  {variant.price && <Money rial={variant.price.value} digits="latin" />}

                  <Button type="button" variant="outline" onClick={() => add(variant)}>
                    افزودن
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <aside className="min-w-0 space-y-5 rounded-card border border-border bg-surface p-6">
          <div className="space-y-1.5">
            <Label htmlFor="label-level">سطح قیمت</Label>
            <Select value={levelId} onValueChange={setLevelId}>
              <SelectTrigger id="label-level" className="w-full">
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
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="label-size">اندازه برچسب</Label>
            <Select value={size} onValueChange={(value) => setSize(value as SizeKey)}>
              <SelectTrigger id="label-size" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                {Object.entries(SIZES).map(([key, option]) => (
                  <SelectItem key={key} value={key}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <Checkbox
            checked={showName}
            onCheckedChange={(checked) => setShowName(checked === true)}
            label="نام کالا روی برچسب"
          />

          <Checkbox
            checked={showPrice}
            onCheckedChange={(checked) => setShowPrice(checked === true)}
            label="قیمت روی برچسب"
          />

          <p className="text-xs text-muted-foreground">
            <Num value={labels.length} /> برچسب روی{' '}
            <Num value={Math.max(1, Math.ceil(labels.length / (spec.columns * 10)))} /> برگ
          </p>
        </aside>
      </div>

      {selection.length > 0 && (
        <section className="no-print mb-8 rounded-card border border-border bg-surface p-6">
          <h2 className="mb-4 text-sm font-bold">برچسب‌های انتخاب‌شده</h2>
          <ul className="divide-y divide-border">
            {selection.map((row) => (
              <li key={row.variant.id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                <span className="min-w-0 flex-1 truncate text-sm">{row.variant.product_name}</span>

                <label className="flex items-center gap-2">
                  <span className="text-2xs text-muted-foreground">تعداد</span>
                  <Input
                    dir="ltr"
                    inputMode="numeric"
                    className="tabular h-10 w-20 text-center"
                    value={String(row.count)}
                    onChange={(event) => {
                      const parsed = Number(toLatinDigits(event.target.value).replace(/\D/g, ''));

                      setSelection((current) =>
                        current.map((item) =>
                          item.variant.id === row.variant.id
                            ? { ...item, count: Math.min(200, Math.max(0, parsed || 0)) }
                            : item
                        )
                      );
                    }}
                  />
                </label>

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={`حذف ${row.variant.product_name}`}
                  onClick={() =>
                    setSelection((current) =>
                      current.filter((item) => item.variant.id !== row.variant.id)
                    )
                  }
                >
                  <XIcon className="size-4" />
                </Button>
              </li>
            ))}
          </ul>
        </section>
      )}

      {labels.length === 0 ? (
        <div className="no-print">
          <EmptyState
            icon={PrinterIcon}
            title="هنوز برچسبی انتخاب نشده"
            description="کالا را از کادر بالا پیدا کنید و اضافه کنید؛ همان چیزی که اینجا می‌بینید چاپ می‌شود."
          />
        </div>
      ) : (
        <PrintLayout.A4>
          <div
            className="flex flex-wrap content-start gap-[2mm] p-[2mm]"
            style={{ minHeight: '297mm' }}
          >
            {labels.map((label) => (
              <LabelCard
                key={label.key}
                variant={label}
                width={spec.width}
                height={spec.height}
                showName={showName}
                showPrice={showPrice}
              />
            ))}
          </div>
        </PrintLayout.A4>
      )}
    </AppShell>
  );
}

/**
 * One label.
 *
 * Sized in millimetres, not pixels: an adhesive sheet is a physical object and the
 * only unit that survives a printer's DPI is a real one.
 */
function LabelCard({
  variant,
  width,
  height,
  showName,
  showPrice,
}: {
  variant: LabelVariant;
  width: number;
  height: number;
  showName: boolean;
  showPrice: boolean;
}) {
  return (
    <div
      className={cn(
        'flex shrink-0 flex-col justify-between overflow-hidden border border-black/15 p-[1.5mm] text-black',
        'break-inside-avoid'
      )}
      style={{ width: `${width}mm`, height: `${height}mm` }}
    >
      {showName && <p className="line-clamp-2 text-[6pt] leading-tight">{variant.product_name}</p>}

      {variant.barcode_svg ? (
        <div
          className="min-h-0 flex-1"
          // Server-rendered SVG from our own encoder, never user-supplied markup.
          dangerouslySetInnerHTML={{ __html: variant.barcode_svg }}
        />
      ) : (
        <p className="flex-1 text-center text-[6pt] text-black/50">بدون بارکد</p>
      )}

      <div className="flex items-end justify-between gap-1">
        {variant.barcode && (
          <span className="ltr-value tabular text-[5pt]" dir="ltr">
            {variant.barcode}
          </span>
        )}

        {showPrice &&
          (variant.price ? (
            // The price-tag yellow, used the way the system allows: a tiny highlight
            // behind the figure, never a fill across the label.
            <span className="rounded-[1mm] bg-label px-[1mm] text-[8pt] font-bold tabular">
              {variant.price.formatted}
            </span>
          ) : (
            <span className="text-[5pt] text-black/50">قیمت ندارد</span>
          ))}
      </div>
    </div>
  );
}
