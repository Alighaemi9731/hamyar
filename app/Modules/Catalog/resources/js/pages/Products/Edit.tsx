import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRightIcon, PlusIcon, Trash2Icon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { HistoryLink } from '@/components/domain/history-link';
import { Num } from '@/components/domain/num';
import { SettingsSection } from '@/components/settings-section';
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
import { Textarea } from '@/components/ui/textarea';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';

interface ProductData {
  id: number;
  name: string;
  sku: string | null;
  type: string;
  category_id: number | null;
  brand_id: number | null;
  description: string | null;
  low_stock_threshold: number | null;
  is_active: boolean;
}

interface VariantRow {
  id: number;
  name: string;
  options: Record<string, string>;
  sku: string | null;
  barcode: string | null;
  is_active: boolean;
}

interface Axis {
  name: string;
  values: string[];
}

interface Props {
  product: ProductData | null;
  variants: VariantRow[];
  axes: Axis[];
  categories: { id: number; label: string }[];
  brands: { id: number; label: string }[];
  types: { value: string; label: string }[];
  can: { view_activity: boolean };
}

const NONE = 'none';

/**
 * The product editor.
 *
 * Two halves that save separately, on purpose. The details form is a normal edit; the
 * variant matrix rewrites rows that may already carry stock and invoice lines, so it
 * gets its own action, its own confirmation of what it will produce, and its own
 * explanation of what happens to combinations that fall outside the new matrix.
 */
export default function ProductEdit({
  product,
  variants,
  axes,
  categories,
  brands,
  types,
  can,
}: Props) {
  const title = product ? product.name : 'کالای جدید';

  return (
    <AppShell
      title={title}
      actions={
        <div className="flex items-center gap-2">
          {/* The door into this product's audit history — «کی این قیمت را عوض کرد؟»
              is asked here, looking at the product, not in Settings. */}
          {product && can.view_activity && <HistoryLink subject="product" record={product.id} />}

          <Button variant="outline" asChild>
            <Link href="/catalog">
              <ArrowRightIcon className="size-4 rtl:rotate-180" />
              بازگشت به فهرست
            </Link>
          </Button>
        </div>
      }
    >
      <Head title={title} />

      <div className="space-y-6">
        <DetailsForm product={product} categories={categories} brands={brands} types={types} />

        {product && <MatrixForm productId={product.id} axes={axes} />}
        {product && <VariantList variants={variants} />}
        {product && <DangerZone product={product} />}
      </div>
    </AppShell>
  );
}

/* ---------------------------------------------------------------- details -- */

function DetailsForm({
  product,
  categories,
  brands,
  types,
}: {
  product: ProductData | null;
  categories: { id: number; label: string }[];
  brands: { id: number; label: string }[];
  types: { value: string; label: string }[];
}) {
  const form = useForm({
    name: product?.name ?? '',
    sku: product?.sku ?? '',
    type: product?.type ?? 'standard',
    category_id: String(product?.category_id ?? NONE),
    brand_id: String(product?.brand_id ?? NONE),
    description: product?.description ?? '',
    low_stock_threshold: product?.low_stock_threshold?.toString() ?? '',
    is_active: product?.is_active ?? true,
  });

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    form.transform((data) => ({
      ...data,
      category_id: data.category_id === NONE ? null : Number(data.category_id),
      brand_id: data.brand_id === NONE ? null : Number(data.brand_id),
      // Empty means "no threshold", which is not the same as a threshold of zero.
      low_stock_threshold:
        data.low_stock_threshold === '' ? null : Number(toLatinDigits(data.low_stock_threshold)),
      sku: data.sku === '' ? null : data.sku,
    }));

    if (product) {
      form.put(`/catalog/products/${product.id}`, { preserveScroll: true });
    } else {
      form.post('/catalog/products');
    }
  }

  return (
    <SettingsSection
      title="مشخصات کالا"
      description="نوع کالا بعد از ثبت هم قابل تغییر است، اما اگر برای کالای سریال‌دار دستگاه ثبت شده باشد تغییر آن معنا ندارد."
    >
      <form onSubmit={submit} className="space-y-6">
        <div className="grid gap-5 md:grid-cols-2">
          <Field label="نام کالا" error={form.errors.name} htmlFor="product-name">
            <Input
              id="product-name"
              value={form.data.name}
              autoFocus={!product}
              onChange={(event) => form.setData('name', event.target.value)}
              aria-invalid={Boolean(form.errors.name)}
            />
          </Field>

          <Field label="کد کالا (اختیاری)" error={form.errors.sku} htmlFor="product-sku">
            <Input
              id="product-sku"
              dir="ltr"
              className="tabular"
              value={form.data.sku}
              onChange={(event) => form.setData('sku', event.target.value)}
            />
          </Field>

          <Field label="نوع کالا" error={form.errors.type} htmlFor="product-type">
            <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
              <SelectTrigger id="product-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                {types.map((type) => (
                  <SelectItem key={type.value} value={type.value}>
                    {type.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>

          <Field
            label="حد هشدار موجودی (اختیاری)"
            error={form.errors.low_stock_threshold}
            htmlFor="product-threshold"
            hint="وقتی موجودی به این عدد برسد، در فهرست هشدار موجودی می‌آید."
          >
            <Input
              id="product-threshold"
              inputMode="numeric"
              className="tabular"
              value={form.data.low_stock_threshold}
              onChange={(event) => form.setData('low_stock_threshold', event.target.value)}
            />
          </Field>

          <Field label="دسته" error={form.errors.category_id} htmlFor="product-category">
            <Select
              value={form.data.category_id}
              onValueChange={(value) => form.setData('category_id', value)}
            >
              <SelectTrigger id="product-category" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value={NONE}>بدون دسته</SelectItem>
                {categories.map((category) => (
                  <SelectItem key={category.id} value={String(category.id)}>
                    {category.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>

          <Field label="برند" error={form.errors.brand_id} htmlFor="product-brand">
            <Select
              value={form.data.brand_id}
              onValueChange={(value) => form.setData('brand_id', value)}
            >
              <SelectTrigger id="product-brand" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value={NONE}>بدون برند</SelectItem>
                {brands.map((brand) => (
                  <SelectItem key={brand.id} value={String(brand.id)}>
                    {brand.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        </div>

        <Field
          label="توضیح (اختیاری)"
          error={form.errors.description}
          htmlFor="product-description"
        >
          <Textarea
            id="product-description"
            rows={3}
            value={form.data.description}
            onChange={(event) => form.setData('description', event.target.value)}
          />
        </Field>

        <Checkbox
          checked={form.data.is_active}
          onCheckedChange={(checked) => form.setData('is_active', checked === true)}
          label="این کالا فعال است و در فروش دیده می‌شود"
        />

        <div className="flex items-center gap-3">
          <Button type="submit" disabled={form.processing}>
            {form.processing ? 'در حال ذخیره…' : product ? 'ذخیره تغییرات' : 'ثبت کالا'}
          </Button>
          {form.recentlySuccessful && <span className="text-sm text-success">ذخیره شد.</span>}
        </div>
      </form>
    </SettingsSection>
  );
}

/* ----------------------------------------------------------------- matrix -- */

/**
 * The attribute matrix.
 *
 * Three colours × two storage sizes is six variants, and typing six rows by hand is
 * how a shop ends up with five. The count is shown before the save because the number
 * of rows this will produce is the one thing an operator cannot work out at a glance.
 */
function MatrixForm({ productId, axes }: { productId: number; axes: Axis[] }) {
  const [rows, setRows] = useState<Axis[]>(axes.length > 0 ? axes : [{ name: '', values: [] }]);
  // The axes live in component state (they are edited as chips, not as fields), but the
  // form is typed against them so validation errors come back on a known key.
  const form = useForm<{ axes: Axis[] }>({ axes });

  const combinations = rows.reduce(
    (total, axis) => (axis.values.length > 0 ? total * axis.values.length : total),
    1
  );
  const usable = rows.filter((axis) => axis.name.trim() !== '' && axis.values.length > 0);

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    form.transform(() => ({ axes: usable }));
    form.put(`/catalog/products/${productId}/variants`, { preserveScroll: true });
  }

  return (
    <SettingsSection
      title="ویژگی‌ها و تنوع‌ها"
      description="ترکیب همه مقادیر ساخته می‌شود. ترکیب‌هایی که از ماتریس بیرون بمانند حذف نمی‌شوند، غیرفعال می‌شوند — چون ممکن است موجودی یا فاکتور داشته باشند."
    >
      <form onSubmit={submit} className="space-y-5">
        {rows.map((axis, index) => (
          <AxisRow
            key={index}
            axis={axis}
            onChange={(next) => setRows(rows.map((row, i) => (i === index ? next : row)))}
            onRemove={() => setRows(rows.filter((_, i) => i !== index))}
          />
        ))}

        {/*
          Was `{form.errors.axes && …}`, which matches the top-level key and nothing else.
          `VariantMatrixRequest` also produces `axes.*.name`, `axes.*.values` and
          `axes.*.values.*` — different keys, so an axis with a blank name or no values was
          refused with nothing on screen.

          The inline render is gone rather than kept alongside: `FormErrors` treats a key as
          handled when the form handles it *or any prefix of it*, so listing `axes` in
          `handled` would have hidden the nested ones too. One region owns the whole family.
        */}
        <FormErrors errors={form.errors} />

        <div className="flex flex-wrap items-center gap-3">
          <Button
            type="button"
            variant="outline"
            onClick={() => setRows([...rows, { name: '', values: [] }])}
          >
            <PlusIcon className="size-4" />
            ویژگی دیگر
          </Button>

          <Button type="submit" disabled={form.processing || usable.length === 0}>
            {form.processing ? 'در حال ساخت…' : 'ساخت تنوع‌ها'}
          </Button>

          {usable.length > 0 && (
            <span className="text-sm text-muted-foreground">
              <Num value={combinations} /> ترکیب ساخته می‌شود
            </span>
          )}
        </div>
      </form>
    </SettingsSection>
  );
}

function AxisRow({
  axis,
  onChange,
  onRemove,
}: {
  axis: Axis;
  onChange: (axis: Axis) => void;
  onRemove: () => void;
}) {
  const [draft, setDraft] = useState('');

  function addValue(): void {
    const value = draft.trim();

    if (value === '' || axis.values.includes(value)) {
      setDraft('');

      return;
    }

    onChange({ ...axis, values: [...axis.values, value] });
    setDraft('');
  }

  return (
    <div className="grid gap-3 rounded-control border border-border p-4 sm:grid-cols-[12rem_1fr_auto] sm:items-start">
      <Input
        value={axis.name}
        placeholder="نام ویژگی (مثلاً رنگ)"
        onChange={(event) => onChange({ ...axis, name: event.target.value })}
      />

      <div className="space-y-2">
        <div className="flex flex-wrap gap-1.5">
          {axis.values.map((value) => (
            <span
              key={value}
              className="inline-flex min-h-10 items-center gap-0.5 rounded-pill bg-muted ps-3 pe-1 text-xs"
            >
              {value}
              {/*
                A bare icon button shrink-wraps to its glyph: this was **12x12px**, the
                smallest target in the product and under a third of the floor, on a control
                that silently drops a variant axis value. The chip is now tall enough to
                hold a real one, and the X is 40x40 inside it — the mark stays `size-3.5`,
                only the area around it grew.
              */}
              <button
                type="button"
                aria-label={`حذف ${value}`}
                onClick={() =>
                  onChange({ ...axis, values: axis.values.filter((v) => v !== value) })
                }
                className="flex size-10 shrink-0 items-center justify-center rounded-pill text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
              >
                <XIcon className="size-3.5" aria-hidden />
              </button>
            </span>
          ))}
        </div>

        <Input
          value={draft}
          placeholder="مقدار را بنویسید و Enter بزنید"
          onChange={(event) => setDraft(event.target.value)}
          onBlur={addValue}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              // Enter adds a value; without this it submits the matrix form with a
              // half-typed value still in the box.
              event.preventDefault();
              addValue();
            }
          }}
        />
      </div>

      <Button
        type="button"
        variant="ghost"
        size="icon"
        aria-label="حذف ویژگی"
        className="group"
        onClick={onRemove}
      >
        <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
      </Button>
    </div>
  );
}

/* --------------------------------------------------------------- variants -- */

function VariantList({ variants }: { variants: VariantRow[] }) {
  if (variants.length === 0) {
    return null;
  }

  return (
    <SettingsSection
      title="تنوع‌ها"
      description="بارکد و کد کالا برای هر تنوع جداگانه ثبت می‌شود؛ موجودی و قیمت هم به تنوع وصل است، نه به خود کالا."
      variant="flush"
    >
      <ul className="mt-6 divide-y divide-border border-t border-border">
        {variants.map((variant) => (
          <VariantRowItem key={variant.id} variant={variant} />
        ))}
      </ul>
    </SettingsSection>
  );
}

function VariantRowItem({ variant }: { variant: VariantRow }) {
  const form = useForm({
    sku: variant.sku ?? '',
    barcode: variant.barcode ?? '',
    is_active: variant.is_active,
  });

  return (
    <li className="grid gap-3 px-6 py-4 sm:grid-cols-[1fr_10rem_12rem_auto] sm:items-end sm:px-7">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">{variant.name}</p>
        {!variant.is_active && <p className="text-2xs text-muted-foreground">غیرفعال</p>}
      </div>

      <label className="space-y-1.5">
        <span className="text-2xs text-muted-foreground">کد کالا</span>
        <Input
          dir="ltr"
          className="tabular"
          value={form.data.sku}
          onChange={(event) => form.setData('sku', event.target.value)}
          aria-invalid={Boolean(form.errors.sku)}
        />
      </label>

      <label className="space-y-1.5">
        <span className="text-2xs text-muted-foreground">بارکد</span>
        <Input
          dir="ltr"
          className="tabular"
          value={form.data.barcode}
          onChange={(event) => form.setData('barcode', event.target.value)}
          aria-invalid={Boolean(form.errors.barcode)}
        />
      </label>

      <Button
        type="button"
        variant="outline"
        disabled={form.processing || !form.isDirty}
        onClick={() => form.put(`/catalog/variants/${variant.id}`, { preserveScroll: true })}
      >
        ذخیره
      </Button>

      {(form.errors.sku || form.errors.barcode) && (
        <p className="text-sm text-danger sm:col-span-4">
          {form.errors.sku ?? form.errors.barcode}
        </p>
      )}
    </li>
  );
}

/* ------------------------------------------------------------ danger zone -- */

function DangerZone({ product }: { product: ProductData }) {
  const [confirming, setConfirming] = useState(false);
  const form = useForm({});

  return (
    <SettingsSection
      title="حذف کالا"
      description="کالا از فهرست خارج می‌شود اما فاکتورها و گردش انبارِ گذشته دست‌نخورده می‌مانند."
    >
      <Button type="button" variant="destructive" onClick={() => setConfirming(true)}>
        <Trash2Icon className="size-4" />
        حذف این کالا
      </Button>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`حذف «${product.name}»`}
        description="این کالا از فهرست و از فروش خارج می‌شود. فاکتورها، گردش انبار و دستگاه‌های ثبت‌شده حذف نمی‌شوند."
        confirmLabel="حذف کالا"
        processing={form.processing}
        onConfirm={() => form.delete(`/catalog/products/${product.id}`)}
      />
    </SettingsSection>
  );
}

/* ------------------------------------------------------------------ field -- */

function Field({
  label,
  htmlFor,
  error,
  hint,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error ? (
        <p className="text-sm text-danger">{error}</p>
      ) : hint ? (
        <p className="text-xs text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}
